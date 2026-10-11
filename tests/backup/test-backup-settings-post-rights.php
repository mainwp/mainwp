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
			if ( 'mainwp_' . $name === $option ) {
				throw new \WPAjaxDieContinueException();
			}
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		add_action( 'updated_option', $written );
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
