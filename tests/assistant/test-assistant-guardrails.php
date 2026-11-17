<?php
/**
 * Tests for the assistant hard guardrails: immutable Stop and the daily spend cap.
 *
 * Covers the plan's verification list: the cancel flag halts the chokepoint
 * before any model call, the flag written by a separate request survives the
 * per-request option cache (the M1/M2 regression), the tool loop stops
 * between iterations, jobs terminate with a cancelled status, the budget gate
 * blocks at and fails closed around the spend read, and accrual charges
 * unpriced models a conservative fallback rate.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use MainWP\Dashboard\MainWP_Assistant_Policies;
use MainWP\Dashboard\MainWP_Assistant_Settings;
use MainWP\Dashboard\MainWP_DB_Assistant;

/**
 * Testable subclass stubbing the generation seam with a call counter.
 */
class Guardrail_Testable_Assistant extends MainWP_Assistant {

	/**
	 * Queue of canned results (GenerativeAiResult or WP_Error), one per call.
	 *
	 * @var array
	 */
	public $canned_results = array();

	/**
	 * Number of times the generation seam was invoked.
	 *
	 * @var int
	 */
	public $generate_calls = 0;

	/**
	 * Override: count calls and pop a canned result instead of HTTP.
	 *
	 * @param object $builder Configured prompt builder.
	 *
	 * @return object|\WP_Error
	 */
	protected function generate_ai_result( $builder ) {
		++$this->generate_calls;

		if ( empty( $this->canned_results ) ) {
			return new \WP_Error( 'no_canned_response', 'Test forgot to queue a canned result.' );
		}

		return array_shift( $this->canned_results );
	}
}

/**
 * Seam that lands a Stop on the active job during the model call.
 *
 * Simulates the user clicking Stop while a provider round-trip is in flight:
 * the flag is written after generation "ran" but before the tool loop's next
 * step, so tests can prove the loop halts between iterations.
 */
class Guardrail_Cancel_During_Generation_Assistant extends Guardrail_Testable_Assistant {

	/**
	 * Job ID whose transient receives the cancel flag mid-generation.
	 *
	 * @var string
	 */
	public $cancel_job_id = '';

	/**
	 * 1-based generation call during which the Stop lands.
	 *
	 * @var int
	 */
	public $cancel_on_call = 1;

	/**
	 * Override: write the cancel flag, then defer to the canned queue.
	 *
	 * @param object $builder Configured prompt builder.
	 *
	 * @return object|\WP_Error
	 */
	protected function generate_ai_result( $builder ) {
		if ( '' !== $this->cancel_job_id && $this->generate_calls + 1 === $this->cancel_on_call ) {
			set_transient( 'mainwp_chat_job_cancel_' . $this->cancel_job_id, time(), HOUR_IN_SECONDS );
		}

		return parent::generate_ai_result( $builder );
	}
}

/**
 * Async seam whose AI execution reports a user Stop.
 */
class Guardrail_Stopped_Async_Assistant extends MainWP_Assistant {

	/**
	 * Override: simulate a chat execution halted by the Stop guardrail.
	 *
	 * @param string $message         User message.
	 * @param int    $conversation_id Conversation ID.
	 * @param array  $context         Request context.
	 * @param int    $regenerate      Bound assistant answer ID (0 for a plain send).
	 * @param int    $user_id         User ID.
	 * @param bool   $show_disclosure Whether to show disclosure banner.
	 *
	 * @return \WP_Error
	 */
	protected function execute_ai_chat( $message, $conversation_id, $context, $regenerate, $user_id, $show_disclosure = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Matches parent signature.
		return new \WP_Error( 'assistant_stopped', 'Response generation stopped.' );
	}
}

/**
 * Object-cache double simulating a persistent external backend (Redis/Memcached).
 *
 * Keeps a second, "persistent" layer beside the parent's in-memory runtime
 * layer: set() writes both, get() falls back to the persistent copy on a
 * runtime miss (repopulating the runtime like a real drop-in), delete()
 * removes from both. flush() clears only the runtime layer — core's default
 * wp_cache_flush_runtime() routes to wp_cache_flush(), so for this double a
 * flush IS a runtime flush and the persistent layer survives, which is
 * exactly the semantic a real drop-in gives wp_cache_flush_runtime().
 */
class Guardrail_Two_Layer_Object_Cache extends \WP_Object_Cache {

	/**
	 * Simulated persistent backend, keyed [ group ][ key ] => value.
	 *
	 * @var array
	 */
	public $persistent = array();

	/**
	 * Write-through set: runtime layer plus the persistent backend.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Data to store.
	 * @param string     $group  Cache group.
	 * @param int        $expire Expiration (ignored by the double).
	 *
	 * @return bool
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$this->persistent[ $group ][ $key ] = $data;

		return parent::set( $key, $data, $group, $expire );
	}

	/**
	 * Runtime read with persistent-layer fallback on a miss.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param bool       $force Unused by the double.
	 * @param bool       $found Whether the key was found.
	 *
	 * @return mixed
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$value = parent::get( $key, $group, $force, $found );

		if ( $found ) {
			return $value;
		}

		if ( isset( $this->persistent[ $group ][ $key ] ) ) {
			$found = true;
			$value = $this->persistent[ $group ][ $key ];
			parent::set( $key, $value, $group );

			return $value;
		}

		return false;
	}

	/**
	 * Delete from both layers, the way a real drop-in's delete hits the backend.
	 *
	 * @param int|string $key        Cache key.
	 * @param string     $group      Cache group.
	 * @param bool       $deprecated Unused.
	 *
	 * @return bool
	 */
	public function delete( $key, $group = 'default', $deprecated = false ) {
		$existed = isset( $this->persistent[ $group ][ $key ] );
		unset( $this->persistent[ $group ][ $key ] );
		parent::delete( $key, $group, $deprecated );

		return $existed;
	}

	/**
	 * Clear only the runtime layer; the persistent backend survives.
	 *
	 * @return bool
	 */
	public function flush() {
		return parent::flush();
	}
}

/**
 * Class Test_Assistant_Guardrails
 */
class Test_Assistant_Guardrails extends MainWP_Assistant_Test_Case {

	/**
	 * Captured AJAX output for the endpoint test.
	 *
	 * @var string
	 */
	protected $ajax_response = '';

	/**
	 * Clean up guardrail state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		delete_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD );
		delete_option( MainWP_Assistant_Policies::OPTION_USER_DAILY_BUDGET_USD );
		delete_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN );

		// Remove today's spend row so accrual tests stay independent.
		$wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_assistant_spend" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test cleanup.
		$wpdb->suppress_errors( false );

		remove_all_filters( 'mainwp_assistant_fallback_token_rate' );

		parent::tearDown();
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * Point an assistant instance at a job ID via the private property.
	 *
	 * @param MainWP_Assistant $assistant Assistant instance.
	 * @param string           $job_id    Job ID.
	 *
	 * @return void
	 */
	private function set_active_job( $assistant, $job_id ) {
		$prop = new \ReflectionProperty( MainWP_Assistant::class, 'active_job_id' );
		$prop->setAccessible( true );
		$prop->setValue( $assistant, $job_id );
	}

	/**
	 * Invoke the private core_chat_turn() on an assistant instance.
	 *
	 * @param MainWP_Assistant $assistant  Assistant instance.
	 * @param string           $message    User message.
	 * @param int              $user_id    User ID.
	 * @param bool             $with_tools Whether to advertise tools.
	 *
	 * @return array|\WP_Error
	 */
	private function invoke_core_chat_turn( $assistant, $message, $user_id, $with_tools = false ) {
		$method = new \ReflectionMethod( $assistant, 'core_chat_turn' );
		$method->setAccessible( true );

		$resolved = null;
		$args     = array( $message, array(), array(), $user_id, &$resolved, $with_tools, null );

		return $method->invokeArgs( $assistant, $args );
	}

