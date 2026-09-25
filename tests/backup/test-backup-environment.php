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

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Backup_Page;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_Backup_Private_Root;

/**
 * Class MainWP_Backup_Environment_Test
 */
class MainWP_Backup_Environment_Test extends \WP_UnitTestCase {

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
	 * A non-writable backup directory is a hard failure before any child
	 * site communication.
	 *
	 * @return void
	 */
	public function test_non_writable_backup_dir_throws() {
		if ( 0 === (int) getmyuid() ) {
			$this->markTestSkipped( 'Permission checks do not apply when running as root.' );
		}

		mkdir( $this->private_root, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		try {
			MainWP_Backup_Monitor::validate_backup_environment();
			$this->fail( 'Expected exception for a non-writable backup directory.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $e->get_message_error_code() );
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
