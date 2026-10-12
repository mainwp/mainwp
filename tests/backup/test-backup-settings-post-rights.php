<?php
/**
 * Backup settings writes require the Dashboard settings right.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Diagnostics;
use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Settings;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_Settings;

/** Shared settings authorization and allowed fields. */
class MainWP_Backup_Settings_Post_Rights_Test extends MainWP_Abilities_Test_Case {

	/** An administrator isolates the granular right from WordPress capabilities. */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
	}

	/** Recovery key files are outside WordPress's transaction cleanup. */
	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		MainWP_Keys_Manager::instance()->delete_key_file( MainWP_Backup_Encryption::instance()->kek_filename() );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );
		parent::tearDown();
	}

	/**
	 * A valid nonce must not substitute for the operation right.
	 *
	 * @dataProvider rights
	 * @param bool $allowed Whether the settings right is held.
	 */
	public function test_post_checks_right_before_any_write( bool $allowed ): void {
		$encryption = MainWP_Backup_Encryption::instance();
		$original   = $encryption->set_recovery_passphrase( 'original settings recovery passphrase' );
		update_option( 'mainwp_enableNativeBackups', 0 );
		update_option( 'mainwp_on_demand_retention_count', 5 );
		$_POST = array(
			'submit'                                    => '1',
			'wp_nonce'                                  => wp_create_nonce( 'Settings' ),
			'mainwp_options_enableNativeBackups'        => '1',
			'mainwp_options_onDemandRetentionCount'     => '9',
			'mainwp_options_backupEncryptionPassphrase' => 'replacement settings recovery passphrase',
			'mainwp_options_backupEncryptionCurrentPassphrase' => 'original settings recovery passphrase',
		);
		$right = static function ( $can, $type, $key ) use ( $allowed ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $key ? $allowed : $can;
		};
		add_filter( 'mainwp_currentusercan', $right, 10, 3 );
		try {
			$saved = MainWP_Backup_Settings::handle_settings_post();
			$this->assertSame( $allowed ? 1 : 0, (int) get_option( 'mainwp_enableNativeBackups' ) );
			$this->assertSame( $allowed ? 9 : 5, (int) get_option( 'mainwp_on_demand_retention_count' ) );
			if ( $allowed ) {
				$this->assertNotSame( $original, $encryption->get_recovery_blob() );
			} else {
				$this->assertSame( $original, $encryption->get_recovery_blob() );
			}
			$this->assertSame( $allowed, $saved );
		} finally {
			remove_filter( 'mainwp_currentusercan', $right, 10 );
		}
	}

	/**
	 * Both sides of the right must leave the nonce valid.
	 *
	 * @return array Granted and refused rights.
	 */
	public static function rights(): array {
		return array(
			'refused' => array( false ),
			'held'    => array( true ),
		);
	}

	/**
	 * A refused passphrase still allows unrelated backup settings to save.
	 *
	 * @dataProvider recovery_proof
	 * @param string $current Current passphrase.
	 * @param bool   $lost Whether the key file is missing.
	 * @param string $flag Expected rejection flag.
	 * @param bool   $foreign Whether the key file differs from the stored recovery key.
	 */
	public function test_settings_recovery_replacement_proof( string $current, bool $lost, string $flag, bool $foreign = false ): void {
		$enc      = MainWP_Backup_Encryption::instance();
		$original = $enc->set_recovery_passphrase( 'original recovery passphrase' );
		$kek      = $enc->load_kek();
		if ( $lost ) {
			MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		} elseif ( $foreign ) {
			MainWP_Keys_Manager::instance()->save_key_file( $enc->kek_filename(), random_bytes( 32 ) );
		}
		$key_bytes = MainWP_Keys_Manager::instance()->get_key_val( $enc->kek_filename() );
		$_POST     = array(
			'submit'                                    => '1',
			'wp_nonce'                                  => wp_create_nonce( 'Settings' ),
			'mainwp_options_backupEncryptionPassphrase' => 'replacement recovery passphrase',
			'mainwp_options_backupEncryptionCurrentPassphrase' => wp_slash( $current ),
			'mainwp_options_onDemandRetentionCount'     => '9',
		);
		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
		$this->assertSame( $flag, MainWP_Backup_Settings::recovery_passphrase_rejection() );
		$this->assertSame( 9, MainWP_Backup_Settings::get_on_demand_retention_count() );
		if ( '' !== $flag ) {
			$this->assertSame( serialize( $original ), serialize( $enc->get_recovery_blob() ) );
			$this->assertSame( $key_bytes, MainWP_Keys_Manager::instance()->get_key_val( $enc->kek_filename() ) );
		} else {
			$this->assertNotSame( $original, $enc->get_recovery_blob() );
			$this->assertSame( $kek, $enc->load_kek() );
			$this->assertSame( $kek, $enc->recover_kek( 'replacement recovery passphrase', $enc->get_recovery_blob() ) );
		}
	}

	/**
	 * The same proof applies when recovering a lost key file.
	 *
	 * @return array Proof and key-file states.
	 */
	public static function recovery_proof(): array {
		return array(
			'missing current' => array( '', false, 'current' ),
			'wrong current'   => array( 'wrong recovery passphrase', false, 'current_mismatch' ),
			'right current'   => array( 'original recovery passphrase', false, '' ),
			'lost missing'    => array( '', true, 'current' ),
			'lost wrong'      => array( 'wrong recovery passphrase', true, 'mismatch' ),
			'lost right'      => array( 'original recovery passphrase', true, '' ),
			'foreign KEK'     => array( 'original recovery passphrase', false, 'key_mismatch', true ),
		);
	}

	/**
	 * A Settings nonce alone cannot establish the first recovery passphrase.
	 *
	 * @dataProvider account_passwords
	 * @param string $password Submitted account password.
	 * @param bool   $accepted Whether the password matches.
	 */
	public function test_settings_first_passphrase_requires_account_password( string $password, bool $accepted ): void {
		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );
		wp_set_current_user(
			$this->factory->user->create(
				array(
					'role'      => 'administrator',
					'user_pass' => 'WordPress password \\ " proof',
				)
			)
		);
		$_POST = array(
			'submit'                                    => '1',
			'wp_nonce'                                  => wp_create_nonce( 'Settings' ),
			'mainwp_options_backupEncryptionPassphrase' => 'first recovery passphrase',
			'mainwp_options_backupEncryptionAccountPassword' => wp_slash( $password ),
			'mainwp_options_onDemandRetentionCount'     => '9',
		);
		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
		$this->assertSame( $accepted ? '' : 'account_password', MainWP_Backup_Settings::recovery_passphrase_rejection() );
		$this->assertSame( 9, MainWP_Backup_Settings::get_on_demand_retention_count() );
		if ( $accepted ) {
			$this->assertSame( $enc->load_kek(), $enc->recover_kek( 'first recovery passphrase', $enc->get_recovery_blob() ) );
		} else {
			$this->assertSame( array(), $enc->get_recovery_blob() );
			$this->assertSame( '', MainWP_Keys_Manager::instance()->get_key_val( $enc->kek_filename() ) );
		}
	}

	/**
	 * Password punctuation must survive the Settings form's unslashing.
	 *
	 * @return array Account password attempts.
	 */
	public static function account_passwords(): array {
		return array(
			'missing' => array( '', false ),
			'wrong'   => array( 'wrong WordPress password', false ),
			'right'   => array( 'WordPress password \\ " proof', true ),
		);
	}

	/**
	 * The generic writer cannot bypass recovery passphrase proof through collated names.
	 *
	 * @dataProvider recovery_option_names
	 * @param string $name Option suffix.
	 * @param string $message Refusal copy.
	 */
	public function test_ajax_save_setting_refuses_recovery_blob( string $name, string $message ): void {
		$enc      = MainWP_Backup_Encryption::instance();
		$original = $enc->set_recovery_passphrase( 'original recovery passphrase' );
		$response = json_decode( $this->ajax_save_setting( $name ), true );
		// A collated alias can overwrite the row while the canonical cache stays stale.
		wp_cache_delete( MainWP_Backup_Encryption::RECOVERY_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertSame( $original, $enc->get_recovery_blob() );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( $message, $response['error'] );
		$this->assertSame( $response['error'], $response['result'] );
	}

	/**
	 * Non-ASCII aliases can compare equal to the recovery option in MySQL.
	 *
	 * @return array Option names and refusal copy.
	 */
	public static function recovery_option_names(): array {
		$message = 'The recovery passphrase cannot be saved here. Change it in the backup settings.';
		return array(
			'exact'      => array( 'backup_recovery_kek', $message ),
			'upper case' => array( 'BACKUP_RECOVERY_KEK', $message ),
			'markup'     => array( '<b>backup_recovery_kek</b>', $message ),
			'accented'   => array( 'backup_recovery_kék', 'This setting name is not valid.' ),
			'zero width' => array( "backup_recovery_kek\u{200B}", 'This setting name is not valid.' ),
		);
	}

	/**
	 * An administrator's nonce cannot bypass the settings right on generic writes.
	 *
	 * @dataProvider rights
	 * @param bool $allowed Whether the settings right is held.
	 */
	public function test_ajax_save_settings_checks_right_before_backup_option_writes( bool $allowed ): void {
		update_option( 'mainwp_backup_encryption_enabled', 1 );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$right = static function ( $can, $type, $key ) use ( $allowed ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $key ? $allowed : $can;
		};
		add_filter( 'mainwp_currentusercan', $right, 10, 3 );
		try {
			foreach ( array( 'backup_encryption_enabled', 'enableNativeBackups' ) as $name ) {
				$response = $this->ajax_save_setting( $name );
				$this->assertSame( $allowed ? 0 : 1, (int) get_option( 'mainwp_' . $name ) );
				if ( ! $allowed ) {
					$message = 'Error - insufficient permissions!';
					$this->assertSame(
						array(
							'success' => false,
							'result'  => $message,
							'error'   => $message,
						),
						json_decode( $response, true )
					);
				}
			}
			$this->assertSame( $allowed ? 0 : 1, (int) get_option( 'mainwp_backup_encryption_enabled' ) );
			$this->assertSame( $allowed ? 0 : 1, (int) get_option( 'mainwp_enableNativeBackups' ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $right, 10 );
		}
	}

	/**
	 * The general settings form must not clear the backup gate without its right.
	 *
	 * @dataProvider rights
	 * @param bool $allowed Whether the settings right is held.
	 */
	public function test_general_settings_post_checks_right_before_update_gate_writes( bool $allowed ): void {
		update_option( 'mainwp_backup_before_upgrade', 1 );
		update_option( 'mainwp_backup_before_upgrade_days', 7 );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );
		$_POST = array(
			'submit'                               => '1',
			'wp_nonce'                             => wp_create_nonce( 'Settings' ),
			'mainwp_backup_before_upgrade_section' => '1',
			'mainwp_backup_before_upgrade_days'    => '12',
		);
		$right = static function ( $can, $type, $key ) use ( $allowed ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $key ? $allowed : $can;
		};
		add_filter( 'mainwp_currentusercan', $right, 10, 3 );
		try {
			MainWP_Settings::handle_settings_post();
			$this->assertSame( $allowed ? 0 : 1, (int) get_option( 'mainwp_backup_before_upgrade' ) );
			$this->assertSame( $allowed ? 12 : 7, (int) get_option( 'mainwp_backup_before_upgrade_days' ) );
			$this->assertSame( $allowed ? 0 : 1, (int) get_option( 'mainwp_backup_before_upgrade_require_offsite' ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $right, 10 );
		}
	}

	/**
	 * The stored window must agree with the update gate's fallback and supported range.
	 *
	 * @dataProvider update_gate_days
	 * @param string $posted Submitted window.
	 * @param int    $expected Stored window.
	 */
	public function test_general_settings_post_normalizes_update_gate_days( string $posted, int $expected ): void {
		update_option( 'mainwp_backup_before_upgrade_days', 99 );
		$_POST = array(
			'submit'                               => '1',
			'wp_nonce'                             => wp_create_nonce( 'Settings' ),
			'mainwp_backup_before_upgrade_section' => '1',
			'mainwp_backup_before_upgrade_days'    => $posted,
		);
		MainWP_Settings::handle_settings_post();
		$this->assertSame( $expected, (int) get_option( 'mainwp_backup_before_upgrade_days' ) );
		$this->assertSame( $expected, \MainWP\Dashboard\MainWP_Updates_Overview::get_backup_before_upgrade_days() );
	}

	/**
	 * Invalid windows use the same fallback as the reader.
	 *
	 * @return array Submitted and stored windows.
	 */
	public static function update_gate_days(): array {
		return array(
			'zero'              => array( '0', 7 ),
			'negative'          => array( '-5', 7 ),
			'valid'             => array( '12', 12 ),
			'minimum'           => array( '1', 1 ),
			'one year'          => array( '365', 365 ),
			'above year'        => array( '400', 400 ),
			'fraction'          => array( '1.5', 7 ),
			'non-numeric'       => array( 'invalid', 7 ),
			'mixed'             => array( '12days', 7 ),
			'leading zero'      => array( '030', 30 ),
			'leading zeros one' => array( '001', 1 ),
			'all zeros'         => array( '000', 7 ),
		);
	}

	/**
	 * The legacy success die() must not terminate the test process after a write.
	 *
	 * @param string $name Option suffix.
	 * @return string Refusal response, or an empty body after a persisted write.
	 */
	private function ajax_save_setting( string $name ): string {
		$nonce    = wp_create_nonce( 'mainwp_save_settings' );
		$_POST    = array(
			'action'   => 'mainwp_save_settings',
			'security' => $nonce,
			'name'     => $name,
			'value'    => '0',
		);
		$_REQUEST = $_POST;

		$throw = static function () {
			return static function ( $message ) {
				throw new \WPAjaxDieStopException( (string) $message );
			};
		};

		$written = static function ( $option ) use ( $name ) {
			if ( 'mainwp_' . sanitize_text_field( $name ) === $option ) {
				throw new \WPAjaxDieContinueException();
			}
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		add_action( 'updated_option', $written );
		add_action( 'added_option', $written );
		$response = '';
		ob_start();
		try {
			MainWP_Post_Handler::instance()->ajax_mainwp_save_settings();
		} catch ( \WPAjaxDieContinueException $exception ) {
			$response = $exception->getMessage();
		} catch ( \WPAjaxDieStopException $exception ) {
			$response = $exception->getMessage();
		} finally {
			$response .= ob_get_clean();
			remove_action( 'updated_option', $written );
			remove_action( 'added_option', $written );
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return $response;
	}

	/** Partial saves must preserve omitted policy and encryption values. */
	public function test_array_saver_only_writes_allowed_settings(): void {
		update_option( 'mainwp_enableNativeBackups', 0 );
		update_option( 'mainwp_primaryBackup', 'third-party' );
		update_option( 'mainwp_backup_before_upgrade_days', 99 );
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, 1 );
		$result = MainWP_Backup_Operations::save_backup_settings(
			array(
				'native_backups_enabled'    => true,
				'on_demand_retention_count' => 9,
				'max_concurrent_backups'    => 3,
				'diagnostics_enabled'       => true,
				'update_gate'               => array(
					'enabled'         => true,
					'require_offsite' => true,
				),
			)
		);
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
		$this->assertSame( '', get_option( 'mainwp_primaryBackup' ) );
		$this->assertSame( 9, MainWP_Backup_Settings::get_on_demand_retention_count() );
		$this->assertSame( 3, MainWP_Backup_Settings::get_max_concurrent_backups() );
		$this->assertTrue( MainWP_Backup_Diagnostics::is_enabled() );
		$this->assertSame( 99, (int) get_option( 'mainwp_backup_before_upgrade_days' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_backup_before_upgrade' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_backup_before_upgrade_require_offsite' ) );
		$this->assertSame( 1, (int) get_option( MainWP_Backup_Encryption::ENABLED_OPTION ) );
	}

	/** A refused array cannot make a partial write before finding its bad field. */
	public function test_array_saver_rejects_secrets_empty_and_restricted_writes(): void {
		update_option( 'mainwp_on_demand_retention_count', 5 );
		foreach ( array(
			array(),
			array(
				'on_demand_retention_count' => 9,
				'encryption'                => true,
			),
			array( 'passphrase' => 'must not enter saver' ),
			array( 'update_gate' => array( 'unknown' => true ) ),
		) as $fields ) {
			$result = MainWP_Backup_Operations::save_backup_settings( $fields );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
			$this->assertSame( 5, (int) get_option( 'mainwp_on_demand_retention_count' ) );
		}
		add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		try {
			$this->assertSame( 'mainwp_restricted_account', MainWP_Backup_Operations::save_backup_settings( array( 'diagnostics_enabled' => true ) )->get_error_code() );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		}
	}

	/** Machine write bounds must not narrow the older form's retention field. */
	public function test_machine_bounds_and_form_retention_remain_distinct(): void {
		update_option( 'mainwp_on_demand_retention_count', 5 );
		foreach ( array( array( 'on_demand_retention_count' => 101 ), array( 'max_concurrent_backups' => 11 ), array( 'update_gate' => array( 'max_age_days' => 366 ) ) ) as $fields ) {
			$result = MainWP_Backup_Operations::save_backup_settings( $fields );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
		}
		$_POST = array(
			'submit'                                => '1',
			'wp_nonce'                              => wp_create_nonce( 'Settings' ),
			'mainwp_options_onDemandRetentionCount' => '2000',
		);
		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
		$this->assertSame( 2000, (int) get_option( 'mainwp_on_demand_retention_count' ) );
	}
}
