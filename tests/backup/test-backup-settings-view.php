<?php
/**
 * MainWP Backup Settings View Tests
 *
 * Tests for the global and per-site backup settings sections.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Diagnostics;
use MainWP\Dashboard\MainWP_Backup_Settings_View;
use MainWP\Dashboard\MainWP_Backup_Settings;
use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_Settings;
use MainWP\Dashboard\MainWP_System_Handler;

require_once __DIR__ . '/class-mainwp-backup-settings-staff-fixtures.php';

/**
 * Class MainWP_Backup_Settings_View_Test
 */
class MainWP_Backup_Settings_View_Test extends MainWP_Abilities_Test_Case {

	use Backup_Settings_Staff_Fixtures;

	/**
	 * Invalid counts stay beside their field without losing other form edits.
	 *
	 * @dataProvider mwp_1965_invalid_count_forms
	 * @param string $server Submitted server count.
	 * @param string $remote Submitted remote count.
	 * @param array  $invalid Fields requiring an error.
	 */
	public function test_mwp_1965_invalid_count_keeps_form( string $server, string $remote, array $invalid ): void {
		$this->set_current_user_as_admin();
		$stored = array(
			'mainwp_backupsOnServer'                  => 3,
			'mainwp_backupOnExternalSources'          => 4,
			'mainwp_enableNativeBackups'              => 0,
			'mainwp_on_demand_retention_count'        => 5,
			'mainwp_archiveFormat'                    => 'tar.gz',
			'mainwp_maximumFileDescriptors'           => 150,
			'mainwp_maximumFileDescriptorsAuto'       => 1,
			'mainwp_notificationOnBackupFail'         => 1,
			'mainwp_notificationOnBackupStart'        => 0,
			'mainwp_chunkedBackupTasks'               => 1,
			'mainwp_options_loadFilesBeforeZip'       => 1,
			MainWP_Backup_Diagnostics::OPTION_ENABLED => 0,
		);
		foreach ( $stored as $option => $value ) {
			update_option( $option, $value );
		}
		$saved_post    = $_POST;
		$saved_get     = $_GET;
		$_GET          = array(
			'page'                                => 'BackupSettings',
			'message'                             => 'saved',
			'backup_encryption_forced_off'        => '1',
			'backup_recovery_passphrase_rejected' => 'short',
		);
		$_POST         = array(
			'submit'                                    => '1',
			'wp_nonce'                                  => wp_create_nonce( 'BackupSettings' ),
			'mainwp_options_backupOnServer'             => $server,
			'mainwp_options_backupOnExternalSources'    => $remote,
			'mainwp_options_enableNativeBackups'        => '1',
			'mainwp_options_onDemandRetentionCount'     => '11',
			'mainwp_archiveFormat'                      => 'zip',
			'mainwp_options_maximumFileDescriptors'     => '37',
			'mainwp_options_notificationOnBackupStart'  => '1',
			'mainwp_options_backupDiagnosticsEnabled'   => '1',
			'mainwp_options_backupEncryptionPassphrase' => 'private recovery input',
		);
		$redirect      = '';
		$stop_redirect = static function ( $location ) use ( &$redirect ) {
			$redirect = $location;
			throw new \RuntimeException( 'Captured settings redirect.' );
		};
		add_filter( 'wp_redirect', $stop_redirect );
		$page = new class() extends MainWP_Settings {
			/**
			 * Omit navigation from the settings feedback test.
			 *
			 * @param string $shownPage Unused page slug.
			 */
			public static function render_header( $shownPage = '' ) {
			}
			/**
			 * Omit navigation from the settings feedback test.
			 *
			 * @param string $shownPage Unused page slug.
			 */
			public static function render_footer( $shownPage = '' ) {
			}
		};
		try {
			try {
				MainWP_System_Handler::instance()->handle_settings_post();
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Captured settings redirect.', $error->getMessage() );
			}
			$this->assertSame( '', $redirect, 'Validation must render the submitted form instead of redirecting away.' );
			ob_start();
			try {
				$page::render_backups();
			} finally {
				$output = ob_get_clean();
			}
			$this->assertStringNotContainsString( 'private recovery input', $output );
			$this->assertStringNotContainsString( 'ui green message', $output );
			$this->assertStringNotContainsString( 'Settings saved', $output );
			$this->assertStringNotContainsString( 'Encryption could not be enabled because no recovery passphrase is set.', $output );
			$this->assertStringNotContainsString( 'The recovery passphrase must be at least 12 characters.', $output );
			$dom = new \DOMDocument();
			@$dom->loadHTML( $output, LIBXML_NONET );
			$xpath = new \DOMXPath( $dom );
			foreach ( array(
				'mainwp_options_backupOnServer'         => $server,
				'mainwp_options_onDemandRetentionCount' => '11',
				'mainwp_options_maximumFileDescriptors' => '37',
			) as $field => $value ) {
				$this->assertSame( $value, $xpath->evaluate( 'string(//input[@name="' . $field . '"]/@value)' ), $field );
			}
			$this->assertSame( 'zip', $xpath->evaluate( 'string(//select[@name="mainwp_archiveFormat"]/option[@selected]/@value)' ) );
			foreach ( array( 'mainwp_options_enableNativeBackups', 'mainwp_options_notificationOnBackupStart', 'mainwp_options_backupDiagnosticsEnabled' ) as $field ) {
				$this->assertSame( 1, $xpath->query( '//input[@name="' . $field . '"][@checked]' )->length, $field );
			}
			foreach ( array( 'mainwp_maximumFileDescriptorsAuto', 'mainwp_options_notificationOnBackupFail', 'mainwp_options_chunkedBackupTasks', 'mainwp_options_loadFilesBeforeZip' ) as $field ) {
				$this->assertSame( 0, $xpath->query( '//input[@name="' . $field . '"][@checked]' )->length, $field );
			}
			// MWP-2031: the retired remote count is no longer an editable field.
			$this->assertSame( 0, $xpath->query( '//input[@name="mainwp_options_backupOnExternalSources"]' )->length );
			foreach ( array( 'mainwp_options_backupOnServer' ) as $field ) {
				$input = $xpath->query( '//input[@name="' . $field . '"]' )->item( 0 );
				$this->assertSame( in_array( $field, $invalid, true ) ? 'true' : '', $input->getAttribute( 'aria-invalid' ), $field );
				if ( in_array( $field, $invalid, true ) ) {
					$error_id = $input->getAttribute( 'aria-describedby' );
					$this->assertNotEmpty( $error_id );
					$this->assertSame( 'Enter a whole number of 0 or more.', $xpath->evaluate( 'string(//*[@id="' . $error_id . '"])' ) );
				}
			}
			foreach ( $stored as $option => $value ) {
				$this->assertEquals( $value, get_option( $option ), $option . ' must remain unchanged.' );
			}
		} finally {
			remove_filter( 'wp_redirect', $stop_redirect );
			$_POST = $saved_post;
			$_GET  = $saved_get;
			MainWP_Backup_Settings::handle_settings_post();
		}
	}

	/**
	 * Supply invalid values for both global count fields.
	 *
	 * @return array Invalid counts for each field and a simultaneous refusal.
	 */
	public static function mwp_1965_invalid_count_forms(): array {
		$server = 'mainwp_options_backupOnServer';
		$remote = 'mainwp_options_backupOnExternalSources';
		return array(
			'server text'     => array( 'abc', '12', array( $server ) ),
			'server negative' => array( '-1', '12', array( $server ) ),
			'remote text'     => array( '12', 'abc', array( $remote ) ),
			'remote negative' => array( '12', '-1', array( $remote ) ),
			'both invalid'    => array( 'abc', '-1', array( $server, $remote ) ),
			'escaped text'    => array( '"><script>alert(1)</script>', '12', array( $server ) ),
		);
	}

