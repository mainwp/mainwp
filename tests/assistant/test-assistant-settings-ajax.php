<?php
/**
 * Tests for MainWP Assistant Settings AJAX handlers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_Onboarding;
use MainWP\Dashboard\MainWP_Assistant_Policies;
use MainWP\Dashboard\MainWP_Assistant_Insights;
use MainWP\Dashboard\MainWP_Assistant_Settings;
use MainWP\Dashboard\MainWP_DB_Assistant;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_System;

/**
 * Class Test_Assistant_Settings_Ajax
 *
 * Tests for the assistant settings page AJAX handler functionality.
 * These tests verify the underlying methods used by AJAX handlers without
 * requiring full AJAX simulation (which requires additional WordPress test setup).
 *
 * AJAX Handler Tests
 *
 * These tests verify the fix for the infinite spinner bug where AJAX handlers
 * weren't registered when the assistant was disabled. The fix ensures:
 *
 * 1. AJAX handlers are ALWAYS registered (prevents -1/0 responses)
 * 2. Feature-level checks return proper errors when assistant is disabled
 * 3. Encryption system availability is verified before storing API keys
 * 4. Page access control shows appropriate messages when disabled
 *
 * @see .mwpdev/plans/assistant-intergration/fix-spinning-provider-save.md
 */
class Test_Assistant_Settings_Ajax extends MainWP_Assistant_Test_Case {

	/**
	 * Last AJAX response captured during simulation.
	 *
	 * @var string
	 */
	protected $ajax_response = '';

	/**
	 * Saved error reporting level for AJAX tests.
	 *
	 * @var int
	 */
	protected $ajax_error_level = 0;

