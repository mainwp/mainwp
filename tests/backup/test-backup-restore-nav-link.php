<?php
/**
 * Restore nav-gap tests: the per-site Backups tab surfaces the native restore
 * flow (not the legacy SiteRestore link) when native backups is enabled, and
 * the shared restore surface still drives the standalone Snapshots page.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Snapshots_View;
use MainWP\Dashboard\MainWP_Manage_Sites_Backup_View;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Restore_Nav_Link_Test
 */
class MainWP_Backup_Restore_Nav_Link_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;

	use Backup_Private_Root_Fixture;

	/**
	 * Isolate native archive fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
	}

	/**
	 * Remove the private archive tree.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * The existing tab lists both roots with their original download mechanism.
	 */
	public function test_show_backups_lists_private_and_legacy_signed_links() {
		$this->as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$private = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		$legacy  = \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir( $site_id );
		$files   = array( $private . 'full-private.zip', $private . 'db-private.sql', $legacy . 'full-legacy.zip', $legacy . 'db-legacy.sql' );
		foreach ( $files as $file ) {
			file_put_contents( $file, 'archive bytes' );
		}
		try {
			ob_start();
			\MainWP\Dashboard\MainWP_Manage_Sites::show_backups( $website );
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'page=SiteRestore', $html );
			preg_match_all( '/<a title="([^"]+)" href="([^"]+)"/', $html, $matches, PREG_SET_ORDER );
			$this->assertCount( 4, $matches );
			foreach ( $matches as $match ) {
				parse_str( wp_parse_url( html_entity_decode( $match[2] ), PHP_URL_QUERY ), $query );
				$is_private = false !== strpos( $match[1], 'private' );
				$file       = ( $is_private ? $private : $legacy ) . $match[1];
				$this->assertTrue( \MainWP\Dashboard\MainWP_System_Utility::valid_download_sig( $file, $query['sig'] ) );
				$this->assertSame( ( $is_private ? 'sites/' : '0/' ) . $site_id . '/' . $match[1], $query['mwpdl'] );
				if ( $is_private ) {
					$this->assertSame( 'private', $query['root'] );
				} else {
					$this->assertArrayNotHasKey( 'root', $query );
				}
			}
			add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
			\MainWP\Dashboard\MainWP_Backup_Private_Root::reset();
			ob_start();
			\MainWP\Dashboard\MainWP_Manage_Sites::show_backups( $website );
			$legacy_only = ob_get_clean();
			$this->assertStringContainsString( 'full-legacy.zip', $legacy_only );
			$this->assertStringContainsString( 'db-legacy.sql', $legacy_only );
			$this->assertStringNotContainsString( 'root=private', $legacy_only );
		} finally {
			foreach ( $files as $file ) {
				wp_delete_file( $file );
			}
		}
	}

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
		$this->assertStringNotContainsString( 'mainwp-main-content', $out, 'Without a sidebar the archive block spans the full width.' );
		$this->assertMatchesRegularExpression( '/id="managesite-backup-status-close"[^>]*value="Close" class="ui button"/', $out );
		$this->assertStringNotContainsString( 'mainwp-kbl-content', $out, 'The known-location lists are resolved on the site, so no empty box is shown.' );
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

	/**
	 * Under native backups the list drops the "Backup Details" heading: the
	 * legacy section's own header and intro explain the archives, and the
	 * in-place list refresh returns the same markup. Off, the original
	 * heading stays.
	 *
	 * @return void
	 */
	public function test_native_relabels_legacy_archive_section() {
		$this->as_admin();

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		update_option( 'mainwp_enableNativeBackups', 1 );
		ob_start();
		MainWP_Manage_Sites_Backup_View::show_backups( $website, array(), array() );
		$native_on = ob_get_clean();

		update_option( 'mainwp_enableNativeBackups', '' );
		ob_start();
		MainWP_Manage_Sites_Backup_View::show_backups( $website, array(), array() );
		$native_off = ob_get_clean();

		$this->assertStringNotContainsString( 'Backup Details', $native_on, 'Native on: the accordion title names the section instead.' );
		$this->assertStringNotContainsString( 'snapshot', $native_on, 'Native on: the explanation lives in the section intro, not repeated in the list.' );
		$this->assertStringContainsString( 'No full backup has been taken yet', $native_on );
		$this->assertStringNotContainsString( 'Downloadable archives', $native_off, 'Native off: the legacy heading is unchanged.' );
		$this->assertStringContainsString( 'Backup Details', $native_off, 'Native off: the original heading stays.' );
	}

	/**
	 * Under native backups the Backup Options form and its action read as
	 * creating a one-off downloadable archive, not a restorable snapshot.
	 *
	 * @return void
	 */
	public function test_native_relabels_backup_now_action() {
		$this->as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		ob_start();
		MainWP_Manage_Sites_Backup_View::render_backup_site( $website );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Create a downloadable archive', $out, 'Native on: the section heading reads as creating a downloadable archive.' );
		$this->assertStringContainsString( 'value="Create downloadable archive"', $out, 'Native on: the action button itself is relabelled, not just the heading.' );
		$this->assertStringNotContainsString( 'value="Backup Now"', $out, 'Native on: the misleading Backup Now button label must be gone.' );
		$this->assertStringContainsString( 'id="mainwp-downloadable-archives-accordion"', $out, 'Native on: the legacy archives sit in one collapsed section.' );
		$this->assertStringContainsString( 'class="ui basic button" value="Create downloadable archive"', $out, 'Native on: the archive button is not a second primary.' );
	}

	/**
	 * MWP-1916: under native backups the legacy archive list and form live in
	 * their own labeled section after Snapshots & Restore, not inside it, and
	 * stay collapsed. The ids the legacy JS and the in-place list refresh use
	 * are kept.
	 *
	 * @return void
	 */
	public function test_native_moves_legacy_archives_out_of_snapshots() {
		$this->as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		ob_start();
		MainWP_Manage_Sites_Backup_View::render_backup_site( $website );
		$out = ob_get_clean();

		$xpath     = $this->parse_html( $out );
		$snapshots = $xpath->query( '//*[@id="mainwp-backup-snapshots"]' )->item( 0 );
		$legacy    = $xpath->query( '//*[@id="mainwp-legacy-archives"]' )->item( 0 );
		$this->assertNotNull( $snapshots, 'The Snapshots & Restore surface renders.' );
		$this->assertNotNull( $legacy, 'The legacy archives get their own section.' );
		$this->assertMatchesRegularExpression( '/\\bsegment\\b/', $legacy->getAttribute( 'class' ) );

		foreach ( array( 'mainwp-downloadable-archives-accordion', 'mainwp-downloadable-archives-list', 'backup_btnSubmit', 'mainwp-message-zone', 'mainwp-known-backup-locations' ) as $id ) {
			$node = $xpath->query( '//*[@id="' . $id . '"]' )->item( 0 );
			$this->assertNotNull( $node, $id . ' is still rendered.' );
			$this->assertFalse( $this->is_inside( $node, $snapshots ), $id . ' must not sit inside Snapshots & Restore.' );
			$this->assertTrue( $this->is_inside( $node, $legacy ), $id . ' sits in the legacy section.' );
		}
		$this->assertFalse( $this->is_inside( $legacy, $snapshots ), 'The legacy section is a sibling of the snapshots surface, not part of it.' );
		$this->assertLessThan( strpos( $out, 'id="mainwp-legacy-archives"' ), strpos( $out, 'id="mainwp-backup-snapshots"' ), 'The legacy section comes after Snapshots & Restore.' );

		$header = $xpath->query( './/h3[contains(concat(" ", normalize-space(@class), " "), " header ")]', $legacy )->item( 0 );
		$this->assertNotNull( $header, 'The legacy section has its own header.' );
		$this->assertStringContainsString( 'Legacy', $header->textContent, 'The header names the section as the legacy feature.' );

		$accordion = $xpath->query( '//*[@id="mainwp-downloadable-archives-accordion"]' )->item( 0 );
		$this->assertSame( 0, $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " active ")]', $accordion )->length, 'The list and form stay collapsed by default.' );

		$this->assertSame( 1, $xpath->query( '//*[@id="managesite-backup-status-box"]' )->length, 'The legacy progress modal stays on the page.' );
	}

	/**
	 * With native backups off the legacy Backups page keeps its old layout.
	 *
	 * @return void
	 */
	public function test_native_off_keeps_legacy_layout() {
		$this->as_admin();
		update_option( 'mainwp_enableNativeBackups', '' );

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		ob_start();
		MainWP_Manage_Sites_Backup_View::render_backup_site( $website );
		$out = ob_get_clean();

		$this->assertStringNotContainsString( 'mainwp-legacy-archives', $out );
		$this->assertStringNotContainsString( 'mainwp-downloadable-archives-accordion', $out );
		$this->assertStringNotContainsString( 'mainwp-backup-snapshots', $out );
		$this->assertStringContainsString( 'value="Backup Now"', $out );
		$this->assertStringContainsString( 'Backup Details', $out );
	}

	/**
	 * Parse rendered markup for structural assertions.
	 *
	 * @param string $html Rendered markup.
	 *
	 * @return \DOMXPath
	 */
	private function parse_html( $html ) {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return new \DOMXPath( $dom );
	}

	/**
	 * Whether $node is $ancestor or one of its descendants.
	 *
	 * @param \DOMNode $node     Node.
	 * @param \DOMNode $ancestor Possible ancestor.
	 *
	 * @return bool
	 */
	private function is_inside( $node, $ancestor ) {
		for ( $n = $node; null !== $n; $n = $n->parentNode ) {
			if ( $n->isSameNode( $ancestor ) ) {
				return true;
			}
		}
		return false;
	}
}
