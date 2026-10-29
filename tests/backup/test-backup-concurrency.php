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
		$this->purge_slot_rows();
		delete_option( 'mainwp_backup_max_concurrent' );
		parent::tear_down();
	}

	/**
	 * Remove every slot row.
	 *
	 * @return void
	 */
	private function purge_slot_rows() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup of the DB semaphore.
	}

	/**
	 * Slot decisions seen through the diagnostics action.
	 *
	 * @var array<int, array>
	 */
	private $slot_events = array();

	/**
	 * Insert a slot row directly, the way a crashed (or concurrent)
	 * process would have left it. Omitting the lease arguments seeds a
	 * pre-lease row, which is what a dashboard upgraded mid-run has.
	 *
	 * @param int         $slot      Slot number.
	 * @param string      $token     Holder token.
	 * @param int         $started   Acquisition epoch.
	 * @param string|null $lane      native|legacy, omitted from the row when null.
	 * @param int|null    $heartbeat Last heartbeat epoch, omitted when null.
	 * @param int|null    $lease     Lease length in seconds, omitted when null.
	 *
	 * @return void
	 */
	private function seed_slot_row( int $slot, string $token, int $started, ?string $lane = null, ?int $heartbeat = null, ?int $lease = null ) {
		$value = array(
			'token'   => $token,
			'started' => $started,
			'pid'     => 1,
		);
		if ( null !== $lane ) {
			$value['lane'] = $lane;
		}
		if ( null !== $heartbeat ) {
			$value['heartbeat'] = $heartbeat;
		}
		if ( null !== $lease ) {
			$value['lease'] = $lease;
		}
		$this->seed_raw_slot_row( $slot, (string) wp_json_encode( $value ) );
	}

	/**
	 * Insert a slot row with an arbitrary stored value, so a malformed row
	 * can be seeded the way a truncated write would leave one.
	 *
	 * @param int    $slot  Slot number.
	 * @param string $value Raw option value.
	 *
	 * @return void
	 */
	private function seed_raw_slot_row( int $slot, string $value ) {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture for the DB semaphore.
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				MainWP_Backup_Monitor::SLOT_OPTION_PREFIX . $slot,
				$value
			)
		);
	}

	/**
	 * Collect the slot decisions the diagnostics recorder would receive.
	 *
	 * @return void
	 */
	private function record_slot_events() {
		$this->slot_events = array();
		add_action(
			'mainwp_backup_diag_slot',
			function ( $event, $token, $active, $max_concurrent ) {
				$this->slot_events[] = array(
					'event'          => (string) $event,
					'token'          => (string) $token,
					'active'         => (int) $active,
					'max_concurrent' => (int) $max_concurrent,
				);
			},
			10,
			4
		);
	}

	/**
	 * The recorded events of one kind.
	 *
	 * @param string $event Event name.
	 *
	 * @return array<int, array> Matching events.
	 */
	private function slot_events_named( string $event ): array {
		return array_values( array_filter( $this->slot_events, fn( $recorded ) => $event === $recorded['event'] ) );
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
	/**
	 * A native holder that died leaves a row whose lease has run out. The
	 * lease, not the two-hour age rule, decides: the row is reclaimed
	 * minutes after the driver died even though it was started a minute
	 * ago, and the reclaim names the token it freed.
	 *
	 * @return void
	 */
	public function test_native_row_with_expired_lease_is_reclaimed() {
		$now = time();
		$this->seed_slot_row( 1, 'dead-driver', $now - 60, 'native', $now - 900, 600 );
		$this->record_slot_events();

		$token = MainWP_Backup_Monitor::acquire_backup_slot( 1, 'native' );

		$this->assertIsString( $token, 'An expired lease must not block the next backup.' );
		$tokens = array_column( $this->slot_rows(), 'token' );
		$this->assertNotContains( 'dead-driver', $tokens, 'The expired native row must be gone.' );

		$reclaimed = $this->slot_events_named( 'reclaimed_stale' );
		$this->assertCount( 1, $reclaimed );
		$this->assertSame( 'dead-driver', $reclaimed[0]['token'] );
		$this->assertSame( 1, $reclaimed[0]['max_concurrent'] );
		$this->assertSame( 0, $reclaimed[0]['active'], 'The freed row must not still be counted as active capacity.' );
	}

	/**
	 * Drive the seeded slot through a native acquisition against a limit of
	 * one: a live row holds the only slot and refuses, a dead one is
	 * reclaimed and replaced by the new holder's row.
	 *
	 * @param bool   $live  Whether the seeded row must count as live.
	 * @param string $label Case name, so a failure names the row that broke.
	 *
	 * @return void
	 */
	private function assert_slot_liveness( bool $live, string $label ) {
		$token = MainWP_Backup_Monitor::acquire_backup_slot( 1, 'native' );

		if ( $live ) {
			$this->assertFalse( $token, $label . ': a live row holds the only slot.' );
			return;
		}

		$this->assertIsString( $token, $label . ': a dead row must not block the only slot.' );
		$this->assertSame( array( $token ), array_column( $this->slot_rows(), 'token' ), $label . ': the dead row must be reclaimed and replaced.' );
	}

	/**
	 * The whole liveness rule in one table. A row is live only while
	 * something can still vouch for it: a lease a native holder keeps
	 * refreshing, or - for every other shape - the two-hour age rule. A
	 * heartbeat further ahead of the reader than the lease margin allows
	 * for vouches for nothing, so it is reclaimable immediately rather than
	 * once the skew has elapsed.
	 *
	 * @return void
	 */
	public function test_slot_liveness_follows_one_rule() {
		$now = time();

		// lane, heartbeat, lease, started, live.
		$shaped = array(
			'old format, fresh'                      => array( null, null, null, $now - 60, true ),
			'old format, past two hours'             => array( null, null, null, $now - 3 * HOUR_IN_SECONDS, false ),
			'legacy, fresh'                          => array( 'legacy', null, null, $now - 60, true ),
			'native, lease running'                  => array( 'native', $now - 10, 600, $now - 60, true ),
			'native, lease run out'                  => array( 'native', $now - 900, 600, $now - 60, false ),
			'native, lease running past two hours'   => array( 'native', $now - 60, 600, $now - 3 * HOUR_IN_SECONDS, true ),
			'native, heartbeat inside the tolerance' => array( 'native', $now + 30, 600, $now - 60, true ),
			'native, heartbeat past the tolerance'   => array( 'native', $now + 3600, 600, $now - 60, false ),
			'native, far-future heartbeat, no lease' => array( 'native', $now + 7200, 0, $now - 60, false ),
			'native, lease past the two-hour cap'    => array( 'native', $now - 3 * HOUR_IN_SECONDS, 999999999, $now - 3 * HOUR_IN_SECONDS, false ),
			'native, no lease field, fresh'          => array( 'native', $now - 60, null, $now - 60, true ),
			'native, no lease field, past two hours' => array( 'native', $now - 60, null, $now - 3 * HOUR_IN_SECONDS, false ),
		);

		foreach ( $shaped as $label => $case ) {
			$this->purge_slot_rows();
			$this->seed_slot_row( 1, 'seeded-holder', $case[3], $case[0], $case[1], $case[2] );
			$this->assert_slot_liveness( $case[4], $label );
		}

		$raw = array(
			'no token to release by' => array(
				(string) wp_json_encode(
					array(
						'started' => $now - 60,
						'pid'     => 1,
					)
				),
				false,
			),
			'unreadable value'       => array( '{"token":"truncat', false ),
		);

		foreach ( $raw as $label => $case ) {
			$this->purge_slot_rows();
			$this->seed_raw_slot_row( 1, $case[0] );
			$this->assert_slot_liveness( $case[1], $label );
		}
	}

	/**
	 * The pre-check count and the reclaim loop apply the same rule: two
	 * expired native rows are not counted as live capacity, so the
	 * acquisition proceeds to reclaim one instead of refusing.
	 *
	 * @return void
	 */
	public function test_expired_native_rows_do_not_count_as_live_capacity() {
		$now = time();
		$this->seed_slot_row( 1, 'dead-1', $now - 60, 'native', $now - 900, 600 );
		$this->seed_slot_row( 2, 'dead-2', $now - 60, 'native', $now - 900, 600 );

		$this->assertIsString( MainWP_Backup_Monitor::acquire_backup_slot( 2, 'native' ), 'Two expired leases are not two live holders.' );
	}

	/**
	 * A row no holder can ever release - unreadable, no started stamp, no
	 * token, or a lease long enough to outlast the age rule - is reclaimed
	 * rather than left to block the slot, and the reclaim event names the
	 * token only when the row carried one.
	 *
	 * @return void
	 */
	public function test_unreleasable_rows_are_reclaimed() {
		$now = time();

		$cases = array(
			'truncated JSON'          => array( '{"token":"truncat', '' ),
			'no started stamp'        => array(
				(string) wp_json_encode(
					array(
						'token' => 'no-started',
						'pid'   => 1,
					)
				),
				'no-started',
			),
			'aged, no token'          => array(
				(string) wp_json_encode(
					array(
						'started' => $now - 3 * HOUR_IN_SECONDS,
						'pid'     => 1,
					)
				),
				'',
			),
			'live lease, no token'    => array(
				(string) wp_json_encode(
					array(
						'started'   => $now - 60,
						'pid'       => 1,
						'lane'      => 'native',
						'heartbeat' => $now - 10,
						'lease'     => 600,
					)
				),
				'',
			),
			'lease past the age rule' => array(
				(string) wp_json_encode(
					array(
						'token'     => 'greedy-lease',
						'started'   => $now - 3 * HOUR_IN_SECONDS,
						'pid'       => 1,
						'lane'      => 'native',
						'heartbeat' => $now - 3 * HOUR_IN_SECONDS,
						'lease'     => 999999999,
					)
				),
				'greedy-lease',
			),
		);

		$this->record_slot_events();

		foreach ( $cases as $label => $case ) {
			$this->purge_slot_rows();
			$this->slot_events = array();
			$this->seed_raw_slot_row( 1, $case[0] );

			$this->assertIsString( MainWP_Backup_Monitor::acquire_backup_slot( 1, 'native' ), $label . ': the row must not block the only slot.' );

			$reclaimed = $this->slot_events_named( 'reclaimed_stale' );
			$this->assertCount( 1, $reclaimed, $label . ': the reclaim must be recorded.' );
			$this->assertSame( $case[1], $reclaimed[0]['token'], $label . ': the reclaim names the token the row carried.' );
		}
	}
}
