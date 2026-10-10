<?php
/**
 * Snapshot list, detail, and restore preview abilities will use these readers.
 * Their adapters are tested separately once those abilities exist.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_DB;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

/** Class MainWP_Backup_Restorable_Evidence_Test. */
class MainWP_Backup_Restorable_Evidence_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Fixtures;

	/**
	 * Fixed records make handler responses repeatable.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->seed_read_fixtures();
	}

	/**
	 * Fixture records and request state must not escape the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->clear_read_fixtures();
		parent::tear_down();
	}

	/**
	 * Each fixture isolates a full-admission refusal.
	 *
	 * @return array Admission fixtures.
	 */
	public function refusals() {
		return array(
			'missing-base'   => array( 'base_missing' ),
			'pruned-parent'  => array( 'chain_pruned' ),
			'partial-chunks' => array( 'artifact_unavailable' ),
			'local-chain'    => array( '' ),
		);
	}

	/**
	 * The full admission result must override the cheap list flag.
	 *
	 * @dataProvider refusals
	 * @param string $reason Expected refusal suffix.
	 * @return void
	 */
	public function test_full_admission_evidence( $reason ) {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		if ( 'base_missing' === $reason ) {
			$wpdb->update(
				$table,
				array(
					'type'      => 'incremental',
					'parent_id' => 987679,
				),
				array( 'id' => 987673 )
			);
		} elseif ( 'chain_pruned' === $reason ) {
			$this->insert_read_fixture(
				'wp_backup_snapshot',
				array(
					'id'       => 987679,
					'site_id'  => $this->site_id,
					'task_id'  => 987671,
					'type'     => 'full',
					'status'   => 'pruned',
					'archives' => '[]',
				)
			);
			$wpdb->update( $table, array( 'parent_id' => 987679 ), array( 'id' => 987673 ) );
		} elseif ( 'artifact_unavailable' === $reason ) {
			$wpdb->update( $table, array( 'archives' => '[{"file":"full.zip","role":"full","path":"/missing/full.zip"},{"file":"db.sql.gz","role":"db","path":"/missing/db.sql.gz"}]' ), array( 'id' => 987673 ) );
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
				array(
					'status'     => 'complete',
					'checkpoint' => '{"artifacts":[{"file":"full.zip","mode":"chunked"}]}',
				),
				array( 'id' => 987675 )
			);
		}
		$catalog = MainWP_Backup_Snapshot_Catalog::instance();
		$this->assertTrue(
			array_values(
				array_filter(
					$catalog->list_snapshots_for_site( $this->site_id ),
					static function ( $entry ) {
						return 987673 === (int) $entry['snapshot']->id;
					}
				)
			)[0]['restorable'],
			'Precondition: the cheap list flag cannot see this admission gap.'
		);
		$admission = $catalog->resolve_restorable_chain( 987673, $this->site_id );
		if ( '' !== $reason ) {
			$this->assertSame( 'mainwp_backup_snapshot_unrestorable_' . $reason, $admission['code'] ?? null );
		}
		$this->assertTrue( class_exists( MainWP_Backup_Read_Model::class ), 'Admission evidence needs the read model.' );
		$list   = MainWP_Backup_Read_Model::list_backup_snapshots( $this->site_id );
		$detail = MainWP_Backup_Read_Model::get_backup_snapshot( 987673 );
		foreach ( array(
			array_values(
				array_filter(
					$list,
					static function ( $entry ) {
						return 987673 === (int) $entry['snapshot']->id; }
				)
			)[0],
			$detail,
		) as $reading ) {
			$this->assertSame( '' === $reason, $reading['restorable'] );
			$this->assertSame( '' === $reason ? null : $admission['code'], $reading['restore_reason']['code'] ?? null );
			$this->assertEquals( $admission, $reading['restore'], 'The restore preview uses the same full admission evidence.' );
		}
	}
}
