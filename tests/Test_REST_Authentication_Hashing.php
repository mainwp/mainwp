<?php
/**
 * MainWP REST Authentication Hashing Tests
 *
 * Tests the consumer_secret at-rest hashing behavior introduced in MWP-1540:
 * new keys are hashed via wp_hash_password on insert, existing legacy plaintext
 * keys continue to authenticate via hash_equals fallback, and OAuth 1.0a is
 * rejected for hashed keys (HMAC requires plaintext).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_REST_Authentication_Hashing
 */
class Test_REST_Authentication_Hashing extends \WP_UnitTestCase {

	/**
	 * Reflection helper: invoke a private method on MainWP_REST_Authentication.
	 *
	 * @param string $method Method name.
	 * @param array  $args Arguments to pass to the method.
	 * @return mixed Return value from the method.
	 */
	private function invoke_auth_method( string $method, array $args ) {
		\MainWP_REST_Authentication::$instance = null;
		$auth = \MainWP_REST_Authentication::get_instance();
		$ref  = new \ReflectionMethod( $auth, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $auth, $args );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		\MainWP_REST_Authentication::$instance = null;
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// is_legacy_plaintext_secret()
	// ---------------------------------------------------------------------

	public function test_is_legacy_plaintext_secret_recognizes_cs_prefix_with_40_hex(): void {
		$legacy = 'cs_' . str_repeat( '0123456789abcdef', 2 ) . '01234567'; // 'cs_' + 40 hex chars.
		$this->assertTrue(
			$this->invoke_auth_method( 'is_legacy_plaintext_secret', array( $legacy ) ),
			'A "cs_" prefix followed by 40 hex chars must be recognized as legacy plaintext'
		);
	}

	public function test_is_legacy_plaintext_secret_rejects_phpass_hash(): void {
		// Realistic phpass hash output length 34, starts with $P$.
		$hashed = '$P$BLh4yOYg8VW6nYTLZRKzVLxf12dW.A0';
		$this->assertFalse(
			$this->invoke_auth_method( 'is_legacy_plaintext_secret', array( $hashed ) ),
			'WP password hash format must NOT be classified as legacy plaintext'
		);
	}

	public function test_is_legacy_plaintext_secret_rejects_argon2_hash(): void {
		// Realistic argon2id output (WP 6.5+ may produce this format).
		$hashed = '$wp$2y$10$abcdefghijklmnopqrstuv.WXYZ0123456789abcdefghijkl';
		$this->assertFalse(
			$this->invoke_auth_method( 'is_legacy_plaintext_secret', array( $hashed ) ),
			'WP $wp$/argon2 password hash format must NOT be classified as legacy plaintext'
		);
	}

	public function test_is_legacy_plaintext_secret_rejects_empty_and_non_string(): void {
		$this->assertFalse( $this->invoke_auth_method( 'is_legacy_plaintext_secret', array( '' ) ) );
		$this->assertFalse( $this->invoke_auth_method( 'is_legacy_plaintext_secret', array( null ) ) );
		$this->assertFalse( $this->invoke_auth_method( 'is_legacy_plaintext_secret', array( 12345 ) ) );
		$this->assertFalse( $this->invoke_auth_method( 'is_legacy_plaintext_secret', array( array() ) ) );
	}

	public function test_is_legacy_plaintext_secret_rejects_wrong_prefix(): void {
		$wrong_prefix = 'ck_' . str_repeat( '0123456789abcdef', 2 ) . '01234567';
		$this->assertFalse(
			$this->invoke_auth_method( 'is_legacy_plaintext_secret', array( $wrong_prefix ) )
		);
	}

	public function test_is_legacy_plaintext_secret_accepts_common_legacy_lengths(): void {
		// Production uses bin2hex(openssl_random_pseudo_bytes(20)) = 40 hex chars.
		// Older fixtures use bin2hex(random_bytes(16)) = 32 hex chars. Both are
		// valid plaintext formats and must be detected.
		$forty = 'cs_' . str_repeat( 'a', 40 );
		$thirty_two = 'cs_' . str_repeat( 'b', 32 );
		$this->assertTrue( $this->invoke_auth_method( 'is_legacy_plaintext_secret', array( $forty ) ) );
		$this->assertTrue( $this->invoke_auth_method( 'is_legacy_plaintext_secret', array( $thirty_two ) ) );
	}

	public function test_is_legacy_plaintext_secret_rejects_empty_body(): void {
		$this->assertFalse( $this->invoke_auth_method( 'is_legacy_plaintext_secret', array( 'cs_' ) ) );
	}

	public function test_is_legacy_plaintext_secret_rejects_non_hex_body(): void {
		$non_hex = 'cs_' . str_repeat( 'g', 40 ); // 'g' is outside hex.
		$this->assertFalse(
			$this->invoke_auth_method( 'is_legacy_plaintext_secret', array( $non_hex ) )
		);
	}

	// ---------------------------------------------------------------------
	// verify_consumer_secret()
	// ---------------------------------------------------------------------

	public function test_verify_consumer_secret_matches_legacy_plaintext(): void {
		$legacy = 'cs_' . str_repeat( '0123456789abcdef', 2 ) . '01234567';
		$this->assertTrue(
			$this->invoke_auth_method( 'verify_consumer_secret', array( $legacy, $legacy ) )
		);
	}

	public function test_verify_consumer_secret_rejects_legacy_mismatch(): void {
		$legacy_a = 'cs_' . str_repeat( 'a', 40 );
		$legacy_b = 'cs_' . str_repeat( 'b', 40 );
		$this->assertFalse(
			$this->invoke_auth_method( 'verify_consumer_secret', array( $legacy_a, $legacy_b ) )
		);
	}

	public function test_verify_consumer_secret_matches_hashed_against_original_plaintext(): void {
		$plaintext = 'cs_' . str_repeat( 'a', 40 );
		$hashed    = wp_hash_password( $plaintext );
		$this->assertTrue(
			$this->invoke_auth_method( 'verify_consumer_secret', array( $hashed, $plaintext ) )
		);
	}

	public function test_verify_consumer_secret_rejects_hashed_against_wrong_plaintext(): void {
		$correct = 'cs_' . str_repeat( 'a', 40 );
		$wrong   = 'cs_' . str_repeat( 'b', 40 );
		$hashed  = wp_hash_password( $correct );
		$this->assertFalse(
			$this->invoke_auth_method( 'verify_consumer_secret', array( $hashed, $wrong ) )
		);
	}

	// ---------------------------------------------------------------------
	// check_oauth_signature() OAuth 1.0a rejection for hashed keys
	// ---------------------------------------------------------------------

	/**
	 * A user row whose consumer_secret is hashed at rest must not be allowed to
	 * authenticate via OAuth 1.0a, since HMAC verification needs the plaintext
	 * secret on the server side. The auth code returns a specific error code so
	 * callers can distinguish this case from a generic signature failure.
	 */
	public function test_oauth_signature_rejects_hashed_secret(): void {
		$hashed_user = (object) array(
			'consumer_secret' => wp_hash_password( 'cs_' . str_repeat( 'a', 40 ) ),
		);
		$params      = array(
			'oauth_signature_method' => 'HMAC-SHA256',
			'oauth_signature'        => 'placeholder',
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/wp-json/mainwp/v2/sites';

		$result = $this->invoke_auth_method( 'check_oauth_signature', array( $hashed_user, $params ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mainwp_rest_authentication_oauth1_unsupported', $result->get_error_code() );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
	}

	// ---------------------------------------------------------------------
	// insert_rest_api_key writes hashed values
	// ---------------------------------------------------------------------

	public function test_insert_rest_api_key_stores_hashed_consumer_secret(): void {
		// Need a current user for insert_rest_api_key to populate user_id.
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$plaintext_secret = 'cs_' . str_repeat( 'a', 40 );
		$consumer_key     = 'ck_' . str_repeat( 'b', 40 );

		$result = \MainWP\Dashboard\MainWP_DB::instance()->insert_rest_api_key(
			$consumer_key,
			$plaintext_secret,
			'read',
			'test key',
			1
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'key_id', $result );
		$this->assertSame( $plaintext_secret, $result['consumer_secret'], 'Returned plaintext for caller display' );

		// Read back from DB and confirm the stored value is hashed (not the plaintext).
		global $wpdb;
		$table_name = $wpdb->prefix . 'mainwp_api_keys';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT consumer_secret FROM {$table_name} WHERE key_id = %d",
				$result['key_id']
			)
		);

		$this->assertNotEmpty( $stored, 'Stored secret row must exist' );
		$this->assertNotSame( $plaintext_secret, $stored, 'Stored secret must NOT be the plaintext' );
		$this->assertTrue( wp_check_password( $plaintext_secret, $stored ), 'Stored secret must verify against the plaintext via wp_check_password' );
	}

	public function test_insert_rest_api_key_does_not_persist_legacy_plaintext_format(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$plaintext_secret = 'cs_' . str_repeat( 'c', 40 );
		$consumer_key     = 'ck_' . str_repeat( 'd', 40 );

		$result = \MainWP\Dashboard\MainWP_DB::instance()->insert_rest_api_key(
			$consumer_key,
			$plaintext_secret,
			'read',
			'test key',
			1
		);

		global $wpdb;
		$table_name = $wpdb->prefix . 'mainwp_api_keys';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT consumer_secret FROM {$table_name} WHERE key_id = %d",
				$result['key_id']
			)
		);

		// The stored value should NOT match the legacy 'cs_<40hex>' format.
		$this->assertDoesNotMatchRegularExpression( '/^cs_[a-f0-9]{40}$/', $stored );
	}

