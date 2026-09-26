<?php
/**
 * Shared backup lane ownership and fencing tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Server_Information;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

/**
 * Class MainWP_Backup_Lane_Lease_Test
 */
class MainWP_Backup_Lane_Lease_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Keep process ownership from leaking between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		MainWP_Backup_Monitor::release_lane_lease();
		delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
		parent::tear_down();
	}

	/**
	 * Read ownership without the options cache.
	 *
	 * @return array|null Stored lease.
	 */
	private function row(): ?array {
		global $wpdb;
		return json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- semaphore assertion.
	}

	/**
	 * Simulate a different process owning the row.
	 *
	 * @param string $token Owner token.
	 * @param int    $age Heartbeat age in seconds.
	 * @param int    $lease Lease duration in seconds.
	 * @return void
	 */
	private function seed( string $token, int $age = 0, int $lease = 600 ): void {
		global $wpdb;
		$value = wp_json_encode(
			array(
				'token'     => $token,
				'started'   => time() - $age,
				'heartbeat' => time() - $age,
				'pid'       => 123,
				'lease'     => $lease,
			)
		);
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- semaphore fixture.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", MainWP_Backup_Monitor::LANE_LEASE_OPTION, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- semaphore fixture.
	}

	/**
	 * Store exact bytes for malformed lease fixtures.
	 *
	 * @param string $value Raw option value.
	 * @return void
	 */
	private function seed_raw( string $value ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- semaphore fixture.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", MainWP_Backup_Monitor::LANE_LEASE_OPTION, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- semaphore fixture.
		$this->assertSame( 1, $inserted, 'Precondition: the raw lane fixture must be stored.' );
	}

	/**
	 * Read the exact stored lane bytes; get_var() would turn an empty value into null.
	 *
	 * @return string|null Stored value, null when no row exists.
	 */
	private function raw_value(): ?string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- assert exact stored bytes.
		return null === $row ? null : (string) $row->option_value;
	}

	/**
	 * Seed a live native slot for request coverage assertions.
	 *
	 * @return string Slot token.
	 */
	private function seed_native_slot(): string {
		global $wpdb;
		$token    = 'lane-test-native-slot';
		$value    = wp_json_encode(
			array(
				'token'     => $token,
				'started'   => time(),
				'heartbeat' => time(),
				'lease'     => 600,
				'lane'      => 'native',
				'pid'       => 123,
			)
		);
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", MainWP_Backup_Monitor::SLOT_OPTION_PREFIX . '1', $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- semaphore fixture.
		$this->assertSame( 1, $inserted, 'Precondition: the native slot fixture must be stored.' );
		return $token;
	}

	/**
	 * Losing the lane removes request coverage even while the slot is live.
	 */
	public function test_lane_loss_cuts_native_coverage() {
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		$token = $this->seed_native_slot();
		MainWP_Backup_Monitor::begin_native_lease( $token );
		try {
			$this->seed( 'replacement' );
			$before = $this->row();
			$this->assertSame( 0, MainWP_Backup_Monitor::touch_native_leases( 960 ) );
			$this->assertTrue( MainWP_Backup_Monitor::native_lease_lost() );
			$this->assertSame( $before, $this->row() );
		} finally {
			MainWP_Backup_Monitor::end_native_lease( $token );
			MainWP_Backup_Monitor::release_backup_slot( $token );
		}
	}

	/**
	 * A held lane preserves coverage for a live native slot.
	 */
	public function test_held_lane_preserves_native_coverage() {
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		$token = $this->seed_native_slot();
		MainWP_Backup_Monitor::begin_native_lease( $token );
		try {
			$this->assertGreaterThan( 0, MainWP_Backup_Monitor::touch_native_leases( 960 ) );
			$this->assertFalse( MainWP_Backup_Monitor::native_lease_lost() );
		} finally {
			MainWP_Backup_Monitor::end_native_lease( $token );
			MainWP_Backup_Monitor::release_backup_slot( $token );
		}
	}

	/**
	 * Lane liveness tolerates clock skew and rejects expired or malformed rows.
	 */
	public function test_lane_liveness_matrix() {
		$now   = time();
		$cases = array(
			'fresh'             => array( 0, 600, null, false ),
			'expired'           => array( 601, 600, null, true ),
			'expiry boundary'   => array( 600, 600, null, true ),
			'future 30 seconds' => array( -30, 600, null, false ),
			'future one hour'   => array( -3600, 600, null, false ),
			'future over limit' => array( -7201, 600, null, true ),
			'extended lease'    => array( 1000, 1860, null, false ),
			'cap expired'       => array( 7300, 99999, null, true ),
			'cap live'          => array( 7000, 99999, null, false ),
			'numeric strings'   => array( 0, 600, '{"token":"other-owner","heartbeat":"' . $now . '","lease":"600"}', false ),
			'missing token'     => array( 0, 600, '{"heartbeat":' . $now . ',"lease":600}', true ),
			'empty token'       => array( 0, 600, '{"token":"","heartbeat":' . $now . ',"lease":600}', true ),
			'integer token'     => array( 0, 600, '{"token":5,"heartbeat":' . $now . ',"lease":600}', true ),
			'invalid heartbeat' => array( 0, 600, '{"token":"other-owner","heartbeat":"abc","lease":600}', true ),
			'missing lease'     => array( 0, 600, '{"token":"other-owner","heartbeat":' . $now . '}', true ),
			'invalid JSON'      => array( 0, 600, 'garbage', true ),
			'empty value'       => array( 0, 600, '', true ),
		);
		foreach ( $cases as $label => list( $age, $lease, $raw, $reclaimed ) ) {
			if ( null === $raw ) {
				$this->seed( 'other-owner', $age, $lease );
			} else {
				$this->seed_raw( $raw );
			}
			$before = $this->raw_value();
			$this->assertIsString( $before, $label );
			if ( null !== $raw ) {
				$this->assertSame( $raw, $before, $label );
			}
			$events = array();
			$record = static function ( $event ) use ( &$events ) {
				$events[] = $event;
			};
			add_action( 'mainwp_backup_diag_lane', $record );
			try {
				$token = MainWP_Backup_Monitor::acquire_lane_lease();
				if ( $reclaimed ) {
					$this->assertIsString( $token, $label );
					$this->assertNotSame( '', $token, $label );
					$this->assertSame( $token, $this->row()['token'], $label );
					$this->assertSame( array( 'reclaimed_stale', 'acquired' ), $events, $label );
				} else {
					$this->assertFalse( $token, $label );
					$this->assertSame( $before, $this->raw_value(), $label );
					$this->assertSame( array( 'refused' ), $events, $label );
				}
			} finally {
				remove_action( 'mainwp_backup_diag_lane', $record );
				MainWP_Backup_Monitor::release_lane_lease();
			}
		}
	}

	/**
	 * Refusal reports the duration stored by the live holder.
	 */
	public function test_refused_diagnostic_reports_stored_lease() {
		$this->seed( 'other-owner', 0, 1860 );
		$events = array();
		$record = static function ( $event, $token, $active, $lease_seconds ) use ( &$events ) {
			$events[] = array( $event, $lease_seconds );
		};
		add_action( 'mainwp_backup_diag_lane', $record, 10, 4 );
		try {
			$this->assertFalse( MainWP_Backup_Monitor::acquire_lane_lease() );
			$this->assertSame( array( array( 'refused', 1860 ) ), $events );
		} finally {
			remove_action( 'mainwp_backup_diag_lane', $record );
		}
	}

	/**
	 * A claim is exclusive and refresh changes only its heartbeat.
	 */
	public function test_acquire_refuse_refresh_and_release() {
		$this->assertFalse( MainWP_Backup_Monitor::lane_lease_lost() );
		$this->assertTrue( MainWP_Backup_Monitor::confirm_lane_lease() );
		$token = MainWP_Backup_Monitor::acquire_lane_lease();
		$this->assertIsString( $token );
		$row = $this->row();
		$this->assertSame( array( 'token', 'started', 'heartbeat', 'pid', 'lease' ), array_keys( $row ) );
		$this->assertSame( $token, $row['token'] );
		$this->assertSame( 600, $row['lease'] );
		$this->assertFalse( MainWP_Backup_Monitor::acquire_lane_lease() );
		$this->assertSame( $row, $this->row() );
		$this->seed( $token, 10 );
		$before = $this->row();
		$this->assertTrue( MainWP_Backup_Monitor::refresh_lane_lease() );
		$after = $this->row();
		$this->assertGreaterThan( $before['heartbeat'], $after['heartbeat'] );
		$before['heartbeat'] = $after['heartbeat'];
		$this->assertSame( $before, $after );
		$this->assertTrue( MainWP_Backup_Monitor::refresh_lane_lease(), 'Repeating a heartbeat within a second still owns the row.' );
		MainWP_Backup_Monitor::release_lane_lease();
		$this->assertNull( $this->row() );
		$this->assertFalse( MainWP_Backup_Monitor::lane_lease_lost() );
		$this->assertTrue( MainWP_Backup_Monitor::confirm_lane_lease() );
	}

	/**
	 * Native request coverage also renews the lane without a native slot token.
	 */
	public function test_native_heartbeat_extends_lane_lease() {
		$token = MainWP_Backup_Monitor::acquire_lane_lease();
		$this->assertIsString( $token );
		$this->seed( $token, 700 );
		$before = time();
		MainWP_Backup_Monitor::touch_native_leases( 1860 );
		$row = $this->row();
		$this->assertSame( $token, $row['token'] );
		$this->assertGreaterThanOrEqual( $before, $row['heartbeat'] );
		$this->assertLessThanOrEqual( time(), $row['heartbeat'] );
		$this->assertGreaterThanOrEqual( 1860, $row['lease'] );
		$this->assertTrue( MainWP_Backup_Monitor::confirm_lane_lease() );
		$this->assertGreaterThan( time(), $row['heartbeat'] + $row['lease'], 'A competing acquirer must refuse this live row even without a local token.' );
	}

	/**
	 * Callers outside the lane neither create nor refresh its row.
	 */
	public function test_native_heartbeat_without_lane_token_leaves_option_untouched() {
		$this->assertTrue( MainWP_Backup_Monitor::refresh_lane_lease( 1860 ) );
		$this->assertSame( PHP_INT_MAX, MainWP_Backup_Monitor::touch_native_leases( 1860 ) );
		$this->assertNull( $this->row() );
		$this->seed( 'other-owner', 700 );
		$before = $this->row();
		MainWP_Backup_Monitor::touch_native_leases( 1860 );
		$this->assertSame( $before, $this->row() );
	}

	/**
	 * Maintenance ticks cannot stand in for missing minutely ticks.
	 */
	public function test_continue_tick_does_not_stamp_slice() {
		update_option( 'mainwp_enableNativeBackups', 1 );
		delete_option( 'mainwp_cron_last_backups_slice' );
		delete_option( 'mainwp_cron_last_backups_continue' );
		$before = time();
		MainWP_System_Cron_Jobs::instance()->cron_backups_continue();
		$this->assertFalse( get_option( 'mainwp_cron_last_backups_slice' ) );
		$this->assertGreaterThanOrEqual( $before, (int) get_option( 'mainwp_cron_last_backups_continue' ) );
	}

	/**
	 * Expiry permits reclamation and identifies the stale holder.
	 */
	public function test_stale_row_is_reclaimed() {
		$this->seed( 'stale', 601 );
		$events = array();
		$record = static function ( $event, $token ) use ( &$events ) {
			$events[] = array( $event, $token );
		};
		add_action( 'mainwp_backup_diag_lane', $record, 10, 2 );
		try {
			$token = MainWP_Backup_Monitor::acquire_lane_lease();
			$this->assertIsString( $token );
			$this->assertSame( $token, $this->row()['token'] );
			$this->assertSame( array( array( 'reclaimed_stale', 'stale' ), array( 'acquired', $token ) ), $events );
		} finally {
			remove_action( 'mainwp_backup_diag_lane', $record );
		}
	}

	/**
	 * A previous holder cannot refresh or delete a replacement owner.
	 */
	public function test_reowned_row_fences_refresh_and_survives_release() {
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		$this->seed( 'replacement', 5, 1860 );
		$row    = $this->row();
		$events = array();
		$record = static function ( $event, $token, $active, $lease_seconds ) use ( &$events ) {
			$events[] = array( $event, $token, $active, $lease_seconds );
		};
		add_action( 'mainwp_backup_diag_lane', $record, 10, 4 );
		try {
			$this->assertFalse( MainWP_Backup_Monitor::refresh_lane_lease() );
			$this->assertCount( 1, $events );
			$this->assertSame( 'lost', $events[0][0] );
			$this->assertSame( 1, $events[0][2], 'The replacement holder remains active.' );
			$this->assertSame( 1860, $events[0][3] );
		} finally {
			remove_action( 'mainwp_backup_diag_lane', $record );
		}
		$this->assertTrue( MainWP_Backup_Monitor::lane_lease_lost() );
		$this->assertFalse( MainWP_Backup_Monitor::confirm_lane_lease() );
		$this->assertSame( $row, $this->row() );
		MainWP_Backup_Monitor::release_lane_lease();
		$this->assertSame( $row, $this->row() );
		$this->assertFalse( MainWP_Backup_Monitor::lane_lease_lost() );
		$this->assertTrue( MainWP_Backup_Monitor::confirm_lane_lease() );
	}

	/**
	 * Removing the row fences the holder and emits loss only once.
	 */
	public function test_missing_row_marks_loss_once() {
		global $wpdb;
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- simulate an expired holder being removed.
		$events = array();
		$record = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		add_action( 'mainwp_backup_diag_lane', $record );
		try {
			$this->assertFalse( MainWP_Backup_Monitor::confirm_lane_lease() );
			$this->assertFalse( MainWP_Backup_Monitor::refresh_lane_lease() );
			$this->assertTrue( MainWP_Backup_Monitor::lane_lease_lost() );
			$this->assertSame( array( 'lost' ), $events );
		} finally {
			remove_action( 'mainwp_backup_diag_lane', $record );
		}
	}

	/**
	 * Only enabled backups with a missing or stale tick need the warning.
	 */
	public function test_server_information_slice_warning() {
		update_option( 'mainwp_enableNativeBackups', 1 );
		delete_option( 'mainwp_cron_last_backups_slice' );
		$this->assertTrue( MainWP_Server_Information::is_backup_slice_stale() );
		update_option( 'mainwp_cron_last_backups_slice', time() - 181 );
		$this->assertTrue( MainWP_Server_Information::is_backup_slice_stale() );
		update_option( 'mainwp_cron_last_backups_slice', time() );
		$this->assertFalse( MainWP_Server_Information::is_backup_slice_stale() );
		update_option( 'mainwp_cron_last_backups_slice', time() - 181 );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$this->assertFalse( MainWP_Server_Information::is_backup_slice_stale() );
	}
}
