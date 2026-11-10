<?php
/**
 * MainWP backup continuation kick tests.
 *
 * The upload driver and the slice lane kick a continuation at the end of a
 * slice that ran for minutes. By then the request's cached cron array is
 * old: it can list a continuation another cron request already ran, and it
 * lacks events scheduled since. The kick must work from the current schedule.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Continuation_Kick_Test
 */
class MainWP_Backup_Continuation_Kick_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Continuation hook name.
	 */
	const HOOK = 'mainwp_native_backup_continue_action';

	/**
	 * Keep kicks from sending loopbacks: cron kicks defer them to shutdown.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'wp_doing_cron', '__return_true' );
	}

	/**
	 * Drop the events and the deferred loopback.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'wp_doing_cron', '__return_true' );
		remove_action( 'shutdown', array( MainWP_Post_Backup_Handler::class, 'spawn_backup_continuation' ) );
		$this->reload_options();
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( 'mainwp_test_unrelated_hook' );
		parent::tear_down();
	}

	/**
	 * Make this request hold an earlier cron array, as a long cron run does.
	 *
	 * @param array $cron Cron array the request read at start.
	 * @return void
	 */
	private function hold_cron_copy( array $cron ) {
		$alloptions         = wp_load_alloptions();
		$alloptions['cron'] = $cron;
		wp_cache_set( 'alloptions', $alloptions, 'options' );
		wp_cache_set( 'cron', $cron, 'options' );
	}

	/**
	 * Forget cached options so the next read comes from the database.
	 *
	 * @return void
	 */
	private function reload_options() {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'cron', 'options' );
	}

	/**
	 * A continuation already run elsewhere does not stop the kick.
	 *
	 * @return void
	 */
	public function test_kick_schedules_when_held_copy_lists_a_run_continuation() {
		$first = time() - 60;
		$this->assertTrue( wp_schedule_single_event( $first, self::HOOK ) );
		$held = get_option( 'cron' );

		// Another cron request runs that continuation.
		$this->assertTrue( wp_unschedule_event( $first, self::HOOK ) );
		$this->hold_cron_copy( $held );
		$this->assertSame( $first, wp_next_scheduled( self::HOOK ), 'The held copy still lists the run continuation.' );

		$when = time();
		MainWP_Post_Backup_Handler::kick_backup_continuation( $when );

		$this->reload_options();
		$this->assertSame( $when, wp_next_scheduled( self::HOOK ), 'The kick must schedule a new continuation.' );
	}

	/**
	 * The kick writes the current schedule, not the held copy.
	 *
	 * @return void
	 */
	public function test_kick_keeps_events_scheduled_since_the_copy_was_read() {
		$held  = get_option( 'cron' );
		$later = time() + HOUR_IN_SECONDS;
		$this->assertTrue( wp_schedule_single_event( $later, 'mainwp_test_unrelated_hook' ) );
		$this->hold_cron_copy( $held );
		$this->assertFalse( wp_next_scheduled( 'mainwp_test_unrelated_hook' ) );

		$when = time();
		MainWP_Post_Backup_Handler::kick_backup_continuation( $when );

		$this->reload_options();
		$this->assertSame( $when, wp_next_scheduled( self::HOOK ) );
		$this->assertSame( $later, wp_next_scheduled( 'mainwp_test_unrelated_hook' ), 'The kick must not drop an event another request added.' );
	}

	/**
	 * A continuation that is still pending is not duplicated.
	 *
	 * @return void
	 */
	public function test_kick_leaves_a_pending_continuation_alone() {
		$first = time() - 60;
		$this->assertTrue( wp_schedule_single_event( $first, self::HOOK ) );

		MainWP_Post_Backup_Handler::kick_backup_continuation();

		$this->reload_options();
		$this->assertSame( $first, wp_next_scheduled( self::HOOK ) );
		$events = 0;
		foreach ( _get_cron_array() as $hooks ) {
			$events += isset( $hooks[ self::HOOK ] ) ? count( $hooks[ self::HOOK ] ) : 0;
		}
		$this->assertSame( 1, $events );
	}
}
