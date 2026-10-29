<?php
/**
 * Hidden manual task identity tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

/**
 * Manual task races must converge on one persisted task id.
 */
class MainWP_Backup_Manual_Task_Identity_Test extends \WP_UnitTestCase {

	/**
	 * Remove hidden tasks even when a race assertion fails.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'sites' => '887701' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		parent::tear_down();
	}

	/**
	 * A newly created task is owned by the requested site.
	 */
	public function test_ensure_manual_backup_task_inserts_site_identity() {
		$website = (object) array(
			'id'     => 887701,
			'userid' => 1,
		);
		$task    = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		$this->assertIsObject( $task );
		$this->assertSame( 887701, (int) $task->manual_site_id );
		$this->assertSame( '887701', $task->authorized_sites );
		$this->assertSame( 1, (int) $task->template );
		$this->assertSame( 'manual', $task->schedule );
	}

	/**
	 * Repeated calls must retain the same snapshot owner.
	 */
	public function test_ensure_manual_backup_task_refetches_existing_identity() {
		$db      = MainWP_DB_Backup::instance();
		$website = (object) array(
			'id'     => 887701,
			'userid' => 1,
		);
		$first   = $db->ensure_manual_backup_task( $website );
		$second  = $db->ensure_manual_backup_task( $website );
		$this->assertIsObject( $first );
		$this->assertIsObject( $second );
		$this->assertSame( $first->id, $second->id );
	}

	/**
	 * The losing insert must adopt the winner even without advisory locks.
	 *
	 * @dataProvider insert_error_languages
	 * @param bool $localized Simulate a non-English duplicate message.
	 */
	public function test_ensure_manual_backup_task_refetches_duplicate_key_winner( $localized ) {
		global $wpdb;
		$db        = MainWP_DB_Backup::instance();
		$website   = (object) array(
			'id'     => 887701,
			'userid' => 1,
		);
		$scheduled = $db->add_backup_task( 1, 'Manual identity scheduled task', 'daily', 'full', '', (string) $website->id, '', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0 );
		$this->assertIsObject( $scheduled );
		$db->update_backup_task_with_values( (int) $scheduled->id, array( 'destinations' => '[3,5]' ) );
		$saved_retention = get_option( 'mainwp_on_demand_retention_count' );
		update_option( 'mainwp_on_demand_retention_count', 11 );
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$winner = null;
		$race   = function ( $query ) use ( &$race, &$winner, $db, $website, $table, $wpdb, $localized ) {
			if ( str_starts_with( $query, 'INSERT INTO `' . $table . '`' ) ) {
				remove_filter( 'query', $race );
				$winner = $db->ensure_manual_backup_task( $website );
				$this->assertIsObject( $winner );
				$db->update_backup_task_with_values(
					(int) $winner->id,
					array(
						'destinations'     => '[999]',
						'retention_count'  => 1,
						'next_run'         => 123,
						'paused'           => 1,
						'max_chain_length' => 5,
					)
				);
				if ( $localized ) {
					$wpdb->last_error = 'Doppelter Eintrag';
					return '';
				}
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$task = $db->ensure_manual_backup_task( $website );
		} finally {
			remove_filter( 'query', $race );
			if ( false === $saved_retention ) {
				delete_option( 'mainwp_on_demand_retention_count' );
			} else {
				update_option( 'mainwp_on_demand_retention_count', $saved_retention );
			}
		}
		$this->assertIsObject( $winner, 'The competing request must insert before the original insert executes.' );
		$this->assertIsObject( $task );
		$this->assertSame( $winner->id, $task->id );
		$this->assertSame( array( 3, 5 ), json_decode( $task->destinations, true ) );
		$this->assertSame( 11, (int) $task->retention_count );
		$this->assertSame( 0, (int) $task->next_run );
		$this->assertSame( 0, (int) $task->paused );
		$this->assertSame( 0, (int) $task->max_chain_length );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE manual_site_id = 887701" ) ); // phpcs:ignore WordPress.DB -- test probe.
	}

	/**
	 * A failed insert without a winner remains a database failure.
	 */
	public function test_ensure_manual_backup_task_returns_false_on_insert_failure() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$fail  = static function ( $query ) use ( $table, $wpdb ) {
			if ( str_starts_with( $query, 'INSERT INTO `' . $table . '`' ) ) {
				$wpdb->last_error = 'Insert failed';
				return '';
			}
			return $query;
		};
		add_filter( 'query', $fail );
		try {
			$this->assertFalse(
				MainWP_DB_Backup::instance()->ensure_manual_backup_task(
					(object) array(
						'id'     => 887701,
						'userid' => 1,
					)
				)
			);
		} finally {
			remove_filter( 'query', $fail );
		}
	}

