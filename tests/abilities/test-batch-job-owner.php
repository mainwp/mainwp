<?php
/**
 * Batch status is addressed by job ID, so the ability must verify ownership
 * before returning another user's status. All three job types use the shared
 * owner resolver.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Batch_Job_Owner extends \WP_UnitTestCase {

	public function tearDown(): void {
		delete_transient( 'mainwp_sync_job_sync_abc' );
		delete_transient( 'mainwp_update_job_update_abc' );
		delete_transient( 'mainwp_batch_job_reconnect_abc' );
		parent::tearDown();
	}

	/**
	 * Owner is resolved from a sync job transient.
	 */
	public function test_owner_from_sync_job() {
		set_transient( 'mainwp_sync_job_sync_abc', array( 'job_type' => 'sync', 'user_id' => 42 ), 60 );
		$this->assertSame( 42, MainWP_Abilities_Util::get_batch_job_owner( 'sync_abc' ) );
	}

	/**
	 * Owner is resolved from an update job transient.
	 */
	public function test_owner_from_update_job() {
		set_transient( 'mainwp_update_job_update_abc', array( 'job_type' => 'update', 'user_id' => 7 ), 60 );
		$this->assertSame( 7, MainWP_Abilities_Util::get_batch_job_owner( 'update_abc' ) );
	}

	/**
	 * Owner is resolved from a batch-operation job transient.
	 */
	public function test_owner_from_operation_job() {
		set_transient( 'mainwp_batch_job_reconnect_abc', array( 'job_type' => 'reconnect', 'user_id' => 99 ), 60 );
		$this->assertSame( 99, MainWP_Abilities_Util::get_batch_job_owner( 'reconnect_abc' ) );
	}

	/**
	 * A missing job must not be attributed to any user.
	 */
	public function test_missing_job_returns_null() {
		$this->assertNull( MainWP_Abilities_Util::get_batch_job_owner( 'sync_does_not_exist' ) );
	}
}
