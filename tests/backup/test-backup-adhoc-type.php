<?php
/**
 * Ad-hoc backup type tests: the single-site Backup Now form offers the
 * files-only type and the AJAX boundary only lets known types through.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

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
}
