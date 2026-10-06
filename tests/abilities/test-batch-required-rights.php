<?php
/**
 * Queued jobs need their required rights recorded so that a role change between
 * chunks can stop the remaining work. Legacy jobs without a rights stamp must
 * keep processing after deployment.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Batch_Required_Rights extends \WP_UnitTestCase {

	/**
	 * Transient names created by this test, for cleanup.
	 *
	 * @var string[]
	 */
	private $transients = array();

	public function tearDown(): void {
		foreach ( $this->transients as $transient ) {
			delete_transient( $transient );
		}
		$this->transients = array();
		parent::tearDown();
	}

	/**
	 * Build minimal site objects for the queue writers.
	 *
	 * @param int[] $ids Site IDs.
	 * @return object[]
	 */
	private function make_sites( array $ids ) {
		return array_map(
			static function ( $id ) {
				return (object) array( 'id' => $id );
			},
			$ids
		);
	}

	/**
	 * Sync has no mapped operation right: the stamp must be an empty list,
	 * present so the worker can distinguish stamped-empty from legacy-missing.
	 */
	public function test_sync_job_stamps_empty_rights() {
		$job_id = MainWP_Abilities_Util::queue_batch_sync( $this->make_sites( array( 11, 12 ) ) );
		$this->assertIsString( $job_id );
		$this->transients[] = 'mainwp_sync_job_' . $job_id;

		$job = get_transient( 'mainwp_sync_job_' . $job_id );
		$this->assertIsArray( $job );
		$this->assertArrayHasKey( 'required_rights', $job );
		$this->assertSame( array(), $job['required_rights'] );
	}

	/**
	 * Update jobs derive per-type rights from the requested types.
	 */
	public function test_update_job_stamps_rights_for_requested_types() {
		$job_id = MainWP_Abilities_Util::queue_batch_updates(
			$this->make_sites( array( 21 ) ),
			array( 'types' => array( 'plugins', 'themes' ) )
		);
		$this->assertIsString( $job_id );
		$this->transients[] = 'mainwp_update_job_' . $job_id;

		$job = get_transient( 'mainwp_update_job_' . $job_id );
		$this->assertIsArray( $job );
		$this->assertSame( array( 'update_plugins', 'update_themes' ), $job['required_rights'] );
	}

	/**
	 * An update job with no types means every type, so every update right.
	 */
	public function test_update_job_empty_types_stamps_all_update_rights() {
		$job_id = MainWP_Abilities_Util::queue_batch_updates( $this->make_sites( array( 22 ) ), array() );
		$this->assertIsString( $job_id );
		$this->transients[] = 'mainwp_update_job_' . $job_id;

		$job = get_transient( 'mainwp_update_job_' . $job_id );
		$this->assertIsArray( $job );
		$this->assertSame(
			array( 'update_wordpress', 'update_plugins', 'update_themes', 'update_translations' ),
			$job['required_rights']
		);
	}

	/**
	 * Site operations (reconnect / disconnect / check / suspend) map to
	 * edit_sites, matching their single-ability entries in the rights map.
	 *
	 * @dataProvider operation_provider
	 *
	 * @param string $operation Operation type.
	 */
	public function test_operation_job_stamps_edit_sites( $operation ) {
		$job_id = MainWP_Abilities_Util::queue_batch_operation( $operation, $this->make_sites( array( 31 ) ) );
		$this->assertIsString( $job_id );
		$this->transients[] = 'mainwp_batch_job_' . $job_id;

		$job = get_transient( 'mainwp_batch_job_' . $job_id );
		$this->assertIsArray( $job );
		$this->assertSame( array( 'edit_sites' ), $job['required_rights'] );
	}

	/**
	 * The four allowed batch operations.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function operation_provider() {
		return array(
			'reconnect'  => array( 'reconnect' ),
			'disconnect' => array( 'disconnect' ),
			'check'      => array( 'check' ),
			'suspend'    => array( 'suspend' ),
		);
	}

	/**
	 * The generic batch ability (get-batch-job-status) is a read-only status
	 * poller: it enqueues nothing and cannot wrap mapped mutating abilities,
	 * so it needs no operation right and no rights stamp of its own. Every
	 * batch enqueue happens inside a mapped ability's execute path, whose
	 * writer stamps required_rights (covered above). This pins that shape: if
	 * the ability ever gains an enqueue/wrap surface, this test must be
	 * replaced with rights-passthrough coverage.
	 */
	public function test_batch_status_ability_is_read_only_and_unmapped() {
		$this->assertArrayNotHasKey(
			'mainwp/get-batch-job-status-v1',
			MainWP_Abilities_Util::ABILITY_OPERATION_RIGHTS
		);
		$this->assertSame(
			array(),
			MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/get-batch-job-status-v1' )
		);
	}
}
