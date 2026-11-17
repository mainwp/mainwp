<?php
/**
 * Personal inference must not change site connector behavior.
 *
 * @package MainWP/Dashboard/Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use MainWP\Dashboard\MainWP_Assistant_ChatGPT_Plan as Plan;
use MainWP\Dashboard\MainWP_Assistant_ChatGPT_Provider as Provider;
use MainWP\Dashboard\MainWP_Assistant_Policies as Policies;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_DB_Assistant as DB;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/** Uses the real WP builder and HTTP interception rather than canned AI results. */
class Test_Assistant_ChatGPT_Provider extends MainWP_Assistant_Test_Case {
	/**
	 * Owner.
	 *
	 * @var int
	 */
	private $user_id;
	/**
	 * Disposable key directory.
	 *
	 * @var string
	 */
	private $directory;
	/**
	 * Observed requests.
	 *
	 * @var array
	 */
	private $requests = array();
	/**
	 * Inference replies.
	 *
	 * @var array
	 */
	private $replies = array();
	/**
	 * Refresh reply.
	 *
	 * @var array
	 */
	private $refresh;
	/**
	 * Model discovery reply.
	 *
	 * @var array
	 */
	private $models;

	/** Establishes isolated personal credentials. */
	public function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client is unavailable.' );
		}
		$this->user_id   = $this->set_current_user_as_admin();
		$this->directory = sys_get_temp_dir() . '/mainwp-inference-' . wp_generate_uuid4();
		mkdir( $this->directory );
		add_filter( 'mainwp_get_wp_upload_dir', array( $this, 'upload_directory' ) );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->seed_plan();
		$this->refresh = $this->response(
			array(
				'access_token'  => 'rotated-access',
				'refresh_token' => 'rotated-refresh',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'scope'         => Plan::SCOPES,
			)
		);
		$this->models  = $this->response( array( 'models' => array() ) );
		update_option( Policies::OPTION_DAILY_BUDGET_USD, '0' );
		update_option( Policies::OPTION_USER_DAILY_BUDGET_USD, '0' );
		foreach ( wp_get_connectors() as $connector ) {
			if ( ! empty( $connector['authentication']['setting_name'] ) ) {
				delete_option( $connector['authentication']['setting_name'] );
			}
		}
	}

	/** Key fixtures must not survive the case. */
	public function tearDown(): void {
		foreach ( $this->modified_user_ids as $user_id ) {
			Plan::delete_user( $user_id );
			delete_transient( 'mainwp_assistant_provider_cooldown_' . Provider::ID . '_' . $user_id );
		}
		delete_transient( 'mainwp_assistant_ability_schemas_v' . MainWP_Assistant::ABILITY_SCHEMA_CACHE_REV );
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		remove_filter( 'mainwp_get_wp_upload_dir', array( $this, 'upload_directory' ) );
		if ( $this->directory && is_dir( $this->directory ) ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->directory );
		}
		parent::tearDown();
	}

	/**
	 * Keeps encrypted test credentials outside shared uploads.
	 *
	 * @param array $upload Upload paths.
	 * @return array
	 */
	public function upload_directory( $upload ) {
		$upload['basedir'] = $this->directory;
		return $upload;
	}

	/**
	 * Inference cases start after authentication without replaying OAuth.
	 *
	 * @param array $overrides Record overrides.
	 * @return void
	 */
	private function seed_plan( $overrides = array() ) {
		$record = get_user_meta( $this->user_id, Plan::META, true );
		$tokens = $record['tokens'] ?? MainWP_Keys_Manager::instance()->encrypt_keys_data(
			wp_json_encode(
				array(
					'access_token'  => 'personal-access',
					'refresh_token' => 'personal-refresh',
				)
			),
			'inference_' . wp_generate_uuid4()
		);
		update_user_meta(
			$this->user_id,
			Plan::META,
			array_merge(
				array(
					'status'     => 'connected',
					'client_id'  => 'oaiapp_personal',
					'email'      => 'owner@example.com',
					'sub'        => 'owner',
					'expires_at' => time() + 3600,
					'scopes'     => explode( ' ', Plan::SCOPES ),
					'tokens'     => $tokens,
					'models'     => array(
						array(
							'slug'         => 'gpt-test',
							'display_name' => 'Account model',
						),
					),
					'model'      => 'gpt-test',
					'last_error' => array(),
				),
				$overrides
			)
		);
	}

	/**
	 * Preserves buffered bodies and HTTP diagnostics for the WP transport.
	 *
	 * @param mixed $body Reply body.
	 * @param int   $status HTTP status.
	 * @return array
	 */
	private function response( $body, $status = 200 ) {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'x-request-id' => 'request-fixture' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		);
	}

	/**
	 * CRLF frames exercise the wire format with blank delimiters.
	 *
	 * @param string $event SSE event.
	 * @param array  $data Payload.
	 * @return string
	 */
	private function event( $event, $data ) {
		return 'event: ' . $event . "\r\ndata: " . wp_json_encode( $data ) . "\r\n\r\n";
	}

	/**
	 * Provisional text must be replaced by the terminal output.
	 *
	 * @param array|null $output Final output items.
	 * @return array
	 */
	private function completed( $output = null ) {
		$output = $output ?? array(
			array(
				'type'    => 'message',
				'content' => array(
					array(
						'type' => 'output_text',
						'text' => 'Finished.',
					),
				),
			),
		);
		return $this->response(
			$this->event( 'response.output_text.delta', array( 'delta' => 'provisional' ) ) . $this->event(
				'response.completed',
				array(
					'response' => array(
						'id'     => 'resp_test',
						'output' => $output,
						'usage'  => array(
							'input_tokens'          => 20,
							'output_tokens'         => 10,
							'total_tokens'          => 30,
							'output_tokens_details' => array( 'reasoning_tokens' => 2 ),
						),
					),
				)
			)
		);
	}

	/**
	 * Every outbound request must match a queued fixture.
	 *
	 * @param mixed  $preempt Prior override.
	 * @param array  $args HTTP arguments.
	 * @param string $url Endpoint.
	 * @return array
	 */
	public function http( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( Plan::TOKEN === $url ) {
			return $this->refresh;
		}
		if ( Plan::RESOURCE . '/models' === $url ) {
			return $this->models;
		}
		$this->assertSame( Plan::RESOURCE . '/responses', $url, 'Site provider must never receive the personal request.' );
		$this->assertNotEmpty( $this->replies, 'Unexpected inference request.' );
		return array_shift( $this->replies );
	}

	/**
	 * Private orchestration is exercised without bypassing generation.
	 *
	 * @param string $method Assistant method.
	 * @param array  $args Method arguments.
	 * @return mixed
	 */
	private function invoke( $method, $args = array() ) {
		$reflection = new \ReflectionMethod( MainWP_Assistant::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( new MainWP_Assistant(), $args );
	}

	/**
	 * Selection must happen again even when a prior provider was resolved.
	 *
	 * @param array|null $resolved Provider selection.
	 * @return array|\WP_Error
	 */
	private function turn( &$resolved = null ) {
		return $this->invoke( 'core_chat_turn', array( 'List sites.', array(), array(), $this->user_id, &$resolved, false ) );
	}

	/** Regression coverage for request has only allowed fields namespace and full history. */
	public function test_request_has_only_allowed_fields_namespace_and_full_history() {
		$this->replies[] = $this->completed();
		$history         = array(
			new UserMessage( array( new MessagePart( 'Question' ) ) ),
			new ModelMessage( array( new MessagePart( 'Checking' ), new MessagePart( new FunctionCall( 'call_1', 'lookup', json_decode( '{"id":7,"filter":{}}' ) ) ) ) ),
			new UserMessage( array( new MessagePart( new FunctionResponse( 'call_1', 'lookup', array( 'found' => true ) ) ) ) ),
			new ModelMessage( array( new MessagePart( new FunctionCall( 'call_2', 'lookup', array() ) ) ) ),
			new UserMessage( array( new MessagePart( new FunctionResponse( 'call_2', 'lookup', array( 'found' => false ) ) ) ) ),
		);
		$result          = wp_ai_client_prompt()->with_history( ...$history )->using_model( Provider::for_user( $this->user_id, 'obsolete' ) )->using_system_instruction( 'Rules' )->using_max_tokens( 123 )->using_temperature( 0.2 )->using_function_declarations( new FunctionDeclaration( 'lookup', 'Lookup site', array( 'type' => 'object' ) ) )->generate_text_result();
		$this->assertNotWPError( $result );
		$parsed = $this->invoke( 'extract_result_payload', array( $result ) );
		$this->assertSame( 'Finished.', $parsed['response'] );
		$this->assertSame( 'ChatGPT plan', $parsed['provider_name'] );
		$this->assertSame( 30, $parsed['usage']['total_tokens'] );
		$args = $this->requests[0]['args'];
		$this->assertSame( 'Bearer personal-access', $args['headers']['Authorization'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 120, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame(
			array(
				'model'        => 'gpt-test',
				'instructions' => 'Rules',
				'input'        => array(
					array(
						'role'    => 'user',
						'content' => 'Question',
					),
					array(
						'role'    => 'assistant',
						'content' => 'Checking',
					),
					array(
						'type'      => 'function_call',
						'call_id'   => 'call_1',
						'name'      => 'lookup',
						'arguments' => '{"id":7,"filter":{}}',
					),
					array(
						'type'    => 'function_call_output',
						'call_id' => 'call_1',
						'output'  => '{"found":true}',
					),
					array(
						'type'      => 'function_call',
						'call_id'   => 'call_2',
						'name'      => 'lookup',
						'arguments' => '{}',
					),
					array(
						'type'    => 'function_call_output',
						'call_id' => 'call_2',
						'output'  => '{"found":false}',
					),
				),
				'store'        => false,
				'stream'       => true,
				'tools'        => array(
					array(
						'type'        => 'namespace',
						'name'        => 'mainwp',
						'description' => "MainWP Dashboard tools for the user's managed WordPress sites.",
						'tools'       => array(
							array(
								'type'        => 'function',
								'name'        => 'lookup',
								'description' => 'Lookup site',
								'strict'      => false,
								'parameters'  => array( 'type' => 'object' ),
							),
						),
					),
				),
			),
			json_decode( $args['body'], true )
		);
	}

	/** Regression coverage for connected plan beats site key every turn and confirmation resume. */
	public function test_connected_plan_beats_site_key_every_turn_and_confirmation_resume() {
		update_option( 'connectors_ai_google_api_key', 'site-key' );
		$this->replies = array( $this->completed(), $this->completed(), $this->completed() );
		for ( $i = 0; $i < 2; ++$i ) {
			$resolved = null;
			$this->assertSame( Provider::ID, $this->turn( $resolved )['provider_id'] );
			$this->assertSame( Provider::ID, $resolved['provider_id'] );
		}
		$conversation = $this->create_test_conversation(
			$this->user_id,
			'Confirmation',
			array(
				'last_provider' => 'google',
				'last_model'    => 'gemini-old',
			)
		);
		$call         = array(
			'id'       => 'confirm_call',
			'type'     => 'function',
			'function' => array(
				'name'      => 'wpab__mainwp__sync-sites-v1',
				'arguments' => '{"site_ids_or_domains":[1]}',
			),
		);
		$result       = $this->invoke( 'resume_after_confirmation', array( array( 'success' => true ), 'confirm_call', array( $call ), array(), $conversation, $this->user_id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'ChatGPT plan', $result['provider_name'] );
		$last = json_decode( $this->requests[2]['args']['body'], true );
		$this->assertSame( 'gpt-test', $last['model'] );
		$this->assertContains(
			array(
				'type'    => 'function_call_output',
				'call_id' => 'confirm_call',
				'output'  => '{"success":true}',
			),
			$last['input']
		);
	}

	/** Regression coverage for inactive plan fails without site fallback or site failure changes. */
	public function test_inactive_plan_fails_without_site_fallback_or_site_failure_changes() {
		update_option( 'connectors_ai_google_api_key', 'site-key' );
		$failure = array(
			'code'        => 'provider_key_rejected',
			'fingerprint' => 'keep',
		);
		update_option( 'mainwp_assistant_provider_failure', $failure );
		foreach ( array( 'needs_reconnect', 'plan_disabled' ) as $status ) {
			$this->seed_plan( array( 'status' => $status ) );
			$this->assertSame( 'chatgpt_plan_reconnect', $this->turn()->get_error_code() );
			$this->assertSame( $failure, get_option( 'mainwp_assistant_provider_failure' ) );
		}
		delete_option( 'connectors_ai_google_api_key' );
		$this->assertSame( 'agent', $this->invoke( 'resolve_chat_route', array( 'agent', false ) ) );
		$this->assertSame( 'ask', $this->invoke( 'resolve_chat_route', array( 'ask', false ) ) );
		$call   = array(
			'id'       => 'blocked',
			'function' => array(
				'name'      => 'wpab__mainwp__sync-sites-v1',
				'arguments' => '{}',
			),
		);
		$resume = $this->invoke( 'resume_after_confirmation', array( array( 'success' => true ), 'blocked', array( $call ), array(), 0, $this->user_id ) );
		$this->assertSame( 'chatgpt_plan_reconnect', $resume->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	/** Regression coverage for other user has no agent mode or access to bound model. */
	public function test_other_user_has_no_agent_mode_or_access_to_bound_model() {
		$model = Provider::for_user( $this->user_id, 'gpt-test' );
		$this->set_current_user_as_admin();
		$this->assertFalse( MainWP_Assistant::agent_mode_available() );
		$result = wp_ai_client_prompt( 'Foreign request' )->using_model( $model )->generate_text_result();
		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_message() );
		$this->assertCount( 0, $this->requests );
	}

	/** Regression coverage for expired token refreshes under lock mid conversation. */
	public function test_expired_token_refreshes_under_lock_mid_conversation() {
		$this->replies = array( $this->completed(), $this->completed() );
		$resolved      = null;
		$this->assertNotWPError( $this->turn( $resolved ) );
		$this->seed_plan( array( 'expires_at' => time() - 1 ) );
		$lock_seen = false;
		$observe   = function ( $preempt, $args, $url ) use ( &$lock_seen ) {
			if ( Plan::TOKEN === $url ) {
				$lock_seen = false !== get_option( Plan::LOCK_PREFIX . $this->user_id, false );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $observe, 11, 3 );
		try {
			$this->assertNotWPError( $this->turn( $resolved ) );
		} finally {
			remove_filter( 'pre_http_request', $observe, 11 );
		}
		$this->assertTrue( $lock_seen );
		$this->assertSame( 'Bearer rotated-access', $this->requests[2]['args']['headers']['Authorization'] );
		global $wpdb;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Plan::LOCK_PREFIX . $this->user_id ) ) );
	}

	/** Regression coverage for unusable refresh returns plan reconnect without fallback. */
	public function test_unusable_refresh_returns_plan_reconnect_without_fallback() {
		$this->seed_plan( array( 'expires_at' => time() - 1 ) );
		$this->refresh = $this->response( array( 'error' => 'invalid_grant' ), 400 );
		update_option( 'connectors_ai_google_api_key', 'site-key' );
		$this->assertSame( 'chatgpt_plan_reconnect', $this->turn()->get_error_code() );
		$this->assertSame( 'needs_reconnect', Plan::get_status( $this->user_id )['status'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'invalid_grant', Plan::get_status( $this->user_id )['last_error']['code'] );
	}

	/** An unreachable token endpoint keeps the plan connected and asks the user to retry. */
	public function test_refresh_network_failure_maps_to_unavailable() {
		$this->seed_plan( array( 'expires_at' => time() - 1 ) );
		$this->refresh = new \WP_Error( 'http_request_failed', 'Connection timed out' );
		$error         = $this->turn();
		$this->assertWPError( $error );
		$this->assertSame( 'chatgpt_plan_unavailable', $error->get_error_code() );
		$this->assertSame( 'connected', Plan::get_status( $this->user_id )['status'] );
		$this->assertSame( 'network_error', Plan::get_status( $this->user_id )['last_error']['code'] );
	}

	/** Regression coverage for stream failures never accept partial output and map plan codes. */
	public function test_stream_failures_never_accept_partial_output_and_map_plan_codes() {
		$delta   = $this->event( 'response.output_text.delta', array( 'delta' => 'Partial answer' ) );
		$cases   = array(
			array( $this->response( $delta ), 'chatgpt_plan_unavailable' ),
			array( $this->response( $delta . $this->event( 'response.failed', array( 'response' => array( 'error' => array( 'code' => 'subscription_sharing_usage_limit_exceeded' ) ) ) ) ), 'chatgpt_plan_limit' ),
			array( $this->response( $delta . $this->event( 'response.incomplete', array( 'response' => array( 'incomplete_details' => array( 'reason' => 'max_output_tokens' ) ) ) ) ), 'chatgpt_plan_unavailable' ),
			array( $this->response( $delta . $this->event( 'error', array( 'code' => 'subscription_sharing_user_not_eligible' ) ) ), 'chatgpt_plan_not_eligible' ),
			array( $this->response( $delta . $this->event( 'error', array( 'code' => 'subscription_sharing_unsupported_capability' ) ) ), 'chatgpt_plan_rejected_request' ),
			array( $this->response( $delta . $this->event( 'response.failed', array( 'response' => array( 'error' => array( 'code' => 'subscription_sharing_usage_unavailable' ) ) ) ) ), 'chatgpt_plan_unavailable' ),
			array( $this->response( $delta . $this->event( 'response.failed', array( 'response' => array( 'error' => array( 'code' => 'server_error' ) ) ) ) ), 'chatgpt_plan_unavailable' ),
			array( $this->response( array( 'detail' => 'Unstable diagnostic' ), 503 ), 'chatgpt_plan_unavailable' ),
			array( $this->response( array( 'detail' => 'Unstable diagnostic' ), 403 ), 'chatgpt_plan_rejected_request' ),
			array( $this->response( array( 'detail' => 'Unstable diagnostic' ), 401 ), 'chatgpt_plan_reconnect' ),
		);
		$failure = array(
			'code'        => 'provider_key_rejected',
			'fingerprint' => 'unchanged',
		);
		update_option( 'mainwp_assistant_provider_failure', $failure );
		foreach ( $cases as $i => $case ) {
			$this->seed_plan();
			$this->replies[] = $case[0];
			$error           = $this->turn();
			$this->assertWPError( $error, (string) $i );
			$this->assertSame( $case[1], $error->get_error_code(), (string) $i );
			$this->assertStringNotContainsString( 'Partial answer', $error->get_error_message() );
			$this->assertSame( $failure, get_option( 'mainwp_assistant_provider_failure' ) );
		}
		$this->assertSame( 'needs_reconnect', Plan::get_status( $this->user_id )['status'] );
		$this->assertSame( 'request-fixture', Plan::get_status( $this->user_id )['last_error']['request_id'] );
	}

	/** Missing plan permission requires renewed consent. */
	public function test_plan_scope_errors_require_reconnect_and_missing_scope_requests_consent() {
		$cases = array(
			'chatpass_v2_scope_not_authorized'          => 'plan_disabled',
			'chatpass_v2_invalid_authorization_context' => 'needs_reconnect',
		);
		foreach ( $cases as $code => $status ) {
			$this->seed_plan();
			$this->replies[] = $this->response( array( 'error' => array( 'code' => $code ) ), 403 );
			$error           = $this->turn();
			$this->assertWPError( $error, $code );
			$this->assertSame( 'chatgpt_plan_reconnect', $error->get_error_code(), $code );
			$this->assertSame( $status, Plan::get_status( $this->user_id )['status'], $code );
			$url = Plan::start_connect( $this->user_id );
			$this->assertIsString( $url );
			parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $params );
			if ( 'plan_disabled' === $status ) {
				$this->assertSame( 'consent', $params['prompt'] );
			} else {
				$this->assertArrayNotHasKey( 'prompt', $params );
			}
		}
	}

	/** Regression coverage for function call round trip echoes call id and tool output. */
	public function test_function_call_round_trip_echoes_call_id_and_tool_output() {
		$this->set_user_access_mode( $this->user_id, Policies::MODE_FULL_ACCESS );
		$call          = array(
			'type'      => 'function_call',
			'call_id'   => 'round_trip',
			'name'      => 'wpab__mainwp__get-sites-basic-v1',
			'arguments' => '{}',
		);
		$this->replies = array( $this->completed( array( $call ) ), $this->completed() );
		$conversation  = $this->create_test_conversation( $this->user_id );
		$result        = $this->invoke( 'execute_ai_chat', array( 'List sites.', $conversation, array(), 0, $this->user_id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'Finished.', $result['response'] );
		$this->assertCount( 2, $this->requests );
		$input = json_decode( $this->requests[1]['args']['body'], true )['input'];
		$this->assertContains( $call, $input );
		$outputs = array_values(
			array_filter(
				$input,
				static function ( $item ) {
					return 'function_call_output' === ( $item['type'] ?? '' );
				}
			)
		);
		$this->assertCount( 1, $outputs );
		$this->assertSame( 'round_trip', $outputs[0]['call_id'] );
		$output = json_decode( $outputs[0]['output'], true );
		$this->assertIsArray( $output );
		$this->assertArrayNotHasKey( 'error', $output );
		$this->assertSame( 'ChatGPT plan', $result['provider_name'] );
	}

	/** Regression coverage for plan limit cooldown is per user without reset time. */
	public function test_plan_limit_cooldown_is_per_user_without_reset_time() {
		$this->replies[] = $this->response( array( 'error' => array( 'code' => 'rate_limit_exceeded' ) ), 429 );
		$error           = $this->turn();
		$this->assertSame( 'chatgpt_plan_limit', $error->get_error_code() );
		$this->assertArrayNotHasKey( 'retry_after', $error->get_error_data() );
		$this->assertGreaterThan( time(), get_transient( 'mainwp_assistant_provider_cooldown_' . Provider::ID . '_' . $this->user_id ) );
		$other = $this->set_current_user_as_admin();
		$this->assertFalse( get_transient( 'mainwp_assistant_provider_cooldown_' . Provider::ID . '_' . $other ) );
		$this->assertFalse( get_transient( 'mainwp_assistant_provider_cooldown_' . Provider::ID ) );
		$this->user_id = $other;
		$this->seed_plan();
		$this->replies[] = $this->completed();
		$this->assertNotWPError( $this->turn() );
	}

	/** Regression coverage for plan turn costs zero bypasses budgets and records tokens and badges. */
	public function test_plan_turn_costs_zero_bypasses_budgets_and_records_tokens_and_badges() {
		update_option( Policies::OPTION_DAILY_BUDGET_USD, '0.01' );
		update_option( Policies::OPTION_USER_DAILY_BUDGET_USD, '0.01' );
		DB::instance()->add_spend( 1.0, array(), true, $this->user_id );
		$site_failure = array(
			'code'        => 'provider_key_rejected',
			'fingerprint' => 'site',
		);
		update_option( 'mainwp_assistant_provider_failure', $site_failure );
		$before          = DB::instance()->get_user_spend_today( $this->user_id );
		$this->replies[] = $this->completed();
		$conversation    = $this->create_test_conversation( $this->user_id );
		$result          = $this->invoke( 'execute_ai_chat', array( 'Hello.', $conversation, array(), 0, $this->user_id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 0.0, $result['cost']['usd'] );
		$this->assertSame( 'ChatGPT plan', $result['provider_name'] );
		$this->assertSame( 30, $result['usage']['total_tokens'] );
		$after = DB::instance()->get_user_spend_today( $this->user_id );
		$this->assertEquals( $before['cost_usd'], $after['cost_usd'] );
		$this->assertEquals( 20, $after['prompt_tokens'] - $before['prompt_tokens'] );
		$this->assertEquals( 10, $after['completion_tokens'] - $before['completion_tokens'] );
		$row  = DB::instance()->get_conversation( $conversation, $this->user_id );
		$meta = is_array( $row->metadata ) ? $row->metadata : json_decode( $row->metadata, true );
		$this->assertSame( 'ChatGPT plan', $meta['last_provider_name'] );
		$this->assertSame( Provider::ID, $meta['last_provider'] );
		$messages = DB::instance()->get_messages( $conversation, $this->user_id );
		$stored   = end( $messages )->metadata;
		$receipt  = is_array( $stored ) ? $stored : json_decode( $stored, true );
		$this->assertSame( 0.0, (float) $receipt['cost']['usd'] );
		$this->assertSame( Provider::ID, $receipt['provider_id'] );
		$this->assertSame( $site_failure, get_option( 'mainwp_assistant_provider_failure' ) );
	}

	/** Regression coverage for provider is never lazy resolved or registered as connector. */
	public function test_provider_is_never_lazy_resolved_or_registered_as_connector() {
		$this->assertTrue( \WordPress\AiClient\AiClient::defaultRegistry()->hasProvider( Provider::ID ) );
		$this->assertFalse( Provider::availability()->isConfigured() );
		$this->assertNull( Provider::metadata()->getAuthenticationMethod() );
		$this->assertArrayNotHasKey( Provider::ID, wp_get_connectors() );
		$result = wp_ai_client_prompt( 'Another plugin' )->generate_text_result();
		$this->assertWPError( $result );
		$this->assertCount( 0, $this->requests );
	}

	/** Regression coverage for no plan preserves site builder and error mapping. */
	public function test_no_plan_preserves_site_builder_and_error_mapping() {
		Plan::delete_user( $this->user_id );
		update_option( 'connectors_ai_google_api_key', 'site-key' );
		$this->assertTrue( MainWP_Assistant::agent_mode_available() );
		$builder = $this->invoke( 'build_prompt_builder', array( array( new UserMessage( array( new MessagePart( 'Site' ) ) ) ), array(), null, $this->user_id ) );
		$wp      = new \ReflectionProperty( $builder, 'builder' );
		$wp->setAccessible( true );
		$sdk   = $wp->getValue( $builder );
		$model = new \ReflectionProperty( $sdk, 'model' );
		$model->setAccessible( true );
		$this->assertNull( $model->getValue( $sdk ) );
		$error = $this->invoke( 'map_provider_wp_error', array( new \WP_Error( 'prompt_client_error', 'Rejected', array( 'status' => 401 ) ), 'google', $this->user_id ) );
		$this->assertSame( 'provider_key_rejected', $error->get_error_code() );
	}

	/** Regression coverage for model discovery errors and empty lists fail without site fallback. */
	public function test_model_discovery_errors_and_empty_lists_fail_without_site_fallback() {
		update_option( 'connectors_ai_google_api_key', 'site-key' );
		foreach ( array( $this->response( array( 'detail' => 'Unavailable' ), 503 ), $this->response( array( 'models' => array() ) ) ) as $models ) {
			$this->seed_plan( array( 'models' => array() ) );
			$this->models = $models;
			$this->assertSame( 'chatgpt_plan_unavailable', $this->turn()->get_error_code() );
		}
		$this->assertCount( 2, $this->requests );
	}
	/** Core prevents prompts when AI support is switched off. */
	public function test_connected_plan_respects_disabled_ai_support() {
		add_filter( 'wp_supports_ai', '__return_false' );
		try {
			$this->assertFalse( MainWP_Assistant::agent_mode_available() );
			$data = $this->check_provider();
			$this->assertFalse( $data['available'] );
			$this->assertNull( $data['provider_name'] );
			$this->assertFalse( $data['wizard_available'] );
			$this->assertSame( 'ask', $this->invoke( 'resolve_chat_route', array( 'agent', MainWP_Assistant::agent_mode_available() ) ) );
			$this->assertSame( array(), $this->requests, 'No personal inference request should be sent.' );
		} finally {
			remove_filter( 'wp_supports_ai', '__return_false' );
		}
		$this->assertTrue( MainWP_Assistant::agent_mode_available() );
	}

	/** Availability reports only the current user's connection and effective budget. */
	public function test_check_provider_reports_plan_status_name_budget_and_user_cooldown() {
		update_option( Policies::OPTION_DAILY_BUDGET_USD, '0.01' );
		DB::instance()->add_spend( 1.0, array(), true, $this->user_id );
		$until = time() + 60;
		set_transient( 'mainwp_assistant_provider_cooldown_' . Provider::ID . '_' . $this->user_id, $until, 60 );
		$data = $this->check_provider();
		$this->assertTrue( $data['available'] );
		$this->assertSame( 'ChatGPT plan', $data['provider_name'] );
		$this->assertFalse( $data['budget']['blocked'] );
		$this->assertSame( $until, $data['cooldown_until'] );
		$this->assertSame(
			array(
				'connected'        => true,
				'status'           => 'connected',
				'email'            => 'owner@example.com',
				'model'            => 'gpt-test',
				'welcome_pending'  => true,
				'manage_usage_url' => 'https://chatgpt.com/settings/usage',
			),
			array_replace( array_fill_keys( array( 'connected', 'status', 'email', 'model', 'welcome_pending', 'manage_usage_url' ), null ), $data['chatgpt_plan'] )
		);
		$this->assertArrayNotHasKey( 'client_id', $data['chatgpt_plan'] );
		$this->seed_plan( array( 'status' => 'plan_disabled' ) );
		update_option( 'connectors_ai_google_api_key', 'site-key' );
		$data = $this->check_provider();
		$this->assertFalse( $data['available'] );
		$this->assertSame( 'chatgpt_plan_reconnect', $data['reason_code'] );
		$this->set_current_user_as_admin();
		$data = $this->check_provider();
		$this->assertFalse( $data['chatgpt_plan']['connected'] );
		$this->assertSame( 'disconnected', $data['chatgpt_plan']['status'] );
		$this->assertNull( $data['provider_name'] );
		$this->assertSame( 0, $data['cooldown_until'] );
	}

	/**
	 * Allows assertions on the JSON sent through the real handler.
	 *
	 * @return array
	 */
	private function check_provider() {
		$_REQUEST = array( 'security' => wp_create_nonce( MainWP_Assistant::AJAX_CHECK_PROVIDER ) );
		$handler  = static function () {
			return static function ( $message ) {
				throw new \WPAjaxDieContinueException( (string) $message );
			};
		};
		add_filter( 'wp_die_ajax_handler', $handler );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$level = ob_get_level();
		ob_start();
		try {
			MainWP_Assistant::instance()->handle_check_provider();
			$this->fail( 'Handler did not terminate.' );
		} catch ( \WPAjaxDieContinueException $exception ) {
			$output = ob_get_contents() . $exception->getMessage();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			remove_filter( 'wp_die_ajax_handler', $handler );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$_REQUEST = array();
		}
		$result = json_decode( $output, true );
		$this->assertTrue( $result['success'] );
		return $result['data'];
	}
}
