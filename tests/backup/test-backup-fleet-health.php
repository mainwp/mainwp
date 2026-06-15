<?php
/**
 * MainWP Fleet Backup-Health Tests
 *
 * The fleet backup-health view buckets each site by the freshness and
 * offsite-verification state of its newest complete snapshot. These tests
 * cover the bulk latest-per-site query, the pure classifier that maps a
 * snapshot to a health bucket, and the access gate on the request-scoped
 * health map.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Fleet_Health_Test
 */
class MainWP_Backup_Fleet_Health_Test extends \WP_UnitTestCase {

	/**
	 * Clean up snapshot rows and the request-scoped map cache between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		MainWP_Backup_Snapshots::flush_health_map_cache();
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		parent::tear_down();
	}

	/**
	 * Record a complete snapshot row, overriding defaults as needed.
	 *
	 * @param array $overrides Field overrides.
	 *
	 * @return int Snapshot id.
	 */
	private function record( $overrides = array() ) {
		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array_merge(
				array(
					'task_id'       => 11,
					'site_id'       => 22,
					'type'          => 'full',
					'parent_id'     => null,
					'archives'      => array(),
					'manifest_path' => '/tmp/snapshots/files.list',
				),
				$overrides
			)
		);
	}

	/**
	 * Build a bare snapshot-like object for the pure classifier.
	 *
	 * @param int    $created       Epoch the snapshot was created.
	 * @param string $offsite_state Offsite verification state.
	 *
	 * @return object
	 */
	private function snap( $created, $offsite_state ) {
		return (object) array(
			'created'       => $created,
			'offsite_state' => $offsite_state,
		);
	}

	// ---- get_latest_per_site() ----

	/**
	 * Empty input returns an empty array and runs no query.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_empty_input_returns_empty_array() {
		$this->assertSame( array(), MainWP_Backup_Snapshots::instance()->get_latest_per_site( array() ) );
	}

	/**
	 * A single site's latest row comes back keyed by its site id.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_keyed_by_site_id() {
		$id  = $this->record( array( 'site_id' => 22 ) );
		$map = MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( 22 ) );

		$this->assertArrayHasKey( 22, $map );
		$this->assertSame( $id, (int) $map[22]->id );
		$this->assertSame( 22, (int) $map[22]->site_id );
	}

	/**
	 * The newest complete row (highest id) wins, not the oldest.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_returns_newest_not_oldest() {
		$this->record( array( 'site_id' => 22 ) );
		$newer = $this->record( array( 'site_id' => 22, 'type' => 'incremental' ) );

		$map = MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( 22 ) );

		$this->assertSame( $newer, (int) $map[22]->id );
	}

	/**
	 * Pruned rows never win - the latest complete row is returned instead.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_excludes_pruned() {
		$base   = $this->record( array( 'site_id' => 22 ) );
		$pruned = $this->record( array( 'site_id' => 22, 'type' => 'incremental' ) );
		MainWP_Backup_Snapshots::instance()->set_status( $pruned, 'pruned' );

		$map = MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( 22 ) );

		$this->assertSame( $base, (int) $map[22]->id );
	}

	/**
	 * A site whose only row is non-complete is absent from the result.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_excludes_site_with_only_non_complete() {
		$pruned = $this->record( array( 'site_id' => 22 ) );
		MainWP_Backup_Snapshots::instance()->set_status( $pruned, 'pruned' );

		$map = MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( 22 ) );

		$this->assertArrayNotHasKey( 22, $map );
	}

	/**
	 * Multiple sites resolve in a single call, each to its own latest row.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_multiple_sites() {
		$a = $this->record( array( 'site_id' => 22 ) );
		$b = $this->record( array( 'site_id' => 33 ) );
		$c = $this->record( array( 'site_id' => 44 ) );

		$map = MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( 22, 33, 44 ) );

		$this->assertSame( $a, (int) $map[22]->id );
		$this->assertSame( $b, (int) $map[33]->id );
		$this->assertSame( $c, (int) $map[44]->id );
	}

	/**
	 * Sites outside the requested id set are excluded.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_scope_excludes_other_sites() {
		$this->record( array( 'site_id' => 22 ) );
		$this->record( array( 'site_id' => 33 ) );

		$map = MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( 22 ) );

		$this->assertArrayHasKey( 22, $map );
		$this->assertArrayNotHasKey( 33, $map );
	}

	/**
	 * Returned rows carry the fields the classifier needs.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_rows_carry_offsite_state_and_created() {
		$id = $this->record( array( 'site_id' => 22 ) );
		MainWP_Backup_Snapshots::instance()->set_offsite_state( $id, 'verified', time() );

		$map = MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( 22 ) );

		$this->assertSame( 'verified', $map[22]->offsite_state );
		$this->assertGreaterThan( 0, (int) $map[22]->created );
	}

	/**
	 * Non-positive and non-numeric ids are sanitized away without error.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_sanitizes_invalid_ids() {
		$id  = $this->record( array( 'site_id' => 22 ) );
		$map = MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( -1, 0, 'abc', 22 ) );

		$this->assertArrayHasKey( 22, $map );
		$this->assertSame( $id, (int) $map[22]->id );
		$this->assertCount( 1, $map );
	}

	/**
	 * All-invalid input collapses to an empty array.
	 *
	 * @return void
	 */
	public function test_get_latest_per_site_all_invalid_returns_empty() {
		$this->assertSame( array(), MainWP_Backup_Snapshots::instance()->get_latest_per_site( array( -1, 0, 'x' ) ) );
	}

	// ---- classify_backup_health() ----

	/**
	 * No snapshot means the unknown bucket.
	 *
	 * @return void
	 */
	public function test_classify_null_is_unknown() {
		$this->assertSame(
			MainWP_Backup_Snapshots::HEALTH_BUCKET_UNKNOWN,
			MainWP_Backup_Snapshots::classify_backup_health( null, time() )
		);
	}

	/**
	 * A fresh verified snapshot is protected.
	 *
	 * @return void
	 */
	public function test_classify_recent_verified_is_protected() {
		$now = 1000000000;
		$this->assertSame(
			MainWP_Backup_Snapshots::HEALTH_BUCKET_PROTECTED,
			MainWP_Backup_Snapshots::classify_backup_health( $this->snap( $now - HOUR_IN_SECONDS, 'verified' ), $now )
		);
	}

	/**
	 * Exactly 24h old and verified is still protected (inclusive boundary).
	 *
	 * @return void
	 */
	public function test_classify_exactly_24h_verified_is_protected() {
		$now = 1000000000;
		$this->assertSame(
			MainWP_Backup_Snapshots::HEALTH_BUCKET_PROTECTED,
			MainWP_Backup_Snapshots::classify_backup_health( $this->snap( $now - DAY_IN_SECONDS, 'verified' ), $now )
		);
	}

	/**
	 * Just past 24h drops to at-risk regardless of offsite state.
	 *
	 * @return void
	 */
	public function test_classify_25h_verified_is_at_risk() {
		$now = 1000000000;
		$this->assertSame(
			MainWP_Backup_Snapshots::HEALTH_BUCKET_AT_RISK,
			MainWP_Backup_Snapshots::classify_backup_health( $this->snap( $now - 25 * HOUR_IN_SECONDS, 'verified' ), $now )
		);
	}

	/**
	 * A recent but unverified snapshot is at-risk for every non-verified state.
	 *
	 * @return void
	 */
	public function test_classify_recent_unverified_is_at_risk() {
		$now = 1000000000;
		foreach ( array( '', 'pending', 'partial', 'failed' ) as $state ) {
			$this->assertSame(
				MainWP_Backup_Snapshots::HEALTH_BUCKET_AT_RISK,
				MainWP_Backup_Snapshots::classify_backup_health( $this->snap( $now - HOUR_IN_SECONDS, $state ), $now ),
				"state '{$state}' should be at-risk when recent but not verified"
			);
		}
	}

	/**
	 * Three days old is at-risk.
	 *
	 * @return void
	 */
	public function test_classify_three_days_is_at_risk() {
		$now = 1000000000;
		$this->assertSame(
			MainWP_Backup_Snapshots::HEALTH_BUCKET_AT_RISK,
			MainWP_Backup_Snapshots::classify_backup_health( $this->snap( $now - 3 * DAY_IN_SECONDS, 'verified' ), $now )
		);
	}

	/**
	 * Exactly 7 days old is at-risk, not yet critical (inclusive boundary).
	 *
	 * @return void
	 */
	public function test_classify_exactly_7d_is_at_risk() {
		$now = 1000000000;
		$this->assertSame(
			MainWP_Backup_Snapshots::HEALTH_BUCKET_AT_RISK,
			MainWP_Backup_Snapshots::classify_backup_health( $this->snap( $now - 7 * DAY_IN_SECONDS, 'verified' ), $now )
		);
	}

	/**
	 * Past 7 days is critical.
	 *
	 * @return void
	 */
	public function test_classify_eight_days_is_critical() {
		$now = 1000000000;
		$this->assertSame(
			MainWP_Backup_Snapshots::HEALTH_BUCKET_CRITICAL,
			MainWP_Backup_Snapshots::classify_backup_health( $this->snap( $now - 8 * DAY_IN_SECONDS, 'verified' ), $now )
		);
	}

	// ---- get_health_map_for_current_user() access gate ----

	/**
	 * With native backups disabled the map is empty - the gate short-circuits
	 * before any query or site lookup.
	 *
	 * @return void
	 */
	public function test_health_map_empty_when_native_disabled() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );

		$this->assertSame(
			array(),
			MainWP_Backup_Snapshots::instance()->get_health_map_for_current_user()
		);
	}
}