	// ---------------------------------------------------------------------
	// Legacy key_type=1 passphrase enforcement (preserved per Codex finding 2)
	// ---------------------------------------------------------------------

	/**
	 * Seed an api_keys row with key_type=1 and a passphrase via direct DB
	 * insert. The public insert_rest_api_key() no longer writes these fields,
	 * so the test exercises a hypothetical pre-existing legacy row.
	 *
	 * @param string $key_pass Stored passphrase (plaintext, matches legacy schema).
	 * @return string The HMAC'd consumer_key suitable for get_user_data_by_consumer_key().
	 */
	private function seed_legacy_keytype1_row( string $key_pass ): string {
		global $wpdb;
		$user_id   = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$plain_ck  = 'ck_' . str_repeat( 'a', 40 );
		$hashed_ck = mainwp_api_hash( $plain_ck );
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_api_keys',
			array(
				'user_id'         => $user_id,
				'description'     => 'legacy key_type=1 fixture',
				'permissions'     => 'read',
				'consumer_key'    => $hashed_ck,
				'consumer_secret' => 'cs_' . str_repeat( 'b', 40 ),
				'truncated_key'   => substr( $plain_ck, -7 ),
				'enabled'         => 1,
				'key_pass'        => $key_pass,
				'key_type'        => 1,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
		);
		return $plain_ck;
	}

