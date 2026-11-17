<?php
/**
 * Tests for MainWP Assistant Bridge.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_Bridge;
use MainWP\Dashboard\MainWP_Assistant_Policies;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Test class for bridge request and URL normalization behavior.
 */
class Test_Assistant_Bridge extends MainWP_Assistant_Test_Case {

	/**
	 * Clean up bridge state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );

		update_option( MainWP_Assistant_Policies::OPTION_CONSENT, false );
		delete_option( MainWP_Assistant_Policies::OPTION_CONSENT_VERSION );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', false );
		delete_option( 'mainwp_assistant_bridge_uuid' );
		delete_transient( 'mainwp_bridge_quota_remaining' );
		delete_transient( 'mainwp_bridge_register_backoff' );

		parent::tearDown();
	}

	/**
	 * Test request blocks outbound calls when consent is missing.
	 */
	public function test_request_requires_current_consent() {
		$bridge = MainWP_Assistant_Bridge::instance();
		$calls  = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt ) use ( &$calls ) {
				++$calls;
				return $preempt;
			}
		);

		$result = $bridge->request( 'hello world' );

		$this->assertWPErrorWithCode( $result, 'bridge_no_consent' );
		$this->assertSame( 0, $calls, 'Bridge request should not issue HTTP calls without consent.' );
	}

	/**
	 * Test normalize_url preserves non-default ports and strips default ones.
	 */
	public function test_normalize_url_handles_default_and_custom_ports() {
		$bridge = MainWP_Assistant_Bridge::instance();

		$this->assertSame(
			'https://example.com:8443/docs',
			$bridge->normalize_url( 'https://Example.com:8443/docs/?q=1' )
		);
		$this->assertSame(
			'https://example.com/docs',
			$bridge->normalize_url( 'https://Example.com:443/docs/' )
		);
		$this->assertSame(
			'http://example.com/docs',
			$bridge->normalize_url( 'http://Example.com:80/docs/' )
		);
	}

	/**
	 * Test normalization answer truncation.
	 */
	public function test_normalize_response_answer_truncation() {
		$result = MainWP_Assistant_Bridge::instance()->normalize_response( array( 'truncated' => true, 'truncated_fields' => array( 'answer' ) ) );

		$this->assertSame( true, $result['truncated'] );
		$this->assertSame( array( 'answer' ), $result['truncated_fields'] );
	}

	/**
	 * Test normalization filters truncated fields.
	 */
	public function test_normalize_response_filters_truncated_fields() {
		$result = MainWP_Assistant_Bridge::instance()->normalize_response( array( 'truncated' => true, 'truncated_fields' => array( 'sources', 'bogus', 'answer', 'answer' ) ) );

		$this->assertSame( true, $result['truncated'] );
		$this->assertSame( array( 'sources', 'answer' ), $result['truncated_fields'] );
	}

	/**
	 * Test normalization rejects string truncated.
	 */
	public function test_normalize_response_rejects_string_truncated() {
		$result = MainWP_Assistant_Bridge::instance()->normalize_response( array( 'truncated' => 'true', 'truncated_fields' => array( 'answer' ) ) );

		$this->assertSame( false, $result['truncated'] );
		$this->assertSame( array(), $result['truncated_fields'] );
	}

	/**
	 * Test normalization clears fields when not truncated.
	 */
	public function test_normalize_response_clears_fields_when_not_truncated() {
		$result = MainWP_Assistant_Bridge::instance()->normalize_response( array( 'truncated' => false, 'truncated_fields' => array( 'answer' ) ) );

		$this->assertSame( false, $result['truncated'] );
		$this->assertSame( array(), $result['truncated_fields'] );
	}

	/**
	 * Test normalization defaults without truncation.
	 */
	public function test_normalize_response_defaults_without_truncation() {
		$result = MainWP_Assistant_Bridge::instance()->normalize_response( array() );

		$this->assertSame( false, $result['truncated'] );
		$this->assertSame( array(), $result['truncated_fields'] );
	}

	/**
	 * Test normalization rejects non array fields.
	 */
	public function test_normalize_response_rejects_non_array_fields() {
		$result = MainWP_Assistant_Bridge::instance()->normalize_response( array( 'truncated' => true, 'truncated_fields' => 'answer' ) );

		$this->assertSame( true, $result['truncated'] );
		$this->assertSame( array(), $result['truncated_fields'] );
	}

	/**
	 * Test 503 retry runs before invalid-JSON failure.
	 */
	public function test_request_retries_non_json_503_before_returning_unavailable() {
		$this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT, true );
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT_VERSION, MainWP_Assistant_Policies::CURRENT_CONSENT_VERSION );

		$bridge = MainWP_Assistant_Bridge::instance();
		$calls  = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$calls ) {
				++$calls;

				if ( false !== strpos( $url, '/assistant/register' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'uuid'  => 'test-uuid',
								'token' => 'test-token',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return array(
					'headers'  => array(),
					'body'     => 'temporary upstream outage',
					'response' => array(
						'code'    => 503,
						'message' => 'Service Unavailable',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = $bridge->request( 'docs please' );

		$this->assertWPErrorWithCode( $result, 'bridge_unavailable' );
		$this->assertSame( 3, $calls, 'Bridge should register once and retry the chat request once on HTTP 503.' );
	}

	/**
	 * Only monthly quota responses should record an exhausted quota.
	 *
	 * @dataProvider bridge_429_responses
	 *
	 * @param string    $body               Bridge response body.
	 * @param string    $expected_code      Expected error code.
	 * @param int|false $expected_remaining Expected cached quota.
	 * @param string    $expected_message   Expected error message.
	 */
	public function test_request_distinguishes_429_limits( $body, $expected_code, $expected_remaining, $expected_message ) {
		$this->set_current_user_as_admin();
		$this->set_assistant_consent( true );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', 'test-token' );
		delete_transient( 'mainwp_bridge_quota_remaining' );
		$calls = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls, $body ) {
				++$calls;
				$this->assertStringEndsWith( '/assistant/chat', $url );
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ),
				);
			},
			10,
			3
		);

		$result = MainWP_Assistant_Bridge::instance()->request( 'docs please' );

		$this->assertWPErrorWithCode( $result, $expected_code );
		$this->assertSame( $expected_message, $result->get_error_message() );
		$this->assertSame( $expected_remaining, get_transient( 'mainwp_bridge_quota_remaining' ) );
		$this->assertSame( 1, $calls, 'HTTP 429 must not trigger another request.' );
	}

	/**
	 * Bridge and gateway bodies for HTTP 429 responses.
	 *
	 * @return array
	 */
	public static function bridge_429_responses() {
		return array(
			'monthly quota' => array( '{"error":"quota_exceeded"}', 'bridge_quota_exceeded', 0, 'Monthly usage limit reached. Please try again later.' ),
			'daily IP cap'  => array( '{"error":"rate_limited"}', 'bridge_rate_limited', false, 'Too many documentation requests right now. Please try again later.' ),
			'upstream cap'  => array( '{"error":"Rate limit exceeded"}', 'bridge_rate_limited', false, 'Too many documentation requests right now. Please try again later.' ),
			'empty body'    => array( '', 'bridge_rate_limited', false, 'Too many documentation requests right now. Please try again later.' ),
		);
	}

	/**
	 * Gateway timeouts must not repeat the exhausted request deadline.
	 */
	public function test_request_does_not_retry_504() {
		$this->set_current_user_as_admin();
		$this->set_assistant_consent( true );
		$bridge = MainWP_Assistant_Bridge::instance();
		$registrations = 0;
		$chat_calls = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$registrations, &$chat_calls ) {
				if ( false !== strpos( $url, '/assistant/register' ) ) {
					++$registrations;
					return array(
						'headers' => array(),
						'body' => wp_json_encode( array( 'uuid' => 'test-uuid', 'token' => 'test-token' ) ),
						'response' => array( 'code' => 200 ),
					);
				}
				$this->assertStringEndsWith( '/assistant/chat', $url );
				++$chat_calls;
				return array(
					'headers' => array(),
					'body' => wp_json_encode( array( 'error' => 'Invalid response' ) ),
					'response' => array( 'code' => 504, 'message' => 'Gateway Timeout' ),
				);
			},
			10,
			3
		);
		$result = $bridge->request( 'docs please' );
		$this->assertWPErrorWithCode( $result, 'bridge_error' );
		$this->assertSame( 1, $registrations );
		$this->assertSame( 1, $chat_calls, 'A gateway timeout must not trigger another chat request.' );
	}

	/**
	 * Ask transport failures show recoverable copy without HTTP internals.
	 */
	public function test_chat_transport_errors_are_recoverable() {
		$this->set_current_user_as_admin();
		$this->set_assistant_consent( true );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', 'test-token' );
		$calls = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls ) {
				++$calls;
				$this->assertStringEndsWith( '/assistant/chat', $url );
				return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 70000 milliseconds with 0 bytes received', array( 'private_detail' => 'transport-detail' ) );
			},
			10,
			3
		);

		$result = MainWP_Assistant_Bridge::instance()->request( 'docs please' );
		$this->assertWPErrorWithCode( $result, 'bridge_request_failed' );
		$this->assertSame( 'The documentation service did not respond. Please try again.', $result->get_error_message() );
		$this->assertNull( $result->get_error_data(), 'Transport details must not reach the chat error payload.' );
		$this->assertSame( 1, $calls, 'A transport timeout must not automatically repeat the request.' );
	}

	/**
	 * Ask also handles transport timeouts during lazy registration.
	 */
	public function test_ask_registration_timeout_is_recoverable() {
		$this->set_current_user_as_admin();
		$this->set_assistant_consent( true );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', false );
		$calls = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls ) {
				++$calls;
				$this->assertStringEndsWith( '/assistant/register', $url );
				return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			},
			10,
			3
		);

		$result = MainWP_Assistant_Bridge::instance()->request( 'docs please' );
		$this->assertWPErrorWithCode( $result, 'bridge_request_failed' );
		$this->assertSame( 'The documentation service did not respond. Please try again.', $result->get_error_message() );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Keep the client deadline outside the gateway's 60-second deadline.
	 */
	public function test_chat_timeout_outlasts_gateway_deadline() {
		$this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT, true );
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT_VERSION, MainWP_Assistant_Policies::CURRENT_CONSENT_VERSION );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', 'test-token' );
		$calls = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls ) {
				++$calls;
				$this->assertStringEndsWith( '/assistant/chat', $url );
				$this->assertSame( 70, $args['timeout'] );
				$this->assertTrue( $args['sslverify'] );
				$this->assertSame( 0, $args['redirection'] );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'success' => true, 'message' => 'Documentation answer.', 'sources' => array(), 'truncated' => false ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = MainWP_Assistant_Bridge::instance()->request( 'docs please' );
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Test request fails before HTTP when the request body cannot be JSON encoded.
	 */
	public function test_request_returns_error_when_json_encoding_fails() {
		$this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT, true );
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT_VERSION, MainWP_Assistant_Policies::CURRENT_CONSENT_VERSION );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', 'test-token' );

		$bridge         = MainWP_Assistant_Bridge::instance();
		$calls          = 0;
		$recursive_data = array();
		$recursive_data['self'] = &$recursive_data;

		add_filter(
			'pre_http_request',
			function ( $preempt ) use ( &$calls ) {
				++$calls;
				return $preempt;
			}
		);

		$result = $bridge->request( 'hello world', $recursive_data );

		$this->assertWPErrorWithCode( $result, 'bridge_json_encode_failed' );
		$this->assertSame( 0, $calls, 'Bridge request should bail out before any HTTP call when JSON encoding fails.' );
	}

	/**
	 * Test registration fails cleanly when the register response body is malformed JSON.
	 */
	public function test_request_returns_error_when_registration_body_is_malformed() {
		$this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT, true );
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT_VERSION, MainWP_Assistant_Policies::CURRENT_CONSENT_VERSION );

		$bridge = MainWP_Assistant_Bridge::instance();

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				if ( false !== strpos( $url, '/assistant/register' ) ) {
					return array(
						'headers'  => array(),
						'body'     => 'not-json',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return $preempt;
			},
			10,
			3
		);

		$result = $bridge->request( 'docs please' );

		$this->assertWPErrorWithCode( $result, 'bridge_registration_failed' );
	}

	/**
	 * Test the bridge URL validator requires HTTPS for non-loopback hosts.
	 */
	public function test_is_allowed_bridge_url_requires_https_except_loopback() {
		$bridge = MainWP_Assistant_Bridge::instance();

		$this->assertTrue( $bridge->is_allowed_bridge_url( 'https://ai-bridge.fly.dev' ) );
		$this->assertTrue( $bridge->is_allowed_bridge_url( 'https://example.com:8443/bridge' ) );
		$this->assertTrue( $bridge->is_allowed_bridge_url( 'http://localhost:3000' ) );
		$this->assertTrue( $bridge->is_allowed_bridge_url( 'http://127.0.0.1:3000' ) );

		$this->assertFalse( $bridge->is_allowed_bridge_url( 'http://ai-bridge.fly.dev' ) );
		$this->assertFalse( $bridge->is_allowed_bridge_url( 'http://example.com/bridge' ) );
		$this->assertFalse( $bridge->is_allowed_bridge_url( 'ftp://example.com' ) );
		$this->assertFalse( $bridge->is_allowed_bridge_url( '' ) );
		$this->assertFalse( $bridge->is_allowed_bridge_url( 'not a url' ) );
	}

	/**
	 * Test registration refuses a plain-http bridge URL before any HTTP call.
	 */
	public function test_register_rejects_plain_http_bridge_url_without_http_call() {
		$this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT, true );
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT_VERSION, MainWP_Assistant_Policies::CURRENT_CONSENT_VERSION );

		$bridge = MainWP_Assistant_Bridge::instance();
		$calls  = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt ) use ( &$calls ) {
				++$calls;
				return $preempt;
			}
		);

		$result = $bridge->register( 'http://bridge.example.com' );

		$this->assertWPErrorWithCode( $result, 'bridge_insecure_url' );
		$this->assertSame( 0, $calls, 'Registration must not send the site URL over plain HTTP.' );
	}

	/**
	 * Test every bridge HTTP request refuses to follow redirects.
	 */
	public function test_bridge_requests_disable_redirects() {
		$this->set_current_user_as_admin();
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT, true );
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT_VERSION, MainWP_Assistant_Policies::CURRENT_CONSENT_VERSION );

		$bridge       = MainWP_Assistant_Bridge::instance();
		$redirections = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$redirections ) {
				$redirections[ $url ] = $parsed_args['redirection'];

				if ( false !== strpos( $url, '/assistant/register' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'uuid'  => 'test-uuid',
								'token' => 'test-token',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'reply' => 'ok' ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$bridge->request( 'docs please' );

		$this->assertNotEmpty( $redirections, 'Bridge request should have issued HTTP calls.' );
		$this->assertCount( 2, $redirections, 'Expected a registration call and a chat call.' );
		foreach ( $redirections as $url => $redirection ) {
			$this->assertSame( 0, $redirection, 'Bridge request to ' . $url . ' must not follow redirects.' );
		}
	}

	/**
	 * Support uses one authenticated request and maps all non-success responses.
	 */
	public function test_escalate_request_and_response_mapping() {
		$this->set_current_user_as_admin();
		$this->set_assistant_consent( true );
		update_option( 'mainwp_assistant_enabled', 1 );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', 'support-test-token' );
		$transcript = array( array( 'role' => 'user', 'text' => 'My question' ) );
		$context    = array( 'dashboard_version' => '6.0', 'wp_version' => '6.9', 'php_version' => PHP_VERSION );
		$response   = array( 'headers' => array(), 'body' => '{"success":true}', 'response' => array( 'code' => 200 ) );
		$calls      = 0;
		$ids        = array();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$response, &$calls, &$ids, $transcript, $context ) {
			++$calls;
			$this->assertSame( 'https://ai-bridge.fly.dev/assistant/escalate', $url );
			$this->assertSame( 20, $args['timeout'] );
			$this->assertSame( 0, $args['redirection'] );
			$this->assertTrue( $args['sslverify'] );
			$this->assertSame( 'Bearer support-test-token', $args['headers']['Authorization'] );
			$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
			$id = $args['headers']['X-Chat-Message-Id'];
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id );
			$this->assertNotContains( $id, $ids );
			$ids[] = $id;
			$this->assertSame( array( 'email' => 'customer@example.org', 'note' => 'Help', 'transcript' => $transcript, 'context' => $context ), json_decode( $args['body'], true ) );
			return $response;
		}, 10, 3 );
		$bridge = MainWP_Assistant_Bridge::instance();
		$this->assertTrue( $bridge->escalate( 'customer@example.org', 'Help', $transcript, $context ) );
		foreach ( array( 503, 502, 400, 401, 302 ) as $status ) {
			$response['response']['code'] = $status;
			$this->assertWPErrorWithCode( $bridge->escalate( 'customer@example.org', 'Help', $transcript, $context ), 503 === $status ? 'support_not_configured' : 'support_request_failed' );
		}
		foreach ( array( '{}', '{"success":"true"}', '{"success":false}', 'not-json' ) as $body ) {
			$response['response']['code'] = 200;
			$response['body'] = $body;
			$this->assertWPErrorWithCode( $bridge->escalate( 'customer@example.org', 'Help', $transcript, $context ), 'support_request_failed' );
		}
		$response = new \WP_Error( 'http_request_failed', 'timeout' );
		$this->assertWPErrorWithCode( $bridge->escalate( 'customer@example.org', 'Help', $transcript, $context ), 'support_request_failed' );
		$this->assertSame( 11, $calls, 'Failures must not register or retry.' );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', false );
		$this->assertWPErrorWithCode( $bridge->escalate( 'customer@example.org', 'Help', $transcript, $context ), 'support_request_failed' );
		$this->assertSame( 11, $calls, 'A missing token must not register.' );
	}

}
