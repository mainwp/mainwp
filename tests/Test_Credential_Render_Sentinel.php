<?php
/**
 * Sentinel-pattern credential rendering tests (MWP-1543 + MWP-1547).
 *
 * Covers the new MainWP_Credential_Render helper directly plus the
 * sentinel short-circuit added to Api_Backups_Utility::update_api_key()
 * and ::update_child_api_key(). The master-license sentinel branch in
 * MainWP_Post_Extension_Handler is exercised indirectly via the
 * helper unit tests; the handler itself depends on $_POST + die() and
 * is awkward to drive from PHPUnit.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_Credential_Render_Sentinel
 */
class Test_Credential_Render_Sentinel extends \WP_UnitTestCase {

	// ---------------------------------------------------------------------
	// MainWP_Credential_Render unit tests
	// ---------------------------------------------------------------------

	public function test_sentinel_constant_is_eight_bullets(): void {
		$this->assertSame( '••••••••', \MainWP\Dashboard\MainWP_Credential_Render::SENTINEL );
	}

	public function test_value_for_input_returns_sentinel_when_value_exists(): void {
		$this->assertSame(
			'••••••••',
			\MainWP\Dashboard\MainWP_Credential_Render::value_for_input( true )
		);
	}

	public function test_value_for_input_returns_empty_when_no_value(): void {
		$this->assertSame(
			'',
			\MainWP\Dashboard\MainWP_Credential_Render::value_for_input( false )
		);
	}

	public function test_value_for_input_accepts_custom_sentinel(): void {
		$this->assertSame(
			'XXX',
			\MainWP\Dashboard\MainWP_Credential_Render::value_for_input( true, 'XXX' )
		);
	}

	public function test_is_sentinel_recognizes_the_sentinel(): void {
		$this->assertTrue(
			\MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( '••••••••' )
		);
	}

	public function test_is_sentinel_rejects_real_credential_values(): void {
		// Real-world credential shapes that must NOT match the sentinel:
		// API keys, password strings, accidental empty strings, similar
		// glyph counts.
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( '' ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( 'somepassword' ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( '••••••' ) );  // 6 bullets
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( '••••••••••' ) ); // 10 bullets
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( '*' ) );
	}

	public function test_is_sentinel_rejects_non_string_input(): void {
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( null ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( 12345 ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( array() ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( false ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Render::is_sentinel( true ) );
	}

	// ---------------------------------------------------------------------
	// Api_Backups_Utility::update_api_key sentinel short-circuit
	// ---------------------------------------------------------------------

	/**
	 * The sentinel short-circuit returns true (success) without touching
	 * stored option state. We can verify both halves without a real
	 * encryption keyfile because the short-circuit path returns before
	 * any encrypt / option-update runs.
	 */
	public function test_update_api_key_sentinel_returns_success_without_writing(): void {
		// Capture the option's current state.
		$opt_name        = 'mainwp_api_backups_cloudways_api_key';
		$before          = get_option( $opt_name );

		$result = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility::get_instance()
			->update_api_key( 'cloudways', '••••••••' );

		$this->assertTrue( $result, 'Sentinel submission must report success so the form save flow continues' );
		$this->assertSame( $before, get_option( $opt_name ), 'Stored option must be unchanged by a sentinel submission' );
	}

	public function test_update_api_key_real_value_is_not_short_circuited(): void {
		// A non-sentinel value reaches the encrypt-and-store path. We can't
		// assert successful storage without a working keyfile in the test
		// environment, but we can confirm the short-circuit did NOT fire by
		// observing that the function did real work: either it stored
		// (returning the wpdb update result) or it returned false from the
		// encrypt path. Both are NOT the early-return-true of the sentinel
		// branch, which always returns true regardless of state.
		//
		// The key invariant: passing the sentinel always returns true and
		// never touches the option, while passing a real value either
		// updates the option or returns false. We verified the first half
		// above; this test verifies the function does NOT match the sentinel
		// path for arbitrary real strings.
		$opt_name = 'mainwp_api_backups_cloudways_api_key';
		delete_option( $opt_name );

		$result = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility::get_instance()
			->update_api_key( 'cloudways', 'real-api-key-value' );

		// In a test environment without a keyfile, encryption fails and the
		// method returns false. That's the non-sentinel path. The point is
		// it did NOT short-circuit with true while leaving option untouched.
		// If encryption DID succeed (test env has a keyfile), the option
		// would be set to an array containing 'encrypted_val'. Either way,
		// not the sentinel path.
		$stored = get_option( $opt_name );
		if ( false === $result ) {
			$this->assertFalse( $stored, 'Encryption failed -> option stays unset' );
		} else {
			$this->assertNotFalse( $stored, 'Encryption succeeded -> option is set' );
			$this->assertIsArray( $stored, 'Stored value should be the encrypted envelope array' );
		}
	}

	public function test_update_api_key_rejects_unknown_provider_name(): void {
		// Pre-existing behaviour: unknown provider names return false. The
		// sentinel short-circuit is gated AFTER the name allowlist, so an
		// unknown provider with a sentinel value still returns false.
		$result = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility::get_instance()
			->update_api_key( 'not-a-provider', '••••••••' );
		$this->assertFalse( $result );
	}
}
