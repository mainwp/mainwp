<?php
/**
 * Tests for mainwp/get-batch-job-status-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Test class for mainwp/get-batch-job-status-v1 ability.
 */
class Test_Get_Batch_Job_Status_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Test that mainwp/get-batch-job-status-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_ability_is_registered(): void {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();
		$this->assertArrayHasKey(
			'mainwp/get-batch-job-status-v1',
			$abilities,
			'mainwp/get-batch-job-status-v1 ability should be registered'
		);
	}

	/**
	 * Test that ability returns sync job status correctly.
	 *
	 * @return void
	 */
	public function test_returns_sync_job_status(): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$job_id = 'sync_test123';
		$this->track_sync_job( $job_id );

		$job_data = [
			'job_type'  => 'sync',
			'status'    => 'queued',
			'progress'  => 0,
			'processed' => 0,
			'total'     => 5,
			'synced'    => [],
			'errors'    => [],
			'created'   => time(),
			'started'   => null,
			'completed' => null,
		];

		set_transient( 'mainwp_sync_job_' . $job_id, $job_data, DAY_IN_SECONDS );

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => $job_id ]
		);

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( $job_id, $result['job_id'], 'job_id should match input' );
		$this->assertEquals( 'sync', $result['type'], 'type should be sync' );
		$this->assertEquals( 'queued', $result['status'], 'status should be queued' );
		$this->assertEquals( 0, $result['progress'], 'progress should be 0' );
		$this->assertEquals( 0, $result['processed'], 'processed should be 0' );
		$this->assertEquals( 5, $result['total'], 'total should be 5' );
		$this->assertEquals( 0, $result['succeeded'], 'succeeded should be 0' );
		$this->assertEquals( 0, $result['failed'], 'failed should be 0' );
		$this->assertNull( $result['started_at'], 'started_at should be null' );
		$this->assertNull( $result['completed_at'], 'completed_at should be null' );
		$this->assertIsArray( $result['errors'], 'errors should be an array' );
	}

	/**
	 * Test that ability returns update job status correctly.
	 *
	 * @return void
	 */
	public function test_returns_update_job_status(): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$job_id = 'update_test456';
		$this->track_update_job( $job_id );

		$job_data = [
			'job_type'  => 'update',
			'status'    => 'processing',
			'progress'  => 40,
			'processed' => 2,
			'total'     => 5,
			'updated'   => [ 1, 2 ],
			'errors'    => [],
			'created'   => time() - 300,
			'started'   => time() - 60,
			'completed' => null,
		];

		set_transient( 'mainwp_update_job_' . $job_id, $job_data, DAY_IN_SECONDS );

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => $job_id ]
		);

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( $job_id, $result['job_id'], 'job_id should match input' );
		$this->assertEquals( 'update', $result['type'], 'type should be update' );
		$this->assertEquals( 'processing', $result['status'], 'status should be processing' );
		$this->assertEquals( 40, $result['progress'], 'progress should be 40' );
		$this->assertEquals( 2, $result['processed'], 'processed should be 2' );
		$this->assertEquals( 5, $result['total'], 'total should be 5' );
		$this->assertEquals( 2, $result['succeeded'], 'succeeded should be 2' );
		$this->assertEquals( 0, $result['failed'], 'failed should be 0' );
		$this->assertIsString( $result['started_at'], 'started_at should be a string (ISO 8601 timestamp)' );
		$this->assertNull( $result['completed_at'], 'completed_at should be null' );
	}

	/**
	 * Test that ability returns batch operation status correctly.
	 *
	 * @return void
	 */
	public function test_returns_batch_operation_status(): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$job_id = 'reconnect_test789';
		$this->track_batch_job( $job_id );

		$job_data = [
			'job_type'   => 'reconnect',
			'status'     => 'completed',
			'progress'   => 100,
			'processed'  => 3,
			'total'      => 3,
			'successful' => [ 10, 11, 12 ],
			'errors'     => [],
			'created'    => time() - 600,
			'started'    => time() - 300,
			'completed'  => time() - 60,
		];

		set_transient( 'mainwp_batch_job_' . $job_id, $job_data, DAY_IN_SECONDS );

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => $job_id ]
		);

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( $job_id, $result['job_id'], 'job_id should match input' );
		$this->assertEquals( 'reconnect', $result['type'], 'type should be reconnect' );
		$this->assertEquals( 'completed', $result['status'], 'status should be completed' );
		$this->assertEquals( 100, $result['progress'], 'progress should be 100' );
		$this->assertEquals( 3, $result['processed'], 'processed should be 3' );
		$this->assertEquals( 3, $result['total'], 'total should be 3' );
		$this->assertEquals( 3, $result['succeeded'], 'succeeded should be 3' );
		$this->assertEquals( 0, $result['failed'], 'failed should be 0' );
		$this->assertIsString( $result['started_at'], 'started_at should be a string (ISO 8601 timestamp)' );
		$this->assertIsString( $result['completed_at'], 'completed_at should be a string (ISO 8601 timestamp)' );
	}

	/**
	 * Test that ability returns failed job status correctly.
	 *
	 * @return void
	 */
	public function test_returns_failed_job_status(): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$job_id = 'sync_failed_test';
		$this->track_sync_job( $job_id );

		$job_data = [
			'job_type'  => 'sync',
			'status'    => 'failed',
			'progress'  => 60,
			'processed' => 3,
			'total'     => 5,
			'synced'    => [ 1, 2 ],
			'errors'    => [
				[
					'site_id' => 3,
					'code'    => 'connection_timeout',
					'message' => 'Connection timeout for site 3',
				],
			],
			'created'   => time() - 600,
			'started'   => time() - 300,
			'completed' => null,
		];

		set_transient( 'mainwp_sync_job_' . $job_id, $job_data, DAY_IN_SECONDS );

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => $job_id ]
		);

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( $job_id, $result['job_id'], 'job_id should match input' );
		$this->assertEquals( 'sync', $result['type'], 'type should be sync' );
		$this->assertEquals( 'failed', $result['status'], 'status should be failed' );
		$this->assertEquals( 60, $result['progress'], 'progress should be 60' );
		$this->assertEquals( 3, $result['processed'], 'processed should be 3' );
		$this->assertEquals( 5, $result['total'], 'total should be 5' );
		$this->assertEquals( 2, $result['succeeded'], 'succeeded should be 2' );
		$this->assertEquals( 1, $result['failed'], 'failed should be 1' );
		$this->assertIsString( $result['started_at'], 'started_at should be a string (ISO 8601 timestamp)' );
		$this->assertNull( $result['completed_at'], 'completed_at should be null for failed job' );
		$this->assertCount( 1, $result['errors'], 'errors array should contain 1 item' );
		$this->assertEquals( 3, $result['errors'][0]['site_id'], 'Error site_id should be 3' );
		$this->assertEquals( 'connection_timeout', $result['errors'][0]['code'], 'Error code should be connection_timeout' );
	}

	/**
	 * Test that ability returns error for non-existent job.
	 *
	 * @return void
	 */
	public function test_returns_error_for_nonexistent_job(): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => 'sync_nonexistent' ]
		);

		$this->assertWPError( $result, 'Result should be a WP_Error' );
		$this->assertEquals( 'mainwp_job_not_found', $result->get_error_code(), 'Error code should be mainwp_job_not_found' );
	}

	/**
	 * Test that ability returns error for invalid job ID format.
	 *
	 * @return void
	 */
	public function test_returns_error_for_invalid_job_id(): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => 'invalid_job_prefix_123' ]
		);

		$this->assertWPError( $result, 'Result should be a WP_Error' );
		// Schema pattern constraint rejects invalid prefixes before runtime validation.
		$this->assertEquals( 'ability_invalid_input', $result->get_error_code(), 'Error code should be ability_invalid_input' );
	}

	/**
	 * Test that ability requires authentication.
	 *
	 * @return void
	 */
	public function test_requires_authentication(): void {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => 'sync_test' ]
		);

		$this->assertWPError( $result, 'Result should be a WP_Error for unauthenticated request' );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code(), 'Error code should be ability_invalid_permissions' );
	}

	/**
	 * Test that ability requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_requires_manage_options(): void {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => 'sync_test' ]
		);

		$this->assertWPError( $result, 'Result should be a WP_Error for subscriber user' );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code(), 'Error code should be ability_invalid_permissions' );
	}

	/**
	 * Test that timestamps are transformed to ISO 8601 format correctly.
	 *
	 * @return void
	 */
	public function test_transforms_timestamps_correctly(): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$job_id = 'sync_timestamps_test';
		$this->track_sync_job( $job_id );

		$started_timestamp   = time() - 300;
		$completed_timestamp = time() - 60;

		$job_data = [
			'job_type'  => 'sync',
			'status'    => 'completed',
			'progress'  => 100,
			'processed' => 2,
			'total'     => 2,
			'synced'    => [ 1, 2 ],
			'errors'    => [],
			'created'   => time() - 600,
			'started'   => $started_timestamp,
			'completed' => $completed_timestamp,
		];

		set_transient( 'mainwp_sync_job_' . $job_id, $job_data, DAY_IN_SECONDS );

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => $job_id ]
		);

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertIsString( $result['started_at'], 'started_at should be a string' );
		$this->assertIsString( $result['completed_at'], 'completed_at should be a string' );

		$expected_started   = gmdate( 'Y-m-d\TH:i:s\Z', $started_timestamp );
		$expected_completed = gmdate( 'Y-m-d\TH:i:s\Z', $completed_timestamp );

		$this->assertEquals( $expected_started, $result['started_at'], 'started_at should be ISO 8601 formatted' );
		$this->assertEquals( $expected_completed, $result['completed_at'], 'completed_at should be ISO 8601 formatted' );
	}

	/**
	 * Test that succeeded and failed counts are calculated correctly.
	 *
	 * @return void
	 */
	public function test_calculates_succeeded_and_failed_counts(): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$job_id = 'sync_counts_test';
		$this->track_sync_job( $job_id );

		$job_data = [
			'job_type'  => 'sync',
			'status'    => 'completed',
			'progress'  => 100,
			'processed' => 5,
			'total'     => 5,
			'synced'    => [ 1, 2, 3 ],
			'errors'    => [
				[
					'site_id' => 4,
					'code'    => 'sync_failed',
					'message' => 'Sync failed for site 4',
				],
				[
					'site_id' => 5,
					'code'    => 'connection_error',
					'message' => 'Connection error for site 5',
				],
			],
			'created'   => time() - 600,
			'started'   => time() - 300,
			'completed' => time(),
		];

		set_transient( 'mainwp_sync_job_' . $job_id, $job_data, DAY_IN_SECONDS );

		$result = $this->execute_ability(
			'mainwp/get-batch-job-status-v1',
			[ 'job_id' => $job_id ]
		);

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 3, $result['succeeded'], 'succeeded count should be 3' );
		$this->assertEquals( 2, $result['failed'], 'failed count should be 2' );
		$this->assertCount( 2, $result['errors'], 'errors array should contain 2 items' );

		$this->assertEquals( 4, $result['errors'][0]['site_id'], 'First error site_id should be 4' );
		$this->assertEquals( 'sync_failed', $result['errors'][0]['code'], 'First error code should be sync_failed' );
		$this->assertEquals( 5, $result['errors'][1]['site_id'], 'Second error site_id should be 5' );
		$this->assertEquals( 'connection_error', $result['errors'][1]['code'], 'Second error code should be connection_error' );
	}
}
