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

	public function test_encrypt_retires_old_key_file_when_old_envelope_provided(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$old_envelope = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'old-pw' ),
				'file_key'      => 'site_credential_http_pass_oldkey',
			)
		);

		$result = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'new-pw', 'http_pass', $old_envelope );

		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $result );
		$this->assertSame( array( 'site_credential_http_pass_oldkey' ), $deleted );
	}

	public function test_encrypt_keeps_old_key_file_when_new_envelope_reuses_it(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		// The stub reuses the same file_key on every encryption; deleting
		// it would orphan the envelope we just produced.
		$old_envelope = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'old-pw', 'http_pass' );
		$result       = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'new-pw', 'http_pass', $old_envelope );

		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $result );
		$this->assertSame( array(), $deleted );
	}

	public function test_encrypt_deletes_nothing_without_old_envelope(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		\MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'fresh-pw', 'http_pass' );
		\MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'plain-old', 'http_pass', 'legacy-plaintext-not-an-envelope' );

		$this->assertSame( array(), $deleted );
	}

	public function test_encrypt_idempotent_path_never_deletes(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$once = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'idem-pw', 'http_pass' );
		// Re-saving the stored envelope unchanged must not retire the key
		// file the stored row still references.
		\MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( $once, 'http_pass', $once );

		$this->assertSame( array(), $deleted );
	}

	public function test_encrypt_unchanged_plaintext_reuses_old_envelope(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		// The Edit Site form posts the decrypted http_user back as
		// plaintext on every save. When the value is unchanged the stored
		// envelope must be reused instead of re-encrypting into a new key
		// file and retiring the old one (key-file churn). The old envelope
		// is seeded with a distinct file_key because the stub encryptor
		// reuses one key per field, which would mask a re-encryption.
		$old_envelope = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'unchanged-user' ),
				'file_key'      => 'site_credential_http_user_churn_key',
			)
		);
		$result       = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'unchanged-user', 'http_user', $old_envelope );

		$this->assertSame( $old_envelope, $result );
		$this->assertSame( array(), $deleted );
	}

	public function test_encrypt_unreadable_old_envelope_falls_through_to_fresh_encryption(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		// An old envelope whose key file is gone decrypts to ''. The
		// unchanged-value comparison must fail and the plaintext must be
		// re-encrypted, repairing the broken envelope and retiring the
		// stale key reference.
		$broken = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'UNREADABLE',
				'file_key'      => 'site_credential_http_user_stale_key',
			)
		);

		$result = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'repair-me', 'http_user', $broken );

		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $result );
		$this->assertNotSame( $broken, $result );
		$this->assertSame( 'repair-me', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $result ) );
		$this->assertSame( array( 'site_credential_http_user_stale_key' ), $deleted );
	}

	public function test_encrypt_credential_empty_string_retires_old_key_and_returns_empty(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$old_envelope = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'old-pw' ),
				'file_key'      => 'site_credential_http_pass_clear_key',
			)
		);

		$result = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( '', 'http_pass', $old_envelope );

		$this->assertSame( '', $result );
		$this->assertSame( array( 'site_credential_http_pass_clear_key' ), $deleted );
	}

	public function test_encrypt_credential_null_does_not_retire_old_key(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$old_envelope = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'some-pw' ),
				'file_key'      => 'site_credential_http_pass_null_key',
			)
		);

		$result = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( null, 'http_pass', $old_envelope );

		$this->assertNull( $result );
		$this->assertSame( array(), $deleted, 'null means not provided and must not retire the old key file' );
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

	/**
	 * Create a site via add_website and overwrite its http_pass column
	 * with an envelope carrying a known file_key, so an update path can
	 * be observed retiring it. The stub encryptor reuses one file_key
	 * per field, so a distinct seeded key is needed to see the delete.
	 *
	 * @param string $file_key Key file id the seeded envelope references.
	 * @return int Site id.
	 */
	private function seed_site_with_envelope( string $file_key ): int {
		global $wpdb;

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_id = \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$user_id,
			'Retire Test',
			'http://retire-site-' . wp_generate_uuid4() . '.example/',
			'admin',
			base64_encode( 'pub-' . wp_generate_uuid4() ),
			base64_encode( 'priv-' . wp_generate_uuid4() ),
			array(
				'http_user' => 'authuser',
				'http_pass' => 'seed-pw',
			)
		);
		$this->assertNotFalse( $site_id );

		$seeded = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'old-pw' ),
				'file_key'      => $file_key,
			)
		);
		$wpdb->update(
			\MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' ),
			array( 'http_pass' => $seeded ),
			array( 'id' => $site_id )
		);

		return (int) $site_id;
	}

	public function test_rest_api_update_website_retires_old_key_file(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_restkey' );

		\MainWP\Dashboard\MainWP_DB_Common::instance()->rest_api_update_website(
			$site_id,
			array( 'http_pass' => 'replacement-pw' )
		);

		$this->assertContains( 'site_credential_http_pass_restkey', $deleted );
	}

	public function test_rest_api_update_website_empty_string_clears_column_and_retires_key(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_rest_clear' );

		\MainWP\Dashboard\MainWP_DB_Common::instance()->rest_api_update_website(
			$site_id,
			array( 'http_pass' => '' )
		);

		// get_row, not get_var: wpdb::get_var() collapses '' to null.
		$table = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( '', $row->http_pass );

		$this->assertContains( 'site_credential_http_pass_rest_clear', $deleted );
	}

	public function test_rest_api_update_website_absent_http_pass_leaves_column_untouched(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$file_key = 'site_credential_http_pass_rest_absent';
		$site_id  = $this->seed_site_with_envelope( $file_key );

		\MainWP\Dashboard\MainWP_DB_Common::instance()->rest_api_update_website(
			$site_id,
			array( 'name' => 'Renamed Site' )
		);

		$table = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass, name FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $row->http_pass );
		$this->assertSame( 'Renamed Site', $row->name );

		$this->assertNotContains( $file_key, $deleted );
	}

	public function test_update_website_values_retires_old_key_file(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_valueskey' );

		\MainWP\Dashboard\MainWP_DB::instance()->update_website_values(
			$site_id,
			array( 'http_pass' => 'replacement-pw' )
		);

		$this->assertContains( 'site_credential_http_pass_valueskey', $deleted );
	}

	public function test_update_website_values_empty_string_clears_column_and_retires_key(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_values_clear' );

		$result = \MainWP\Dashboard\MainWP_DB::instance()->update_website_values(
			$site_id,
			array( 'http_pass' => '' )
		);

		$this->assertNotFalse( $result );

		// get_row, not get_var: wpdb::get_var() collapses '' to null.
		$table = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( '', $row->http_pass );

		$this->assertContains( 'site_credential_http_pass_values_clear', $deleted );
	}

	public function test_update_website_values_without_credentials_reads_no_prior_row(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_untouched' );

		\MainWP\Dashboard\MainWP_DB::instance()->update_website_values(
			$site_id,
			array( 'offline_check_result' => 1 )
		);

		$this->assertSame( array(), $deleted );
	}

	public function test_update_website_values_explicit_null_leaves_column_untouched(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_values_null' );

		$table  = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$before = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );

		// Explicit null means "not provided" and must behave exactly like
		// an absent key: the column stays untouched, other fields update.
		$result = \MainWP\Dashboard\MainWP_DB::instance()->update_website_values(
			$site_id,
			array(
				'http_pass'            => null,
				'offline_check_result' => 1,
			)
		);

		$this->assertNotFalse( $result );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass, offline_check_result FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( $before->http_pass, $row->http_pass, 'explicit null must not overwrite the stored envelope' );
		$this->assertSame( '1', (string) $row->offline_check_result );
		$this->assertSame( array(), $deleted );
	}

	public function test_update_website_values_only_null_credentials_updates_nothing(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_values_null_only' );

		$table  = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$before = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );

		$result = \MainWP\Dashboard\MainWP_DB::instance()->update_website_values(
			$site_id,
			array( 'http_pass' => null )
		);

		$this->assertFalse( $result, 'nothing left to update once the null credential is dropped' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( $before->http_pass, $row->http_pass );
		$this->assertSame( array(), $deleted );
	}

	// ---------------------------------------------------------------------
	// Key retirement must not precede persistence. Staging carries the
	// key work as an intent; commit fires it after a confirmed write and
	// rollback deletes the freshly minted key instead.
	// ---------------------------------------------------------------------

	public function test_stage_credential_encryption_fires_no_deletes(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$old_envelope = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'old-pw' ),
				'file_key'      => 'site_credential_http_pass_stage_old',
			)
		);

		$intent = \MainWP\Dashboard\MainWP_Credential_Storage::stage_credential_encryption( 'new-pw', 'http_pass', $old_envelope );

		$this->assertIsArray( $intent );
		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $intent['value'] );
		$this->assertSame( 'site_credential_http_pass_stage_old', $intent['retire_key'] );
		$this->assertSame( 'site_credential_http_pass_stubkey', $intent['fresh_key'] );
		$this->assertSame( array(), $deleted, 'staging must not touch any key file' );
	}

	public function test_commit_intent_retires_superseded_key_only(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		\MainWP\Dashboard\MainWP_Credential_Storage::commit_credential_intent(
			array(
				'value'      => 'irrelevant',
				'retire_key' => 'old-key-to-retire',
				'fresh_key'  => 'fresh-key-kept',
			)
		);

		$this->assertSame( array( 'old-key-to-retire' ), $deleted );
	}

	public function test_rollback_intent_deletes_fresh_key_only(): void {
		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		\MainWP\Dashboard\MainWP_Credential_Storage::rollback_credential_intent(
			array(
				'value'      => 'irrelevant',
				'retire_key' => 'old-key-kept',
				'fresh_key'  => 'fresh-key-to-delete',
			)
		);

		$this->assertSame( array( 'fresh-key-to-delete' ), $deleted );
	}

	public function test_stage_clear_carries_retire_key_and_no_fresh_key(): void {
		$old_envelope = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'old-pw' ),
				'file_key'      => 'site_credential_http_pass_clear_stage',
			)
		);

		$intent = \MainWP\Dashboard\MainWP_Credential_Storage::stage_credential_encryption( '', 'http_pass', $old_envelope );

		$this->assertSame( '', $intent['value'] );
		$this->assertSame( 'site_credential_http_pass_clear_stage', $intent['retire_key'] );
		$this->assertNull( $intent['fresh_key'] );
	}

	public function test_stage_unchanged_plaintext_carries_no_keys(): void {
		$old_envelope = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'same-pw' ),
				'file_key'      => 'site_credential_http_pass_same_stage',
			)
		);

		$intent = \MainWP\Dashboard\MainWP_Credential_Storage::stage_credential_encryption( 'same-pw', 'http_pass', $old_envelope );

		$this->assertSame( $old_envelope, $intent['value'] );
		$this->assertNull( $intent['retire_key'] );
		$this->assertNull( $intent['fresh_key'] );
	}

	/**
	 * Seed BOTH credential columns with envelopes carrying known distinct
	 * file keys, so multi-field write paths can be observed retiring or
	 * preserving each one.
	 *
	 * @param string $user_key Key file id for the http_user envelope.
	 * @param string $pass_key Key file id for the http_pass envelope.
	 * @return int Site id.
	 */
	private function seed_site_with_both_envelopes( string $user_key, string $pass_key ): int {
		global $wpdb;

		$site_id = $this->seed_site_with_envelope( $pass_key );
		$seeded  = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'old-user' ),
				'file_key'      => $user_key,
			)
		);
		$wpdb->update(
			\MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' ),
			array( 'http_user' => $seeded ),
			array( 'id' => $site_id )
		);

		return $site_id;
	}

	public function test_update_website_values_failed_second_encryption_keeps_old_keys(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_both_envelopes(
			'site_credential_http_user_m4_enc_fail',
			'site_credential_http_pass_m4_enc_fail'
		);

		$table  = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$before = $wpdb->get_row( $wpdb->prepare( "SELECT http_user, http_pass FROM `{$table}` WHERE id = %d", $site_id ) );

		// The http_user encryption succeeds (minting a fresh key), then the
		// http_pass encryption fails: the write path must abort, keep both
		// stored envelopes readable, and clean up only the fresh key.
		$fail_pass = function ( $envelope, $value, $prefix ) {
			return false !== strpos( (string) $prefix, 'http_pass' ) ? false : $envelope;
		};
		add_filter( 'mainwp_encrypt_key_value', $fail_pass, 11, 3 );

		$result = \MainWP\Dashboard\MainWP_DB::instance()->update_website_values(
			$site_id,
			array(
				'http_user' => 'new-user',
				'http_pass' => 'new-pass',
			)
		);

		remove_filter( 'mainwp_encrypt_key_value', $fail_pass, 11 );

		$this->assertFalse( $result );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT http_user, http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( $before->http_user, $row->http_user, 'aborted write must leave the stored http_user envelope' );
		$this->assertSame( $before->http_pass, $row->http_pass, 'aborted write must leave the stored http_pass envelope' );
		$this->assertSame( 'old-user', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row->http_user ) );

		$this->assertNotContains( 'site_credential_http_user_m4_enc_fail', $deleted, 'stored http_user key must survive the abort' );
		$this->assertNotContains( 'site_credential_http_pass_m4_enc_fail', $deleted, 'stored http_pass key must survive the abort' );
		$this->assertContains( 'site_credential_http_user_stubkey', $deleted, 'the fresh key minted before the abort must be cleaned up' );
	}

	public function test_update_website_values_failed_db_update_keeps_old_key(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_m4_db_fail' );

		// Break only the credential UPDATE: staging already happened by the
		// time it runs, so a failure here is the retire-before-persist window.
		$break = function ( $query ) {
			if ( false !== strpos( (string) $query, 'UPDATE' ) && false !== strpos( (string) $query, 'http_pass' ) ) {
				return 'UPDATE nonexistent_mwp_m4_table SET broken = 1';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		$result = \MainWP\Dashboard\MainWP_DB::instance()->update_website_values(
			$site_id,
			array( 'http_pass' => 'replacement-pw' )
		);

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertFalse( $result );

		$table = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( 'old-pw', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row->http_pass ), 'the stored envelope must still decrypt after the failed write' );

		$this->assertNotContains( 'site_credential_http_pass_m4_db_fail', $deleted, 'stored key must survive the failed write' );
		$this->assertContains( 'site_credential_http_pass_stubkey', $deleted, 'the fresh key must be cleaned up on rollback' );
	}

	public function test_update_website_values_retires_old_key_only_after_new_envelope_persisted(): void {
		global $wpdb;

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_m4_ordering' );
		$table   = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );

		$stored_at_retire = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$stored_at_retire, $wpdb, $table, $site_id ) {
				if ( 'site_credential_http_pass_m4_ordering' === $file_key ) {
					$row                = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
					$stored_at_retire[] = \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row->http_pass );
				}
			}
		);

		$result = \MainWP\Dashboard\MainWP_DB::instance()->update_website_values(
			$site_id,
			array( 'http_pass' => 'replacement-pw' )
		);

		$this->assertNotFalse( $result );
		$this->assertSame(
			array( 'replacement-pw' ),
			$stored_at_retire,
			'when the old key retires, the row must already hold the replacement envelope'
		);
	}

	public function test_rest_api_update_website_failed_db_update_keeps_old_key(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$site_id = $this->seed_site_with_envelope( 'site_credential_http_pass_m4_rest_db_fail' );

		$break = function ( $query ) {
			if ( false !== strpos( (string) $query, 'UPDATE' ) && false !== strpos( (string) $query, 'http_pass' ) ) {
				return 'UPDATE nonexistent_mwp_m4_table SET broken = 1';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		$result = \MainWP\Dashboard\MainWP_DB_Common::instance()->rest_api_update_website(
			$site_id,
			array( 'http_pass' => 'replacement-pw' )
		);

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertFalse( $result );

		$table = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( 'old-pw', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row->http_pass ) );

		$this->assertNotContains( 'site_credential_http_pass_m4_rest_db_fail', $deleted );
		$this->assertContains( 'site_credential_http_pass_stubkey', $deleted );
	}

	public function test_update_website_failed_db_update_keeps_old_key(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_id = \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$user_id,
			'Retire After Persist Test',
			'http://retire-after-persist-' . wp_generate_uuid4() . '.example/',
			'admin',
			base64_encode( 'pub-' . wp_generate_uuid4() ),
			base64_encode( 'priv-' . wp_generate_uuid4() ),
			array(
				'http_user' => 'authuser',
				'http_pass' => 'seed-pw',
			)
		);
		$this->assertNotFalse( $site_id );
		wp_set_current_user( $user_id );

		// Re-key the stored http_pass envelope so the retire decision is
		// observable (the stub reuses one key per field otherwise).
		$table  = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$seeded = self::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'encrypted_val' => 'STUB:' . base64_encode( 'old-pw' ),
				'file_key'      => 'site_credential_http_pass_m4_uw_db_fail',
			)
		);
		$wpdb->update( $table, array( 'http_pass' => $seeded ), array( 'id' => $site_id ) );

		$break = function ( $query ) {
			if ( false !== strpos( (string) $query, 'UPDATE' ) && false !== strpos( (string) $query, 'http_pass' ) ) {
				return 'UPDATE nonexistent_mwp_m4_table SET broken = 1';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		\MainWP\Dashboard\MainWP_DB::instance()->update_website(
			$site_id,
			'http://retire-after-persist-renamed.example/',
			$user_id,
			'Retire After Persist Renamed',
			'admin',
			array(),
			array(),
			'',
			0,
			0,
			0,
			1,
			'global',
			'',
			null,
			'replacement-pw'
		);

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( 'old-pw', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row->http_pass ), 'the stored envelope must still decrypt after the failed write' );

		$this->assertNotContains( 'site_credential_http_pass_m4_uw_db_fail', $deleted, 'stored key must survive the failed write' );
		$this->assertContains( 'site_credential_http_pass_stubkey', $deleted, 'the fresh key must be cleaned up on rollback' );
	}

	public function test_add_website_failed_insert_rolls_back_fresh_keys(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Break only the site INSERT (its VALUES carry the envelopes, so
		// the stub key names are the discriminator); staging has already
		// minted fresh keys by the time it runs.
		$break = function ( $query ) {
			if ( false !== strpos( (string) $query, 'INSERT' ) && false !== strpos( (string) $query, 'site_credential_http_user_stubkey' ) ) {
				return 'INSERT INTO nonexistent_mwp_add_table VALUES (1)';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		$site_id = \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$user_id,
			'Never Inserted',
			'http://never-inserted-' . wp_generate_uuid4() . '.example/',
			'admin',
			base64_encode( 'pub-' . wp_generate_uuid4() ),
			base64_encode( 'priv-' . wp_generate_uuid4() ),
			array(
				'http_user' => 'authuser',
				'http_pass' => 'seed-pw',
			)
		);

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertFalse( $site_id );
		$this->assertContains( 'site_credential_http_user_stubkey', $deleted, 'The fresh http_user key must be deleted when the insert never lands.' );
		$this->assertContains( 'site_credential_http_pass_stubkey', $deleted, 'The fresh http_pass key must be deleted when the insert never lands.' );
	}

	public function test_update_website_null_credentials_leave_columns_untouched(): void {
		global $wpdb;

		$deleted = array();
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_id = \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$user_id,
			'Null Update Test',
			'http://null-update-' . wp_generate_uuid4() . '.example/',
			'admin',
			base64_encode( 'pub-' . wp_generate_uuid4() ),
			base64_encode( 'priv-' . wp_generate_uuid4() ),
			array(
				'http_user' => 'authuser',
				'http_pass' => 'seed-pw',
			)
		);
		$this->assertNotFalse( $site_id );

		// can_edit_website() compares the row's userid against the
		// current user when the install is not single-user.
		wp_set_current_user( $user_id );

		$table  = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$before = $wpdb->get_row( $wpdb->prepare( "SELECT http_user, http_pass FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $before->http_user );
		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $before->http_pass );

		// The 20-arg positional signature with $http_user / $http_pass
		// left at their null defaults: "not provided" must leave the
		// stored envelopes alone instead of NULLing the columns.
		$result = \MainWP\Dashboard\MainWP_DB::instance()->update_website(
			$site_id,
			'http://null-update-renamed.example/',
			$user_id,
			'Null Update Renamed',
			'admin',
			array(),
			array(),
			'',
			0,
			0,
			0
		);

		$this->assertTrue( $result );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT http_user, http_pass, name FROM `{$table}` WHERE id = %d", $site_id ) );
		$this->assertSame( $before->http_user, $row->http_user, 'null http_user must not overwrite the stored envelope' );
		$this->assertSame( $before->http_pass, $row->http_pass, 'null http_pass must not overwrite the stored envelope' );
		$this->assertSame( 'Null Update Renamed', $row->name );
		$this->assertSame( array(), $deleted );
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
