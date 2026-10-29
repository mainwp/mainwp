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
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
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
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses directory write permissions.' );
		}
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

	/**
	 * Render Manage Backups as an administrator.
	 *
	 * @param array $get Query args for the request.
	 *
	 * @return string Page output.
	 */
	private function render_manager( array $get = array() ) {
		$saved = $_GET;
		$_GET  = array_merge( $_GET, $get ); // phpcs:ignore WordPress.Security.NonceVerification -- test request.
		ob_start();
		try {
			MainWP_Backup_Page::render_manager();
		} finally {
			$output = ob_get_clean();
			$_GET   = $saved;
		}
		return $output;
	}

	/**
	 * With native backups off, Manage Backups explains how to turn them on
	 * instead of answering with the WordPress permission error.
	 */
	public function test_manage_backups_with_native_off_points_to_the_setting() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 0 );

		$output = $this->render_manager();

		$this->assertStringContainsString( 'id="mainwp-native-backups-off-message"', $output );
		$this->assertStringContainsString( 'Built-In Backups are turned off.', $output );
		$this->assertStringContainsString( 'page=BackupSettings#mainwp_options_enableNativeBackups', $output );
		$this->assertStringNotContainsString( 'mainwp-backups-table', $output );
	}

	/**
	 * Team Control staff cannot change backup settings, so the off notice
	 * sends them to an administrator instead of the settings form.
	 */
	public function test_manage_backups_with_native_off_sends_staff_to_an_administrator() {
		add_role(
			'mainwp_page_staff',
			'Page staff',
			array(
				'read'     => true,
				'level_10' => true,
			)
		);
		$user = $this->factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_role( 'mainwp_page_staff' );
		wp_set_current_user( $user->ID );
		add_filter( 'mainwp_currentusercan', '__return_true', PHP_INT_MAX );
		update_option( 'mainwp_enableNativeBackups', 0 );
		try {
			$this->assertTrue( MainWP_System_Utility::is_admin() );
			$output = $this->render_manager();
		} finally {
			remove_filter( 'mainwp_currentusercan', '__return_true', PHP_INT_MAX );
			remove_role( 'mainwp_page_staff' );
		}

		$this->assertStringContainsString( 'Built-In Backups are turned off.', $output );
		$this->assertStringContainsString( 'Ask a Dashboard administrator to turn them on in the Backup Settings.', $output );
		$this->assertStringNotContainsString( 'page=BackupSettings#mainwp_options_enableNativeBackups', $output );
	}

	/**
	 * With native backups off only Manage Backups is registered; the other
	 * backup pages stay gated.
	 */
	public function test_menu_registers_only_manage_backups_with_native_off() {
		global $_registered_pages;
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$saved = $_registered_pages;
		foreach ( array( 'ManageBackups', 'ManageBackupsAddNew', 'ManageBackupsStorage', 'ManageBackupsSnapshots' ) as $slug ) {
			unset( $_registered_pages[ get_plugin_page_hookname( $slug, 'mainwp_tab' ) ] ); // Earlier tests may have registered them.
		}
		try {
			MainWP_Backup_Page::init_menu();
			$this->assertArrayHasKey( get_plugin_page_hookname( 'ManageBackups', 'mainwp_tab' ), $_registered_pages );
			foreach ( array( 'ManageBackupsAddNew', 'ManageBackupsStorage', 'ManageBackupsSnapshots' ) as $slug ) {
				$this->assertArrayNotHasKey( get_plugin_page_hookname( $slug, 'mainwp_tab' ), $_registered_pages, $slug . ' must stay gated.' );
			}
		} finally {
			$_registered_pages = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restore test global.
			delete_option( 'mainwp_enableNativeBackups' );
		}
	}

	/**
	 * The edit URL refuses Back Up Now's hidden per-site task.
	 */
	public function test_edit_url_refuses_hidden_on_demand_task() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task(
			(object) array(
				'id'     => 887731,
				'userid' => $user_id,
			)
		);
		try {
			$output = $this->render_manager( array( 'task_id' => (string) $task->id ) );
		} finally {
			MainWP_DB_Backup::instance()->remove_backup_task( (int) $task->id );
			delete_option( 'mainwp_enableNativeBackups' );
		}

		$this->assertStringContainsString( 'id="mainwp-backup-task-on-demand-refusal"', $output );
		$this->assertStringContainsString( 'page=ManageBackupsSnapshots&#038;site_id=887731', $output );
		$this->assertStringNotContainsString( 'mainwp_managebackups_update', $output, 'The schedule form must not open.' );
	}

	/**
	 * An empty task list shows the intro, an empty state with the two first
	 * steps, and the Run Now dialog closes with a Close button.
	 */
	public function test_empty_task_list_shows_intro_and_empty_state() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		try {
			$output = $this->render_manager();
		} finally {
			delete_option( 'mainwp_enableNativeBackups' );
		}

		$this->assertStringContainsString( 'notice-id="mainwp-backup-tasks-info-message"', $output );
		$this->assertStringContainsString( 'id="mainwp-backup-tasks-empty"', $output );
		$this->assertStringContainsString( 'mainwp-empty-page-placeholder', $output, 'The empty state uses the core placeholder.' );
		$this->assertStringNotContainsString( 'ui placeholder segment', $output );
		$this->assertStringContainsString( 'page=ManageBackupsAddNew', $output );
		$this->assertStringContainsString( 'page=ManageBackupsStorage', $output );
		$this->assertStringNotContainsString( 'Did you know that MainWP has extensions', $output );
		$this->assertMatchesRegularExpression( '/<i class="close icon" id="managebackups-task-status-close"/', $output );
	}
}
