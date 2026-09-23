<?php
/**
 * MainWP Backup Snapshots Tests
 *
 * Snapshot rows are the durable per-run backup records: which run is the
 * base of an incremental chain, where the downloaded manifest lives, and
 * how runs relate through parent ids. These tests cover recording rows,
 * resolving the latest complete snapshot for a site and task, and walking
 * a chain back to its base.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Snapshots_Test
 */
class MainWP_Backup_Snapshots_Test extends \WP_UnitTestCase {

	/**
	 * Clean up snapshot rows between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		// Encryption state leaks across tests through the KEK key file and
		// options; reset it so a record_snapshot in one test is not armed by
		// a passphrase set in another.
		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );

		parent::tear_down();
	}

	/**
	 * Arm encryption: turn the toggle on and set a recovery passphrase, which
	 * creates the dashboard KEK.
	 *
	 * @return MainWP_Backup_Encryption
	 */
	private function arm_encryption() {
		$enc = MainWP_Backup_Encryption::instance();
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '1' );
		$enc->set_recovery_passphrase( 'snapshot encryption passphrase' );
		return $enc;
	}

	/**
	 * Record a snapshot row, overriding defaults as needed.
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
					'archives'      => array(
						array(
							'file' => 'backup.tar',
							'size' => 1024,
							'role' => 'part',
						),
					),
					'manifest_path' => '/tmp/snapshots/files.list',
				),
				$overrides
			)
		);
	}

	/**
	 * Recording a snapshot returns its id and the row round-trips with the
	 * archives list decoded.
	 *
	 * @return void
	 */
	public function test_record_snapshot_round_trips() {
		$id = $this->record();

		$this->assertGreaterThan( 0, $id );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 11, (int) $snapshot->task_id );
		$this->assertSame( 22, (int) $snapshot->site_id );
		$this->assertSame( 'full', $snapshot->type );
		$this->assertSame( 'complete', $snapshot->status );
		$this->assertNull( $snapshot->parent_id );
		$this->assertSame( '/tmp/snapshots/files.list', $snapshot->manifest_path );
		$this->assertGreaterThan( 0, (int) $snapshot->created );

		$this->assertIsArray( $snapshot->archives );
		$this->assertCount( 1, $snapshot->archives );
		$this->assertSame( 'backup.tar', $snapshot->archives[0]['file'] );
		$this->assertSame( 1024, $snapshot->archives[0]['size'] );
		$this->assertSame( 'part', $snapshot->archives[0]['role'] );
	}

	/**
	 * A duplicate run adopts the winner without repeating upload hooks.
	 */
	public function test_b11_run_key_records_one_row_and_fires_hook_once() {
		$before = did_action( 'mainwp_backup_snapshot_recorded' );
		$first  = $this->record( array( 'run_key' => '22:11:c:child-one' ) );
		$second = $this->record(
			array(
				'run_key'       => '22:11:c:child-one',
				'manifest_path' => '/loser/files.list',
			)
		);

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( 22 ) );
		$this->assertSame( '/tmp/snapshots/files.list', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $first )->manifest_path );
		$this->assertSame( $before + 1, did_action( 'mainwp_backup_snapshot_recorded' ) );
	}

	/**
	 * Legacy callers without identity retain independent inserts.
	 */
	public function test_b11_null_run_key_inserts_twice() {
		$before = did_action( 'mainwp_backup_snapshot_recorded' );
		$first  = $this->record( array( 'run_key' => null ) );
		$second = $this->record( array( 'run_key' => null ) );

		$this->assertGreaterThan( 0, $first );
		$this->assertGreaterThan( 0, $second );
		$this->assertNotSame( $first, $second );
		$this->assertCount( 2, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( 22 ) );
		$this->assertNull( MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $first )->run_key );
		$this->assertSame( $before + 2, did_action( 'mainwp_backup_snapshot_recorded' ) );
	}

	/**
	 * Unknown ids resolve to null.
	 *
	 * @return void
	 */
	public function test_get_snapshot_by_id_returns_null_for_unknown_id() {
		$this->assertNull( MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( 987654 ) );
	}

	/**
	 * The Woo order-storage facts a database-bearing backup captured at
	 * dump time persist on the snapshot row and round-trip decoded, so the
	 * restore initiate handler can judge a preserve-orders request without
	 * touching the archive bytes.
	 *
	 * @return void
	 */
	public function test_record_snapshot_persists_woo_meta() {
		$snapshots = MainWP_Backup_Snapshots::instance();

		$id = $snapshots->record_snapshot(
			array(
				'task_id'       => 11,
				'site_id'       => 22,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => array(),
				'manifest_path' => '',
				'woo_meta'      => array(
					'has_wc_orders'          => true,
					'woocommerce_db_version' => '9.4.0',
				),
			)
		);
		$this->assertGreaterThan( 0, $id );

		$snapshot = $snapshots->get_snapshot_by_id( $id );
		$this->assertIsArray( $snapshot->woo_meta );
		$this->assertTrue( $snapshot->woo_meta['has_wc_orders'] );
		$this->assertSame( '9.4.0', $snapshot->woo_meta['woocommerce_db_version'] );
	}

	/**
	 * A snapshot recorded without Woo facts (files-only runs, older
	 * children) decodes to an empty array, never null or a string.
	 *
	 * @return void
	 */
	public function test_snapshot_without_woo_meta_decodes_to_empty_array() {
		$id       = $this->record();
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );

		$this->assertIsArray( $snapshot->woo_meta );
		$this->assertSame( array(), $snapshot->woo_meta );
	}

	/**
	 * The latest complete snapshot for a site and task is the newest row,
	 * scoped to that site and task only.
	 *
	 * @return void
	 */
	public function test_get_latest_complete_returns_newest_for_site_and_task() {
		$older = $this->record();
		$newer = $this->record(
			array(
				'type'      => 'incremental',
				'parent_id' => $older,
			)
		);
		$this->record( array( 'site_id' => 33 ) ); // Another site, must not match.
		$this->record( array( 'task_id' => 44 ) ); // Another task, must not match.

		$latest = MainWP_Backup_Snapshots::instance()->get_latest_complete( 22, 11 );

		$this->assertNotNull( $latest );
		$this->assertSame( $newer, (int) $latest->id );
		$this->assertSame( 'incremental', $latest->type );
		$this->assertSame( $older, (int) $latest->parent_id );
	}

	/**
	 * A site and task with no snapshot rows yields null - the caller runs a
	 * full base backup in that case.
	 *
	 * @return void
	 */
	public function test_get_latest_complete_returns_null_when_no_rows() {
		$this->assertNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( 22, 11 ) );
	}

	/**
	 * Non-complete rows never become the chain base.
	 *
	 * @return void
	 */
	public function test_get_latest_complete_ignores_pruned_rows() {
		$base   = $this->record();
		$pruned = $this->record(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		MainWP_Backup_Snapshots::instance()->set_status( $pruned, 'pruned' );

		$latest = MainWP_Backup_Snapshots::instance()->get_latest_complete( 22, 11 );

		$this->assertNotNull( $latest );
		$this->assertSame( $base, (int) $latest->id );
	}

	/**
	 * Walking the chain from a leaf snapshot follows parent ids back to the
	 * base, base first so a restore can apply rows in order.
	 *
	 * @return void
	 */
	public function test_get_chain_returns_base_first() {
		$base   = $this->record();
		$first  = $this->record(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		$second = $this->record(
			array(
				'type'      => 'incremental',
				'parent_id' => $first,
			)
		);

		$chain = MainWP_Backup_Snapshots::instance()->get_chain( $second );

		$this->assertCount( 3, $chain );
		$this->assertSame( $base, (int) $chain[0]->id );
		$this->assertSame( $first, (int) $chain[1]->id );
		$this->assertSame( $second, (int) $chain[2]->id );
	}

	/**
	 * A chain whose parent link points at a missing row stops at the break
	 * rather than failing - the caller treats a broken chain as no base.
	 *
	 * @return void
	 */
	public function test_get_chain_stops_at_missing_parent() {
		$leaf = $this->record(
			array(
				'type'      => 'incremental',
				'parent_id' => 987654,
			)
		);

		$chain = MainWP_Backup_Snapshots::instance()->get_chain( $leaf );

		$this->assertCount( 1, $chain );
		$this->assertSame( $leaf, (int) $chain[0]->id );
	}

	/**
	 * When encryption is armed, recording a snapshot writes per-snapshot key
	 * material, and the wrapped DEK unwraps under the KEK to a usable 32-byte
	 * key bound to that snapshot.
	 *
	 * @return void
	 */
	public function test_record_snapshot_attaches_encryption_when_armed() {
		$enc = $this->arm_encryption();
		$id  = $this->record();
		$this->assertGreaterThan( 0, $id );

		// The raw column holds metadata JSON, not the empty default.
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT encryption FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifiers cannot be prepared.
		$this->assertNotEmpty( $raw );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertTrue( MainWP_Backup_Encryption::meta_is_encrypted( $snapshot->encryption ) );
		$this->assertSame( 1, $snapshot->encryption['enc'] );
		$this->assertSame( 'aes-256-gcm', $snapshot->encryption['cipher'] );
		$this->assertSame( 28, $snapshot->encryption['overhead'] );

		$kek = $enc->load_kek();
		$dek = $enc->dek_from_meta( $snapshot->encryption, $id, $kek );
		$this->assertSame( 32, strlen( $dek ) );
	}

	/**
	 * Without encryption armed, a recorded snapshot stays plaintext: its
	 * encryption metadata decodes to an empty array (the mixed-mode legacy
	 * signal), and meta_is_encrypted is false.
	 *
	 * @return void
	 */
	public function test_record_snapshot_leaves_plaintext_when_not_armed() {
		$id       = $this->record();
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );

		$this->assertSame( array(), $snapshot->encryption );
		$this->assertFalse( MainWP_Backup_Encryption::meta_is_encrypted( $snapshot->encryption ) );
	}

	/**
	 * A snapshot's wrapped DEK is bound to its own id: unwrapping it against a
	 * different snapshot id is rejected, so a catalog-row swap cannot move key
	 * material between snapshots.
	 *
	 * @return void
	 */
	public function test_wrapped_dek_is_bound_to_its_snapshot() {
		$enc      = $this->arm_encryption();
		$id       = $this->record();
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$kek      = $enc->load_kek();

		$this->expectException( \RuntimeException::class );
		$enc->dek_from_meta( $snapshot->encryption, $id + 1, $kek );
	}
}