	public function test_legacy_keytype1_accepts_matching_passphrase(): void {
		$plain_ck = $this->seed_legacy_keytype1_row( 'correct-pass' );
		$_REQUEST['key_pass'] = 'correct-pass';
		$result = $this->invoke_auth_method( 'get_user_data_by_consumer_key', array( $plain_ck ) );
		$this->assertNotEmpty( $result );
		$this->assertNotFalse( $result );
		unset( $_REQUEST['key_pass'] );
	}

	public function test_legacy_keytype1_rejects_wrong_passphrase(): void {
		$plain_ck = $this->seed_legacy_keytype1_row( 'correct-pass' );
		$_REQUEST['key_pass'] = 'wrong-pass';
		$result = $this->invoke_auth_method( 'get_user_data_by_consumer_key', array( $plain_ck ) );
		$this->assertFalse( $result, 'Wrong passphrase must fail authentication' );
		unset( $_REQUEST['key_pass'] );
	}

	public function test_legacy_keytype1_rejects_missing_passphrase(): void {
		$plain_ck = $this->seed_legacy_keytype1_row( 'correct-pass' );
		// $_REQUEST['key_pass'] intentionally not set.
		$result = $this->invoke_auth_method( 'get_user_data_by_consumer_key', array( $plain_ck ) );
		$this->assertFalse( $result, 'Missing passphrase must fail authentication' );
	}

	public function test_legacy_keytype1_rejects_when_stored_passphrase_is_empty(): void {
		// Defensive: if a row somehow has key_type=1 with an empty key_pass,
		// auth must NOT succeed regardless of what the caller submits (no
		// hash_equals('','')==true bypass).
		$plain_ck = $this->seed_legacy_keytype1_row( '' );
		$_REQUEST['key_pass'] = '';
		$result = $this->invoke_auth_method( 'get_user_data_by_consumer_key', array( $plain_ck ) );
		$this->assertFalse( $result, 'Empty stored passphrase must not allow empty submission' );
		unset( $_REQUEST['key_pass'] );
	}
}
