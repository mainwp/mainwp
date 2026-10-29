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
use MainWP\Dashboard\MainWP_Backup_Handler;
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
	 * Unreleased helpers need no compatibility entry points on the old classes.
	 *
	 * @return void
	 */
	public function test_unreleased_shims_are_absent() {
		foreach ( array( 'sanitize_exclude_list', 'format_backup_errors', 'negotiate_backup_preflight', 'child_supports_task_spine', 'get_max_concurrent_backups', 'acquire_backup_slot', 'release_backup_slot', 'validate_backup_environment', 'acquire_download_lock', 'release_download_lock', 'cleanup_stale_download_locks', 'validate_backup_file' ) as $method ) {
			$this->assertFalse( method_exists( MainWP_Backup_Handler::class, $method ), $method . ' must not have a compatibility shim.' );
		}
		$this->assertFalse( method_exists( MainWP_Manage_Backups::class, 'render_backup_environment_notices' ) );
	}

	/**
	 * Compatibility warnings must identify the release that deprecated the APIs.
	 *
	 * @return void
	 */
	public function test_retained_shims_report_deprecation_version_63() {
		$page_method    = 'MainWP\Dashboard\MainWP_Manage_Backups::get_class_name';
		$handler_method = 'MainWP\Dashboard\MainWP_Backup_Handler::normalize_filename';
		$this->setExpectedDeprecated( $page_method );
		$this->setExpectedDeprecated( $handler_method );
		$versions = array();
		$capture  = static function ( $method, $replacement, $version ) use ( &$versions ) {
			$versions[ $method ] = $version;
		};
		add_action( 'deprecated_function_run', $capture, 10, 3 );
		try {
			MainWP_Manage_Backups::get_class_name();
			MainWP_Backup_Handler::normalize_filename( 'archive.zip' );
		} finally {
			remove_action( 'deprecated_function_run', $capture, 10 );
		}
		$this->assertSame(
			array(
				$page_method    => '6.3',
				$handler_method => '6.3',
			),
			$versions
		);
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
