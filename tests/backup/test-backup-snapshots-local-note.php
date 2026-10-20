<?php
/**
 * MainWP Backup Snapshots "local-only" note tests.
 *
 * The Snapshots/restore surface shows a note next to "Back up now" when the
 * site has no offsite destination, so an on-demand backup that will stay on
 * the dashboard does not look like a misconfiguration. The note's initial
 * visibility is decided server-side from the same destination plan the
 * manual task uses.
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
	 * Without a scheduled task or Default, the backup stays local, so the note
	 * renders visible (local-only flagged on).
	 *
	 * @return void
	 */
	public function test_note_visible_when_no_destination_configured() {
		$out = $this->render_surface();

		$this->assertStringContainsString( 'mainwp-native-backup-now-note', $out );
		$this->assertStringContainsString( 'data-local-only="1"', $out );
		$this->assertMatchesRegularExpression( '/id="mainwp-native-backup-now-note"[^>]*style="margin-top: \.5em;"/', $out );
		$this->assertStringContainsString( '<div class="ui blue message mainwp-backup-local-note">No backup destination is configured for this site, so this backup will be kept on the dashboard.</div>', $out );
	}

	/**
	 * With a scheduled task carrying a destination, the on-demand backup
	 * names the inherited destinations while the local-only flag stays off.
	 *
	 * @return void
	 */
	public function test_note_names_destinations_when_configured() {
		$destinations = MainWP_Backup_Storage_Destinations::instance();
		$dest_a       = $destinations->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Z & archive',
			)
		);
		$dest_b       = $destinations->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'A archive',
			)
		);
		$destinations->add_destination(
			array(
				'provider'   => 'local',
				'name'       => 'Unused Default',
				'is_default' => 1,
			)
		);
		$this->insert_scheduled_task( (string) $this->site_id, array( $dest_b, $dest_a ) );

		$out = $this->render_surface();

		$this->assertStringContainsString( 'mainwp-native-backup-now-note', $out );
		$this->assertStringContainsString( 'data-local-only="0"', $out );
		$this->assertMatchesRegularExpression( '/id="mainwp-native-backup-now-note"[^>]*style="margin-top: \.5em;"/', $out );
		$this->assertStringContainsString( '<div class="ui blue message mainwp-backup-local-note" style="display: none;">', $out );
		$this->assertStringContainsString( '<div class="ui blue message mainwp-backup-destinations-note">Backup destinations: Dashboard + Z &amp; archive + A archive. These come from the scheduled backup tasks that include this site.</div>', $out );
	}

	/**
	 * A stale destination reference remains visible in the inherited list.
	 */
	public function test_note_names_removed_destination() {
		$destinations = MainWP_Backup_Storage_Destinations::instance();
		$dest_id      = $destinations->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Removed archive',
			)
		);
		$this->insert_scheduled_task( (string) $this->site_id, array( $dest_id ) );
		$destinations->delete_destination( $dest_id );

		$out = $this->render_surface();

		$this->assertStringContainsString( 'data-local-only="0"', $out );
		$this->assertStringContainsString( 'Backup destinations: Dashboard + Destination #' . $dest_id . ' (removed). These come from the scheduled backup tasks that include this site.', $out );
	}

	/** The Default must be visible before a site with no scheduled task starts. */
	public function test_note_names_default_without_covering_task() {
		$id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider'   => 'local',
				'name'       => 'Default & archive',
				'is_default' => 1,
			)
		);
		$this->assertGreaterThan( 0, $id );
		$out = $this->render_surface();
		$this->assertStringContainsString( 'data-local-only="0"', $out );
		$this->assertStringContainsString( '<div class="ui blue message mainwp-backup-destinations-note">Backup destinations: Dashboard + Default &amp; archive. No scheduled backup task includes this site, so Back Up Now uses the Default destination.</div>', $out );
		$this->assertStringContainsString( '<div class="ui blue message mainwp-backup-local-note" style="display: none;">', $out );
	}

	/** An empty covering task deliberately keeps its site Dashboard-only. */
	public function test_empty_covering_task_explains_dashboard_only() {
		MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider'   => 'local',
				'name'       => 'Unused Default',
				'is_default' => 1,
			)
		);
		$this->insert_scheduled_task( (string) $this->site_id, array() );
		$out = $this->render_surface();
		$this->assertStringContainsString( 'data-local-only="1"', $out );
		$this->assertStringContainsString( '<div class="ui blue message mainwp-backup-local-note">The scheduled backup tasks that include this site have no backup destination, so this backup will be kept on the dashboard.</div>', $out );
		$this->assertStringContainsString( '<div class="ui blue message mainwp-backup-destinations-note" style="display: none;">', $out );
	}

	/** Both sources must treat stored destination names as untrusted text. */
	public function test_destination_names_are_escaped_for_tasks_and_default() {
		$name = '<img src=x onerror=alert(1)> & archive';
		$id   = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider'   => 'local',
				'name'       => $name,
				'is_default' => 1,
			)
		);
		foreach ( array( 'default', 'tasks' ) as $source ) {
			if ( 'tasks' === $source ) {
				$this->insert_scheduled_task( (string) $this->site_id, array( $id ) );
			}
			$out = $this->render_surface();
			$this->assertStringNotContainsString( $name, $out );
			$this->assertStringContainsString( 'Backup destinations: Dashboard + &lt;img src=x onerror=alert(1)&gt; &amp; archive.', $out );
		}
	}
}
