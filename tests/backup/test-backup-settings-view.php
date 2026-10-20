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

require_once __DIR__ . '/class-mainwp-backup-settings-staff-fixtures.php';

/**
 * Class MainWP_Backup_Settings_View_Test
 */
class MainWP_Backup_Settings_View_Test extends MainWP_Abilities_Test_Case {

	use Backup_Settings_Staff_Fixtures;

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
		$this->assertStringContainsString( '<div class="sub header">Native backups back up your Child Sites directly from this dashboard.', $output );
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
