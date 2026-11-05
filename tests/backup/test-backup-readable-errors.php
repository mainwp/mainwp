<?php
/**
 * Readable backup errors name the machine that needs the fix.
 *
 * A Child refusal carrying a registered code reaches the user as the
 * registry text, so that text must say whether the child site or the
 * Dashboard server is at fault.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Readable_Errors_Test
 */
class MainWP_Backup_Readable_Errors_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Child's own refusal text, which the registry text replaces.
	 */
	const CHILD_TEXT = 'Child-side refusal text.';

	/**
	 * Code the fake Child refuses the task create with.
	 *
	 * @var string
	 */
	private $code = '';

	/**
	 * Native backups on, an administrator, and a test-owned private root.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), 10, 3 );
	}

	/**
	 * Remove committed rows and fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), 10 );
		foreach ( array( 'wp_backup_run_history', 'wp_backup_snapshot', 'wp_backup_progress', 'wp_backup' ) as $key ) {
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed test tables.
		}
		delete_option( 'mainwp_enableNativeBackups' );
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Pass preflight, then refuse the task create the way a Child reports an error.
	 *
	 * @param mixed  $pre  Transport result.
	 * @param object $site Site row.
	 * @param string $what Child action.
	 * @return array
	 * @throws \RuntimeException Unexpected network action.
	 */
	public function child_response( $pre, $site, $what ) {
		if ( 'backup_preflight' === $what ) {
			return array(
				'task_spine'  => 1,
				'zip_support' => true,
			);
		}
		if ( 'backup_task_create' === $what ) {
			return array(
				'error'      => self::CHILD_TEXT,
				'error_code' => $this->code,
			);
		}
		throw new \RuntimeException( 'Unexpected Child action: ' . $what ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception.
	}

	/**
	 * The task driver stores and shows text that names the machine to fix.
	 *
	 * @dataProvider child_codes
	 * @param string $code     Registered error code.
	 * @param string $expected Text the user sees.
	 */
	public function test_child_error_code_shows_text_naming_the_machine( string $code, string $expected ): void {
		$this->code = $code;
		$site_id    = $this->create_test_site( array( 'userid' => get_current_user_id() ) );
		$task       = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Readable errors', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertIsObject( $task );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
		$this->assertSame( $expected, $progress->last_error, 'The progress dialog shows the registry text.' );
		$this->assertStringNotContainsString( self::CHILD_TEXT, $progress->last_error );
		$errors = (string) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->backup_errors;
		$this->assertStringContainsString( $expected, $errors, 'The task error list shows the same text.' );
		$this->assertSame( $code, $progress->fetchResult['error_code'] ?? null );
	}

	/**
	 * Child refusal codes whose text must name a machine.
	 *
	 * @return array
	 */
	public static function child_codes(): array {
		return array(
			'directory not writable' => array( MainWP_Backup_Error_Codes::ERR_DIR_NOT_WRITABLE, 'The MainWP backup folder in the uploads folder on the child site is not writable. Allow the web server on that site to write to it, or contact the hosting provider for that site.' ),
			'permissions'            => array( MainWP_Backup_Error_Codes::ERR_PERMISSIONS, 'Could not create a backup file or folder. Check that the web server can write to the MainWP backup folder on the child site and to the private backup folder on the Dashboard server.' ),
			'zip failed'             => array( MainWP_Backup_Error_Codes::ERR_ZIP_FAILED, 'Could not create the ZIP archive on the child site. The ZipArchive PHP extension may not be available there, or the backup folder is not writable. Check the PHP configuration and folder permissions on the child site.' ),
			'tar write failed'       => array( MainWP_Backup_Error_Codes::ERR_TAR_WRITE_FAILED, 'Could not write to the backup archive on the child site. The child site may be out of disk space, or the backup folder permissions may have changed. Free up disk space or check folder permissions on the child site.' ),
		);
	}
}
