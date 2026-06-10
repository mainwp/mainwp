<?php
/**
 * MainWP Backup Archive Format Tests
 *
 * Covers archive format resolution for sites without a per-site backup
 * settings row. The site-default path must fall back to the global
 * format instead of returning null, which previously produced a
 * malformed ext parameter in the child backup request.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Handler;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Archive_Format_Test
 */
class MainWP_Backup_Archive_Format_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Clean up the global format option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( 'mainwp_archiveFormat' );
		parent::tearDown();
	}

	/**
	 * A site that never had per-site backup settings saved must resolve to
	 * the global default format, not null.
	 *
	 * @return void
	 */
	public function test_site_without_backup_settings_falls_back_to_global_default() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		delete_option( 'mainwp_archiveFormat' );

		$this->assertSame( 'tar.gz', MainWP_Backup_Handler::get_current_archive_extension( $website ) );
	}

	/**
	 * The configured global format wins when the site has no settings row.
	 *
	 * @return void
	 */
	public function test_site_without_backup_settings_uses_configured_global_format() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		update_option( 'mainwp_archiveFormat', 'zip' );

		$this->assertSame( 'zip', MainWP_Backup_Handler::get_current_archive_extension( $website ) );
	}

	/**
	 * A saved per-site format still wins over the global one.
	 *
	 * @return void
	 */
	public function test_site_with_saved_format_keeps_it() {
		global $wpdb;

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_settings_backup',
			array(
				'wpid'          => $site_id,
				'archiveFormat' => 'tar.bz2',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		update_option( 'mainwp_archiveFormat', 'zip' );

		$this->assertSame( 'tar.bz2', MainWP_Backup_Handler::get_current_archive_extension( $website ) );
	}
}
