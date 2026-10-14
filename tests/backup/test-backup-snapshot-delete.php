<?php
/**
 * Snapshot deletion follows descendants and revalidates consent.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Retention_Query;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;

require_once __DIR__ . '/trait-snapshot-delete-fixture.php';

/** Exercise deletion against stored rows and real owned files. */
class MainWP_Backup_Snapshot_Delete_Test extends MainWP_Abilities_Test_Case {
	use Snapshot_Delete_Fixture;

	/**
	 * Base, middle and leaf targets.
	 *
	 * @return array
	 */
	public static function chain_targets() {
		return array(
			'base'   => array( 0 ),
			'middle' => array( 1 ),
			'leaf'   => array( 2 ),
		);
	}

	/**
	 * A later sibling is not a descendant of the middle increment.
	 *
	 * @dataProvider chain_targets
	 * @param int $position Target position.
	 */
	public function test_chain_rule( $position ) {
		$base   = $this->snapshot();
		$middle = $this->snapshot( $base );
		$leaf   = $this->snapshot( $middle );
		$branch = $this->snapshot( $base );
		$ids    = array( $base, $middle, $leaf, $branch );
		$wanted = array( $ids, array( $middle, $leaf ), array( $leaf ) )[ $position ];
		$target = $ids[ $position ];
		$plan   = $this->preview( $target );
		$this->assertSame( $wanted, $plan['would_affect']['snapshot_ids'] );
		$this->assertSame( $wanted, MainWP_Backup_Retention_Query::instance()->get_snapshot_delete_ids( $target ) );
		$result  = $this->confirm( $target, $plan );
		$deleted = $result['deleted_ids'];
		sort( $deleted, SORT_NUMERIC );
		$this->assertSame( $wanted, $deleted );
		$this->assertSame( array(), $result['remaining_ids'] );
		foreach ( $ids as $id ) {
			$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
			$this->assertSame( in_array( $id, $wanted, true ) ? 'pruned' : 'complete', $snapshot->status );
			$this->assertSame( ! in_array( $id, $wanted, true ), file_exists( $snapshot->archives[0]['path'] ) );
		}
	}

