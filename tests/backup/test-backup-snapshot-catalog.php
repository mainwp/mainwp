<?php
/**
 * Snapshot catalog tests: availability classification, chain
 * resolution, and restore plan assembly.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Snapshot_Catalog_Test
 */
class MainWP_Backup_Snapshot_Catalog_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Fixture dir holding artifact files.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Snapshot ids created, cleaned up in tear_down.
	 *
	 * @var int[]
	 */
	private $snapshot_ids = array();

	/**
	 * Build the fixture dir.
	 */
	public function set_up() {
		parent::set_up();
		$this->create_test_site( array( 'id' => 9901 ) );
		$this->create_test_site( array( 'id' => 9902 ) );
		$this->dir = sys_get_temp_dir() . '/mainwp-catalog-test-' . uniqid();
		mkdir( $this->dir );
	}

	/**
	 * Remove fixtures and snapshot rows.
	 */
	public function tear_down() {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		global $wpdb;
		if ( ! empty( $this->snapshot_ids ) ) {
			$ids = implode( ',', array_map( 'intval', $this->snapshot_ids ) );
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . " WHERE id IN ( {$ids} )" ); // phpcs:ignore WordPress.DB -- test cleanup.
		}
		parent::tear_down();
	}

	/**
	 * Insert a snapshot row with on-disk artifacts.
	 *
	 * @param array $archives  Archive entries (file/role; path filled).
	 * @param array $overrides Row overrides.
	 *
	 * @return int Snapshot id.
	 */
	private function make_snapshot( $archives, $overrides = array() ) {
		global $wpdb;

		foreach ( $archives as &$archive ) {
			if ( ! isset( $archive['path'] ) ) {
				$archive['path'] = $this->dir . '/' . $archive['file'];
				if ( empty( $archive['missing'] ) ) {
					file_put_contents( $archive['path'], 'artifact-bytes-' . $archive['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
				}
				$archive['size'] = 32;
			}
			unset( $archive['missing'] );
		}
		unset( $archive );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array_merge(
				array(
					'task_id'       => 991,
					'site_id'       => 9901,
					'type'          => 'full',
					'parent_id'     => null,
					'created'       => time(),
					'archives'      => wp_json_encode( $archives ),
					'manifest_path' => '',
					'status'        => 'complete',
				),
				$overrides
			)
		);
		$id                   = (int) $wpdb->insert_id;
		$this->snapshot_ids[] = $id;
		return $id;
	}

	/**
	 * A complete snapshot with on-disk artifacts lists as local and
	 * restorable.
	 */
	public function test_list_snapshots_local_available() {
		$this->make_snapshot( array( array( 'file' => 'full.tar.gz', 'role' => 'full' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( 9901 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'local', $entries[0]['source'] );
		$this->assertTrue( $entries[0]['restorable'] );
	}

	/**
	 * The listing leaves create-idempotency checks to restore initiation.
	 */
	public function test_listing_ignores_restore_create_idempotency_capability() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'restore_create_idempotency_support', '0' );

		$snapshot_id = $this->make_snapshot(
			array( array( 'file' => 'full.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'site_id' => $site_id )
		);

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
		$this->assertTrue( $entries[0]['restorable'], 'The listing must allow restore initiation to refresh a stale capability.' );
		$this->assertSame( '', $entries[0]['restore_reason'] );

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id );
		$this->assertTrue( $resolved['ok'] );
		$this->assertNull( $resolved['error'] );

		MainWP_DB::instance()->update_website_option( $website, 'restore_create_idempotency_support', '1' );

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
		$this->assertTrue( $entries[0]['restorable'] );
		$this->assertSame( '', $entries[0]['restore_reason'] );
		$this->assertTrue( MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id )['ok'] );

		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'mainwp_wp_options',
			array(
				'wpid' => $site_id,
				'name' => 'restore_create_idempotency_support',
			)
		);
		$this->assertNull( MainWP_DB::instance()->get_website_option( $site_id, 'restore_create_idempotency_support' ) );

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
		$this->assertTrue( $entries[0]['restorable'] );
		$this->assertSame( '', $entries[0]['restore_reason'] );
	}

	/**
	 * A multisite child without network-restore support lists its
	 * snapshots as non-restorable with a reason, and resolution refuses;
	 * a child that advertises support restores normally.
	 */
	public function test_multisite_gate_follows_child_capability() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'child_is_multisite', '1' );

		$snapshot_id = $this->make_snapshot(
			array( array( 'file' => 'full.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'site_id' => $site_id )
		);

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
		$this->assertFalse( $entries[0]['restorable'], 'A multisite child without the capability must not be restorable.' );
		$this->assertStringContainsString( 'Update the MainWP Child plugin', $entries[0]['restore_reason'] );

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id );
		$this->assertFalse( $resolved['ok'] );
		$this->assertStringContainsString( 'Multisite', $resolved['error'] );

		MainWP_DB::instance()->update_website_option( $website, 'restore_multisite_support', '1' );

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
		$this->assertTrue( $entries[0]['restorable'] );
		$this->assertSame( '', $entries[0]['restore_reason'] );
		$this->assertTrue( MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id )['ok'] );
	}

	/**
	 * Single-site children are unaffected by the multisite gate, and the
	 * existing non-restorable cases carry reasons too.
	 */
	public function test_restore_reasons_for_existing_cases() {
		$this->make_snapshot( array( array( 'file' => 'full.tar.gz', 'role' => 'full' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( 9901 );
		$this->assertTrue( $entries[0]['restorable'], 'A site with no multisite flags must restore as before.' );
		$this->assertSame( '', $entries[0]['restore_reason'] );

		$this->make_snapshot(
			array( array( 'file' => 'gone.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array(
				'site_id' => 9902,
				'status'  => 'pruned',
			)
		);
		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( 9902 );
		$this->assertFalse( $entries[0]['restorable'] );
		$this->assertNotSame( '', $entries[0]['restore_reason'], 'Unavailable snapshots must explain themselves.' );
	}

	/**
	 * A pruned snapshot lists as unavailable.
	 */
	public function test_pruned_snapshot_unavailable() {
		$this->make_snapshot(
			array( array( 'file' => 'full.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'status' => 'pruned' )
		);

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( 9901 );
		$this->assertSame( 'unavailable', $entries[0]['source'] );
		$this->assertFalse( $entries[0]['restorable'] );
	}

	/**
	 * Resolution refuses a snapshot that belongs to a different site than
	 * the caller is authorized for: a user scoped to one site cannot
	 * restore another site's backup by passing its snapshot id. With no
	 * expected site (0) the resolve is unscoped and succeeds.
	 */
	public function test_resolve_refuses_snapshot_from_other_site() {
		$id = $this->make_snapshot(
			array( array( 'file' => 'full.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'site_id' => 9901 )
		);

		$catalog = MainWP_Backup_Snapshot_Catalog::instance();

		$crossed = $catalog->resolve_restorable_chain( $id, 9902 );
		$this->assertFalse( $crossed['ok'], 'A snapshot from another site must not resolve for the authorized site.' );
		$this->assertStringContainsString( 'does not belong to this site', $crossed['error'] );

		$this->assertTrue( $catalog->resolve_restorable_chain( $id, 9901 )['ok'], 'The owning site must still resolve.' );
		$this->assertTrue( $catalog->resolve_restorable_chain( $id )['ok'], 'An unscoped resolve must stay backward compatible.' );
	}

	/**
	 * Resolving a pruned snapshot refuses cleanly.
	 */
	public function test_resolve_pruned_refused() {
		$id = $this->make_snapshot(
			array( array( 'file' => 'full.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'status' => 'pruned' )
		);

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id );
		$this->assertFalse( $resolved['ok'] );
		$this->assertNotEmpty( $resolved['error'] );
	}

	/**
	 * A files snapshot on a child without the files-only restore
	 * capability stays non-restorable: the list withholds the restore
	 * action and a direct resolve refuses with a clear message.
	 */
	public function test_files_snapshot_not_restorable_when_child_lacks_capability() {
		// Create idempotency is irrelevant to the listing; files-only support still gates it.
		$this->set_site_option( 9901, 'restore_create_idempotency_support', '0' );
		$id = $this->make_snapshot(
			array( array( 'file' => 'files.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'type' => 'files' )
		);

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( 9901 );
		$this->assertFalse( $entries[0]['restorable'], 'A files snapshot must not offer a restore on a child without the capability.' );
		$this->assertSame( 'Update the MainWP Child plugin on the site to restore files-only backups.', $entries[0]['restore_reason'] );

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id );
		$this->assertFalse( $resolved['ok'] );
		$this->assertStringContainsString( 'database', $resolved['error'] );
	}

	/**
	 * A files snapshot on a child that advertises the files-only restore
	 * pipeline is restorable, resolves with sub_type files, and its plan
	 * carries the archive with no database entry.
	 */
	public function test_files_snapshot_restorable_when_child_has_capability() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'restore_files_support', '1' );

		$id = $this->make_snapshot(
			array( array( 'file' => 'files.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array(
				'type'    => 'files',
				'site_id' => $site_id,
			)
		);

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
		$this->assertTrue( $entries[0]['restorable'], 'A files snapshot must offer a restore once the child advertises the capability.' );

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id );
		$this->assertTrue( $resolved['ok'] );
		$this->assertSame( 'files', $resolved['sub_type'] );
		$this->assertCount( 1, $resolved['plan'] );
		$this->assertSame( 'full', $resolved['plan'][0]['role'] );
		foreach ( $resolved['plan'] as $entry ) {
			$this->assertNotSame( 'db', $entry['role'], 'A files snapshot plan must not carry a database entry.' );
		}
	}

	/**
	 * A full snapshot resolves to a db-last plan with local sources.
	 */
	public function test_resolve_full_snapshot_plan_order() {
		$id = $this->make_snapshot(
			array(
				array(
					'file' => 'db.tar.gz',
					'role' => 'db',
				),
				array(
					'file' => 'full.tar.gz',
					'role' => 'full',
				),
			)
		);

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id );
		$this->assertTrue( $resolved['ok'] );
		$this->assertSame( 'full', $resolved['sub_type'] );

		$roles = array_map(
			function ( $entry ) {
				return $entry['role'];
			},
			$resolved['plan']
		);
		$this->assertSame( array( 'full', 'db' ), $roles, 'Files extract first; the dump replays last.' );
		$this->assertSame( 'local', $resolved['plan'][0]['source'] );
	}

	/**
	 * A mid-chain incremental resolves through its base, parts and
	 * tombstones stay in chain order, and the chain-newest db dump wins.
	 */
	public function test_resolve_incremental_chain_newest_db_wins() {
		$base = $this->make_snapshot(
			array(
				array(
					'file' => 'base-db.zip',
					'role' => 'db',
				),
				array(
					'file' => 'base-files.zip',
					'role' => 'full',
				),
			)
		);
		$incr = $this->make_snapshot(
			array(
				array(
					'file' => 'incr-db.zip',
					'role' => 'db',
				),
				array(
					'file' => 'plugins.part001.zip',
					'role' => 'part',
				),
				array(
					'file' => 'tombstones.list',
					'role' => 'tombstones',
				),
			),
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $incr );
		$this->assertTrue( $resolved['ok'] );

		$files = array_map(
			function ( $entry ) {
				return $entry['file'];
			},
			$resolved['plan']
		);
		$this->assertSame(
			array( 's' . $base . '-base-files.zip', 's' . $incr . '-plugins.part001.zip', 's' . $incr . '-tombstones.list', 's' . $incr . '-incr-db.zip' ),
			$files,
			'Base files, then increment parts and tombstones, then the newest dump - each namespaced by snapshot.'
		);
	}

	/**
	 * An ancestor's file list would reconcile against the wrong point in time.
	 */
	public function test_assemble_plan_keeps_only_leaf_manifest_after_file_ops() {
		$walk = array(
			'excludes'     => array( 'wp-content/cache', 'private' ),
			'excludenonwp' => 1,
			'excludezip'   => 0,
		);
		$base = $this->make_snapshot(
			array(
				array( 'file' => 'files.list', 'role' => 'manifest' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'base.zip', 'role' => 'full' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'base-db.zip', 'role' => 'db' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			)
		);
		$leaf = $this->make_snapshot(
			array(
				array(
					'file' => 'files.list',
					'role' => 'manifest',
					'walk' => $walk,
				),
				array( 'file' => 'db.zip', 'role' => 'db' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'tombstones.list', 'role' => 'tombstones' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'plugins.part001.zip', 'role' => 'part' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			),
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);

		$chain = MainWP_Backup_Snapshots::instance()->get_chain( $leaf );
		$plan  = MainWP_Backup_Snapshot_Catalog::instance()->assemble_plan( $chain );
		$this->assertSame( array( 'full', 'tombstones', 'part', 'manifest', 'db' ), array_column( $plan, 'role' ) );
		$this->assertSame(
			array( 's' . $base . '-base.zip', 's' . $leaf . '-tombstones.list', 's' . $leaf . '-plugins.part001.zip', 's' . $leaf . '-files.list', 's' . $leaf . '-db.zip' ),
			array_column( $plan, 'file' )
		);
		$this->assertSame( $leaf, $plan[3]['snapshot_id'] );
		$this->assertSame( $walk, $plan[3]['walk'] );
		$this->assertSame( 'local', $plan[3]['source'] );
		$this->assertSame( $this->dir . '/files.list', $plan[3]['local_path'] );
		$this->assertSame( 32, $plan[3]['size'] );
	}

	/**
	 * A missing leaf manifest must never select an ancestor's manifest.
	 */
	public function test_assemble_plan_omits_ancestor_manifest_when_leaf_has_none() {
		$base = $this->make_snapshot(
			array(
				array( 'file' => 'base.zip', 'role' => 'full' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'files.list', 'role' => 'manifest' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			)
		);
		$leaf = $this->make_snapshot(
			array( array( 'file' => 'db.zip', 'role' => 'db' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		$plan = MainWP_Backup_Snapshot_Catalog::instance()->assemble_plan( MainWP_Backup_Snapshots::instance()->get_chain( $leaf ) );
		$this->assertSame( array( 'full', 'db' ), array_column( $plan, 'role' ) );
	}

	/**
	 * A chain whose base was pruned refuses.
	 */
	public function test_resolve_chain_with_pruned_base_refused() {
		$base = $this->make_snapshot(
			array( array( 'file' => 'base-files.zip', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'status' => 'pruned' )
		);
		$incr = $this->make_snapshot(
			array( array( 'file' => 'incr-db.zip', 'role' => 'db' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $incr );
		$this->assertFalse( $resolved['ok'] );
	}

	/**
	 * An artifact with no local file and no completed upload makes the
	 * chain unrestorable.
	 */
	public function test_resolve_missing_artifact_refused() {
		$id = $this->make_snapshot(
			array(
				array(
					'file'    => 'gone.tar.gz',
					'role'    => 'full',
					'missing' => true,
				),
			)
		);

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id );
		$this->assertFalse( $resolved['ok'] );
		$this->assertStringContainsString( 'gone.tar.gz', $resolved['error'] );
	}

	/**
	 * A db-only snapshot resolves with the db sub type.
	 */
	public function test_resolve_db_snapshot_sub_type() {
		$id = $this->make_snapshot(
			array( array( 'file' => 'db.tar.gz', 'role' => 'db' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'type' => 'db' )
		);

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id );
		$this->assertTrue( $resolved['ok'] );
		$this->assertSame( 'db', $resolved['sub_type'] );
	}

	/**
	 * Mark a snapshot's upload job complete with the given artifact records.
	 *
	 * @param int   $snapshot_id    Snapshot id.
	 * @param int   $destination_id Destination id.
	 * @param array $artifacts      Artifact records for the checkpoint.
	 *
	 * @return void
	 */
	private function complete_upload( $snapshot_id, $destination_id, $artifacts ) {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $destination_id ) );
		foreach ( $store->get_jobs_for_snapshot( $snapshot_id ) as $job ) {
			if ( (int) $job->destination_id !== $destination_id ) {
				continue;
			}
			$store->save_checkpoint( (int) $job->id, array( 'artifacts' => $artifacts ) );
			$store->complete_job( (int) $job->id );
		}
	}

	/**
	 * Every completed upload of an artifact survives into the plan entry
	 * as a failover candidate, in job order, with the first copy still
	 * the primary source resolution. One corrupt or unavailable
	 * destination must not be able to defeat a restore that another
	 * healthy replica could serve.
	 */
	public function test_plan_entry_retains_all_completed_upload_candidates() {
		$id = $this->make_snapshot(
			array(
				array(
					'file'    => 'full.tar.gz',
					'role'    => 'full',
					'missing' => true,
				),
			)
		);

		$this->complete_upload(
			$id,
			71,
			array(
				array(
					'file'   => 'full.tar.gz',
					'mode'   => 'whole',
					'sha256' => 'aaa111',
				),
			)
		);
		$this->complete_upload(
			$id,
			72,
			array(
				array(
					'file'   => 'full.tar.gz',
					'mode'   => 'chunked',
					'sha256' => 'bbb222',
				),
			)
		);

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id );
		$this->assertTrue( $resolved['ok'] );

		$entry = $resolved['plan'][0];
		$this->assertSame( 'remote', $entry['source'], 'The first completed upload stays the primary source.' );
		$this->assertSame( '9901/' . $id . '/files/full.tar.gz', $entry['remote_key'] );
		$this->assertSame( 'aaa111', $entry['expected_sha256'] );

		$this->assertArrayHasKey( 'remote_candidates', $entry );
		$this->assertCount( 2, $entry['remote_candidates'], 'Every completed destination is a candidate.' );
		$this->assertSame( 71, $entry['remote_candidates'][0]['destination_id'] );
		$this->assertFalse( $entry['remote_candidates'][0]['chunked'] );
		$this->assertSame( 'aaa111', $entry['remote_candidates'][0]['sha256'] );
		$this->assertSame( 72, $entry['remote_candidates'][1]['destination_id'] );
		$this->assertTrue( $entry['remote_candidates'][1]['chunked'] );
		$this->assertSame( 'bbb222', $entry['remote_candidates'][1]['sha256'] );
	}
}
