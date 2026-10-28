<?php
/**
 * Snapshot list and restore admission agreement.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;

/** Tests the catalog through stored rows and real local artifacts. */
class MainWP_Backup_V2_List_Admission_Agreement_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Artifact directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Fixture row counter.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/** Create one connected site. */
	public function set_up() {
		parent::set_up();
		$this->create_test_site( array( 'id' => 9901 ) );
		$this->dir = sys_get_temp_dir() . '/mainwp-v2-list-' . uniqid();
		$this->assertTrue( mkdir( $this->dir ) );
	}

	/** Remove local artifacts. */
	public function tear_down() {
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Read descriptor bytes and digests from the shared contract.
	 *
	 * @param string $name Contract case name, or v1.
	 * @return array Archives.
	 */
	private function archives( string $name ): array {
		if ( 'v1' === $name ) {
			return array(
				array(
					'file' => 'full.tar.gz',
					'role' => 'full',
				),
			);
		}
		$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/mwp2-descriptor-contract.json' ), true );
		$cases   = array_column( $fixture['cases'], null, 'name' );
		$case    = $cases[ $name ];
		return array(
			array(
				'file'                     => 'backup.mwp2',
				'role'                     => 'full',
				'backup_format'            => $case['backup_format'],
				'layout_descriptor_sha256' => $case['supplied_digest'],
			),
			array(
				'file'                     => 'files.list',
				'role'                     => 'manifest',
				'backup_format'            => $case['backup_format'],
				'layout_descriptor'        => $case['input_descriptor'],
				'layout_descriptor_sha256' => $case['supplied_digest'],
			),
		);
	}

	/**
	 * Insert stored metadata, including rows a current writer would refuse.
	 *
	 * @param string $format Contract case name, or v1.
	 * @param int    $parent_id Parent snapshot id.
	 * @param bool   $damaged Whether its file archive is damaged.
	 * @return int Snapshot id.
	 */
	private function record( string $format, int $parent_id = 0, bool $damaged = false ): int {
		global $wpdb;
		++$this->sequence;
		$id       = 2043000 + $this->sequence;
		$archives = $this->archives( $format );
		foreach ( $archives as &$archive ) {
			$archive['file'] = $id . '-' . $archive['file'];
			$archive['path'] = $this->dir . '/' . $archive['file'];
			file_put_contents( $archive['path'], 'fixture' );
			$archive['size'] = 7;
			if ( $parent_id && 'full' === $archive['role'] ) {
				$archive['role'] = 'part';
			}
			if ( $damaged && in_array( $archive['role'], array( 'full', 'part' ), true ) ) {
				$archive['damaged'] = true;
			}
		}
		unset( $archive );
		$this->assertNotFalse(
			$wpdb->insert( // phpcs:ignore WordPress.DB -- stored fixture.
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'id'        => $id,
					'site_id'   => 9901,
					'task_id'   => 0,
					'type'      => $parent_id ? 'incremental' : 'full',
					'parent_id' => $parent_id ?: null,
					'created'   => 1700000000 + $this->sequence,
					'status'    => 'complete',
					'archives'  => wp_json_encode( $archives ),
				)
			)
		);
		return $id;
	}

	/**
	 * Every listed row uses the resolver's verdict and existing message.
	 *
	 * @dataProvider chain_cases
	 * @param string $base Base format fixture.
	 * @param string $leaf Leaf format fixture.
	 * @param string $code Expected leaf refusal, or empty for success.
	 * @param bool   $damaged Whether the base archive is damaged.
	 */
	public function test_list_agrees_with_admission( string $base, string $leaf, string $code, bool $damaged = false ) {
		$base_id = $this->record( $base, 0, $damaged );
		$leaf_id = $this->record( $leaf, $base_id );
		$this->record( $leaf, $leaf_id );
		$catalog = MainWP_Backup_Snapshot_Catalog::instance();
		$entries = $catalog->list_snapshots_for_site( 9901 );
		$this->assertCount( 3, $entries );
		foreach ( $entries as $entry ) {
			$admission = $catalog->resolve_restorable_chain( (int) $entry['snapshot']->id, 9901 );
			$this->assertSame( $admission['ok'], $entry['restorable'], 'Snapshot ' . $entry['snapshot']->id );
			$this->assertSame( $admission['ok'] ? '' : $admission['error'], $entry['restore_reason'] );
			if ( $leaf_id === (int) $entry['snapshot']->id ) {
				$this->assertSame( $code, $admission['code'] ?? '' );
			}
		}
	}

	/**
	 * Chain format and damage combinations.
	 *
	 * @return array
	 */
	public static function chain_cases(): array {
		$layout = MainWP_Backup_Error_Codes::ERR_LAYOUT_CHANGED;
		return array(
			'v1 base and v2 leaf'          => array( 'v1', 'canonical_control', $layout ),
			'v2 base and v1 leaf'          => array( 'canonical_control', 'v1', $layout ),
			'different valid layouts'      => array( 'canonical_control', 'reordered_roots_canonical_digest', $layout ),
			'invalid leaf metadata'        => array( 'canonical_control', 'unknown_top_level_key', MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ),
			'invalid ancestor metadata'    => array( 'unknown_top_level_key', 'v1', MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ),
			'damage and different layouts' => array( 'canonical_control', 'reordered_roots_canonical_digest', $layout, true ),
			'matching v2 control'          => array( 'canonical_control', 'canonical_control', '' ),
		);
	}

	/**
	 * V1 keeps every list field and its existing damage handling.
	 *
	 * @dataProvider v1_cases
	 * @param bool $damaged Whether the full base is damaged.
	 */
	public function test_all_v1_control( bool $damaged ) {
		global $wpdb;
		$base = $this->record( 'v1', 0, $damaged );
		$mid  = $this->record( 'v1', $base );
		$this->record( 'v1', $mid );
		$catalog = MainWP_Backup_Snapshot_Catalog::instance();
		$before  = $wpdb->num_queries;
		$entries = $catalog->list_snapshots_for_site( 9901 );
		$queries = $wpdb->num_queries - $before;
		$this->assertSame( $damaged ? 31 : 9, $queries );
		$this->assertCount( 3, $entries );
		foreach ( $entries as $entry ) {
			$id        = (int) $entry['snapshot']->id;
			$admission = $catalog->resolve_restorable_chain( $id, 9901 );
			$this->assertSame( ! $damaged, $admission['ok'] );
			$this->assertEquals(
				array(
					'snapshot'       => MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id ),
					'source'         => $damaged && $base === $id ? 'unavailable' : 'local',
					'damaged'        => $damaged && $base === $id,
					'restorable'     => $admission['ok'],
					'restore_reason' => $admission['ok'] ? '' : $admission['error'],
					'removal_reason' => MainWP_Backup_Snapshots::REMOVAL_REASON_UNKNOWN,
				),
				$entry
			);
		}
	}

	/**
	 * Existing v1 availability states.
	 *
	 * @return array
	 */
	public static function v1_cases(): array {
		return array(
			'available'        => array( false ),
			'damaged ancestor' => array( true ),
		);
	}

	/**
	 * Removed rows retain the recorded cause while admission refuses them.
	 *
	 * @dataProvider removal_cases
	 * @param string $format Stored format fixture.
	 * @param string $cause Recorded removal cause.
	 * @param string $tooltip Existing list message.
	 */
	public function test_pruned_rows_keep_removal_tooltip( string $format, string $cause, string $tooltip ) {
		$healthy = $this->record( $format );
		$removed = $this->record( $format );
		$this->assertTrue( MainWP_Backup_Snapshots::instance()->mark_pruned( $removed, $cause ) );
		$catalog = MainWP_Backup_Snapshot_Catalog::instance();
		$entries = $catalog->list_snapshots_for_site( 9901 );
		$this->assertCount( 2, $entries );
		foreach ( $entries as $entry ) {
			$id        = (int) $entry['snapshot']->id;
			$admission = $catalog->resolve_restorable_chain( $id, 9901 );
			$this->assertSame( $admission['ok'], $entry['restorable'], 'Snapshot ' . $id );
			if ( $removed === $id ) {
				$this->assertFalse( $entry['restorable'] );
				$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_PRUNED, $admission['code'] );
				$this->assertSame( $cause, $entry['removal_reason'] );
				$this->assertSame( $tooltip, $entry['restore_reason'] );
				$this->assertSame( 'unavailable', $entry['source'] );
			} else {
				$this->assertSame( $healthy, $id );
				$this->assertTrue( $entry['restorable'] );
				$this->assertSame( '', $entry['restore_reason'] );
			}
		}
	}

	/**
	 * Existing removal causes in both formats.
	 *
	 * @return array
	 */
	public static function removal_cases(): array {
		$causes = array(
			'manual'       => 'Deleted manually; no copy survives locally or in cloud storage.',
			'retention'    => 'Removed by retention; no copy survives locally or in cloud storage.',
			'task_deleted' => 'Deleted with its backup task; no copy survives locally or in cloud storage.',
			'cleanup'      => 'Removed by Clean Up; no copy survives locally or in cloud storage.',
			''             => 'Removed; no copy survives locally or in cloud storage.',
		);
		$cases  = array();
		foreach ( array( 'v1', 'canonical_control' ) as $format ) {
			foreach ( $causes as $cause => $tooltip ) {
				$cases[ $format . '-' . ( '' !== $cause ? $cause : 'unknown' ) ] = array( $format, $cause, $tooltip );
			}
		}
		return $cases;
	}

	/**
	 * Site refusals retain their shorter list messages for v2 rows.
	 *
	 * @dataProvider site_gate_cases
	 * @param bool   $multisite Whether the site is a multisite network.
	 * @param string $tooltip Existing list message.
	 */
	public function test_v2_site_gate_keeps_list_tooltip( bool $multisite, string $tooltip ) {
		global $wpdb;
		$id = $this->record( 'canonical_control' );
		if ( $multisite ) {
			$website = MainWP_DB::instance()->get_website_by_id( 9901 );
			MainWP_DB::instance()->update_website_option( $website, 'child_is_multisite', '1' );
		} else {
			$this->assertSame( 1, $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'type' => 'files' ), array( 'id' => $id ) ) ); // phpcs:ignore WordPress.DB -- stored fixture.
		}
		$catalog   = MainWP_Backup_Snapshot_Catalog::instance();
		$entries   = $catalog->list_snapshots_for_site( 9901 );
		$admission = $catalog->resolve_restorable_chain( $id, 9901 );
		$this->assertCount( 1, $entries );
		$this->assertFalse( $admission['ok'] );
		$this->assertSame( $admission['ok'], $entries[0]['restorable'] );
		$this->assertSame( $tooltip, $entries[0]['restore_reason'] );
	}

	/**
	 * Site capabilities checked by both list and resolver.
	 *
	 * @return array
	 */
	public static function site_gate_cases(): array {
		return array(
			'files-only' => array( false, 'Update the MainWP Child plugin on the site to restore files-only backups.' ),
			'multisite'  => array( true, 'This site runs WordPress Multisite. Update the MainWP Child plugin to enable network-wide restore.' ),
		);
	}
}
