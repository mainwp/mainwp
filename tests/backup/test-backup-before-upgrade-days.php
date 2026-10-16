<?php
/**
 * Backup window readers must agree with the update gate.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Server_Information_Handler;
use MainWP\Dashboard\MainWP_Settings;
use MainWP\Dashboard\MainWP_System_View;
use MainWP\Dashboard\MainWP_Updates_Overview;

/** Stored option types must not change the effective freshness window. */
class MainWP_Backup_Before_Upgrade_Days_Test extends \WP_UnitTestCase {

	/** Render fixtures must not affect later settings tests. */
	public function tearDown(): void {
		delete_option( 'mainwp_backup_before_upgrade_days' );
		delete_option( 'mainwp_backup_before_upgrade' );
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_primaryBackup' );
		parent::tearDown();
	}

	/**
	 * Existing getter behavior is the contract for every reader.
	 *
	 * @dataProvider stored_windows
	 * @param mixed $stored Stored value, or null for a missing option.
	 * @param int   $expected Effective window.
	 */
	public function test_getter_uses_effective_backup_window( $stored, int $expected ): void {
		delete_option( 'mainwp_backup_before_upgrade_days' );
		if ( null !== $stored ) {
			update_option( 'mainwp_backup_before_upgrade_days', $stored );
			$this->assertSame( $stored, get_option( 'mainwp_backup_before_upgrade_days' ) );
		}
		$this->assertSame( $expected, MainWP_Updates_Overview::get_backup_before_upgrade_days() );
	}

	/**
	 * The fallback also applies to old or manually edited option values.
	 *
	 * @return array Stored values and effective windows.
	 */
	public static function stored_windows(): array {
		return array(
			'integer'          => array( 3, 3 ),
			'string'           => array( '3', 3 ),
			'integer zero'     => array( 0, 7 ),
			'string zero'      => array( '0', 7 ),
			'integer negative' => array( -5, 7 ),
			'string negative'  => array( '-5', 7 ),
			'empty'            => array( '', 7 ),
			'non-numeric'      => array( 'abc', 7 ),
			'leading zero'     => array( '030', 30 ),
			'missing'          => array( null, 7 ),
		);
	}

	/**
	 * Confirmation copy must describe the window the gate actually uses.
	 *
	 * @dataProvider reader_windows
	 * @param mixed $stored Stored value.
	 * @param int   $expected Effective window.
	 */
	public function test_translations_use_effective_backup_window( $stored, int $expected ): void {
		update_option( 'mainwp_backup_before_upgrade_days', $stored );
		$translations = MainWP_System_View::get_mainwp_translations();
		$this->assertSame(
			'A full backup has not been taken in the last ' . $expected . ' days for the following sites:',
			$translations['A_full_backup_has_not_been_taken_in_the_last_days_for_the_following_sites_']
		);
	}

	/**
	 * The form must show the effective window even before the next save.
	 *
	 * @dataProvider reader_windows
	 * @param mixed $stored Stored value.
	 * @param int   $expected Effective window.
	 */
	public function test_settings_render_uses_effective_backup_window( $stored, int $expected ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_primaryBackup', '' );
		update_option( 'mainwp_backup_before_upgrade', 1 );
		update_option( 'mainwp_backup_before_upgrade_days', $stored );
		$previous_screen = get_current_screen();
		set_current_screen( 'toplevel_page_Settings' );
		ob_start();
		try {
			MainWP_Settings::render();
		} finally {
			$output                    = ob_get_clean();
			$GLOBALS['current_screen'] = $previous_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Other tests may depend on the previous screen.
		}
		$this->assertMatchesRegularExpression(
			'/<input\b[^>]*name="mainwp_backup_before_upgrade_days"[^>]*value="' . $expected . '"[^>]*>/',
			$output
		);
	}

	/**
	 * Support reports must not show an invalid stored window as effective.
	 *
	 * @dataProvider reader_windows
	 * @param mixed $stored Stored value.
	 * @param int   $expected Effective window.
	 */
	public function test_server_report_uses_effective_backup_window( $stored, int $expected ): void {
		update_option( 'mainwp_backup_before_upgrade_days', $stored );
		$rows = MainWP_Server_Information_Handler::get_general_settings_report_rows();
		$rows = array_column( $rows, 'value', 'label' );
		$this->assertSame( $expected . ' days', $rows['Full backup tolerance before update'] );
	}

	/**
	 * Integer writes and invalid stored values exposed inconsistent readers.
	 *
	 * @return array Stored values and effective windows.
	 */
	public static function reader_windows(): array {
		return array(
			'integer'  => array( 3, 3 ),
			'string'   => array( '3', 3 ),
			'zero'     => array( 0, 7 ),
			'negative' => array( -5, 7 ),
		);
	}
}
