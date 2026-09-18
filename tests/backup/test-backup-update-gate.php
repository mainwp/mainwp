<?php
/**
 * MainWP Backup Update Gate Tests
 *
 * Covers MainWP_Backup_Executor::evaluate_update_backup_gate(), the
 * pre-update backup-freshness gate that combines native-snapshot and
 * legacy-archive evidence under the require-offsite policy, plus its two
 * consumers: the updates-overview "needs backup" widget and the Updates
 * screen per-site badge.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Updates;
use MainWP\Dashboard\MainWP_Updates_Overview;

/**
 * Class MainWP_Backup_Update_Gate_Test
 */
class MainWP_Backup_Update_Gate_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Bulk rendering must match the gate's newest-snapshot policy with one query.
	 */
	public function test_recent_backup_map_matches_single_site_and_reuses_query() {
		$recent = $this->create_test_site();
		$stale  = $this->create_test_site();
		$none   = $this->create_test_site();
		$shadow = $this->create_test_site();
		$this->record_native_snapshot( $recent, array( 'offsite_state' => 'verified' ) );
		$this->record_native_snapshot( $recent, array( 'type' => 'files' ) );
		$this->record_native_snapshot( $stale, array( 'created' => time() - 8 * DAY_IN_SECONDS ) );
		$this->record_native_snapshot( $shadow, array( 'offsite_state' => 'verified' ) );
		$this->record_native_snapshot( $shadow );
		$ids     = array( $recent, $stale, $none, $shadow );
		$queries = 0;
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$count   = static function ( $sql ) use ( &$queries, $table ) {
			if ( str_contains( $sql, $table ) ) {
				++$queries;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		try {
			foreach ( array( false, true ) as $offsite ) {
				$queries = 0;
				$map     = MainWP_Backup_Executor::get_recent_native_backup_map( $ids, 7, $offsite );
				$this->assertSame( 1, $queries );
				$this->assertSame(
					array(
						$recent => true,
						$stale  => false,
						$none   => false,
						$shadow => ! $offsite,
					),
					$map
				);
				$queries = 0;
				$this->assertSame( $map, MainWP_Backup_Executor::get_recent_native_backup_map( $ids, 7, $offsite ) );
				$this->assertSame( 0, $queries );
				foreach ( $ids as $id ) {
					$this->assertSame( MainWP_Backup_Executor::has_recent_native_backup( $id, 7, $offsite ), $map[ $id ] );
				}
			}
		} finally {
			remove_filter( 'query', $count );
		}
	}

	/**
	 * Set up: enable native backups so the evaluator's native-snapshot path
	 * and the badge indicator are both live for these cases.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$cache = new \ReflectionProperty( MainWP_Backup_Executor::class, 'recent_native_backup_maps' );
		$cache->setValue( null, array() );
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean up every option a test in this file may have set.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_POST['sites'] );
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_backup_before_upgrade_require_offsite' );
		delete_option( 'mainwp_backup_before_upgrade' );
		delete_option( 'mainwp_backup_before_upgrade_days' );
		parent::tear_down();
	}

	/**
	 * Non-strict: a fresh unverified native snapshot alone passes the gate.
	 *
	 * @return void
	 */
	public function test_evaluate_non_strict_fresh_unverified_native_passes() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id );

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_PASS,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, false ),
			'A fresh unverified native snapshot satisfies the non-strict gate.'
		);
	}

	/**
	 * Non-strict: a fresh legacy archive alone passes the gate.
	 *
	 * @return void
	 */
	public function test_evaluate_non_strict_fresh_legacy_archive_passes() {
		$site_id = $this->create_test_site();
		$archive = $this->make_fresh_legacy_archive( $site_id );

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_PASS,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, false ),
			'A fresh legacy archive satisfies the non-strict gate.'
		);

		unlink( $archive );
	}

	/**
	 * Non-strict: no evidence, or only stale evidence, is missing.
	 *
	 * @return void
	 */
	public function test_evaluate_non_strict_no_evidence_is_missing() {
		$site_id = $this->create_test_site();

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_MISSING,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, false ),
			'A site with no backup evidence at all is missing.'
		);

		$this->record_native_snapshot( $site_id, array( 'created' => time() - 10 * DAY_IN_SECONDS ) );
		$archive = $this->make_fresh_legacy_archive( $site_id );
		touch( $archive, time() - 10 * DAY_IN_SECONDS );

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_MISSING,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, false ),
			'Only stale evidence is still missing.'
		);

		unlink( $archive );
	}

	/**
	 * Strict: a fresh offsite-verified native snapshot passes the gate.
	 *
	 * @return void
	 */
	public function test_evaluate_strict_fresh_verified_native_passes() {
		$site_id = $this->create_test_site();
		$id      = $this->record_native_snapshot( $site_id );
		MainWP_Backup_Snapshots::instance()->set_offsite_state( $id, 'verified', time() );

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_PASS,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, true ),
			'A fresh verified native snapshot satisfies the strict gate.'
		);
	}

	/**
	 * Strict: a fresh but unverified native snapshot is pending offsite, not
	 * a pass and not missing.
	 *
	 * @return void
	 */
	public function test_evaluate_strict_fresh_unverified_native_pending_offsite() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id );

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_PENDING_OFFSITE,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, true ),
			'A fresh unverified native snapshot is pending offsite under the strict gate.'
		);
	}

	/**
	 * Strict: a fresh legacy archive with no native snapshot is pending
	 * offsite, since a local-only archive can never be offsite-verified.
	 *
	 * @return void
	 */
	public function test_evaluate_strict_fresh_legacy_archive_only_pending_offsite() {
		$site_id = $this->create_test_site();
		$archive = $this->make_fresh_legacy_archive( $site_id );

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_PENDING_OFFSITE,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, true ),
			'A fresh legacy archive alone is pending offsite under the strict gate.'
		);

		unlink( $archive );
	}

	/**
	 * Strict: no fresh evidence at all is missing.
	 *
	 * @return void
	 */
	public function test_evaluate_strict_no_fresh_evidence_is_missing() {
		$site_id = $this->create_test_site();

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_MISSING,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, true ),
			'A site with no fresh evidence is missing under the strict gate.'
		);
	}

	/**
	 * Strict: has_recent_native_backup() judges only the newest complete
	 * db-bearing snapshot, so a newer unverified snapshot shadows an older
	 * verified one that is still within the freshness window.
	 *
	 * @return void
	 */
	public function test_evaluate_strict_newer_unverified_shadows_older_verified() {
		$site_id = $this->create_test_site();

		$older_id = $this->record_native_snapshot( $site_id, array( 'created' => time() - 3 * DAY_IN_SECONDS ) );
		MainWP_Backup_Snapshots::instance()->set_offsite_state( $older_id, 'verified', time() );

		// A default record_snapshot() call stamps the current time, so this
		// row is newer than $older_id and unverified.
		$this->record_native_snapshot( $site_id );

		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_PENDING_OFFSITE,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, true ),
			'The newer unverified snapshot shadows the older verified one, so the gate sees it as pending offsite.'
		);
	}

	/**
	 * A null $require_offsite resolves from the
	 * mainwp_backup_before_upgrade_require_offsite option.
	 *
	 * @return void
	 */
	public function test_evaluate_null_require_offsite_resolves_from_option() {
		$site_id = $this->create_test_site();
		$archive = $this->make_fresh_legacy_archive( $site_id );

		update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );
		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_PENDING_OFFSITE,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, null ),
			'With the option on, a null argument resolves strict and a legacy archive alone is pending offsite.'
		);

		delete_option( 'mainwp_backup_before_upgrade_require_offsite' );
		$this->assertSame(
			MainWP_Backup_Executor::UPDATE_GATE_PASS,
			MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, 7, null ),
			'With the option unset, a null argument resolves non-strict and the same archive passes.'
		);

		unlink( $archive );
	}

	/**
	 * Strict: MainWP_Updates_Overview::check_backups() reports false for a
	 * site whose only evidence is a fresh legacy archive.
	 *
	 * @return void
	 */
	public function test_check_backups_strict_legacy_archive_only_reports_false() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_eligible_site( $user );
		$archive = $this->make_fresh_legacy_archive( $site_id );

		update_option( 'mainwp_backup_before_upgrade', 1 );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );
		$_POST['sites'] = array( (string) $site_id );

		$output = MainWP_Updates_Overview::check_backups();

		$this->assertIsArray( $output );
		$this->assertArrayHasKey( $site_id, $output['sites'], 'The site must still get a freshness answer.' );
		$this->assertFalse( $output['sites'][ $site_id ], 'A legacy-archive-only site does not pass the strict gate.' );

		unlink( $archive );
	}

	/**
	 * Strict: check_backups() reports true for a site with a fresh
	 * offsite-verified native snapshot.
	 *
	 * @return void
	 */
	public function test_check_backups_strict_verified_native_reports_true() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_eligible_site( $user );
		$id      = $this->record_native_snapshot( $site_id );
		MainWP_Backup_Snapshots::instance()->set_offsite_state( $id, 'verified', time() );

		update_option( 'mainwp_backup_before_upgrade', 1 );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );
		$_POST['sites'] = array( (string) $site_id );

		$output = MainWP_Updates_Overview::check_backups();

		$this->assertIsArray( $output );
		$this->assertArrayHasKey( $site_id, $output['sites'], 'The site must still get a freshness answer.' );
		$this->assertTrue( $output['sites'][ $site_id ], 'A fresh offsite-verified native snapshot passes the strict gate.' );
	}

	/**
	 * Non-strict: check_backups() preserves today's behavior of passing a
	 * site whose only evidence is a fresh legacy archive.
	 *
	 * @return void
	 */
	public function test_check_backups_non_strict_legacy_archive_only_reports_true() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_eligible_site( $user );
		$archive = $this->make_fresh_legacy_archive( $site_id );

		update_option( 'mainwp_backup_before_upgrade', 1 );
		$_POST['sites'] = array( (string) $site_id );

		$output = MainWP_Updates_Overview::check_backups();

		$this->assertIsArray( $output );
		$this->assertArrayHasKey( $site_id, $output['sites'], 'The site must still get a freshness answer.' );
		$this->assertTrue( $output['sites'][ $site_id ], 'A fresh legacy archive alone passes the non-strict gate.' );

		unlink( $archive );
	}

	/**
	 * Strict: the Updates-screen badge shows "No recent backup" for an
	 * unverified fresh snapshot.
	 *
	 * @return void
	 */
	public function test_indicator_strict_unverified_snapshot_shows_no_recent_backup() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );

		$html = MainWP_Updates::get_native_backup_indicator( (object) array( 'id' => $site_id ) );

		$this->assertStringContainsString( 'No recent backup', $html, 'An unverified snapshot does not satisfy the strict badge.' );
	}

	/**
	 * Strict: the badge shows "Backup ready" once the same snapshot is
	 * offsite-verified.
	 *
	 * @return void
	 */
	public function test_indicator_strict_verified_snapshot_shows_backup_ready() {
		$site_id = $this->create_test_site();
		$id      = $this->record_native_snapshot( $site_id );
		MainWP_Backup_Snapshots::instance()->set_offsite_state( $id, 'verified', time() );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );

		$html = MainWP_Updates::get_native_backup_indicator( (object) array( 'id' => $site_id ) );

		$this->assertStringContainsString( 'Backup ready', $html, 'A verified snapshot satisfies the strict badge.' );
	}

	/**
	 * Non-strict: the badge preserves today's behavior of showing
	 * "Backup ready" for an unverified fresh snapshot.
	 *
	 * @return void
	 */
	public function test_indicator_non_strict_unverified_snapshot_shows_backup_ready() {
		$site_id = $this->create_test_site();
		$this->record_native_snapshot( $site_id );

		$html = MainWP_Updates::get_native_backup_indicator( (object) array( 'id' => $site_id ) );

		$this->assertStringContainsString( 'Backup ready', $html, 'An unverified snapshot still satisfies the non-strict badge.' );
	}

	/**
	 * Create a site whose backup_before_upgrade flag makes it eligible for a
	 * freshness answer from check_backups().
	 *
	 * @param int $user_id Owner user id.
	 *
	 * @return int Site id.
	 */
	private function create_eligible_site( $user_id ) {
		global $wpdb;

		$site_id = $this->create_test_site( array( 'userid' => $user_id ) );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'backup_before_upgrade' => 1 ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.

		return $site_id;
	}

	/**
	 * Create a fresh full legacy archive on disk for a site.
	 *
	 * @param int $site_id Child site id.
	 *
	 * @return string Path to the created archive, for the caller to unlink.
	 */
	private function make_fresh_legacy_archive( $site_id ) {
		$dir = MainWP_System_Utility::get_mainwp_specific_dir( $site_id );
		wp_mkdir_p( $dir );

		$archive = $dir . 'full-example-backup.tar.gz';
		file_put_contents( $archive, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		return $archive;
	}

	/**
	 * Record a native snapshot row with controllable created/status/state.
	 *
	 * record_snapshot() always stamps a fresh complete row, so age, status
	 * and offsite-state overrides are applied with a direct update.
	 *
	 * @param int   $site_id Child site id.
	 * @param array $args    Overrides: type, created, status, offsite_state.
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
		if ( isset( $args['offsite_state'] ) ) {
			$update['offsite_state'] = (string) $args['offsite_state'];
		}
		if ( ! empty( $update ) ) {
			global $wpdb;
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), $update, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB -- test fixture adjust.
		}

		return (int) $id;
	}
}
