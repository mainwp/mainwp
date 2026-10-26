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

	use Site_Fixture;

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

	/**
	 * Failed encryption leaves the previous per-site credential unchanged.
	 *
	 * @dataProvider failed_child_credential_envelopes
	 * @param mixed $envelope Encryption filter result.
	 */
	public function test_child_api_key_encryption_failure_preserves_previous_value( $envelope ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'mainwp_wp', [ 'name' => 'Backup encryption fixture', 'url' => 'https://test-backup-encryption.example.com/', 'userid' => 1 ] );
		$site_id  = (int) $wpdb->insert_id;
		$option   = 'mainwp_api_backups_cloudways_api_key';
		$previous = wp_json_encode( [ 'encrypted_val' => 'synthetic-previous-ciphertext', 'file_key' => '' ] );
		\MainWP\Dashboard\MainWP_DB::instance()->update_website_option( $site_id, $option, $previous );
		$key_files = [];
		$failure = static function ( $result ) use ( $envelope, &$key_files ) {
			if ( is_array( $result ) && ! empty( $result['file_key'] ) ) {
				$key_files[] = $result['file_key'];
			}
			return $envelope;
		};
		add_filter( 'mainwp_encrypt_key_value', $failure, PHP_INT_MAX );
		try {
			$result = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility::get_instance()->update_child_api_key( $site_id, 'cloudways', 'synthetic-new-provider-secret' );
			$this->assertSame( $previous, \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option ), 'Failed encryption must not replace the stored credential.' );
			$this->assertFalse( $result, 'The caller must receive a failed save result.' );
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $failure, PHP_INT_MAX );
			foreach ( $key_files as $file_key ) {
				do_action( 'mainwp_delete_key_file', $file_key );
			}
		}
	}

	/**
	 * Encryption failures include incomplete envelopes.
	 *
	 * @return array Failed encryption results.
	 */
	public static function failed_child_credential_envelopes(): array {
		return [
			'failure'          => [ false ],
			'missing-key-file' => [ [ 'encrypted_val' => 'synthetic-ciphertext' ] ],
			'empty-ciphertext' => [ [ 'encrypted_val' => '', 'file_key' => 'synthetic-key-file' ] ],
		];
	}

	/**
	 * Rejected replacements retain the original ciphertext and its real key.
	 *
	 * @dataProvider child_credential_replacement_failures
	 * @param string $failure Failure to inject after encryption.
	 */
	public function test_child_api_key_replacement_failure_preserves_decryption( string $failure ): void {
		global $wpdb;
		$site_id        = $this->create_test_site();
		$option         = 'mainwp_api_backups_cloudways_api_key';
		$utility        = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility::get_instance();
		$key_dir        = \MainWP\Dashboard\MainWP_Keys_Manager::get_keys_dir();
		$before         = glob( $key_dir . '*' );
		$key_files      = array();
		$capture        = static function ( $envelope ) use ( &$key_files ) {
			if ( is_array( $envelope ) && ! empty( $envelope['file_key'] ) ) {
				$key_files[] = $envelope['file_key'];
			}
			return $envelope;
		};
		$invalid        = static function () {
			return false;
		};
		$failed_updates = 0;
		$fail_update    = static function ( $query ) use ( $wpdb, $option, &$failed_updates ) {
			if ( 0 === strpos( $query, 'UPDATE `' . $wpdb->prefix . 'mainwp_wp_options`' ) && false !== strpos( $query, $option ) ) {
				++$failed_updates;
				return 'UPDATE mainwp_missing_sec37_fixture_table SET value = 1';
			}
			return $query;
		};
		$suppressed     = $wpdb->suppress_errors();
		add_filter( 'mainwp_encrypt_key_value', $capture, 11 );
		try {
			$this->assertNotFalse( $utility->update_child_api_key( $site_id, 'cloudways', 'synthetic-original-secret' ) );
			$previous = \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option );
			$original = json_decode( $previous, true );
			$this->assertSame( 'synthetic-original-secret', $utility->decrypt_api_keys( $original ) );
			$this->assertCount( count( $before ) + 1, glob( $key_dir . '*' ) );
			if ( 'invalid-envelope' === $failure ) {
				add_filter( 'mainwp_encrypt_key_value', $invalid, 11 );
			} else {
				add_filter( 'query', $fail_update );
			}
			$result = $utility->update_child_api_key( $site_id, 'cloudways', 'synthetic-replacement-secret' );
			$this->assertSame( $previous, \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option ) );
			$this->assertSame( 'synthetic-original-secret', $utility->decrypt_api_keys( $original ), 'The saved envelope must still decrypt after a rejected replacement.' );
			$this->assertFalse( $result );
			$this->assertFileExists( $key_dir . $original['file_key'] );
			$this->assertCount( 2, $key_files );
			$this->assertNotSame( $key_files[0], $key_files[1] );
			$this->assertFileDoesNotExist( $key_dir . $key_files[1] );
			$this->assertCount( count( $before ) + 1, glob( $key_dir . '*' ) );
			$this->assertSame( 'failed-update' === $failure ? 1 : 0, $failed_updates );
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $capture, 11 );
			remove_filter( 'mainwp_encrypt_key_value', $invalid, 11 );
			remove_filter( 'query', $fail_update );
			$wpdb->suppress_errors( $suppressed );
			foreach ( array_unique( $key_files ) as $file_key ) {
				do_action( 'mainwp_delete_key_file', $file_key );
			}
		}
		$this->assertSame( $before, glob( $key_dir . '*' ) );
	}

	/**
	 * Replacement failure paths.
	 *
	 * @return array Failure cases.
	 */
	public static function child_credential_replacement_failures(): array {
		return array(
			'invalid-envelope' => array( 'invalid-envelope' ),
			'failed-update'    => array( 'failed-update' ),
		);
	}

	/** Successful replacement retires only the previous credential key. */
	public function test_child_api_key_replacement_retires_previous_key(): void {
		$site_id   = $this->create_test_site();
		$option    = 'mainwp_api_backups_cloudways_api_key';
		$utility   = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility::get_instance();
		$key_dir   = \MainWP\Dashboard\MainWP_Keys_Manager::get_keys_dir();
		$before    = glob( $key_dir . '*' );
		$key_files = array();
		$capture   = static function ( $envelope ) use ( &$key_files ) {
			if ( is_array( $envelope ) && ! empty( $envelope['file_key'] ) ) {
				$key_files[] = $envelope['file_key'];
			}
			return $envelope;
		};
		add_filter( 'mainwp_encrypt_key_value', $capture, 11 );
		try {
			foreach ( array( 'synthetic-original-secret', 'synthetic-replacement-secret' ) as $value ) {
				$this->assertNotFalse( $utility->update_child_api_key( $site_id, 'cloudways', $value ) );
				$stored = json_decode( \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option ), true );
				$this->assertSame( $value, $utility->decrypt_api_keys( $stored ) );
				$this->assertFileExists( $key_dir . $stored['file_key'] );
				$this->assertCount( count( $before ) + 1, glob( $key_dir . '*' ) );
			}
			$this->assertCount( 2, $key_files );
			$this->assertNotSame( $key_files[0], $key_files[1] );
			$this->assertFileDoesNotExist( $key_dir . $key_files[0] );
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $capture, 11 );
			foreach ( array_unique( $key_files ) as $file_key ) {
				do_action( 'mainwp_delete_key_file', $file_key );
			}
		}
		$this->assertSame( $before, glob( $key_dir . '*' ) );
	}

	/** A same-name replacement must not retire the key used by the saved envelope. */
	public function test_child_api_key_filename_collision_preserves_saved_key(): void {
		$site_id   = $this->create_test_site();
		$option    = 'mainwp_api_backups_cloudways_api_key';
		$utility   = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility::get_instance();
		$key_dir   = \MainWP\Dashboard\MainWP_Keys_Manager::get_keys_dir();
		$before    = glob( $key_dir . '*' );
		$key_files = array();
		$deleted   = array();
		$collision = null;
		$observe   = static function ( $file_key ) use ( &$deleted ) {
			$deleted[] = $file_key;
		};
		try {
			$this->assertTrue( $utility->update_child_api_key( $site_id, 'cloudways', 'synthetic-original-secret' ) );
			$original    = json_decode( \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option ), true );
			$key_files[] = $original['file_key'];
			$collision   = function ( $envelope ) use ( $key_dir, $original, &$key_files ) {
				$key_files[] = $envelope['file_key'];
				$this->assertTrue( rename( $key_dir . $envelope['file_key'], $key_dir . $original['file_key'] ) );
				$envelope['file_key'] = $original['file_key'];
				return $envelope;
			};
			add_filter( 'mainwp_encrypt_key_value', $collision, 11 );
			add_action( 'mainwp_delete_key_file', $observe, 1 );
			$this->assertTrue( $utility->update_child_api_key( $site_id, 'cloudways', 'synthetic-replacement-secret' ) );
			$this->assertSame( array(), $deleted, 'A filename shared by both envelopes must not be deleted.' );
			$stored = json_decode( \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option ), true );
			$this->assertSame( $original['file_key'], $stored['file_key'] );
			$this->assertSame( 'synthetic-replacement-secret', $utility->decrypt_api_keys( $stored ) );
			$this->assertFileExists( $key_dir . $original['file_key'] );
			$this->assertCount( count( $before ) + 1, glob( $key_dir . '*' ) );
		} finally {
			if ( null !== $collision ) {
				remove_filter( 'mainwp_encrypt_key_value', $collision, 11 );
			}
			remove_action( 'mainwp_delete_key_file', $observe, 1 );
			foreach ( array_unique( $key_files ) as $file_key ) {
				do_action( 'mainwp_delete_key_file', $file_key );
			}
		}
		$this->assertSame( $before, glob( $key_dir . '*' ) );
	}

	/**
	 * A successful per-site save stores a decryptable encryption envelope.
	 */
	public function test_child_api_key_success_stores_encrypted_value(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'mainwp_wp', [ 'name' => 'Backup encryption fixture', 'url' => 'https://test-backup-encryption.example.com/', 'userid' => 1 ] );
		$site_id = (int) $wpdb->insert_id;
		$option  = 'mainwp_api_backups_cloudways_api_key';
		$utility = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility::get_instance();
		$stored  = [];
		try {
			$this->assertNotFalse( $utility->update_child_api_key( $site_id, 'cloudways', 'synthetic-provider-secret' ) );
			$raw    = \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option );
			$stored = json_decode( $raw, true );
			$this->assertIsArray( $stored );
			$this->assertNotEmpty( $stored['encrypted_val'] );
			$this->assertNotEmpty( $stored['file_key'] );
			$this->assertStringNotContainsString( 'synthetic-provider-secret', $raw );
			$this->assertSame( 'synthetic-provider-secret', $utility->get_child_api_key( $site_id, 'cloudways' ) );
		} finally {
			if ( is_array( $stored ) && ! empty( $stored['file_key'] ) ) {
				do_action( 'mainwp_delete_key_file', $stored['file_key'] );
			}
		}
	}

	/**
	 * The site edit save reports failed encryption and clears the notice after a successful save.
	 */
	public function test_site_edit_reports_child_credential_save_failure(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		$site_id = $this->create_test_site( [ 'name' => 'Backup settings fixture', 'url' => 'https://test-backup-settings.example.com/', 'userid' => $user_id ] );
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		set_current_screen( 'dashboard' );
		$render_site = static function () use ( $site_id ) {
			ob_start();
			try {
				\MainWP\Dashboard\MainWP_Manage_Sites_View::render_edit_site( $site_id, true );
				return ob_get_contents();
			} finally {
				ob_end_clean();
			}
		};
		$option   = 'mainwp_api_backups_cpanel_api_key';
		$previous = wp_json_encode( [ 'encrypted_val' => 'synthetic-previous-ciphertext', 'file_key' => '' ] );
		\MainWP\Dashboard\MainWP_DB::instance()->update_website_option( $site_id, $option, $previous );
		$old_settings = \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Settings::get_instance();
		remove_action( 'mainwp_update_site', [ $old_settings, 'hook_mainwp_update_site' ] );
		remove_action( 'mainwp_manage_sites_edit', [ $old_settings, 'hook_render_mainwp_manage_sites_edit' ] );
		remove_action( 'mainwp_after_header', [ $old_settings, 'hook_render_credential_save_error' ] );
		$settings = new \MainWP\Dashboard\Module\ApiBackups\Api_Backups_Settings();
		\MainWP\Dashboard\Module\ApiBackups\Api_Backups_Settings::$instance = $settings;
		$original_post = $_POST;
		$key_files     = [];
		$failure = static function ( $result ) use ( &$key_files ) {
			if ( is_array( $result ) && ! empty( $result['file_key'] ) ) {
				$key_files[] = $result['file_key'];
			}
			return false;
		};
		$_POST = [
			'mainwp_managesites_edit_module_api_backups_provider' => 4,
			'mainwp_cpanel_account_password' => 'synthetic-new-provider-secret',
			'mainwp_plesk_api_key' => \MainWP\Dashboard\MainWP_Credential_Render::SENTINEL,
			'mainwp_kinsta_api_key' => \MainWP\Dashboard\MainWP_Credential_Render::SENTINEL,
		];
		add_filter( 'mainwp_encrypt_key_value', $failure, PHP_INT_MAX );
		try {
			do_action( 'mainwp_update_site', $site_id );
			ob_start();
			do_action( 'mainwp_after_header', [] );
			$notice = ob_get_clean();
			$this->assertStringContainsString( 'Some backup credentials could not be saved. Their previous values were kept.', $notice );
			$this->assertTrue( $settings->has_credential_save_failed() );
			$failed_page = $render_site();
			$this->assertStringContainsString( 'id="mainwp-edit-site"', $failed_page );
			$this->assertStringNotContainsString( 'Child site settings saved successfully.', $failed_page );
			$this->assertSame( $previous, \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option ) );
			remove_filter( 'mainwp_encrypt_key_value', $failure, PHP_INT_MAX );
			foreach ( [ \MainWP\Dashboard\MainWP_Credential_Render::SENTINEL, 'synthetic-valid-provider-secret' ] as $value ) {
				$_POST['mainwp_cpanel_account_password'] = $value;
				do_action( 'mainwp_update_site', $site_id );
				$this->assertFalse( $settings->has_credential_save_failed() );
				$this->assertStringContainsString( 'Child site settings saved successfully.', $render_site() );
				ob_start();
				do_action( 'mainwp_after_header', [] );
				$this->assertStringNotContainsString( 'Some backup credentials could not be saved.', ob_get_clean() );
			}
			$stored = json_decode( \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option ), true );
			$this->assertNotEmpty( $stored['encrypted_val'] );
			$this->assertNotEmpty( $stored['file_key'] );
			$key_files[] = $stored['file_key'];
		} finally {
			$_POST = $original_post;
			remove_filter( 'mainwp_encrypt_key_value', $failure, PHP_INT_MAX );
			\MainWP\Dashboard\Module\ApiBackups\Api_Backups_Settings::$instance = $old_settings;
			$stored = json_decode( \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site_id, $option ), true );
			if ( is_array( $stored ) && ! empty( $stored['file_key'] ) ) {
				$key_files[] = $stored['file_key'];
			}
			foreach ( array_unique( $key_files ) as $file_key ) {
				do_action( 'mainwp_delete_key_file', $file_key );
			}
		}
	}
}