	/**
	 * Seed a chat job transient.
	 *
	 * @param string $job_id  Job ID.
	 * @param int    $user_id Owning user.
	 * @param array  $extra   Extra job fields to merge.
	 *
	 * @return void
	 */
	private function seed_job_transient( $job_id, $user_id, $extra = array() ) {
		set_transient(
			'mainwp_chat_job_' . $job_id,
			array_merge(
				array(
					'status'  => 'processing',
					'user_id' => $user_id,
				),
				$extra
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Build a get_user_metadata filter callback that lands a Stop on a job
	 * the moment a given user-meta key is read.
	 *
	 * execute_tool_call() reads the confirmation-level meta while deciding
	 * whether an ability needs confirmation, so hooking that read places the
	 * cancel flag deterministically inside tool execution — after the tool
	 * loop's iteration-top check, before the handled-path persistence.
	 *
	 * @param string $job_id   Job ID whose transient receives the cancel flag.
	 * @param string $meta_key Meta key whose read triggers the write.
	 *
	 * @return callable Filter callback for get_user_metadata.
	 */
	private function make_meta_read_cancel_writer( $job_id, $meta_key ) {
		return function ( $value, $object_id, $key ) use ( $job_id, $meta_key ) {
			if ( $key === $meta_key ) {
				set_transient( 'mainwp_chat_job_cancel_' . $job_id, time(), HOUR_IN_SECONDS );
			}

			return $value;
		};
	}

	/**
	 * Invoke the private process_tool_call_loop() with a single canned tool call.
	 *
	 * @param MainWP_Assistant $assistant       Assistant instance.
	 * @param string           $function_name   Wire-format tool function name.
	 * @param array            $arguments       Tool call arguments.
	 * @param int              $conversation_id Conversation ID for persistence.
	 * @param int              $user_id         User ID.
	 * @param string           $message         User message for the turn.
	 *
	 * @return array|\WP_Error Loop result.
	 */
	private function invoke_tool_loop_with_call( $assistant, $function_name, $arguments, $conversation_id, $user_id, $message ) {
		$total_usage = array();
		$resolved    = array(
			'provider_id'   => 'openai',
			'provider_name' => 'OpenAI',
			'model_id'      => 'gpt-test',
		);

		$method = new \ReflectionMethod( MainWP_Assistant::class, 'process_tool_call_loop' );
		$method->setAccessible( true );

		return $method->invokeArgs(
			$assistant,
			array(
				$message,
				array(),
				array(
					'has_tool_calls' => true,
					'tool_calls'     => array(
						array(
							'id'       => 'call_1',
							'type'     => 'function',
							'function' => array(
								'name'      => $function_name,
								'arguments' => wp_json_encode( $arguments ),
							),
						),
					),
					'response'       => '',
				),
				&$total_usage,
				$user_id,
				&$resolved,
				array(
					'conversation_history' => array(),
					'conversation_id'      => $conversation_id,
					'regenerate'           => false,
					'show_disclosure'      => false,
					'history_disabled'     => false,
				),
			)
		);
	}

	/**
	 * Invoke the private process_tool_call_loop() with several canned tool
	 * calls in the same round-trip, to test the loop's per-call Stop check.
	 *
	 * @param MainWP_Assistant $assistant       Assistant instance.
	 * @param array            $calls           Array of [ function_name, arguments ] pairs, in call order.
	 * @param int              $conversation_id Conversation ID for persistence.
	 * @param int              $user_id         User ID.
	 * @param string           $message         User message for the turn.
	 *
	 * @return array|\WP_Error Loop result.
	 */
	private function invoke_tool_loop_with_calls( $assistant, array $calls, $conversation_id, $user_id, $message ) {
		$total_usage = array();
		$resolved    = array(
			'provider_id'   => 'openai',
			'provider_name' => 'OpenAI',
			'model_id'      => 'gpt-test',
		);

		$tool_calls = array();
		foreach ( $calls as $index => $call ) {
			list( $function_name, $arguments ) = $call;
			$tool_calls[]                      = array(
				'id'       => 'call_' . ( $index + 1 ),
				'type'     => 'function',
				'function' => array(
					'name'      => $function_name,
					'arguments' => wp_json_encode( $arguments ),
				),
			);
		}

		$method = new \ReflectionMethod( MainWP_Assistant::class, 'process_tool_call_loop' );
		$method->setAccessible( true );

		return $method->invokeArgs(
			$assistant,
			array(
				$message,
				array(),
				array(
					'has_tool_calls' => true,
					'tool_calls'     => $tool_calls,
					'response'       => '',
				),
				&$total_usage,
				$user_id,
				&$resolved,
				array(
					'conversation_history' => array(),
					'conversation_id'      => $conversation_id,
					'regenerate'           => false,
					'show_disclosure'      => false,
					'history_disabled'     => false,
				),
			)
		);
	}

	// =========================================================================
	// IMMUTABLE STOP
	// =========================================================================

	/**
	 * A cancelled job returns assistant_stopped before any model call.
	 *
	 * @return void
	 */
	public function test_stop_blocks_next_model_call_at_chokepoint() {
		$user_id   = $this->set_current_user_as_admin();
		$assistant = new Guardrail_Testable_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );
		set_transient( 'mainwp_chat_job_cancel_' . $job_id, time(), HOUR_IN_SECONDS );
		$this->set_active_job( $assistant, $job_id );

		$result = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_id );

		$this->assertWPErrorWithCode( $result, 'assistant_stopped' );
		$this->assertSame( 0, $assistant->generate_calls, 'No model round-trip may start after a Stop.' );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * M2 regression: a cancel written by a separate request must be visible
	 * even though this request already primed the option cache.
	 *
	 * Without the cache bust in is_active_job_cancelled() this test fails:
	 * the primed per-request copy never shows the flag.
	 *
	 * @return void
	 */
	public function test_stop_flag_visible_across_request_cache_boundary() {
		global $wpdb;

		$user_id   = $this->set_current_user_as_admin();
		$assistant = new Guardrail_Testable_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );

		// Prime the per-request caches, mirroring the worker's own reads —
		// including the negative (notoptions) result for the Stop key no
		// request has written yet.
		get_transient( 'mainwp_chat_job_' . $job_id );
		$this->assertFalse( get_transient( 'mainwp_chat_job_cancel_' . $job_id ) );

		// Simulate the separate Stop request: a direct options-table write
		// that bypasses this request's object cache entirely.
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Deliberate cache bypass to simulate a cross-request write.
			$wpdb->options,
			array(
				'option_name'  => '_transient_mainwp_chat_job_cancel_' . $job_id,
				'option_value' => (string) time(),
				'autoload'     => 'no',
			)
		);
		$this->assertSame( 1, $inserted, 'The cross-request Stop write must land in the options table.' );

		$this->set_active_job( $assistant, $job_id );

		$method = new \ReflectionMethod( $assistant, 'is_active_job_cancelled' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $assistant ), 'The Stop key written by a separate request must be seen through the primed cache.' );

