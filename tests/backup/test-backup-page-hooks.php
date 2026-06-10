<?php
/**
 * MainWP Backup Page Hook Tests
 *
 * Regression tests for the extension-facing hooks fired by the Backups
 * page menu registration after the page split.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Page;

/**
 * Class MainWP_Backup_Page_Hooks_Test
 */
class MainWP_Backup_Page_Hooks_Test extends \WP_UnitTestCase {

	/**
	 * Reset the shared sub-pages state and test options.
	 *
	 * @return void
	 */
	public function tear_down() {
		MainWP_Backup_Page::$subPages = null;
		// The hide flag is a private static that would leak the custom-page state into later tests.
		$hide_flag = new \ReflectionProperty( MainWP_Backup_Page::class, 'hideSubmenuBackups' );
		$hide_flag->setAccessible( true );
		$hide_flag->setValue( null, false );
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_primaryBackup' );
		parent::tear_down();
	}

	/**
	 * Prepare an admin context for menu registration.
	 *
	 * @return void
	 */
	private function prepare_admin_menu_context() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The subpages filter still fires from init_menu and its result is
	 * exposed on the page class.
	 *
	 * @return void
	 */
	public function test_init_menu_applies_subpages_filter() {
		$this->prepare_admin_menu_context();
		update_option( 'mainwp_enableNativeBackups', 1 );

		$fired       = 0;
		$add_subpage = static function ( $subPages ) use ( &$fired ) {
			++$fired;
			$subPages[] = array(
				'title'    => 'Hook Test Subpage',
				'slug'     => 'HookTest',
				'callback' => '__return_null',
			);
			return $subPages;
		};
		add_filter( 'mainwp_getsubpages_backups', $add_subpage );

		MainWP_Backup_Page::init_menu();

		remove_filter( 'mainwp_getsubpages_backups', $add_subpage );

		$this->assertSame( 1, $fired, 'The mainwp_getsubpages_backups filter must fire exactly once from init_menu.' );
		$this->assertSame( 'HookTest', MainWP_Backup_Page::$subPages[0]['slug'] );
	}

	/**
	 * A custom page provided through the filter takes over the Backups
	 * menu slot when an external primary backup method is active, and the
	 * native submenu wrapper stays empty.
	 *
	 * @return void
	 */
	public function test_init_menu_honors_custom_page_filter() {
		$this->prepare_admin_menu_context();
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_primaryBackup', 'ext-backup-method' );

		$custom_page = static function () {
			return array(
				'title'    => 'Custom Backups',
				'slug'     => 'Custom',
				'callback' => '__return_null',
			);
		};
		add_filter( 'mainwp_getcustompage_backups', $custom_page );

		MainWP_Backup_Page::init_menu();

		remove_filter( 'mainwp_getcustompage_backups', $custom_page );

		ob_start();
		MainWP_Backup_Page::init_subpages_menu();
		$submenu_output = ob_get_clean();

		$this->assertSame( '', $submenu_output, 'With a custom page and no subpages, the native submenu wrapper must not render.' );
	}

	/**
	 * The page header and footer actions stay registered against the new
	 * page class.
	 *
	 * @return void
	 */
	public function test_header_and_footer_hooks_bind_to_page_class() {
		MainWP_Backup_Page::init();

		$this->assertNotFalse( has_action( 'mainwp-pageheader-backups', array( MainWP_Backup_Page::class, 'render_header' ) ) );
		$this->assertNotFalse( has_action( 'mainwp-pagefooter-backups', array( MainWP_Backup_Page::class, 'render_footer' ) ) );
	}
}
