<?php
/**
 * Tests for MainWP_Assistant_SSE class.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_SSE;

/**
 * Test case for SSE streaming endpoint.
 */
class Test_Assistant_SSE extends MainWP_Assistant_Test_Case {

	/**
	 * The browser needs the saved answer ID to offer regeneration over SSE.
	 */
	public function test_format_complete_data_message_id() {
		$instance = MainWP_Assistant_SSE::instance();
		$method   = new \ReflectionMethod( $instance, 'format_complete_data' );
		$method->setAccessible( true );
		$payload = $method->invoke( $instance, array( 'result' => array( 'message_id' => 123 ) ) );
		$this->assertArrayHasKey( 'message_id', $payload );
		$this->assertSame( 123, $payload['message_id'] );
		$payload = $method->invoke( $instance, array( 'result' => array() ) );
		$this->assertArrayHasKey( 'message_id', $payload );
		$this->assertNull( $payload['message_id'] );
	}

	/**
	 * Test that SSE class is a singleton.
	 */
	public function test_singleton_instance() {
		$instance1 = MainWP_Assistant_SSE::instance();
		$instance2 = MainWP_Assistant_SSE::instance();

		$this->assertSame( $instance1, $instance2, 'SSE class should return same singleton instance.' );
	}

	/**
	 * Test that init() registers the AJAX handler.
	 */
	public function test_init_registers_ajax_handler() {
		$instance = MainWP_Assistant_SSE::instance();
		$instance->init();

		$this->assertNotFalse(
			has_action( 'wp_ajax_' . MainWP_Assistant_SSE::AJAX_ACTION, array( $instance, 'handle_sse_stream' ) ),
			'SSE AJAX handler should register the expected callback.'
		);
	}

	/**
	 * Test that jobs without a user_id are rejected.
	 */
	public function test_get_job_status_rejects_missing_user_id() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$job_id  = 'test-sse-missing-user-' . wp_generate_uuid4();

		set_transient(
			'mainwp_chat_job_' . $job_id,
			array(
				'status' => 'processing',
			),
			HOUR_IN_SECONDS
		);

