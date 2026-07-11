<?php
/**
 * Dashboard CLI restore runner tests: the engine-agnostic core behind
 * `wp mainwp backup restore`. The runner mirrors the AJAX initiate path
 * (resolve, confirm-gate parity, initiate) and then drives the job to a
 * terminal state synchronously, since WP-Cron is not driving it under CLI.
 *
 * The WP-CLI command method itself is not exercised here: the command file
 * returns early when WP_CLI is undefined and the harness never defines it,
 * so the testable work lives in the runner and these tests call it directly.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_CLI_Restore_Runner;
use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Local;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Orchestrator double: external reachability pinned, continuation kicks
 * counted instead of scheduling real cron, and the transfer driver
 * overridable so a test can pin the cloud-pull decision.
 */
class CLI_Restore_Test_Orchestrator extends MainWP_Backup_Restore_Orchestrator {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Scripted reachability.
	 *
	 * @var bool
	 */
	public $responds = true;

	/**
	 * Driver override, or null to use the real one.
	 *
	 * @var MainWP_Backup_Restore_Transfer_Driver|null
	 */
	public $driver = null;

	/**
	 * Continuation kicks, so tests need no real cron.
	 *
	 * @var int
	 */
	public $continuation_count = 0;

	/**
	 * Pinned check.
	 *
	 * @param object $website Website.
	 *
	 * @return bool
	 */
	protected function site_responds( object $website ): bool {
		return $this->responds;
	}

	/**
	 * Record the kick instead of scheduling real cron.
	 */
	protected function schedule_continuation(): void {
		++$this->continuation_count;
	}

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
 * Transfer driver that forces the cloud-pull path and builds a manifest
 * straight from the resolved plan, so a local-source job drains to
 * completed in one slice without chunk-transfer accounting.
 */
class CLI_Cloud_Pull_Driver extends MainWP_Backup_Restore_Transfer_Driver {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Whether the gate passes (the tried flag still wins).
	 *
	 * @var bool
	 */
	public $cloud_ok = true;

	/**
	 * Scripted gate.
	 *
	 * @param object $website    Website.
	 * @param array  $plan       Plan.
	 * @param array  $checkpoint Checkpoint.
	 *
	 * @return bool
	 */
	public function can_use_cloud_pull( object $website, array $plan, array $checkpoint ): bool {
		return $this->cloud_ok && empty( $checkpoint['cloud_pull_tried'] );
	}

	/**
	 * Build a manifest from the plan; fill plan hashes like the real one.
	 *
	 * @param array $plan Plan (by reference).
	 *
	 * @return array|null
	 */
	public function generate_pull_manifest( array &$plan ): ?array {
		$artifacts = array();
		foreach ( $plan as $index => $entry ) {
			if ( ! empty( $entry['adopt_only'] ) ) {
				continue;
			}
			$plan[ $index ]['sha256'] = str_repeat( 'a', 64 );
			$artifacts[]              = array(
				'file'   => $entry['file'],
				'sha256' => str_repeat( 'a', 64 ),
				'size'   => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
				'url'    => 'https://bucket.example/' . $entry['file'] . '?X-Amz-Signature=stub',
			);
		}
		return array( 'artifacts' => $artifacts );
	}
}

/**
 * Restore driver that serves remote (sealed) objects from a pinned base
 * path through the real local provider, so an encrypted snapshot stages and
 * decrypts dashboard-side exactly as it would against cloud storage.
 */
class CLI_Decrypt_Test_Driver extends MainWP_Backup_Restore_Transfer_Driver {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Base path the stub provider serves objects from.
	 *
	 * @var string
	 */
	public $remote_base = '';

