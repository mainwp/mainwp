<?php
/**
 * Cleanup previews select the same directories and snapshot units as deletion.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

/** Class MainWP_Backup_Cleanup_Plan_Test. */
class MainWP_Backup_Cleanup_Plan_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Purity_Guard;

	/**
	 * One orphan, one deleted task, one broken chain and three intact units.
	 *
	 * @return void
	 */
	public function test_preview_is_pure_and_cleanup_applies_exactly_its_set() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$task    = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $site_id ) );
		$root    = trailingslashit( wp_upload_dir()['basedir'] ) . 'mainwp/0/' . $site_id . '/';
		$dirs    = array();
		$ids     = array();
		try {
			for ( $index = 0; $index < 6; ++$index ) {
				$dir = $root . 'snapshot-preview-' . $index;
				wp_mkdir_p( $dir );
				$dirs[] = $dir;
				file_put_contents( $dir . '/full.tar', str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture bytes.
				touch( $dir . '/full.tar', time() - DAY_IN_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture age.
				touch( $dir, time() - DAY_IN_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture age.
				if ( 0 === $index ) {
					continue;
				}
				$this->assertNotFalse(
					$wpdb->insert(
						MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
						array(
							'site_id'   => $site_id,
							'task_id'   => 1 === $index ? 999999999 : (int) $task->id,
							'type'      => 2 === $index ? 'incremental' : 'full',
							'parent_id' => 2 === $index ? 999999999 : null,
							'created'   => 1700000000 + $index,
							'status'    => 'complete',
							'archives'  => wp_json_encode(
								array(
									array(
										'file' => 'full.tar',
										'path' => $dir . '/full.tar',
										'size' => 100,
										'role' => 'full',
									),
								)
							),
						)
					)
				);
				$ids[ $index ] = (int) $wpdb->insert_id;
			}
			$plan = $this->guarded(
				static function () use ( $site_id ) {
					return MainWP_Backup_Retention_Executor::instance()->plan_site_cleanup( $site_id, 1 );
				}
			);
			$this->assertSame( array( $dirs[0] ), $plan['orphan_dirs'] );
			$this->assertSame( array( $ids[1] ), $plan['orphaned_task_snapshot_ids'] );
			$this->assertSame(
				array(
					array(
						'snapshot_ids' => array( $ids[2] ),
						'reason'       => 'broken_chain',
					),
					array(
						'snapshot_ids' => array( $ids[4] ),
						'reason'       => 'beyond_keep',
					),
					array(
						'snapshot_ids' => array( $ids[3] ),
						'reason'       => 'beyond_keep',
					),
				),
				$plan['on_demand_units']
			);
			$this->assertSame( 500, $plan['bytes'] );
			$this->assertSame(
				$plan,
				$this->guarded(
					static function () use ( $site_id ) {
						return MainWP_Backup_Operations::cleanup_site_backups( $site_id, 1, true );
					}
				)
			);
			$result = MainWP_Backup_Operations::cleanup_site_backups( $site_id, 1 );
			$this->assertSame( 1, $result['orphan_dirs_removed'] );
			$this->assertSame( 4, $result['snapshots_pruned'] );
			foreach ( $ids as $index => $id ) {
				$this->assertSame( 5 === $index ? 'complete' : 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
				$this->assertSame( 5 === $index, is_dir( $dirs[ $index ] ) );
			}
			$this->assertDirectoryDoesNotExist( $dirs[0] );
		} finally {
			foreach ( $dirs as $dir ) {
				if ( is_file( $dir . '/full.tar' ) ) {
					unlink( $dir . '/full.tar' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture cleanup.
				}
				if ( is_dir( $dir ) ) {
					rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture cleanup.
				}
			}
		}
	}
}