	/** Reversed id order must still delete children before their parents. */
	public function test_parent_walk_ignores_id_order() {
		$early = $this->snapshot();
		$base  = $this->snapshot();
		$mid   = $this->snapshot( $base );
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'type'      => 'incremental',
				'parent_id' => $mid,
			),
			array( 'id' => $early )
		);
		$plan = $this->preview( $base );
		$this->assertSame( array( $early, $base, $mid ), $plan['would_affect']['snapshot_ids'] );
		$this->assertSame( array( $base, $mid, $early ), MainWP_Backup_Retention_Query::instance()->get_snapshot_delete_ids( $base ) );
		$this->assertSame( array( $early, $mid, $base ), $this->confirm( $base, $plan )['deleted_ids'] );
	}

	/**
	 * Interleavings change persisted membership.
	 *
	 * @return array
	 */
	public static function races() {
		return array(
			'increment recorded' => array( true ),
			'member pruned'      => array( false ),
		);
	}

	/**
	 * The seam mutates the real chain between preview and confirmation.
	 *
	 * @dataProvider races
	 * @param bool $add Whether a new increment appears.
	 */
	public function test_changed_set_refuses( $add ) {
		$base    = $this->snapshot();
		$leaf    = $this->snapshot( $base );
		$plan    = $this->preview( $base );
		$between = function () use ( $add, $leaf ) {
			if ( $add ) {
				return $this->snapshot( $leaf );
			}
			MainWP_Backup_Snapshots::instance()->set_status( $leaf, 'pruned' );
			return 0;
		};
		$new     = $between();
		$result  = $this->confirm( $base, $plan );
		$this->assertSame( 'changed', $result['status'] );
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertSame( $add ? array( $base, $leaf, $new ) : array( $base ), $result['preview']['would_affect']['snapshot_ids'] );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ), 'Release the reservation on refusal.' );
	}

	/**
	 * Both cloud choices.
	 *
	 * @return array
	 */
	public static function cloud_choices() {
		return array(
			'keep copies'   => array( false ),
			'queue deletes' => array( true ),
		);
	}

	/**
	 * Kept cloud copies lose their tracking rows; deletes remain queued.
	 *
	 * @dataProvider cloud_choices
	 * @param bool $cloud Choice.
	 */
	public function test_cloud_checkbox( $cloud ) {
		$id = $this->snapshot();
		global $wpdb;
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'snapshot_id'    => $id,
				'destination_id' => 987654,
				'provider'       => 's3',
				'status'         => 'complete',
				'kind'           => 'upload',
				'created'        => time(),
				'updated'        => time(),
			)
		);
		$plan = $this->preview( $id, $cloud );
		$this->assertSame( $cloud, $plan['would_affect']['delete_cloud_copies'] );
		$this->assertCount( $cloud ? 0 : 1, $plan['warnings'] );
		$result = $this->confirm( $id, $plan, $cloud );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$this->assertSame(
			$cloud ? array(
				array(
					'snapshot_id'      => $id,
					'destination_id'   => 987654,
					'destination_name' => '',
				),
			) : array(),
			$result['cloud_deletes_queued']
		);
		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id, 'upload' ) );
	}

	/** Hidden task authorization must never block snapshot authorization. */
	public function test_hidden_back_up_now_and_missing_task_defaults() {
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		$this->assertTrue( $plan['can_proceed'] );
		$this->assertTrue( $plan['would_affect']['delete_cloud_copies'] );
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $this->task->id ) );
		$this->assertTrue( $this->preview( $id )['would_affect']['delete_cloud_copies'] );
		$this->assertSame( 'complete', $this->confirm( $id, $plan )['status'] );
	}

	/**
	 * Gate applicability and evidence left after deletion.
	 *
	 * @return array
	 */
	public static function gate_cases() {
		return array(
			'last backup'                            => array( true, false, true ),
			'another remains'                        => array( true, true, false ),
			'gate off'                               => array( false, false, false ),
			'files archive supplies legacy evidence' => array( true, false, true, 'files' ),
		);
	}

	/**
	 * Warn only when a passing applicable gate would stop passing.
	 *
	 * @dataProvider gate_cases
	 * @param bool   $enabled Gate enabled.
	 * @param bool   $another Another fresh backup remains.
	 * @param bool   $warn Expected warning.
	 * @param string $type Target type.
	 */
	public function test_update_gate_warning( $enabled, $another, $warn, $type = 'full' ) {
		update_option( 'mainwp_backup_before_upgrade', (int) $enabled );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 0 );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'backup_before_upgrade' => 1 ), array( 'id' => $this->site_id ) );
		if ( $another ) {
			$this->snapshot();
		}
		$id   = $this->snapshot( null, $type );
		$plan = $this->preview( $id );
		$this->assertSame( $warn, $plan['would_affect']['update_gate_warning'] );
		$this->assertCount( $warn ? 1 : 0, $plan['warnings'] );
		if ( $warn ) {
			$this->assertSame( 'After this deletion, updates for this site are held until it has another backup that meets the "Require a backup before an update" setting.', $plan['warnings'][0] );
		}
		$this->assertSame( $another, MainWP_Backup_Executor::has_recent_native_backup( $this->site_id, 7, false, array( $id ) ) );
	}

	/** Site denial and a mismatched site must leave snapshots intact. */
	public function test_restricted_other_site_refused() {
		$this->skip_when_capabilities_bypassed();
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		$deny = '__return_false';
		add_filter( 'mainwp_check_site_access', $deny, 10, 2 );
		try {
			$this->assertWPError( MainWP_Backup_Operations::preview_delete_snapshot( $this->site_id, $id ) );
			$this->assertWPError( $this->confirm( $id, $plan ) );
		} finally {
			remove_filter( 'mainwp_check_site_access', $deny );
		}
		$this->assertWPError( MainWP_Backup_Operations::preview_delete_snapshot( $this->create_test_site(), $id ) );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
	}

	/**
	 * Running site actions.
	 *
	 * @return array
	 */
	public static function busy_cases() {
		return array(
			'restore'        => array( 'restore' ),
			'reservation'    => array( 'reservation' ),
			'manual backup'  => array( 'manual' ),
			'scheduled full' => array( 'scheduled' ),
		);
	}

	/**
	 * The server refuses either running lane, including a scheduled full backup.
	 *
	 * @dataProvider busy_cases
	 * @param string $kind Running lane.
	 */
	public function test_running_site_refuses( $kind ) {
		if ( 'scheduled' === $kind ) {
			$this->use_scheduled_task();
		}
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		if ( 'restore' === $kind ) {
			MainWP_Backup_Restore_Job_Store::instance()->create_job( $this->site_id, $id, array() );
		} elseif ( 'reservation' === $kind ) {
			MainWP_Backup_Restore_Job_Store::instance()->reserve_site( $this->site_id );
		} else {
			global $wpdb;
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup' ),
				array(
					'template'  => 'scheduled' === $kind ? 0 : 1,
					'last_run'  => time(),
					'completed' => 0,
				),
				array( 'id' => $this->task->id )
			);
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ),
				array(
					'task_id'              => $this->task->id,
					'wp_id'                => $this->site_id,
					'dtsFetched'           => time(),
					'child_backup_task_id' => 'busy',
					'child_phase'          => 'archiving',
				)
			);
		}
		$this->assertFalse( $this->preview( $id )['can_proceed'] );
		$this->assertSame(
			array(
				'code'    => 'mainwp_backup_already_running',
				'message' => in_array( $kind, array( 'restore', 'reservation' ), true )
					? 'A restore or another backup action is running on this site. Try again when it finishes.'
					: 'A backup is running on this site. Try again when it finishes.',
			),
			$this->preview( $id )['refusals'][0]
		);
		$this->assertSame( 'refused', $this->confirm( $id, $plan )['status'] );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
	}

	/** A failing local preflight must report a partial result honestly. */
	public function test_partial_result_keeps_ancestor() {
		$base = $this->snapshot();
		$mid  = $this->snapshot( $base );
		$leaf = $this->snapshot( $mid );
		$plan = $this->preview( $base );
		global $wpdb;
		$outside = tempnam( sys_get_temp_dir(), 'x2-outside-' );
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'archives' => wp_json_encode(
					array(
						array(
							'path' => $outside,
							'size' => 1,
						),
					)
				),
			),
			array( 'id' => $mid )
		);
		try {
			$result = $this->confirm( $base, $plan );
			$this->assertSame( 'partial', $result['status'] );
			$this->assertSame( array( $leaf ), $result['deleted_ids'] );
			$this->assertSame( array( $base, $mid ), $result['remaining_ids'] );
			$this->assertNotEmpty( $result['errors'] );
		} finally {
			unlink( $outside );
		}
	}
}
