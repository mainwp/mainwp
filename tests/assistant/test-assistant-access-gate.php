<?php
/**
 * Tests for the multi-user assistant access gate.
 *
 * MainWP_Assistant::current_user_can_use_assistant() gates assistant use: a user
 * passes only when they exist, hold manage_options, pass
 * mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ), and the
 * mainwp_currentuserallowedaccesssites filter returns 'all' or a non-empty scope
 * (an empty array or any other non-'all', non-list value denies). Scoped users
 * pass the gate and are then constrained per ability downstream.
 *
 * test_gate_helper_exists() asserts the helper is present, so its accidental
 * removal surfaces as a failure rather than a fatal across the rest of the file.
 * The truth-table tests exercise the helper directly; the AJAX-handler and
 * render tests exercise the production code paths that consume it.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use MainWP\Dashboard\MainWP_Assistant_Insights;
use MainWP\Dashboard\MainWP_Assistant_Policies;
use MainWP\Dashboard\MainWP_DB_Assistant;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_UI;

/**
 * Class Test_Assistant_Access_Gate
 */
class Test_Assistant_Access_Gate extends MainWP_Assistant_Test_Case {

    /**
     * Captured AJAX output for the endpoint tests.
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
     * Clean up the access-restriction filter after each test.
     *
     * @return void
     */
    public function tearDown(): void {
        remove_all_filters( 'mainwp_currentuserallowedaccesssites' );
        parent::tearDown();
    }

    // =========================================================================
    // SKIP GUARDS
    // =========================================================================

    /**
     * Skip when a capability-bypass constant has leaked into the process.
     *
     * mainwp_current_user_have_right() returns true unconditionally while
     * MAINWP_REST_API_DOING, DOING_CRON, or WP_CLI is defined, and the REST
     * integration tests in tests/abilities define MAINWP_REST_API_DOING during
     * authentication. Constants cannot be undefined, so a capability-denial
     * assertion proves nothing once one of them is set. phpunit.xml.dist runs
     * the Assistant suite before Abilities for this reason; the skip makes an
     * ordering regression visible instead of failing confusingly.
     *
     * Copied from Test_Assistant_Settings_Ajax::skip_if_capability_checks_bypassed()
     * — it is not on the shared base class.
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
    // FIXTURE HELPERS
    // =========================================================================

    /**
     * Scope the current user's site access via the allowed-sites filter.
     *
     * @param array $site_ids Allowed site IDs. An empty array scopes the user
     *                         to nothing; any non-'all' return denies the gate.
     *
     * @return void
     */
    private function restrict_current_user_to_sites( array $site_ids = array( 1 ) ): void {
        add_filter(
            'mainwp_currentuserallowedaccesssites',
            function () use ( $site_ids ) {
                return $site_ids;
            }
        );
    }

    /**
     * Create and switch to an admin whose site access is scoped (restricted).
     *
     * @param array $site_ids Allowed site IDs for the restriction filter.
     *
     * @return int User ID.
     */
    private function set_current_user_as_restricted_admin( array $site_ids = array( 1 ) ): int {
        $user_id = $this->set_current_user_as_admin();
        $this->restrict_current_user_to_sites( $site_ids );
        return $user_id;
    }

    // =========================================================================
    // AJAX HARNESS (mirrors test-assistant-guardrails.php / test-assistant-chat.php)
    // =========================================================================

    /**
     * Set up AJAX testing environment.
     *
     * @return void
     */
    protected function set_up_ajax_testing(): void {
        $this->ajax_response = '';
        add_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
        add_filter( 'wp_doing_ajax', '__return_true' );

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

        $_POST    = array();
        $_GET     = array();
        $_REQUEST = array();
    }

