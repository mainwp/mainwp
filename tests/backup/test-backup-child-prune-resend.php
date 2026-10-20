<?php
/**
 * Dashboard pruning can finish while the Child still owns the backup.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Retention_Scheduler;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

/** Class MainWP_Backup_Child_Prune_Resend_Test. */
class MainWP_Backup_Child_Prune_Resend_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;

	/** Fixture site.
	 *
	 * @var int
	 */
	private $site_id;
	/** Fixture snapshots.
	 *
	 * @var int[]
	 */
	private $ids = array();
	/** Fixture tasks.
	 *
	 * @var int[]
	 */
	private $task_ids = array();
	/** Captured prune requests.
	 *
	 * @var array
	 */
	private $requests = array();
	/** Previous repair marker.
	 *
	 * @var mixed
	 */
	private $pending;

	/** Fixtures must not inherit an unrelated deferred repair. */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$this->pending = get_site_option( MainWP_Install::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING );
		delete_site_option( MainWP_Install::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING );
		$this->site_id = $this->create_test_site();
	}

	/** Failed assertions must not leave owed rows for another test's cron. */
	public function tear_down() {
		global $wpdb;
		foreach ( $this->ids as $id ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'id' => $id ) );
		}
		foreach ( $this->task_ids as $id ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $id ) );
		}
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'site_id' => $this->site_id ) );
		if ( false === $this->pending ) {
			delete_site_option( MainWP_Install::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING );
		} else {
			update_site_option( MainWP_Install::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING, $this->pending );
		}
		parent::tear_down();
	}

	/**
	 * Run identities must agree with their row's site and task.
	 *
	 * @param string $child Child task identity.
	 * @param array  $fields Row overrides.
	 * @return int Snapshot identity.
	 */
	private function snapshot( string $child, array $fields = array() ): int {
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array_merge(
					array(
						'site_id'  => $this->site_id,
						'task_id'  => 998191,
						'type'     => 'full',
						'created'  => time(),
						'archives' => '[]',
						'status'   => 'pruned',
						'run_key'  => $this->site_id . ':998191:c:' . $child,
					),
					$fields
				)
			)
		);
		$id          = (int) $wpdb->insert_id;
		$this->ids[] = $id;
		return $id;
	}

	/**
	 * Counts can arrive as integers or digit strings.
	 *
	 * @param int $pruned Pruned count.
	 * @param int $missing Missing count.
	 * @return array Child response.
	 */
	private function answer( int $pruned = 1, int $missing = 0 ): array {
		return array(
			'result'  => 'ok',
			'pruned'  => $pruned,
			'active'  => 0,
			'missing' => $missing,
		);
	}

	/**
	 * Retention always protects the newest complete unit, so cron needs two.
	 *
	 * @return int[] Old and new snapshots.
	 */
	private function retention_pair(): array {
		$task             = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Child prune cron test', 'daily', 'full', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->task_ids[] = (int) $task->id;
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'retention_count' => 1 ) );
		$ids = array();
		$now = time();
		foreach ( array(
			'old' => 200,
			'new' => 100,
		) as $child => $age ) {
			$ids[] = $this->snapshot(
				$child,
				array(
					'task_id' => (int) $task->id,
					'run_key' => $this->site_id . ':' . (int) $task->id . ':c:' . $child,
					'status'  => 'complete',
					'created' => $now - $age,
				)
			);
		}
		return $ids;
	}

	/**
	 * Keep mocked transport scoped even when a send or assertion throws.
	 *
	 * @param callable $answer Child response callback.
	 * @param callable $work Test operation.
	 * @return mixed Operation result.
	 */
	private function with_transport( callable $answer, callable $work ) {
		$filter = function ( $pre, $website, $what, $params ) use ( $answer ) {
			if ( 'backup_task_prune' !== $what ) {
				return $pre;
			}
			$this->requests[] = array(
				'site_id' => (int) $website->id,
				'params'  => $params,
			);
			return $answer( $website, $params );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $filter, 10, 4 );
		try {
			return $work();
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $filter, 10 );
		}
	}

	/**
	 * Assert persisted state rather than only the sender's report.
	 *
	 * @param int  $id Snapshot identity.
	 * @param bool $confirmed Expected confirmation.
	 * @param int  $attempts Expected failure count.
	 */
	private function assert_state( int $id, bool $confirmed, int $attempts ): void {
		$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( $confirmed, (int) $row->child_prune_confirmed_at > 0 );
		$this->assertSame( $attempts, (int) $row->child_prune_attempts );
	}

	/** A single confirmed task needs no further daily request. */
	public function test_resend_confirms_pruned_task(): void {
		$id     = $this->snapshot( 'one', array( 'child_prune_attempts' => 2 ) );
		$result = $this->with_transport(
			function () {
				return $this->answer();
			},
			function () {
				return MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
			}
		);
		$this->assertSame(
			array(
				'sent'      => 1,
				'confirmed' => 1,
				'failed'    => 0,
				'gave_up'   => 0,
				'skipped'   => 0,
			),
			$result
		);
		$this->assertSame(
			array(
				array(
					'site_id' => $this->site_id,
					'params'  => array(
						'task_ids' => '["one"]',
						'sweep'    => 0,
					),
				),
			),
			$this->requests
		);
		$this->assert_state( $id, true, 2 );
	}

	/** The Child may never have held the task or may already have deleted it. */
	public function test_resend_confirms_missing_task(): void {
		$id = $this->snapshot( 'missing' );
		$this->with_transport(
			function () {
				return $this->answer( 0, 1 );
			},
			function () {
				MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
			}
		);
		$this->assert_state( $id, true, 0 );
		$this->assertTrue(
			MainWP_Backup_Executor::child_prune_confirmed(
				array(
					'result'  => 'ok',
					'pruned'  => '0',
					'missing' => '1',
					'active'  => '0',
				)
			)
		);
	}

	/**
	 * Older Children, failures and unreadable counts all leave cleanup owed.
	 *
	 * @return array Response cases.
	 */
	public static function failure_answers(): array {
		$ok = array(
			'result'  => 'ok',
			'pruned'  => 1,
			'active'  => 0,
			'missing' => 0,
		);
		return array(
			'unreachable'  => array( 'throw' ),
			'error'        => array( array_merge( $ok, array( 'error' => '' ) ) ),
			'older child'  => array( array( 'error' => 'UNKNOWN_CALLABLE' ) ),
			'float'        => array( array_merge( $ok, array( 'pruned' => 1.0 ) ) ),
			'negative'     => array( array_merge( $ok, array( 'pruned' => -1 ) ) ),
			'non digit'    => array( array_merge( $ok, array( 'missing' => '0x0' ) ) ),
			'absent count' => array(
				array(
					'result' => 'ok',
					'pruned' => 1,
					'active' => 0,
				),
			),
		);
	}

	/**
	 * Unsent rows must not keep an unreachable site ahead of fresh sites tomorrow.
	 *
	 * @dataProvider failure_answers
	 * @param mixed $answer Child response or transport failure.
	 */
	public function test_failure_retries_once_per_site( $answer ): void {
		global $wpdb;
		$first  = $this->snapshot( 'first' );
		$second = $this->snapshot( 'second' );
		$this->with_transport(
			static function () use ( $answer ) {
				if ( is_string( $answer ) ) {
					throw new \RuntimeException( 'Child unreachable' );
				}
				return $answer;
			},
			function () use ( $first, $second ) {
				$result = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
				$this->assertSame(
					array(
						'sent'      => 1,
						'confirmed' => 0,
						'failed'    => 1,
						'gave_up'   => 0,
						'skipped'   => 1,
					),
					$result
				);
				$this->assert_state( $first, false, 1 );
				$this->assert_state( $second, false, 1 );
			}
		);
		$this->assertCount( 1, $this->requests );
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () {
				$this->assertSame( 2, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['confirmed'] );
			}
		);
		$this->assertSame( '["first"]', $this->requests[0]['params']['task_ids'] );
		$this->assertSame( '["first"]', $this->requests[1]['params']['task_ids'] );
		$this->assertSame( '["second"]', $this->requests[2]['params']['task_ids'] );
		$this->assert_state( $first, true, 1 );
		$this->assert_state( $second, true, 1 );
	}

	/** A failed site must fall behind fresh sites even when its rows exceed the batch. */
	public function test_failed_site_does_not_fill_next_batch(): void {
		$owed = array();
		for ( $i = 0; $i < 3; ++$i ) {
			$owed[] = $this->snapshot( 'unreachable-' . $i );
		}
		$site = $this->create_test_site();
		$id   = $this->snapshot(
			'reachable',
			array(
				'site_id' => $site,
				'run_key' => $site . ':998191:c:reachable',
			)
		);
		$this->with_transport(
			function ( $website ) {
				return (int) $website->id === $this->site_id ? array( 'error' => 'offline' ) : $this->answer();
			},
			function () use ( $owed, $id ) {
				$this->assertSame( 1, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes( 2 )['sent'] );
				foreach ( $owed as $snapshot_id ) {
					$this->assert_state( $snapshot_id, false, 1 );
				}
				$this->assert_state( $id, false, 0 );
				$this->assertSame( 1, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes( 1 )['confirmed'] );
			}
		);
		$this->assertCount( 2, $this->requests );
		$this->assertSame( $this->site_id, $this->requests[0]['site_id'] );
		$this->assertSame( $site, $this->requests[1]['site_id'] );
		$this->assert_state( $id, true, 0 );
	}

	/** A failed site's connect timeout must not consume the next day's fresh site's budget. */
	public function test_failed_site_does_not_consume_next_run_budget(): void {
		$first  = $this->snapshot( 'slow-a' );
		$second = $this->snapshot( 'slow-b' );
		$site   = $this->create_test_site();
		$id     = $this->snapshot(
			'fresh',
			array(
				'site_id' => $site,
				'run_key' => $site . ':998191:c:fresh',
			)
		);
		$this->with_transport(
			function ( $website ) {
				if ( (int) $website->id === $this->site_id ) {
					usleep( 1100000 );
					return array( 'error' => 'timeout' );
				}
				return $this->answer();
			},
			function () use ( $first, $second, $id ) {
				$this->assertSame( 1, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes( 50, 1 )['sent'] );
				$this->assert_state( $first, false, 1 );
				$this->assert_state( $second, false, 1 );
				$this->assert_state( $id, false, 0 );
				$this->assertSame( 1, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes( 1, 1 )['confirmed'] );
			}
		);
		$this->assertCount( 2, $this->requests );
		$this->assertSame( $site, $this->requests[1]['site_id'] );
		$this->assert_state( $id, true, 0 );
	}

	/** A site pass must preserve confirmed, live and already capped rows. */
	public function test_site_attempt_updates_only_owed_rows(): void {
		$first     = $this->snapshot( 'owed' );
		$near_cap  = $this->snapshot( 'near-cap', array( 'child_prune_attempts' => 9 ) );
		$complete  = $this->snapshot( 'complete', array( 'status' => 'complete' ) );
		$confirmed = $this->snapshot( 'confirmed', array( 'child_prune_confirmed_at' => 123 ) );
		$capped    = $this->snapshot( 'capped', array( 'child_prune_attempts' => 10 ) );
		$site      = $this->create_test_site();
		$other     = $this->snapshot(
			'other',
			array(
				'site_id' => $site,
				'run_key' => $site . ':998191:c:other',
			)
		);
		$this->assertSame( 2, MainWP_Backup_Snapshots::instance()->record_child_prune_site_attempt( $this->site_id, 10 ) );
		$this->assert_state( $first, false, 1 );
		$this->assert_state( $near_cap, false, 10 );
		$this->assert_state( $complete, false, 0 );
		$this->assert_state( $confirmed, true, 0 );
		$this->assert_state( $capped, false, 10 );
		$this->assert_state( $other, false, 0 );
	}

	/** Rows that reach the cap in one site pass must leave one counted warning. */
	public function test_attempt_cap_warns_and_stops_selection(): void {
		$id     = $this->snapshot( 'capped', array( 'child_prune_attempts' => MainWP_Backup_Retention_Executor::CHILD_PRUNE_MAX_ATTEMPTS - 1 ) );
		$second = $this->snapshot( 'capped-outside-batch', array( 'child_prune_attempts' => MainWP_Backup_Retention_Executor::CHILD_PRUNE_MAX_ATTEMPTS - 1 ) );
		$this->snapshot( 'already-capped', array( 'child_prune_attempts' => MainWP_Backup_Retention_Executor::CHILD_PRUNE_MAX_ATTEMPTS ) );
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$saved    = $priority->getValue( $logger );
		$warnings = array();
		$capture  = static function ( $text, $level ) use ( &$warnings ) {
			if ( MainWP_Logger::WARNING === $level ) {
				$warnings[] = $text;
			}
		};
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture, 10, 2 );
		try {
			$this->with_transport(
				static function () {
					return array( 'error' => 'offline' );
				},
				function () {
					$this->assertSame( 1, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes( 1 )['failed'] );
					$this->assertSame( 0, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['sent'] );
				}
			);
			$this->assertCount( 1, $warnings );
			$this->assertCount( 1, $this->requests );
			$this->assertStringContainsString( 'site ' . $this->site_id, $warnings[0] );
			$this->assertStringContainsString( 'Retry cap reached by 2 rows', $warnings[0] );
			$this->assertStringContainsString( 'attempts: 10', $warnings[0] );
			$this->assert_state( $id, false, MainWP_Backup_Retention_Executor::CHILD_PRUNE_MAX_ATTEMPTS );
			$this->assert_state( $second, false, MainWP_Backup_Retention_Executor::CHILD_PRUNE_MAX_ATTEMPTS );
		} finally {
			remove_action( 'mainwp_before_log_data', $capture, 10 );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $saved );
		}
	}

	/** A failed marker write must remain safe to replay after Child deletion. */
	public function test_success_before_marker_crash_replays_missing_once(): void {
		$id    = $this->snapshot( 'crashed' );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$crash = static function ( $sql ) use ( $table ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` SET child_prune_confirmed_at' ) ) {
				throw new \RuntimeException( 'marker crash' );
			}
			return $sql;
		};
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () use ( $crash ) {
				add_filter( 'query', $crash );
				try {
					MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
					$this->fail( 'The marker write should throw.' );
				} catch ( \RuntimeException $e ) {
					$this->assertSame( 'marker crash', $e->getMessage() );
				} finally {
					remove_filter( 'query', $crash );
				}
			}
		);
		$this->assert_state( $id, false, 0 );
		$this->with_transport(
			function () {
				return $this->answer( 0, 1 );
			},
			function () {
				$this->assertSame( 1, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['confirmed'] );
				$this->assertSame( 0, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['sent'] );
			}
		);
		$this->assert_state( $id, true, 0 );
		$this->assertCount( 2, $this->requests );
	}

	/** Expired or interleaved writers cannot overwrite confirmation or lose increments. */
	public function test_overlapping_writers_preserve_confirmation(): void {
		global $wpdb;
		$id    = $this->snapshot( 'overlap' );
		$store = MainWP_Backup_Snapshots::instance();
		$this->assertSame( 1, $store->record_child_prune_site_attempt( $this->site_id, 10 ) );
		$this->assertSame( 1, $store->record_child_prune_site_attempt( $this->site_id, 10 ) );
		$notify = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'notify_child_prune' );
		$notify->setAccessible( true );
		$this->with_transport(
			function ( $website, $params ) use ( $id, $notify ) {
				if ( 1 === $params['sweep'] ) {
					return $this->answer();
				}
				$notify->invoke( MainWP_Backup_Retention_Executor::instance(), $this->site_id, array( $id => 'overlap' ) );
				return array( 'error' => 'late failure' );
			},
			function () {
				$result = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
				$this->assertSame( 0, $result['failed'] );
			}
		);
		$this->assert_state( $id, true, 2 );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'child_prune_confirmed_at' => 123 ), array( 'id' => $id ) );
		$first = 123;
		$this->assertSame( 0, $store->mark_child_prune_confirmed( array( $id ) ) );
		$this->assertSame( 0, $store->record_child_prune_site_attempt( $this->site_id, 10 ) );
		$store->give_up_child_prune( $id, 10 );
		$this->assertSame( $first, (int) $store->get_snapshot_by_id( $id )->child_prune_confirmed_at );
		$this->assert_state( $id, true, 2 );
		$this->assertCount( 2, $this->requests );
	}

	/** Historical or mismatched identities cannot identify a Child task safely. */
	public function test_run_key_shapes_and_live_identity_block_send(): void {
		$legacy = $this->snapshot( 'legacy', array( 'run_key' => $this->site_id . ':998191:l:legacy' ) );
		$null   = $this->snapshot( 'null', array( 'run_key' => null ) );
		$wrong  = $this->snapshot( 'wrong', array( 'run_key' => '0:0:c:wrong' ) );
		$owed   = $this->snapshot( 'shared' );
		$live   = $this->snapshot(
			'shared',
			array(
				'status'  => 'complete',
				'task_id' => 998192,
				'run_key' => $this->site_id . ':998192:c:shared',
			)
		);
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () use ( $owed ) {
				$rows = MainWP_Backup_Snapshots::instance()->get_child_prune_candidates( 50, 10 );
				$this->assertSame( array( $owed ), array_map( 'intval', array_column( $rows, 'id' ) ) );
				$this->assertSame( 1, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['failed'] );
			}
		);
		$this->assertSame( array(), $this->requests );
		$this->assert_state( $owed, false, 1 );
		foreach ( array( $legacy, $null, $wrong ) as $id ) {
			$this->assert_state( $id, false, 0 );
		}
		$this->assert_state( $live, false, 0 );
	}

	/** A Child that keeps one task must not cap siblings that were never sent. */
	public function test_child_keeping_one_task_charges_only_that_row(): void {
		$kept  = $this->snapshot( 'kept' );
		$after = $this->snapshot( 'after' );
		$this->with_transport(
			function ( $website, $params ) {
				if ( '["kept"]' === $params['task_ids'] ) {
					return array(
						'result'  => 'ok',
						'pruned'  => 0,
						'active'  => '1',
						'missing' => 0,
					);
				}
				return $this->answer();
			},
			function () {
				$result = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
				$this->assertSame( 2, $result['sent'] );
				$this->assertSame( 1, $result['confirmed'] );
				$this->assertSame( 1, $result['failed'] );
				$this->assertSame( 0, $result['skipped'] );
			}
		);
		$this->assert_state( $kept, false, 1 );
		$this->assert_state( $after, true, 0 );
	}

	/** The resend calls Childs that already failed once, so one hung request must not hold the cron for hours. */
	public function test_resend_caps_the_request_timeout_only_while_sending(): void {
		$this->snapshot( 'timeout' );
		$seen = array();
		$this->with_transport(
			function () use ( &$seen ) {
				$seen[] = (int) apply_filters( 'mainwp_fetch_url_site_timeout', 72000, 'backup_task_prune' );
				$seen[] = (int) apply_filters( 'mainwp_fetch_url_site_timeout', 72000, 'sync' );
				return $this->answer();
			},
			function () {
				$this->assertSame( 1, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['confirmed'] );
			}
		);
		$this->assertSame( array( MainWP_Backup_Retention_Executor::CHILD_PRUNE_RESEND_TIMEOUT_SECONDS, 72000 ), $seen );
		$this->assertSame( 72000, (int) apply_filters( 'mainwp_fetch_url_site_timeout', 72000, 'backup_task_prune' ) );
	}

	/** A held site must not be aged by an overlapping run, even when its lookup fails. */
	public function test_held_site_with_failed_lookup_records_no_attempt(): void {
		global $wpdb;
		$id    = $this->snapshot( 'held-lookup' );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$hold  = $store->reserve_site( $this->site_id );
		$this->assertGreaterThan( 0, $hold );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'wpid' => $this->site_id ) );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
			$this->assertSame( 1, $result['skipped'] );
			$this->assertSame( 0, $result['failed'] );
		} finally {
			$store->release_reservation( $hold );
		}
		$this->assert_state( $id, false, 0 );
	}

	/**
	 * Preserve the normal prune's unit and request shape.
	 *
	 * @param int[] $ids Snapshot identities, base first.
	 */
	private function normal_prune( array $ids ): void {
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
			array(
				array(
					'snapshot_ids' => $ids,
					'reason'       => 'count',
				),
			),
			(object) array(
				'id'                  => 998191,
				'delete_cloud_copies' => 0,
			)
		);
		$this->assertSame( array_reverse( $ids ), $result['deleted_ids'] );
	}

	/** Aggregate confirmation must account for every distinct target before any marker changes. */
	public function test_normal_multi_task_confirmation_is_all_or_none(): void {
		foreach ( array( 'partial', 'wrong count', 'full', 'deduplicated' ) as $case ) {
			$base = $this->snapshot( $case . '-a', array( 'status' => 'complete' ) );
			$leaf = $this->snapshot(
				$case . '-b',
				array(
					'status'    => 'complete',
					'parent_id' => $base,
					'type'      => 'incremental',
				)
			);
			if ( 'deduplicated' === $case ) {
				global $wpdb;
				$wpdb->update(
					MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
					array(
						'task_id' => 998192,
						'run_key' => $this->site_id . ':998192:c:' . $case . '-a',
					),
					array( 'id' => $leaf )
				);
			}
			$this->with_transport(
				function () use ( $case ) {
					if ( 'partial' === $case ) {
						return array(
							'result'  => 'ok',
							'pruned'  => 1,
							'active'  => 1,
							'missing' => 0,
						);
					}
					return $this->answer( 'full' === $case ? 2 : 1 );
				},
				function () use ( $base, $leaf ) {
					$this->normal_prune( array( $base, $leaf ) );
				}
			);
			$confirmed = in_array( $case, array( 'full', 'deduplicated' ), true );
			$this->assert_state( $base, $confirmed, 0 );
			$this->assert_state( $leaf, $confirmed, 0 );
			$this->assertSame( 1, end( $this->requests )['params']['sweep'] );
		}
		$this->assertCount( 4, $this->requests );
		$this->assertSame( '["full-b","full-a"]', $this->requests[2]['params']['task_ids'] );
		$this->assertSame( '["deduplicated-a"]', $this->requests[3]['params']['task_ids'] );
		$this->assertFalse( MainWP_Backup_Executor::child_prune_confirmed( $this->answer(), 0 ) );
		$this->assertFalse( MainWP_Backup_Executor::child_prune_confirmed( $this->answer(), -1 ) );
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () {
				$this->assertSame( 4, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['confirmed'] );
			}
		);
	}

	/** Dashboard pruning must finish even when its immediate Child notification throws. */
	public function test_normal_prune_keeps_request_shape_when_unreachable(): void {
		$id = $this->snapshot( 'normal', array( 'status' => 'complete' ) );
		$this->with_transport(
			static function () {
				throw new \RuntimeException( 'offline' );
			},
			function () use ( $id ) {
				$this->normal_prune( array( $id ) );
			}
		);
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assert_state( $id, false, 0 );
		$this->assertSame(
			array(
				'task_ids' => '["normal"]',
				'sweep'    => 1,
			),
			$this->requests[0]['params']
		);
	}

	/** Known backup work must not spend cleanup attempts waiting for the Child mutex. */
	public function test_running_backup_skips_site_without_attempt(): void {
		$first  = $this->snapshot( 'waiting-a' );
		$second = $this->snapshot( 'waiting-b' );
		$option = MainWP_Backup_Executor::BACKUP_HOLD_OPTION_PREFIX . $this->site_id;
		update_option( $option, wp_json_encode( array( 'test-backup' => time() + 600 ) ) );
		try {
			$this->with_transport(
				function () {
					return $this->answer();
				},
				function () {
					$result = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
					$this->assertSame( 2, $result['skipped'] );
					$this->assertSame( 0, $result['sent'] );
					$this->assertSame( 0, $result['failed'] );
				}
			);
			$this->assertSame( array(), $this->requests );
			$this->assert_state( $first, false, 0 );
			$this->assert_state( $second, false, 0 );
		} finally {
			delete_option( $option );
		}
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () {
				$this->assertSame( 2, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['confirmed'] );
			}
		);
	}

	/**
	 * Missing sync rows and failed reads are not proof that the site was deleted.
	 *
	 * @return array Lookup failures.
	 */
	public static function site_lookup_failures(): array {
		return array(
			'missing sync'   => array( 'sync' ),
			'joined read'    => array( 'joined' ),
			'existence read' => array( 'exists' ),
		);
	}

	/**
	 * Lookup failures must age all owed rows while leaving the site retryable.
	 *
	 * @dataProvider site_lookup_failures
	 * @param string $failure Failed read.
	 */
	public function test_site_lookup_failure_records_site_attempt( string $failure ): void {
		global $wpdb;
		$first  = $this->snapshot( 'lookup-a' );
		$second = $this->snapshot( 'lookup-b' );
		$wp     = MainWP_DB::instance()->get_table_name( 'wp' );
		if ( 'joined' !== $failure ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'wpid' => $this->site_id ) );
		}
		$deny     = static function ( $sql ) use ( $failure, $wp ) {
			if ( ( 'joined' === $failure && 0 === strpos( $sql, 'SELECT wp.*,wp_sync.*' ) )
				|| ( 'exists' === $failure && 0 === strpos( $sql, 'SELECT id FROM ' . $wp . ' WHERE id = ' ) ) ) {
				return 'SELECT mainwp_child_prune_lookup_forced_failure';
			}
			return $sql;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $deny );
		try {
			$this->with_transport(
				function () {
					return $this->answer();
				},
				function () {
					$result = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes( 1 );
					$this->assertSame( 0, $result['sent'] );
					$this->assertSame( 0, $result['gave_up'] );
					$this->assertSame( 1, $result['failed'] );
				}
			);
			$this->assertSame( array(), $this->requests );
			$this->assert_state( $first, false, 1 );
			$this->assert_state( $second, false, 1 );
		} finally {
			remove_filter( 'query', $deny );
			$wpdb->suppress_errors( $suppress );
			if ( 'joined' !== $failure ) {
				$this->create_test_site_sync( $this->site_id );
			}
		}
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () {
				$this->assertSame( 2, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['confirmed'] );
			}
		);
	}

	/** An unreadable ownership guard must not delete a possibly live Child task. */
	public function test_live_guard_query_error_records_site_attempt(): void {
		global $wpdb;
		$first    = $this->snapshot( 'guard-a' );
		$second   = $this->snapshot( 'guard-b' );
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$denied   = 0;
		$deny     = static function ( $sql ) use ( $table, &$denied ) {
			if ( 0 === strpos( $sql, 'SELECT id FROM ' . $table . ' WHERE site_id = ' ) && false !== strpos( $sql, "status <> 'pruned'" ) ) {
				++$denied;
				return 'SELECT mainwp_child_prune_guard_forced_failure';
			}
			return $sql;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $deny );
		try {
			$this->with_transport(
				function () {
					return $this->answer();
				},
				function () {
					$result = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes( 1 );
					$this->assertSame( 0, $result['sent'] );
					$this->assertSame( 1, $result['failed'] );
				}
			);
			$this->assertSame( 1, $denied );
			$this->assertSame( array(), $this->requests );
			$this->assert_state( $first, false, 1 );
			$this->assert_state( $second, false, 1 );
		} finally {
			remove_filter( 'query', $deny );
			$wpdb->suppress_errors( $suppress );
		}
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () {
				$this->assertSame( 2, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['confirmed'] );
			}
		);
	}

	/** A removed site has no remaining authenticated transport. */
	public function test_deleted_site_gives_up_without_request(): void {
		global $wpdb;
		$ids = array( $this->snapshot( 'gone-a' ), $this->snapshot( 'gone-b' ) );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'id' => $this->site_id ) );
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () {
				$this->assertSame( 2, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['gave_up'] );
			}
		);
		$this->assertSame( array(), $this->requests );
		foreach ( $ids as $id ) {
			$this->assert_state( $id, false, 10 );
		}
		$this->assertSame( array(), MainWP_Backup_Snapshots::instance()->get_child_prune_candidates( 50, 10 ) );
	}

	/** Restore admission and overlapping prune runs own the same site slot. */
	public function test_held_site_skips_and_processed_site_releases(): void {
		global $wpdb;
		$id    = $this->snapshot( 'held' );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		foreach ( array( $store::STATUS_RESERVED, $store::STATUS_RUNNING ) as $status ) {
			$hold = $store->reserve_site( $this->site_id );
			$this->assertGreaterThan( 0, $hold );
			try {
				$wpdb->update(
					$table,
					array(
						'status'      => $status,
						'snapshot_id' => $id,
					),
					array( 'id' => $hold )
				);
				$result = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
				$this->assertSame( 1, $result['skipped'] );
				$this->assertSame( 0, $result['sent'] );
				$this->assert_state( $id, false, 0 );
			} finally {
				$wpdb->update( $table, array( 'status' => $store::STATUS_RESERVED ), array( 'id' => $hold ) );
				$store->release_reservation( $hold );
			}
		}
		$overlap = null;
		$this->with_transport(
			function () use ( &$overlap ) {
				$overlap = MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
				throw new \RuntimeException( 'offline' );
			},
			function () {
				MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
			}
		);
		$this->assertSame( 1, $overlap['skipped'] );
		$this->assertSame( 0, $overlap['sent'] );
		$this->assertCount( 1, $this->requests );
		$hold = $store->reserve_site( $this->site_id );
		try {
			$this->assertGreaterThan( 0, $hold );
		} finally {
			$store->release_reservation( $hold );
		}
	}

	/** Downloads and restores use complete snapshots, which cannot become owed cleanup. */
	public function test_complete_snapshot_is_never_selected_or_marked(): void {
		$id    = $this->snapshot( 'download', array( 'status' => 'complete' ) );
		$store = MainWP_Backup_Snapshots::instance();
		$this->assertSame( array(), $store->get_child_prune_candidates( 50, 10 ) );
		$this->assertSame( 0, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['sent'] );
		$this->assertSame( 0, $store->mark_child_prune_confirmed( array( $id ) ) );
		$this->assertSame( 0, $store->record_child_prune_site_attempt( $this->site_id, 10 ) );
		$store->give_up_child_prune( $id, 10 );
		$this->assert_state( $id, false, 0 );
		$this->assertSame( 'complete', $store->get_snapshot_by_id( $id )->status );
	}

	/** Batch order favors untouched rows, and an expired budget leaves later rows owed. */
	public function test_batch_and_budget_leave_remaining_rows_untouched(): void {
		$older  = $this->snapshot( 'attempted', array( 'child_prune_attempts' => 1 ) );
		$first  = $this->snapshot( 'fresh-a' );
		$second = $this->snapshot( 'fresh-b' );
		$sender = MainWP_Backup_Retention_Executor::instance();
		$this->assertSame( 0, $sender->resend_child_prunes( 50, 0 )['sent'] );
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () use ( $sender ) {
				$this->assertSame( 1, $sender->resend_child_prunes( 1 )['confirmed'] );
			}
		);
		$this->assert_state( $first, true, 0 );
		$this->assert_state( $second, false, 0 );
		$this->assert_state( $older, false, 1 );
		$this->with_transport(
			function () {
				usleep( 1100000 );
				return $this->answer();
			},
			function () use ( $sender ) {
				$this->assertSame( 1, $sender->resend_child_prunes( 50, 1 )['sent'] );
			}
		);
		$this->assert_state( $second, true, 0 );
		$this->assert_state( $older, false, 1 );
		$this->assertCount( 2, $this->requests );
	}

	/** Deferred schema repair must disable bookkeeping while preserving normal pruning. */
	public function test_schema_not_ready_preserves_normal_prune(): void {
		$id = $this->snapshot( 'pending', array( 'status' => 'complete' ) );
		update_site_option( MainWP_Install::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING, time() );
		$this->with_transport(
			function () {
				return $this->answer();
			},
			function () use ( $id ) {
				$this->normal_prune( array( $id ) );
				$this->assertSame( 0, MainWP_Backup_Retention_Executor::instance()->resend_child_prunes()['sent'] );
			}
		);
		$store = MainWP_Backup_Snapshots::instance();
		$this->assertSame( array(), $store->get_child_prune_candidates( 50, 10 ) );
		$this->assertSame( 0, $store->mark_child_prune_confirmed( array( $id ) ) );
		$this->assertSame( 0, $store->record_child_prune_site_attempt( $this->site_id, 10 ) );
		$store->give_up_child_prune( $id, 10 );
		$this->assert_state( $id, false, 0 );
		$this->assertCount( 1, $this->requests );
	}

	/** Both cron entry points must replay old cleanup before offering new retention work. */
	public function test_cron_resends_before_retention_and_honors_native_gate(): void {
		$id                = $this->snapshot( 'cron' );
		list( $old, $new ) = $this->retention_pair();
		$events            = array();
		$order             = static function ( $sql ) use ( &$events ) {
			if ( false !== strpos( $sql, 'retention_count > 0' ) ) {
				$events[] = 'retention';
			}
			return $sql;
		};
		add_filter( 'query', $order );
		try {
			$this->with_transport(
				function ( $website, $params ) use ( &$events ) {
					$this->assertGreaterThan( 0, (int) get_option( 'mainwp_cron_last_backups_prune' ) );
					$events[] = 0 === $params['sweep'] ? 'resend' : 'notify';
					return $this->answer();
				},
				function () use ( &$events ) {
					update_option( 'mainwp_enableNativeBackups', 0 );
					MainWP_System_Cron_Jobs::instance()->cron_backups_prune();
					$this->assertSame( array(), $events );
					update_option( 'mainwp_enableNativeBackups', 1 );
					MainWP_System_Cron_Jobs::instance()->cron_backups_prune();
				}
			);
		} finally {
			remove_filter( 'query', $order );
		}
		$this->assertSame( array( 'resend', 'retention', 'notify' ), $events );
		$this->assert_state( $id, true, 0 );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
		$this->assert_state( $old, true, 0 );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $new )->status );
	}

	/** An unexpected retry failure must not prevent the daily retention query. */
	public function test_cron_resend_throw_does_not_stop_retention(): void {
		update_option( 'mainwp_enableNativeBackups', 1 );
		list( $old, $new ) = $this->retention_pair();
		$events            = array();
		$throw             = static function ( $sql ) use ( &$events ) {
			if ( false !== strpos( $sql, 'SELECT id, site_id, task_id, run_key, child_prune_attempts' ) ) {
				$events[] = 'throw';
				throw new \RuntimeException( 'resend failed' );
			}
			if ( false !== strpos( $sql, 'retention_count > 0' ) ) {
				$events[] = 'retention';
			}
			return $sql;
		};
		add_filter( 'query', $throw );
		try {
			$this->with_transport(
				function () {
					return $this->answer();
				},
				function () {
					MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
				}
			);
		} finally {
			remove_filter( 'query', $throw );
		}
		$this->assertSame( array( 'throw', 'retention' ), $events );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $new )->status );
	}

	/** Internal retry columns must not become browser response fields. */
	public function test_retry_columns_do_not_leak_into_snapshot_ajax(): void {
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->snapshot(
			'browser',
			array(
				'child_prune_attempts'     => 7,
				'child_prune_confirmed_at' => 123,
			)
		);
		$_POST    = array(
			'site_id'  => $this->site_id,
			'security' => wp_create_nonce( 'mainwp_restore_snapshot_list' ),
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'json-end' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_restore_snapshot_list();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json-end', $e->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die );
			$_POST    = array();
			$_REQUEST = array();
		}
		$data = json_decode( $raw, true );
		$this->assertIsArray( $data, $raw );
		$this->assertCount( 1, $data['snapshots'] );
		$this->assertStringNotContainsString( 'child_prune_', $raw );
	}
}
