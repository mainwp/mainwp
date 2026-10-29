<?php
/**
 * REST credential input normalization tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_REST_Credential_Input
 */
class Test_REST_Credential_Input extends \WP_UnitTestCase {

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

	/**
	 * Register encryption stubs.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'mainwp_encrypt_key_value', array( $this, 'stub_encrypt' ), 10, 4 );
		add_filter( 'mainwp_decrypt_key_value', array( $this, 'stub_decrypt' ), 10, 3 );
	}

	/**
	 * Remove encryption stubs.
	 */
	public function tearDown(): void {
		remove_filter( 'mainwp_encrypt_key_value', array( $this, 'stub_encrypt' ), 10 );
		remove_filter( 'mainwp_decrypt_key_value', array( $this, 'stub_decrypt' ), 10 );
		parent::tearDown();
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

	/**
	 * Scalar REST credentials are encrypted after conversion to strings.
	 */
	public function test_rest_credentials_normalize_scalars_before_encryption(): void {
		global $wpdb;
		$site_id = $this->seed_site_with_envelope( 'rest_scalar_seed' );
		$table   = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		foreach ( array( 12345, 12.5, true, false, null ) as $value ) {
			$result = \MainWP\Dashboard\MainWP_DB_Common::instance()->rest_api_update_website(
				$site_id,
				array(
					'http_user' => $value,
					'http_pass' => $value,
				)
			);
			$this->assertNotFalse( $result );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT http_user, http_pass FROM %i WHERE id = %d', $table, $site_id ) );
			foreach ( array( 'http_user', 'http_pass' ) as $field ) {
				$expected = null === $value ? '' : (string) $value;
				if ( '' === $expected ) {
					$this->assertSame( '', $row->{$field} );
				} else {
					$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $row->{$field}, 'Scalar credentials must never be stored in plaintext.' );
					$this->assertSame( $expected, \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row->{$field} ) );
				}
			}
		}
	}

	/**
	 * Invalid credentials reject the update before any encryption is staged.
	 */
	public function test_rest_credentials_reject_non_scalars(): void {
		global $wpdb;
		$site_id = $this->seed_site_with_envelope( 'rest_invalid_seed' );
		$table   = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$before  = $wpdb->get_row( $wpdb->prepare( 'SELECT http_user, http_pass FROM %i WHERE id = %d', $table, $site_id ) );
		foreach ( array( array( 'secret' ), (object) array( 'secret' => 'value' ) ) as $invalid ) {
			foreach ( array( 'http_user', 'http_pass' ) as $field ) {
				$data           = array(
					'http_user' => 'replacement',
					'http_pass' => 'replacement',
				);
				$data[ $field ] = $invalid;
				$this->assertFalse( \MainWP\Dashboard\MainWP_DB_Common::instance()->rest_api_update_website( $site_id, $data ) );
				$this->assertEquals( $before, $wpdb->get_row( $wpdb->prepare( 'SELECT http_user, http_pass FROM %i WHERE id = %d', $table, $site_id ) ) );
			}
		}
	}
}