		delete_transient( 'mainwp_chat_job_' . $job_id );
		delete_transient( 'mainwp_chat_job_cancel_' . $job_id );
	}

	/**
	 * M2 regression: under a persistent external object cache the cancel
	 * check must not destroy the job entry it reads.
	 *
	 * Under Redis/Memcached the 'transient' cache entry IS the job store, so
	 * the ext-cache branch's wp_cache_delete() deletes the job. The check may
	 * only bust the per-request runtime layer; a cancel written to the
	 * persistent layer by a separate request must still become visible.
	 *
	 * @return void
	 */
	public function test_cancel_check_under_ext_object_cache_preserves_job() {
		$user_id   = $this->set_current_user_as_admin();
		$assistant = new Guardrail_Testable_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();
		$key       = 'mainwp_chat_job_' . $job_id;

		$real_cache                 = $GLOBALS['wp_object_cache'];
		$was_ext                    = wp_using_ext_object_cache( true );
		$GLOBALS['wp_object_cache'] = new Guardrail_Two_Layer_Object_Cache();

		try {
			set_transient(
				$key,
				array(
					'status'  => 'processing',
					'user_id' => $user_id,
				),
				HOUR_IN_SECONDS
			);

			// Prime the runtime layer, mirroring the worker's own reads.
			get_transient( $key );

			$this->set_active_job( $assistant, $job_id );

			$method = new \ReflectionMethod( $assistant, 'is_active_job_cancelled' );
			$method->setAccessible( true );

			$this->assertFalse( $method->invoke( $assistant ), 'No cancel has been requested yet.' );

			$job_data = get_transient( $key );
			$this->assertIsArray( $job_data, 'The cancel check must not delete the job entry from a persistent object cache.' );
			$this->assertSame( 'processing', $job_data['status'] ?? '' );

			// Cross-request Stop: lands only in the persistent layer while
			// this request's runtime layer holds a stale primed miss.
			get_transient( $key );
			$GLOBALS['wp_object_cache']->persistent['transient'][ 'mainwp_chat_job_cancel_' . $job_id ] = time();

			$this->assertTrue( $method->invoke( $assistant ), 'A Stop present only in the persistent layer must be seen through the primed runtime cache.' );
		} finally {
			// The test bootstrap leaves the flag NULL, and passing NULL back
			// into wp_using_ext_object_cache() is a read, not a restore.
			wp_using_ext_object_cache( (bool) $was_ext );
			$GLOBALS['wp_object_cache'] = $real_cache;
		}
	}

	/**
	 * M4 regression: a worker blob write must not erase a landed Stop.
	 *
	 * The worker holds its own in-memory copy of the job blob and rewrites
	 * the whole thing on every status transition; while the Stop flag lived
	 * inside that blob, any worker write between the Stop and the next
	 * cancel check silently un-cancelled the job (lost update).
	 *
	 * @return void
	 */
	public function test_worker_blob_write_does_not_clobber_stop_flag() {
		$user_id   = $this->set_current_user_as_admin();
		$assistant = new Guardrail_Testable_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();
		$key       = 'mainwp_chat_job_' . $job_id;

		$this->seed_job_transient( $job_id, $user_id );
		$this->set_active_job( $assistant, $job_id );

		// The worker takes its in-memory copy, as process_chat_job() does.
		$worker_copy = get_transient( $key );

		// The user's Stop lands through the real AJAX handler.
		$response = $this->dispatch_cancel_job_ajax( $job_id );
		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['cancel_requested'] );

		// The worker rewrites the whole blob from its stale copy.
		$worker_copy['status'] = 'processing';
		set_transient( $key, $worker_copy, HOUR_IN_SECONDS );

		$method = new \ReflectionMethod( $assistant, 'is_active_job_cancelled' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $assistant ), 'A worker blob write must not erase a landed Stop.' );

		delete_transient( $key );
		delete_transient( 'mainwp_chat_job_cancel_' . $job_id );
	}

	/**
	 * M4 regression, other direction: Stop must not rewrite the job blob.
	 *
	 * The Stop handler's old read-modify-write of the whole blob could
	 * resurrect stale fields over the worker's newer ones; the signal must
	 * travel through its own key and leave the blob untouched.
	 *
	 * @return void
	 */
	public function test_stop_does_not_rewrite_job_blob() {
		$user_id = $this->set_current_user_as_admin();
		$job_id  = 'grd_' . wp_generate_uuid4();
		$key     = 'mainwp_chat_job_' . $job_id;

		$this->seed_job_transient( $job_id, $user_id, array( 'result' => array( 'response' => 'worker fields' ) ) );

		$before   = get_transient( $key );
		$response = $this->dispatch_cancel_job_ajax( $job_id );
		$after    = get_transient( $key );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['cancel_requested'] );
		$this->assertSame( $before, $after, 'Stop must signal through its own key and never rewrite the job blob.' );

		delete_transient( $key );
		delete_transient( 'mainwp_chat_job_cancel_' . $job_id );
	}

	/**
	 * The worker's terminal write refuses to overwrite an existing terminal
	 * status: a stale-lock takeover that wakes up late must not flip a
	 * settled job.
	 *
	 * @return void
	 */
	public function test_worker_terminal_write_refuses_to_overwrite_terminal_status() {
		$user_id   = $this->set_current_user_as_admin();
		$assistant = new Guardrail_Testable_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();
		$key       = 'mainwp_chat_job_' . $job_id;

		$this->seed_job_transient( $job_id, $user_id, array( 'status' => 'cancelled' ) );

		$stale = array(
			'status'  => 'completed',
			'user_id' => $user_id,
			'result'  => array( 'response' => 'late takeover result' ),
		);

		$method = new \ReflectionMethod( $assistant, 'store_job_terminal_state' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $assistant, $job_id, $stale ), 'A terminal status must not be overwritten.' );

		$stored = get_transient( $key );
		$this->assertSame( 'cancelled', $stored['status'] ?? '', 'The settled status must survive the late writer.' );

		// The healthy path still persists: a non-terminal job accepts its
		// terminal write.
		$this->seed_job_transient( $job_id, $user_id, array( 'status' => 'processing' ) );
		$this->assertTrue( $method->invoke( $assistant, $job_id, $stale ) );
		$stored = get_transient( $key );
		$this->assertSame( 'completed', $stored['status'] ?? '' );

		delete_transient( $key );
	}

	/**
	 * A Stop landing during a provider round-trip halts the tool loop before
	 * any tool executes or another round-trip starts.
	 *
	 * @return void
	 */
	public function test_stop_halts_tool_loop_between_iterations() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available.' );
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$assistant = new Guardrail_Cancel_During_Generation_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );
		$this->set_active_job( $assistant, $job_id );
		$assistant->cancel_job_id = $job_id;

		// First (and only permitted) round-trip asks for a tool.
		$assistant->canned_results = array(
			$this->make_ai_result(
				array(
					new \WordPress\AiClient\Messages\DTO\MessagePart(
						new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_1', 'wpab__mainwp__get-sites-basic-v1', array() )
					),
				)
			),
		);

		$method = new \ReflectionMethod( $assistant, 'execute_ai_chat' );
		$method->setAccessible( true );
		$result = $method->invoke( $assistant, 'List my sites', 0, array(), false, $user_id );

		$this->assertWPErrorWithCode( $result, 'assistant_stopped' );
		$this->assertSame( 1, $assistant->generate_calls, 'Only the in-flight round-trip may complete after Stop; the loop must not continue.' );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * A Stop landing during a plain content-only response blocks delivery and
	 * persists nothing — stored history must agree with the "stopped" the UI
	 * already showed.
	 *
	 * @return void
	 */
	public function test_stop_during_plain_response_persists_nothing() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available.' );
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id );

		$assistant = new Guardrail_Cancel_During_Generation_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );
		$this->set_active_job( $assistant, $job_id );
		$assistant->cancel_job_id  = $job_id;
		$assistant->cancel_on_call = 1;
		$assistant->canned_results = array( $this->make_text_result( 'A finished answer.' ) );

		$method = new \ReflectionMethod( $assistant, 'execute_ai_chat' );
		$method->setAccessible( true );
		$result = $method->invoke( $assistant, 'Hello', $conversation_id, array(), false, $user_id );

		$this->assertWPErrorWithCode( $result, 'assistant_stopped' );
		$this->assertSame( 1, $assistant->generate_calls );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id );
		$this->assertSame( array(), $messages, 'A stopped turn must not write the user or assistant message to history.' );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * A Stop landing during the tool loop's concluding content-only round-trip
	 * blocks delivery instead of persisting the finished answer.
	 *
	 * @return void
	 */
	public function test_stop_during_final_loop_response_persists_nothing() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available.' );
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id );

		$assistant = new Guardrail_Cancel_During_Generation_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );
		$this->set_active_job( $assistant, $job_id );
		$assistant->cancel_job_id  = $job_id;
		$assistant->cancel_on_call = 2;
		$assistant->canned_results = array(
			$this->make_ai_result(
				array(
					new \WordPress\AiClient\Messages\DTO\MessagePart(
						new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_1', 'wpab__mainwp__get-sites-basic-v1', array() )
					),
				)
			),
			$this->make_text_result( 'A finished answer.' ),
		);

		$method = new \ReflectionMethod( $assistant, 'execute_ai_chat' );
		$method->setAccessible( true );
		$result = $method->invoke( $assistant, 'List my sites', $conversation_id, array(), false, $user_id );

		$this->assertWPErrorWithCode( $result, 'assistant_stopped' );
		$this->assertSame( 2, $assistant->generate_calls, 'The concluding round-trip was in flight and may complete; nothing after it may run.' );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id );
		$this->assertSame( array(), $messages, 'A stopped turn must not persist the finished answer.' );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * A Stop landing during the round-trip that produced a confirmation ask
	 * returns assistant_stopped and persists no confirmation-request row.
	 *
	 * Without this, the reload re-arm would bring back a card for a turn the
	 * user was already told stopped.
	 *
	 * @return void
	 */
	public function test_stop_during_confirmation_ask_round_trip_persists_no_card() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available.' );
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		MainWP_Assistant_Policies::instance()->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_ALL, $user_id );

		$conversation_id = $this->create_test_conversation( $user_id );

		$assistant = new Guardrail_Cancel_During_Generation_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );
		$this->set_active_job( $assistant, $job_id );
		$assistant->cancel_job_id  = $job_id;
		$assistant->cancel_on_call = 1;
		$assistant->canned_results = array(
			$this->make_ai_result(
				array(
					new \WordPress\AiClient\Messages\DTO\MessagePart(
						new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_confirm', 'wpab__mainwp__sync-sites-v1', array() )
					),
				)
			),
		);

		$method = new \ReflectionMethod( $assistant, 'execute_ai_chat' );
		$method->setAccessible( true );
		$result = $method->invoke( $assistant, 'Sync all my sites', $conversation_id, array(), false, $user_id );

		$this->assertWPErrorWithCode( $result, 'assistant_stopped' );
		$this->assertSame( 1, $assistant->generate_calls, 'The ask-producing round-trip was in flight and may complete; nothing after it may run.' );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id );
		$this->assertSame( array(), $messages, 'A stopped turn must not persist a confirmation-request row.' );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * A Stop landing while the confirmation-producing tool call is executing
	 * — after the iteration-top check, before the handled-path persistence —
	 * must not arm the confirmation card.
	 *
	 * This is the window the loop-top and pre-call checks cannot see: the
	 * user clicks Stop while the ability's policy and access checks run, the
	 * UI already shows "stopped", and without the persistence-point gate the
	 * confirmation row is written and re-armed on reload.
	 *
	 * @return void
	 */
	public function test_stop_during_confirmation_tool_execution_persists_no_card() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available.' );
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		MainWP_Assistant_Policies::instance()->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_ALL, $user_id );

		$conversation_id = $this->create_test_conversation( $user_id );

		$assistant = new Guardrail_Testable_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );
		$this->set_active_job( $assistant, $job_id );

		$cancel_writer = $this->make_meta_read_cancel_writer( $job_id, MainWP_Assistant_Policies::USER_META_CONFIRMATION_LEVEL );
		add_filter( 'get_user_metadata', $cancel_writer, 10, 3 );

		try {
			$result = $this->invoke_tool_loop_with_call( $assistant, 'wpab__mainwp__sync-sites-v1', array(), $conversation_id, $user_id, 'Sync all my sites' );
		} finally {
			remove_filter( 'get_user_metadata', $cancel_writer, 10 );
		}

		$this->assertNotEmpty( get_transient( 'mainwp_chat_job_cancel_' . $job_id ), 'Seam failure: the Stop flag must land during tool execution.' );

		$this->assertWPErrorWithCode( $result, 'assistant_stopped' );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id );
		$this->assertSame( array(), $messages, 'A Stop during tool execution must not persist the confirmation-request row.' );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * S4 regression: a Stop landing during the FIRST tool call of a multi-call
	 * turn must prevent the SECOND call from executing.
	 *
	 * process_tool_call_loop()'s foreach re-checks is_active_job_cancelled() at
	 * the top of every tool call, not just once per round-trip — the pre-loop
	 * check only guards the turn's first call. This uses a readonly,
	 * site-scoped ability for both calls so neither call arms the
	 * requires_confirmation branch (which has its own, separately-tested Stop
	 * check above): the cancel flag lands via the protected-tags meta read
	 * inside call 1's own policy check, before call 1's ability actually
	 * executes, and a wp_before_execute_ability spy proves call 2's ability
	 * execution never runs.
	 *
	 * @return void
	 */
	public function test_stop_between_tool_calls_in_same_turn_blocks_second_call() {
		if ( ! function_exists( 'wp_get_ability' ) || ! is_object( wp_get_ability( 'mainwp/get-site-updates-v1' ) ) ) {
			$this->markTestSkipped( 'MainWP abilities are not registered in this environment.' );
		}

		global $wpdb;

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );

		$site_id = $this->create_test_site();
		// The site resolver joins mainwp_wp_sync, which the assistant base's
		// site fixture does not seed.
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			array(
				'wpid'        => $site_id,
				'version'     => '5.0.0',
				'sync_errors' => '',
			),
			array( '%d', '%s', '%s' )
		);

		$conversation_id = $this->create_test_conversation( $user_id, 'Two Call Stop Conversation' );

		$assistant = new Guardrail_Testable_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );
		$this->set_active_job( $assistant, $job_id );

		$cancel_writer = $this->make_meta_read_cancel_writer( $job_id, MainWP_Assistant_Policies::USER_META_PROTECTED_TAGS );
		add_filter( 'get_user_metadata', $cancel_writer, 10, 3 );

		// wp_before_execute_ability fires once per real ability execution
		// (WP Abilities API core hook), so this proves whether call 2's
		// ability ever ran — process_tool_call_loop() returns a WP_Error on
		// Stop, so there is no other way to observe execution counts.
		$execute_count = 0;
		$spy           = function ( $ability_name ) use ( &$execute_count ) {
			if ( 'mainwp/get-site-updates-v1' === $ability_name ) {
				++$execute_count;
			}
		};
		add_action( 'wp_before_execute_ability', $spy, 10, 1 );

		try {
			$result = $this->invoke_tool_loop_with_calls(
				$assistant,
				array(
					array( 'wpab__mainwp__get-site-updates-v1', array( 'site_id_or_domain' => $site_id ) ),
					array( 'wpab__mainwp__get-site-updates-v1', array( 'site_id_or_domain' => $site_id ) ),
				),
				$conversation_id,
				$user_id,
				'Get site updates twice.'
			);
		} finally {
			remove_filter( 'get_user_metadata', $cancel_writer, 10 );
			remove_action( 'wp_before_execute_ability', $spy, 10 );
		}

		$this->assertNotEmpty( get_transient( 'mainwp_chat_job_cancel_' . $job_id ), 'Seam failure: the Stop flag must land during call 1.' );

		$this->assertWPErrorWithCode( $result, 'assistant_stopped' );
		$this->assertSame( 1, $execute_count, 'Only the first tool call may execute; the second must be blocked before its ability runs.' );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * A Stop landing during batch dispatch still persists the batch row.
	 *
	 * By the time the batch branch persists, the ability has already queued
	 * the batch job — handle_batch_response() runs after execute(). Blocking
	 * the row would tell the user the turn stopped while the batch keeps
	 * running with no monitoring surface; persisting is the honest outcome.
	 *
	 * @return void
	 */
	public function test_stop_during_batch_dispatch_still_persists_batch_row() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available.' );
		}
		if ( ! function_exists( 'wp_get_ability' ) || ! is_object( wp_get_ability( 'mainwp/check-sites-v1' ) ) ) {
			$this->markTestSkipped( 'MainWP abilities are not registered in this environment.' );
		}

		global $wpdb;

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		MainWP_Assistant_Policies::instance()->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE, $user_id );

		$site_id = $this->create_test_site();
		// The site resolver joins mainwp_wp_sync, which the assistant base's
		// site fixture does not seed.
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			array(
				'wpid'        => $site_id,
				'version'     => '5.0.0',
				'sync_errors' => '',
			),
			array( '%d', '%s', '%s' )
		);

		$conversation_id = $this->create_test_conversation( $user_id, 'Batch Stop Conversation' );

		$assistant = new Guardrail_Testable_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		$this->seed_job_transient( $job_id, $user_id );
		$this->set_active_job( $assistant, $job_id );

		// Force the queue path regardless of site count.
		$force_queue = function () {
			return 0;
		};
		add_filter( 'mainwp_abilities_batch_threshold', $force_queue );

		$cancel_writer = $this->make_meta_read_cancel_writer( $job_id, MainWP_Assistant_Policies::USER_META_CONFIRMATION_LEVEL );
		add_filter( 'get_user_metadata', $cancel_writer, 10, 3 );

		try {
			$result = $this->invoke_tool_loop_with_call(
				$assistant,
				'wpab__mainwp__check-sites-v1',
				array( 'site_ids_or_domains' => array( $site_id ) ),
				$conversation_id,
				$user_id,
				'Check my sites.'
			);
		} finally {
			remove_filter( 'get_user_metadata', $cancel_writer, 10 );
			remove_filter( 'mainwp_abilities_batch_threshold', $force_queue );
		}

		$this->assertNotEmpty( get_transient( 'mainwp_chat_job_cancel_' . $job_id ), 'Seam failure: the Stop flag must land before the batch branch persists.' );

		$this->assertIsArray( $result, 'A dispatched batch must return its handled response, not assistant_stopped: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
		$this->assertTrue( ! empty( $result['handled'] ) );
		$this->assertTrue( ! empty( $result['response']['batch_job'] ) );
		$this->assertNotEmpty( $result['response']['job_id'] );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 500, 0, 'ASC' );
		$this->assertCount( 2, $messages, 'The user message and the batch monitoring row must both persist.' );
		$closing = end( $messages );
		$this->assertSame( 'assistant', $closing->role );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * A job halted by Stop terminates with a cancelled status, not failed.
	 *
	 * @return void
	 */
	public function test_process_chat_job_marks_job_cancelled() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$assistant = new Guardrail_Stopped_Async_Assistant();
		$job_id    = 'grd_' . wp_generate_uuid4();

		set_transient(
			'mainwp_chat_job_' . $job_id,
			array(
				'status'          => 'queued',
				'user_id'         => $user_id,
				'message'         => 'Hello',
				'conversation_id' => 0,
				'context'         => array(),
				'regenerate'      => false,
				'show_disclosure' => false,
				'created'         => time(),
				'result'          => null,
				'error'           => null,
			),
			HOUR_IN_SECONDS
		);

		$assistant->process_chat_job( $job_id );

		$job_data = get_transient( 'mainwp_chat_job_' . $job_id );
		$this->assertIsArray( $job_data );
		$this->assertSame( 'cancelled', $job_data['status'] );
		$this->assertSame( 'assistant_stopped', $job_data['error_code'] );

		delete_transient( 'mainwp_chat_job_' . $job_id );
	}

	/**
	 * The Stop endpoint sets the cancel flag on the caller's own job and
	 * refuses to touch another user's job.
	 *
	 * @return void
	 */
	public function test_cancel_job_endpoint_sets_flag_for_owner_only() {
		$user_id  = $this->set_current_user_as_admin();
		$other_id = $this->create_other_admin();

		$own_job     = 'grd_' . wp_generate_uuid4();
		$foreign_job = 'grd_' . wp_generate_uuid4();
		$this->seed_job_transient( $own_job, $user_id );
		$this->seed_job_transient( $foreign_job, $other_id );

		$own = $this->dispatch_cancel_job_ajax( $own_job );
		$this->assertTrue( $own['success'] );
		$this->assertTrue( $own['data']['cancel_requested'] );
		$this->assertNotEmpty( get_transient( 'mainwp_chat_job_cancel_' . $own_job ) );

		$foreign = $this->dispatch_cancel_job_ajax( $foreign_job );
		$this->assertTrue( $foreign['success'] );
		$this->assertFalse( $foreign['data']['cancel_requested'] );
		$this->assertFalse( get_transient( 'mainwp_chat_job_cancel_' . $foreign_job ) );

		delete_transient( 'mainwp_chat_job_' . $own_job );
		delete_transient( 'mainwp_chat_job_' . $foreign_job );
		delete_transient( 'mainwp_chat_job_cancel_' . $own_job );
	}

	// =========================================================================
	// DAILY SPEND CAP
	// =========================================================================

	/**
	 * At or over the limit, the gate blocks before any model call.
	 *
	 * @return void
	 */
	public function test_budget_gate_blocks_before_model_call() {
		$user_id = $this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '1.00' );
		MainWP_DB_Assistant::instance()->add_spend(
			1.50,
			array(
				'prompt_tokens'     => 1000,
				'completion_tokens' => 500,
			),
			true
		);

		$assistant = new Guardrail_Testable_Assistant();
		$result    = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_id );

		$this->assertWPErrorWithCode( $result, 'assistant_budget_exceeded' );
		$this->assertSame( 0, $assistant->generate_calls, 'No model call may start once the daily cap is reached.' );
	}

	/**
	 * A spend-read failure with a cap in force blocks instead of failing open.
	 *
	 * @return void
	 */
	public function test_budget_gate_fail_closed_on_read_error() {
		global $wpdb;

		$user_id = $this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '1.00' );

		$table = $wpdb->prefix . 'mainwp_assistant_spend';
		$wpdb->query( "RENAME TABLE {$table} TO {$table}_bak" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test simulates an unreadable spend table.

		try {
			$this->assertNull( MainWP_DB_Assistant::instance()->get_spend_today(), 'A failed spend read must report null, not zero.' );

			$assistant = new Guardrail_Testable_Assistant();
			$result    = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_id );

			$this->assertWPErrorWithCode( $result, 'assistant_spend_unavailable' );
			$this->assertSame( 0, $assistant->generate_calls );
		} finally {
			$wpdb->query( "RENAME TABLE {$table}_bak TO {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restore.
		}
	}

	/**
	 * A failed accrual insert must trip the persistent broken flag and the
	 * site-wide gate must fail closed on the next call.
	 *
	 * Accrual runs after the provider call, so the failing turn itself cannot
	 * be blocked; without the flag the site-wide total silently sticks at $0
	 * and the cap never engages while every later read "succeeds".
	 *
	 * @return void
	 */
	public function test_failed_spend_accrual_sets_broken_flag_and_gate_fails_closed() {
		global $wpdb;

		$user_id = $this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '1.00' );

		// Break only the accrual INSERT; reads stay healthy so the gate
		// cannot fail closed for the read-error reason.
		$spend_table        = '`' . $wpdb->prefix . 'mainwp_assistant_spend`';
		$break_spend_insert = function ( $query ) use ( $spend_table ) {
			// Match the backticked table only: the flag option's own name
			// contains this substring and its write must stay healthy.
			if ( false !== stripos( $query, $spend_table ) && 0 === stripos( ltrim( $query ), 'INSERT' ) ) {
				return 'INSERT INTO nonexistent_mainwp_spend_probe (x) VALUES (1)';
			}
			return $query;
		};

		add_filter( 'query', $break_spend_insert );
		$suppress = $wpdb->suppress_errors( true );

		try {
			$accrued = MainWP_DB_Assistant::instance()->add_spend(
				0.10,
				array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 50,
				),
				true,
				$user_id
			);
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break_spend_insert );
		}

		$this->assertFalse( $accrued, 'The broken accrual insert must report failure.' );
		$this->assertNotEmpty(
			get_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN ),
			'A failed accrual must set the persistent broken flag.'
		);

		$assistant = new Guardrail_Testable_Assistant();
		$result    = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_id );

		$this->assertWPErrorWithCode( $result, 'assistant_spend_unavailable' );
		$this->assertSame( 0, $assistant->generate_calls, 'Broken spend accounting with a cap in force must block before any model call.' );
	}

	/**
	 * A later successful accrual clears the broken flag.
	 *
	 * Reachable when no cap is configured (the gate only blocks while a cap
	 * is in force): once inserts work again, accounting is provably healthy
	 * and the flag must not keep a later-configured cap wedged.
	 *
	 * @return void
	 */
	public function test_successful_spend_accrual_clears_broken_flag() {
		$user_id = $this->set_current_user_as_admin();

		update_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN, time() );

		$accrued = MainWP_DB_Assistant::instance()->add_spend(
			0.05,
			array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
			),
			true,
			$user_id
		);

		$this->assertNotFalse( $accrued );
		$this->assertFalse(
			get_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN ),
			'A successful accrual must clear the broken flag.'
		);
	}

	/**
	 * An explicit 0 disables the cap: no gate, the call proceeds.
	 *
	 * @return void
	 */
	public function test_budget_zero_disables_gate() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available.' );
		}

		$user_id = $this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '0' );

		$assistant                 = new Guardrail_Testable_Assistant();
		$assistant->canned_results = array( $this->make_text_result( 'All good.' ) );

		$result = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_id );

		$this->assertNotWPError( $result );
		$this->assertSame( 'All good.', $result['response'] );
		$this->assertSame( 1, $assistant->generate_calls );
	}

	/**
	 * Budget option semantics: absent = conservative default, empty/zero =
	 * unlimited, junk = default, numeric = itself.
	 *
	 * @return void
	 */
	public function test_daily_budget_option_semantics() {
		$policies = MainWP_Assistant_Policies::instance();

		delete_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD );
		$this->assertSame( MainWP_Assistant_Policies::DEFAULT_DAILY_BUDGET_USD, $policies->get_daily_budget_usd() );

		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '' );
		$this->assertSame( 0.0, $policies->get_daily_budget_usd() );

		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '0' );
		$this->assertSame( 0.0, $policies->get_daily_budget_usd() );

		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '5.50' );
		$this->assertSame( 5.5, $policies->get_daily_budget_usd() );

		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, 'garbage' );
		$this->assertSame( MainWP_Assistant_Policies::DEFAULT_DAILY_BUDGET_USD, $policies->get_daily_budget_usd() );
	}

	/**
	 * Accrual accumulates across turns and reads back exact totals.
	 *
	 * @return void
	 */
	public function test_add_spend_accumulates_and_reads_back() {
		$db = MainWP_DB_Assistant::instance();

		$this->assertTrue(
			$db->add_spend(
				0.5,
				array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 40,
					'thought_tokens'    => 10,
				),
				true
			)
		);
		$this->assertTrue(
			$db->add_spend(
				0.25,
				array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 50,
				),
				false
			)
		);

		$spend = $db->get_spend_today();

		$this->assertIsArray( $spend );
		$this->assertEqualsWithDelta( 0.75, $spend['cost_usd'], 0.000001 );
		$this->assertSame( 200, $spend['prompt_tokens'] );
		$this->assertSame( 100, $spend['completion_tokens'] );
		$this->assertSame( 1, $spend['unpriced_turns'] );
		$this->assertSame( 2, $spend['turns'] );
	}

	/**
	 * An unpriced model accrues the conservative fallback rate, never $0.
	 *
	 * @return void
	 */
	public function test_unpriced_model_accrues_fallback_rate() {
		$this->set_current_user_as_admin();

		$assistant = new Guardrail_Testable_Assistant();
		$method    = new \ReflectionMethod( $assistant, 'accrue_turn_spend' );
		$method->setAccessible( true );

		$method->invoke(
			$assistant,
			array(
				'usage'       => array(
					'prompt_tokens'     => 600000,
					'completion_tokens' => 400000,
					'total_tokens'      => 1000000,
				),
				'model_id'    => 'totally-unknown-model-x',
				'provider_id' => 'unknown',
			)
		);

		$spend = MainWP_DB_Assistant::instance()->get_spend_today();

		$this->assertIsArray( $spend );
		$this->assertEqualsWithDelta( 20.0, $spend['cost_usd'], 0.000001, '1M unpriced tokens must accrue the $20/1M fallback rate.' );
		$this->assertSame( 1, $spend['unpriced_turns'] );
		$this->assertSame( 1, $spend['turns'] );
	}

	// =========================================================================
	// BUDGET STATE IN THE PROVIDER CHECK
	// =========================================================================

	/**
	 * The provider check reports the budget state the panel needs up front:
	 * under the limit and at limit 0 nothing is blocked, at or over the limit
	 * blocked flips true.
	 *
	 * @return void
	 */
	public function test_check_provider_reports_budget_states() {
		$this->set_current_user_as_admin();

		// Under the limit.
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '2.00' );
		MainWP_DB_Assistant::instance()->add_spend( 0.50, array( 'prompt_tokens' => 100 ), true );

		$response = $this->dispatch_check_provider_ajax();
		$this->assertTrue( $response['success'] );
		$budget = $response['data']['budget'];
		$this->assertEqualsWithDelta( 2.0, $budget['limit'], 0.000001 );
		$this->assertEqualsWithDelta( 0.5, $budget['spent_today'], 0.000001 );
		$this->assertFalse( $budget['blocked'], 'Under the limit nothing is blocked.' );
		$this->assertFalse( $budget['unavailable'], 'A readable spend total is not reported as unavailable.' );

		// At the limit.
		MainWP_DB_Assistant::instance()->add_spend( 1.50, array( 'prompt_tokens' => 100 ), true );

		$response = $this->dispatch_check_provider_ajax();
		$budget   = $response['data']['budget'];
		$this->assertTrue( $budget['blocked'], 'Spend at the limit must report blocked, mirroring the server gate.' );
		$this->assertFalse( $budget['unavailable'], 'A reached limit must not read as an unreadable spend total.' );
		$this->assertEqualsWithDelta( 2.0, $budget['spent_today'], 0.000001 );

		// Limit 0 disables the cap: never blocked, whatever was spent.
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '0' );

		$response = $this->dispatch_check_provider_ajax();
		$budget   = $response['data']['budget'];
		$this->assertEqualsWithDelta( 0.0, $budget['limit'], 0.000001 );
		$this->assertFalse( $budget['blocked'], 'Limit 0 means no cap, so nothing is blocked.' );
	}

	/**
	 * A spend-read failure with a cap in force reports blocked, mirroring the
	 * fail-closed gate, and reports the unknown spend as null.
	 *
	 * @return void
	 */
	public function test_check_provider_budget_blocked_on_read_failure() {
		global $wpdb;

		$this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '1.00' );

		$table = $wpdb->prefix . 'mainwp_assistant_spend';
		$wpdb->query( "RENAME TABLE {$table} TO {$table}_bak" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test simulates an unreadable spend table.

		try {
			$response = $this->dispatch_check_provider_ajax();
			$budget   = $response['data']['budget'];

			$this->assertNull( $budget['spent_today'], 'An unreadable spend total must be reported as null, not zero.' );
			$this->assertTrue( $budget['blocked'], 'While a limit is set, an unreadable spend total blocks — the panel must mirror the fail-closed gate.' );
			$this->assertTrue( $budget['unavailable'], 'The panel must say the total could not be read, not that the limit was reached.' );
		} finally {
			$wpdb->query( "RENAME TABLE {$table}_bak TO {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restore.
		}
	}

	/**
	 * Hitting only the per-user cap sets user_reached so the panel blames the
	 * user's own limit. Once the dashboard-wide cap is also hit, the flag
	 * clears and the dashboard message takes over.
	 *
	 * @return void
	 */
	public function test_check_provider_reports_user_limit_reached() {
		$user_id = $this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '0' );
		update_option( MainWP_Assistant_Policies::OPTION_USER_DAILY_BUDGET_USD, '1.00' );

		$budget = $this->dispatch_check_provider_ajax()['data']['budget'];
		$this->assertFalse( $budget['user_reached'], 'Under the per-user limit the flag stays off.' );

		MainWP_DB_Assistant::instance()->add_spend( 1.50, array( 'prompt_tokens' => 100 ), true, $user_id );

		$budget = $this->dispatch_check_provider_ajax()['data']['budget'];
		$this->assertTrue( $budget['blocked'] );
		$this->assertTrue( $budget['user_reached'], 'Only the per-user cap is hit, so the panel must say it is the user\'s own limit.' );
		$this->assertFalse( $budget['unavailable'] );

		$total = MainWP_DB_Assistant::instance()->get_spend_today();
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, (string) max( 0.01, $total['cost_usd'] ) );

		$budget = $this->dispatch_check_provider_ajax()['data']['budget'];
		$this->assertTrue( $budget['blocked'] );
		$this->assertFalse( $budget['user_reached'], 'With the dashboard-wide cap also hit, the dashboard message applies.' );
	}

	// =========================================================================
	// PER-USER SPEND CAP
	// =========================================================================

	/**
	 * Per-user budget option semantics: absent, empty, and junk all mean off
	 * (unlike the global cap there is no legacy default to preserve), numeric
	 * means itself.
	 *
	 * @return void
	 */
	public function test_user_budget_option_semantics() {
		$policies = MainWP_Assistant_Policies::instance();

		delete_option( MainWP_Assistant_Policies::OPTION_USER_DAILY_BUDGET_USD );
		$this->assertSame( 0.0, $policies->get_user_daily_budget_usd() );

		update_option( MainWP_Assistant_Policies::OPTION_USER_DAILY_BUDGET_USD, '' );
		$this->assertSame( 0.0, $policies->get_user_daily_budget_usd() );

		update_option( MainWP_Assistant_Policies::OPTION_USER_DAILY_BUDGET_USD, 'garbage' );
		$this->assertSame( 0.0, $policies->get_user_daily_budget_usd() );

		update_option( MainWP_Assistant_Policies::OPTION_USER_DAILY_BUDGET_USD, '3.25' );
		$this->assertSame( 3.25, $policies->get_user_daily_budget_usd() );
	}

	/**
	 * Accrual stamps the acting user and per-user reads stay separate while
	 * the site-wide read aggregates every bucket.
	 *
	 * @return void
	 */
	public function test_add_spend_stamps_user_and_aggregates_across_users() {
		$db     = MainWP_DB_Assistant::instance();
		$user_a = $this->set_current_user_as_admin();
		$user_b = $this->create_other_admin();

		// Delta against the pre-test aggregate: rows committed past a DDL
		// auto-commit in unrelated tests survive transaction rollback, so an
		// empty table cannot be assumed.
		$before = $db->get_spend_today();
		$this->assertIsArray( $before );

		$this->assertTrue( $db->add_spend( 0.75, array( 'prompt_tokens' => 100 ), true, $user_a ) );
		$this->assertTrue( $db->add_spend( 0.25, array( 'prompt_tokens' => 50 ), true, $user_b ) );

		$spend_a = $db->get_user_spend_today( $user_a );
		$this->assertIsArray( $spend_a );
		$this->assertEqualsWithDelta( 0.75, $spend_a['cost_usd'], 0.000001 );
		$this->assertSame( 1, $spend_a['turns'] );

		$spend_b = $db->get_user_spend_today( $user_b );
		$this->assertEqualsWithDelta( 0.25, $spend_b['cost_usd'], 0.000001 );

		$total = $db->get_spend_today();
		$this->assertEqualsWithDelta( $before['cost_usd'] + 1.0, $total['cost_usd'], 0.000001, 'The site-wide read must aggregate all user buckets.' );
		$this->assertSame( $before['turns'] + 2, $total['turns'] );
	}

	/**
	 * The per-user cap blocks the user who spent it while another user with
	 * headroom proceeds — one user can no longer exhaust the assistant for
	 * everyone.
	 *
	 * @return void
	 */
	public function test_user_budget_gate_blocks_capped_user_only() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available.' );
		}

		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '0' );
		update_option( MainWP_Assistant_Policies::OPTION_USER_DAILY_BUDGET_USD, '1.00' );

		$user_a = $this->set_current_user_as_admin();
		MainWP_DB_Assistant::instance()->add_spend( 1.50, array( 'prompt_tokens' => 100 ), true, $user_a );

		$assistant = new Guardrail_Testable_Assistant();
		$result    = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_a );

		$this->assertWPErrorWithCode( $result, 'assistant_user_budget_exceeded' );
		$this->assertSame( 0, $assistant->generate_calls, 'No model call may start once the user cap is reached.' );

		$user_b = $this->create_other_admin();
		wp_set_current_user( $user_b );

		$assistant                 = new Guardrail_Testable_Assistant();
		$assistant->canned_results = array( $this->make_text_result( 'Still open for you.' ) );

		$result = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_b );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $assistant->generate_calls, 'A user with per-user headroom proceeds while another user is capped.' );
	}

	/**
	 * The global cap keeps blocking everyone based on the aggregate across
	 * user buckets, whoever spent it.
	 *
	 * @return void
	 */
	public function test_global_gate_blocks_on_aggregate_across_users() {
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '1.00' );

		$user_a = $this->set_current_user_as_admin();
		$user_b = $this->create_other_admin();
		MainWP_DB_Assistant::instance()->add_spend( 0.60, array( 'prompt_tokens' => 100 ), true, $user_a );
		MainWP_DB_Assistant::instance()->add_spend( 0.60, array( 'prompt_tokens' => 100 ), true, $user_b );

		wp_set_current_user( $user_b );
		$assistant = new Guardrail_Testable_Assistant();
		$result    = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_b );

		$this->assertWPErrorWithCode( $result, 'assistant_budget_exceeded' );
		$this->assertSame( 0, $assistant->generate_calls, 'The shared cap gates on the aggregate across all users.' );
	}

	/**
	 * A spend-read failure with a per-user cap in force blocks instead of
	 * failing open, mirroring the global cap's contract.
	 *
	 * @return void
	 */
	public function test_user_budget_gate_fail_closed_on_read_error() {
		global $wpdb;

		$user_id = $this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_DAILY_BUDGET_USD, '0' );
		update_option( MainWP_Assistant_Policies::OPTION_USER_DAILY_BUDGET_USD, '1.00' );

		$table = $wpdb->prefix . 'mainwp_assistant_spend';
		$wpdb->query( "RENAME TABLE {$table} TO {$table}_bak" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test simulates an unreadable spend table.

		try {
			$this->assertNull( MainWP_DB_Assistant::instance()->get_user_spend_today( $user_id ), 'A failed per-user spend read must report null, not zero.' );

			$assistant = new Guardrail_Testable_Assistant();
			$result    = $this->invoke_core_chat_turn( $assistant, 'Hello', $user_id );

			$this->assertWPErrorWithCode( $result, 'assistant_spend_unavailable' );
			$this->assertSame( 0, $assistant->generate_calls );
		} finally {
			$wpdb->query( "RENAME TABLE {$table}_bak TO {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restore.
		}
	}

	// =========================================================================
	// SPEND FIELDS IN THE LIMIT SAVE
	// =========================================================================

	/**
	 * Saving the limit returns the spend fields the settings JS needs to
	 * rewrite the "used today" line without a reload.
	 *
	 * @return void
	 */
	public function test_save_daily_budget_returns_spend_fields() {
		$this->set_current_user_as_admin();
		MainWP_DB_Assistant::instance()->add_spend( 0.50, array( 'prompt_tokens' => 100 ), true );

		$response = $this->dispatch_save_daily_budget_ajax( '2.00' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( '2.00', $response['data']['budget'] );
		$this->assertSame( number_format_i18n( 0.5, 2 ), $response['data']['spent_today'] );
		$this->assertSame( number_format_i18n( 2.0, 2 ), $response['data']['limit_display'] );

		// Limit 0: the no-limit display state still carries the spend.
		$response = $this->dispatch_save_daily_budget_ajax( '0' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( '0.00', $response['data']['budget'] );
		$this->assertSame( number_format_i18n( 0.5, 2 ), $response['data']['spent_today'] );
	}

	/**
	 * A spend-read failure reports spent_today as null so the JS renders the
	 * unreadable state, mirroring the PHP template.
	 *
	 * @return void
	 */
	public function test_save_daily_budget_reports_null_spend_on_read_failure() {
		global $wpdb;

		$this->set_current_user_as_admin();

		$table = $wpdb->prefix . 'mainwp_assistant_spend';
		$wpdb->query( "RENAME TABLE {$table} TO {$table}_bak" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test simulates an unreadable spend table.

		try {
			$response = $this->dispatch_save_daily_budget_ajax( '1.00' );

			$this->assertTrue( $response['success'] );
			$this->assertArrayHasKey( 'spent_today', $response['data'] );
			$this->assertNull( $response['data']['spent_today'], 'An unreadable spend total must be reported as null so the JS renders the unreadable state.' );
		} finally {
			$wpdb->query( "RENAME TABLE {$table}_bak TO {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restore.
		}
	}

	// =========================================================================
	// AJAX HARNESS (mirrors test-assistant-settings-ajax.php)
	// =========================================================================

	/**
	 * Dispatch a nonce-signed request to ajax_save_daily_budget() and parse the JSON reply.
	 *
	 * @param string $budget Limit to save, as posted by the settings JS.
	 *
	 * @return array Parsed response with success/data keys.
	 */
	private function dispatch_save_daily_budget_ajax( $budget ) {
		$this->ajax_response = '';
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$action   = 'mainwp_assistant_save_daily_budget';
		$_POST    = array(
			'action'   => $action,
			'security' => wp_create_nonce( 'mainwp_assistant_settings' ),
			'budget'   => $budget,
		);
		$_REQUEST = $_POST;

		$ob_level = ob_get_level();
		ob_start();

		try {
			MainWP_Assistant_Settings::ajax_save_daily_budget();
		} catch ( \WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json calls wp_die, which the handler intercepts.
		} catch ( \WPAjaxDieStopException $e ) {
			// Also expected for error paths.
		}

		if ( ob_get_level() > $ob_level ) {
			$remaining = ob_get_clean();
			if ( $remaining ) {
				$this->ajax_response .= $remaining;
			}
		}

		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$decoded = json_decode( $this->ajax_response, true );

		return is_array( $decoded ) ? $decoded : array(
			'success' => false,
			'data'    => array( 'raw' => $this->ajax_response ),
		);
	}

	/**
	 * Dispatch a nonce-signed request to handle_check_provider() and parse the JSON reply.
	 *
	 * @return array Parsed response with success/data keys.
	 */
	private function dispatch_check_provider_ajax() {
		$this->ajax_response = '';
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$action         = MainWP_Assistant::AJAX_CHECK_PROVIDER;
		$_POST          = array(
			'action'   => $action,
			'security' => wp_create_nonce( $action ),
		);
		$_GET['action'] = $action;
		$_REQUEST       = array_merge( $_POST, $_GET );

		$ob_level = ob_get_level();
		ob_start();

		try {
			MainWP_Assistant::instance()->handle_check_provider();
		} catch ( \WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json calls wp_die, which the handler intercepts.
		} catch ( \WPAjaxDieStopException $e ) {
			// Also expected for error paths.
		}

		if ( ob_get_level() > $ob_level ) {
			$remaining = ob_get_clean();
			if ( $remaining ) {
				$this->ajax_response .= $remaining;
			}
		}

		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$decoded = json_decode( $this->ajax_response, true );

		return is_array( $decoded ) ? $decoded : array(
			'success' => false,
			'data'    => array( 'raw' => $this->ajax_response ),
		);
	}

	/**
	 * Dispatch a nonce-signed request to handle_cancel_job() and parse the JSON reply.
	 *
	 * @param string $job_id Job ID to cancel.
	 *
	 * @return array Parsed response with success/data keys.
	 */
	private function dispatch_cancel_job_ajax( $job_id ) {
		$this->ajax_response = '';
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$action           = MainWP_Assistant::AJAX_CANCEL_JOB;
		$_POST            = array(
			'action'   => $action,
			'security' => wp_create_nonce( $action ),
			'job_id'   => $job_id,
		);
		$_GET['action']   = $action;
		$_REQUEST         = array_merge( $_POST, $_GET );

		$ob_level = ob_get_level();
		ob_start();

		try {
			MainWP_Assistant::instance()->handle_cancel_job();
		} catch ( \WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json calls wp_die, which the handler intercepts.
		} catch ( \WPAjaxDieStopException $e ) {
			// Also expected for error paths.
		}

		if ( ob_get_level() > $ob_level ) {
			$remaining = ob_get_clean();
			if ( $remaining ) {
				$this->ajax_response .= $remaining;
			}
		}

		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$decoded = json_decode( $this->ajax_response, true );

		return is_array( $decoded ) ? $decoded : array(
			'success' => false,
			'data'    => array( 'raw' => $this->ajax_response ),
		);
	}

	/**
	 * Provide the wp_die handler used during AJAX dispatch.
	 *
	 * @return callable
	 */
	public function get_ajax_die_handler(): callable {
		return array( $this, 'ajax_die_handler' );
	}

	/**
	 * Capture output and stop execution without dying.
	 *
	 * @param string $message The die message.
	 *
	 * @throws \WPAjaxDieContinueException Always, to unwind the handler.
	 */
	public function ajax_die_handler( $message ): void {
		$this->ajax_response .= ob_get_clean();

		throw new \WPAjaxDieContinueException( $message );
	}
}
