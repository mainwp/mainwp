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
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Page_Test
 */
class MainWP_Backup_Page_Test extends \WP_UnitTestCase {

	/**
	 * Read-only private root created by a test.
	 *
	 * @var string
	 */
	private $private_dir = '';

	/**
	 * Private root filter added by a test.
	 *
	 * @var \Closure|null
	 */
	private $root_filter = null;

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
		if ( '' !== $this->private_dir && is_dir( $this->private_dir ) ) {
			chmod( $this->private_dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			rmdir( $this->private_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
		if ( null !== $this->root_filter ) {
			remove_filter( 'mainwp_backup_private_root', $this->root_filter, 99 );
		}
		MainWP_Backup_Private_Root::reset();
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
	 * Environment notices do not probe a read-only private backup directory.
	 *
	 * @return void
	 */
	public function test_environment_notices_do_not_probe_non_writable_dir() {
		$this->private_dir = trailingslashit( get_temp_dir() ) . 'mainwp-page-ro-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->private_dir );
		chmod( $this->private_dir, 0500 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$private_dir       = $this->private_dir;
		$this->root_filter = static function () use ( $private_dir ) {
			return $private_dir;
		};
		add_filter( 'mainwp_backup_private_root', $this->root_filter, 99 );
		MainWP_Backup_Private_Root::reset();

		ob_start();
		MainWP_Backup_Page::render_backup_environment_notices();
		$output = ob_get_clean();

		// The page may warn from a stat, never from a write probe: the directory stays untouched.
		$this->assertStringContainsString( 'directory is not writable', $output );
		$this->assertSame( 0500, fileperms( $this->private_dir ) & 0777 );
		$this->assertSame( array( '.', '..' ), scandir( $this->private_dir ) );
	}
}
