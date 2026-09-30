<?php
/**
 * Connect request nonce shape tests.
 *
 * Pins the nonce that MainWP_Connect puts in every signed Child request
 * to 16 random bytes, hex-encoded. The Child stores a hash of every legacy
 * signature it accepts and refuses a repeat. Signing is deterministic, so a
 * nonce drawn from a small range reproduces an earlier signature and gets
 * the new request refused.
 *
 * The fixture is an in-memory site object with a freshly generated RSA
 * key, so every call signs for real through openssl. The properties
 * support_advanced_sign, signature_algo and verify_method are set on the
 * object so get_website_option() and get_connect_sign_algorithm() read
 * them from the object and never query the wp_options table.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect;
use ReflectionMethod;
use WP_UnitTestCase;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Connect_Request_Nonce extends WP_UnitTestCase {

	const NONCE_PATTERN = '/^[0-9a-f]{32}$/';

	/**
	 * Site fixture shared by the tests.
	 *
	 * @var object
	 */
	private $website;

	public function setUp(): void {
		parent::setUp();

		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$this->assertNotFalse( $key, 'openssl_pkey_new() failed; the test needs a real RSA key.' );

		$pem = '';
		$this->assertTrue( openssl_pkey_export( $key, $pem ), 'openssl_pkey_export() failed.' );

		// A site id with no per-site key file, so decrypt_privkey() returns
		// '' and connect_sign() falls back to the raw PEM.
		$this->website = (object) array(
			'id'                    => 987654321,
			'url'                   => 'https://nonce-test.example/',
			'adminname'             => 'admin',
			'privkey'               => base64_encode( $pem ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- privkey is stored base64-encoded.
			'verify_method'         => 1,
			'signature_algo'        => OPENSSL_ALGO_SHA256,
			'support_advanced_sign' => 0,
			'verify_certificate'    => 1,
			'http_user'             => '',
			'http_pass'             => '',
		);
	}

	/**
	 * Parse the query string get_post_data_authed() returns.
	 *
	 * @return array Request data.
	 */
	private function post_data() {
		$query = MainWP_Connect::get_post_data_authed( $this->website, 'stats' );
		$this->assertIsString( $query );

		$data = array();
		parse_str( $query, $data );
		return $data;
	}

	public function test_post_data_nonce_is_32_char_lowercase_hex(): void {
		$data = $this->post_data();

		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertMatchesRegularExpression( self::NONCE_PATTERN, (string) $data['nonce'] );
	}

	public function test_post_data_nonce_and_signature_are_distinct_across_calls(): void {
		$nonces     = array();
		$signatures = array();

		for ( $i = 0; $i < 20; $i++ ) {
			$data = $this->post_data();

			$this->assertNotEmpty( $data['mainwpsignature'], 'Signing failed; the fixture key did not produce a signature.' );

			$nonces[]     = $data['nonce'];
			$signatures[] = $data['mainwpsignature'];
		}

		$this->assertSame( 20, count( array_unique( $nonces ) ) );
		$this->assertSame( 20, count( array_unique( $signatures ) ) );
	}

	public function test_get_data_nonce_is_32_char_lowercase_hex(): void {
		$params = MainWP_Connect::get_get_data_authed( $this->website, 'x', 'where', true );

		$this->assertIsArray( $params );
		$this->assertArrayHasKey( 'nonce', $params );
		$this->assertMatchesRegularExpression( self::NONCE_PATTERN, (string) $params['nonce'] );
	}

	public function test_renew_post_data_nonce_is_32_char_lowercase_hex(): void {
		$method = new ReflectionMethod( MainWP_Connect::class, 'get_renew_post_data_authed' );
		$method->setAccessible( true );

		$website = $this->website;
		$query   = $method->invokeArgs( null, array( &$website, 'renew' ) );
		$this->assertIsString( $query );

		$data = array();
		parse_str( $query, $data );

		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertMatchesRegularExpression( self::NONCE_PATTERN, (string) $data['nonce'] );
	}
}
