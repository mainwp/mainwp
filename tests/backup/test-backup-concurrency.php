<?php
/**
 * MainWP Backup Concurrency Tests
 *
 * Tests for the DB semaphore that caps how many backup tasks run at the
 * same time: fixed-name wp_options slot rows whose UNIQUE(option_name)
 * key makes the INSERT the atomic acquisition.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Settings;

/**
 * Class MainWP_Backup_Concurrency_Test
 */
class MainWP_Backup_Concurrency_Test extends \WP_UnitTestCase {

	/**
	 * Clean the semaphore between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup of the DB semaphore.
		delete_option( 'mainwp_backup_max_concurrent' );
		parent::tear_down();
	}

	/**
	 * Insert a slot row directly, the way a crashed (or concurrent)
	 * process would have left it.
	 *
	 * @param int    $slot    Slot number.
	 * @param string $token   Holder token.
	 * @param int    $started Acquisition epoch.
	 *
	 * @return void
	 */
	private function seed_slot_row( int $slot, string $token, int $started ) {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture for the DB semaphore.
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				MainWP_Backup_Monitor::SLOT_OPTION_PREFIX . $slot,
				(string) wp_json_encode(
					array(
						'token'   => $token,
						'started' => $started,
						'pid'     => 1,
					)
				)
			)
		);
	}

	/**
	 * The raw slot rows, keyed by option name.
	 *
	 * @return array<string, array> Decoded slot values by option name.
	 */
	private function slot_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct read of the DB semaphore.
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' )
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->option_name ] = (array) json_decode( (string) $row->option_value, true );
		}
		return $out;
	}

	/**
	 * Slots are granted up to the limit and refused beyond it.
	 *
	 * @return void
	 */
	public function test_slots_granted_up_to_limit_then_refused() {
		$first  = MainWP_Backup_Monitor::acquire_backup_slot( 2 );
		$second = MainWP_Backup_Monitor::acquire_backup_slot( 2 );
		$third  = MainWP_Backup_Monitor::acquire_backup_slot( 2 );

		$this->assertIsString( $first );
		$this->assertIsString( $second );
		$this->assertNotSame( $first, $second );
		$this->assertFalse( $third, 'The third task must wait for a free slot.' );
	}

	/**
	 * Releasing a slot frees it for the next task.
	 *
	 * @return void
	 */
	public function test_released_slot_frees_capacity() {
		$first  = MainWP_Backup_Monitor::acquire_backup_slot( 2 );
		$second = MainWP_Backup_Monitor::acquire_backup_slot( 2 );
		$this->assertIsString( $second );

		MainWP_Backup_Monitor::release_backup_slot( $first );

		$this->assertIsString( MainWP_Backup_Monitor::acquire_backup_slot( 2 ), 'A released slot must be reusable.' );
	}

	/**
	 * Slots from crashed processes expire after two hours and are
	 * reclaimed by the next acquisition.
	 *
	 * @return void
	 */
	public function test_stale_slots_are_reclaimed() {
		$this->seed_slot_row( 1, 'stale-token-1', time() - 3 * HOUR_IN_SECONDS );
		$this->seed_slot_row( 2, 'stale-token-2', time() - 3 * HOUR_IN_SECONDS );

		$token = MainWP_Backup_Monitor::acquire_backup_slot( 2 );

		$this->assertIsString( $token, 'Stale slots must not block new backups.' );
		$tokens = array_column( $this->slot_rows(), 'token' );
		$this->assertNotContains( 'stale-token-1', $tokens, 'The reclaimed stale slot row must be gone.' );
		$this->assertContains( $token, $tokens, 'The new holder must own a slot row.' );
	}

	/**
	 * A live slot row is never reclaimed: acquisition claims the other
	 * slot and a full house refuses.
	 *
	 * @return void
	 */
	public function test_live_slots_are_not_reclaimed() {
		$this->seed_slot_row( 1, 'live-token-1', time() - 60 );

		$token = MainWP_Backup_Monitor::acquire_backup_slot( 2 );
		$this->assertIsString( $token );

		$tokens = array_column( $this->slot_rows(), 'token' );
		$this->assertContains( 'live-token-1', $tokens, 'A live holder must keep its slot.' );

		$this->assertFalse( MainWP_Backup_Monitor::acquire_backup_slot( 2 ), 'Both slots held means refusal.' );
	}

	/**
	 * Releasing a token whose stale row another contender already
	 * reclaimed deletes nothing - the compare-and-delete misses, and the
	 * new holder keeps its slot.
	 *
	 * @return void
	 */
	public function test_release_of_reclaimed_token_cannot_free_new_holder() {
		$this->seed_slot_row( 1, 'crashed-token', time() - 3 * HOUR_IN_SECONDS );

		// The reclaim replaced the crashed holder's row.
		$new_holder = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		$this->assertIsString( $new_holder );

		// The crashed process comes back and releases its old token.
		MainWP_Backup_Monitor::release_backup_slot( 'crashed-token' );

		$tokens = array_column( $this->slot_rows(), 'token' );
		$this->assertContains( $new_holder, $tokens, 'The zombie release must not free the new holder\'s slot.' );
		$this->assertFalse( MainWP_Backup_Monitor::acquire_backup_slot( 1 ), 'The slot must still be held.' );
	}

	/**
	 * The configured option controls the slot limit.
	 *
	 * @return void
	 */
	public function test_option_controls_limit() {
		update_option( 'mainwp_backup_max_concurrent', 1 );
		$max = MainWP_Backup_Settings::get_max_concurrent_backups();

		$this->assertSame( 1, $max );
		$this->assertIsString( MainWP_Backup_Monitor::acquire_backup_slot( $max ) );
		$this->assertFalse( MainWP_Backup_Monitor::acquire_backup_slot( $max ) );
	}

	/**
	 * Lowering the concurrency limit while more jobs than the new maximum
	 * are live must not admit new work: live holders in slots above the new
	 * cap still occupy capacity, so acquisition is refused until enough of
	 * them drain to bring the total live count below the limit.
	 *
	 * @return void
	 */
	public function test_lowering_limit_below_live_holders_refuses_until_they_drain() {
		// Five live holders exist from when the limit was five.
		for ( $slot = 1; $slot <= 5; $slot++ ) {
			$this->seed_slot_row( $slot, 'live-' . $slot, time() - 60 );
		}

		// The limit is now two: five live holders exceed it, so no new work.
		$this->assertFalse( MainWP_Backup_Monitor::acquire_backup_slot( 2 ), 'Five live holders above the reduced cap must block new work.' );

		// Drain slots 1 and 2; slots 3, 4, 5 remain live (three > two).
		MainWP_Backup_Monitor::release_backup_slot( 'live-1' );
		MainWP_Backup_Monitor::release_backup_slot( 'live-2' );
		$this->assertFalse( MainWP_Backup_Monitor::acquire_backup_slot( 2 ), 'Three live holders still exceed the cap of two.' );

		// Drain slot 3; slots 4, 5 remain live (two == cap), still refused.
		MainWP_Backup_Monitor::release_backup_slot( 'live-3' );
		$this->assertFalse( MainWP_Backup_Monitor::acquire_backup_slot( 2 ), 'Two live holders at the cap must refuse.' );

		// Drain slot 4; one live holder remains, below the cap: admit again.
		MainWP_Backup_Monitor::release_backup_slot( 'live-4' );
		$this->assertIsString( MainWP_Backup_Monitor::acquire_backup_slot( 2 ), 'Below the cap, new work is admitted again.' );
	}
}
