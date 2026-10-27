<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit direct-file discovery matches the test class name.
/**
 * Global backup credential saves retain the previous key until persistence.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Credential_Render;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Settings;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility;

/** Exercise the real option writer, encryptor and key files in one process. */
class Test_Global_Backup_Credential_Persistence extends \WP_UnitTestCase {

	/**
	 * Key files created by this test.
	 *
	 * @var array
	 */
	private $keys = array();

	/**
	 * Directory entries before the fixture creates any key files.
	 *
	 * @var array
	 */
	private $initial_files;

	/** Prepare real encryption and an administrator for the settings callers. */
	public function setUp(): void {
		parent::setUp();
		MainWP_Keys_Manager::init_keys_dir();
		$this->initial_files = $this->key_files();
		add_filter( 'mainwp_encrypt_key_value', array( $this, 'record_key' ), 10 );
		add_filter( 'pre_http_request', array( $this, 'offline_response' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Remove only the exact fixture key names captured during encryption. */
	public function tearDown(): void {
		remove_filter( 'mainwp_encrypt_key_value', array( $this, 'record_key' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'offline_response' ) );
		foreach ( array_unique( $this->keys ) as $key ) {
			do_action( 'mainwp_delete_key_file', $key );
		}
		$this->assertCount( count( $this->initial_files ), $this->key_files(), 'Fixture key files must be removed in the shared process.' );
		$this->assertTrue( $this->key_files() === $this->initial_files, 'Fixture cleanup must preserve existing filenames.' );
		parent::tearDown();
	}

	/**
	 * Record filenames before a later filter rejects or throws.
	 *
	 * @param mixed $envelope Encryption result.
	 * @return mixed Unchanged result.
	 */
	public function record_key( $envelope ) {
		if ( is_array( $envelope ) && ! empty( $envelope['file_key'] ) ) {
			$this->keys[] = $envelope['file_key'];
		}
		return $envelope;
	}

	/**
	 * Prevent incidental provider requests from leaving the test process.
	 *
	 * @return array Offline response.
	 */
	public function offline_response(): array {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * List entries without reading key material.
	 *
	 * @return array Private key directory entries.
	 */
	private function key_files(): array {
		return glob( MainWP_Keys_Manager::get_keys_dir() . '*' );
	}

	/**
	 * Both rejection points cover replacement, first save and a named suboption.
	 *
	 * @return array Failure matrix.
	 */
	public static function failures(): array {
		$cases = array();
		foreach ( array( 'filter', 'write', 'sql' ) as $failure ) {
			foreach ( array( true, false ) as $existing ) {
				foreach ( array( '', 'access_token' ) as $variant ) {
					$cases[ $failure . '-' . ( $existing ? 'replace' : 'first' ) . '-' . ( $variant ?: 'api_key' ) ] = array( $failure, $existing, $variant );
				}
			}
		}
		return $cases;
	}

	/**
	 * Failed saves preserve decryptability and remove every staged key.
	 *
	 * @dataProvider failures
	 * @param string $failure Failure point.
	 * @param bool   $existing Whether a credential already exists.
	 * @param string $variant Optional credential name.
	 */
	public function test_failed_save_retains_previous_credential( string $failure, bool $existing, string $variant ): void {
		global $wpdb;
		$utility = Api_Backups_Utility::get_instance();
		$option  = 'mainwp_api_backups_cloudways_' . ( $variant ?: 'api_key' );
		if ( $existing ) {
			$this->assertTrue( $utility->update_api_key( 'cloudways', 'fixture-original', $variant ) );
		}
		$previous      = get_option( $option );
		$before        = $this->key_files();
		$reject        = static function () {
			return false;
		};
		$refuse        = static function ( $replacement, $old ) {
			return $old;
		};
		$failed_writes = 0;
		$fail_sql      = static function ( $query ) use ( $wpdb, $option, &$failed_writes ) {
			if ( preg_match( '/^(?:UPDATE|INSERT INTO) `' . preg_quote( $wpdb->options, '/' ) . '`/', $query ) && false !== strpos( $query, $option ) ) {
				++$failed_writes;
				return 'UPDATE mainwp_missing_mwp2015_fixture SET value = 1';
			}
			return $query;
		};
		$suppressed    = $wpdb->suppress_errors();
		if ( 'filter' === $failure ) {
			add_filter( 'mainwp_encrypt_key_value', $reject, 20 );
		} elseif ( 'sql' === $failure ) {
			add_filter( 'query', $fail_sql );
		} else {
			add_filter( 'pre_update_option_' . $option, $refuse, 10, 2 );
		}
		try {
			$result = $utility->update_api_key( 'cloudways', 'fixture-replacement', $variant );
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $reject, 20 );
			remove_filter( 'pre_update_option_' . $option, $refuse, 10 );
			remove_filter( 'query', $fail_sql );
			$wpdb->suppress_errors( $suppressed );
		}
		$this->assertTrue( get_option( $option ) === $previous, 'A failed save must keep the stored envelope.' );
		if ( $existing ) {
			$this->assertTrue( 'fixture-original' === $utility->get_api_key( 'cloudways', '', $variant ), 'The original credential must still decrypt.' );
			$this->assertFileExists( MainWP_Keys_Manager::get_keys_dir() . $previous['file_key'] );
		}
		$this->assertFalse( $result );
		$this->assertSame( 'sql' === $failure ? 1 : 0, $failed_writes );
		$this->assertCount( count( $before ), $this->key_files(), 'A failed save must leave the key-file count unchanged.' );
		$this->assertTrue( $this->key_files() === $before, 'A failed save must retain the same key filenames.' );
	}

	/**
	 * Successful saves stage a new key and retire only the previous key.
	 *
	 * @dataProvider credential_names
	 * @param string $variant Optional credential name.
	 */
	public function test_successful_replacement_retires_previous_key( string $variant ): void {
		$utility = Api_Backups_Utility::get_instance();
		$option  = 'mainwp_api_backups_cloudways_' . ( $variant ?: 'api_key' );
		$before  = count( $this->key_files() );
		foreach ( array( 'fixture-original', 'fixture-replacement' ) as $value ) {
			$this->assertTrue( $utility->update_api_key( 'cloudways', $value, $variant ) );
			$this->assertTrue( $utility->get_api_key( 'cloudways', '', $variant ) === $value, 'The saved credential must decrypt.' );
			$this->assertCount( $before + 1, $this->key_files() );
		}
		$this->assertCount( 2, $this->keys );
		$this->assertNotSame( $this->keys[0], $this->keys[1] );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $this->keys[0] );
		$this->assertFileExists( MainWP_Keys_Manager::get_keys_dir() . get_option( $option )['file_key'] );
	}

	/**
	 * Both names must retain the same option format.
	 *
	 * @return array Default and named credential options.
	 */
	public static function credential_names(): array {
		return array(
			'api_key'      => array( '' ),
			'access_token' => array( 'access_token' ),
		);
	}

	/** An unchanged valid envelope is saved even when update_option returns false. */
	public function test_unchanged_envelope_is_confirmed_by_readback(): void {
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_api_key( 'plesk', 'fixture-original' ) );
		$previous = get_option( 'mainwp_api_backups_plesk_api_key' );
		$before   = $this->key_files();
		$hooks    = clone $GLOBALS['wp_filter']['mainwp_encrypt_key_value'];
		$replay   = static function () use ( $previous ) {
			return $previous;
		};
		foreach ( $hooks->callbacks[10] as $callback ) {
			if ( is_array( $callback['function'] ) && 'hook_encrypt_key_value' === $callback['function'][1] ) {
				remove_filter( 'mainwp_encrypt_key_value', $callback['function'], 10 );
			}
		}
		add_filter( 'mainwp_encrypt_key_value', $replay, 9 );
		try {
			$result = $utility->update_api_key( 'plesk', 'fixture-original' );
		} finally {
			$GLOBALS['wp_filter']['mainwp_encrypt_key_value'] = $hooks;
		}
		$this->assertTrue( $result );
		$this->assertTrue( get_option( 'mainwp_api_backups_plesk_api_key' ) === $previous );
		$this->assertTrue( 'fixture-original' === $utility->get_api_key( 'plesk' ) );
		$this->assertSame( $before, $this->key_files() );
	}

	/** Sentinel and nonstrings remain no-ops; empty input deletes the option and key. */
	public function test_sentinel_nonstring_and_empty_value_keep_their_contract(): void {
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_api_key( 'plesk', 'fixture-original' ) );
		$previous = get_option( 'mainwp_api_backups_plesk_api_key' );
		$before   = $this->key_files();
		foreach ( array( MainWP_Credential_Render::SENTINEL, array(), null, false ) as $value ) {
			$this->assertTrue( $utility->update_api_key( 'plesk', $value ) );
			$this->assertTrue( get_option( 'mainwp_api_backups_plesk_api_key' ) === $previous );
			$this->assertSame( $before, $this->key_files() );
		}
		$this->assertTrue( $utility->update_api_key( 'plesk', '' ) );
		$this->assertFalse( get_option( 'mainwp_api_backups_plesk_api_key' ) );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $previous['file_key'] );
		$this->assertCount( count( $before ) - 1, $this->key_files() );
	}

	/** Throwing filters retain the old credential and remove the capture callback. */
	public function test_throwing_filter_preserves_previous_key_and_hook_cleanup(): void {
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_api_key( 'plesk', 'fixture-original' ) );
		$previous = get_option( 'mainwp_api_backups_plesk_api_key' );
		$before   = count( $this->key_files() );
		$hooks    = $GLOBALS['wp_filter']['mainwp_encrypt_key_value']->callbacks[10];
		$throw    = static function () {
			throw new \RuntimeException( 'Fixture encryption filter failure.' );
		};
		add_filter( 'mainwp_encrypt_key_value', $throw, 20 );
		$caught = false;
		try {
			$utility->update_api_key( 'plesk', 'fixture-replacement' );
		} catch ( \RuntimeException $error ) {
			$caught = true;
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $throw, 20 );
		}
		$this->assertTrue( $caught, 'Existing exception propagation must remain unchanged.' );
		$this->assertTrue( get_option( 'mainwp_api_backups_plesk_api_key' ) === $previous );
		$this->assertTrue( 'fixture-original' === $utility->get_api_key( 'plesk' ), 'A throwing filter must leave the original credential readable.' );
		$this->assertSame( $hooks, $GLOBALS['wp_filter']['mainwp_encrypt_key_value']->callbacks[10] );
		$this->assertCount( 2, $this->keys );
		$this->assertNotSame( $this->keys[0], $this->keys[1] );
		$this->assertFileExists( MainWP_Keys_Manager::get_keys_dir() . $previous['file_key'] );
		$this->assertFileExists( MainWP_Keys_Manager::get_keys_dir() . $this->keys[1] );
		// Match the per-site exception path; staged-file exception cleanup is a separate change.
		$this->assertCount( $before + 1, $this->key_files() );
	}

	/** The settings form reaches the same failure-safe writer with real encryption. */
	public function test_settings_form_rejection_preserves_previous_credential(): void {
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_api_key( 'plesk', 'fixture-original' ) );
		$before = $this->key_files();
		$post   = $_POST;
		$reject = static function () {
			return false;
		};
		$_POST  = array(
			'submit'               => '1',
			'wp_nonce_plesk'       => wp_create_nonce( 'cloudways_api_form_general' ),
			'mainwp_plesk_api_key' => 'fixture-replacement',
		);
		add_filter( 'mainwp_encrypt_key_value', $reject, 20 );
		ob_start();
		try {
			Api_Backups_Settings::render_3rd_party_api_manager();
		} finally {
			ob_end_clean();
			$_POST = $post;
			remove_filter( 'mainwp_encrypt_key_value', $reject, 20 );
		}
		$this->assertTrue( 'fixture-original' === $utility->get_api_key( 'plesk' ), 'The settings form must preserve the original credential after rejection.' );
		$this->assertSame( $before, $this->key_files() );
	}

	/** REST route dispatch retains the old credential when its replacement is rejected. */
	public function test_rest_rejection_preserves_previous_credential(): void {
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_api_key( 'plesk', 'fixture-original' ) );
		$before  = $this->key_files();
		$reject  = static function () {
			return false;
		};
		$request = new \WP_REST_Request( 'PUT', '/mainwp/v2/settings/api-backups/plesk/edit' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'secrets' => array( 'api_key' => 'fixture-replacement' ) ) ) );
		$auth          = \MainWP_REST_Authentication::get_instance();
		$original_rest = $GLOBALS['wp_rest_server'] ?? null;
		$rest_doing    = defined( 'MAINWP_REST_API_DOING' );
		$dispatch_hook = clone $GLOBALS['wp_filter']['rest_pre_dispatch'];
		// Authentication has a separate suite because it defines a process-wide constant.
		remove_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ), 10 );
		$user_property = new \ReflectionProperty( $auth, 'user' );
		$user_property->setAccessible( true );
		$original_user = $user_property->getValue( $auth );
		$user_property->setValue(
			$auth,
			(object) array(
				'permissions'  => 'read_write',
				'user_id'      => get_current_user_id(),
				'key_id'       => 0,
				'consumer_key' => 'fixture-rest-key',
			)
		);
		add_filter( 'mainwp_encrypt_key_value', $reject, 20 );
		try {
			$response = rest_do_request( $request );
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $reject, 20 );
			$user_property->setValue( $auth, $original_user );
			$GLOBALS['wp_rest_server']                 = $original_rest;
			$GLOBALS['wp_filter']['rest_pre_dispatch'] = $dispatch_hook;
		}
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $rest_doing, defined( 'MAINWP_REST_API_DOING' ), 'The credential route fixture must not change process-wide authentication state.' );
		$this->assertTrue( 'fixture-original' === $utility->get_api_key( 'plesk' ), 'The REST writer must preserve the original credential after rejection.' );
		$this->assertSame( $before, $this->key_files() );
	}

	/**
	 * A later filter cannot transfer cleanup ownership to another stored key.
	 *
	 * @dataProvider changed_filename_failures
	 * @param bool $invalid Whether to reject the final envelope instead of vetoing persistence.
	 */
	public function test_changed_filename_failure_preserves_both_credentials( bool $invalid ): void {
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_api_key( 'plesk', 'fixture-original' ) );
		$this->assertTrue( $utility->update_api_key( 'vultr', 'fixture-other-provider' ) );
		$previous = get_option( 'mainwp_api_backups_plesk_api_key' );
		$other    = get_option( 'mainwp_api_backups_vultr_api_key' );
		$before   = $this->key_files();
		$rename   = static function ( $envelope ) use ( $other, $invalid ) {
			$envelope['file_key'] = $other['file_key'];
			if ( $invalid ) {
				$envelope['encrypted_val'] = array( 'invalid-envelope-fixture' );
			}
			return $envelope;
		};
		$refuse   = static function ( $replacement, $old ) {
			return $old;
		};
		add_filter( 'mainwp_encrypt_key_value', $rename, 20 );
		if ( ! $invalid ) {
			add_filter( 'pre_update_option_mainwp_api_backups_plesk_api_key', $refuse, 10, 2 );
		}
		try {
			$result = $utility->update_api_key( 'plesk', 'fixture-replacement' );
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $rename, 20 );
			remove_filter( 'pre_update_option_mainwp_api_backups_plesk_api_key', $refuse, 10 );
		}
		$this->assertTrue( 'fixture-original' === $utility->decrypt_api_keys( $previous ), 'The previous provider credential must remain readable.' );
		$this->assertTrue( 'fixture-other-provider' === $utility->get_api_key( 'vultr' ), 'A later filter must not cause another provider key to be deleted.' );
		$this->assertFalse( $result );
		$this->assertTrue( get_option( 'mainwp_api_backups_plesk_api_key' ) === $previous );
		$this->assertTrue( get_option( 'mainwp_api_backups_vultr_api_key' ) === $other );
		$this->assertCount( count( $before ), $this->key_files() );
		$this->assertTrue( $this->key_files() === $before, 'Failed saves must preserve the exact original key filenames.' );
		$this->assertCount( 3, $this->keys );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $this->keys[2] );
	}

	/**
	 * Final envelope rejection and persistence rejection must use the staged name.
	 *
	 * @return array Failure points after a later filter changes the filename.
	 */
	public static function changed_filename_failures(): array {
		return array(
			'invalid-envelope' => array( true ),
			'failed-write'     => array( false ),
		);
	}

	/** Valid custom envelopes remain accepted while their unused staged key is removed. */
	public function test_valid_changed_filename_retires_only_unreferenced_keys(): void {
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_api_key( 'plesk', 'fixture-original' ) );
		$this->assertTrue( $utility->update_api_key( 'vultr', 'fixture-other-provider' ) );
		$previous = get_option( 'mainwp_api_backups_plesk_api_key' );
		$other    = get_option( 'mainwp_api_backups_vultr_api_key' );
		$before   = count( $this->key_files() );
		$custom   = static function () use ( $other ) {
			return $other;
		};
		add_filter( 'mainwp_encrypt_key_value', $custom, 20 );
		try {
			$result = $utility->update_api_key( 'plesk', 'fixture-replacement' );
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $custom, 20 );
		}
		$this->assertTrue( $result );
		$this->assertTrue( get_option( 'mainwp_api_backups_plesk_api_key' ) === $other );
		$this->assertTrue( 'fixture-other-provider' === $utility->get_api_key( 'plesk' ) );
		$this->assertTrue( 'fixture-other-provider' === $utility->get_api_key( 'vultr' ) );
		$this->assertCount( $before - 1, $this->key_files(), 'A valid custom envelope must not leave its unused staged key behind.' );
		$this->assertCount( 3, $this->keys );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $previous['file_key'] );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $this->keys[2] );
		$this->assertFileExists( MainWP_Keys_Manager::get_keys_dir() . $other['file_key'] );
	}

	/** A later filter can retain the old envelope without leaving the native staged key. */
	public function test_replayed_previous_envelope_removes_unused_staged_key(): void {
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_api_key( 'plesk', 'fixture-original' ) );
		$previous = get_option( 'mainwp_api_backups_plesk_api_key' );
		$before   = $this->key_files();
		$replay   = static function () use ( $previous ) {
			return $previous;
		};
		add_filter( 'mainwp_encrypt_key_value', $replay, 20 );
		try {
			$result = $utility->update_api_key( 'plesk', 'fixture-replacement' );
		} finally {
			remove_filter( 'mainwp_encrypt_key_value', $replay, 20 );
		}
		$this->assertTrue( $result );
		$this->assertTrue( get_option( 'mainwp_api_backups_plesk_api_key' ) === $previous );
		$this->assertTrue( 'fixture-original' === $utility->get_api_key( 'plesk' ) );
		$this->assertCount( count( $before ), $this->key_files(), 'Replaying the old envelope must remove the unused staged key.' );
		$this->assertTrue( $this->key_files() === $before );
		$this->assertCount( 2, $this->keys );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $this->keys[1] );
	}
}
