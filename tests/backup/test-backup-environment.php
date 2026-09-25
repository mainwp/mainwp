<?php
/**
 * MainWP Backup Environment Tests
 *
 * Tests for the dashboard-side pre-flight environment validation, the
 * AJAX resource limit helper, and the backup page environment notices.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Page;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class MainWP_Backup_Environment_Test
 */
class MainWP_Backup_Environment_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Isolate the destination checked by preflight.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_private_root();
	}

	/**
	 * Restore permissions on the backup dir.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * A healthy environment passes without exceptions.
	 *
	 * @return void
	 */
	public function test_healthy_environment_passes() {
		MainWP_Backup_Monitor::validate_backup_environment();
		$this->assertTrue( true, 'No exception for a healthy environment.' );
	}

	/**
	 * Advisory environment reads do not probe or change a read-only root.
	 *
	 * @return void
	 */
	public function test_non_writable_backup_dir_remains_readable() {
		mkdir( $this->private_root, 0500 );
		MainWP_Backup_Monitor::validate_backup_environment();
		$this->assertSame( $this->private_root . '/', MainWP_Backup_Private_Root::resolve() );
		$this->assertSame( 0500, fileperms( $this->private_root ) & 0777 );
		$this->assertSame( array( '.', '..' ), scandir( $this->private_root ) );
	}

	/**
	 * Backup refuses an unwritable root before contacting the child.
	 *
	 * @return void
	 */
	public function test_backup_refuses_read_only_root_before_http() {
		$this->assertTrue( mkdir( $this->private_root, 0500 ) );
		if ( is_writable( $this->private_root ) ) {
			$this->markTestSkipped( 'The process can write read-only directories (for example, as root).' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id  = $this->create_test_site();
		$enabled  = get_option( 'mainwp_enableNativeBackups' );
		$requests = 0;
		$http     = static function () use ( &$requests ) {
			++$requests;
			return new \WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
		};
		update_option( 'mainwp_enableNativeBackups', 1 );
		add_filter( 'pre_http_request', $http );
		try {
			$caught = null;
			try {
				MainWP_Backup_Executor::backup( $site_id, 'full', '', '', false, false, false, false );
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $caught->get_message_error_code() );
			$this->assertSame( 0, $requests );
		} finally {
			remove_filter( 'pre_http_request', $http );
			if ( false === $enabled ) {
				delete_option( 'mainwp_enableNativeBackups' );
			} else {
				update_option( 'mainwp_enableNativeBackups', $enabled );
			}
		}
	}

	/**
	 * Unknown sites must not leave a private backup directory behind.
	 *
	 * @return void
	 */
	public function test_backup_unknown_site_does_not_create_private_directory() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertEmpty( \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( 987654 ) );
		$enabled = get_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableNativeBackups', 1 );
		try {
			$caught = null;
			try {
				MainWP_Backup_Executor::backup( 987654, 'full', '', '', false, false, false, false );
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( 'Site not found.', $caught->getMessage() );
			$this->assertDirectoryDoesNotExist( $this->private_root . '/sites/987654' );
			$this->assertDirectoryDoesNotExist( $this->private_root );
		} finally {
			if ( false === $enabled ) {
				delete_option( 'mainwp_enableNativeBackups' );
			} else {
				update_option( 'mainwp_enableNativeBackups', $enabled );
			}
		}
	}

	/**
	 * Scheduled backups for unknown sites must not create private directories.
	 *
	 * @return void
	 */
	public function test_backup_site_unknown_site_does_not_create_private_directory() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertEmpty( \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( 987654 ) );
		$task    = (object) array(
			'userid'        => get_current_user_id(),
			'type'          => 'full',
			'exclude'       => '',
			'id'            => 1,
			'excludebackup' => false,
			'excludecache'  => false,
			'excludenonwp'  => false,
			'excludezip'    => false,
			'filename'      => '',
		);
		$enabled = get_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableNativeBackups', 1 );
		try {
			$caught = null;
			try {
				MainWP_Backup_Executor::backup_site( 987654, $task, '' );
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( 'Site not found.', $caught->getMessage() );
			$this->assertDirectoryDoesNotExist( $this->private_root . '/sites/987654' );
			$this->assertDirectoryDoesNotExist( $this->private_root );
		} finally {
			if ( false === $enabled ) {
				delete_option( 'mainwp_enableNativeBackups' );
			} else {
				update_option( 'mainwp_enableNativeBackups', $enabled );
			}
		}
	}

	/**
	 * The resource limit helper registers the elevated memory filter.
	 *
	 * @return void
	 */
	public function test_resource_limits_helper_raises_memory_filter() {
		MainWP_Post_Backup_Handler::raise_backup_resource_limits();

		$this->assertNotFalse( has_filter( 'admin_memory_limit' ), 'The elevated memory filter must be registered.' );
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
	 * The environment notices escape private-root failure messages.
	 *
	 * @return void
	 */
	public function test_environment_notices_warn_for_private_root_failure() {
		add_filter(
			'mainwp_backup_private_root',
			static function () {
				return "bad\0<unsafe>";
			},
			99
		);
		MainWP_Backup_Private_Root::reset();
		$error = MainWP_Backup_Private_Root::resolve();
		$this->assertWPError( $error );

		ob_start();
		MainWP_Backup_Page::render_backup_environment_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( esc_html( $error->get_error_message() ), $output );
		$this->assertStringNotContainsString( '<unsafe>', $output );
		$this->assertStringContainsString( 'ui yellow message', $output );
	}
}
