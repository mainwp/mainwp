<?php
/**
 * MainWP "Back up now" immediate-continuation tests.
 *
 * An on-demand backup kicks a near-term continuation so a tab-closed run
 * finishes within seconds instead of waiting for the 5-minute recurring
 * backup cron. The continuation rides a dedicated single-event hook
 * (mainwp_native_backup_continue_action) that reuses the same
 * cron_backups_continue handler, because the recurring
 * mainwp_cronbackups_continue_action cannot be re-triggered immediately.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

/**
 * Class MainWP_Backup_Now_Continuation_Test
 */
class MainWP_Backup_Now_Continuation_Test extends \WP_UnitTestCase {

	/**
	 * Clear the continuation event between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_clear_scheduled_hook( 'mainwp_native_backup_continue_action' );
		parent::tear_down();
	}

	/**
	 * Invoke the protected static kick helper.
	 *
	 * @return void
	 */
	private function kick() {
		$method = new \ReflectionMethod( MainWP_Post_Backup_Handler::class, 'kick_backup_continuation' );
		$method->setAccessible( true );
		$method->invoke( null );
	}

	/**
	 * The helper schedules an immediate continuation event.
	 *
	 * @return void
	 */
	public function test_kick_schedules_continuation_event() {
		// Keep spawn_cron from making a real outbound request.
		$short_circuit = function () {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $short_circuit );

		$this->assertFalse( wp_next_scheduled( 'mainwp_native_backup_continue_action' ), 'No continuation should be pending before the kick.' );

		$this->kick();

		$this->assertNotFalse( wp_next_scheduled( 'mainwp_native_backup_continue_action' ), 'The kick must schedule a continuation event.' );

		remove_filter( 'pre_http_request', $short_circuit );
	}

	/**
	 * The continuation hook is wired to the shared backup-continue handler,
	 * so it drives the same work the recurring cron does.
	 *
	 * @return void
	 */
	public function test_continuation_hook_registered_to_backup_continue_handler() {
		MainWP_System_Cron_Jobs::instance()->init_cron_jobs();

		$this->assertNotFalse(
			has_action(
				'mainwp_native_backup_continue_action',
				array( MainWP_System_Cron_Jobs::instance(), 'cron_backups_continue' )
			),
			'The single-event continuation hook must reuse cron_backups_continue.'
		);
	}

	/**
	 * The continuation hook stays single-event only: it must not be added to
	 * the recurring cron schedule (that is the lane it exists to work around).
	 *
	 * @return void
	 */
	public function test_continuation_hook_is_not_a_recurring_cron_job() {
		$jobs = MainWP_System_Cron_Jobs::instance()->get_cron_jobs();

		$this->assertArrayNotHasKey( 'mainwp_native_backup_continue_action', $jobs, 'The continuation trigger must stay single-event only.' );
	}

	/**
	 * Disabling native backups must not strand a restore mid-flight: both
	 * cron lanes still drive nonterminal restore jobs to a terminal state.
	 * The toggle governs STARTING work (initiate_restore refuses), not
	 * abandoning destructive work already running on a child site.
	 *
	 * @return void
	 */
	public function test_disabled_toggle_still_drives_restore_jobs_on_cron_lanes() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );

		$spy = new class() extends MainWP_Backup_Restore_Orchestrator {
			/**
			 * Drive-call count.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * Record the drive instead of running jobs.
			 *
			 * @param int      $budget_seconds Slice budget.
			 * @param int|null $only_job_id    Job filter.
			 */
			public function process_pending_restore_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $only_job_id = null ): void {
				unset( $budget_seconds, $only_job_id );
				++$this->calls;
			}
		};

		$prop = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'instance' );
		$prop->setAccessible( true );
		$saved = $prop->getValue();
		$prop->setValue( null, $spy );

		try {
			MainWP_System_Cron_Jobs::instance()->cron_restore_continue();
			$this->assertSame( 1, $spy->calls, 'The dedicated restore lane must run with the feature toggled off.' );

			MainWP_System_Cron_Jobs::instance()->cron_backups_continue();
			$this->assertSame( 2, $spy->calls, 'The periodic continuation lane must still finish restore jobs with the feature toggled off.' );
		} finally {
			$prop->setValue( null, $saved );
			delete_option( 'mainwp_enableNativeBackups' );
			delete_option( 'mainwp_enableLegacyBackupFeature' );
		}
	}
}
