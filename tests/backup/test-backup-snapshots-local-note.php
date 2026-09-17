<?php
/**
 * MainWP Backup Snapshots "local-only" note tests.
 *
 * The Snapshots/restore surface shows a note next to "Back up now" when the
 * site has no offsite destination, so an on-demand backup that will stay on
 * the dashboard does not look like a misconfiguration. The note's initial
 * visibility is decided server-side from the same destinations union the
 * manual task inherits.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Snapshots_View;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;

/**
 * Class MainWP_Backup_Snapshots_Local_Note_Test
 */
class MainWP_Backup_Snapshots_Local_Note_Test extends \WP_UnitTestCase {

	/**
	 * Fake site id; no real website row is needed because the note only
	 * reads the destinations union, keyed by site id.
	 *
	 * @var int
	 */
	private $site_id = 990020;

	/**
	 * Enable native backups and act as an administrator so the surface
	 * renders.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Drop fixture task rows and the native toggle between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->query( "DELETE FROM {$table} WHERE name LIKE 'Local note test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifier cannot be prepared.

		delete_option( 'mainwp_enableNativeBackups' );

		parent::tear_down();
	}

	/**
	 * Insert a scheduled (template = 0) task targeting a site with
	 * destinations, so the destinations union is non-empty.
	 *
	 * @param string $sites        Comma-separated site ids.
	 * @param array  $destinations Destination ids.
	 *
	 * @return void
	 */
	private function insert_scheduled_task( $sites, array $destinations ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'userid'          => 1,
				'name'            => 'Local note test ' . uniqid(),
				'schedule'        => 'daily',
				'type'            => 'full',
				'exclude'         => '',
				'sites'           => $sites,
				'groups'          => '',
				'subfolder'       => '',
				'filename'        => '',
				'completed_sites' => '',
				'backup_errors'   => '',
				'archiveFormat'   => 'site',
				'template'        => 0,
				'destinations'    => wp_json_encode( $destinations ),
			)
		);
	}

	/**
	 * Capture the rendered per-site restore surface.
	 *
	 * @return string Rendered markup.
	 */
	private function render_surface() {
		ob_start();
		MainWP_Backup_Snapshots_View::render_restore_surface( $this->site_id, true );
		return (string) ob_get_clean();
	}

	/**
	 * With no scheduled task, the on-demand backup stays local, so the note
	 * renders visible (local-only flagged on).
	 *
	 * @return void
	 */
	public function test_note_visible_when_no_destination_configured() {
		$out = $this->render_surface();

		$this->assertStringContainsString( 'mainwp-native-backup-now-note', $out );
		$this->assertStringContainsString( 'data-local-only="1"', $out );
		$this->assertMatchesRegularExpression( '/id="mainwp-native-backup-now-note"[^>]*style="margin-top: \.5em; opacity: \.8;"/', $out );
		$this->assertStringContainsString( '<span class="mainwp-backup-local-note">No backup destination is configured for this site, so this backup will be kept on the dashboard.</span>', $out );
	}

	/**
	 * With a scheduled task carrying a destination, the on-demand backup
	 * names the inherited destinations while the local-only flag stays off.
	 *
	 * @return void
	 */
	public function test_note_names_destinations_when_configured() {
		$destinations = MainWP_Backup_Storage_Destinations::instance();
		$dest_a       = $destinations->add_destination( array( 'provider' => 'local', 'name' => 'Z & archive' ) );
		$dest_b       = $destinations->add_destination( array( 'provider' => 'local', 'name' => 'A archive' ) );
		$this->insert_scheduled_task( (string) $this->site_id, array( $dest_b, $dest_a ) );

		$out = $this->render_surface();

		$this->assertStringContainsString( 'mainwp-native-backup-now-note', $out );
		$this->assertStringContainsString( 'data-local-only="0"', $out );
		$this->assertMatchesRegularExpression( '/id="mainwp-native-backup-now-note"[^>]*style="margin-top: \.5em; opacity: \.8;"/', $out );
		$this->assertStringContainsString( '<span class="mainwp-backup-local-note" style="display: none;">', $out );
		$this->assertStringContainsString( '<span class="mainwp-backup-destinations-note">Back Up Now saves this backup to the destinations of the scheduled backup tasks that include this site: Z &amp; archive, A archive.</span>', $out );
	}

	/**
	 * A stale destination reference remains visible in the inherited list.
	 */
	public function test_note_names_removed_destination() {
		$destinations = MainWP_Backup_Storage_Destinations::instance();
		$dest_id      = $destinations->add_destination( array( 'provider' => 'local', 'name' => 'Removed archive' ) );
		$this->insert_scheduled_task( (string) $this->site_id, array( $dest_id ) );
		$destinations->delete_destination( $dest_id );

		$out = $this->render_surface();

		$this->assertStringContainsString( 'data-local-only="0"', $out );
		$this->assertStringContainsString( 'include this site: Destination #' . $dest_id . ' (removed).', $out );
	}
}
