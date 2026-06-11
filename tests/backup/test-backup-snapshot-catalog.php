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
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Snapshot_Catalog_Test
 */
class MainWP_Backup_Snapshot_Catalog_Test extends \WP_UnitTestCase {

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
	 * A files-only snapshot carries no database, so a dashboard restore
	 * cannot rebuild the site from it: the list withholds the restore
	 * action and a direct resolve refuses with a clear message.
	 */
	public function test_resolve_files_snapshot_refused() {
		$id = $this->make_snapshot(
			array( array( 'file' => 'files.tar.gz', 'role' => 'full' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			array( 'type' => 'files' )
		);

		$entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( 9901 );
		$this->assertFalse( $entries[0]['restorable'], 'A files-only snapshot must not offer a restore.' );

		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id );
		$this->assertFalse( $resolved['ok'] );
		$this->assertStringContainsString( 'database', $resolved['error'] );
	}

	/**
	 * A full snapshot resolves to a db-last plan with local sources.
	 */
	public function test_resolve_full_snapshot_plan_order() {
		$id = $this->make_snapshot(
			array(
				array( 'file' => 'db.tar.gz', 'role' => 'db' ),
				array( 'file' => 'full.tar.gz', 'role' => 'full' ),
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
				array( 'file' => 'base-db.zip', 'role' => 'db' ),
				array( 'file' => 'base-files.zip', 'role' => 'full' ),
			)
		);
		$incr = $this->make_snapshot(
			array(
				array( 'file' => 'incr-db.zip', 'role' => 'db' ),
				array( 'file' => 'plugins.part001.zip', 'role' => 'part' ),
				array( 'file' => 'tombstones.list', 'role' => 'tombstones' ),
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
}