	/**
	 * Create the site's task as $owner and open a run on it.
	 *
	 * @param int $owner User the task was refreshed for.
	 * @return object Task row with an open run.
	 */
	private function running_manual_task( $owner ) {
		$db      = MainWP_DB_Backup::instance();
		$website = (object) array(
			'id'     => 887701,
			'userid' => 1,
		);
		wp_set_current_user( $owner );
		$task = $db->ensure_manual_backup_task( $website );
		$this->assertIsObject( $task );
		$db->update_backup_task_with_values(
			(int) $task->id,
			array(
				'retention_count'  => 2,
				'destinations'     => '[41]',
				'authorized_sites' => '887701,887702',
				'last_run'         => time(),
				'completed'        => time() - 60,
			)
		);
		return $db->get_manual_backup_task( 887701 );
	}

	/**
	 * Assert the task row still holds what the run's initiator stored.
	 *
	 * @param object $before Row read before the second user's call.
	 * @param object $after  Row the call returned.
	 */
	private function assert_task_unchanged( $before, $after ) {
		$this->assertIsObject( $after );
		foreach ( array( 'userid', 'retention_count', 'destinations', 'authorized_sites', 'last_run', 'completed' ) as $field ) {
			$this->assertSame( (string) $before->$field, (string) $after->$field, $field );
		}
	}

	/**
	 * A second user attaching to a running backup must not take over the task.
	 */
	public function test_attach_to_running_run_leaves_task_unchanged() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$second = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$before = $this->running_manual_task( $owner );

		wp_set_current_user( $second );
		$after = MainWP_DB_Backup::instance()->ensure_manual_backup_task( (object) array( 'id' => 887701 ) );

