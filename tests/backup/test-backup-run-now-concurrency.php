<?php
/**
 * MainWP "Run Now" Concurrency Governance Tests
 *
 * The scheduled-run path (execute_backup_task()) caps concurrent backups
 * through MainWP_Backup_Monitor::acquire_backup_slot()/release_backup_slot().
 * The user-initiated "Run Now" path (MainWP_Manage_Backups_Handler::backup())
 * called it, so a user could stack runs past the configured cap and starve
 * dashboard resources the cap exists to protect. These tests prove the same
 * slot governance now guards backup() too: a full slot refuses the call with
 * a coded, catchable exception before the executor ever runs, and a free
 * slot is acquired and released around the executor call - even when the
 * executor itself throws.
 *
 * Deliberately excluded: MainWP_DB_Backup::claim_backup_run(). That is
 * scheduled-run dedup semantics (one driver owns a given run at a time) and
 * has nothing to do with the dashboard-wide concurrency cap under test here.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Run_Now_Concurrency_Test
 */
class MainWP_Backup_Run_Now_Concurrency_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Clean the semaphore and options before each test. The native-backups
	 * flag must be cleared on the way IN, not just out: these tests rely on
	 * the executor's disabled-feature throw, and an earlier test whose
	 * install() call committed the flag mid-transaction (DDL) leaks a
	 * durable enabled state past that test's rollback.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_transient( 'mainwp_backup_slots' );
		delete_option( 'mainwp_backup_max_concurrent' );
		delete_option( 'mainwp_enableNativeBackups' );
	}

	/**
	 * Clean up the semaphore and options after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_transient( 'mainwp_backup_slots' );
		delete_option( 'mainwp_backup_max_concurrent' );
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tearDown();
	}

	/**
	 * Create a manual backup task owned by the given user over one site.
	 *
	 * @param int $owner_id Task owner user id.
	 * @param int $site_id  Site id in the task's sites column.
	 *
	 * @return object Task row.
	 */
	private function create_task( $owner_id, $site_id ) {
		$task = MainWP_DB_Backup::instance()->add_backup_task( $owner_id, 'Run Now concurrency test task', 'manual', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		return $task;
	}

	/**
	 * A Run Now call is refused with a coded exception - not a silent
	 * success, not an uncaught fatal - when the configured concurrency cap
	 * is already full, and never reaches the executor. If the guard were
	 * missing, this call would fall through to the executor and throw its
	 * "native backups are not enabled" exception instead, since native
	 * backups are deliberately left disabled in this test.
	 *
	 * @return void
	 */
	public function test_backup_refuses_run_when_slot_capacity_is_full() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $user, $site_id );

		update_option( 'mainwp_backup_max_concurrent', 1 );
		$held_token = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		$this->assertIsString( $held_token, 'Precondition: the only slot must be held before Run Now is attempted.' );

		$thrown = null;
		try {
			MainWP_Manage_Backups_Handler::backup( $task->id, $site_id, 'uid-concurrency-full-test' );
		} catch ( MainWP_Exception $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( MainWP_Exception::class, $thrown, 'A full concurrency slot must refuse the run with a coded exception instead of running the executor.' );
		$this->assertStringContainsString( 'concurrent', strtolower( $thrown->getMessage() ), 'The concurrency-limit gate specifically, not some other refusal, must be what stops this call.' );
		$this->assertStringNotContainsString( 'Native backups are not enabled', $thrown->getMessage(), 'The refusal must come from the slot guard before the executor, not from falling through into it.' );

		MainWP_Backup_Monitor::release_backup_slot( $held_token );
	}

	/**
	 * When a slot is available, Run Now acquires one and hands off to the
	 * executor - proved here by the executor's own disabled-feature
	 * exception surfacing, since native backups are left off in this test -
	 * and releases the slot afterward even though the executor threw.
	 *
	 * @return void
	 */
	public function test_backup_acquires_and_releases_slot_when_capacity_is_available() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $user, $site_id );

		$this->assertFalse( get_transient( 'mainwp_backup_slots' ), 'Precondition: no slot should be held before the call.' );

		$thrown = null;
		try {
			MainWP_Manage_Backups_Handler::backup( $task->id, $site_id, 'uid-concurrency-available-test' );
		} catch ( MainWP_Exception $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( MainWP_Exception::class, $thrown, 'Precondition: native backups are left disabled so the executor throws quickly without touching the network.' );
		$this->assertStringContainsString( 'Native backups are not enabled', $thrown->getMessage(), 'The call must reach the executor once a slot is available.' );

		$slots = get_transient( 'mainwp_backup_slots' );
		$this->assertTrue( false === $slots || empty( $slots ), 'The slot must be released even though the executor threw.' );
	}
}
