<?php
/**
 * Tests for MainWP_Assistant WordPress core AI Client integration helpers.
 *
 * These tests cover the DTO boundary layer: availability detection,
 * DB-row-to-Message conversion, function declaration building, and
 * GenerativeAiResult extraction.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;

/**
 * Class Test_Assistant_Core_AI
 *
 * Tests the core AI Client boundary helpers on MainWP_Assistant.
 */
class Test_Assistant_Core_AI extends MainWP_Assistant_Test_Case {

	/**
	 * Skip agent-path tests when the WP AI Client is unavailable in this harness.
	 *
	 * @return void
	 */
	protected function require_ai_client(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
		}
	}

	/**
	 * Invoke a private/protected method on the assistant singleton.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 *
	 * @return mixed
	 */
	protected function invoke_assistant( string $method, array $args = array() ) {
		$instance   = MainWP_Assistant::instance();
		$reflection = new \ReflectionMethod( $instance, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $instance, $args );
	}

	// =========================================================================
	// agent_mode_available()
	// =========================================================================

	/**
	 * Availability is false when no AI connector has credentials.
	 *
	 * The test harness has no provider plugins installed and no connector
	 * API keys stored, so the computed result must be false.
	 *
	 * @return void
	 */
	public function test_agent_mode_available_false_without_configured_connector() {
		$this->require_ai_client();

		$this->assertFalse( MainWP_Assistant::agent_mode_available() );
	}

	/**
	 * The availability filter can force the result (test seam + extension point).
	 *
	 * @return void
	 */
	public function test_agent_mode_available_filter_override() {
		add_filter( 'mainwp_assistant_agent_mode_available', '__return_true' );

		$this->assertTrue( MainWP_Assistant::agent_mode_available() );

		remove_filter( 'mainwp_assistant_agent_mode_available', '__return_true' );
	}

	/**
	 * Availability never fatals when core AI functions are missing.
	 *
	 * On a WP 7.0 harness the functions exist, so this asserts the guard
	 * path indirectly: the method must return a boolean, never throw.
	 *
	 * @return void
	 */
	public function test_agent_mode_available_returns_boolean() {
		$this->assertIsBool( MainWP_Assistant::agent_mode_available() );
	}

	// =========================================================================
	// history_to_messages()
	// =========================================================================

	/**
	 * User and assistant text rows convert to UserMessage/ModelMessage pairs.
	 *
	 * @return void
	 */
	public function test_history_to_messages_converts_text_rows() {
		$this->require_ai_client();

		$rows = array(
			(object) array(
				'role'    => 'user',
				'content' => 'List my sites',
			),
			(object) array(
				'role'    => 'assistant',
				'content' => 'Here are your sites.',
			),
		);

		$messages = $this->invoke_assistant( 'history_to_messages', array( $rows ) );

		$this->assertCount( 2, $messages );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\UserMessage::class, $messages[0] );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\ModelMessage::class, $messages[1] );
		$this->assertSame( 'List my sites', $messages[0]->getParts()[0]->getText() );
	}

	/**
	 * Leading non-user rows are trimmed (core requires the first message be user-role).
	 *
	 * @return void
	 */
	public function test_history_to_messages_trims_leading_assistant_rows() {
		$this->require_ai_client();

		$rows = array(
			(object) array(
				'role'    => 'assistant',
				'content' => 'Orphaned assistant row from a trimmed context window.',
			),
			(object) array(
				'role'    => 'user',
				'content' => 'Hello',
			),
			(object) array(
				'role'    => 'assistant',
				'content' => 'Hi!',
			),
		);

		$messages = $this->invoke_assistant( 'history_to_messages', array( $rows ) );

		$this->assertCount( 2, $messages );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\UserMessage::class, $messages[0] );
	}

	/**
	 * Assistant rows carrying tool_calls become ModelMessages with FunctionCall parts.
	 *
	 * @return void
	 */
	public function test_history_to_messages_reconstructs_function_calls() {
		$this->require_ai_client();

		$rows = array(
			(object) array(
				'role'    => 'user',
				'content' => 'Delete site 5',
			),
			(object) array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_abc',
						'type'     => 'function',
						'function' => array(
							'name'      => 'wpab__mainwp__delete-site-v1',
							'arguments' => '{"site_id_or_domain":5}',
						),
					),
				),
			),
			(object) array(
				'role'         => 'tool',
				'content'      => '{"success":true}',
				'tool_call_id' => 'call_abc',
			),
		);

		$messages = $this->invoke_assistant( 'history_to_messages', array( $rows ) );

		$this->assertCount( 3, $messages );

		$model_parts = $messages[1]->getParts();
		$this->assertTrue( $model_parts[0]->getType()->isFunctionCall() );
		$call = $model_parts[0]->getFunctionCall();
		$this->assertSame( 'call_abc', $call->getId() );
		$this->assertSame( 'wpab__mainwp__delete-site-v1', $call->getName() );
		$this->assertSame( array( 'site_id_or_domain' => 5 ), $call->getArgs() );

		$tool_message = $messages[2];
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\UserMessage::class, $tool_message );
		$tool_parts = $tool_message->getParts();
		$this->assertCount( 1, $tool_parts );
		$this->assertTrue( $tool_parts[0]->getType()->isFunctionResponse() );
		$this->assertSame( 'call_abc', $tool_parts[0]->getFunctionResponse()->getId() );
		$this->assertSame( 'wpab__mainwp__delete-site-v1', $tool_parts[0]->getFunctionResponse()->getName(), 'Google keys function responses by name, and its proto rejects an empty one.' );
	}

	/**
	 * Empty function-call arguments reconstruct as null, never array().
	 *
	 * PHP's empty array JSON-encodes as a list, and Google's proto rejects a
	 * list where the args Struct belongs ("Proto field is not repeating,
	 * cannot start list"). The Google provider omits args when null.
	 *
	 * @return void
	 */
	public function test_history_to_messages_empty_args_become_null() {
		$this->require_ai_client();

		$rows = array(
			(object) array(
				'role'    => 'user',
				'content' => 'Which sites have updates?',
			),
			(object) array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_1',
						'type'     => 'function',
						'function' => array(
							'name'      => 'wpab__mainwp__list-updates-v1',
							'arguments' => '{}',
						),
					),
				),
			),
			(object) array(
				'role'         => 'tool',
				'content'      => '{"updates":[]}',
				'tool_call_id' => 'call_1',
			),
		);

		$messages = $this->invoke_assistant( 'history_to_messages', array( $rows ) );

		$call = $messages[1]->getParts()[0]->getFunctionCall();
		$this->assertNull( $call->getArgs() );
	}

	/**
	 * Each tool row becomes its own single-part UserMessage.
	 *
	 * The OpenAI-compatible wire layer requires a function response to be the
	 * sole content of its message, so consecutive tool rows must not merge.
	 *
	 * @return void
	 */
	public function test_history_to_messages_keeps_tool_rows_separate() {
		$this->require_ai_client();

		$rows = array(
			(object) array(
				'role'    => 'user',
				'content' => 'Check two sites',
			),
			(object) array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_1',
						'type'     => 'function',
						'function' => array(
							'name'      => 'wpab__mainwp__get-site-v1',
							'arguments' => '{"site_id_or_domain":1}',
						),
					),
					array(
						'id'       => 'call_2',
						'type'     => 'function',
						'function' => array(
							'name'      => 'wpab__mainwp__get-site-v1',
							'arguments' => '{"site_id_or_domain":2}',
						),
					),
				),
			),
			(object) array(
				'role'         => 'tool',
				'content'      => '{"id":1}',
				'tool_call_id' => 'call_1',
			),
			(object) array(
				'role'         => 'tool',
				'content'      => '{"id":2}',
				'tool_call_id' => 'call_2',
			),
		);

		$messages = $this->invoke_assistant( 'history_to_messages', array( $rows ) );

		$this->assertCount( 4, $messages );
		$this->assertCount( 1, $messages[2]->getParts() );
		$this->assertCount( 1, $messages[3]->getParts() );
		$this->assertSame( 'call_2', $messages[3]->getParts()[0]->getFunctionResponse()->getId() );
	}

	/**
	 * Rows with empty content and no tool calls are skipped.
	 *
	 * @return void
	 */
	public function test_history_to_messages_skips_empty_rows() {
		$this->require_ai_client();

		$rows = array(
			(object) array(
				'role'    => 'user',
				'content' => 'Hello',
			),
			(object) array(
				'role'    => 'assistant',
				'content' => '',
			),
			(object) array(
				'role'    => 'user',
				'content' => 'Are you there?',
			),
		);

		$messages = $this->invoke_assistant( 'history_to_messages', array( $rows ) );

		$this->assertCount( 2, $messages );
		$this->assertSame( 'Are you there?', $messages[1]->getParts()[0]->getText() );
	}

	// =========================================================================
	// extract_result_payload()
	// =========================================================================

	/**
	 * A text-only result extracts response text and snake_case usage keys.
	 *
	 * @return void
	 */
	public function test_extract_result_payload_text_only() {
		$this->require_ai_client();

		$result = $this->make_ai_result(
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'All sites are healthy.' ) ),
			array( 100, 20, 120 )
		);

		$payload = $this->invoke_assistant( 'extract_result_payload', array( $result ) );

		$this->assertIsArray( $payload );
		$this->assertSame( 'All sites are healthy.', $payload['response'] );
		$this->assertFalse( $payload['has_tool_calls'] );
		$this->assertSame( array(), $payload['tool_calls'] );
		$this->assertSame( 100, $payload['usage']['prompt_tokens'] );
		$this->assertSame( 20, $payload['usage']['completion_tokens'] );
		$this->assertSame( 120, $payload['usage']['total_tokens'] );
		$this->assertSame( 'openai', $payload['provider_id'] );
		$this->assertSame( 'gpt-test', $payload['model_id'] );
	}

	/**
	 * A parser failure quotes the SDK exception, and the SDK puts the request
	 * it was making into that text; the quote leaves the server scrubbed.
	 *
	 * @return void
	 */
	public function test_extract_result_payload_scrubs_the_parser_exception() {
		$throwing = new class() {
			public function toMessage() {
				throw new \RuntimeException( 'Unexpected Google API response from https://generativelanguage.googleapis.test/v1beta/models?key=AIzaSyA1234567890abcdefghijklmnopqrstuv (AIzaSyA1234567890abcdefghijklmnopqrstuv)' );
			}
		};

		$payload = $this->invoke_assistant( 'extract_result_payload', array( $throwing ) );

		$this->assertWPError( $payload );
		$this->assertSame( 'ai_result_parse_failed', $payload->get_error_code() );
		$this->assertStringNotContainsString( 'AIzaSyA1234567890abcdefghijklmnopqrstuv', $payload->get_error_message() );
		$this->assertStringContainsString( 'Unexpected Google API response', $payload->get_error_message(), 'The non-secret part of the detail stays.' );
	}

	/**
	 * A function-call-only result must not throw and must expose wire-shaped tool calls.
	 *
	 * GenerativeAiResult::toText() throws on such results; the extractor must
	 * scan parts instead of calling it.
	 *
	 * @return void
	 */
	public function test_extract_result_payload_function_call_only() {
		$this->require_ai_client();

		$call   = new \WordPress\AiClient\Tools\DTO\FunctionCall(
			'call_xyz',
			'wpab__mainwp__list-sites-v1',
			array( 'status' => 'connected' )
		);
		$result = $this->make_ai_result(
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( $call ) )
		);

		$payload = $this->invoke_assistant( 'extract_result_payload', array( $result ) );

		$this->assertIsArray( $payload );
		$this->assertSame( '', $payload['response'] );
		$this->assertTrue( $payload['has_tool_calls'] );
		$this->assertCount( 1, $payload['tool_calls'] );
		$this->assertSame( 'call_xyz', $payload['tool_calls'][0]['id'] );
		$this->assertSame( 'wpab__mainwp__list-sites-v1', $payload['tool_calls'][0]['function']['name'] );
		$this->assertSame(
			array( 'status' => 'connected' ),
			json_decode( $payload['tool_calls'][0]['function']['arguments'], true )
		);
	}

	/**
	 * A function call's thought signature is captured into the wire shape.
	 *
	 * Gemini thinking models attach a thought signature to function-call parts
	 * and reject the follow-up turn unless it is echoed back unchanged.
	 *
	 * @return void
	 */
	public function test_extract_result_payload_captures_thought_signature() {
		$this->require_ai_client();

		$call   = new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_sig', 'wpab__mainwp__list-updates-v1', null );
		$result = $this->make_ai_result(
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( $call, null, 'sig-abc123' ) )
		);

		$payload = $this->invoke_assistant( 'extract_result_payload', array( $result ) );

		$this->assertSame( 'sig-abc123', $payload['tool_calls'][0]['thought_signature'] );
	}

	/**
	 * A function call without an id gets a synthesized one.
	 *
	 * Gemini's functionCall parts carry no id, which left tool rows with an
	 * empty tool_call_id; history_to_messages() then dropped them as orphans
	 * and the rebuilt history ended with a model message the SDK rejects.
	 *
	 * @return void
	 */
	public function test_extract_result_payload_synthesizes_missing_call_id() {
		$this->require_ai_client();

		$call   = new \WordPress\AiClient\Tools\DTO\FunctionCall( null, 'wpab__mainwp__list-updates-v1', array( 'page' => 1 ) );
		$result = $this->make_ai_result(
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( $call ) )
		);

		$payload = $this->invoke_assistant( 'extract_result_payload', array( $result ) );

		$this->assertNotSame( '', $payload['tool_calls'][0]['id'] );
		$this->assertStringStartsWith( 'call_', $payload['tool_calls'][0]['id'] );
	}

	/**
	 * A stored thought signature is re-attached to the rebuilt function-call part.
	 *
	 * @return void
	 */
	public function test_history_to_messages_reattaches_thought_signature() {
		$this->require_ai_client();

		$rows = array(
			(object) array(
				'role'    => 'user',
				'content' => 'Which sites have updates?',
			),
			(object) array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'                => 'call_sig',
						'type'              => 'function',
						'function'          => array(
							'name'      => 'wpab__mainwp__list-updates-v1',
							'arguments' => '{}',
						),
						'thought_signature' => 'sig-abc123',
					),
				),
			),
			(object) array(
				'role'         => 'tool',
				'content'      => '{"updates":[]}',
				'tool_call_id' => 'call_sig',
			),
		);

		$messages = $this->invoke_assistant( 'history_to_messages', array( $rows ) );

		$this->assertSame( 'sig-abc123', $messages[1]->getParts()[0]->getThoughtSignature() );
	}

	/**
	 * Mixed text + function-call results extract both.
	 *
	 * @return void
	 */
	public function test_extract_result_payload_mixed_parts() {
		$this->require_ai_client();

		$call   = new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_1', 'wpab__mainwp__get-site-v1', array( 'site_id_or_domain' => 3 ) );
		$result = $this->make_ai_result(
			array(
				new \WordPress\AiClient\Messages\DTO\MessagePart( 'Let me check that site.' ),
				new \WordPress\AiClient\Messages\DTO\MessagePart( $call ),
			)
		);

		$payload = $this->invoke_assistant( 'extract_result_payload', array( $result ) );

		$this->assertSame( 'Let me check that site.', $payload['response'] );
		$this->assertTrue( $payload['has_tool_calls'] );
		$this->assertCount( 1, $payload['tool_calls'] );
	}

	// =========================================================================
	// build_function_declarations()
	// =========================================================================

	/**
	 * Declarations use the core resolver naming and normalized schemas.
	 *
	 * @return void
	 */
	public function test_build_function_declarations_naming_and_schema_normalization() {
		$this->require_ai_client();

		$user_id = $this->set_current_user_as_admin();

		$declarations = $this->invoke_assistant( 'build_function_declarations', array( $user_id, array( 'mainwp-sites' ), false ) );
		$name         = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( 'mainwp/get-site-v1' );
		$index        = array_search( $name, array_map( static fn( $tool ) => $tool->getName(), $declarations ), true );
		$this->assertSame( 'wpab__mainwp__get-site-v1', $name );
		$this->assertNotFalse( $index );

		$parameters = $declarations[ $index ]->getParameters();
		$this->assertIsArray( $parameters );
		$this->assertIsString(
			$parameters['properties']['site_id_or_domain']['type'],
			'Union-typed property must be normalized to a single string type.'
		);
	}

	// =========================================================================
	// Ability naming contract (canary)
	// =========================================================================

	/**
	 * Every registered mainwp/ ability name must satisfy core's ability-name
	 * contract, which guarantees the wpab__ function-name round-trip is lossless.
	 *
	 * Core enforces this at registration (class-wp-abilities-registry.php), so a
	 * failure here means an ability slipped past registration entirely.
	 *
	 * @return void
	 */
	public function test_mainwp_ability_names_round_trip_safely() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API not available in this WordPress version.' );
		}

		$abilities = wp_get_abilities();
		$checked   = 0;

		foreach ( $abilities as $ability ) {
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'mainwp/' ) ) {
				continue;
			}
			++$checked;
			$this->assertMatchesRegularExpression(
				'/^[a-z0-9-]+\/[a-z0-9-]+$/',
				$name,
				"Ability name {$name} violates the core naming contract required for wpab__ round-trips."
			);
		}

		$this->assertGreaterThan( 0, $checked, 'Expected registered mainwp/ abilities in the test environment.' );
	}
}