		$instance   = MainWP_Assistant_SSE::instance();
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'get_job_status' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $instance, $job_id, $user_id ) );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * Test the failed-job terminal event does not use the reserved 'error' type.
	 *
	 * A server-sent event named 'error' dispatches on EventSource with the same
	 * type as the built-in connection-error event, so the client's onerror
	 * reconnect logic fires for every job failure — an infinite reconnect loop
	 * that re-appends the failure message every second. The terminal event must
	 * use a non-reserved name ('job_error').
	 */
	public function test_failed_job_stream_uses_non_reserved_event_name() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$job_id = 'test-sse-failed-' . wp_generate_uuid4();
		set_transient(
			'mainwp_chat_job_' . $job_id,
			array(
				'user_id' => $user_id,
				'status'  => 'failed',
				'error'   => 'No models found that support text_generation for this prompt.',
			),
			HOUR_IN_SECONDS
		);

		$instance   = MainWP_Assistant_SSE::instance();
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'run_sse_loop' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $instance, $job_id );
		$output = ob_get_clean();

		delete_transient( 'mainwp_chat_job_' . $job_id );

		$this->assertStringContainsString( 'event: job_error', $output, 'Terminal failure event must use the non-reserved job_error name.' );
		$this->assertStringNotContainsString( "event: error\n", $output, 'The reserved error event type collides with EventSource connection errors.' );
		$this->assertStringContainsString( 'No models found', $output, 'Failure message should be carried in the event data.' );
	}

	/**
	 * Test the expired-job terminal event also avoids the reserved 'error' type.
	 */
	public function test_expired_job_stream_uses_non_reserved_event_name() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$instance   = MainWP_Assistant_SSE::instance();
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'run_sse_loop' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $instance, 'test-sse-nonexistent-' . wp_generate_uuid4() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'event: job_error', $output );
		$this->assertStringNotContainsString( "event: error\n", $output );
	}

	/**
	 * Test the failed-job event carries the stored error code for layered
	 * client-side error mapping, still under the non-reserved event name.
	 */
	public function test_failed_job_stream_carries_error_code() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$job_id = 'test-sse-error-code-' . wp_generate_uuid4();
		set_transient(
			'mainwp_chat_job_' . $job_id,
			array(
				'user_id'    => $user_id,
				'status'     => 'failed',
				'error'      => 'Injected bridge failure.',
				'error_code' => 'bridge_unavailable',
			),
			HOUR_IN_SECONDS
		);

		$instance   = MainWP_Assistant_SSE::instance();
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'run_sse_loop' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $instance, $job_id );
		$output = ob_get_clean();

		delete_transient( 'mainwp_chat_job_' . $job_id );

		$this->assertStringContainsString( 'event: job_error', $output );
		$this->assertStringNotContainsString( "event: error\n", $output );
		$this->assertStringContainsString( '"code":"bridge_unavailable"', $output, 'Failure event must carry the machine-readable code.' );
	}

	/**
	 * Test the completed-job event carries executed abilities to the client.
	 */
	public function test_completed_job_stream_carries_executed_abilities() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$job_id = 'test-sse-chips-' . wp_generate_uuid4();
		set_transient(
			'mainwp_chat_job_' . $job_id,
			array(
				'user_id' => $user_id,
				'status'  => 'completed',
				'result'  => array(
					'response'           => 'Synced.',
					'sources'            => array(),
					'executed_abilities' => array(
						array(
							'ability'      => 'mainwp/sync-sites-v1',
							'label'        => 'Sync Sites',
							'success'      => true,
							'target_count' => 3,
							'duration'     => 2.5,
						),
					),
				),
			),
			HOUR_IN_SECONDS
		);

		$instance   = MainWP_Assistant_SSE::instance();
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'run_sse_loop' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $instance, $job_id );
		$output = ob_get_clean();

		delete_transient( 'mainwp_chat_job_' . $job_id );

		$this->assertStringContainsString( 'event: complete', $output );
		$this->assertStringContainsString( '"executed_abilities"', $output, 'Complete event must carry the executed-abilities array.' );
		$this->assertStringContainsString( 'mainwp\/sync-sites-v1', $output, 'Chip payload should survive JSON encoding into the event data.' );
	}

	/**
	 * Test that jobs owned by another user are rejected.
	 */
	public function test_get_job_status_rejects_wrong_user() {
		$owner_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$user_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$job_id   = 'test-sse-wrong-user-' . wp_generate_uuid4();

		set_transient(
			'mainwp_chat_job_' . $job_id,
			array(
				'user_id' => $owner_id,
				'status'  => 'processing',
			),
			HOUR_IN_SECONDS
		);

		$instance   = MainWP_Assistant_SSE::instance();
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'get_job_status' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $instance, $job_id, $user_id ) );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * Test a mid-stream consent revoke terminates the loop with the terminal
	 * job_error event instead of relaying the job to completion.
	 */
	public function test_sse_loop_terminates_when_consent_revoked_mid_stream() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( false );

		$job_id = 'test-sse-consent-revoke-' . wp_generate_uuid4();
		set_transient(
			'mainwp_chat_job_' . $job_id,
			array(
				'user_id' => $user_id,
				'status'  => 'processing',
			),
			HOUR_IN_SECONDS
		);

		$instance   = MainWP_Assistant_SSE::instance();
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'run_sse_loop' );
		$method->setAccessible( true );

		$start = time();
		ob_start();
		$method->invoke( $instance, $job_id );
		$output = ob_get_clean();

		delete_transient( 'mainwp_chat_job_' . $job_id );

		$this->assertLessThan( 30, time() - $start, 'Revoked consent must terminate the loop immediately, not run toward MAX_RUNTIME.' );
		$this->assertStringContainsString( 'event: job_error', $output );
		$this->assertStringNotContainsString( "event: error\n", $output );
		$this->assertStringContainsString( '"code":"no_consent"', $output, 'Terminal event must carry the machine-readable consent code.' );
	}

	/**
	 * Test a mid-stream assistant disable terminates the loop with the
	 * terminal job_error event.
	 */
	public function test_sse_loop_terminates_when_disabled_mid_stream() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->set_assistant_enabled( false );
		$this->set_assistant_consent( true );

		$job_id = 'test-sse-disabled-' . wp_generate_uuid4();
		set_transient(
			'mainwp_chat_job_' . $job_id,
			array(
				'user_id' => $user_id,
				'status'  => 'processing',
			),
			HOUR_IN_SECONDS
		);

		$instance   = MainWP_Assistant_SSE::instance();
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'run_sse_loop' );
		$method->setAccessible( true );

		$start = time();
		ob_start();
		$method->invoke( $instance, $job_id );
		$output = ob_get_clean();

		delete_transient( 'mainwp_chat_job_' . $job_id );

		$this->assertLessThan( 30, time() - $start, 'A disabled assistant must terminate the loop immediately, not run toward MAX_RUNTIME.' );
		$this->assertStringContainsString( 'event: job_error', $output );
		$this->assertStringNotContainsString( "event: error\n", $output );
		$this->assertStringContainsString( '"code":"assistant_disabled"', $output, 'Terminal event must carry the machine-readable disabled code.' );
	}
}
