<?php
/**
 * RED-FIRST tests for the chat-job poll status collapse bug.
 *
 * MainWP_Assistant::ajax_poll_chat_job() currently collapses a 'queued' job
 * status into 'processing' before returning it to the client (see the
 * `if ( 'queued' === $status || 'processing' === $status )` branch at
 * class/class-mainwp-assistant.php:5726). The fix under test makes the
 * handler return the real status verbatim: 'queued' stays 'queued' and
 * 'processing' stays 'processing'. The client uses a persistent 'queued'
 * response as its cron-dead fallback signal, so collapsing the two statuses
 * hides a dead cron queue from the poller.
 *
 * test_poll_reports_queued_status_distinctly() is the red signal: it fails
 * today because the handler still reports 'processing' for a queued job.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;

/**
 * Class Test_Assistant_Chat_Job_Poll
 */
class Test_Assistant_Chat_Job_Poll extends MainWP_Assistant_Test_Case {

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

    // =========================================================================
    // AJAX HARNESS (mirrors test-assistant-access-gate.php / test-assistant-guardrails.php)
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

    // =========================================================================
    // FIXTURE HELPERS
    // =========================================================================

    /**
     * Set up an admin who can reach ajax_poll_chat_job() past the enabled /
     * consent gates that run before the status branch.
     *
     * @return int Admin user ID.
     */
    private function set_up_admin_for_poll(): int {
        $user_id = $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        return $user_id;
    }

    /**
     * Seed a chat-job transient shaped like queue_chat_job()'s own write
     * (class/class-mainwp-assistant.php ~:2229), so the polled data matches
     * what a real queued job looks like.
     *
     * @param string $job_id Job ID (without the transient prefix).
     * @param string $status Job status to seed.
     * @param int    $user_id Owning user ID.
     *
     * @return void
     */
    private function seed_chat_job( string $job_id, string $status, int $user_id ): void {
        set_transient(
            'mainwp_chat_job_' . $job_id,
            array(
                'status'          => $status,
                'user_id'         => $user_id,
                'message'         => 'Test poll message',
                'conversation_id' => 0,
                'context'         => array(),
                'regenerate'      => false,
                'show_disclosure' => false,
                'created'         => time(),
                'result'          => null,
                'error'           => null,
            ),
            300
        );
    }

    // =========================================================================
    // TESTS
    // =========================================================================

    /**
     * A queued job must be reported as 'queued', not collapsed into
     * 'processing'. This is the red signal for this file: the handler
     * currently returns 'processing' for a queued job (line 5726).
     *
     * @return void
     */
    public function test_poll_reports_queued_status_distinctly() {
        $user_id = $this->set_up_admin_for_poll();
        $job_id  = 'test-poll-queued-' . wp_generate_uuid4();
        $this->seed_chat_job( $job_id, 'queued', $user_id );

        $response = $this->dispatch_assistant_ajax(
            'ajax_poll_chat_job',
            'mainwp_assistant_poll_chat_job',
            array( 'job_id' => $job_id )
        );

        delete_transient( 'mainwp_chat_job_' . $job_id );

        $this->assertTrue(
            $response['success'] ?? false,
            'Polling a queued job must succeed.'
        );
        $this->assertSame(
            'queued',
            $response['data']['status'] ?? null,
            'A queued job must not be reported as processing — the client uses persistent queued as the cron-dead fallback signal.'
        );
    }

    /**
     * A processing job must still be reported as 'processing'.
     *
     * @return void
     */
    public function test_poll_reports_processing_status() {
        $user_id = $this->set_up_admin_for_poll();
        $job_id  = 'test-poll-processing-' . wp_generate_uuid4();
        $this->seed_chat_job( $job_id, 'processing', $user_id );

        $response = $this->dispatch_assistant_ajax(
            'ajax_poll_chat_job',
            'mainwp_assistant_poll_chat_job',
            array( 'job_id' => $job_id )
        );

        delete_transient( 'mainwp_chat_job_' . $job_id );

        $this->assertTrue(
            $response['success'] ?? false,
            'Polling a processing job must succeed.'
        );
        $this->assertSame(
            'processing',
            $response['data']['status'] ?? null,
            'A processing job must be reported as processing.'
        );
    }

    /**
     * A job owned by a different user must stay denied by the ownership
     * gate, regardless of the status-reporting fix.
     *
     * @return void
     */
    public function test_poll_denies_other_users_job() {
        $this->set_up_admin_for_poll();
        $other_user_id = $this->create_other_admin();

        $job_id = 'test-poll-other-user-' . wp_generate_uuid4();
        $this->seed_chat_job( $job_id, 'queued', $other_user_id );

        $response = $this->dispatch_assistant_ajax(
            'ajax_poll_chat_job',
            'mainwp_assistant_poll_chat_job',
            array( 'job_id' => $job_id )
        );

        delete_transient( 'mainwp_chat_job_' . $job_id );

        $this->assertFalse(
            $response['success'] ?? true,
            'Polling a job owned by another user must be denied.'
        );
    }
}