    /**
     * Get the wp_die handler used during AJAX dispatch.
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

    /**
     * Dispatch a nonce-signed request to an assistant AJAX handler and parse
     * the JSON reply.
     *
     * @param string $method_name Method name on MainWP_Assistant.
     * @param string $action      Nonce action name.
     * @param array  $request     POST payload (merged over action/security).
     *
     * @return array Parsed response with success/data keys.
     */
    private function dispatch_assistant_ajax( string $method_name, string $action, array $request = array() ): array {
        $this->set_up_ajax_testing();

        $_POST    = array_merge(
            array(
                'action'   => $action,
                'security' => wp_create_nonce( $action ),
            ),
            $request
        );
        $_GET     = array( 'action' => $action );
        $_REQUEST = array_merge( $_POST, $_GET );

        $ob_level = ob_get_level();
        ob_start();

        try {
            MainWP_Assistant::instance()->{$method_name}();
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

        $this->tear_down_ajax_testing();

        $decoded = json_decode( trim( $this->ajax_response ), true );

        return is_array( $decoded ) ? $decoded : array(
            'success' => false,
            'data'    => array( 'raw' => $this->ajax_response ),
        );
    }

    /**
     * Seed an unresolved confirmation-request message, matching the
     * confirmation card's own persisted shape (mirrors
     * Test_Assistant_Chat::add_unresolved_confirmation_message()).
     *
     * @param int   $conversation_id Conversation ID.
     * @param int   $user_id         Owning user.
     * @param array $params          Ability params the card previewed.
     *
     * @return int Row (request) ID.
     */
    private function add_unresolved_confirmation_message( int $conversation_id, int $user_id, array $params ): int {
        return MainWP_DB_Assistant::instance()->add_message(
            $conversation_id,
            'assistant',
            'I need your confirmation to proceed with: Add MainWP Tag',
            $user_id,
            array(
                'route'                 => 'agent',
                'requires_confirmation' => true,
                'pending_ability'       => 'mainwp/add-tag-v1',
                'pending_ability_label' => 'Add MainWP Tag',
                'pending_confirmation'  => array(
                    'ability'      => 'mainwp/add-tag-v1',
                    'label'        => 'Add MainWP Tag',
                    'params'       => $params,
                    'destructive'  => false,
                    'tool_call_id' => 'call_gate_test',
                    'tool_calls'   => array(
                        array(
                            'id'       => 'call_gate_test',
                            'type'     => 'function',
                            'function' => array(
                                'name'      => 'wpab__mainwp__add-tag-v1',
                                'arguments' => wp_json_encode( $params ),
                            ),
                        ),
                    ),
                    'tool_results' => array(),
                ),
            )
        );
    }

    // =========================================================================
    // TEST 1: HELPER EXISTENCE — the clean red signal
    // =========================================================================

    /**
     * The gate helper must exist as a public static method.
     *
     * This is the primary red signal for this file: everything else either
     * skips (if this fails) or exercises production code that does not
     * reference the helper and fails on its own merits.
     *
     * @return void
     */
    public function test_gate_helper_exists() {
        $this->assertTrue(
            method_exists( MainWP_Assistant::class, 'current_user_can_use_assistant' ),
            'MainWP_Assistant::current_user_can_use_assistant() must exist as a public static gate helper.'
        );
    }

    // =========================================================================
    // HELPER TRUTH TABLE
    // =========================================================================

    /**
     * An unrestricted admin (filter returns 'all') passes the gate.
     *
     * @return void
     */
    public function test_gate_true_for_unrestricted_admin() {
        $this->skip_if_capability_checks_bypassed();
        $this->set_current_user_as_admin();

        $this->assertTrue(
            MainWP_Assistant::current_user_can_use_assistant(),
            'An admin with manage_options, dashboard access, and an unscoped allowed-sites filter must pass the gate.'
        );
    }

    /**
     * An admin scoped to specific sites (filter returns a non-empty array)
     * passes the gate.
     *
     * Flipped when scoped multi-user access landed: the blanket block on
     * scoped accounts became per-site/per-client enforcement downstream, so
     * the gate admits any non-empty scope.
     *
     * @return void
     */
    public function test_gate_true_for_restricted_admin_with_scoped_sites() {
        $this->skip_if_capability_checks_bypassed();
        $this->set_current_user_as_restricted_admin( array( 1, 2 ) );

        $this->assertTrue(
            MainWP_Assistant::current_user_can_use_assistant(),
            'A user scoped to a non-empty site list must pass the gate; scoping is enforced per ability.'
        );
    }

    /**
     * A malformed filter value (neither "all" nor an ID array) stays denied.
     *
     * @return void
     */
    public function test_gate_false_for_malformed_filter_value() {
        $this->skip_if_capability_checks_bypassed();
        $this->set_current_user_as_admin();
        add_filter(
            'mainwp_currentuserallowedaccesssites',
            static function () {
                return 'everything';
            }
        );

        $this->assertFalse(
            MainWP_Assistant::current_user_can_use_assistant(),
            'A malformed allowed-sites value must fail closed.'
        );
    }

    /**
     * An admin scoped to an empty site list is denied.
     *
     * @return void
     */
    public function test_gate_false_for_restricted_admin_empty_site_list() {
        $this->skip_if_capability_checks_bypassed();
        $this->set_current_user_as_restricted_admin( array() );

        $this->assertFalse(
            MainWP_Assistant::current_user_can_use_assistant(),
            'A user scoped to an empty site list must not pass the gate.'
        );
    }

    /**
     * A user without manage_options is denied even with an unscoped filter.
     *
     * @return void
     */
    public function test_gate_false_for_user_without_manage_options() {
        $this->skip_if_capability_checks_bypassed();
        $user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $user_id );
        $this->modified_user_ids[] = $user_id;

        $this->assertFalse(
            MainWP_Assistant::current_user_can_use_assistant(),
            'A user without manage_options must not pass the gate, regardless of the allowed-sites filter.'
        );
    }

