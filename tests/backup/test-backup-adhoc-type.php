<?php
/**
 * Ad-hoc backup type tests: the single-site Backup Now form offers the
 * files-only type and the AJAX boundary only lets known types through.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Sites_Backup_View;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Adhoc_Type_Test
 */
class MainWP_Backup_Adhoc_Type_Test extends MainWP_Abilities_Test_Case {

	/**
	 * The single-site backup form offers the files-only type next to
	 * full and database.
	 *
	 * @return void
	 */
	public function test_single_site_backup_dropdown_offers_files() {
		$site_id = $this->create_test_site();

		ob_start();
		MainWP_Manage_Sites_Backup_View::render_backup_options( $site_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="files"', $output );
		$this->assertStringContainsString( 'Files Backup', $output );
	}

	/**
	 * Known ad-hoc types pass through the boundary unchanged.
	 *
	 * @return void
	 */
	public function test_known_adhoc_types_pass() {
		$this->assertSame( 'full', MainWP_Post_Backup_Handler::sanitize_adhoc_backup_type( 'full' ) );
		$this->assertSame( 'db', MainWP_Post_Backup_Handler::sanitize_adhoc_backup_type( 'db' ) );
		$this->assertSame( 'files', MainWP_Post_Backup_Handler::sanitize_adhoc_backup_type( 'files' ) );
	}

	/**
	 * Incremental cannot run ad hoc - its chain lives on a scheduled
	 * task - so the boundary folds it to full.
	 *
	 * @return void
	 */
	public function test_incremental_folds_to_full() {
		$this->assertSame( 'full', MainWP_Post_Backup_Handler::sanitize_adhoc_backup_type( 'incremental' ) );
	}

	/**
	 * Arbitrary wire input never reaches the executor as a type.
	 *
	 * @return void
	 */
	public function test_unknown_types_fold_to_full() {
		$this->assertSame( 'full', MainWP_Post_Backup_Handler::sanitize_adhoc_backup_type( 'totally-bogus' ) );
		$this->assertSame( 'full', MainWP_Post_Backup_Handler::sanitize_adhoc_backup_type( '' ) );
	}

	/**
	 * Mock per-action child responses.
	 *
	 * @param array $responses Map of action => response array.
	 *
	 * @return void
	 */
	private function mock_child_actions( $responses ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $responses ) {
				unset( $website );
				return isset( $responses[ $what ] ) ? $responses[ $what ] : $result;
			},
			10,
			3
		);
	}

	/**
	 * A minimal passing preflight report.
	 *
	 * @return array Readiness report.
	 */
	private function preflight_response() {
		return array(
			'disk_free'       => 10 * GB_IN_BYTES,
			'dir_writable'    => true,
			'db_connected'    => true,
			'memory_limit'    => '256M',
			'max_exec_time'   => '30',
			'zip_support'     => true,
			'gz_support'      => true,
			'bz2_support'     => true,
			'time_limit_ok'   => true,
			'backup_dir_hash' => 'abc123def456gh78',
		);
	}

	/**
	 * An ad-hoc files backup whose child answers with the archive on the
	 * full slot succeeds and reports the artifact.
	 *
	 * @return void
	 */
	public function test_adhoc_files_backup_accepts_full_slot_artifact() {
		update_option( 'mainwp_enableNativeBackups', 1 );
		$site_id = $this->create_test_site();
		$this->mock_child_actions(
			array(
				'backup_preflight' => $this->preflight_response(),
				'backup'           => array(
					'full' => 'https://child.test/files-archive.tar.gz',
					'db'   => false,
					'size' => 12345,
				),
			)
		);

		$result = MainWP_Backup_Executor::backup( $site_id, 'files', '', '', 1, 1, 1, 1 );

		$this->assertSame( 'https://child.test/files-archive.tar.gz', $result['url'] );
	}

	/**
	 * A child that does not produce the files archive (an older child's
	 * legacy backup action knows only full and db) fails loudly instead
	 * of reporting an empty success.
	 *
	 * @return void
	 */
	public function test_adhoc_files_backup_throws_when_child_produces_nothing() {
		update_option( 'mainwp_enableNativeBackups', 1 );
		$site_id = $this->create_test_site();
		$this->mock_child_actions(
			array(
				'backup_preflight' => $this->preflight_response(),
				'backup'           => array(
					'full' => false,
					'db'   => false,
				),
			)
		);

		try {
			MainWP_Backup_Executor::backup( $site_id, 'files', '', '', 1, 1, 1, 1 );
			$this->fail( 'An ad-hoc files backup with no artifact must throw.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $e->get_message_error_code() );
		}
	}
}
