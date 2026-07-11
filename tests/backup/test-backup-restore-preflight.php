<?php
/**
 * Restore preflight tests: the dashboard remote-artifact check fails a
 * doomed restore before any transfer or destructive work, a healthy plan
 * advances, and a preflight-state job counts as an active restore.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_DB;

/**
 * Transfer driver with the remote-artifact check scripted.
 */
class Preflight_Stub_Transfer_Driver extends MainWP_Backup_Restore_Transfer_Driver {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Scripted artifact availability.
	 *
	 * @var bool
	 */
	public $present = true;

	/**
	 * Scripted preflight result.
	 *
	 * @param array $plan Plan.
	 *
	 * @return bool
	 */
	public function remote_artifacts_present( array $plan ): bool {
		return $this->present;
	}
}

/**
 * Orchestrator with the transfer driver pinned.
 */
class Preflight_Test_Orchestrator extends MainWP_Backup_Restore_Orchestrator {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double above.

	/**
	 * Driver override.
	 *
	 * @var MainWP_Backup_Restore_Transfer_Driver|null
	 */
	public $driver = null;

	/**
	 * Pinned driver.
	 *
	 * @return MainWP_Backup_Restore_Transfer_Driver
	 */
	protected function transfer_driver(): MainWP_Backup_Restore_Transfer_Driver {
		return null !== $this->driver ? $this->driver : parent::transfer_driver();
	}
}

/**
 * Class MainWP_Backup_Restore_Preflight_Test
 */
class MainWP_Backup_Restore_Preflight_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Test site id.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Scripted driver.
	 *
	 * @var Preflight_Stub_Transfer_Driver
	 */
	private $driver;

	/**
	 * Orchestrator under test.
	 *
	 * @var Preflight_Test_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Set up the site and a preflight-pinned orchestrator.
	 */
	public function set_up() {
		parent::set_up();
		$this->site_id              = $this->create_test_site();
		$this->driver               = new Preflight_Stub_Transfer_Driver();
		$this->orchestrator         = new Preflight_Test_Orchestrator();
		$this->orchestrator->driver = $this->driver;
	}

	/**
	 * Drop restore jobs between tests.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		parent::tear_down();
	}

	/**
	 * Create a job already in the preflight phase.
	 *
	 * @param array $options Checkpoint options overrides.
	 *
	 * @return object Job row.
	 */
	private function make_preflight_job( $options = array() ) {
		$checkpoint = array(
			'restore_id' => 'preflighttest',
			'sub_type'   => 'db',
			'plan'       => array(
				array(
					'role'        => 'db',
					'file'        => 'db.zip',
					'snapshot_id' => 5,
					'source'      => 'local',
					'adopt_only'  => false,
				),
			),
			'options'    => array_merge(
				array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				$options
			),
			'transfer'   => array(),
		);

		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job( $this->site_id, 5, $checkpoint );
		$store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT );
		return $store->get_job( $job_id );
	}

	/**
	 * Run the job one slice.
	 *
	 * @param object $job Job row.
	 *
	 * @return object Fresh job row.
	 */
	private function tick( $job ) {
		$this->orchestrator->run_job( MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id ), microtime( true ) + 30 );
		return MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
	}

	/**
	 * A healthy plan passes preflight and routes to staging.
	 */
	public function test_preflight_passes_to_staging_when_artifacts_present() {
		$this->driver->present = true;

		$job = $this->tick( $this->make_preflight_job() );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
	}

	/**
	 * A missing remote artifact fails the restore permanently, with the
	 * stable code and before any transfer.
	 */
	public function test_preflight_fails_permanently_when_remote_artifact_missing() {
		$this->driver->present = false;

		$job = $this->tick( $this->make_preflight_job() );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'mainwp_restore_artifact_missing', $job->checkpoint['last_error_code'] );
	}

	/**
	 * A job sitting in preflight is an active restore and is claimable, so
	 * the run loop picks it up and a second restore is refused.
	 */
	public function test_preflight_job_is_active_and_claimable() {
		$job   = $this->make_preflight_job();
		$store = MainWP_Backup_Restore_Job_Store::instance();

		$active = $store->get_active_job_for_site( $this->site_id );
		$this->assertNotNull( $active );
		$this->assertSame( (int) $job->id, (int) $active->id );

		$claimed = $store->acquire_next_job( 'tok-preflight', 300 );
		$this->assertNotNull( $claimed );
		$this->assertSame( (int) $job->id, (int) $claimed->id );
	}
}