		$this->assert_task_unchanged( $before, $after );
		$this->assertLessThan( (int) $after->last_run, (int) $after->completed, 'The caller must still see the run as open so it attaches.' );
	}

	/**
	 * A run claimed between the task read and the refresh must keep its row.
	 */
	public function test_run_claimed_before_refresh_leaves_task_unchanged() {
		global $wpdb;
		$db      = MainWP_DB_Backup::instance();
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$owner   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$second  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$before  = $this->running_manual_task( $owner );
		$started = (int) $before->last_run;
		// Present the task as idle to the first read, then let the run be claimed.
		$db->update_backup_task_with_values( (int) $before->id, array( 'completed' => $started ) );

		$claim = function ( $query ) use ( &$claim, $db, $before, $started, $table ) {
			if ( str_starts_with( ltrim( $query ), 'UPDATE' ) && str_contains( $query, $table ) && str_contains( $query, 'userid' ) ) {
				remove_filter( 'query', $claim );
				$db->update_backup_task_with_values(
					(int) $before->id,
					array(
						'last_run'  => $started + 1,
						'completed' => $started - 60,
					)
				);
			}
			return $query;
		};
		wp_set_current_user( $second );
		add_filter( 'query', $claim );
		try {
			$after = $db->ensure_manual_backup_task( (object) array( 'id' => 887701 ) );
		} finally {
			remove_filter( 'query', $claim );
		}

		$this->assertSame( (string) ( $started + 1 ), (string) $after->last_run, 'The claim must have landed before the refresh executed.' );
		$this->assertSame( (string) $owner, (string) $after->userid );
		$this->assertSame( '2', (string) $after->retention_count );
		$this->assertSame( '[41]', $after->destinations );
		$this->assertSame( '887701,887702', $after->authorized_sites );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d AND userid = %d", $before->id, $owner ) ) ); // phpcs:ignore WordPress.DB -- test probe.
	}

	/**
	 * Call ensure_manual_backup_task() as $caller while the open run closes
	 * between the refused refresh and the re-read that follows it.
	 *
	 * @param object $before Task row with an open run.
	 * @param int    $caller User making the call.
	 * @return object Row the call returned.
	 */
	private function call_while_run_closes_after_refused_refresh( $before, $caller ) {
		$db      = MainWP_DB_Backup::instance();
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$refused = false;
		$close   = function ( $query ) use ( &$close, &$refused, $db, $before, $table ) {
			if ( ! $refused && str_starts_with( ltrim( $query ), 'UPDATE' ) && str_contains( $query, $table ) && str_contains( $query, 'userid' ) ) {
				$refused = true;
				return $query;
			}
			if ( $refused && str_starts_with( ltrim( $query ), 'SELECT' ) && str_contains( $query, 'manual_site_id' ) ) {
				remove_filter( 'query', $close );
				$db->update_backup_task_with_values( (int) $before->id, array( 'completed' => (int) $before->last_run ) );
			}
			return $query;
		};
		wp_set_current_user( $caller );
		add_filter( 'query', $close );
		try {
			$after = $db->ensure_manual_backup_task( (object) array( 'id' => 887701 ) );
		} finally {
			remove_filter( 'query', $close );
		}
		$this->assertTrue( $refused, 'The first refresh must run while the run is open.' );
		$this->assertIsObject( $after );
		return $after;
	}

	/**
	 * A run that closes after the refused refresh must not hand the next run the old owner.
	 */
	public function test_run_closed_after_refused_refresh_refreshes_again() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$second = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$before = $this->running_manual_task( $owner );

		$after = $this->call_while_run_closes_after_refused_refresh( $before, $second );

		$this->assertSame( (string) $second, (string) $after->userid );
		$this->assertSame( '887701', $after->authorized_sites );
	}

	/**
	 * The run's own initiator calling again must still pick up settings changed during the run.
	 */
	public function test_initiator_call_after_run_closes_refreshes_settings() {
		$owner           = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$before          = $this->running_manual_task( $owner );
		$saved_retention = get_option( 'mainwp_on_demand_retention_count' );
		update_option( 'mainwp_on_demand_retention_count', 7 );

		try {
			$after = $this->call_while_run_closes_after_refused_refresh( $before, $owner );
		} finally {
			if ( false === $saved_retention ) {
				delete_option( 'mainwp_on_demand_retention_count' );
			} else {
				update_option( 'mainwp_on_demand_retention_count', $saved_retention );
			}
		}

		$this->assertSame( (string) $owner, (string) $after->userid );
		$this->assertSame( 7, (int) $after->retention_count );
		$this->assertSame( '887701', $after->authorized_sites );
		$this->assertNotSame( '[41]', $after->destinations );
	}

	/**
	 * With no run in progress the task takes the caller and current settings.
	 */
	public function test_idle_task_refreshes_for_the_caller() {
		$db     = MainWP_DB_Backup::instance();
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$second = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$before = $this->running_manual_task( $owner );
		$db->update_backup_task_with_values( (int) $before->id, array( 'completed' => (int) $before->last_run ) );
		$saved_retention = get_option( 'mainwp_on_demand_retention_count' );
		update_option( 'mainwp_on_demand_retention_count', 9 );

		wp_set_current_user( $second );
		try {
			$after = $db->ensure_manual_backup_task( (object) array( 'id' => 887701 ) );
		} finally {
			if ( false === $saved_retention ) {
				delete_option( 'mainwp_on_demand_retention_count' );
			} else {
				update_option( 'mainwp_on_demand_retention_count', $saved_retention );
			}
		}

		$this->assertSame( (string) $second, (string) $after->userid );
		$this->assertSame( 9, (int) $after->retention_count );
		$this->assertSame( '887701', $after->authorized_sites );
		$this->assertNotSame( '[41]', $after->destinations );
	}

	/**
	 * Exercise both the real duplicate key and a localized failure response.
	 *
	 * @return array Error message modes.
	 */
	public static function insert_error_languages() {
		return array(
			'server message'    => array( false ),
			'localized message' => array( true ),
		);
	}
}
