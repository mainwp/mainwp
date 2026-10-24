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

require_once __DIR__ . '/test-backup-now-continuation.php';

/**
 * Class MainWP_Backup_Retention_Scheduler_Test
 */
class MainWP_Backup_Retention_Scheduler_Test extends \WP_UnitTestCase {

	/** Live retention precedes orphan work, which rotates past minimum-held history. */
	public function test_deleted_task_deadline_rotates_held_history_after_live_retention() {
		global $wpdb;

		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( MainWP_Backup_Retention_Scheduler::CHILD_PRUNE_RESEND_OPTION, time(), false );
		$orphan      = $this->insert_task(
			array(
				'manual_site_id'      => $this->site_id,
				'template'            => 1,
				'schedule'            => 'manual',
				'delete_cloud_copies' => 1,
			)
		);
		$live        = $this->insert_task( array( 'retention_count' => 1 ) );
		$old         = $this->insert_snapshot_with_dir( $live, array( 'created' => time() - 200 ) );
		$new         = $this->insert_snapshot_with_dir( $live, array( 'created' => time() - 100 ) );
		$destination = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Deleted task minimum',
				'settings' => array( 'minimum_remote_retention_days' => 30 ),
			)
		);
		$store       = \MainWP\Dashboard\MainWP_Backup_Upload_Job_Store::instance();
		$held        = array();
		for ( $i = 0; $i < 12; ++$i ) {
			$id     = $this->insert_snapshot_with_dir( $orphan );
			$held[] = $id;
			$store->enqueue_for_snapshot( $id, array( $destination ) );
			$job = $store->get_jobs_for_snapshot( $id )[0];
			$store->save_checkpoint(
				$job->id,
				array(
					'uploaded_at'        => time(),
					'manifest_submitted' => true,
				)
			);
			$store->complete_job( $job->id );
		}
		$eligible = $this->insert_snapshot_with_dir( $orphan );
		$store->enqueue_for_snapshot( $eligible, array( $destination ) );
		$job = $store->get_jobs_for_snapshot( $eligible )[0];
		$store->save_checkpoint(
			$job->id,
			array(
				'uploaded_at'        => time() - 31 * DAY_IN_SECONDS,
				'manifest_submitted' => true,
			)
		);
		$store->complete_job( $job->id );
		$attempts   = array();
		$slow_claim = static function ( $sql ) use ( &$attempts ) {
			if ( preg_match( '/SET locked_by = \'prune:.*WHERE snapshot_id IN \((\d+)\)/', $sql, $matches ) ) {
				$attempts[] = (int) $matches[1];
				MainWP_Backup_Now_Continuation_Test::$fixed_microtime += 121.0;
			}
			return $sql;
		};
		MainWP_Backup_Now_Continuation_Test::$fixed_microtime = 1000.0;
		add_filter( 'query', $slow_claim );
		try {
			$scheduler = MainWP_Backup_Retention_Scheduler::instance();
			$scheduler->cron_backups_prune();
			$this->assertSame( array( $old, $held[0] ), $attempts, 'Live work must run first, followed by exactly one orphan attempt after expiry.' );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $new )->status );
			for ( $i = 1; $i <= 12; ++$i ) {
				$before = count( $attempts );
				$scheduler->cron_backups_prune();
				$this->assertSame( $before + 1, count( $attempts ), 'Each expired pass attempts one further orphan unit.' );
			}
			$this->assertSame( array_merge( array( $old ), $held, array( $eligible ) ), $attempts );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $eligible )->status );
			$this->assertCount( 1, $store->get_jobs_for_snapshot( $eligible, 'delete' ) );
			$scheduler->cron_backups_prune();
			$this->assertSame( $held[0], end( $attempts ), 'Traversal wraps once and retries held units.' );
			$this->assertCount( 1, $store->get_jobs_for_snapshot( $eligible, 'delete' ), 'Settled history must not enqueue remote deletion again.' );
			$this->assertNotNull( \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $orphan ) );
			foreach ( $held as $id ) {
				$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
			}
		} finally {
			remove_filter( 'query', $slow_claim );
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'destination_id' => $destination ) );
			\MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->delete_destination( $destination );
			delete_option( 'mainwp_backup_deleted_task_cursor' );
		}
	}

	/** Dry-run lists orphan candidates and leaves backup data and files unchanged. */
	public function test_deleted_task_dry_run_lists_candidates_without_mutation() {
		global $wpdb;

		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( MainWP_Backup_Retention_Scheduler::CHILD_PRUNE_RESEND_OPTION, time(), false );
		$task = $this->insert_task(
			array(
				'manual_site_id'      => $this->site_id,
				'template'            => 1,
				'schedule'            => 'manual',
				'delete_cloud_copies' => 1,
			)
		);
		$id   = $this->insert_snapshot_with_dir( $task );
		$this->insert_snapshot_with_dir( $task, array( 'status' => 'pruned' ) );
		update_option(
			'mainwp_backup_deleted_task_cursor',
			array(
				'tasks'     => array( $task => $id ),
				'last_task' => $task,
			),
			false
		);
		$before = array();
		foreach ( array( 'wp_backup', 'wp_backup_snapshot', 'wp_backup_upload_job', 'wp_backup_storage' ) as $table ) {
			$name             = MainWP_DB::instance()->get_table_name( $table );
			$before[ $table ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $name ), ARRAY_A );
		}
		$options  = $wpdb->get_results( "SELECT * FROM {$wpdb->options} WHERE option_name LIKE 'mainwp_backup%' OR option_name = 'mainwp_enableNativeBackups'", ARRAY_A );
		$messages = array();
		$requests = 0;
		$log      = static function ( $message, $site_id, $task_id ) use ( &$messages ) {
			$messages[] = array( $message, $site_id, $task_id );
		};
		$http     = static function ( $pre ) use ( &$requests ) {
			++$requests;
			return $pre;
		};
		add_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
		add_action( 'mainwp_log_info', $log, 10, 3 );
		add_filter( 'pre_http_request', $http );
		try {
			MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
			$this->assertCount( 1, $messages, 'Cron must expose the deleted-site preview.' );
			$this->assertStringContainsString( '2 candidates', $messages[0][0] );
			$this->assertStringContainsString( '2 local', $messages[0][0] );
			$this->assertStringContainsString( '0 remote', $messages[0][0] );
			$this->assertSame( array( $this->site_id, $task ), array_slice( $messages[0], 1 ) );
			foreach ( $before as $table => $rows ) {
				$name = MainWP_DB::instance()->get_table_name( $table );
				$this->assertEquals( $rows, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $name ), ARRAY_A ), $table );
			}
			$this->assertEquals( $options, $wpdb->get_results( "SELECT * FROM {$wpdb->options} WHERE option_name LIKE 'mainwp_backup%' OR option_name = 'mainwp_enableNativeBackups'", ARRAY_A ) );
			foreach ( $this->fixture_dirs as $dir ) {
				$this->assertSame( str_repeat( 'x', 100 ), file_get_contents( $dir . '/artifact.tar' ) );
			}
			$this->assertSame( 0, $requests );
		} finally {
			remove_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
			remove_action( 'mainwp_log_info', $log );
			remove_filter( 'pre_http_request', $http );
			delete_option( 'mainwp_backup_deleted_task_cursor' );
		}
	}

	/** An expired preview reports one unit and yields without writing progress. */
	public function test_deleted_task_dry_run_expired_deadline_bounds_candidates_and_tasks() {
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( MainWP_Backup_Retention_Scheduler::CHILD_PRUNE_RESEND_OPTION, time(), false );
		for ( $i = 0; $i < 2; ++$i ) {
			$task = $this->insert_task(
				array(
					'manual_site_id' => $this->site_id + $i,
					'template'       => 1,
					'schedule'       => 'manual',
				)
			);
			$this->insert_snapshot_with_dir( $task );
			$this->insert_snapshot_with_dir( $task );
		}
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$expire   = static function ( $sql ) use ( $table ) {
			if ( str_contains( $sql, 'SELECT * FROM ' . $table ) && ! str_contains( $sql, ' WHERE ' ) ) {
				MainWP_Backup_Now_Continuation_Test::$fixed_microtime += 121.0;
			}
			return $sql;
		};
		$messages = array();
		$log      = static function ( $message ) use ( &$messages ) {
			$messages[] = $message;
		};
		MainWP_Backup_Now_Continuation_Test::$fixed_microtime = 1000.0;
		add_filter( 'query', $expire );
		add_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
		add_action( 'mainwp_log_info', $log );
		try {
			MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
			$this->assertCount( 1, $messages, 'Preview must yield before another deleted task once the daily deadline expires.' );
			$this->assertStringContainsString( '1 candidate', $messages[0], 'The expired executor must report one candidate, then yield.' );
			$this->assertFalse( get_option( 'mainwp_backup_deleted_task_cursor' ) );
			foreach ( $this->fixture_dirs as $dir ) {
				$this->assertFileExists( $dir . '/artifact.tar' );
			}
		} finally {
			remove_filter( 'query', $expire );
			remove_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
			remove_action( 'mainwp_log_info', $log );
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
		}
	}

	/** Expired sweeps rotate tasks and retain owners for skipped tombstone cleanup. */
	public function test_deleted_task_expired_sweeps_rotate_owners_with_pending_tombstones() {
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( MainWP_Backup_Retention_Scheduler::CHILD_PRUNE_RESEND_OPTION, time(), false );
		$first  = $this->insert_task(
			array(
				'manual_site_id' => $this->site_id,
				'template'       => 1,
				'schedule'       => 'manual',
			)
		);
		$second = $this->insert_task(
			array(
				'manual_site_id' => $this->site_id + 1,
				'template'       => 1,
				'schedule'       => 'manual',
			)
		);
		$this->insert_snapshot_with_dir( $first, array( 'status' => 'pruned' ) );
		$this->insert_snapshot_with_dir( $first, array( 'status' => 'pruned' ) );
		$other      = $this->insert_snapshot_with_dir( $second );
		$task_table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$queries    = 0;
		$expire     = static function ( $sql ) use ( $task_table, &$queries ) {
			if ( ++$queries > 500 ) {
				throw new \RuntimeException( 'Deleted-site sweep exceeded 500 queries without yielding.' );
			}
			if ( str_contains( $sql, 'SELECT * FROM ' . $task_table ) && ! str_contains( $sql, ' WHERE ' ) ) {
				MainWP_Backup_Now_Continuation_Test::$fixed_microtime += 121.0;
			}
			return $sql;
		};
		$purged     = array();
		$listener   = static function ( $task ) use ( &$purged ) {
			$purged[] = $task;
		};
		MainWP_Backup_Now_Continuation_Test::$fixed_microtime = 1000.0;
		add_filter( 'query', $expire );
		add_action( 'mainwp_backup_task_snapshots_purged', $listener );
		try {
			$scheduler = MainWP_Backup_Retention_Scheduler::instance();
			$scheduler->cron_backups_prune();
			$this->assertFileDoesNotExist( $this->fixture_dirs[0] . '/artifact.tar' );
			$this->assertFileExists( $this->fixture_dirs[1] . '/artifact.tar' );
			$this->assertFileExists( $this->fixture_dirs[2] . '/artifact.tar' );
			$this->assertNotNull( \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $first ) );
			$this->assertSame( array(), $purged );
			$queries = 0;
			$scheduler->cron_backups_prune();
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $other )->status );
			$this->assertNull( \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $second ) );
			$this->assertFileExists( $this->fixture_dirs[1] . '/artifact.tar' );
			$queries = 0;
			$scheduler->cron_backups_prune();
			$this->assertNull( \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $first ) );
			$this->assertFileDoesNotExist( $this->fixture_dirs[1] . '/artifact.tar' );
			$this->assertSame( array( $second, $first ), $purged );
			$this->assertSame( array(), get_option( 'mainwp_backup_deleted_task_cursor' )['tasks'] );
		} finally {
			remove_filter( 'query', $expire );
			remove_action( 'mainwp_backup_task_snapshots_purged', $listener );
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
			delete_option( 'mainwp_backup_deleted_task_cursor' );
		}
	}

	/** A failed traversal save keeps the task and diagnostics retryable. */
	public function test_deleted_task_cursor_save_failure_retains_owner() {
		$task   = $this->insert_task(
			array(
				'manual_site_id' => $this->site_id,
				'template'       => 1,
				'schedule'       => 'manual',
			)
		);
		$id     = $this->insert_snapshot_with_dir( $task );
		$refuse = static function ( $value, $old ) {
			return $old;
		};
		update_option(
			'mainwp_backup_deleted_task_cursor',
			array(
				'tasks'     => array(),
				'last_task' => 0,
			),
			false
		);
		add_filter( 'pre_update_option_mainwp_backup_deleted_task_cursor', $refuse, 10, 2 );
		try {
			$handler = \MainWP\Dashboard\MainWP_Post_Backup_Handler::instance();
			$this->assertFalse(
				$handler->hook_delete_manual_backup_task(
					(object) array(
						'id'   => $this->site_id,
						'name' => '',
						'url'  => '',
					),
					0.0
				)
			);
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
			$this->assertNotNull( \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $task ) );
			$this->assertSame( 0, get_option( 'mainwp_backup_deleted_task_cursor' )['last_task'] );
			remove_filter( 'pre_update_option_mainwp_backup_deleted_task_cursor', $refuse );
			$this->assertTrue(
				$handler->hook_delete_manual_backup_task(
					(object) array(
						'id'   => $this->site_id,
						'name' => '',
						'url'  => '',
					),
					0.0
				)
			);
			$this->assertNull( \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $task ) );
		} finally {
			remove_filter( 'pre_update_option_mainwp_backup_deleted_task_cursor', $refuse );
			delete_option( 'mainwp_backup_deleted_task_cursor' );
		}
	}

	/**
	 * Eviction and query time both reduce the prune budget.
	 *
	 * @dataProvider shared_budget_cases
	 * @param float $eviction_seconds Eviction elapsed time.
	 * @param float $query_seconds Query elapsed time.
	 * @param int   $remaining Expected executor budget, zero when exhausted.
	 */
	public function test_pair_shares_budget_without_rounding_up( $eviction_seconds, $query_seconds, $remaining ) {
		$executor_class    = \MainWP\Dashboard\MainWP_Backup_Retention_Executor::class;
		$query_class       = \MainWP\Dashboard\MainWP_Backup_Retention_Query::class;
		$executor_slot     = new \ReflectionProperty( $executor_class, 'instance' );
		$query_slot        = new \ReflectionProperty( $query_class, 'instance' );
		$original_executor = $executor_slot->getValue();
		$original_query    = $query_slot->getValue();
		$executor          = $this->getMockBuilder( $executor_class )->disableOriginalConstructor()->onlyMethods( array( 'evict_local_archives_for_pair', 'run_prune' ) )->getMock();
		$query             = $this->getMockBuilder( $query_class )->disableOriginalConstructor()->onlyMethods( array( 'get_units_for_site_task' ) )->getMock();
		MainWP_Backup_Now_Continuation_Test::$fixed_microtime = 1000.0;
		$executor->expects( $this->once() )->method( 'evict_local_archives_for_pair' )->with( 12, 34, 10 )->willReturnCallback(
			static function () use ( $eviction_seconds ) {
				MainWP_Backup_Now_Continuation_Test::$fixed_microtime += $eviction_seconds;
				return array(
					'evicted_ids' => array(),
					'held'        => 0,
					'errors'      => array(),
				);
			}
		);
		$query->expects( $this->once() )->method( 'get_units_for_site_task' )->willReturnCallback(
			static function () use ( $query_seconds ) {
				MainWP_Backup_Now_Continuation_Test::$fixed_microtime += $query_seconds;
				return array(
					array(
						'snapshots'      => array( (object) array( 'id' => 1 ) ),
						'is_complete'    => true,
						'is_restorable'  => true,
						'newest_created' => 100,
						'size_bytes'     => 1,
					),
					array(
						'snapshots'      => array( (object) array( 'id' => 2 ) ),
						'is_complete'    => true,
						'is_restorable'  => true,
						'newest_created' => 200,
						'size_bytes'     => 1,
					),
				);
			}
		);
		$executor->expects( $this->once() )->method( 'run_prune' )->with( $this->anything(), $this->anything(), false, $remaining )->willReturn(
			array(
				'errors'  => array(),
				'skipped' => 0,
			)
		);
		$executor_slot->setValue( null, $executor );
		$query_slot->setValue( null, $query );
		try {
			$task   = (object) array(
				'id'                   => 34,
				'retention_count'      => 1,
				'retention_days'       => 0,
				'retention_storage_mb' => 0,
				'template'             => 0,
			);
			$report = MainWP_Backup_Retention_Scheduler::instance()->run_retention_for_pair( $task, 12, 10 );
			$this->assertSame( 0, $report['skipped'] );
		} finally {
			$executor_slot->setValue( null, $original_executor );
			$query_slot->setValue( null, $original_query );
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
		}
	}

	/**
	 * Elapsed times and the remaining whole seconds.
	 *
	 * @return array
	 */
	public static function shared_budget_cases() {
		return array(
			'eviction and query'       => array( 2.25, 1.5, 6 ),
			'fractional remainder'     => array( 2.25, 7.25, 0 ),
			'query exhausts budget'    => array( 2.0, 8.5, 0 ),
			'eviction exhausts budget' => array( 10.5, 0.25, 0 ),
		);
	}

	/** Exhausted preparation budgets still prune one real candidate each pass. */
	public function test_exhausted_pair_budget_keeps_pruning_across_passes() {
		$task_id           = $this->insert_task( array( 'retention_count' => 1 ) );
		$old               = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 300 ) );
		$middle            = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 200 ) );
		$newest            = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 100 ) );
		$task              = \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
		$slot              = new \ReflectionProperty( \MainWP\Dashboard\MainWP_Backup_Retention_Executor::class, 'instance' );
		$original_executor = $slot->getValue();
		$executor          = $this->getMockBuilder( \MainWP\Dashboard\MainWP_Backup_Retention_Executor::class )->disableOriginalConstructor()->onlyMethods( array( 'evict_local_archives_for_pair' ) )->getMock();
		$executor->expects( $this->exactly( 2 ) )->method( 'evict_local_archives_for_pair' )->willReturnCallback(
			static function () {
				MainWP_Backup_Now_Continuation_Test::$fixed_microtime += 11.0;
				return array(
					'evicted_ids' => array(),
					'held'        => 0,
					'errors'      => array(),
				);
			}
		);
		$slot->setValue( null, $executor );
		MainWP_Backup_Now_Continuation_Test::$fixed_microtime = 1000.0;
		try {
			$scheduler = MainWP_Backup_Retention_Scheduler::instance();
			$first     = $scheduler->run_retention_for_pair( $task, $this->site_id, 10 );
			$second    = $scheduler->run_retention_for_pair( $task, $this->site_id, 10 );
			$this->assertSame( array( 1, 1 ), array( $first['pruned'], $second['pruned'] ), 'Both passes must prune despite recurring preparation overrun.' );
			$this->assertSame( array( $old ), $first['deleted_ids'] );
			$this->assertSame( array( $middle ), $first['remaining_ids'], 'The zero remaining budget must stop before a second candidate.' );
			$this->assertSame( 1, $first['skipped'] );
			$this->assertSame( array( $middle ), $second['deleted_ids'] );
			$this->assertSame( array(), $second['remaining_ids'] );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $middle )->status );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $newest )->status );
			$this->assertFileDoesNotExist( $this->fixture_dirs[0] . '/artifact.tar' );
			$this->assertFileDoesNotExist( $this->fixture_dirs[1] . '/artifact.tar' );
			$this->assertFileExists( $this->fixture_dirs[2] . '/artifact.tar' );
		} finally {
			$slot->setValue( null, $original_executor );
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
		}
	}

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
	 * Hidden policies follow settings while scheduled policies retain their count.
	 *
	 * @dataProvider task_count_cases
	 * @param int $template Hidden-row flag.
	 * @param int $pruned Expected oldest units removed.
	 */
	public function test_daily_prune_uses_live_count_only_for_hidden_tasks( $template, $pruned ) {
		update_option( 'mainwp_on_demand_retention_count', 5 );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$task_id = $this->insert_task(
			array(
				'template'        => $template,
				'retention_count' => 25,
			)
		);
		$ids     = array();
		for ( $index = 0; $index < 7; ++$index ) {
			$ids[] = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 700 + 100 * $index ) );
		}
		MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
		foreach ( $ids as $index => $id ) {
			$this->assertSame( $index < $pruned ? 'pruned' : 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		}
		delete_option( 'mainwp_on_demand_retention_count' );
	}

	/**
	 * Task policy owners.
	 *
	 * @return array
	 */
	public static function task_count_cases() {
		return array(
			'hidden'    => array( 1, 2 ),
			'scheduled' => array( 0, 0 ),
		);
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
		$old     = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 300 ) );
		$this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 200 ) );
		$new = $this->insert_snapshot_with_dir( $task_id, array( 'created' => time() - 100 ) );

		$scheduler = MainWP_Backup_Retention_Scheduler::instance();
		$scheduler->on_snapshot_recorded( $new, $task_id, $this->site_id );

		// A zero budget prunes one candidate and defers the rest until the next tick.
		$scheduler->process_post_snapshot_queue( 0 );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );

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
	/** Daily backstop retries held archive eviction even when every retention cap is zero. */
	public function test_unlimited_remote_only_task_daily_backstop_retries_eviction() {
		$destination = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Unlimited retention fixture',
				'settings' => array(),
			)
		);
		$this->assertTrue( class_exists( '\MainWP\Dashboard\MainWP_Backup_Retention_Protection' ) );
		$protection = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		update_option( 'mainwp_enableNativeBackups', 1 );
		$task = $this->insert_task(
			array(
				'retention_count'      => 0,
				'retention_days'       => 0,
				'retention_storage_mb' => 0,
			)
		);
		$protection::save_task_remove_local_archives( $task, true );
		$id = $this->insert_snapshot_with_dir( $task );
		$protection::capture_required_destinations( $id, array( $destination ) );
		$store = \MainWP\Dashboard\MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'provider'   => 's3',
				'status'     => 'complete',
				'checkpoint' => wp_json_encode(
					array(
						'uploaded_at'         => time(),
						'manifest_submitted'  => true,
						'destination_binding' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->get_destination( $destination ) ),
						'artifacts'           => array(
							array(
								'file'   => 'artifact.tar',
								'role'   => 'full',
								'size'   => 100,
								'sha256' => hash( 'sha256', str_repeat( 'x', 100 ) ),
								'mode'   => 'whole',
								'done'   => true,
							),
						),
					)
				),
			),
			array( 'snapshot_id' => $id )
		);
		$path        = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->archives[0]['path'];
		$restores    = \MainWP\Dashboard\MainWP_Backup_Restore_Job_Store::instance();
		$reservation = $restores->reserve_site( $this->site_id );
		$this->assertGreaterThan( 0, $reservation );
		$scheduler = MainWP_Backup_Retention_Scheduler::instance();
		$scheduler->on_snapshot_recorded( $id, $task, $this->site_id );
		$scheduler->process_post_snapshot_queue();
		$this->assertFileExists( $path );
		$restores->release_reservation( $reservation );
		delete_option( MainWP_Backup_Retention_Scheduler::QUEUE_OPTION );
		$scheduler->cron_backups_prune();
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
	}
}