	/**
	 * Staff must not see editable fields whose save is refused.
	 *
	 * @dataProvider settings_writers
	 * @param string $actor Settings actor.
	 * @param bool   $allowed Whether backup fields are editable.
	 */
	public function test_render_settings_staff_boundary( string $actor, bool $allowed ): void {
		$this->settings_actor( $actor );
		$this->allow_unrestricted_settings_access();
		update_option( 'mainwp_enableNativeBackups', 1 );
		ob_start();
		try {
			MainWP_Backup_Settings_View::render_settings();
		} finally {
			$output = ob_get_clean();
		}
		$this->assertStringContainsString( '<h3 class="ui dividing header">', $output );
		$this->assertStringContainsString( 'Backup Settings', $output );
		$this->assertStringContainsString( '<div class="sub header">Built-In Backups back up your Child Sites directly from this dashboard.', $output );
		// Fomantic hides state messages inside .ui.form unless they carry visible.
		$message = '<div class="ui blue message">Only WordPress administrators can change backup settings.</div>';
		if ( $allowed ) {
			$this->assertStringNotContainsString( 'Only WordPress administrators', $output );
			$this->assertStringContainsString( 'mainwp_options_enableNativeBackups', $output );
			$this->assertStringContainsString( 'mainwp_options_backupEncryptionPassphrase', $output );
		} else {
			$this->assertSame( 1, substr_count( $output, $message ) );
			$this->assertStringNotContainsString( 'mainwp_options_enableNativeBackups', $output );
			$this->assertStringNotContainsString( 'mainwp_options_backupEncryptionPassphrase', $output );
			foreach ( array( '<input', '<select', '<textarea' ) as $field ) {
				$this->assertStringNotContainsString( $field, $output );
			}
		}
	}

	/**
	 * A short first passphrase saves other settings and reports both refusals.
	 */
	public function test_settings_save_redirect_args_for_short_first_passphrase() {
		wp_set_current_user(
			$this->factory->user->create(
				array(
					'role'      => 'administrator',
					'user_pass' => 'settings account password',
				)
			)
		);
		$enc = MainWP_Backup_Encryption::instance();
		$this->assertEmpty( $enc->get_recovery_blob() );
		$_POST['submit']                                    = '1';
		$_POST['wp_nonce']                                  = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_enableBackupEncryption']     = '1';
		$_POST['mainwp_options_backupEncryptionPassphrase'] = '12345678901';
		$_POST['mainwp_options_backupEncryptionAccountPassword'] = 'settings account password';
		$_POST['mainwp_options_backupOnServer']                  = '3';
		try {
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertSame( 3, (int) get_option( 'mainwp_backupsOnServer' ) );
			$this->assertEmpty( $enc->get_recovery_blob() );
			$this->assertFalse( $enc->is_enabled_setting() );
			$this->assertTrue( is_callable( array( MainWP_Backup_Settings::class, 'get_save_redirect_args' ) ) );
			$this->assertSame(
				array(
					'backup_encryption_forced_off'        => 1,
					'backup_recovery_passphrase_rejected' => 'short',
				),
				MainWP_Backup_Settings::get_save_redirect_args()
			);
		} finally {
			unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_enableBackupEncryption'], $_POST['mainwp_options_backupEncryptionPassphrase'], $_POST['mainwp_options_backupEncryptionAccountPassword'], $_POST['mainwp_options_backupOnServer'] );
			delete_option( 'mainwp_backupsOnServer' );
		}
	}