	/**
	 * Serve remote objects from the pinned base via the real local provider.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return \MainWP\Dashboard\MainWP_Backup_Storage_Provider|null
	 */
	protected function provider_for_snapshot( int $snapshot_id ): ?\MainWP\Dashboard\MainWP_Backup_Storage_Provider {
		if ( '' === $this->remote_base ) {
			return null;
		}
		return new MainWP_Backup_Storage_Local( array( 'base_path' => $this->remote_base ) );
	}
}

/**
 * Class MainWP_Backup_CLI_Restore_Test
 */
class MainWP_Backup_CLI_Restore_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Test site id.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Orchestrator double the runner drives.
	 *
	 * @var CLI_Restore_Test_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Scripted child responses by action.
	 *
	 * @var array
	 */
	private $responses = array();

	/**
	 * Captured child calls.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Fixture dir holding artifact files.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Snapshot ids created, cleaned up in tear_down.
	 *
	 * @var int[]
	 */
	private $snapshot_ids = array();

	/**
	 * Set up site, orchestrator double, scratch, and the child mock.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->site_id      = $this->create_test_site();
		$this->orchestrator = new CLI_Restore_Test_Orchestrator();
		$this->dir          = sys_get_temp_dir() . '/mainwp-cli-restore-test-' . uniqid();
		mkdir( $this->dir );

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what, $params ) {
				$this->calls[] = array(
					'what'   => $what,
					'params' => $params,
				);
				if ( isset( $this->responses[ $what ] ) ) {
					$scripted = $this->responses[ $what ];
					return is_array( $scripted ) && isset( $scripted[0] ) && is_array( $scripted[0] )
						? array_shift( $this->responses[ $what ] )
						: $scripted;
				}
				return array( 'result' => 'ok' );
			},
			10,
			4
		);
	}

	/**
	 * Remove fixtures, snapshot rows, and restore jobs.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		if ( ! empty( $this->snapshot_ids ) ) {
			$ids = implode( ',', array_map( 'intval', $this->snapshot_ids ) );
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . " WHERE id IN ( {$ids} )" ); // phpcs:ignore WordPress.DB -- test cleanup.
		}
		$this->rrmdir( $this->dir );

		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );
		delete_option( 'mainwp_enableNativeBackups' );

		parent::tear_down();
	}

	/**
	 * Recursive directory removal (the encrypted case nests subdirs).
	 *
	 * @param string $dir Directory.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Build the runner with the test orchestrator and zeroed pauses so the
	 * suite never sleeps.
	 *
	 * @return MainWP_Backup_CLI_Restore_Runner
	 */
	private function runner() {
		return new MainWP_Backup_CLI_Restore_Runner( $this->orchestrator, null, null, 30, 0, 0, 0 );
	}

	/**
	 * Insert a snapshot row with an on-disk artifact for the test site.
	 *
	 * @param string $type      Snapshot type (full, db, files, incremental).
	 * @param array  $overrides Row overrides.
	 *
	 * @return int Snapshot id.
	 */
	private function make_snapshot( $type = 'full', $overrides = array() ) {
		global $wpdb;

		$archives = array(
			array(
				'file' => 'full.tar.gz',
				'role' => 'db' === $type ? 'db' : 'full',
			),
		);
		foreach ( $archives as &$archive ) {
			$archive['path'] = $this->dir . '/' . $archive['file'];
			file_put_contents( $archive['path'], 'artifact-bytes-' . $archive['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$archive['size'] = 32;
		}
		unset( $archive );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array_merge(
				array(
					'task_id'       => 991,
					'site_id'       => $this->site_id,
					'type'          => $type,
					'parent_id'     => null,
					'created'       => time(),
					'archives'      => wp_json_encode( $archives ),
					'manifest_path' => '',
					'status'        => 'complete',
				),
				$overrides
			)
		);
		$id                   = (int) $wpdb->insert_id;
		$this->snapshot_ids[] = $id;
		return $id;
	}

	/**
	 * Pin the cloud-pull driver so a local job drains to completed.
	 */
	private function use_cloud_pull_driver() {
		$this->orchestrator->driver = new CLI_Cloud_Pull_Driver();
	}

	/**
	 * Script the child so a cloud-pull job reaches completed.
	 */
	private function script_completed_child() {
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-cli-restore',
			'phase'   => 'queued',
		);
		$this->responses['restore_task_run']    = array(
			'task_id'   => 'task-cli-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
		);
	}

