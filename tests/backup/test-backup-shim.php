<?php
/**
 * MainWP Manage Backups Shim Tests
 *
 * Tests for the deprecated MainWP_Manage_Backups shim left in place for
 * extension compatibility after the page split.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_List_View;
use MainWP\Dashboard\MainWP_Backup_Page;
use MainWP\Dashboard\MainWP_Manage_Backups;

/**
 * Class MainWP_Backup_Shim_Test
 */
class MainWP_Backup_Shim_Test extends \WP_UnitTestCase {

	/**
	 * Reset the shared sub-pages state and test options.
	 *
	 * @return void
	 */
	public function tear_down() {
		MainWP_Backup_Page::$subPages    = null;
		MainWP_Manage_Backups::$subPages = null;
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tear_down();
	}

	/**
	 * The shim instance accessor hands out the list view so third-party
	 * calls to display() and the column methods keep working.
	 *
	 * @return void
	 */
	public function test_instance_returns_list_view() {
		$this->setExpectedDeprecated( 'MainWP\Dashboard\MainWP_Manage_Backups::instance' );

		$this->assertInstanceOf( MainWP_Backup_List_View::class, MainWP_Manage_Backups::instance() );
	}

	/**
	 * The shim delegates rendering and produces the same output as the
	 * new class.
	 *
	 * @return void
	 */
	public function test_environment_notices_delegate_with_identical_output() {
		$this->setExpectedDeprecated( 'MainWP\Dashboard\MainWP_Manage_Backups::render_backup_environment_notices' );

		ob_start();
		MainWP_Manage_Backups::render_backup_environment_notices();
		$shim_output = ob_get_clean();

		ob_start();
		MainWP_Backup_Page::render_backup_environment_notices();
		$new_output = ob_get_clean();

		$this->assertSame( $new_output, $shim_output );
	}

	/**
	 * The shim class name accessor returns the new page class so callables
	 * built from it bind to the real implementation.
	 *
	 * @return void
	 */
	public function test_get_class_name_returns_new_page_class() {
		$this->setExpectedDeprecated( 'MainWP\Dashboard\MainWP_Manage_Backups::get_class_name' );

		$this->assertSame( MainWP_Backup_Page::class, MainWP_Manage_Backups::get_class_name() );
	}

	/**
	 * After init_menu runs through the shim, the legacy public $subPages
	 * property mirrors the new class so extension readers see the value.
	 *
	 * @return void
	 */
	public function test_subpages_synced_after_init_menu() {
		$this->setExpectedDeprecated( 'MainWP\Dashboard\MainWP_Manage_Backups::init_menu' );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );

		$add_subpage = static function ( $subPages ) {
			$subPages[] = array(
				'title'    => 'Shim Test Subpage',
				'slug'     => 'ShimTest',
				'callback' => '__return_null',
			);
			return $subPages;
		};
		add_filter( 'mainwp_getsubpages_backups', $add_subpage );

		MainWP_Manage_Backups::init_menu();

		remove_filter( 'mainwp_getsubpages_backups', $add_subpage );

		$this->assertSame( MainWP_Backup_Page::$subPages, MainWP_Manage_Backups::$subPages );
		$this->assertSame( 'ShimTest', MainWP_Manage_Backups::$subPages[0]['slug'] );
	}
}
