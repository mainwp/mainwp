<?php
/**
 * A role change between chunks must stop work that the job owner can no longer
 * authorize. The global rights wrapper bypasses checks under DOING_CRON, so
 * workers need the direct-filter check. Legacy jobs without a rights stamp
 * must keep processing after deployment.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Cron;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Batch_Cron_Required_Rights extends MainWP_Abilities_Test_Case {

	/**
	 * The exact right filter callback added by this test, for targeted removal.
	 *
	 * @var callable|null
	 */
	private $right_filter = null;

	/**
	 * The remote-call spy filter, for targeted removal.
	 *
	 * @var callable|null
	 */
	private $remote_spy = null;

	/**
	 * Remote calls observed by the spy.
	 *
	 * @var array
	 */
	private $remote_calls = array();

	/**
	 * The chunk-size filter, for targeted removal.
	 *
	 * @var callable|null
	 */
	private $chunk_filter = null;

	public function tearDown(): void {
		if ( null !== $this->right_filter ) {
			remove_filter( 'mainwp_currentusercan', $this->right_filter, 10 );
			$this->right_filter = null;
		}
		if ( null !== $this->remote_spy ) {
			remove_filter( 'mainwp_fetch_url_authed_pre', $this->remote_spy, 10 );
			$this->remote_spy = null;
		}
		if ( null !== $this->chunk_filter ) {
			remove_filter( 'mainwp_abilities_cron_chunk_size', $this->chunk_filter, 10 );
			$this->chunk_filter = null;
		}
		parent::tearDown();
	}

	/**
	 * Withhold a specific dashboard right from the current user.
	 *
	 * @param string $withheld_right The right slug to deny.
	 */
	private function withhold_right( $withheld_right ) {
		$this->right_filter = static function ( $allowed, $cap_type, $cap ) use ( $withheld_right ) {
			if ( 'dashboard' === $cap_type && $cap === $withheld_right ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $this->right_filter, 10, 3 );
	}

	/**
	 * Record every child-site request and answer it successfully, so the test
	 * can prove the rights terminal made no remote call.
	 */
	private function spy_on_remote_calls() {
		$this->remote_calls = array();
		$calls              = &$this->remote_calls;
		$this->remote_spy   = static function ( $result, $website, $what, $params ) use ( &$calls ) {
			$calls[] = array(
				'site_id' => (int) $website->id,
				'what'    => (string) $what,
			);
			return array( 'sync' => array() );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $this->remote_spy, 10, 4 );
	}

	/**
	 * Process one site per cron run so rights can flip between chunks.
	 */
	private function set_chunk_size_one() {
		$this->chunk_filter = static function () {
			return 1;
		};
		add_filter( 'mainwp_abilities_cron_chunk_size', $this->chunk_filter, 10 );
	}

	/**
	 * Seed an update job owned by a real user with stamped rights.
	 *
	 * @param string   $job_id   Job ID.
	 * @param int      $owner_id Owner user ID.
	 * @param int[]    $site_ids Site IDs.
	 * @param string[] $rights   Stamped required rights (omit key when null).
	 * @param array    $overrides Extra job fields.
	 * @return array Seeded job data.
	 */
	private function seed_owned_update_job( string $job_id, int $owner_id, array $site_ids, ?array $rights, array $overrides = array() ): array {
		$job = array_merge(
			array(
				'job_type'       => 'update',
				'user_id'        => $owner_id,
				'sites'          => $site_ids,
				'types'          => array( 'plugins' ),
				'specific_items' => array(),
				'status'         => 'queued',
				'created'        => time(),
				'started'        => null,
				'completed'      => null,
				'updated'        => array(),
				'errors'         => array(),
				'progress'       => 0,
				'total'          => count( $site_ids ),
				'processed'      => 0,
			),
			$overrides
		);

		if ( null !== $rights ) {
			$job['required_rights'] = $rights;
		}

		set_transient( 'mainwp_update_job_' . $job_id, $job, DAY_IN_SECONDS );
		$this->track_update_job( $job_id );

		return $job;
	}

	/**
	 * A right revoked between chunks stops the job: every remaining site is
	 * marked access_revoked, the job fails, and no remote call is made.
	 */
	public function test_update_job_right_revoked_mid_job_stops_remaining_sites() {
		$owner_id = $this->set_current_user_as_admin();
		$this->set_chunk_size_one();
		$this->spy_on_remote_calls();

		$site_ids = array(
			$this->create_test_site( array( 'name' => 'Rights Site 1' ) ),
			$this->create_test_site( array( 'name' => 'Rights Site 2' ) ),
			$this->create_test_site( array( 'name' => 'Rights Site 3' ) ),
		);

		$job_id = 'update_rights_midjob';
		$this->seed_owned_update_job( $job_id, $owner_id, $site_ids, array( 'update_plugins' ) );

		// First chunk runs with the right held: site 1 is processed.
		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );
		$job = get_transient( 'mainwp_update_job_' . $job_id );
		$this->assertSame( 'processing', $job['status'], 'First chunk must process normally while the right is held.' );
		$this->assertSame( 1, $job['processed'] );

		// The owner's role loses update_plugins before the next chunk.
		$this->withhold_right( 'update_plugins' );
		$calls_before = count( $this->remote_calls );

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );
		$this->assertSame( 'failed', $job['status'], 'A revoked right must fail the job.' );
		$this->assertNotEmpty( $job['completed'] );

		$revoked_ids = array();
		foreach ( $job['errors'] as $error ) {
			if ( 'access_revoked' === $error['code'] ) {
				$revoked_ids[] = (int) $error['site_id'];
			}
		}
		$this->assertSame(
			array( $site_ids[1], $site_ids[2] ),
			$revoked_ids,
			'Both unprocessed sites must be marked access_revoked.'
		);
		$this->assertSame( count( $site_ids ), (int) $job['processed'], 'Remaining sites count as processed so progress math stays coherent.' );
		$this->assertSame( $calls_before, count( $this->remote_calls ), 'The rights terminal must not touch any child site.' );
	}

	/**
	 * Held rights leave the job untouched by the recheck.
	 */
	public function test_update_job_with_held_rights_processes_normally() {
		$owner_id = $this->set_current_user_as_admin();
		$this->spy_on_remote_calls();
		$this->withhold_right( 'delete_sites' ); // An unrelated right.

		$site_ids = array( $this->create_test_site( array( 'name' => 'Rights Held Site' ) ) );

		$job_id = 'update_rights_held';
		$this->seed_owned_update_job( $job_id, $owner_id, $site_ids, array( 'update_plugins' ) );

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );
		$this->assertSame( 'completed', $job['status'], 'A held right must not interfere with processing.' );
		foreach ( $job['errors'] as $error ) {
			$this->assertNotSame( 'access_revoked', $error['code'] );
		}
	}

	/**
	 * A legacy job queued before rights stamping (no required_rights key) keeps
	 * processing even when a filter would deny a right the job type implies.
	 */
	public function test_legacy_job_without_stamp_is_not_failed_on_deploy() {
		$owner_id = $this->set_current_user_as_admin();
		$this->spy_on_remote_calls();
		$this->withhold_right( 'update_plugins' );

		$site_ids = array( $this->create_test_site( array( 'name' => 'Legacy Job Site' ) ) );

		$job_id = 'update_rights_legacy';
		$this->seed_owned_update_job( $job_id, $owner_id, $site_ids, null );

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );
		$this->assertSame( 'completed', $job['status'], 'Legacy in-flight jobs must not fail on deploy.' );
		foreach ( $job['errors'] as $error ) {
			$this->assertNotSame( 'access_revoked', $error['code'] );
		}
	}

	/**
	 * The batch-operation path enforces its stamped edit_sites right too.
	 */
	public function test_batch_operation_job_denied_right_marks_all_sites_revoked() {
		$owner_id = $this->set_current_user_as_admin();
		$this->spy_on_remote_calls();
		$this->withhold_right( 'edit_sites' );

		$site_ids = array(
			$this->create_test_site( array( 'name' => 'Batch Rights Site 1' ) ),
			$this->create_test_site( array( 'name' => 'Batch Rights Site 2' ) ),
		);

		$job_id = 'disconnect_rights_denied';
		$job    = array(
			'job_type'        => 'disconnect',
			'user_id'         => $owner_id,
			'sites'           => $site_ids,
			'required_rights' => array( 'edit_sites' ),
			'status'          => 'queued',
			'created'         => time(),
			'started'         => null,
			'completed'       => null,
			'successful'      => array(),
			'errors'          => array(),
			'progress'        => 0,
			'total'           => count( $site_ids ),
			'processed'       => 0,
		);
		set_transient( 'mainwp_batch_job_' . $job_id, $job, DAY_IN_SECONDS );
		$this->track_batch_job( $job_id );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );
		$this->assertSame( 'failed', $job['status'] );

		$revoked_ids = array();
		foreach ( $job['errors'] as $error ) {
			if ( 'access_revoked' === $error['code'] ) {
				$revoked_ids[] = (int) $error['site_id'];
			}
		}
		$this->assertSame( $site_ids, $revoked_ids, 'Every site must be marked access_revoked before any work.' );
		$this->assertSame( array(), $this->remote_calls, 'No child site may be contacted.' );
	}
}