	/**
	 * Count restore jobs for the test site.
	 *
	 * @return int
	 */
	private function count_jobs_for_site() {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB -- test helper.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id = %d',
				$this->site_id
			)
		);
	}

	/**
	 * A valid snapshot for the site resolves, initiates a job, and drives
	 * it to completed.
	 */
	public function test_run_resolves_initiates_and_drives_to_completed() {
		$snapshot_id = $this->make_snapshot( 'full' );
		$this->use_cloud_pull_driver();
		$this->script_completed_child();

		$result = $this->runner()->run( $this->site_id, $snapshot_id, array( 'pre_snapshot' => false ), true );

		$this->assertTrue( $result['ok'], 'A valid restore must report ok.' );
		$this->assertFalse( $result['needs_confirmation'] );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $result['status'] );
		$this->assertGreaterThan( 0, $result['job_id'] );
		$this->assertNull(
			MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ),
			'A completed restore leaves no active job.'
		);
	}

	/**
	 * A database-bearing snapshot refuses without the destructive confirm,
	 * and no job is created.
	 */
	public function test_run_refuses_db_restore_without_confirm() {
		$snapshot_id = $this->make_snapshot( 'full' );

		$result = $this->runner()->run( $this->site_id, $snapshot_id, array( 'pre_snapshot' => false ), false );

		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['needs_confirmation'], 'A non-files restore must require confirmation.' );
		$this->assertSame( 0, $result['job_id'] );
		$this->assertNull(
			MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ),
			'A refused restore must not create a job.'
		);
		$this->assertSame( 0, $this->count_jobs_for_site() );
	}

	/**
	 * A files-only snapshot is not destructive, so it needs no confirmation
	 * and initiates a job.
	 */
	public function test_run_files_snapshot_needs_no_confirmation() {
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		MainWP_DB::instance()->update_website_option( $website, 'restore_files_support', '1' );

		$snapshot_id = $this->make_snapshot( 'files' );
		$this->use_cloud_pull_driver();
		$this->script_completed_child();

		$result = $this->runner()->run( $this->site_id, $snapshot_id, array( 'pre_snapshot' => false ), false );

		$this->assertFalse( $result['needs_confirmation'], 'A files-only restore must not be gated.' );
		$this->assertTrue( $result['ok'], 'A files restore proceeds without confirmation.' );
		$this->assertGreaterThan( 0, $result['job_id'] );
	}

	/**
	 * An unknown snapshot is refused with the catalog error and no job is
	 * created.
	 */
	public function test_run_refuses_unknown_snapshot() {
		$result = $this->runner()->run( $this->site_id, 9999999, array( 'pre_snapshot' => false ), true );

		$this->assertFalse( $result['ok'] );
		$this->assertFalse( $result['needs_confirmation'] );
		$this->assertSame( 0, $result['job_id'] );
		$this->assertNotSame( '', $result['error'], 'An unknown snapshot must explain itself.' );
		$this->assertSame( 0, $this->count_jobs_for_site() );
	}

	/**
	 * A snapshot belonging to another site is refused with the catalog
	 * cross-site error and no job is created.
	 */
	public function test_run_refuses_wrong_site_snapshot() {
		$snapshot_id = $this->make_snapshot( 'full', array( 'site_id' => $this->site_id + 4242 ) );

		$result = $this->runner()->run( $this->site_id, $snapshot_id, array( 'pre_snapshot' => false ), true );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'does not belong', $result['error'] );
		$this->assertSame( 0, $this->count_jobs_for_site() );
	}

	/**
	 * A second restore is refused while one is already active for the site,
	 * leaving the original job untouched.
	 */
	public function test_run_refuses_when_active_job_exists() {
		$snapshot_id = $this->make_snapshot( 'full' );
		MainWP_Backup_Restore_Job_Store::instance()->create_job(
			$this->site_id,
			$snapshot_id,
			array(
				'plan'    => array(),
				'options' => array(),
			)
		);

		$result = $this->runner()->run( $this->site_id, $snapshot_id, array( 'pre_snapshot' => false ), true );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'already running', $result['error'] );
		$this->assertSame( 1, $this->count_jobs_for_site(), 'No second job may be created.' );
	}

	/**
	 * An encrypted snapshot drives through the same path: the runner adds no
	 * encryption special case, so staging decrypts the offsite ciphertext
	 * dashboard-side and the job reaches completed. (The seal/open round-trip
	 * itself is proven by test-backup-restore-decryption.php; this proves the
	 * CLI entry point drives it unchanged.)
	 */
	public function test_run_drives_encrypted_snapshot_through_staging() {
		$enc = MainWP_Backup_Encryption::instance();
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '1' );
		$enc->set_recovery_passphrase( 'cli restore decryption passphrase' );
		add_filter(
			'mainwp_backup_upload_whole_file_threshold',
			static function () {
				return 100000;
			}
		);

		$base    = $this->dir . '/dest';
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'cli encrypted',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$plain = 'encrypted database dump bytes for the cli restore drive';
		$src   = $this->dir . '/src';
		mkdir( $src );
		file_put_contents( $src . '/db.sql.gz', $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$snapshot_id          = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => $this->site_id,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => array(
					array(
						'file' => 'db.sql.gz',
						'size' => strlen( $plain ),
						'role' => 'full',
						'path' => $src . '/db.sql.gz',
					),
				),
				'manifest_path' => '',
			)
		);
		$this->snapshot_ids[] = $snapshot_id;

		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		// Drop the local source so resolution must pull the sealed copy from
		// the destination, exercising the dashboard-side staging decrypt.
		unlink( $src . '/db.sql.gz' );

		$driver                     = new CLI_Decrypt_Test_Driver();
		$driver->remote_base        = $base;
		$this->orchestrator->driver = $driver;

		$this->responses['restore_task_create']    = array(
			'task_id' => 'task-cli-enc',
			'phase'   => 'queued',
		);
		$this->responses['restore_artifact_chunk'] = array(
			'result'         => 'ok',
			'file'           => 'db.sql.gz',
			'bytes_received' => strlen( $plain ),
			'phase'          => 'receiving',
		);
		$this->responses['restore_task_run']       = array(
			'task_id'   => 'task-cli-enc',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
		);

		$result = $this->runner()->run( $this->site_id, $snapshot_id, array( 'pre_snapshot' => false ), true );

		$this->assertTrue( $result['ok'], 'Encrypted restore must complete; error: ' . $result['error'] );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $result['status'] );
	}

	/**
	 * drive_to_terminal() must scope the orchestrator slice to the job it was
	 * asked to drive. Two sites each have a pending job; the target job (the
	 * one created second, higher id) must be the one that reaches completed,
	 * and the other site's job must be left untouched rather than advanced as
	 * a side effect of an unscoped acquire.
	 */
	public function test_drive_to_terminal_advances_only_the_target_job() {
		$snapshot_id_1 = $this->make_snapshot( 'full' );
		$job_id_1      = $this->orchestrator->initiate_restore( $this->site_id, $snapshot_id_1, array( 'pre_snapshot' => false ) );

		$site_id_2     = $this->create_test_site();
		$snapshot_id_2 = $this->make_snapshot( 'full', array( 'site_id' => $site_id_2 ) );
		$job_id_2      = $this->orchestrator->initiate_restore( $site_id_2, $snapshot_id_2, array( 'pre_snapshot' => false ) );

		$this->use_cloud_pull_driver();
		$this->script_completed_child();

		$final = $this->runner()->drive_to_terminal( $job_id_2 );

		$this->assertNotNull( $final );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $final->status );

		$other = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id_1 );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $other->status, "A different site's pending job must not advance as a side effect." );
	}
}
