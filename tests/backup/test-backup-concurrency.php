<?php
/**
 * MainWP Backup Concurrency Tests
 *
 * Tests for the transient-based semaphore that caps how many backup
 * tasks run at the same time.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Handler;

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
		delete_transient( 'mainwp_backup_slots' );
		delete_option( 'mainwp_backup_max_concurrent' );
		parent::tear_down();
	}

	/**
	 * Slots are granted up to the limit and refused beyond it.
	 *
	 * @return void
	 */
	public function test_slots_granted_up_to_limit_then_refused() {
		$first  = MainWP_Backup_Handler::acquire_backup_slot( 2 );
		$second = MainWP_Backup_Handler::acquire_backup_slot( 2 );
		$third  = MainWP_Backup_Handler::acquire_backup_slot( 2 );

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
		$first  = MainWP_Backup_Handler::acquire_backup_slot( 2 );
		$second = MainWP_Backup_Handler::acquire_backup_slot( 2 );
		$this->assertIsString( $second );

		MainWP_Backup_Handler::release_backup_slot( $first );

		$this->assertIsString( MainWP_Backup_Handler::acquire_backup_slot( 2 ), 'A released slot must be reusable.' );
	}

	/**
	 * Slots from crashed processes expire after two hours.
	 *
	 * @return void
	 */
	public function test_stale_slots_are_reclaimed() {
		set_transient(
			'mainwp_backup_slots',
			array(
				'stale-token-1' => array(
					'started' => time() - 3 * HOUR_IN_SECONDS,
					'pid'     => 1,
				),
				'stale-token-2' => array(
					'started' => time() - 3 * HOUR_IN_SECONDS,
					'pid'     => 2,
				),
			),
			2 * HOUR_IN_SECONDS
		);

		$token = MainWP_Backup_Handler::acquire_backup_slot( 2 );

		$this->assertIsString( $token, 'Stale slots must not block new backups.' );
		$slots = get_transient( 'mainwp_backup_slots' );
		$this->assertArrayNotHasKey( 'stale-token-1', $slots );
		$this->assertArrayNotHasKey( 'stale-token-2', $slots );
	}

	/**
	 * The configured option controls the slot limit.
	 *
	 * @return void
	 */
	public function test_option_controls_limit() {
		update_option( 'mainwp_backup_max_concurrent', 1 );
		$max = MainWP_Backup_Handler::get_max_concurrent_backups();

		$this->assertSame( 1, $max );
		$this->assertIsString( MainWP_Backup_Handler::acquire_backup_slot( $max ) );
		$this->assertFalse( MainWP_Backup_Handler::acquire_backup_slot( $max ) );
	}
}