	/**
	 * A rejected replacement keeps encryption enabled and reports only the refusal.
	 */
	public function test_settings_save_redirect_args_for_rejected_replacement() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$enc               = MainWP_Backup_Encryption::instance();
		$original          = $enc->set_recovery_passphrase( 'original recovery passphrase' );
		$_POST['submit']   = '1';
		$_POST['wp_nonce'] = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_enableBackupEncryption']            = '1';
		$_POST['mainwp_options_backupEncryptionPassphrase']        = 'replacement recovery passphrase';
		$_POST['mainwp_options_backupEncryptionCurrentPassphrase'] = 'wrong current recovery passphrase';
		try {
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertSame( $original, $enc->get_recovery_blob() );
			$this->assertTrue( $enc->is_enabled_setting() );
			$this->assertTrue( is_callable( array( MainWP_Backup_Settings::class, 'get_save_redirect_args' ) ) );
			$this->assertSame( array( 'backup_recovery_passphrase_rejected' => 'current_mismatch' ), MainWP_Backup_Settings::get_save_redirect_args() );
		} finally {
			unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_enableBackupEncryption'], $_POST['mainwp_options_backupEncryptionPassphrase'], $_POST['mainwp_options_backupEncryptionCurrentPassphrase'] );
		}
	}

	/**
	 * A clean save clears redirect warnings from the previous handler call.
	 */
	public function test_settings_save_redirect_args_for_clean_save() {
		wp_set_current_user(
			$this->factory->user->create(
				array(
					'role'      => 'administrator',
					'user_pass' => 'settings account password',
				)
			)
		);
		$_POST['submit']                                    = '1';
		$_POST['wp_nonce']                                  = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_enableBackupEncryption']     = '1';
		$_POST['mainwp_options_backupEncryptionPassphrase'] = '12345678901';
		$_POST['mainwp_options_backupEncryptionAccountPassword'] = 'settings account password';
		try {
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertTrue( MainWP_Backup_Settings::encryption_was_forced_off() );
			$this->assertSame( 'short', MainWP_Backup_Settings::recovery_passphrase_rejection() );
			$_POST['mainwp_options_backupEncryptionPassphrase'] = 'a strong settings passphrase';
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertTrue( MainWP_Backup_Encryption::instance()->is_armed() );
			$this->assertTrue( is_callable( array( MainWP_Backup_Settings::class, 'get_save_redirect_args' ) ) );
			$this->assertSame( array(), MainWP_Backup_Settings::get_save_redirect_args() );
		} finally {
			unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_enableBackupEncryption'], $_POST['mainwp_options_backupEncryptionPassphrase'], $_POST['mainwp_options_backupEncryptionAccountPassword'] );
		}
	}

	/**
	 * Partial saves show one warning paragraph; clean saves print nothing.
	 *
	 * @dataProvider save_notice_flags
	 * @param array  $query   Redirect query arguments.
	 * @param string $message Expected warning text, or empty for a clean save.
	 */
	public function test_render_save_notice( array $query, string $message ) {
		$this->assertTrue( is_callable( array( MainWP_Backup_Settings_View::class, 'render_save_notice' ) ) );
		$saved_get = $_GET;
		$_GET      = $query;
		ob_start();
		try {
			$rendered = MainWP_Backup_Settings_View::render_save_notice();
		} finally {
			$output = ob_get_clean();
			$_GET   = $saved_get;
		}
		$this->assertSame( '' !== $message, $rendered );
		$this->assertSame( '' === $message ? '' : '<div class="ui yellow message">' . $message . '<i class="close icon"></i></div>', $output );
	}

	/**
	 * The Backup Settings page replaces its success banner on a partial save.
	 *
	 * @dataProvider save_notice_flags
	 * @param array  $query   Redirect query arguments.
	 * @param string $message Expected warning text, or empty for a clean save.
	 */
	public function test_backup_settings_save_notice_suppresses_green_banner( array $query, string $message ) {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$page      = new class() extends MainWP_Settings {
			/**
			 * Omit navigation from this feedback test.
			 *
			 * @param string $shownPage Unused page slug.
			 */
			public static function render_header( $shownPage = '' ) {
			}
			/**
			 * Omit navigation from this feedback test.
			 *
			 * @param string $shownPage Unused page slug.
			 */
			public static function render_footer( $shownPage = '' ) {
			}
		};
		$saved_get = $_GET;
		$_GET      = array_merge( $query, array( 'message' => 'saved' ) );
		ob_start();
		try {
			$page::render_backups();
		} finally {
			$output = ob_get_clean();
			$_GET   = $saved_get;
		}
		$this->assertStringContainsString( 'id="mainwp-backup-settings"', $output );
		if ( '' !== $message ) {
			$this->assertStringContainsString( '<div class="ui yellow message">' . $message . '<i class="close icon"></i></div>', $output );
			$this->assertStringNotContainsString( 'ui green message', $output );
		} else {
			$this->assertStringContainsString( '<div class="ui green message">Backup settings have been saved successfully.<i class="close icon"></i></div>', $output );
			$this->assertStringNotContainsString( 'ui yellow message', $output );
		}
	}

	/**
	 * Redirect flags determine the warning and its recovery instructions.
	 *
	 * @return array Query arguments and exact warning text.
	 */
	public static function save_notice_flags(): array {
		$cases = array(
			'forced off and short' => array(
				array(
					'backup_encryption_forced_off'        => '1',
					'backup_recovery_passphrase_rejected' => 'short',
				),
				'Settings saved, but backup encryption is still off. The recovery passphrase must be at least 12 characters. Fix this under Backup Settings below, then save again.',
			),
			'forced off alone'     => array(
				array( 'backup_encryption_forced_off' => '1' ),
				'Settings saved, but backup encryption is still off. No recovery passphrase is set. Fix this under Backup Settings below, then save again.',
			),
			'unknown rejection'    => array(
				array( 'backup_recovery_passphrase_rejected' => 'unknown' ),
				'Settings saved, but the backup recovery passphrase was rejected. The recovery passphrase must be at least 12 characters. Check it under Backup Settings below.',
			),
			'clean save'           => array( array(), '' ),
		);
		foreach ( self::recovery_proof_banners() as $name => $rejection ) {
			$cases[ $name ] = array(
				array( 'backup_recovery_passphrase_rejected' => $rejection[0] ),
				'Settings saved, but the backup recovery passphrase was rejected. ' . $rejection[1] . ' Check it under Backup Settings below.',
			);
		}
		return $cases;
	}

	/**
	 * A refused passphrase must not change how the other settings save.
	 */
	public function test_settings_passphrase_minimum_preserves_blob_and_other_saves() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$enc               = MainWP_Backup_Encryption::instance();
		$original          = $enc->set_recovery_passphrase( 'original recovery passphrase' );
		$_POST['submit']   = '1';
		$_POST['wp_nonce'] = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_backupEncryptionPassphrase'] = ' 12345678901 ';
		$_POST['mainwp_options_backupOnServer']             = '3';
		$_POST['mainwp_options_enableBackupEncryption']     = '1';
		try {
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertSame( 'short', MainWP_Backup_Settings::recovery_passphrase_rejection() );
			$this->assertSame( $original, $enc->get_recovery_blob() );
			$this->assertSame( 3, (int) get_option( 'mainwp_backupsOnServer' ) );
			$this->assertTrue( $enc->is_enabled_setting() );
			$_POST['mainwp_options_backupEncryptionPassphrase']        = '123456789012';
			$_POST['mainwp_options_backupEncryptionCurrentPassphrase'] = 'original recovery passphrase';
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertSame( '', MainWP_Backup_Settings::recovery_passphrase_rejection() );
			$this->assertNotSame( $original, $enc->get_recovery_blob() );
			$this->assertSame( $enc->load_kek(), $enc->recover_kek( '123456789012', $enc->get_recovery_blob() ) );
		} finally {
			unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_backupEncryptionPassphrase'], $_POST['mainwp_options_backupEncryptionCurrentPassphrase'], $_POST['mainwp_options_backupOnServer'], $_POST['mainwp_options_enableBackupEncryption'] );
			delete_option( 'mainwp_backupsOnServer' );
		}
	}

	/**
	 * The redirect flag must explain why the passphrase was not saved.
	 */
	public function test_settings_renders_short_passphrase_error() {
		$_GET['backup_recovery_passphrase_rejected'] = 'short';
		ob_start();
		try {
			MainWP_Backup_Settings_View::render_settings();
		} finally {
			$output = ob_get_clean();
			unset( $_GET['backup_recovery_passphrase_rejected'] );
		}
		$this->assertStringContainsString( 'The recovery passphrase must be at least 12 characters.', $output );
	}

	/**
	 * With the key file lost, a refusal means the passphrase did not open the
	 * stored recovery key, so the banner must ask for the original passphrase.
	 */
	public function test_settings_renders_lost_key_passphrase_error() {
		$enc = MainWP_Backup_Encryption::instance();
		$enc->set_recovery_passphrase( 'original recovery passphrase' );
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		$_GET['backup_recovery_passphrase_rejected'] = 'mismatch';
		ob_start();
		try {
			MainWP_Backup_Settings_View::render_settings();
		} finally {
			$output = ob_get_clean();
			unset( $_GET['backup_recovery_passphrase_rejected'] );
		}
		$this->assertStringContainsString( 'enter the original recovery passphrase', $output );
		$this->assertStringNotContainsString( 'The recovery passphrase must be at least 12 characters.', $output );
	}

	/**
	 * With the key file lost, a too-short passphrase is refused for its length
	 * before any recovery attempt, and the banner must say so.
	 */
	public function test_lost_key_short_passphrase_reports_length_not_mismatch() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$enc = MainWP_Backup_Encryption::instance();
		$enc->set_recovery_passphrase( 'original recovery passphrase' );
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		$_POST['submit']   = '1';
		$_POST['wp_nonce'] = wp_create_nonce( 'BackupSettings' );
		try {
			$_POST['mainwp_options_backupEncryptionPassphrase'] = 'short';
			MainWP_Backup_Settings::handle_settings_post();
			$this->assertSame( 'short', MainWP_Backup_Settings::recovery_passphrase_rejection() );
			$_POST['mainwp_options_backupEncryptionPassphrase']        = 'a wrong but long passphrase';
			$_POST['mainwp_options_backupEncryptionCurrentPassphrase'] = 'a wrong but long passphrase';
			MainWP_Backup_Settings::handle_settings_post();
			$this->assertSame( 'mismatch', MainWP_Backup_Settings::recovery_passphrase_rejection() );
		} finally {
			unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_backupEncryptionPassphrase'], $_POST['mainwp_options_backupEncryptionCurrentPassphrase'] );
		}

		$_GET['backup_recovery_passphrase_rejected'] = 'short';
		ob_start();
		try {
			MainWP_Backup_Settings_View::render_settings();
		} finally {
			$output = ob_get_clean();
			unset( $_GET['backup_recovery_passphrase_rejected'] );
		}
		$this->assertStringContainsString( 'The recovery passphrase must be at least 12 characters.', $output );
		$this->assertStringNotContainsString( 'enter the original recovery passphrase', $output );
	}

	/**
	 * Reset the on-demand retention option between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->clear_settings_staff_fixtures();
		delete_option( 'mainwp_on_demand_retention_count' );
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( MainWP_Backup_Diagnostics::OPTION_ENABLED );

		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );

		parent::tear_down();
	}

	/**
	 * The global settings section renders the encryption toggle and the
	 * recovery passphrase field.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_encryption_fields() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_options_enableBackupEncryption', $output );
		$this->assertStringContainsString( 'mainwp_options_backupEncryptionPassphrase', $output );
	}

	/**
	 * Saving with the encryption toggle on and a recovery passphrase arms
	 * encryption: the toggle, the key, and the recovery blob all land.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_arms_encryption_with_passphrase() {
		$admin = $this->factory->user->create(
			array(
				'role'      => 'administrator',
				'user_pass' => 'settings account password',
			)
		);
		wp_set_current_user( $admin );

		$_POST['submit']                                    = '1';
		$_POST['wp_nonce']                                  = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_enableBackupEncryption']     = '1';
		$_POST['mainwp_options_backupEncryptionPassphrase'] = 'a strong settings passphrase';
		$_POST['mainwp_options_backupEncryptionAccountPassword'] = 'settings account password';

		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );

		$enc = MainWP_Backup_Encryption::instance();
		$this->assertTrue( $enc->is_enabled_setting() );
		$this->assertNotEmpty( $enc->get_recovery_blob() );
		$this->assertNotNull( $enc->load_kek() );
		$this->assertTrue( $enc->is_armed() );
		$this->assertFalse( MainWP_Backup_Settings::encryption_was_forced_off(), 'Encryption armed legitimately; the forced-off signal must not be set.' );

		unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_enableBackupEncryption'], $_POST['mainwp_options_backupEncryptionPassphrase'], $_POST['mainwp_options_backupEncryptionAccountPassword'] );
	}

	/**
	 * The form asks for the proof appropriate to the stored recovery state.
	 *
	 * @dataProvider recovery_input_states
	 * @param bool $armed   Whether a recovery blob exists.
	 * @param bool $partial Whether a stored array lacks the wrapped key.
	 */
	public function test_settings_renders_recovery_proof_inputs( bool $armed, bool $partial = false ) {
		$enc = MainWP_Backup_Encryption::instance();
		if ( $armed ) {
			$enc->set_recovery_passphrase( 'original recovery passphrase' );
		} elseif ( $partial ) {
			// The save handler treats a blob without a wrapped key as unset.
			update_option( MainWP_Backup_Encryption::RECOVERY_OPTION, array( 'algo' => 'pbkdf2-sha256' ), false );
		} else {
			delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );
		}
		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();
		$shown  = $armed ? 'mainwp_options_backupEncryptionCurrentPassphrase' : 'mainwp_options_backupEncryptionAccountPassword';
		$hidden = $armed ? 'mainwp_options_backupEncryptionAccountPassword' : 'mainwp_options_backupEncryptionCurrentPassphrase';
		$this->assertStringContainsString( 'autocomplete="current-password" name="' . $shown . '"', $output );
		$this->assertStringNotContainsString( 'name="' . $hidden . '"', $output );
		$this->assertStringContainsString( 'placeholder="' . ( $armed ? 'Current recovery passphrase' : 'Your WordPress password' ) . '"', $output );
		$this->assertStringContainsString( 'placeholder="' . ( $armed ? 'New recovery passphrase' : 'Set a recovery passphrase' ) . '"', $output );
		$this->assertLessThan( strpos( $output, 'name="mainwp_options_backupEncryptionPassphrase"' ), strpos( $output, 'name="' . $shown . '"' ) );
		$this->assertStringContainsString( 'To change it, enter the current passphrase and a new one. Leave both blank to keep the current one. Setting the first passphrase asks for your WordPress password.', $output );
	}

	/**
	 * Each recovery state asks for a different secret.
	 *
	 * @return array Stored recovery states.
	 */
	public static function recovery_input_states(): array {
		return array(
			'first'          => array( false ),
			'replacement'    => array( true ),
			'no wrapped key' => array( false, true ),
		);
	}

	/**
	 * A redirect must preserve the specific reason proof was refused.
	 *
	 * @dataProvider recovery_proof_banners
	 * @param string $flag Rejection flag.
	 * @param string $message Required banner copy.
	 */
	public function test_settings_renders_recovery_proof_banner( string $flag, string $message ) {
		$_GET['backup_recovery_passphrase_rejected'] = $flag;
		ob_start();
		try {
			MainWP_Backup_Settings_View::render_settings();
		} finally {
			$output = ob_get_clean();
			unset( $_GET['backup_recovery_passphrase_rejected'] );
		}
		$this->assertStringContainsString( $message, $output );
		$this->assertStringContainsString( 'class="ui small red message"', $output );
	}

	/**
	 * Keep refusal copy tied to the failure the operator can correct.
	 *
	 * @return array Flags and exact copy.
	 */
	public static function recovery_proof_banners(): array {
		return array(
			'short'            => array( 'short', 'The recovery passphrase must be at least 12 characters.' ),
			'mismatch'         => array( 'mismatch', 'The backup encryption key file is missing and the passphrase does not match the stored recovery key. To restore the key, enter the original recovery passphrase.' ),
			'current'          => array( 'current', 'Enter the current recovery passphrase to change it. The passphrase was not changed.' ),
			'current mismatch' => array( 'current_mismatch', 'The current recovery passphrase is not correct. The passphrase was not changed.' ),
			'key mismatch'     => array( 'key_mismatch', 'The stored recovery key does not match the backup encryption key file. The passphrase was not changed.' ),
			'account password' => array( 'account_password', 'Your WordPress password is not correct. The recovery passphrase was not set.' ),
		);
	}

	/**
	 * The toggle cannot arm encryption without a recovery passphrase: a
	 * backup must never be sealed without a way to recover it.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_refuses_to_arm_without_passphrase() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['submit']                                = '1';
		$_POST['wp_nonce']                              = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_enableBackupEncryption'] = '1';

		MainWP_Backup_Settings::handle_settings_post();

		$this->assertFalse( MainWP_Backup_Encryption::instance()->is_enabled_setting(), 'Encryption must not arm without a recovery passphrase.' );

		unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_enableBackupEncryption'] );
	}

	/**
	 * The toggle was submitted on but there is no recovery passphrase, so
	 * the save silently forces encryption off. The save flow must record
	 * that fact so the settings view can surface a warning next to the
	 * green "saved" banner instead of leaving the user with no signal that
	 * encryption is actually off.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_reports_encryption_forced_off_signal() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['submit']                                = '1';
		$_POST['wp_nonce']                              = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_enableBackupEncryption'] = '1';
		// No recovery passphrase submitted and none set yet, so this is the force-off path.

		MainWP_Backup_Settings::handle_settings_post();

		$this->assertTrue( MainWP_Backup_Settings::encryption_was_forced_off(), 'The forced-off signal must be set when encryption cannot be armed.' );

		unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_enableBackupEncryption'] );
	}

	/**
	 * Turning encryption off leaves the key material in place so existing
	 * encrypted snapshots can still be restored.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_disable_keeps_key_material() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$enc = MainWP_Backup_Encryption::instance();
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '1' );
		$enc->set_recovery_passphrase( 'keep this passphrase set please' );
		$kek = $enc->load_kek();

		$_POST['submit']   = '1';
		$_POST['wp_nonce'] = wp_create_nonce( 'BackupSettings' );
		// The encryption toggle is absent, which means off - a legitimate user choice, not a forced one.

		MainWP_Backup_Settings::handle_settings_post();

		$this->assertFalse( $enc->is_enabled_setting(), 'The toggle is off.' );
		$this->assertSame( $kek, $enc->load_kek(), 'Disabling must not delete the KEK.' );
		$this->assertNotEmpty( $enc->get_recovery_blob(), 'Disabling must not delete the recovery blob.' );
		$this->assertFalse( MainWP_Backup_Settings::encryption_was_forced_off(), 'The user chose to leave encryption off; nothing was forced.' );

		unset( $_POST['submit'], $_POST['wp_nonce'] );
	}

	/**
	 * The global settings section renders the header and the legacy
	 * feature toggle.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_header_and_toggle() {
		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Backup Settings', $output );
		$this->assertStringContainsString( 'mainwp_options_enableNativeBackups', $output );
	}

	/**
	 * The global settings section renders the archive format selector.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_archive_format_select() {
		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_archiveFormat', $output );
	}

	/**
	 * The global settings section renders the on-demand retention field.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_on_demand_retention_field() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_options_onDemandRetentionCount', $output );
		$this->assertStringContainsString( 'Counts the backups made with Back Up Now on the Snapshots &amp; Restore page, per site.', $output );
	}

	/**
	 * Saving the settings form persists the on-demand retention count.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_saves_on_demand_retention() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['submit']                                = '1';
		$_POST['wp_nonce']                              = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_onDemandRetentionCount'] = '3';

		$saved = MainWP_Backup_Settings::handle_settings_post();

		$this->assertTrue( $saved );
		$this->assertSame( 3, MainWP_Backup_Settings::get_on_demand_retention_count() );

		unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_onDemandRetentionCount'] );
	}

	/**
	 * MWP-2031: the settings page describes what each count really does.
	 *
	 * The remote count has no Built-In Backups consumer, so the form no longer
	 * offers it, and a form save leaves its stored value for compatibility.
	 */
	public function test_mwp_2031_global_counts_describe_real_behavior(): void {
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_backupsOnServer', 3 );
		update_option( 'mainwp_backupOnExternalSources', 4 );
		ob_start();
		try {
			MainWP_Backup_Settings_View::render_settings();
		} finally {
			$output = ob_get_clean();
		}
		$this->assertStringNotContainsString( 'mainwp_options_backupOnExternalSources', $output );
		$this->assertStringNotContainsString( 'Backups on remote storage', $output );
		$this->assertStringNotContainsString( 'Backups on server', $output );
		$this->assertStringContainsString( 'Leftover backup files to keep', $output );
		$this->assertStringContainsString( 'It does not limit how many Built-In Backups are kept', $output );
		$this->assertStringContainsString( 'name="mainwp_options_backupOnServer" value="3"', $output );

		$_POST = array(
			'submit'                        => '1',
			'wp_nonce'                      => wp_create_nonce( 'BackupSettings' ),
			'mainwp_options_backupOnServer' => '2',
		);
		try {
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertSame( 2, (int) get_option( 'mainwp_backupsOnServer' ) );
			$this->assertSame( 4, (int) get_option( 'mainwp_backupOnExternalSources' ) );
		} finally {
			$_POST = array();
		}
	}

	/** Global archive counts accept zero without changing on-demand retention. */
	public function test_global_retention_zero_roundtrip(): void {
		$this->set_current_user_as_admin();
		$_POST = array(
			'submit'   => '1',
			'wp_nonce' => wp_create_nonce( 'BackupSettings' ),
		);
		try {
			foreach ( array( 3, 0, 7 ) as $count ) {
				$_POST['mainwp_options_backupOnServer']          = (string) $count;
				$_POST['mainwp_options_backupOnExternalSources'] = (string) $count;
				$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
				$this->assertSame( $count, (int) get_option( 'mainwp_backupsOnServer' ) );
				$this->assertSame( $count, (int) get_option( 'mainwp_backupOnExternalSources' ) );
			}
		} finally {
			$_POST = array();
		}
	}

	/** Rejected counts must not partially change other backup settings. */
	public function test_invalid_global_count_preflight_preserves_all_settings(): void {
		$this->set_current_user_as_admin();
		foreach ( array( array( '0', 'invalid' ), array( 'invalid', '0' ) ) as $counts ) {
			update_option( 'mainwp_backupsOnServer', 3 );
			update_option( 'mainwp_backupOnExternalSources', 4 );
			update_option( 'mainwp_enableNativeBackups', 0 );
			update_option( 'mainwp_on_demand_retention_count', 5 );
			update_option( \MainWP\Dashboard\MainWP_Backup_Diagnostics::OPTION_ENABLED, 0 );
			$_POST = array(
				'submit'                                  => '1',
				'wp_nonce'                                => wp_create_nonce( 'BackupSettings' ),
				'mainwp_options_backupOnServer'           => $counts[0],
				'mainwp_options_backupOnExternalSources'  => $counts[1],
				'mainwp_options_enableNativeBackups'      => '1',
				'mainwp_options_onDemandRetentionCount'   => '7',
				'mainwp_options_backupDiagnosticsEnabled' => '1',
			);
			try {
				$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
				$this->assertSame( 3, (int) get_option( 'mainwp_backupsOnServer' ) );
				$this->assertSame( 4, (int) get_option( 'mainwp_backupOnExternalSources' ) );
				$this->assertFalse( MainWP_Backup_Settings::is_native_backups_enabled() );
				$this->assertSame( 5, MainWP_Backup_Settings::get_on_demand_retention_count() );
				$this->assertSame( 0, (int) get_option( \MainWP\Dashboard\MainWP_Backup_Diagnostics::OPTION_ENABLED ) );
			} finally {
				$_POST = array(); }
		}
	}

	/** Invalid global counts reject the save and retain the stored limit. */
	public function test_global_retention_invalid_counts_and_failed_write(): void {
		$this->set_current_user_as_admin();
		update_option( 'mainwp_backupsOnServer', 3 );
		$_POST = array(
			'submit'   => '1',
			'wp_nonce' => wp_create_nonce( 'BackupSettings' ),
		);
		try {
			foreach ( array( '-1', '1.5', 'invalid', array( 1 ) ) as $value ) {
				$_POST['mainwp_options_backupOnServer'] = $value;
				$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
				$this->assertSame( 3, (int) get_option( 'mainwp_backupsOnServer' ) );
			}
			$block                                  = static fn( $candidate, $old ) => $old;
			add_filter( 'pre_update_option_mainwp_backupsOnServer', $block, 10, 2 );
			$_POST['mainwp_options_backupOnServer'] = '0';
			try {
				$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
				$this->assertSame( 3, (int) get_option( 'mainwp_backupsOnServer' ) );
			} finally {
				remove_filter( 'pre_update_option_mainwp_backupsOnServer', $block );
			}
		} finally {
			$_POST = array();
		}
	}


	/** Count persistence failure leaves the complete mixed form unchanged.
	 *
	 * @dataProvider failed_count_writes
	 * @param string $failed Failed option.
	 * @param bool   $absent Whether counts were absent.
	 * @param int    $old Old server count.
	 * @param int    $next Submitted server count.
	 */
	public function test_checked_count_sql_failure( string $failed, bool $absent, int $old, int $next ): void {
		global $wpdb;
		$this->prepare_checked_count_form( $next );
		foreach ( array(
			'mainwp_backupsOnServer'         => $old,
			'mainwp_backupOnExternalSources' => 4,
		) as $option => $value ) {
			delete_option( $option );
			if ( ! $absent ) {
				add_option( $option, $value, '', 'yes' );
			}
		}
		$before = $this->checked_count_rows();
		$fail   = static function ( $sql ) use ( $wpdb, $failed ) {
			if ( preg_match( '/^(UPDATE|INSERT) /i', $sql ) && str_contains( $sql, $wpdb->options ) && str_contains( $sql, "'" . $failed . "'" ) ) {
				return 'INVALID SQL FOR CHECKED COUNT TEST';
			}
			return $sql;
		};
		$errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
			$_POST = array();
		}
		$this->assertSame( $before, $this->checked_count_rows(), 'Failed save must restore raw rows, identity and autoload.' );
		foreach ( array( 'mainwp_backupsOnServer', 'mainwp_backupOnExternalSources' ) as $option ) {
			$this->assertSame( isset( $before[ $option ] ) ? $before[ $option ]['option_value'] : false, false === get_option( $option ) ? false : (string) get_option( $option ), 'Option caches must agree after recovery.' );
		}
	}

	/**
	 * Count SQL failure cases.
	 *
	 * @return array Cases.
	 */
	public static function failed_count_writes(): array {
		$cases = array();
		foreach ( array( 'mainwp_backupsOnServer', 'mainwp_backupOnExternalSources' ) as $failed ) {
			foreach ( array( false, true ) as $absent ) {
				foreach ( array( array( 0, 1 ), array( 3, 0 ) ) as $transition ) {
					$cases[ $failed . '-' . (int) $absent . '-' . $transition[0] ] = array( $failed, $absent, $transition[0], $transition[1] );
				}
			}
		}
		return $cases;
	}

	/** Later edits to a successfully written count survive recovery.
	 *
	 * @dataProvider concurrent_count_changes
	 * @param string $change Later edit kind.
	 */
	public function test_checked_count_preserves_later_edit( string $change ): void {
		global $wpdb;
		$this->prepare_checked_count_form( 1 );
		update_option( 'mainwp_backupsOnServer', 0 );
		update_option( 'mainwp_backupOnExternalSources', 4 );
		$later = null;
		$edit  = function () use ( $wpdb, $change, &$later ) {
			if ( 'value' === $change ) {
				update_option( 'mainwp_backupOnExternalSources', 9 );
			} elseif ( 'autoload' === $change ) {
				$wpdb->update( $wpdb->options, array( 'autoload' => 'yes' ), array( 'option_name' => 'mainwp_backupOnExternalSources' ) );
			} else {
				delete_option( 'mainwp_backupOnExternalSources' );
				add_option( 'mainwp_backupOnExternalSources', 2, '', 'no' );
			}
			$later = $this->checked_count_rows()['mainwp_backupOnExternalSources'];
		};
		$fail  = static fn( $sql ) => str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, "'mainwp_backupsOnServer'" ) ? 'INVALID SQL FOR CHECKED COUNT TEST' : $sql;
		add_action( 'update_option_mainwp_backupOnExternalSources', $edit );
		add_filter( 'query', $fail );
		$errors = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_action( 'update_option_mainwp_backupOnExternalSources', $edit );
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
			$_POST = array();
		}
		$this->assertNotNull( $later );
		$this->assertSame( $later, $this->checked_count_rows()['mainwp_backupOnExternalSources'] );
		$this->assertSame( $later['option_value'], (string) get_option( 'mainwp_backupOnExternalSources' ) );
		$this->assertSame( '0', get_option( 'mainwp_backupsOnServer' ) );
	}

	/**
	 * Later writer cases.
	 *
	 * @return array Cases.
	 */
	public static function concurrent_count_changes(): array {
		return array( array( 'value' ), array( 'autoload' ), array( 'recreated' ) );
	}

	/** Operations can refuse after successful count writes. */
	public function test_checked_count_operations_refusal(): void {
		$this->prepare_checked_count_form( 1 );
		update_option( 'mainwp_backupsOnServer', 0 );
		delete_option( 'mainwp_backupOnExternalSources' );
		$before = $this->checked_count_rows();
		$deny   = static function () {
			add_filter( 'mainwp_currentusercan', '__return_false', PHP_INT_MAX );
		};
		add_action( 'update_option_mainwp_backupsOnServer', $deny );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_action( 'update_option_mainwp_backupsOnServer', $deny );
			remove_filter( 'mainwp_currentusercan', '__return_false', PHP_INT_MAX );
			$_POST = array();
		}
		$this->assertSame( $before, $this->checked_count_rows() );
		$this->assertFalse( get_option( 'mainwp_backupOnExternalSources' ) );
		$this->assertSame( '0', get_option( 'mainwp_backupsOnServer' ) );
	}


	/** A failed snapshot read refuses the complete form without writes. */
	public function test_checked_count_snapshot_read_failure(): void {
		global $wpdb;
		$this->prepare_checked_count_form( 1 );
		$before = $this->checked_count_rows();
		$fail   = static fn( $sql ) => str_starts_with( $sql, 'SELECT option_id, option_value, autoload' ) && str_contains( $sql, "'mainwp_backupsOnServer'" ) ? 'INVALID SQL FOR CHECKED COUNT TEST' : $sql;
		add_filter( 'query', $fail );
		$errors = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
			$_POST = array();
		}
		$this->assertSame( $before, $this->checked_count_rows() );
	}

	/** Recovery preserves an existing malformed raw value and its autoload. */
	public function test_checked_count_raw_value_recovery(): void {
		global $wpdb;
		$this->prepare_checked_count_form( 1 );
		update_option( 'mainwp_backupsOnServer', 0 );
		delete_option( 'mainwp_backupOnExternalSources' );
		add_option( 'mainwp_backupOnExternalSources', array( 'legacy' => 'invalid' ), '', 'yes' );
		$before = $this->checked_count_rows();
		$fail   = static fn( $sql ) => str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, "'mainwp_backupsOnServer'" ) ? 'INVALID SQL FOR CHECKED COUNT TEST' : $sql;
		add_filter( 'query', $fail );
		$errors = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
			$_POST = array();
		}
		$this->assertSame( $before, $this->checked_count_rows() );
		$this->assertSame( array( 'legacy' => 'invalid' ), get_option( 'mainwp_backupOnExternalSources' ) );
	}


	/** A transient journal read failure still permits exact recovery. */
	public function test_checked_count_transient_capture_read_failure(): void {
		global $wpdb;
		$this->prepare_checked_count_form( 1 );
		update_option( 'mainwp_backupsOnServer', 0 );
		update_option( 'mainwp_backupOnExternalSources', 4 );
		$before = $this->checked_count_rows();
		$reads  = 0;
		$fail   = static function ( $sql ) use ( &$reads ) {
			if ( str_starts_with( $sql, 'SELECT option_id, option_value, autoload' ) && str_contains( $sql, "'mainwp_backupOnExternalSources'" ) && 2 === ++$reads ) {
				return 'INVALID SQL FOR CHECKED COUNT TEST';
			}
			return $sql;
		};
		$deny   = static function () {
			add_filter( 'mainwp_currentusercan', '__return_false', PHP_INT_MAX );
		};
		add_action( 'update_option_mainwp_backupsOnServer', $deny );
		add_filter( 'query', $fail );
		$errors = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_action( 'update_option_mainwp_backupsOnServer', $deny );
			remove_filter( 'mainwp_currentusercan', '__return_false', PHP_INT_MAX );
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
			$_POST = array();
		}
		$this->assertSame( $before, $this->checked_count_rows() );
	}

	/** Failed compensation remains a failed save and leaves other settings untouched. */
	public function test_checked_count_recovery_write_failure(): void {
		global $wpdb;
		$this->prepare_checked_count_form( 1 );
		update_option( 'mainwp_backupsOnServer', 0 );
		update_option( 'mainwp_backupOnExternalSources', 4 );
		$before        = $this->checked_count_rows();
		$remote_writes = 0;
		$fail          = static function ( $sql ) use ( &$remote_writes ) {
			if ( str_starts_with( $sql, 'UPDATE ' ) && ( str_contains( $sql, "'mainwp_backupsOnServer'" ) || ( str_contains( $sql, "'mainwp_backupOnExternalSources'" ) && 2 === ++$remote_writes ) ) ) {
				return 'INVALID SQL FOR CHECKED COUNT TEST';
			}
			return $sql;
		};
		add_filter( 'query', $fail );
		$errors = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
			$_POST = array();
		}
		$after = $this->checked_count_rows();
		$this->assertSame( '2', $after['mainwp_backupOnExternalSources']['option_value'] );
		$this->assertSame( '2', get_option( 'mainwp_backupOnExternalSources' ) );
		unset( $before['mainwp_backupOnExternalSources'], $after['mainwp_backupOnExternalSources'] );
		$this->assertSame( $before, $after );
	}


	/** Exhausted journal reads compensate the current write before other settings.
	 *
	 * @dataProvider exhausted_count_reads
	 * @param string $option Count whose capture reads fail.
	 * @param string $state Original row state.
	 * @param bool   $later Whether a later hook changes the count.
	 */
	public function test_checked_count_exhausted_capture_reads( string $option, string $state, bool $later ): void {
		global $wpdb;
		$this->prepare_checked_count_form( 1 );
		update_option( 'mainwp_backupsOnServer', 0 );
		update_option( 'mainwp_backupOnExternalSources', 0 );
		delete_option( $option );
		if ( 'absent' !== $state ) {
			add_option( $option, 0, '', 'yes' );
			$wpdb->update( $wpdb->options, array( 'autoload' => in_array( $state, array( 'yes', 'auto' ), true ) ? $state : 'yes' ), array( 'option_name' => $option ) );
			if ( 'false' === $state ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => 'b:0;' ), array( 'option_name' => $option ) );
				wp_cache_delete( $option, 'options' );
			}
			wp_cache_delete( 'alloptions', 'options' );
		}
		$before      = $this->checked_count_rows();
		$reads       = 0;
		$fail        = static function ( $sql ) use ( $option, &$reads ) {
			if ( str_starts_with( $sql, 'SELECT option_id, option_value, autoload' ) && str_contains( $sql, "'" . $option . "'" ) ) {
				++$reads;
				if ( $reads >= 2 && $reads <= 4 ) {
					return 'INVALID SQL FOR CHECKED COUNT TEST';
				}
			}
			return $sql;
		};
		$autoload    = static fn( $value, $name ) => $option === $name ? true : $value;
		$later_value = in_array( $state, array( 'same', 'recreated' ), true ) ? ( 'mainwp_backupsOnServer' === $option ? 1 : 2 ) : 9;
		$edit        = static function () use ( $option, $state, $later_value, $wpdb ) {
			if ( 'recreated' === $state ) {
				delete_option( $option );
				add_option( $option, $later_value, '', 'no' );
			} elseif ( 'autoload' === $state ) {
				$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $option ) );
			} else {
				update_option( $option, $later_value );
			}
		};
		$forward     = static fn() => 7;
		if ( 'filtered' === $state ) {
			add_filter( 'pre_update_option_' . $option, $forward );
		}
		add_filter( 'wp_default_autoload_value', $autoload, 20, 2 );
		add_filter( 'query', $fail );
		if ( $later ) {
			add_action( 'update_option_' . $option, $edit );
		}
		$errors = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_filter( 'query', $fail );
			remove_filter( 'wp_default_autoload_value', $autoload, 20 );
			remove_filter( 'pre_update_option_' . $option, $forward );
			remove_action( 'update_option_' . $option, $edit );
			$wpdb->suppress_errors( $errors );
			$_POST = array();
		}
		$after = $this->checked_count_rows();
		if ( $later ) {
			if ( 'autoload' === $state ) {
				$this->assertSame( 'no', $after[ $option ]['autoload'] );
				$this->assertSame( '0', $after[ $option ]['option_value'] );
			} else {
				$this->assertSame( (string) $later_value, $after[ $option ]['option_value'] );
				$this->assertSame( $later_value, (int) get_option( $option ) );
			}
			if ( 'recreated' === $state ) {
				$this->assertNotSame( $before[ $option ]['option_id'], $after[ $option ]['option_id'] );
			}
			unset( $before[ $option ], $after[ $option ] );
		} else {
			$this->assertSame( isset( $before[ $option ] ) && 'false' !== $state ? 0 : false, false === get_option( $option ) ? false : (int) get_option( $option ) );
		}
		$this->assertSame( $before, $after );
	}

	/**
	 * Exhausted capture read cases.
	 *
	 * @return array Cases.
	 */
	public static function exhausted_count_reads(): array {
		$cases = array();
		foreach ( array( 'mainwp_backupsOnServer', 'mainwp_backupOnExternalSources' ) as $option ) {
			foreach ( array( 'yes', 'auto', 'absent', 'filtered', 'false' ) as $state ) {
				$cases[ $option . '-' . $state ] = array( $option, $state, false );
			}
			foreach ( array( 'yes', 'same', 'recreated', 'autoload' ) as $state ) {
				$cases[ $option . '-later-' . $state ] = array( $option, $state, true );
			}
		}
		return $cases;
	}


	/** A row changed before capture must never enter this save's journal.
	 *
	 * @dataProvider interleaved_count_rows
	 * @param string $option Changed count option.
	 * @param string $change Concurrent row change.
	 */
	public function test_checked_count_capture_preserves_interleaved_row( string $option, string $change ): void {
		global $wpdb;
		$this->prepare_checked_count_form( 1 );
		foreach ( array( 'mainwp_backupsOnServer', 'mainwp_backupOnExternalSources' ) as $name ) {
			delete_option( $name );
			add_option( $name, 0, '', 'yes' );
		}
		$before     = $this->checked_count_rows();
		$later      = null;
		$reads      = 0;
		$interleave = function ( $sql ) use ( $wpdb, $option, $change, &$reads, &$later ) {
			if ( str_starts_with( $sql, 'SELECT option_id, option_value, autoload' ) && str_contains( $sql, "'" . $option . "'" ) && 2 === ++$reads ) {
				if ( 'identity' === $change ) {
					$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
					$wpdb->insert(
						$wpdb->options,
						array(
							'option_name'  => $option,
							'option_value' => 'mainwp_backupsOnServer' === $option ? '1' : '2',
							'autoload'     => 'yes',
						)
					);
				} else {
					$wpdb->update( $wpdb->options, array( 'value' === $change ? 'option_value' : 'autoload' => 'value' === $change ? '9' : 'no' ), array( 'option_name' => $option ) );
				}
				$later = $this->checked_count_rows()[ $option ];
				add_filter( 'mainwp_currentusercan', '__return_false', PHP_INT_MAX );
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_filter( 'query', $interleave );
			remove_filter( 'mainwp_currentusercan', '__return_false', PHP_INT_MAX );
			$_POST = array();
		}
		$after = $this->checked_count_rows();
		$this->assertNotNull( $later );
		$this->assertSame( $later, $after[ $option ] );
		$this->assertSame( $later['option_value'], (string) get_option( $option ) );
		unset( $before[ $option ], $after[ $option ] );
		$this->assertSame( $before, $after );
	}

	/**
	 * Row changes between a successful write and its first capture read.
	 *
	 * @return array Cases.
	 */
	public static function interleaved_count_rows(): array {
		$cases = array();
		foreach ( array( 'mainwp_backupsOnServer', 'mainwp_backupOnExternalSources' ) as $option ) {
			foreach ( array( 'identity', 'value', 'autoload' ) as $change ) {
				$cases[ $option . '-' . $change ] = array( $option, $change );
			}
		}
		return $cases;
	}


	/** A single failed INSERT refuses the form without an implicit retry.
	 *
	 * @dataProvider one_shot_count_inserts
	 * @param string $option Absent count option.
	 * @param int    $next Submitted count.
	 */
	public function test_checked_count_one_shot_insert_failure( string $option, int $next ): void {
		global $wpdb;
		$this->prepare_checked_count_form( $next );
		$_POST['mainwp_options_backupOnExternalSources'] = (string) $next;
		foreach ( array( 'mainwp_backupsOnServer', 'mainwp_backupOnExternalSources' ) as $name ) {
			delete_option( $name );
			add_option( $name, 0 === $next ? 3 : 0, '', 'yes' );
		}
		delete_option( $option );
		$before   = $this->checked_count_rows();
		$attempts = 0;
		$fail     = static function ( $sql ) use ( $wpdb, $option, &$attempts ) {
			if ( str_starts_with( $sql, 'INSERT ' ) && str_contains( $sql, $wpdb->options ) && str_contains( $sql, "'" . $option . "'" ) ) {
				++$attempts;
				if ( 1 === $attempts ) {
					return 'INVALID SQL FOR ONE SHOT COUNT TEST';
				}
			}
			return $sql;
		};
		add_filter( 'query', $fail );
		$errors = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( MainWP_Backup_Settings::handle_settings_post() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
			$_POST = array();
		}
		$this->assertSame( $before, $this->checked_count_rows(), 'One failed INSERT must preserve all raw rows and absence.' );
		$this->assertSame( 1, $attempts, 'Count persistence must not silently retry a failed INSERT.' );
		$this->assertFalse( get_option( $option ) );
		$other = 'mainwp_backupsOnServer' === $option ? 'mainwp_backupOnExternalSources' : 'mainwp_backupsOnServer';
		$this->assertSame( $before[ $other ]['option_value'], (string) get_option( $other ) );
	}

	/**
	 * One-shot failures for each count, with zero and positive submissions.
	 *
	 * @return array Cases.
	 */
	public static function one_shot_count_inserts(): array {
		$cases = array();
		foreach ( array( 'mainwp_backupsOnServer', 'mainwp_backupOnExternalSources' ) as $option ) {
			foreach ( array( 0, 3 ) as $next ) {
				$cases[ $option . '-' . $next ] = array( $option, $next );
			}
		}
		return $cases;
	}


	/** Existing false-valued rows can be normalized, then saved unchanged.
	 *
	 * @dataProvider existing_false_count_upserts
	 * @param string    $option Existing false-valued option.
	 * @param int       $next Submitted count.
	 * @param bool|null $decision Default autoload decision.
	 */
	public function test_checked_count_existing_false_upsert_and_unchanged( string $option, int $next, ?bool $decision ): void {
		global $wpdb;
		$this->prepare_checked_count_form( $next );
		$_POST['mainwp_options_backupOnExternalSources'] = (string) $next;
		delete_option( $option );
		add_option( $option, 0, '', 'yes' );
		$wpdb->update( $wpdb->options, array( 'option_value' => 'b:0;' ), array( 'option_name' => $option ) );
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$before   = $this->checked_count_rows()[ $option ];
		$autoload = static fn( $value, $name ) => $option === $name ? $decision : $value;
		add_filter( 'wp_default_autoload_value', $autoload, 20, 2 );
		$observed = array();
		$observe  = static function ( $unused, $value ) use ( $option, &$observed ) {
			$observed[] = array( (string) $value, (string) get_option( $option ) );
		};
		add_action( 'add_option_' . $option, $observe, 10, 2 );
		try {
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertSame( array( array( (string) $next, (string) $next ) ), $observed, 'Later option hooks must read the saved count.' );
			$row = $this->checked_count_rows()[ $option ];
			$this->assertSame( $before['option_id'], $row['option_id'] );
			$this->assertSame( (string) $next, $row['option_value'] );
			$this->assertSame( $next, (int) get_option( $option ) );
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertSame( $row, $this->checked_count_rows()[ $option ] );
		} finally {
			remove_filter( 'wp_default_autoload_value', $autoload, 20 );
			remove_action( 'add_option_' . $option, $observe );
			$_POST = array();
		}
	}

	/**
	 * Existing false rows with each modern default-autoload decision.
	 *
	 * @return array Cases.
	 */
	public static function existing_false_count_upserts(): array {
		$cases = array();
		foreach ( self::one_shot_count_inserts() as $name => $case ) {
			foreach ( array(
				'default'  => null,
				'enabled'  => true,
				'disabled' => false,
			) as $label => $decision ) {
				$cases[ $name . '-' . $label ] = array( $case[0], $case[1], $decision );
			}
		}
		return $cases;
	}

	/** Unchanged and omitted counts preserve the existing UI domain. */
	public function test_checked_count_unchanged_and_omitted(): void {
		$this->prepare_checked_count_form( 1 );
		update_option( 'mainwp_backupsOnServer', 1 );
		update_option( 'mainwp_backupOnExternalSources', 2 );
		$before = $this->checked_count_rows();
		$_POST['mainwp_options_onDemandRetentionCount'] = '101';
		try {
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			foreach ( array( 'mainwp_backupsOnServer', 'mainwp_backupOnExternalSources' ) as $option ) {
				$this->assertSame( $before[ $option ], $this->checked_count_rows()[ $option ] );
			}
			unset( $_POST['mainwp_options_backupOnServer'], $_POST['mainwp_options_backupOnExternalSources'] );
			$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
			$this->assertSame( 101, MainWP_Backup_Settings::get_on_demand_retention_count() );
		} finally {
			$_POST = array();
		}
	}

	/**
	 * Prepare a mixed settings submission.
	 *
	 * @param int $server Submitted server count.
	 */
	private function prepare_checked_count_form( int $server ): void {
		$this->set_current_user_as_admin();
		foreach ( array(
			'mainwp_enableNativeBackups'              => 0,
			'mainwp_enableLegacyBackupFeature'        => 0,
			'mainwp_primaryBackup'                    => 'existing-provider',
			'mainwp_on_demand_retention_count'        => 5,
			MainWP_Backup_Diagnostics::OPTION_ENABLED => 0,
			MainWP_Backup_Encryption::ENABLED_OPTION  => 1,
		) as $option => $value ) {
			update_option( $option, $value );
		}
		$_POST = array(
			'submit'                                  => '1',
			'wp_nonce'                                => wp_create_nonce( 'BackupSettings' ),
			'mainwp_options_backupOnServer'           => (string) $server,
			'mainwp_options_backupOnExternalSources'  => '2',
			'mainwp_options_enableNativeBackups'      => '1',
			'mainwp_options_onDemandRetentionCount'   => '7',
			'mainwp_options_backupDiagnosticsEnabled' => '1',
			'mainwp_primaryBackup'                    => 'new-provider',
		);
	}

	/**
	 * Read persisted settings independently of option caches.
	 *
	 * @return array Raw rows.
	 */
	private function checked_count_rows(): array {
		global $wpdb;
		$rows = array();
		foreach ( array_unique( MainWP_Backup_Settings::SETTINGS_OPTIONS ) as $option ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_id, option_value, autoload FROM $wpdb->options WHERE option_name = %s", $option ), ARRAY_A );
			if ( null !== $row ) {
				$rows[ $option ] = $row;
			}
		}
		return $rows;
	}

	/**
	 * The global settings section renders the diagnostics toggle.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_diagnostics_toggle() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_options_backupDiagnosticsEnabled', $output );
	}

	/**
	 * The export button appears only while diagnostics is enabled: with
	 * nothing being recorded there is nothing to export.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_export_button_only_when_enabled() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$without = ob_get_clean();
		$this->assertStringNotContainsString( 'id="mainwp-backup-diagnostics-export"', $without );

		update_option( MainWP_Backup_Diagnostics::OPTION_ENABLED, '1' );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$with = ob_get_clean();
		$this->assertStringContainsString( 'mainwp-backup-diagnostics-export', $with );
		$this->assertStringContainsString( 'mainwp_backup_diagnostics_export', $with, 'The button must post the export AJAX action.' );
	}

	/**
	 * Saving the settings form persists the diagnostics toggle both ways.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_saves_diagnostics_toggle() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['submit']                                  = '1';
		$_POST['wp_nonce']                                = wp_create_nonce( 'BackupSettings' );
		$_POST['mainwp_options_backupDiagnosticsEnabled'] = '1';

		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
		$this->assertTrue( MainWP_Backup_Diagnostics::is_enabled() );

		unset( $_POST['mainwp_options_backupDiagnosticsEnabled'] );
		$_POST['wp_nonce'] = wp_create_nonce( 'BackupSettings' );

		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
		$this->assertFalse( MainWP_Backup_Diagnostics::is_enabled(), 'An absent checkbox must turn the recorder off.' );

		unset( $_POST['submit'], $_POST['wp_nonce'] );
	}

	/**
	 * The per-site section renders the accordion and the primary backup
	 * selector.
	 *
	 * @return void
	 */
	public function test_render_individual_settings_outputs_accordion() {
		$website = (object) array(
			'id'                    => 1,
			'primary_backup_method' => '',
		);

		ob_start();
		MainWP_Backup_Settings_View::render_individual_settings( $website );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-edit-site-backup-settings-accordion', $output );
		$this->assertStringContainsString( 'mainwp_primaryBackup', $output );
	}
}
