<?php
/**
 * Settings writes must preserve omitted options and keep recovery material private.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Backup_Diagnostics;
use MainWP\Dashboard\MainWP_Backup_Operations;

require_once __DIR__ . '/../backup/class-mainwp-backup-read-fixtures.php';

/** Global backup policy requires unrestricted Dashboard authority. */
class MainWP_Backups_Settings_Abilities_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Purity_Guard;

	/** Stored values above machine write bounds must remain visible in previews. */
	public function set_up() {
		parent::set_up();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );
		update_option( 'mainwp_on_demand_retention_count', 101 );
		update_option( 'mainwp_backup_max_concurrent', 12 );
		update_option( 'mainwp_primaryBackup', 'backupbuddy' );
		update_option( MainWP_Backup_Encryption::RECOVERY_OPTION, array( 'wrapped_kek' => 'private-recovery-material' ) );
	}

	/**
	 * Discovery is a prerequisite to invoking a settings write.
	 *
	 * @return \WP_Ability
	 */
	private function ability() {
		$this->assertTrue( wp_has_ability( 'mainwp/update-backup-settings-v1' ) );
		return wp_get_ability( 'mainwp/update-backup-settings-v1' );
	}

	/**
	 * Purity includes refused previews.
	 *
	 * @param array $input Caller input.
	 * @param bool  $direct Bypass core permission wrapping.
	 * @return mixed
	 */
	private function execute( array $input, $direct = false ) {
		$ability = $this->ability();
		$call    = static function () use ( $ability, $input, $direct ) {
			return $direct ? MainWP_Abilities_Backups::execute_update_backup_settings( $input ) : $ability->execute( $input );
		};
		return ! empty( $input['dry_run'] ) ? $this->guarded( $call ) : $call();
	}

	/** Settings can stop scheduled work and therefore advertise destructive semantics. */
	public function test_ability_is_registered() {
		$ability = $this->ability();
		$this->assertSame( 'mainwp-backups', $ability->get_category() );
		$this->assertSame( array( 'manage_dashboard_settings' ), MainWP_Abilities_Util::required_rights_for_ability( $ability->get_name() ) );
		$this->assertNotEmpty( $ability->get_label() );
		$this->assertStringContainsString( 'Possible errors:', $ability->get_description() );
		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['idempotent'] );
	}

	/** The confirmation result must match the public settings read. */
	public function test_action_returns_expected_structure() {
		$input  = array(
			'on_demand_retention_count' => 7,
			'max_concurrent_backups'    => 3,
			'diagnostics_enabled'       => true,
			'update_gate'               => array(
				'enabled'         => true,
				'max_age_days'    => 4,
				'require_offsite' => true,
			),
			'confirm'                   => true,
		);
		$output = $this->execute( $input );
		$this->assertIsArray( $output );
		$this->assertTrue( rest_validate_value_from_schema( $output, $this->ability()->get_output_schema() ) );
		$this->assertFalse( $output['dry_run'] );
		unset( $output['dry_run'] );
		$this->assertSame( wp_get_ability( 'mainwp/get-backup-settings-v1' )->execute( array() ), $output );
		$this->assertSame( 7, (int) get_option( 'mainwp_on_demand_retention_count' ) );
		$this->assertSame( 3, (int) get_option( 'mainwp_backup_max_concurrent' ) );
		$this->assertSame( 1, (int) get_option( MainWP_Backup_Diagnostics::OPTION_ENABLED ) );
		$this->assertSame(
			array(
				'enabled'         => true,
				'max_age_days'    => 4,
				'require_offsite' => true,
			),
			$output['update_gate']
		);
	}

	/** Anonymous previews cannot inspect global policy. */
	public function test_action_requires_authentication() {
		wp_set_current_user( 0 );
		$error = $this->execute(
			array(
				'diagnostics_enabled' => true,
				'dry_run'             => true,
			),
			true
		);
		$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
	}

	/** Ordinary WordPress access does not grant Dashboard settings rights. */
	public function test_action_requires_manage_options() {
		$this->set_current_user_as_subscriber();
		$error = $this->execute(
			array(
				'diagnostics_enabled' => true,
				'dry_run'             => true,
			),
			true
		);
		$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	/** Encryption, recovery material, and invalid bounds cannot reach the saver. */
	public function test_action_validates_input() {
		foreach ( array( array( 'encryption' => false ), array( 'passphrase' => 'private' ), array( 'wrapped_kek' => 'private' ), array( 'on_demand_retention_count' => 101 ), array( 'max_concurrent_backups' => 11 ), array( 'update_gate' => array( 'max_age_days' => 366 ) ), array( 'update_gate' => array( 'unknown' => true ) ), array( 'diagnostics_enabled' => 'yes' ) ) as $input ) {
			$this->assertSame( 'ability_invalid_input', $this->execute( $input + array( 'confirm' => true ) )->get_error_code() );
		}
		foreach ( array( array(), array( 'update_gate' => array() ) ) as $input ) {
			$this->assertSame( 'mainwp_invalid_input', $this->execute( $input + array( 'dry_run' => true ) )->get_error_code() );
		}
	}

	/** Turning native off must explain the scheduling effect without writing. */
	public function test_action_dry_run_returns_preview() {
		$output = $this->execute(
			array(
				'native_backups_enabled'    => false,
				'on_demand_retention_count' => 7,
				'max_concurrent_backups'    => 3,
				'dry_run'                   => true,
			)
		);
		$this->assertTrue( rest_validate_value_from_schema( $output, $this->ability()->get_output_schema() ) );
		$this->assertTrue( $output['would_affect']['stops_scheduled_backups'] );
		$this->assertFalse( $output['would_affect']['clears_primary_backup_method'] );
		$this->assertNull( $output['would_affect']['primary_backup_method'] );
		$this->assertContains(
			array(
				'setting' => 'on_demand_retention_count',
				'from'    => 101,
				'to'      => 7,
			),
			$output['would_affect']['changes']
		);
		$this->assertContains(
			array(
				'setting' => 'max_concurrent_backups',
				'from'    => 12,
				'to'      => 3,
			),
			$output['would_affect']['changes']
		);
		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ) );
	}

	/** Changes require explicit consent. */
	public function test_action_requires_confirmation() {
		$this->assertSame( 'mainwp_confirmation_required', $this->execute( array( 'diagnostics_enabled' => true ) )->get_error_code() );
	}

	/** Ambiguous consent cannot alter global behavior. */
	public function test_action_rejects_confirm_with_dry_run() {
		$this->assertSame(
			'mainwp_invalid_input',
			$this->execute(
				array(
					'diagnostics_enabled' => true,
					'confirm'             => true,
					'dry_run'             => true,
				)
			)->get_error_code()
		);
	}

	/** Site-restricted accounts cannot alter Dashboard-wide settings. */
	public function test_restricted_accounts_and_missing_right_are_refused() {
		foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
			add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
			try {
				$this->assertSame(
					'mainwp_restricted_account',
					$this->execute(
						array(
							'diagnostics_enabled' => true,
							$mode                 => true,
						),
						true
					)->get_error_code()
				);
			} finally {
				remove_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
			}
			add_filter( 'mainwp_currentusercan', '__return_false' );
			try {
				$input = array(
					'diagnostics_enabled' => true,
					$mode                 => true,
				);
				foreach ( array( $this->ability()->check_permissions( $input ), $this->execute( $input, true ) ) as $error ) {
					$this->assertWPError( $error );
					$this->assertSame( 'mainwp_insufficient_right', $error->get_error_code() );
					$this->assertSame( 403, $error->get_error_data()['status'] );
					$this->assertSame( 'manage_dashboard_settings', $error->get_error_data()['right'] );
				}
			} finally {
				remove_filter( 'mainwp_currentusercan', '__return_false' );
			}
		}
	}

	/** MainWP rights and manage_options alone do not grant the settings screen's admin authority. */
	public function test_non_admin_with_all_rights_cannot_update_settings() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'manage_options' );
		wp_set_current_user( $user->ID );
		add_filter( 'mainwp_currentusercan', '__return_true' );
		try {
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				$input = array(
					'on_demand_retention_count' => 7,
					$mode                       => true,
				);
				foreach ( array(
					$this->ability()->check_permissions( $input ),
					$this->execute( $input, true ),
					MainWP_Backup_Operations::save_backup_settings( array( 'on_demand_retention_count' => 7 ) ),
					MainWP_Backup_Operations::save_backup_settings( array( 'on_demand_retention_count' => 7 ), 'ui' ),
				) as $error ) {
					$this->assertWPError( $error );
					$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
					$this->assertSame( 403, $error->get_error_data()['status'] );
				}
				$this->assertSame( 101, (int) get_option( 'mainwp_on_demand_retention_count' ) );
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', '__return_true' );
		}
	}

	/** Native toggles must preserve the rollback mirror and explain primary-method clearing. */
	public function test_native_toggle_works_when_disabled_and_mirrors_legacy_option() {
		$this->execute(
			array(
				'native_backups_enabled' => false,
				'confirm'                => true,
			)
		);
		$this->assertSame( 0, (int) get_option( 'mainwp_enableNativeBackups' ) );
		$this->assertSame( 0, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
		$preview = $this->execute(
			array(
				'native_backups_enabled' => true,
				'dry_run'                => true,
			)
		);
		$this->assertTrue( $preview['would_affect']['clears_primary_backup_method'] );
		$this->assertSame( 'backupbuddy', $preview['would_affect']['primary_backup_method'] );
		$output = $this->execute(
			array(
				'native_backups_enabled' => true,
				'confirm'                => true,
			)
		);
		$this->assertTrue( $output['native_backups_enabled'] );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
		$this->assertSame( '', get_option( 'mainwp_primaryBackup' ) );
	}

	/** A partial gate write cannot reset other fields or recoverable key material. */
	public function test_partial_settings_preserve_omitted_values_and_hide_key_material() {
		update_option( 'mainwp_backup_before_upgrade', 1 );
		update_option( 'mainwp_backup_before_upgrade_days', 14 );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 0 );
		$blob   = get_option( MainWP_Backup_Encryption::RECOVERY_OPTION );
		$output = $this->execute(
			array(
				'update_gate' => array( 'require_offsite' => true ),
				'confirm'     => true,
			)
		);
		$this->assertSame(
			array(
				'enabled'         => true,
				'max_age_days'    => 14,
				'require_offsite' => true,
			),
			$output['update_gate']
		);
		$this->assertSame( 101, $output['on_demand_retention_count'] );
		$this->assertSame( $blob, get_option( MainWP_Backup_Encryption::RECOVERY_OPTION ) );
		foreach ( array( $output, wp_get_ability( 'mainwp/get-backup-settings-v1' )->execute( array() ) ) as $result ) {
			$text = wp_json_encode( $result );
			$this->assertStringNotContainsString( 'wrapped_kek', $text );
			$this->assertStringNotContainsString( 'private-recovery-material', $text );
			$this->assertStringNotContainsString( MainWP_Backup_Encryption::RECOVERY_OPTION, $text );
		}
	}
}
