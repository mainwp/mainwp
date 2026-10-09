<?php
/**
 * MainWP Updates Native Backup Indicator Tests
 *
 * Covers MainWP_Updates::get_native_backup_indicator(), the per-site badge
 * shown on the Updates screen so an operator can see which sites have a
 * recent restorable native backup before running updates.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Updates;
use MainWP\Dashboard\MainWP_Updates_Overview;

/**
 * Class MainWP_Updates_Native_Backup_Indicator_Test
 */
class MainWP_Updates_Native_Backup_Indicator_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Set up: native backups on by default for these cases.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean up both backup-enable options so disabled cases are honest.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		delete_option( 'mainwp_backup_before_upgrade_days' );
		parent::tearDown();
	}

	/**
	 * A site with a fresh complete snapshot gets the green "ready" badge.
	 *
	 * @return void
	 */
	public function test_indicator_is_green_for_fresh_snapshot() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id );

		$html = MainWP_Updates::get_native_backup_indicator( (object) array( 'id' => $site_id ) );

		$this->assertStringContainsString( 'label', $html, 'A recent backup renders a label.' );
		$this->assertStringContainsString( 'green', $html, 'A recent backup renders the green state.' );
	}

	/**
	 * A site with no snapshot gets the grey "none" badge, not the green one.
	 *
	 * @return void
	 */
	public function test_indicator_is_grey_when_no_snapshot() {
		$site_id = $this->create_test_site();

		$html = MainWP_Updates::get_native_backup_indicator( (object) array( 'id' => $site_id ) );

		$this->assertStringContainsString( 'grey', $html, 'No backup renders the grey state.' );
		$this->assertStringNotContainsString( 'green label', $html, 'No backup is not the green state.' );
	}

	/**
	 * A snapshot older than the freshness window counts as none (grey).
	 *
	 * @return void
	 */
	public function test_indicator_is_grey_for_stale_snapshot() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id, array( 'created' => time() - 10 * DAY_IN_SECONDS ) );

		$html = MainWP_Updates::get_native_backup_indicator( (object) array( 'id' => $site_id ), 7 );

		$this->assertStringContainsString( 'grey', $html, 'A stale snapshot is not a recent backup.' );
	}

	/**
	 * A ready badge must agree with the configured pre-update backup window.
	 *
	 * @return void
	 */
	public function test_indicator_uses_backup_before_upgrade_days_setting() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id, array( 'created' => time() - 5 * DAY_IN_SECONDS ) );
		$website = (object) array( 'id' => $site_id );

		update_option( 'mainwp_backup_before_upgrade_days', '3' );
		$html = MainWP_Updates::get_native_backup_indicator( $website );
		$this->assertStringContainsString( 'grey label', $html, 'A five-day-old backup is stale under the three-day gate window.' );
		$this->assertStringNotContainsString( 'Backup ready', $html );

		update_option( 'mainwp_backup_before_upgrade_days', '7' );
		$html = MainWP_Updates::get_native_backup_indicator( $website );
		$this->assertStringContainsString( 'green label', $html, 'The same backup is recent under the seven-day gate window.' );
		$this->assertStringContainsString( 'Backup ready', $html );
	}

	/**
	 * Integer options retain the configured window for the gate and badge.
	 *
	 * @return void
	 */
	public function test_indicator_uses_integer_backup_before_upgrade_days_setting() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id, array( 'created' => time() - 5 * DAY_IN_SECONDS ) );
		$website = (object) array( 'id' => $site_id );

		update_option( 'mainwp_backup_before_upgrade_days', 3 );
		$this->assertSame( 3, MainWP_Updates_Overview::get_backup_before_upgrade_days(), 'An integer option keeps the three-day window.' );

		$html = MainWP_Updates::get_native_backup_indicator( $website );
		$this->assertStringContainsString( 'grey label', $html, 'A five-day-old backup is stale under the three-day gate window.' );
		$this->assertStringNotContainsString( 'Backup ready', $html );
	}

	/**
	 * The explicit max-age argument overrides the default window: a 10-day-old
	 * snapshot is recent under a 30-day window.
	 *
	 * @return void
	 */
	public function test_indicator_respects_max_age_argument() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id, array( 'created' => time() - 10 * DAY_IN_SECONDS ) );

		$html = MainWP_Updates::get_native_backup_indicator( (object) array( 'id' => $site_id ), 30 );

		$this->assertStringContainsString( 'green', $html, 'A 10-day-old snapshot is recent under a 30-day window.' );
	}

	/**
	 * With native backups disabled the indicator renders nothing at all.
	 *
	 * @return void
	 */
	public function test_indicator_is_empty_when_native_backups_disabled() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id );

		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );

		$html = MainWP_Updates::get_native_backup_indicator( (object) array( 'id' => $site_id ) );

		$this->assertSame( '', $html, 'No indicator is rendered when native backups are off.' );
	}

	/**
	 * Record a native snapshot row with controllable created/status.
	 *
	 * record_snapshot() always stamps a fresh complete row, so age and status
	 * overrides are applied with a direct update.
	 *
	 * @param int   $site_id Child site id.
	 * @param array $args    Overrides: type, created, status.
	 *
	 * @return int Snapshot id.
	 */
	private function record_native_snapshot( $site_id, $args = array() ) {
		$id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 1,
				'site_id'       => (int) $site_id,
				'type'          => isset( $args['type'] ) ? (string) $args['type'] : 'full',
				'archives'      => array(),
				'manifest_path' => '',
			)
		);

		$update = array();
		if ( isset( $args['created'] ) ) {
			$update['created'] = (int) $args['created'];
		}
		if ( isset( $args['status'] ) ) {
			$update['status'] = (string) $args['status'];
		}
		if ( ! empty( $update ) ) {
			global $wpdb;
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), $update, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB -- test fixture adjust.
		}

		return (int) $id;
	}
}
