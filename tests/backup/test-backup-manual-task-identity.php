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