	/**
	 * Skip when a capability-bypass constant has leaked into the process.
	 *
	 * mainwp_current_user_have_right() returns true unconditionally while
	 * MAINWP_REST_API_DOING, DOING_CRON, or WP_CLI is defined, and the REST
	 * integration tests in tests/abilities define MAINWP_REST_API_DOING during
	 * authentication. Constants cannot be undefined, so a capability-denial
	 * assertion proves nothing once one of them is set. phpunit.xml.dist runs
	 * the Assistant suite before Abilities for this reason; the skip makes an
	 * ordering regression visible instead of failing three tests confusingly.
	 *
	 * @return void
	 */
	protected function skip_if_capability_checks_bypassed(): void {
		foreach ( array( 'MAINWP_REST_API_DOING', 'DOING_CRON', 'WP_CLI' ) as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				$this->markTestSkipped( $constant . ' is defined, so mainwp_current_user_have_right() bypasses capability checks; denial cannot be tested in this process. Run the Assistant suite before the suite that defines it.' );
			}
		}
	}

	// =========================================================================
	// AJAX SIMULATION HELPERS
	// These helpers implement WordPress-style AJAX testing patterns.
	// =========================================================================

	/**
	 * Set up AJAX testing environment.
	 *
	 * Filters wp_die to throw an exception instead of dying, allowing
	 * us to capture AJAX responses. Based on WP_Ajax_UnitTestCase pattern.
	 *
	 * @return void
	 */
	protected function set_up_ajax_testing(): void {
		$this->ajax_response = '';

		// Filter wp_die to use our handler for AJAX requests.
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		add_filter( 'wp_doing_ajax', '__return_true' );

		// Suppress header warnings.
		$this->ajax_error_level = error_reporting();
		error_reporting( $this->ajax_error_level & ~E_WARNING );
	}

	/**
	 * Tear down AJAX testing environment.
	 *
	 * @return void
	 */
	protected function tear_down_ajax_testing(): void {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		error_reporting( $this->ajax_error_level );

		// Clean superglobals.
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * Get our custom wp_die handler for AJAX testing.
	 *
	 * @return callable
	 */
	public function get_ajax_die_handler(): callable {
		return array( $this, 'ajax_die_handler' );
	}

	/**
	 * Handle wp_die() during AJAX testing.
	 *
	 * Captures output and throws exception to stop execution without dying.
	 *
	 * @param string $message The die message.
	 *
	 * @throws \WPAjaxDieContinueException To stop execution.
	 */
	public function ajax_die_handler( $message ): void {
		$this->ajax_response .= ob_get_clean();

		// Throw exception to stop execution without actually dying.
		throw new \WPAjaxDieContinueException( $message );
	}

	/**
	 * Simulate an AJAX request to an assistant settings handler.
	 *
	 * Sets up $_POST, captures output, and returns parsed JSON response.
	 *
	 * @param string $action    The AJAX action (e.g., 'mainwp_assistant_save_provider').
	 * @param array  $post_data POST data to send with the request.
	 *
	 * @return array Parsed JSON response with 'success' and 'data' keys.
	 */
	protected function simulate_ajax_request( string $action, array $post_data = array() ): array {
		$this->set_up_ajax_testing();

		// Set up the request.
		$_POST            = array_merge(
			array(
				'action'   => $action,
				'security' => wp_create_nonce( 'mainwp_assistant_settings' ),
			),
			$post_data
		);
		$_GET['action']   = $action;
		$_REQUEST         = array_merge( $_POST, $_GET );

		// Start output buffering.
		$ob_level = ob_get_level();
		ob_start();

		try {
			// Trigger the AJAX action via do_action (how WordPress actually dispatches AJAX).
			do_action( 'wp_ajax_' . $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json calls wp_die which we intercept.
		} catch ( \WPAjaxDieStopException $e ) {
			// Also expected for error cases.
		} catch ( \Exception $e ) {
			// Unexpected exception - capture any remaining output if our buffer is still open.
			if ( ob_get_level() > $ob_level ) {
				$this->ajax_response .= ob_get_clean();
			}
		}

		// Capture any remaining buffered output, only if our buffer is still open.
		if ( ob_get_level() > $ob_level ) {
			$remaining = ob_get_clean();
			if ( $remaining ) {
				$this->ajax_response .= $remaining;
			}
		}

		$this->tear_down_ajax_testing();

		return $this->parse_ajax_response( $this->ajax_response );
	}

	/**
	 * Simulate MainWP post-handler AJAX requests that use action-specific nonces.
	 *
	 * WARNING: The underlying handler calls secure_request(), which uses raw
	 * die() for non-admin and insecure requests. Only use this helper when the
	 * current user is an administrator. Permission tests for non-admin users
	 * should exercise the policy layer directly.
	 *
	 * @param string $action    AJAX action.
	 * @param array  $post_data POST payload.
	 *
	 * @return array Parsed JSON response.
	 */
	protected function simulate_post_handler_ajax_request( string $action, array $post_data = array() ): array {
		$this->set_up_ajax_testing();

		$_POST          = array_merge(
			array(
				'action'   => $action,
				'security' => wp_create_nonce( $action ),
			),
			$post_data
		);
		$_GET['action'] = $action;
		$_REQUEST       = array_merge( $_POST, $_GET );

		$ob_level = ob_get_level();
		ob_start();

		try {
			MainWP_Post_Handler::instance()->ajax_mainwp_help_modal_content_update();
		} catch ( \WPAjaxDieContinueException $e ) {
			// Expected.
		} catch ( \WPAjaxDieStopException $e ) {
			// Expected.
		} catch ( \Exception $e ) {
			if ( ob_get_level() > $ob_level ) {
				$this->ajax_response .= ob_get_clean();
			}
		}

		if ( ob_get_level() > $ob_level ) {
			$remaining = ob_get_clean();
			if ( $remaining ) {
				$this->ajax_response .= $remaining;
			}
		}

		$this->tear_down_ajax_testing();

		return $this->parse_ajax_response( $this->ajax_response );
	}

	/**
	 * Parse AJAX JSON response.
	 *
	 * Handles WordPress JSON responses which may have security prefixes.
	 *
	 * @param string $output Raw output from AJAX handler.
	 *
	 * @return array Parsed response with 'success' and 'data' keys, or error array.
	 */
	protected function parse_ajax_response( string $output ): array {
		$output = trim( $output );

		// WordPress sometimes prepends security tokens - strip them.
		if ( strpos( $output, ')]}' ) === 0 ) {
			$output = substr( $output, 3 );
		}

		// Handle empty response (AJAX handler not registered - returns 0 or -1).
		if ( '' === $output || '0' === $output || '-1' === $output ) {
			return array(
				'success' => false,
				'data'    => array( 'message' => 'AJAX handler not registered or returned ' . $output ),
				'raw'     => $output,
			);
		}

		$decoded = json_decode( $output, true );

		if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'data'    => array( 'message' => 'Invalid JSON: ' . json_last_error_msg() ),
				'raw'     => $output,
			);
		}

		return $decoded;
	}

	// =========================================================================
	// PROVIDER SETTINGS TESTS
	// =========================================================================

	// =========================================================================
	// ACCESS MODE SETTINGS TESTS
	// =========================================================================

	/**
	 * Test save access mode.
	 */
	public function test_save_access_mode() {
		$user_id = $this->set_current_user_as_admin();

		// Set to full access.
		$result = MainWP_Assistant_Policies::instance()->set_access_mode(
			MainWP_Assistant_Policies::MODE_FULL_ACCESS,
			$user_id
		);
		$this->assertNotWPError( $result );

		// Verify.
		$mode = MainWP_Assistant_Policies::instance()->get_access_mode( $user_id );
		$this->assertEquals( MainWP_Assistant_Policies::MODE_FULL_ACCESS, $mode );
	}

	/**
	 * Test save access mode with invalid mode fails.
	 */
	public function test_save_access_mode_invalid_fails() {
		$user_id = $this->set_current_user_as_admin();

		$result = MainWP_Assistant_Policies::instance()->set_access_mode( 'invalid-mode', $user_id );
		$this->assertWPErrorWithCode( $result, 'mainwp_assistant_invalid_mode' );
	}

	/**
	 * Test access mode defaults to read-only.
	 */
	public function test_access_mode_defaults_to_read_only() {
		// Create a new user without any settings.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$mode = MainWP_Assistant_Policies::instance()->get_access_mode( $user_id );
		$this->assertEquals( MainWP_Assistant_Policies::MODE_READ_ONLY, $mode );
	}

	// =========================================================================
	// PROTECTED TAGS SETTINGS TESTS
	// =========================================================================

	/**
	 * Test save protected tags.
	 */
	public function test_save_protected_tags() {
		$user_id = $this->set_current_user_as_admin();

		// Create test tags using array format.
		$tag1_id = $this->create_test_tag( array( 'name' => 'Production' ) );
		$tag2_id = $this->create_test_tag( array( 'name' => 'Staging' ) );

		// Save protected tags.
		$result = MainWP_Assistant_Policies::instance()->set_protected_tags(
			array( $tag1_id, $tag2_id ),
			$user_id
		);
		$this->assertTrue( $result );

		// Verify.
		$protected = MainWP_Assistant_Policies::instance()->get_protected_tags( $user_id );
		$this->assertCount( 2, $protected );
		$this->assertContains( $tag1_id, $protected );
		$this->assertContains( $tag2_id, $protected );
	}

	/**
	 * Test clear protected tags.
	 */
	public function test_clear_protected_tags() {
		$user_id = $this->set_current_user_as_admin();

		// Set some tags using array format.
		$tag_id = $this->create_test_tag( array( 'name' => 'Test Tag' ) );
		MainWP_Assistant_Policies::instance()->set_protected_tags( array( $tag_id ), $user_id );

		// Clear them.
		$result = MainWP_Assistant_Policies::instance()->set_protected_tags( array(), $user_id );
		$this->assertTrue( $result );

		// Verify empty.
		$protected = MainWP_Assistant_Policies::instance()->get_protected_tags( $user_id );
		$this->assertEmpty( $protected );
	}

	// =========================================================================
	// CONFIRMATION LEVEL SETTINGS TESTS
	// =========================================================================

	/**
	 * Test save confirmation level via Policies class.
	 */
	public function test_save_confirmation_level() {
		$user_id = $this->set_current_user_as_admin();

		$result = MainWP_Assistant_Policies::instance()->set_confirmation_level(
			MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE,
			$user_id
		);
		$this->assertNotWPError( $result );

		$level = MainWP_Assistant_Policies::instance()->get_confirmation_level( $user_id );
		$this->assertEquals( MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE, $level );
	}

	/**
	 * Test save confirmation level with invalid value fails.
	 */
	public function test_save_confirmation_level_invalid_fails() {
		$user_id = $this->set_current_user_as_admin();

		$result = MainWP_Assistant_Policies::instance()->set_confirmation_level( 'invalid', $user_id );
		$this->assertWPErrorWithCode( $result, 'mainwp_assistant_invalid_confirmation_level' );
	}

	// =========================================================================
	// GLOBAL TOGGLE SETTINGS TESTS
	// =========================================================================

	/**
	 * Test save global toggle - enable.
	 */
	public function test_save_global_toggle_enable() {
		$this->set_current_user_as_admin();

		$this->set_assistant_enabled( true );
		$this->assertTrue( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );
	}

	/**
	 * Test save global toggle - disable.
	 */
	public function test_save_global_toggle_disable() {
		$this->set_current_user_as_admin();

		$this->set_assistant_enabled( false );
		$this->assertFalse( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );
	}

	// =========================================================================
	// HISTORY SETTINGS TESTS
	// =========================================================================

	/**
	 * Test clear history removes all conversations.
	 */
	public function test_clear_history_removes_conversations() {
		$user_id = $this->set_current_user_as_admin();

		// Create some conversations.
		$conv1 = $this->create_test_conversation( $user_id, 'Conversation 1' );
		$conv2 = $this->create_test_conversation( $user_id, 'Conversation 2' );
		$this->add_test_message( $conv1, 'user', 'Message 1' );
		$this->add_test_message( $conv2, 'user', 'Message 2' );

		// Verify they exist.
		$count_before = MainWP_DB_Assistant::instance()->get_conversation_count( $user_id );
		$this->assertGreaterThanOrEqual( 2, $count_before );

		// Clear history - returns count of deleted rows.
		$deleted = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_id );
		$this->assertGreaterThanOrEqual( 2, $deleted );

		// Verify cleared.
		$count_after = MainWP_DB_Assistant::instance()->get_conversation_count( $user_id );
		$this->assertEquals( 0, $count_after );
	}

	/**
	 * Test clear history only affects current user.
	 */
	public function test_clear_history_only_affects_current_user() {
		$user_a = $this->set_current_user_as_admin();
		$user_b = $this->create_other_admin();

		// Create conversations for both users.
		$conv_a = $this->create_test_conversation( $user_a, 'User A Conversation' );
		$this->add_test_message( $conv_a, 'user', 'User A Message' );

		// Switch to user B and create conversation.
		wp_set_current_user( $user_b );
		$conv_b = $this->create_test_conversation( $user_b, 'User B Conversation' );
		$this->add_test_message( $conv_b, 'user', 'User B Message' );

		// Clear user B's history.
		MainWP_DB_Assistant::instance()->delete_user_conversations( $user_b );

		// Verify user A still has their conversation.
		$count_a = MainWP_DB_Assistant::instance()->get_conversation_count( $user_a );
		$this->assertEquals( 1, $count_a );

		// Verify user B has no conversations.
		$count_b = MainWP_DB_Assistant::instance()->get_conversation_count( $user_b );
		$this->assertEquals( 0, $count_b );
	}

	/**
	 * Test history settings can still be saved while assistant is disabled.
	 */
	public function test_history_settings_can_be_saved_when_assistant_is_disabled() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		MainWP_Assistant_Settings::init();

		$response = $this->simulate_ajax_request(
			'mainwp_assistant_save_history_settings',
			array(
				'retention_days'  => 45,
				'history_opt_out' => 'false',
			)
		);

		$this->assertTrue( $response['success'] ?? false );
		$this->assertEquals( 45, (int) get_user_meta( $user_id, 'mainwp_assistant_history_retention', true ) );
	}

	/**
	 * Test out-of-range retention days are clamped and the response echoes the stored value.
	 *
	 * The JS relies on this echo to re-sync the input, so the UI never
	 * reports "saved" for a value the server rejected.
	 */
	public function test_history_retention_clamped_value_echoed_in_response() {
		$user_id = $this->set_current_user_as_admin();

		MainWP_Assistant_Settings::init();

		$response = $this->simulate_ajax_request(
			'mainwp_assistant_save_history_settings',
			array(
				'retention_days'  => 500,
				'history_opt_out' => 'false',
			)
		);

		$this->assertTrue( $response['success'] ?? false );
		$this->assertEquals( 365, (int) ( $response['data']['retention_days'] ?? 0 ) );
		$this->assertEquals( 365, (int) get_user_meta( $user_id, 'mainwp_assistant_history_retention', true ) );
	}

	/**
	 * Test history can be cleared while assistant is disabled.
	 */
	public function test_clear_history_works_when_assistant_is_disabled() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Disabled Clear History' );
		$this->add_test_message( $conversation_id, 'user', 'Hello', $user_id );
		$this->set_assistant_enabled( false );

		MainWP_Assistant_Settings::init();

		$response = $this->simulate_ajax_request( 'mainwp_assistant_clear_history' );

		$this->assertTrue( $response['success'] ?? false );
		$this->assertEquals( 0, MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ) );
	}

	/**
	 * Test history export remains available while assistant is disabled.
	 */
	public function test_export_history_works_when_assistant_is_disabled() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Disabled Export History' );
		$this->add_test_message( $conversation_id, 'user', 'Hello export', $user_id );
		$this->add_test_message( $conversation_id, 'assistant', 'Exported reply', $user_id );
		$this->set_assistant_enabled( false );

		MainWP_Assistant_Settings::init();

		$response = $this->simulate_ajax_request( 'mainwp_assistant_export_history' );

		$this->assertArrayHasKey( 'exported_at', $response );
		$this->assertSame( $user_id, $response['user_id'] ?? 0 );
		$this->assertNotEmpty( $response['conversations'] ?? array() );
		$this->assertEquals( $conversation_id, $response['conversations'][0]['id'] ?? 0 );
		$this->assertTrue( $response['complete'] ?? false );
	}

	/**
	 * Test export marks the payload incomplete when messages fail to load.
	 *
	 * The handler streams the export, so a mid-stream failure still ends as
	 * HTTP 200 with well-formed JSON; the completeness flag is the client's
	 * only signal that conversations were dropped.
	 */
	public function test_export_history_reports_incomplete_on_message_failure() {
		$user_id = $this->set_current_user_as_admin();
		$ok_id   = $this->create_test_conversation( $user_id, 'Exports Fine' );
		$this->add_test_message( $ok_id, 'user', 'Hello', $user_id );
		$bad_id = $this->create_test_conversation( $user_id, 'Messages Break' );
		$this->add_test_message( $bad_id, 'user', 'Doomed', $user_id );

		MainWP_Assistant_Settings::init();

		$original = MainWP_DB_Assistant::instance();

		$failing = new class() extends MainWP_DB_Assistant {
			/**
			 * Conversation ID whose message loads fail.
			 *
			 * @var int
			 */
			public $fail_conversation_id = 0;

			/**
			 * Simulate a message-load failure for one conversation.
			 *
			 * @param int      $conversation_id Conversation ID.
			 * @param int|null $user_id         User ID.
			 * @param int      $limit           Limit.
			 * @param int      $offset          Offset.
			 * @param string   $order           Sort order.
			 *
			 * @return array|\WP_Error Messages or simulated failure.
			 */
			public function get_messages( $conversation_id, $user_id = null, $limit = 50, $offset = 0, $order = 'ASC' ) {
				if ( (int) $conversation_id === (int) $this->fail_conversation_id ) {
					return new \WP_Error( 'db_error', 'Simulated message load failure.' );
				}
				return parent::get_messages( $conversation_id, $user_id, $limit, $offset, $order );
			}
		};

		$failing->fail_conversation_id = $bad_id;

		$prop = new \ReflectionProperty( MainWP_DB_Assistant::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, $failing );

		try {
			$response = $this->simulate_ajax_request( 'mainwp_assistant_export_history' );
		} finally {
			$prop->setValue( null, $original );
		}

		$this->assertFalse( $response['complete'] ?? true );
		$this->assertIsArray( $response['conversations'] ?? null );
		$this->assertArrayHasKey( 'exported_at', $response );
	}

	// =========================================================================
	// CHAT-ONLY USER (NO SETTINGS PERMISSION) TESTS
	// =========================================================================

	/**
	 * Test a chat-only user can save their own history settings.
	 */
	public function test_chat_only_user_can_save_history_settings() {
		$user_id = $this->set_current_user_as_chat_only();

		MainWP_Assistant_Settings::init();

		$response = $this->simulate_ajax_request(
			'mainwp_assistant_save_history_settings',
			array(
				'retention_days'  => 60,
				'history_opt_out' => 'false',
			)
		);

		$this->assertTrue( $response['success'] ?? false );
		$this->assertEquals( 60, (int) get_user_meta( $user_id, 'mainwp_assistant_history_retention', true ) );
	}

	/**
	 * Test a chat-only user can clear their own history.
	 */
	public function test_chat_only_user_can_clear_own_history() {
		$user_id         = $this->set_current_user_as_chat_only();
		$conversation_id = $this->create_test_conversation( $user_id, 'Chat Only Clear' );
		$this->add_test_message( $conversation_id, 'user', 'Hello', $user_id );

		MainWP_Assistant_Settings::init();

		$response = $this->simulate_ajax_request( 'mainwp_assistant_clear_history' );

		$this->assertTrue( $response['success'] ?? false );
		$this->assertEquals( 0, MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ) );
	}

	/**
	 * Test a chat-only user can export their own history.
	 */
	public function test_chat_only_user_can_export_own_history() {
		$user_id         = $this->set_current_user_as_chat_only();
		$conversation_id = $this->create_test_conversation( $user_id, 'Chat Only Export' );
		$this->add_test_message( $conversation_id, 'user', 'Hello export', $user_id );

		MainWP_Assistant_Settings::init();

		$response = $this->simulate_ajax_request( 'mainwp_assistant_export_history' );

		$this->assertArrayHasKey( 'exported_at', $response );
		$this->assertSame( $user_id, $response['user_id'] ?? 0 );
		$this->assertNotEmpty( $response['conversations'] ?? array() );
		$this->assertTrue( $response['complete'] ?? false );
	}

	/**
	 * Test a chat-only user is denied on every settings-level AJAX handler.
	 *
	 * The history handlers were opened up to access_global_dashboard; the
	 * global assistant settings must stay behind manage_dashboard_settings.
	 * Each request carries the nonce key its handler expects and asserts the
	 * handler's own denial message, so a nonce failure cannot masquerade as
	 * a capability denial.
	 */
	public function test_chat_only_user_denied_settings_handlers() {
		$this->skip_if_capability_checks_bypassed();
		$this->set_current_user_as_chat_only();
		$this->set_assistant_enabled( true );

		MainWP_Assistant_Settings::init();

		// action => array( post payload, expected denial message substring ).
		$denied_actions = array(
			'mainwp_assistant_save_model_preference'   => array(
				array(
					'model_preference' => '',
					// This handler reads the nonce from 'nonce', not 'security'.
					'nonce'            => wp_create_nonce( 'mainwp_assistant_settings' ),
				),
				'Permission denied.',
			),
			'mainwp_assistant_save_access_mode'        => array(
				array( 'mode' => 'read_only' ),
				'Permission denied.',
			),
			'mainwp_assistant_save_confirmation_level' => array(
				array( 'level' => 'destructive' ),
				'Permission denied.',
			),
			'mainwp_assistant_save_protected_tags'     => array(
				array( 'tag_ids' => array() ),
				'Permission denied.',
			),
			'mainwp_assistant_save_global_toggle'      => array(
				array( 'enabled' => 'false' ),
				'Only administrators',
			),
		);

		foreach ( $denied_actions as $action => $case ) {
			list( $post_data, $expected_message ) = $case;

			$response = $this->simulate_ajax_request( $action, $post_data );

			$this->assertFalse(
				$response['success'] ?? true,
				$action . ' must be denied for a user without manage_dashboard_settings.'
			);
			$this->assertStringContainsString(
				$expected_message,
				$response['data']['message'] ?? '',
				$action . ' must be denied by its capability check, not a nonce or validation failure.'
			);
		}
	}

	/**
	 * Test render() limits a chat-only user to the History & Privacy tab.
	 *
	 * A requested tab other than history must be ignored, and the settings
	 * tabs must not appear in the navigation.
	 */
	public function test_render_history_only_for_chat_only_user() {
		$this->skip_if_capability_checks_bypassed();
		$this->set_current_user_as_chat_only();
		$this->set_assistant_enabled( true );
		$_GET['tab'] = 'providers';

		ob_start();
		MainWP_Assistant_Settings::render();
		$output = ob_get_clean();

		unset( $_GET['tab'] );

		$output = html_entity_decode( $output, ENT_QUOTES );

		$this->assertStringContainsString(
			'History & Privacy',
			$output,
			'Chat-only users should land on the History & Privacy tab even when requesting another tab'
		);
		$this->assertStringNotContainsString(
			'AI Connection',
			$output,
			'Chat-only users should not see the AI Connection tab'
		);
		$this->assertStringNotContainsString(
			'AI Access Policies',
			$output,
			'Chat-only users should not see the AI Access Policies tab'
		);
	}

	/**
	 * Test render() still denies users without any assistant capability.
	 */
	public function test_render_denies_user_without_any_assistant_capability() {
		$this->skip_if_capability_checks_bypassed();
		$this->set_current_user_as_subscriber();

		add_filter(
			'mainwp_currentusercan',
			function ( $result, $cap_type, $cap ) {
				if ( 'dashboard' === $cap_type && in_array( $cap, array( 'access_global_dashboard', 'manage_dashboard_settings' ), true ) ) {
					return false;
				}
				return $result;
			},
			10,
			3
		);

		ob_start();
		MainWP_Assistant_Settings::render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'permission',
			strtolower( $output ),
			'Users with neither capability should hit the permission error'
		);
		$this->assertStringNotContainsString(
			'History & Privacy',
			html_entity_decode( $output, ENT_QUOTES ),
			'Users with neither capability should not reach the History tab'
		);
	}

	/**
	 * Test set_assistant_enabled requires manage_options.
	 *
	 * Note: The actual class method checks capability before updating.
	 */
	public function test_set_assistant_enabled_requires_capability() {
		$this->set_current_user_as_subscriber();

		// Using the class method should fail for non-admin.
		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( false );

		$this->assertWPErrorWithCode( $result, 'mainwp_permission_denied' );
	}

	/**
	 * Test help modal updates return JSON success for authorized assistant changes.
	 */
	public function test_help_modal_content_update_returns_json_success() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		$response = $this->simulate_post_handler_ajax_request(
			'mainwp_help_modal_content_update',
			array(
				'enable_chatbase' => '1',
				'enable_tour'     => '0',
				'enable_video'    => '0',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, (int) $response['data']['assistant_enabled'] );
		$this->assertSame( 1, (int) get_option( 'mainwp_enable_guided_chatbase', 0 ) );
	}

	/**
	 * Test assistant policy denies unauthorized help modal toggle attempts.
	 */
	public function test_help_modal_content_update_denies_unauthorized_toggle() {
		$this->set_current_user_as_subscriber();
		delete_option( 'mainwp_enable_guided_chatbase' );

		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( true );

		$this->assertWPErrorWithCode( $result, 'mainwp_permission_denied' );
		$this->assertSame( 0, (int) get_option( 'mainwp_enable_guided_chatbase', 0 ) );
	}

	// =========================================================================
	// PROVIDER VALIDATION TESTS
	// =========================================================================

	// =========================================================================
	// EXPORT HISTORY TESTS
	// =========================================================================

	/**
	 * Test conversation export includes all messages.
	 */
	public function test_conversation_export_includes_all_messages() {
		$user_id = $this->set_current_user_as_admin();

		$conversation_id = $this->create_test_conversation( $user_id, 'Export Test' );
		$this->add_test_message( $conversation_id, 'user', 'First user message' );
		$this->add_test_message( $conversation_id, 'assistant', 'Assistant response' );
		$this->add_test_message( $conversation_id, 'user', 'Follow up question' );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 100 );

		$this->assertCount( 3, $messages );

		// Verify all roles are present.
		$roles = array_column( (array) $messages, 'role' );
		$this->assertEquals( 2, array_count_values( $roles )['user'] );
		$this->assertEquals( 1, array_count_values( $roles )['assistant'] );
	}

	/**
	 * Test getting user conversations for export.
	 */
	public function test_get_user_conversations_for_export() {
		$user_id = $this->set_current_user_as_admin();

		// Create multiple conversations.
		$this->create_test_conversation( $user_id, 'Conversation 1' );
		$this->create_test_conversation( $user_id, 'Conversation 2' );
		$this->create_test_conversation( $user_id, 'Conversation 3' );

		$conversations = MainWP_DB_Assistant::instance()->get_user_conversations( $user_id, 1, 10 );

		$this->assertIsArray( $conversations );
		$this->assertGreaterThanOrEqual( 3, count( $conversations ) );
	}

	// =========================================================================
	// AJAX HANDLER REGISTRATION TESTS
	// These tests prevent regression of the infinite spinner bug.
	// =========================================================================

	/**
	 * Test that AJAX handlers are registered even when assistant is disabled.
	 *
	 * This is critical for preventing the infinite spinner bug. When AJAX handlers
	 * aren't registered, WordPress returns -1 or 0, causing the JS to spin forever.
	 * The fix ensures handlers are ALWAYS registered, but return proper errors
	 * when the assistant is disabled.
	 */
	public function test_ajax_handlers_registered_when_assistant_disabled() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		// Ensure the settings class is initialized (this registers AJAX handlers).
		MainWP_Assistant_Settings::init();

		// Settings handlers stay registered even when the assistant is disabled
		// so an admin can always re-enable it.
		$this->assertNotFalse( has_action( 'wp_ajax_mainwp_assistant_save_model_preference' ), 'mainwp_assistant_save_model_preference should be registered even when assistant is disabled' );
		$this->assertNotFalse( has_action( 'wp_ajax_mainwp_assistant_save_global_toggle' ), 'mainwp_assistant_save_global_toggle should be registered even when assistant is disabled' );

		// The pre-AI-Client provider CRUD handlers are gone for good.
		$this->assertFalse( has_action( 'wp_ajax_mainwp_assistant_save_provider' ), 'Deleted provider handler must not be registered' );
		$this->assertFalse( has_action( 'wp_ajax_mainwp_assistant_delete_provider' ), 'Deleted provider handler must not be registered' );
		$this->assertFalse( has_action( 'wp_ajax_mainwp_assistant_test_provider' ), 'Deleted provider handler must not be registered' );
		$this->assertFalse( has_action( 'wp_ajax_mainwp_assistant_set_active_provider' ), 'Deleted provider handler must not be registered' );
		$this->assertFalse( has_action( 'wp_ajax_mainwp_assistant_fetch_models' ), 'Deleted provider handler must not be registered' );
	}

	/**
	 * Test that all AJAX handlers are registered when assistant is enabled.
	 */
	public function test_ajax_handlers_registered_when_assistant_enabled() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );

		MainWP_Assistant_Settings::init();

		// Verify all AJAX handlers are registered.
		$handlers = array(
			'wp_ajax_mainwp_assistant_save_model_preference',
			'wp_ajax_mainwp_assistant_save_access_mode',
			'wp_ajax_mainwp_assistant_save_confirmation_level',
			'wp_ajax_mainwp_assistant_save_protected_tags',
			'wp_ajax_mainwp_assistant_save_global_toggle',
			'wp_ajax_mainwp_assistant_save_history_settings',
			'wp_ajax_mainwp_assistant_clear_history',
			'wp_ajax_mainwp_assistant_export_history',
		);

		foreach ( $handlers as $action ) {
			$this->assertNotFalse( has_action( $action ), "{$action} should be registered when assistant is enabled" );
		}
	}

	// =========================================================================
	// FEATURE-LEVEL CHECK TESTS
	// These tests verify the assistant enabled check works correctly.
	// =========================================================================

	/**
	 * Test that is_assistant_enabled returns false when disabled.
	 *
	 * This is the core check used by AJAX handlers. When disabled, handlers
	 * should return errors instead of processing requests, which prevents
	 * unauthorized operations while still returning proper JSON responses.
	 */
	public function test_is_assistant_enabled_returns_false_when_disabled() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		// Verify the check returns false.
		$this->assertFalse(
			MainWP_System::is_assistant_enabled(),
			'is_assistant_enabled should return false when assistant is disabled'
		);

		// Also verify the Policies class method.
		$this->assertFalse(
			MainWP_Assistant_Policies::instance()->is_assistant_enabled(),
			'Policies::is_assistant_enabled should also return false'
		);
	}

	/**
	 * Test that is_assistant_enabled returns true when enabled.
	 */
	public function test_is_assistant_enabled_returns_true_when_enabled() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );

		$this->assertTrue(
			MainWP_System::is_assistant_enabled(),
			'is_assistant_enabled should return true when assistant is enabled'
		);
	}

	/**
	 * Test that the global toggle can be changed via the Policies class.
	 */
	public function test_global_toggle_can_be_changed() {
		$this->set_current_user_as_admin();

		// Start with disabled.
		$this->set_assistant_enabled( false );
		$this->assertFalse( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );

		// Enable via the class method.
		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( true );
		$this->assertNotWPError( $result );
		$this->assertTrue( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );

		// Disable again.
		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( false );
		$this->assertNotWPError( $result );
		$this->assertFalse( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );
	}

	// =========================================================================
	// ENCRYPTION FILTER AVAILABILITY TESTS
	// =========================================================================

	/**
	 * Test that encryption filter availability can be checked.
	 *
	 * The AJAX handlers check for encryption filter availability before storing
	 * API keys. This test verifies the check mechanism works correctly.
	 */
	public function test_encryption_filter_availability_check() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );

		// Ensure encryption filter is NOT available.
		remove_all_filters( 'mainwp_encrypt_key_value' );

		// Verify filter check returns false.
		$this->assertFalse(
			has_filter( 'mainwp_encrypt_key_value' ),
			'Encryption filter should not be available after removal'
		);

		// Now mock the encryption filter.
		$this->mock_encryption_filter();

		// Verify filter check returns truthy value (priority number).
		$this->assertNotFalse(
			has_filter( 'mainwp_encrypt_key_value' ),
			'Encryption filter should be available after mocking'
		);
	}

	// =========================================================================
	// SECURITY & PERMISSION TESTS
	// These verify that proper permission checks are in place.
	// =========================================================================

	/**
	 * Test that global toggle requires admin capability.
	 *
	 * The set_assistant_enabled method should require manage_options capability.
	 */
	public function test_global_toggle_requires_admin_capability() {
		$this->set_current_user_as_subscriber();

		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( true );

		$this->assertWPErrorWithCode( $result, 'mainwp_permission_denied' );
	}

	/**
	 * Test that admin can change global toggle.
	 */
	public function test_admin_can_change_global_toggle() {
		$this->set_current_user_as_admin();

		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( true );

		$this->assertNotWPError( $result );
		$this->assertTrue( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );
	}

	// =========================================================================
	// INTEGRATION TEST FOR BUG SCENARIO
	// =========================================================================

	/**
	 * Test the exact bug scenario from the PRD.
	 *
	 * This integration test verifies the fix for the infinite spinner bug:
	 * 1. Assistant is disabled
	 * 2. AJAX handlers MUST still be registered (prevents -1/0 response)
	 * 3. The is_assistant_enabled check should return false
	 * 4. AJAX handlers (if called) would return proper JSON error
	 *
	 * This test prevents regression of the infinite spinner bug by verifying
	 * the handlers are always registered.
	 */
	public function test_integration_handlers_registered_and_check_works() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		// Ensure AJAX handlers are registered.
		MainWP_Assistant_Settings::init();

		// CRITICAL: Verify key AJAX handlers ARE registered (prevents infinite spinner).
		$critical_handlers = array(
			'wp_ajax_mainwp_assistant_save_model_preference',
			'wp_ajax_mainwp_assistant_save_global_toggle',
			'wp_ajax_mainwp_assistant_save_history_settings',
			'wp_ajax_mainwp_assistant_export_history',
		);

		foreach ( $critical_handlers as $handler ) {
			$this->assertNotFalse(
				has_action( $handler ),
				"{$handler} must be registered even when assistant is disabled"
			);
		}

		// Verify the is_assistant_enabled check returns false.
		$this->assertFalse(
			MainWP_System::is_assistant_enabled(),
			'is_assistant_enabled should return false when disabled'
		);

		// This proves that:
		// 1. Handlers are registered (won't return -1/0)
		// 2. The check returns false (handlers will return proper error JSON)
	}

	/**
	 * Test that global toggle can be changed via Policies class when assistant is disabled.
	 *
	 * The global toggle should always work so admins can re-enable the assistant.
	 * This tests the Policies class directly, not the AJAX handler.
	 */
	public function test_global_toggle_works_when_assistant_disabled() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		// Verify initially disabled.
		$this->assertFalse( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );

		// Enable via the Policies class.
		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( true );
		$this->assertNotWPError( $result );

		// Verify now enabled.
		$this->assertTrue( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );
	}

	// =========================================================================
	// AJAX HANDLER EXECUTION TESTS
	// These tests verify actual AJAX handler responses using WordPress patterns.
	// =========================================================================

	/**
	 * Test that AJAX handler returns JSON error, not -1/0, when disabled.
	 *
	 * This is the critical test for the infinite spinner bug. The bug occurred
	 * because unregistered handlers return -1/0, which the JS couldn't parse.
	 * The fix ensures handlers are always registered and return proper JSON.
	 */
	public function test_ajax_disabled_returns_json_not_wordpress_error_codes() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		// Ensure handlers are registered.
		MainWP_Assistant_Settings::init();

		// The global toggle is the handler an admin needs while the assistant is
		// off; it must stay registered and answer with JSON.
		$response = $this->simulate_ajax_request(
			'mainwp_assistant_save_global_toggle',
			array( 'enabled' => 'false' )
		);

		// parse_ajax_response() adds 'raw' only for an empty, -1, 0 or non-JSON body.
		$this->assertArrayNotHasKey( 'raw', $response, 'The handler must answer with JSON, not an empty, -1 or 0 body.' );
		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'enabled' => false ), $response['data'] );
	}

	// =========================================================================
	// PAGE RENDERING TESTS
	// =========================================================================

	/**
	 * Test that disabled state message is shown when assistant is disabled.
	 *
	 * This test verifies the UI graceful degradation: when the assistant is disabled,
	 * the settings page should show an "Enable Assistant" message rather than the
	 * full provider configuration UI.
	 */
	public function test_page_shows_enable_message_when_assistant_disabled() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		// Capture output from the shared disabled-state renderer.
		ob_start();
		MainWP_Assistant_Policies::render_disabled_state( 'settings' );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'href="https://docs.mainwp.com/dashboard/overview/mainwp-ai"', $output );
		$this->assertStringNotContainsString( 'https://kb.mainwp.com/', $output );

		// Assert the disabled state message contains expected elements.
		$this->assertStringContainsString(
			'AI Assistant Disabled',
			$output,
			'Disabled state should show "AI Assistant Disabled" header'
		);

		$this->assertStringContainsString(
			'Enable Assistant',
			$output,
			'Disabled state should show "Enable Assistant" button for admins'
		);

		$this->assertStringContainsString(
			'mainwp-assistant-enable-btn',
			$output,
			'Enable button should have the expected ID for JavaScript handling'
		);

		// Assert provider UI elements are NOT present.
		$this->assertStringNotContainsString(
			'AI Providers',
			$output,
			'Provider configuration UI should NOT be shown when assistant is disabled'
		);

		$this->assertStringNotContainsString(
			'Add Provider',
			$output,
			'Add Provider button should NOT be shown when assistant is disabled'
		);
	}

	/**
	 * Test that non-admin users see a different message when assistant is disabled.
	 *
	 * Non-admins should see a message to contact their administrator instead of
	 * the enable button, since they lack the capability to enable the assistant.
	 */
	public function test_page_shows_contact_admin_message_for_non_admin_when_disabled() {
		$this->set_current_user_as_subscriber();
		$this->set_assistant_enabled( false );

		// Capture output from the shared disabled-state renderer.
		ob_start();
		MainWP_Assistant_Policies::render_disabled_state( 'settings' );
		$output = ob_get_clean();

		// Assert the disabled state message shows for non-admins.
		$this->assertStringContainsString(
			'AI Assistant Disabled',
			$output,
			'Disabled state should show header for non-admins too'
		);

		// Non-admins should be pointed at their administrator instead of the enable button.
		$this->assertStringContainsString(
			'Ask your administrator to enable it.',
			$output,
			'Non-admins should be told to ask their administrator'
		);

		// Enable button should NOT be present for non-admins.
		$this->assertStringNotContainsString(
			'mainwp-assistant-enable-btn',
			$output,
			'Enable button should NOT be shown to non-admin users'
		);
	}

	/**
	 * Test that the settings page render() method shows disabled state on the default tab.
	 *
	 * Unlike the direct render_disabled_state() tests above, this exercises the full
	 * render() integration path including the tab-based conditional logic.
	 */
	public function test_render_shows_disabled_state_on_default_tab() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );
		$_GET['tab'] = 'providers';

		ob_start();
		MainWP_Assistant_Settings::render();
		$output = ob_get_clean();

		unset( $_GET['tab'] );

		$this->assertStringContainsString(
			'AI Assistant Disabled',
			$output,
			'Full render() path should show disabled state on providers tab when assistant is disabled'
		);
	}

	/**
	 * Test history tab content remains available when assistant is disabled.
	 */
	public function test_history_tab_renders_when_assistant_is_disabled() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		$reflection = new \ReflectionClass( MainWP_Assistant_Settings::class );
		$method     = $reflection->getMethod( 'render_history_tab' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( null );
		$output = ob_get_clean();
		$output = html_entity_decode( $output, ENT_QUOTES );

		$this->assertStringContainsString( 'History & Privacy', $output );
		$this->assertStringContainsString( 'Save Settings', $output );
		$this->assertStringContainsString( 'Export History (JSON)', $output );
		$this->assertStringContainsString( 'href="https://docs.mainwp.com/dashboard/overview/mainwp-ai"', $output );
		$this->assertStringNotContainsString( 'https://kb.mainwp.com/', $output );
		$this->assertStringNotContainsString( 'AI Assistant Disabled', $output );
	}


	/**
	 * Test both Assistant help sidebars link to the current documentation.
	 */
	public function test_help_sidebars_link_to_current_documentation() {
		$original_get = $_GET;
		try {
			foreach ( array( 'AssistantSettings' => MainWP_Assistant_Settings::class, 'InsightsOverview' => MainWP_Assistant_Insights::class ) as $page => $class ) {
				$_GET['page'] = $page;
				ob_start();
				$class::mainwp_help_content();
				$output = ob_get_clean();
				$this->assertStringContainsString( 'href="https://docs.mainwp.com/dashboard/overview/mainwp-ai"', $output, $page );
				$this->assertStringNotContainsString( 'https://mainwp.com/kb/', $output, $page );
			}
		} finally {
			$_GET = $original_get;
		}
	}

	// =========================================================================
	// MODEL PREFERENCE ALLOWLIST
	// =========================================================================

	/**
	 * Save a model preference through the AJAX handler.
	 *
	 * The handler reads its nonce from 'nonce' rather than the 'security' key
	 * simulate_ajax_request() sends by default.
	 *
	 * @param string $preference Model id to submit.
	 *
	 * @return array Parsed response.
	 */
	protected function save_model_preference( string $preference ): array {
		return $this->simulate_ajax_request(
			'mainwp_assistant_save_model_preference',
			array(
				'model_preference' => $preference,
				'nonce'            => wp_create_nonce( 'mainwp_assistant_settings' ),
			)
		);
	}

	/**
	 * Store a model allowlist so the handler does not probe live providers.
	 *
	 * @param array $map Map of model id to provider ids.
	 *
	 * @return void
	 */
	protected function prime_model_allowlist( array $map ): void {
		set_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT, $map, HOUR_IN_SECONDS );
	}

	/**
	 * Ids outside the character set model ids use are still refused.
	 */
	public function test_model_preference_rejects_ids_outside_the_syntax_floor() {
		$this->set_current_user_as_admin();
		MainWP_Assistant_Settings::init();
		$this->prime_model_allowlist( array() );

		foreach ( array( 'gpt-4!bad', str_repeat( 'a', 101 ), 'model with spaces' ) as $bad ) {
			$response = $this->save_model_preference( $bad );

			$this->assertFalse( $response['success'] ?? true, 'A model id of "' . $bad . '" must be refused.' );
			$this->assertStringContainsString( 'not valid', $response['data']['message'] ?? '' );
			$this->assertSame( '', (string) get_option( 'mainwp_assistant_model_preference', '' ), 'A refused id must not be stored.' );
		}

		// A script tag is stripped away entirely by sanitisation, which lands on
		// the empty "let WordPress choose" value rather than on a rejection.
		$response = $this->save_model_preference( '<script>alert(1)</script>' );

		$this->assertTrue( $response['success'] ?? false );
		$this->assertSame( '', $response['data']['model'] ?? 'unset' );
		$this->assertSame( '', (string) get_option( 'mainwp_assistant_model_preference', '' ) );

		delete_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT );
		delete_option( 'mainwp_assistant_model_preference' );
	}

	/**
	 * An id no configured provider serves is refused, naming real candidates.
	 */
	public function test_model_preference_rejects_an_unknown_id_and_names_candidates() {
		$this->set_current_user_as_admin();
		MainWP_Assistant_Settings::init();
		$this->prime_model_allowlist(
			array(
				'gemini-2.5-flash' => array( 'google' ),
				'gpt-5-mini'       => array( 'openai' ),
			)
		);

		$response = $this->save_model_preference( 'no-such-model' );

		$this->assertFalse( $response['success'] ?? true );
		$this->assertStringContainsString( 'gemini-2.5-flash', $response['data']['message'] ?? '', 'The error must name models the dashboard can actually use.' );
		$this->assertContains( 'gemini-2.5-flash', $response['data']['available'] ?? array() );
		$this->assertSame( '', (string) get_option( 'mainwp_assistant_model_preference', '' ), 'A refused id must not be stored.' );

		delete_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT );
		delete_option( 'mainwp_assistant_model_preference' );
	}

	/**
	 * A known id is stored and echoed back with the providers offering it.
	 */
	public function test_model_preference_echoes_the_providers_for_a_known_id() {
		$this->set_current_user_as_admin();
		MainWP_Assistant_Settings::init();
		$this->prime_model_allowlist( array( 'gemini-2.5-flash' => array( 'google' ) ) );

		$response = $this->save_model_preference( 'gemini-2.5-flash' );

		$this->assertTrue( $response['success'] ?? false );
		$this->assertSame( 'gemini-2.5-flash', $response['data']['model'] ?? '' );
		$this->assertTrue( $response['data']['known'] ?? false );
		$this->assertSame( array( 'google' ), $response['data']['providers'] ?? array() );
		$this->assertSame( 'gemini-2.5-flash', (string) get_option( 'mainwp_assistant_model_preference', '' ) );

		delete_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT );
		delete_option( 'mainwp_assistant_model_preference' );
	}

	/**
	 * An empty preference stays valid and means "let WordPress choose".
	 */
	public function test_model_preference_accepts_an_empty_value() {
		$this->set_current_user_as_admin();
		MainWP_Assistant_Settings::init();
		$this->prime_model_allowlist( array( 'gemini-2.5-flash' => array( 'google' ) ) );

		update_option( 'mainwp_assistant_model_preference', 'gemini-2.5-flash' );

		$response = $this->save_model_preference( '' );

		$this->assertTrue( $response['success'] ?? false );
		$this->assertFalse( $response['data']['known'] ?? true );
		$this->assertSame( '', (string) get_option( 'mainwp_assistant_model_preference', '' ) );

		delete_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT );
		delete_option( 'mainwp_assistant_model_preference' );
	}

	/**
	 * An allowlist that cannot be built never blocks a save.
	 *
	 * A dashboard whose providers are unreachable must still be able to set a
	 * preference; it just cannot be told whether the id is real.
	 */
	public function test_model_preference_saves_unverified_when_the_allowlist_is_empty() {
		$this->set_current_user_as_admin();
		MainWP_Assistant_Settings::init();
		$this->prime_model_allowlist( array() );

		$response = $this->save_model_preference( 'some-future-model' );

		$this->assertTrue( $response['success'] ?? false, 'An unbuildable allowlist must not block a save.' );
		$this->assertFalse( $response['data']['known'] ?? true );
		$this->assertSame( array(), $response['data']['providers'] ?? array( 'unset' ) );
		$this->assertSame( 'some-future-model', (string) get_option( 'mainwp_assistant_model_preference', '' ) );

		delete_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT );
		delete_option( 'mainwp_assistant_model_preference' );
	}

	/**
	 * The allowlist filter is what the handler validates against.
	 */
	public function test_model_preference_honors_the_allowlist_filter() {
		$this->set_current_user_as_admin();
		MainWP_Assistant_Settings::init();
		$this->prime_model_allowlist( array( 'gemini-2.5-flash' => array( 'google' ) ) );

		$filter = static function () {
			return array( 'house-model' => array( 'in-house' ) );
		};

		add_filter( 'mainwp_assistant_model_allowlist', $filter );

		$accepted = $this->save_model_preference( 'house-model' );
		$refused  = $this->save_model_preference( 'gemini-2.5-flash' );

		remove_filter( 'mainwp_assistant_model_allowlist', $filter );

		$this->assertTrue( $accepted['success'] ?? false, 'A filtered-in model must be accepted.' );
		$this->assertSame( array( 'in-house' ), $accepted['data']['providers'] ?? array() );
		$this->assertFalse( $refused['success'] ?? true, 'A model the filter removed must be refused.' );

		delete_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT );
		delete_option( 'mainwp_assistant_model_preference' );
	}

}