    /**
     * A logged-out visitor is denied.
     *
     * @return void
     */
    public function test_gate_false_for_logged_out_user() {
        $this->skip_if_capability_checks_bypassed();
        wp_set_current_user( 0 );

        $this->assertFalse(
            MainWP_Assistant::current_user_can_use_assistant(),
            'A logged-out visitor must not pass the gate.'
        );
    }

    // =========================================================================
    // AJAX HANDLER DENIAL
    // =========================================================================

    /**
     * Set up a restricted admin with the assistant fully enabled/consented.
     *
     * Shared fixture for the four AJAX-handler denial tests below, so each
     * can be verified (and later maintained) independently.
     *
     * @return int Restricted admin's user ID.
     */
    private function set_up_restricted_admin_for_ajax(): int {
        $user_id = $this->set_current_user_as_restricted_admin( array( 1 ) );
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );
        // Full access mode so the pre-existing per-user access-mode policy
        // never blocks the confirm/cancel fixtures below for an unrelated
        // reason (the default read-only mode denies any mutating ability).
        $this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
        update_user_meta( $user_id, 'mainwp_assistant_disclosure_shown', '1' );
        delete_user_meta( $user_id, 'mainwp_assistant_history_opt_out' );

        return $user_id;
    }

    /**
     * A scoped admin (non-empty site list) can chat.
     *
     * Flipped when scoped multi-user access landed. A bare acknowledgment
     * with mode=auto and no prior conversation resolves entirely locally
     * (send_to_local_ack_response()), with zero AI/network calls, so this is
     * deterministic.
     *
     * The remaining 8 registered handlers not covered individually here
     * (handle_cancel_job, ajax_poll_batch_job, ajax_poll_chat_job,
     * ajax_process_chat_fallback, handle_load_conversation,
     * handle_delete_conversation, handle_check_provider,
     * handle_dismiss_disclosure) are gated by the same
     * current_user_can_use_assistant() helper and are covered by the helper
     * truth table above.
     *
     * @return void
     */
    public function test_handle_chat_request_succeeds_for_scoped_admin() {
        $this->skip_if_capability_checks_bypassed();

        $this->set_up_restricted_admin_for_ajax();

        $chat_response = $this->dispatch_assistant_ajax(
            'handle_chat_request',
            MainWP_Assistant::AJAX_CHAT,
            array(
                'message' => 'yes',
                'mode'    => 'auto',
            )
        );

        if ( ! empty( $chat_response['data']['conversation_id'] ) ) {
            $this->created_conversation_ids[] = (int) $chat_response['data']['conversation_id'];
        }

        $this->assertTrue(
            $chat_response['success'] ?? false,
            'handle_chat_request must serve a scoped admin; scoping is enforced per ability, not at the door.'
        );
    }

    /**
     * An admin with an EMPTY scope stays denied by handle_chat_request, and
     * the denial blocks the side effect (a new conversation).
     *
     * @return void
     */
    public function test_handle_chat_request_denies_empty_scope_admin() {
        $this->skip_if_capability_checks_bypassed();

        $user_id = $this->set_current_user_as_restricted_admin( array() );
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );
        update_user_meta( $user_id, 'mainwp_assistant_disclosure_shown', '1' );

        $before_count = MainWP_DB_Assistant::instance()->get_conversation_count( $user_id );

        $chat_response = $this->dispatch_assistant_ajax(
            'handle_chat_request',
            MainWP_Assistant::AJAX_CHAT,
            array(
                'message' => 'yes',
                'mode'    => 'auto',
            )
        );

        if ( ! empty( $chat_response['data']['conversation_id'] ) ) {
            $this->created_conversation_ids[] = (int) $chat_response['data']['conversation_id'];
        }

        $this->assertFalse(
            $chat_response['success'] ?? true,
            'handle_chat_request must deny an empty-scope admin.'
        );
        $after_count = MainWP_DB_Assistant::instance()->get_conversation_count( $user_id );
        $this->assertSame(
            $before_count,
            $after_count,
            'A denied chat request must not create a conversation.'
        );
    }

    /**
     * A scoped admin can read their own conversation history (own-data
     * endpoint, no cross-user exposure), while an empty scope stays denied.
     *
     * Flipped when scoped multi-user access landed.
     *
     * @return void
     */
    public function test_handle_get_history_succeeds_for_scoped_admin() {
        $this->skip_if_capability_checks_bypassed();

        $user_id = $this->set_up_restricted_admin_for_ajax();

        $conversation_id = $this->create_test_conversation( $user_id, 'Restricted Gate History' );
        $this->add_test_message( $conversation_id, 'user', 'Hello', $user_id );

        $history_response = $this->dispatch_assistant_ajax(
            'handle_get_history',
            MainWP_Assistant::AJAX_GET_HISTORY
        );

        $this->assertTrue(
            $history_response['success'] ?? false,
            'handle_get_history must serve a scoped admin their own history.'
        );
    }

    /**
     * An empty-scope admin stays denied by handle_get_history.
     *
     * @return void
     */
    public function test_handle_get_history_denies_empty_scope_admin() {
        $this->skip_if_capability_checks_bypassed();

        $user_id = $this->set_current_user_as_restricted_admin( array() );
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $conversation_id = $this->create_test_conversation( $user_id, 'Empty Scope History' );
        $this->add_test_message( $conversation_id, 'user', 'Hello', $user_id );

        $history_response = $this->dispatch_assistant_ajax(
            'handle_get_history',
            MainWP_Assistant::AJAX_GET_HISTORY
        );

        $this->assertFalse(
            $history_response['success'] ?? true,
            'handle_get_history must deny an empty-scope admin.'
        );
    }

    /**
     * A scoped admin's confirm executes the pending ability (add-tag stays
     * open to scoped callers), while an empty scope is denied with the
     * ability blocked.
     *
     * Flipped when scoped multi-user access landed.
     *
     * @return void
     */
    public function test_handle_confirm_action_succeeds_for_scoped_admin() {
        $this->skip_if_capability_checks_bypassed();

        $user_id = $this->set_up_restricted_admin_for_ajax();

        $confirm_conversation_id = $this->create_test_conversation( $user_id, 'Restricted Gate Confirm' );
        $this->add_test_message( $confirm_conversation_id, 'user', 'Create a tag called Restricted Gate Confirm Tag.', $user_id );
        $confirm_params      = array( 'name' => 'Test Tag Restricted Gate Confirm' );
        $confirm_request_id  = $this->add_unresolved_confirmation_message( $confirm_conversation_id, $user_id, $confirm_params );

        $confirm_response = $this->dispatch_assistant_ajax(
            'handle_confirm_action',
            MainWP_Assistant::AJAX_CONFIRM_ACTION,
            array(
                'ability'         => 'mainwp/add-tag-v1',
                'params'          => wp_slash( wp_json_encode( $confirm_params ) ),
                'conversation_id' => $confirm_conversation_id,
                'request_id'      => $confirm_request_id,
            )
        );

        $this->assertTrue(
            $confirm_response['success'] ?? false,
            'handle_confirm_action must serve a scoped admin; per-target scoping is enforced by the ability itself.'
        );
        $this->assertNotEmpty(
            MainWP_DB_Common::instance()->get_group_by_name( 'Test Tag Restricted Gate Confirm', $user_id ),
            'The confirmed add-tag ability must have executed for the scoped admin.'
        );
    }

    /**
     * An empty-scope admin stays denied by handle_confirm_action and the
     * underlying ability does not run.
     *
     * @return void
     */
    public function test_handle_confirm_action_denies_empty_scope_admin() {
        $this->skip_if_capability_checks_bypassed();

        $user_id = $this->set_current_user_as_restricted_admin( array() );
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );
        $this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );

        $confirm_conversation_id = $this->create_test_conversation( $user_id, 'Empty Scope Confirm' );
        $this->add_test_message( $confirm_conversation_id, 'user', 'Create a tag.', $user_id );
        $confirm_params     = array( 'name' => 'Test Tag Empty Scope Confirm' );
        $confirm_request_id = $this->add_unresolved_confirmation_message( $confirm_conversation_id, $user_id, $confirm_params );

        $confirm_response = $this->dispatch_assistant_ajax(
            'handle_confirm_action',
            MainWP_Assistant::AJAX_CONFIRM_ACTION,
            array(
                'ability'         => 'mainwp/add-tag-v1',
                'params'          => wp_slash( wp_json_encode( $confirm_params ) ),
                'conversation_id' => $confirm_conversation_id,
                'request_id'      => $confirm_request_id,
            )
        );

        $this->assertFalse(
            $confirm_response['success'] ?? true,
            'handle_confirm_action must deny an empty-scope admin.'
        );
        $this->assertEmpty(
            MainWP_DB_Common::instance()->get_group_by_name( 'Test Tag Empty Scope Confirm', $user_id ),
            'A denied confirm must not create the tag the ability would have added.'
        );
    }

    /**
     * A scoped admin can cancel a pending confirmation (closing row
     * persists), while an empty scope stays denied.
     *
     * Flipped when scoped multi-user access landed.
     *
     * @return void
     */
    public function test_handle_cancel_action_succeeds_for_scoped_admin() {
        $this->skip_if_capability_checks_bypassed();

        $user_id = $this->set_up_restricted_admin_for_ajax();

        $confirm_conversation_id = $this->create_test_conversation( $user_id, 'Restricted Gate Cancel' );
        $this->add_test_message( $confirm_conversation_id, 'user', 'Create a tag called Restricted Gate Cancel Tag.', $user_id );
        $confirm_params     = array( 'name' => 'Test Tag Restricted Gate Cancel' );
        $confirm_request_id = $this->add_unresolved_confirmation_message( $confirm_conversation_id, $user_id, $confirm_params );

        $cancel_response = $this->dispatch_assistant_ajax(
            'handle_cancel_action',
            MainWP_Assistant::AJAX_CANCEL_ACTION,
            array(
                'ability'         => 'mainwp/add-tag-v1',
                'conversation_id' => $confirm_conversation_id,
                'request_id'      => $confirm_request_id,
            )
        );

        $this->assertTrue(
            $cancel_response['success'] ?? false,
            'handle_cancel_action must serve a scoped admin.'
        );
        $messages_after_cancel = MainWP_DB_Assistant::instance()->get_messages( $confirm_conversation_id, $user_id, 500, 0, 'ASC' );
        $this->assertGreaterThan(
            2,
            count( $messages_after_cancel ),
            'A served cancel must append the closing row.'
        );
    }

    /**
     * An empty-scope admin stays denied by handle_cancel_action with no
     * closing row appended.
     *
     * @return void
     */
    public function test_handle_cancel_action_denies_empty_scope_admin() {
        $this->skip_if_capability_checks_bypassed();

        $user_id = $this->set_current_user_as_restricted_admin( array() );
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $confirm_conversation_id = $this->create_test_conversation( $user_id, 'Empty Scope Cancel' );
        $this->add_test_message( $confirm_conversation_id, 'user', 'Create a tag.', $user_id );
        $confirm_params     = array( 'name' => 'Test Tag Empty Scope Cancel' );
        $confirm_request_id = $this->add_unresolved_confirmation_message( $confirm_conversation_id, $user_id, $confirm_params );

        $cancel_response = $this->dispatch_assistant_ajax(
            'handle_cancel_action',
            MainWP_Assistant::AJAX_CANCEL_ACTION,
            array(
                'ability'         => 'mainwp/add-tag-v1',
                'conversation_id' => $confirm_conversation_id,
                'request_id'      => $confirm_request_id,
            )
        );

        $this->assertFalse(
            $cancel_response['success'] ?? true,
            'handle_cancel_action must deny an empty-scope admin.'
        );
        $messages_after_cancel = MainWP_DB_Assistant::instance()->get_messages( $confirm_conversation_id, $user_id, 500, 0, 'ASC' );
        $this->assertCount(
            2,
            $messages_after_cancel,
            'A denied cancel must not append a closing row (user message + pending confirmation only).'
        );
    }

    // =========================================================================
    // PANEL RENDERING
    // =========================================================================

    /**
     * The floating assistant panel renders for a scoped user (non-empty
     * site list).
     *
     * Flipped when scoped multi-user access landed.
     *
     * @return void
     */
    public function test_render_assistant_panel_renders_for_scoped_user() {
        $this->set_current_user_as_restricted_admin( array( 1 ) );
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        ob_start();
        MainWP_UI::render_assistant_panel();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'id="mainwp-assistant-panel"',
            $output,
            'A scoped user must receive the assistant panel markup.'
        );
    }

    /**
     * The floating assistant panel must render nothing for an empty-scope
     * user.
     *
     * @return void
     */
    public function test_render_assistant_panel_empty_for_empty_scope_user() {
        $this->set_current_user_as_restricted_admin( array() );
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        ob_start();
        MainWP_UI::render_assistant_panel();
        $output = ob_get_clean();

        $this->assertStringNotContainsString(
            'id="mainwp-assistant-panel"',
            $output,
            'An empty-scope user must not receive the assistant panel markup.'
        );
    }

    /**
     * The floating assistant panel renders normally for an unrestricted admin.
     *
     * Control case: proves the restricted-user assertion above is meaningful
     * and not a fixture/setup problem.
     *
     * @return void
     */
    public function test_render_assistant_panel_renders_for_admin() {
        $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        ob_start();
        MainWP_UI::render_assistant_panel();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'id="mainwp-assistant-panel"',
            $output,
            'An unrestricted admin must receive the assistant panel markup.'
        );
    }

    // =========================================================================
    // ASSISTANT INSIGHTS
    // Insights is an assistant surface: its cross-user conversation and
    // activity reads gate on manage_dashboard_settings, a right Team Control
    // can grant to a site-restricted role. The shared verify_insights_access()
    // chokepoint must fail closed to the same unrestricted-admin gate so a
    // restricted account cannot read other users' transcripts through it.
    // =========================================================================

    /**
     * Invoke the private verify_insights_access() chokepoint through the AJAX
     * die harness and report whether it denied the current user.
     *
     * @return bool True when the gate denied (wp_send_json_error fired).
     */
    private function insights_access_denied(): bool {
        $this->set_up_ajax_testing();

        $ob_level = ob_get_level();
        ob_start();

        $denied = false;
        try {
            $method = new \ReflectionMethod( MainWP_Assistant_Insights::class, 'verify_insights_access' );
            $method->setAccessible( true );
            $method->invoke( null );
        } catch ( \WPAjaxDieContinueException $e ) {
            $denied = true;
        } catch ( \WPAjaxDieStopException $e ) {
            $denied = true;
        }

        if ( ob_get_level() > $ob_level ) {
            $remaining = ob_get_clean();
            if ( $remaining ) {
                $this->ajax_response .= $remaining;
            }
        }

        $this->tear_down_ajax_testing();

        return $denied;
    }

    /**
     * A restricted admin — even one holding manage_dashboard_settings, the
     * right that unlocks cross-user conversation reads — must be denied at the
     * insights access chokepoint.
     *
     * @return void
     */
    public function test_insights_access_denies_restricted_admin() {
        $this->skip_if_capability_checks_bypassed();

        // Non-empty scope on purpose: the main assistant gate now ADMITS
        // this user, so this pins Insights' deliberate unrestricted-only
        // exception — cross-user transcript reads stay locked even for
        // scoped accounts.
        $this->set_current_user_as_restricted_admin( array( 1 ) );

        $this->assertTrue(
            $this->insights_access_denied(),
            'verify_insights_access() must deny a scoped admin so cross-user assistant transcripts stay unreadable.'
        );
    }

    /**
     * Control: an unrestricted admin passes the insights access chokepoint,
     * proving the restricted-user assertion above is not a fixture artifact.
     *
     * @return void
     */
    public function test_insights_access_allows_unrestricted_admin() {
        $this->skip_if_capability_checks_bypassed();

        $this->set_current_user_as_admin();

        $this->assertFalse(
            $this->insights_access_denied(),
            'verify_insights_access() must allow an unrestricted admin through the gate.'
        );
    }

    /**
     * The reflection helper must own its output buffer: the AJAX die handler
     * closes one buffer unconditionally, and without a helper-opened buffer
     * that close consumes PHPUnit's own wrapper (reported as a risky test).
     *
     * @return void
     */
    public function test_insights_access_helper_preserves_output_buffers() {
        $this->skip_if_capability_checks_bypassed();

        $this->set_current_user_as_restricted_admin( array( 1 ) );

        $level_before = ob_get_level();
        $this->insights_access_denied();

        $this->assertSame(
            $level_before,
            ob_get_level(),
            'insights_access_denied() must leave the output buffer stack exactly as it found it.'
        );
    }

    /**
     * Invoke the CSV export handler under the AJAX harness with a valid nonce
     * and an all-users filter, returning the wp_die message it raised (empty
     * string if it did not die).
     *
     * @return string The wp_die message, lowercased.
     */
    private function export_activity_die_message(): string {
        $this->set_up_ajax_testing();

        $_POST['security']    = wp_create_nonce( 'mainwp_assistant_insights_nonce' );
        $_POST['user_ids']    = 'allusers';
        $_REQUEST['security'] = $_POST['security'];
        $_REQUEST['user_ids'] = $_POST['user_ids'];

        $message  = '';
        $ob_level = ob_get_level();
        ob_start();

        try {
            MainWP_Assistant_Insights::ajax_export_activity_csv();
        } catch ( \WPAjaxDieContinueException $e ) {
            $message = $e->getMessage();
        } catch ( \WPAjaxDieStopException $e ) {
            $message = $e->getMessage();
        }

        if ( ob_get_level() > $ob_level ) {
            ob_end_clean();
        }

        unset( $_POST['security'], $_POST['user_ids'], $_REQUEST['security'], $_REQUEST['user_ids'] );
        $this->tear_down_ajax_testing();

        return strtolower( is_string( $message ) ? $message : '' );
    }

    /**
     * The CSV export endpoint carries the same restricted-account lock as the
     * interactive insights endpoints: a scoped admin (even with
     * manage_dashboard_settings + an all-users filter) is denied before the
     * handler can read and export other users' activity.
     *
     * @return void
     */
    public function test_export_activity_denies_restricted_admin() {
        $this->skip_if_capability_checks_bypassed();

        $this->set_up_restricted_admin_for_ajax();

        $this->assertStringContainsString(
            'restricted',
            $this->export_activity_die_message(),
            'ajax_export_activity_csv() must deny a scoped admin with the restricted-account lock before exporting cross-user activity.'
        );
    }
}
