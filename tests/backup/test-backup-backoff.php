<?php
/**
 * Backoff helper tests: the one retry schedule every backup lane uses.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Backoff;

/**
 * Class MainWP_Backup_Backoff_Test
 */
class MainWP_Backup_Backoff_Test extends \WP_UnitTestCase {

	/**
	 * The schedule doubles from the base and caps at the ceiling.
	 *
	 * @return void
	 */
	public function test_delay_doubles_from_base_and_caps() {
		$this->assertSame( 300, MainWP_Backup_Backoff::delay_for_attempt( 1 ) );
		$this->assertSame( 600, MainWP_Backup_Backoff::delay_for_attempt( 2 ) );
		$this->assertSame( 1200, MainWP_Backup_Backoff::delay_for_attempt( 3 ) );
		$this->assertSame( 9600, MainWP_Backup_Backoff::delay_for_attempt( 6 ) );
		$this->assertSame( 14400, MainWP_Backup_Backoff::delay_for_attempt( 7 ) );
		$this->assertSame( 14400, MainWP_Backup_Backoff::delay_for_attempt( 12 ), 'The ceiling holds for any later attempt.' );
	}

	/**
	 * Attempt numbers below one are clamped to the first delay.
	 *
	 * @return void
	 */
	public function test_delay_clamps_low_attempts() {
		$this->assertSame( 300, MainWP_Backup_Backoff::delay_for_attempt( 0 ) );
		$this->assertSame( 300, MainWP_Backup_Backoff::delay_for_attempt( -3 ) );
	}

	/**
	 * Exhaustion triggers at the shared attempt ceiling.
	 *
	 * @return void
	 */
	public function test_attempts_exhausted_at_ceiling() {
		$this->assertFalse( MainWP_Backup_Backoff::attempts_exhausted( 1 ) );
		$this->assertFalse( MainWP_Backup_Backoff::attempts_exhausted( MainWP_Backup_Backoff::MAX_ATTEMPTS - 1 ) );
		$this->assertTrue( MainWP_Backup_Backoff::attempts_exhausted( MainWP_Backup_Backoff::MAX_ATTEMPTS ) );
		$this->assertTrue( MainWP_Backup_Backoff::attempts_exhausted( MainWP_Backup_Backoff::MAX_ATTEMPTS + 1 ) );
	}
}
