<?php
/**
 * MainWP Backup Retention Scheduler Tests
 *
 * The scheduler wires retention to its two triggers: a freshly recorded
 * snapshot queues its task and site pair for the next continue tick (no
 * synchronous pruning in the snapshot path), and the daily prune cron
 * sweeps every task carrying a policy. The dry-run filter forces a
 * report-only pass for staging.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Retention_Scheduler;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

/**
 * Class MainWP_Backup_Retention_Scheduler_Test
 */
class MainWP_Backup_Retention_Scheduler_Test extends \WP_UnitTestCase {

	/**
	 * Fixture site id.
	 *
	 * @var int
	 */
	private $site_id = 990020;

	/**
	 * Created fixture dirs for cleanup.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

	/**
	 * Clean fixture rows, dirs and options.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $this->site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		$task_table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->query( "DELETE FROM {$task_table} WHERE name LIKE 'Retention scheduler test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		delete_option( MainWP_Backup_Retention_Scheduler::QUEUE_OPTION );
		delete_option( 'mainwp_enableNativeBackups' );

		foreach ( $this->fixture_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( $dir . '/*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
			}
		}
		$this->fixture_dirs = array();

		parent::tear_down();
	}

	/**
	 * Insert a backup task row.
	 *
	 * @param array $fields Field overrides.
	 *
	 * @return int Task id.
	 */
	private function insert_task( array $fields = array() ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array_merge(
				array(
					'userid'            => 1,
					'name'              => 'Retention scheduler test ' . uniqid(),
					'schedule'          => 'daily',
					'type'              => 'full',
					'exclude'           => '',
					'sites'             => '',
					'groups'            => '',
					'last'              => 0,
					'last_run'          => 0,
					'last_run_manually' => 0,
					'completed_sites'   => '',
					'completed'         => 0,
					'backup_errors'     => '',
					'subfolder'         => '',
					'filename'          => '',
					'paused'            => 0,
					'archiveFormat'     => 'global',
				),
				$fields
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Create a local snapshot dir with an artifact and record a snapshot
	 * row over it.
	 *
	 * @param int   $task_id Task id.
	 * @param array $fields  Snapshot row overrides.
	 *
	 * @return int Snapshot id.
	 */
	private function insert_snapshot_with_dir( $task_id, array $fields = array() ) {
		global $wpdb;

		$upload = wp_upload_dir();
		// Nested under the production mainwp/0/{site_id}/ layout: the
		// retention executor confines deletions to the snapshot's own site
		// subtree, so a fixture outside it would be refused.
		$dir = trailingslashit( $upload['basedir'] ) . 'mainwp/0/' . $this->site_id . '/retention-sched-test-' . uniqid();
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;

		$path = $dir . '/artifact.tar';
		file_put_contents( $path, str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array_merge(
				array(
					'task_id'       => $task_id,
					'site_id'       => $this->site_id,
					'type'          => 'full',
					'created'       => time(),
					'archives'      => wp_json_encode( array( array( 'file' => 'artifact.tar', 'size' => 100, 'role' => 'full', 'path' => $path ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
					'manifest_path' => '',
					'status'        => 'complete',
				),
				$fields
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * A recorded snapshot queues its pair only when the task carries a
	 * policy, and the queue deduplicates.
	 *
	 * @return void
	 */
	public function test_snapshot_recorded_queues_pairs_with_policy_deduped() {
		$with_policy = $this->insert_task( array( 'retention_count' => 2 ) );
		$without     = $this->insert_task();

		$scheduler = MainWP_Backup_Retention_Scheduler::instance();
		$scheduler->on_snapshot_recorded( 1, $with_policy, $this->site_id );
		$scheduler->on_snapshot_recorded( 2, $with_policy, $this->site_id );
		$scheduler->on_snapshot_recorded( 3, $without, $this->site_id );

		$queue = get_option( MainWP_Backup_Retention_Scheduler::QUEUE_OPTION );

		$this->assertCount( 1, $queue, 'One pair: deduped, and the policy-less task never queues.' );
		$pair = array_values( $queue )[0];
		$this->assertSame( $with_policy, (int) $pair['task_id'] );
		$this->assertSame( $this->site_id, (int) $pair['site_id'] );
	}

	/**
	 * Draining the queue runs retention for one pair per call.
	 *
	 * @return void
	 */
	public function test_process_post_snapshot_queue_prunes_one_pair() {
		$task_id = $this->insert_task( array( 'retention_count' => 1 ) );
		$old     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 200 ) );
		$new     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 100 ) );

		$scheduler = MainWP_Backup_Retention_Scheduler::instance();
		$scheduler->on_snapshot_recorded( $new, $task_id, $this->site_id );

		$scheduler->process_post_snapshot_queue();

		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $new )->status );
		$this->assertSame( array(), get_option( MainWP_Backup_Retention_Scheduler::QUEUE_OPTION, array() ), 'The drained pair leaves the queue.' );
	}

	/**
	 * A pair whose budget expires with candidates left goes back to the
	 * queue tail, so the next continue tick finishes the cleanup instead
	 * of leaving it to the daily sweep.
	 *
	 * @return void
	 */
	public function test_budget_skip_requeues_pair() {
		$task_id = $this->insert_task( array( 'retention_count' => 1 ) );
		$this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 300 ) );
		$this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 200 ) );
		$new = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 100 ) );

		$scheduler = MainWP_Backup_Retention_Scheduler::instance();
		$scheduler->on_snapshot_recorded( $new, $task_id, $this->site_id );

		// A zero budget prunes the first candidate and skips the rest.
		$scheduler->process_post_snapshot_queue( 0 );

		$queue = get_option( MainWP_Backup_Retention_Scheduler::QUEUE_OPTION, array() );
		$this->assertCount( 1, $queue, 'The partially processed pair must return to the queue.' );
		$pair = array_values( $queue )[0];
		$this->assertSame( $task_id, (int) $pair['task_id'] );
		$this->assertSame( $this->site_id, (int) $pair['site_id'] );

		// The next tick finishes and leaves the queue empty.
		$scheduler->process_post_snapshot_queue();
		$this->assertSame( array(), get_option( MainWP_Backup_Retention_Scheduler::QUEUE_OPTION, array() ), 'A completed pair must not requeue.' );
	}

	/**
	 * A retention pass whose executor reports errors must surface them in
	 * the scheduled paths: the pair drain logs a warning carrying the task
	 * and site ids, and the kept snapshot stays complete for the daily
	 * retry. Both cron triggers flow through run_retention_for_pair(), so
	 * the drain path stands in for the daily sweep too.
	 *
	 * @return void
	 */
	public function test_queue_drain_surfaces_retention_errors() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses directory write permissions.' );
		}
		$task_id = $this->insert_task( array( 'retention_count' => 1 ) );
		$old     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 200 ) );
		$new     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 100 ) );

		$old_dir = $this->fixture_dirs[0];

		$warnings = array();
		$capture  = function ( $message ) use ( &$warnings ) {
			$warnings[] = (string) $message;
		};
		add_action( 'mainwp_log_warning', $capture );

		// A read-only directory makes the old snapshot's unlink fail.
		chmod( $old_dir, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		try {
			$scheduler = MainWP_Backup_Retention_Scheduler::instance();
			$scheduler->on_snapshot_recorded( $new, $task_id, $this->site_id );
			$scheduler->process_post_snapshot_queue();
		} finally {
			chmod( $old_dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			remove_action( 'mainwp_log_warning', $capture );
		}

		$this->assertNotSame( array(), $warnings, 'A failed retention delete must log a warning in the scheduled path.' );
		$this->assertStringContainsString( 'task ' . $task_id, $warnings[0] );
		$this->assertStringContainsString( 'site ' . $this->site_id, $warnings[0] );
		$this->assertStringContainsString( 'Could not remove local backup file', $warnings[0] );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status, 'The kept snapshot stays complete for the daily retry.' );
	}

	/**
	 * The daily cron sweeps every task with a policy.
	 *
	 * @return void
	 */
	public function test_daily_cron_prunes_tasks_with_policy() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$task_id = $this->insert_task( array( 'retention_count' => 1 ) );
		$old     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 200 ) );
		$new     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 100 ) );

		MainWP_System_Cron_Jobs::instance()->cron_backups_prune();

		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $new )->status );
	}

	/**
	 * The daily cron does nothing while native backups are disabled.
	 *
	 * @return void
	 */
	public function test_daily_cron_gated_on_native_backups() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );

		$task_id = $this->insert_task( array( 'retention_count' => 1 ) );
		$old     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 200 ) );
		$this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 100 ) );

		MainWP_System_Cron_Jobs::instance()->cron_backups_prune();

		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
	}

	/**
	 * The dry-run filter turns every retention pass into a report-only
	 * pass.
	 *
	 * @return void
	 */
	public function test_dry_run_filter_blocks_mutations() {
		add_filter( 'mainwp_backup_retention_dry_run', '__return_true' );

		$task_id = $this->insert_task( array( 'retention_count' => 1 ) );
		$old     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 200 ) );
		$new     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 100 ) );

		$scheduler = MainWP_Backup_Retention_Scheduler::instance();
		$scheduler->on_snapshot_recorded( $new, $task_id, $this->site_id );
		$scheduler->process_post_snapshot_queue();

		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
	}

	/**
	 * The prune cron registers daily alongside the other backup crons,
	 * only while native backups are enabled.
	 *
	 * @return void
	 */
	public function test_prune_cron_registered_daily_with_native_backups() {
		update_option( 'mainwp_enableNativeBackups', 1 );
		$jobs = MainWP_System_Cron_Jobs::instance()->get_cron_jobs();
		$this->assertSame( 'daily', $jobs['mainwp_cronbackups_prune_action'] );

		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		$jobs = MainWP_System_Cron_Jobs::instance()->get_cron_jobs();
		$this->assertArrayNotHasKey( 'mainwp_cronbackups_prune_action', $jobs );
	}
}
