<?php
/**
 * MainWP "Back up now" immediate-continuation tests.
 *
 * An on-demand backup kicks a near-term continuation so a tab-closed run
 * finishes within seconds instead of waiting for the recurring
 * backup cron. The continuation rides a dedicated single-event hook
 * (mainwp_native_backup_continue_action) that reuses the same
 * cron_backups_continue handler, because the recurring
 * mainwp_cronbackups_continue_action cannot be re-triggered immediately.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

/**
 * Class MainWP_Backup_Now_Continuation_Test
 */
class MainWP_Backup_Now_Continuation_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Clear the continuation event between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_clear_scheduled_hook( 'mainwp_native_backup_continue_action' );
		parent::tear_down();
	}

	/**
	 * Invoke the protected static kick helper.
	 *
	 * @return void
	 */
	private function kick() {
		$method = new \ReflectionMethod( MainWP_Post_Backup_Handler::class, 'kick_backup_continuation' );
		$method->setAccessible( true );
		$method->invoke( null );
	}

	/**
	 * The helper schedules an immediate continuation event.
	 *
	 * @return void
	 */
	public function test_kick_schedules_continuation_event() {
		// Keep spawn_cron from making a real outbound request.
		$short_circuit = function () {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $short_circuit );

		$this->assertFalse( wp_next_scheduled( 'mainwp_native_backup_continue_action' ), 'No continuation should be pending before the kick.' );

		$this->kick();

		$this->assertNotFalse( wp_next_scheduled( 'mainwp_native_backup_continue_action' ), 'The kick must schedule a continuation event.' );

		remove_filter( 'pre_http_request', $short_circuit );
	}

	/**
	 * The continuation hook is wired to the shared backup-continue handler,
	 * so it drives the same work the recurring cron does.
	 *
	 * @return void
	 */
	public function test_continuation_hook_registered_to_backup_continue_handler() {
		MainWP_System_Cron_Jobs::instance()->init_cron_jobs();

		$this->assertNotFalse(
			has_action(
				'mainwp_native_backup_continue_action',
				array( MainWP_System_Cron_Jobs::instance(), 'cron_backups_continue' )
			),
			'The single-event continuation hook must reuse cron_backups_continue.'
		);
	}

	/**
	 * The continuation hook stays single-event only: it must not be added to
	 * the recurring cron schedule (that is the lane it exists to work around).
	 *
	 * @return void
	 */
	public function test_continuation_hook_is_not_a_recurring_cron_job() {
		$jobs = MainWP_System_Cron_Jobs::instance()->get_cron_jobs();

		$this->assertArrayNotHasKey( 'mainwp_native_backup_continue_action', $jobs, 'The continuation trigger must stay single-event only.' );
	}

	/**
	 * Disabling native backups must not strand a restore mid-flight: both
	 * cron lanes still drive nonterminal restore jobs to a terminal state.
	 * The toggle governs STARTING work (initiate_restore refuses), not
	 * abandoning destructive work already running on a child site.
	 *
	 * @return void
	 */
	public function test_disabled_toggle_still_drives_restore_jobs_on_cron_lanes() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );

		$spy = new class() extends MainWP_Backup_Restore_Orchestrator {
			/**
			 * Drive-call count.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * Record the drive instead of running jobs.
			 *
			 * @param int      $budget_seconds Slice budget.
			 * @param int|null $only_job_id    Job filter.
			 */
			public function process_pending_restore_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $only_job_id = null ): void {
				unset( $budget_seconds, $only_job_id );
				++$this->calls;
			}
		};

		$prop = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'instance' );
		$prop->setAccessible( true );
		$saved = $prop->getValue();
		$prop->setValue( null, $spy );

		try {
			MainWP_System_Cron_Jobs::instance()->cron_restore_continue();
			$this->assertSame( 1, $spy->calls, 'The dedicated restore lane must run with the feature toggled off.' );

			MainWP_System_Cron_Jobs::instance()->cron_backups_continue();
			$this->assertSame( 2, $spy->calls, 'The periodic continuation lane must still finish restore jobs with the feature toggled off.' );
		} finally {
			$prop->setValue( null, $saved );
			delete_option( 'mainwp_enableNativeBackups' );
			delete_option( 'mainwp_enableLegacyBackupFeature' );
		}
	}

	/**
	 * Exercise both entry points against the same rotation cases.
	 *
	 * @return array Entry point names.
	 */
	public static function slice_entry_points(): array {
		return array( array( 'cron_backups_continue' ), array( 'cron_backups_slice' ) );
	}

	/**
	 * Unfinished tasks share the continue lane even when a run never completes.
	 *
	 * @dataProvider slice_entry_points
	 * @param string $entry Entry point.
	 * @return void
	 */
	public function test_continue_lane_rotates_across_unfinished_tasks( string $entry ) {
		$cases = array(
			'alternation'               => array(
				'ticks'    => 4,
				'paused'   => false,
				'results'  => array(),
				'expected' => array( 'A', 'B', 'A', 'B' ),
			),
			'false still ends the tick' => array(
				'ticks'    => 2,
				'paused'   => false,
				'results'  => array( false, false ),
				'expected' => array( 'A', 'B' ),
			),
			'paused after query'        => array(
				'ticks'    => 1,
				'paused'   => false,
				'results'  => array(),
				'expected' => array( 'B' ),
			),
			'paused never picked'       => array(
				'ticks'    => 3,
				'paused'   => true,
				'results'  => array(),
				'expected' => array( 'A', 'B', 'A' ),
			),
		);

		foreach ( $cases as $message => $case ) {
			$ids         = array();
			$db          = MainWP_DB_Backup::instance();
			$pause_later = null;

			try {
				update_option( 'mainwp_enableNativeBackups', 1 );
				$names = $case['paused'] ? array( 'A', 'B', 'C' ) : array( 'A', 'B' );
				foreach ( $names as $name ) {
					$task = $db->add_backup_task(
						self::factory()->user->create(),
						'',
						'test',
						'test',
						'',
						'',
						'',
						'',
						'',
						0,
						0,
						0,
						0,
						0,
						'zip',
						0,
						0,
						0,
						0
					);
					$this->assertNotFalse( $task, $message );
					$ids[ $name ] = (int) $task->id;
					$db->update_backup_task_with_values(
						$task->id,
						array(
							'last_run'  => 100,
							'completed' => 0,
							'paused'    => 'C' === $name ? 1 : 0,
						)
					);
				}

				$lane                       = new class() extends MainWP_System_Cron_Jobs {
					/**
					 * Attempted task IDs in order.
					 *
					 * @var int[]
					 */
					public $picked = array();

					/**
					 * Scripted results by task ID.
					 *
					 * @var array
					 */
					public $results = array();

					/**
					 * Record the attempt without completing the task.
					 *
					 * @param object     $task Backup task.
					 * @param array|null $outcome Execution outcome.
					 * @return bool Scripted result, defaulting to a started run.
					 */
					protected function continue_backup_task( $task, &$outcome = null ) {
						$this->picked[] = (int) $task->id;
						return empty( $this->results[ $task->id ] ) ? true : array_shift( $this->results[ $task->id ] );
					}
				};
				$lane->results[ $ids['A'] ] = $case['results'];

				if ( 'paused after query' === $message ) {
					$seen        = false;
					$paused      = false;
					$pause_later = static function ( $sql ) use ( &$seen, &$paused, $db, $ids ) {
						if ( $seen && ! $paused ) {
							// Guard before the nested UPDATE so the filter cannot recurse.
							$paused = true;
							$db->update_backup_task_with_values( $ids['A'], array( 'paused' => 1 ) );
						} elseif ( str_contains( $sql, 'WHERE paused = 0 AND completed < last_run ORDER BY' ) ) {
							$seen = true;
						}
						return $sql;
					};
					add_filter( 'query', $pause_later );
				}

				$before = time();
				for ( $tick = 1; $tick <= $case['ticks']; ++$tick ) {
					$already = count( $lane->picked );
					$lane->$entry();
					// The lane, not this test, must stamp every task it tried this tick.
					foreach ( array_slice( $lane->picked, $already ) as $tried ) {
						$row = $db->get_backup_task_by_id( $tried );
						$this->assertGreaterThanOrEqual( $before, (int) $row->last_continued, $message );
					}
					if ( $tick < $case['ticks'] && ! empty( $lane->picked ) ) {
						// Rapid ticks can share a Unix second; preserve their order without sleeping.
						$db->update_backup_task_with_values( end( $lane->picked ), array( 'last_continued' => $tick ) );
					}
				}

				$expected = array();
				foreach ( $case['expected'] as $name ) {
					$expected[] = $ids[ $name ];
				}
				$this->assertSame( $expected, $lane->picked, $message );
			} finally {
				if ( null !== $pause_later ) {
					remove_filter( 'query', $pause_later );
				}
				foreach ( $ids as $id ) {
					$db->remove_backup_task( $id );
				}
				delete_option( 'mainwp_enableNativeBackups' );
			}
		}
	}

	/**
	 * Seed an unfinished task owned by the current administrator.
	 *
	 * @param int[] $sites Site IDs.
	 * @return object Backup task.
	 */
	private function open_outcome_task( array $sites ) {
		$db   = MainWP_DB_Backup::instance();
		$task = $db->add_backup_task( get_current_user_id(), 'Outcome task', 'daily', 'full', '', implode( ',', $sites ), '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'  => time() - 100,
				'completed' => 0,
			)
		);
		return $db->get_backup_task_by_id( $task->id );
	}

	/**
	 * Movement and completion are independent; only idle visits yield.
	 *
	 * @return void
	 */
	public function test_execute_outcome_preserves_runner_results() {
		global $wpdb;
		$cases = array(
			// result, visited, advanced, finished, yielded, refused, lost, closed, advanced unfinished, dropped.
			'advanced same-phase' => array( true, 1, 1, 0, 0, false, false, false, 1, 0 ),
			'current file moved'  => array( true, 1, 1, 0, 0, false, false, false, 1, 0 ),
			'create issued'       => array( true, 1, 1, 0, 0, false, false, false, 1, 0 ),
			'identical poll'      => array( true, 1, 0, 0, 1, false, false, false, 0, 0 ),
			'creating marker'     => array( true, 1, 0, 0, 1, false, false, false, 0, 0 ),
			'finished success'    => array( true, 1, 1, 1, 0, false, false, true, 0, 0 ),
			'finished error'      => array( false, 1, 1, 1, 0, false, false, true, 0, 0 ),
			'lease lost'          => array( false, 1, 1, 0, 0, false, true, false, 0, 1 ),
			'slot refused'        => array( false, 0, 0, 0, 0, true, false, false, 0, 0 ),
		);
		$keys  = array( 'sites_visited', 'sites_advanced', 'sites_finished', 'sites_yielded', 'slot_refused', 'lease_lost', 'run_closed', 'wall_time' );
		$user  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_backup_max_concurrent', 1 );
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $private_key );

		foreach ( $cases as $label => $expected ) {
			$sites = array();
			for ( $i = 0; $i < 3; ++$i ) {
				$sites[] = $this->create_test_site(
					array(
						'userid'  => $user,
						'privkey' => base64_encode( $private_key ),
					)
				); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- signing fixture.
			}
			$db   = MainWP_DB_Backup::instance();
			$task = $this->open_outcome_task( $sites );
			$db->update_completed_sites(
				$task->id,
				array(
					$sites[0] => true,
					$sites[2] => true,
				)
			);
			if ( 'create issued' !== $label ) {
				$db->add_backup_task_progress( $task->id, $sites[1], array() );
				$db->update_backup_task_progress(
					$task->id,
					$sites[1],
					array(
						'dtsFetched'           => time(),
						'pid'                  => 12345,
						'child_backup_task_id' => 'creating marker' === $label ? '__creating_outcome' : 'outcome-child-task',
						'child_phase'          => 'archiving_files',
						'bytes_downloaded'     => 10,
						'current_file'         => 'wp_options',
						'removedFiles'         => 1,
					)
				);
			}
			$files    = array();
			$calls    = array();
			$child    = static function ( $result, $website, $what ) use ( $label, &$calls ) {
				$calls[] = array( (int) $website->id, $what );
				if ( 'backup_preflight' === $what ) {
					return array(
						'task_spine'  => 1,
						'zip_support' => true,
					);
				}
				if ( 'backup_task_create' === $what ) {
					return array( 'task_id' => 'outcome-created-task' );
				}
				if ( 'backup_task_run' === $what ) {
					if ( in_array( $label, array( 'finished success', 'lease lost' ), true ) ) {
						return array(
							'phase' => 'completed',
							'file'  => 'outcome-backup.zip',
							'size'  => 4096,
						);
					}
					if ( 'finished error' === $label ) {
						return array(
							'phase'      => 'failed',
							'error'      => 'Outcome failure',
							'error_code' => 'outcome_failed',
						);
					}
					if ( 'current file moved' === $label ) {
						return array(
							'phase'    => 'archiving_files',
							'progress' => array(
								'bytes_done'   => 10,
								'current_file' => 'wp_posts',
							),
						);
					}
					return array(
						'phase'    => 'archiving_files',
						'progress' => array( 'bytes_done' => 'advanced same-phase' === $label ? 20 : 10 ),
					);
				}
				if ( in_array( $what, array( 'delete_backup', 'backup_task_cancel' ), true ) ) {
					return array( 'result' => 'ok' );
				}
				throw new \RuntimeException( 'Unexpected Child action: ' . $what );
			};
			$download = static function ( $pre, $url, $file ) use ( &$files ) {
				unset( $pre, $url );
				$files[] = $file;
				file_put_contents( $file, 'PK' . "\x03\x04" . str_repeat( 'x', 4092 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- validated artifact fixture.
				return true;
			};
			$drop     = static function () use ( $wpdb, $label ) {
				if ( 'lease lost' === $label ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- simulate a reclaimed slot after the site was driven.
				}
			};
			add_filter( 'mainwp_fetch_url_authed_pre', $child, 10, 3 );
			add_filter( 'mainwp_download_to_file_pre', $download, 10, 3 );
			add_action( 'mainwp_postprocess_backup_site', $drop );
			$slot = 'slot refused' === $label ? MainWP_Backup_Monitor::acquire_backup_slot( 1, 'native' ) : false;
			try {
				if ( 'slot refused' === $label ) {
					$this->assertIsString( $slot, $label );
				}
				if ( 'identical poll' === $label ) {
					$lane_token = MainWP_Backup_Monitor::acquire_lane_lease();
					$this->assertIsString( $lane_token );
					$aged = wp_json_encode(
						array(
							'token'     => $lane_token,
							'started'   => time() - 10,
							'heartbeat' => time() - 10,
							'pid'       => getmypid(),
							'lease'     => 600,
						)
					);
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $aged, MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- age the heartbeat to expose a skipped refresh on yield.
					$before = time();
				}
				$claimed         = null;
				$claimed_started = 0;
				$outcome         = array( 'stale' => true );
				$result          = MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false, 0, $claimed, $claimed_started, false, $outcome );
				if ( 'identical poll' === $label ) {
					$lane_row = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- observe the yielded site's heartbeat.
					$this->assertGreaterThanOrEqual( $before, $lane_row['heartbeat'], 'Yielded sites must refresh the lane too.' );
				}
				$this->assertSame( $expected[0], $result, $label . ': existing bool result' );
				$this->assertSame( $keys, array_keys( $outcome ), $label );
				$this->assertSame( array_slice( $expected, 1, 7 ), array_slice( array_values( $outcome ), 0, 7 ), $label );
				$this->assertIsFloat( $outcome['wall_time'], $label );
				$this->assertGreaterThan( 0.0, $outcome['wall_time'], $label );
				$this->assertSame( $outcome['sites_visited'], $outcome['sites_finished'] + $outcome['sites_yielded'] + $expected[8] + $expected[9], $label . ': visit partition' );
				foreach ( $calls as $call ) {
					$this->assertSame( $sites[1], $call[0], $label . ': completed sites must be skipped' );
				}
			} finally {
				remove_filter( 'mainwp_fetch_url_authed_pre', $child );
				remove_filter( 'mainwp_download_to_file_pre', $download );
				remove_action( 'mainwp_postprocess_backup_site', $drop );
				MainWP_Backup_Monitor::release_lane_lease();
				if ( false !== $slot ) {
					MainWP_Backup_Monitor::release_backup_slot( $slot );
				}
				foreach ( $files as $file ) {
					if ( file_exists( $file ) ) {
						unlink( $file );
					}
				}
				$wpdb->delete( $wpdb->prefix . 'mainwp_wp_backup_snapshot', array( 'task_id' => $task->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture cleanup.
				$db->remove_backup_task( $task->id );
			}
		}
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_backup_max_concurrent' );
	}

	/**
	 * Every queried tick emits one event, even when no task is due.
	 *
	 * @dataProvider slice_entry_points
	 * @param string $entry Entry point.
	 * @return void
	 */
	public function test_continue_tick_reports_pick_and_empty_queue( string $entry ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$db     = MainWP_DB_Backup::instance();
		$first  = $this->open_outcome_task( array() );
		$second = $this->open_outcome_task( array() );
		$db->update_backup_task_with_values( $first->id, array( 'last_continued' => 2 ) );
		$db->update_backup_task_with_values( $second->id, array( 'last_continued' => 1 ) );
		$events = array();
		$record = static function ( $picked, $outcome, $tick ) use ( &$events ) {
			$events[] = array( $picked, $outcome, $tick );
		};
		add_action( 'mainwp_backup_diag_tick', $record, 10, 3 );
		try {
			MainWP_System_Cron_Jobs::instance()->$entry();
			$this->assertCount( 1, $events );
			$this->assertFalse( $events[0][2]['lane_busy'] );
			$this->assertSame( 'cron_backups_slice' === $entry ? 'slice' : 'continue', $events[0][2]['lane'] );
			$this->assertSame( (int) $second->id, $events[0][0] );
			$this->assertSame( array( (int) $second->id, (int) $first->id ), $events[0][2]['candidates'] );
			$this->assertSame( array( 'sites_visited', 'sites_advanced', 'sites_finished', 'sites_yielded', 'slot_refused', 'lease_lost', 'run_closed', 'wall_time' ), array_keys( $events[0][1] ) );
			$this->assertTrue( $events[0][1]['run_closed'] );
			$this->assertTrue( $events[0][2]['unfinished_remaining'] );
			$this->assertIsFloat( $events[0][2]['started'] );
			$this->assertGreaterThanOrEqual( $events[0][1]['wall_time'], $events[0][2]['wall_time'] );
			$db->remove_backup_task( $first->id );
			$db->remove_backup_task( $second->id );
			$events = array();
			MainWP_System_Cron_Jobs::instance()->$entry();
			$this->assertCount( 1, $events );
			$this->assertSame( 0, $events[0][0] );
			$this->assertNull( $events[0][1] );
			$this->assertSame( array(), $events[0][2]['candidates'] );
			$this->assertFalse( $events[0][2]['unfinished_remaining'] );
		} finally {
			remove_action( 'mainwp_backup_diag_tick', $record );
			$db->remove_backup_task( $first->id );
			$db->remove_backup_task( $second->id );
			delete_option( 'mainwp_enableNativeBackups' );
		}
	}

	/**
	 * An occupied lane stamps the tick but cannot pick or rotate a task.
	 */
	public function test_busy_slice_does_not_pick() {
		global $wpdb;
		update_option( 'mainwp_enableNativeBackups', 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$task = $this->open_outcome_task( array() );
		$row  = wp_json_encode(
			array(
				'token'     => 'other-owner',
				'started'   => time(),
				'heartbeat' => time(),
				'pid'       => 2,
				'lease'     => 600,
			)
		);
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", MainWP_Backup_Monitor::LANE_LEASE_OPTION, $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another process owns this lease.
		$events = array();
		$record = static function ( $picked, $outcome, $tick ) use ( &$events ) {
			$events[] = array( $picked, $outcome, $tick );
		};
		add_action( 'mainwp_backup_diag_tick', $record, 10, 3 );
		try {
			$before = time();
			MainWP_System_Cron_Jobs::instance()->cron_backups_slice();
			$this->assertGreaterThanOrEqual( $before, (int) get_option( 'mainwp_cron_last_backups_slice' ) );
			$this->assertCount( 1, $events );
			$this->assertSame( 0, $events[0][0] );
			$this->assertNull( $events[0][1] );
			$this->assertTrue( $events[0][2]['lane_busy'] );
			$this->assertSame( 'slice', $events[0][2]['lane'] );
			$this->assertSame( array(), $events[0][2]['candidates'] );
			$this->assertSame( $task->last_continued, MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_continued );
			$this->assertSame( $row, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- refusal cannot release another process's row.
		} finally {
			remove_action( 'mainwp_backup_diag_tick', $record );
			delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
			MainWP_DB_Backup::instance()->remove_backup_task( $task->id );
		}
	}

	/**
	 * Losing the lane after backup_site prevents marks and the run close.
	 */
	public function test_lane_loss_after_first_site_fences_task_writes() {
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $private_key );
		$sites = array();
		for ( $i = 0; $i < 2; ++$i ) {
			$sites[] = $this->create_test_site(
				array(
					'userid'  => $user,
					'privkey' => base64_encode( $private_key ),
				)
			); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- signing fixture.
		}
		$db   = MainWP_DB_Backup::instance();
		$task = $this->open_outcome_task( $sites );
		foreach ( $sites as $site ) {
			$db->add_backup_task_progress( $task->id, $site, array() );
			$db->update_backup_task_progress(
				$task->id,
				$site,
				array(
					'dtsFetched'           => time(),
					'pid'                  => 12345,
					'child_backup_task_id' => 'lane-child-' . $site,
					'child_phase'          => 'archiving_files',
					'removedFiles'         => 1,
				)
			);
		}
		$calls    = array();
		$files    = array();
		$child    = static function ( $result, $website, $what ) use ( &$calls ) {
			$calls[] = (int) $website->id;
			if ( 'backup_task_run' === $what ) {
				return array(
					'phase' => 'completed',
					'file'  => 'lane-backup.zip',
					'size'  => 4096,
				);
			}
			if ( in_array( $what, array( 'delete_backup', 'backup_task_cancel' ), true ) ) {
				return array( 'result' => 'ok' );
			}
			throw new \RuntimeException( 'Unexpected Child action: ' . $what );
		};
		$download = static function ( $pre, $url, $file ) use ( &$files ) {
			$files[] = $file;
			file_put_contents( $file, 'PK' . "\x03\x04" . str_repeat( 'x', 4092 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- validated artifact fixture.
			return true;
		};
		$dropped  = 0;
		$drop     = static function () use ( $wpdb, &$dropped ) {
			++$dropped;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another process removed the lane after backup_site.
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 10, 3 );
		add_filter( 'mainwp_download_to_file_pre', $download, 10, 3 );
		add_action( 'mainwp_postprocess_backup_site', $drop );
		try {
			$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
			$claimed         = null;
			$claimed_started = 0;
			$outcome         = null;
			$this->assertFalse( MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false, 0, $claimed, $claimed_started, false, $outcome ) );
			$this->assertSame( 1, $dropped );
			$this->assertSame( 1, $outcome['sites_visited'] );
			$this->assertTrue( $outcome['lease_lost'] );
			$this->assertFalse( $outcome['run_closed'] );
			$this->assertNotEmpty( $calls );
			$this->assertSame( array( $sites[0] ), array_values( array_unique( $calls ) ) );
			$after = $db->get_backup_task_by_id( $task->id );
			$this->assertSame( $task->completed_sites, $after->completed_sites );
			$this->assertSame( $task->completed, $after->completed );
		} finally {
			MainWP_Backup_Monitor::release_lane_lease();
			remove_filter( 'mainwp_fetch_url_authed_pre', $child );
			remove_filter( 'mainwp_download_to_file_pre', $download );
			remove_action( 'mainwp_postprocess_backup_site', $drop );
			foreach ( $files as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_backup_snapshot', array( 'task_id' => $task->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture cleanup.
			$db->remove_backup_task( $task->id );
		}
	}

	/**
	 * Option values read from storage must keep their numeric meaning.
	 *
	 * @return void
	 */
	public function test_hourly_site_cap_handles_stored_options() {
		$method = new \ReflectionMethod( MainWP_System_Cron_Jobs::class, 'hourly_site_cap' );
		try {
			foreach ( array(
				'0'       => 0,
				'1'       => 5,
				'empty'   => 0,
				'missing' => 5,
			) as $value => $expected ) {
				if ( 'missing' === $value ) {
					delete_option( 'mainwp_chunkedBackupTasks' );
				} else {
					update_option( 'mainwp_chunkedBackupTasks', 'empty' === $value ? '' : (string) $value );
				}
				$this->assertSame( $expected, $method->invoke( MainWP_System_Cron_Jobs::instance() ), (string) $value );
			}
		} finally {
			delete_option( 'mainwp_chunkedBackupTasks' );
		}
	}
}
