<?php
/**
 * Restore nav-gap tests: the per-site Backups tab surfaces the native restore
 * flow (not the legacy SiteRestore link) when native backups is enabled, and
 * the shared restore surface still drives the standalone Snapshots page.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots_View;
use MainWP\Dashboard\MainWP_Manage_Sites_Backup_View;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Restore_Nav_Link_Test
 */
class MainWP_Backup_Restore_Nav_Link_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Act as an administrator (carries the execute_backups capability the
	 * restore surface and the per-site Backups tab both gate on).
	 *
	 * @return void
	 */
	private function as_admin() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A real, named archive file so show_backups()'s filemtime/filesize/
	 * basename calls succeed. Returns the absolute path; caller unlinks it.
	 *
	 * @return string Archive path.
	 */
	private function make_archive_file() {
		$path = wp_tempnam( 'full-test-site.tar.gz' );
		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		return $path;
	}

	/**
	 * Embedded (locked) the surface renders the snapshot list and restore modal,
	 * locks to the given site, and drops the site picker.
	 *
	 * @return void
	 */
	public function test_locked_surface_embeds_without_site_picker() {
		$this->as_admin();

		ob_start();
		MainWP_Backup_Snapshots_View::render_restore_surface( 4242, true );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-restore-snapshots-table', $out, 'The locked surface must render the snapshot list.' );
		$this->assertStringContainsString( 'mainwp-restore-modal', $out, 'The locked surface must render the restore modal.' );
		$this->assertStringContainsString( 'var currentSite = 4242;', $out, 'The locked surface must seed the locked site id so snapshots auto-load.' );
		$this->assertStringNotContainsString( '<select id="mainwp-restore-site-selector"', $out, 'The locked surface must not render the site picker element.' );
	}

	/**
	 * The standalone Snapshots page keeps the site picker (the shared surface
	 * still serves the unlocked page after the extract).
	 *
	 * @return void
	 */
	public function test_standalone_page_keeps_site_picker() {
		$this->as_admin();

		ob_start();
		MainWP_Backup_Snapshots_View::render();
		$out = ob_get_clean();

		$this->assertStringContainsString( '<select id="mainwp-restore-site-selector"', $out, 'The standalone page must keep the site picker.' );
		$this->assertStringContainsString( 'mainwp-restore-snapshots-table', $out, 'The standalone page must still render the snapshot list.' );
	}

	/**
	 * The per-site Backups tab surfaces the native restore for that site and
	 * does not offer the broken legacy SiteRestore link.
	 *
	 * @return void
	 */
	public function test_per_site_tab_surfaces_native_restore() {
		$this->as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		ob_start();
		MainWP_Manage_Sites_Backup_View::render_backup_site( $website );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-restore-snapshots-table', $out, 'The per-site Backups tab must surface the native restore.' );
		$this->assertStringContainsString( 'var currentSite = ' . (int) $site_id . ';', $out, 'The embedded surface must lock to this site.' );
		$this->assertStringNotContainsString( 'page=SiteRestore', $out, 'The broken legacy restore link must not appear under native backups.' );
	}

	/**
	 * show_backups() drops the per-file legacy SiteRestore link under native
	 * backups (Download stays) and keeps it when native backups is off.
	 *
	 * @return void
	 */
	public function test_show_backups_legacy_link_gated_on_native() {
		$this->as_admin();

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$archive     = $this->make_archive_file();
		$fullBackups = array( filemtime( $archive ) . basename( $archive ) => $archive );
		$dbBackups   = array();

		update_option( 'mainwp_enableNativeBackups', 1 );
		ob_start();
		MainWP_Manage_Sites_Backup_View::show_backups( $website, $fullBackups, $dbBackups );
		$native_on = ob_get_clean();

		update_option( 'mainwp_enableNativeBackups', '' );
		ob_start();
		MainWP_Manage_Sites_Backup_View::show_backups( $website, $fullBackups, $dbBackups );
		$native_off = ob_get_clean();

		wp_delete_file( $archive );

		$this->assertStringNotContainsString( 'page=SiteRestore', $native_on, 'Native on: the legacy restore link is suppressed.' );
		$this->assertStringContainsString( '>Download</a>', $native_on, 'Native on: the Download link stays.' );
		$this->assertStringContainsString( 'page=SiteRestore', $native_off, 'Native off: the legacy restore link remains.' );
	}
}
