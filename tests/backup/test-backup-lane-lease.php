<?php
/**
 * Shared backup lane ownership and fencing tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Server_Information;

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
	 * @return void
	 */
	private function seed( string $token, int $age = 0 ): void {
		global $wpdb;
		$value = wp_json_encode(
			array(
				'token'     => $token,
				'started'   => time() - $age,
				'heartbeat' => time() - $age,
				'pid'       => 123,
				'lease'     => 600,
			)
		);
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- semaphore fixture.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", MainWP_Backup_Monitor::LANE_LEASE_OPTION, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- semaphore fixture.
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
		$this->seed( 'replacement', 5 );
		$row    = $this->row();
		$events = array();
		$record = static function ( $event, $token, $active ) use ( &$events ) {
			$events[] = array( $event, $token, $active );
		};
		add_action( 'mainwp_backup_diag_lane', $record, 10, 3 );
		try {
			$this->assertFalse( MainWP_Backup_Monitor::refresh_lane_lease() );
			$this->assertCount( 1, $events );
			$this->assertSame( 'lost', $events[0][0] );
			$this->assertSame( 1, $events[0][2], 'The replacement holder remains active.' );
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
