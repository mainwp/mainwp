<?php
/**
 * MainWP Backup Page Tests
 *
 * Tests for the Backups page chrome: hook registration, footer markup,
 * and the environment notices.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Page;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Page_Test
 */
class MainWP_Backup_Page_Test extends \WP_UnitTestCase {

	/**
	 * Restore permissions on the backup dir.
	 *
	 * @return void
	 */
	public function tear_down() {
		$backup_dir = MainWP_System_Utility::get_mainwp_dir( 'backup' );
		if ( is_array( $backup_dir ) ) {
			$backup_dir = $backup_dir[0];
		}
		if ( is_dir( $backup_dir ) ) {
			chmod( $backup_dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
		parent::tear_down();
	}

	/**
	 * Init registers the page header and footer actions.
	 *
	 * @return void
	 */
	public function test_init_registers_header_and_footer_actions() {
		MainWP_Backup_Page::init();

		$this->assertNotFalse(
			has_action( 'mainwp-pageheader-backups', array( MainWP_Backup_Page::class, 'render_header' ) ),
			'The page header action must point at MainWP_Backup_Page::render_header.'
		);
		$this->assertNotFalse(
			has_action( 'mainwp-pagefooter-backups', array( MainWP_Backup_Page::class, 'render_footer' ) ),
			'The page footer action must point at MainWP_Backup_Page::render_footer.'
		);
	}

	/**
	 * The footer closes the wrapper div opened by the header.
	 *
	 * @return void
	 */
	public function test_render_footer_closes_wrapper() {
		ob_start();
		MainWP_Backup_Page::render_footer();
		$output = ob_get_clean();

		$this->assertSame( '</div>', $output );
	}

	/**
	 * The singleton accessor returns the same page instance.
	 *
	 * @return void
	 */
	public function test_instance_returns_singleton() {
		$first  = MainWP_Backup_Page::instance();
		$second = MainWP_Backup_Page::instance();

		$this->assertInstanceOf( MainWP_Backup_Page::class, $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * The environment notices render nothing for a healthy environment.
	 *
	 * @return void
	 */
	public function test_environment_notices_silent_when_healthy() {
		ob_start();
		MainWP_Backup_Page::render_backup_environment_notices();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'not writable', $output );
	}

	/**
	 * The environment notices warn about a non-writable backup directory.
	 *
	 * @return void
	 */
	public function test_environment_notices_warn_for_non_writable_dir() {
		if ( 0 === (int) getmyuid() ) {
			$this->markTestSkipped( 'Permission checks do not apply when running as root.' );
		}

		$backup_dir = MainWP_System_Utility::get_mainwp_dir( 'backup' );
		if ( is_array( $backup_dir ) ) {
			$backup_dir = $backup_dir[0];
		}
		chmod( $backup_dir, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		ob_start();
		MainWP_Backup_Page::render_backup_environment_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'not writable', $output );
		$this->assertStringContainsString( 'ui yellow message', $output );
	}
}
