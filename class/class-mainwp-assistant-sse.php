<?php
/**
 * Handles Server-Sent Events (SSE) streaming for MainWP Assistant chat jobs.
 *
 * Provides real-time updates for async chat jobs with automatic fallback signaling
 * when SSE cannot complete (timeout, connection issues).
 *
 * @package MainWP/Dashboard
 * @since   6.0.0
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Assistant_SSE
 *
 * SSE endpoint handler for streaming chat job status updates.
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_SSE {

    /**
     * AJAX action for SSE stream endpoint.
     *
     * @var string
     */
    const AJAX_ACTION = 'mainwp_assistant_sse_stream';

    /**
     * Heartbeat interval in seconds.
     *
     * Reverse proxies in front of WordPress commonly drop an idle connection at
     * 30 or 60 seconds. Sending an event every 15 seconds keeps bytes moving so a
     * stream waiting on a slow model call is not cut off mid-job.
     *
     * @var int
     */
    const HEARTBEAT_INTERVAL = 15;

    /**
     * Maximum SSE runtime in seconds (2 minutes).
     *
     * Stops short of the max_execution_time and proxy read timeouts on typical
     * hosts, so the loop can send its own timeout event with fallback = true and
     * let the client switch to polling. A hard kill would leave the client hanging.
     *
     * @var int
     */
    const MAX_RUNTIME = 120;

    /**
     * Interval in seconds between consent/enabled re-checks inside the loop.
     *
     * Each re-check busts the option cache (an alloptions reload), so it is
     * throttled instead of running every 500ms iteration.
     *
     * @var int
     */
    const POLICY_CHECK_INTERVAL = 2;

    /**
     * Poll interval in microseconds (500ms).
     *
     * @var int
     */
    const POLL_INTERVAL_US = 500000;

    /**
     * Singleton instance.
     *
     * @var MainWP_Assistant_SSE|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return MainWP_Assistant_SSE
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct() {}

    /**
     * Initialize SSE endpoint.
     *
     * Registers the AJAX handler for the SSE stream.
     */
    public function init() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_sse_stream' ) );
    }

    /**
     * Handle SSE stream request.
     *
     * Validates request, sets up SSE headers, and streams job status updates.
     * Uses exit() instead of wp_die() to avoid breaking the SSE stream.
     */
    public function handle_sse_stream() {
        // Release session lock immediately to allow other requests.
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }

        // EventSource cannot set request headers, so the nonce has to ride in the query string.
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::AJAX_ACTION ) ) {
            $this->setup_sse_headers();
            $this->send_sse_event( 'job_error', array( 'message' => 'Invalid security token' ) );
            exit;
        }

        // Verify user capabilities: unrestricted administrators only.
        if ( ! MainWP_Assistant::current_user_can_use_assistant() ) {
            $this->setup_sse_headers();
            $this->send_sse_event( 'job_error', array( 'message' => 'The assistant is not available for restricted accounts.' ) );
            exit;
        }

        if ( ! MainWP_Assistant_Policies::instance()->is_assistant_enabled() ) {
            $this->setup_sse_headers();
            $this->send_sse_event( 'job_error', array( 'message' => 'The AI Assistant is disabled.' ) );
            exit;
        }

        if ( ! MainWP_Assistant_Policies::instance()->has_consent() ) {
            $this->setup_sse_headers();
            $this->send_sse_event( 'job_error', array( 'message' => 'Assistant features require privacy consent.' ) );
            exit;
        }

        $job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            $this->setup_sse_headers();
            $this->send_sse_event( 'job_error', array( 'message' => 'Missing job_id' ) );
            exit;
        }

        // The loop must keep running past the client's disconnect so an in-progress
        // job still reaches the transient, and past the default execution limit.
        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );

        $this->setup_sse_headers();
        $this->run_sse_loop( $job_id );

        exit;
    }

    /**
     * Set up SSE headers and clear output buffers.
     *
     * Any layer that buffers or compresses output holds events back until the
     * response ends, which defeats streaming, so all of them are turned off here.
     */
    private function setup_sse_headers() {
        // Prevent compression.
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- Required for SSE streaming.
        }
        @ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.IniSet.Risky

        header( 'Content-Type: text/event-stream; charset=utf-8' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'X-Accel-Buffering: no' ); // Nginx: disable proxy buffering.
        header( 'Connection: keep-alive' );

        while ( ob_get_level() > 0 ) {
            if ( ! ob_end_clean() ) {
                break;
            }
        }

        ob_implicit_flush( true );
    }

    /**
     * Run the SSE event loop.
     *
     * Continuously checks job status and sends events until job completes,
     * fails, or max runtime is reached.
     *
     * @param string $job_id The chat job ID to monitor.
     */
    private function run_sse_loop( $job_id ) {
        $start_time          = time();
        $last_heartbeat_time = time();
        $last_policy_check   = 0;
        $user_id             = get_current_user_id();

        while ( ! connection_aborted() ) {
            $elapsed = time() - $start_time;

            if ( $elapsed >= self::MAX_RUNTIME ) {
                $this->send_sse_event(
                    'job_error',
                    array(
                        'message'  => 'SSE timeout reached',
                        'fallback' => true,
                    )
                );
                break;
            }

            // Consent boundary re-check: a mid-stream revoke/disable is terminal.
            // $last_policy_check starts at 0 so the first iteration always checks.
            // The cache bust also keeps the queued case's in-process job run below
            // reading fresh policy options.
            if ( ( time() - $last_policy_check ) >= self::POLICY_CHECK_INTERVAL ) {
                $last_policy_check = time();
                MainWP_Assistant_Policies::instance()->refresh_policy_cache();

                $policy_code    = null;
                $policy_message = '';
                if ( ! MainWP_Assistant_Policies::instance()->is_assistant_enabled() ) {
                    $policy_code    = 'assistant_disabled';
                    $policy_message = 'The AI Assistant is disabled.';
                } elseif ( ! MainWP_Assistant_Policies::instance()->has_consent() ) {
                    $policy_code    = 'no_consent';
                    $policy_message = 'Assistant features require privacy consent.';
                }

                if ( $policy_code ) {
                    $this->send_sse_event(
                        'job_error',
                        array(
                            'status'   => 'failed',
                            'message'  => $policy_message,
                            'code'     => $policy_code,
                            'fallback' => false,
                        )
                    );
                    break;
                }
            }

            $job_data = $this->get_job_status( $job_id, $user_id );

            if ( false === $job_data ) {
                $this->send_sse_event(
                    'job_error',
                    array(
                        'status'   => 'expired',
                        'message'  => 'Job not found or expired',
                        'fallback' => false,
                    )
                );
                break;
            }

            $status = $job_data['status'] ?? 'unknown';

            switch ( $status ) {
                case 'completed':
                    $this->send_sse_event( 'complete', $this->format_complete_data( $job_data ) );
                    return;

                case 'failed':
                    $this->send_sse_event( 'job_error', $this->format_failure_data( $job_data ) );
                    return;

                case 'cancelled':
                    // The user's Stop landed server-side. The stopping tab
                    // already closed its stream; this terminal event is for
                    // any other stream still watching the job.
                    $this->send_sse_event(
                        'job_error',
                        array(
                            'status'   => 'failed',
                            'message'  => $job_data['error'] ?? 'Response generation stopped.',
                            'code'     => $job_data['error_code'] ?? 'assistant_stopped',
                            'fallback' => false,
                        )
                    );
                    return;

                case 'queued':
                    // No cron here: this request runs the job itself. process_chat_job()
                    // holds an add_option() mutex (class-mainwp-assistant.php), so a second
                    // stream or the sync fallback racing the same job returns instead of
                    // running it twice.
                    $this->send_sse_event(
                        'status',
                        array(
                            'status'  => 'processing',
                            'phase'   => 'direct',
                            'elapsed' => $elapsed,
                        )
                    );
                    if ( function_exists( 'flush' ) ) {
                        flush();
                    }
                    MainWP_Assistant::instance()->process_chat_job( $job_id );

                    // Read the result here rather than waiting for the next iteration: a slow
                    // model call can push the loop past MAX_RUNTIME and lose a finished job.
                    $job_data = $this->get_job_status( $job_id, $user_id );
                    if ( false !== $job_data && 'completed' === ( $job_data['status'] ?? '' ) ) {
                        $this->send_sse_event( 'complete', $this->format_complete_data( $job_data ) );
                        return;
                    } elseif ( false !== $job_data && 'failed' === ( $job_data['status'] ?? '' ) ) {
                        $this->send_sse_event( 'job_error', $this->format_failure_data( $job_data ) );
                        return;
                    }
                    // Unexpected state, keep monitoring.
                    break;

                case 'processing':
                    // Already being processed by the sync fallback or an earlier stream, so only report status.
                    $this->send_sse_event(
                        'status',
                        array(
                            'status'  => $status,
                            'phase'   => $job_data['phase'] ?? null,
                            'elapsed' => $elapsed,
                        )
                    );
                    break;

                default:
                    $this->send_sse_event(
                        'status',
                        array(
                            'status'  => $status,
                            'elapsed' => $elapsed,
                        )
                    );
            }

            if ( ( time() - $last_heartbeat_time ) >= self::HEARTBEAT_INTERVAL ) {
                $this->send_sse_event( 'heartbeat', array( 'timestamp' => time() ) );
                $last_heartbeat_time = time();
            }

            if ( function_exists( 'flush' ) ) {
                flush();
            }

            usleep( self::POLL_INTERVAL_US );
        }
    }

    /**
     * Get job status from transient.
     *
     * Retrieves the chat job data and validates ownership.
     *
     * @param string $job_id  The job ID.
     * @param int    $user_id The current user ID.
     *
     * @return array|false Job data array or false if not found/unauthorized.
     */
    private function get_job_status( $job_id, $user_id ) {
        $job_data = get_transient( 'mainwp_chat_job_' . $job_id );

        if ( false === $job_data || ! is_array( $job_data ) ) {
            return false;
        }

        // Verify job belongs to current user.
        if ( ! isset( $job_data['user_id'] ) || (int) $job_data['user_id'] !== (int) $user_id ) {
            return false;
        }

        return $job_data;
    }

    /**
     * Format completed job data for SSE event.
     *
     * Extracts relevant fields from the job result for the complete event.
     *
     * @param array $job_data The completed job data.
     *
     * @return array Formatted data for the complete event.
     */
    private function format_complete_data( $job_data ) {
        $result = $job_data['result'] ?? array();

        return array(
            'status'                => 'completed',
            'response'              => $result['response'] ?? $result['message'] ?? '',
            'sources'               => $result['sources'] ?? array(),
            'executed_abilities'    => $result['executed_abilities'] ?? array(),
            'conversation_id'       => $result['conversation_id'] ?? null,
            'message_id'            => $result['message_id'] ?? null,
            'provider_name'         => $result['provider_name'] ?? null,
            'mode'                  => $result['mode'] ?? 'agent',
            'model'                 => $result['model'] ?? null,
            'usage'                 => $result['usage'] ?? null,
            'cost'                  => $result['cost'] ?? null,
            'display_usage'         => $result['display_usage'] ?? null,
            'display_cost'          => $result['display_cost'] ?? null,
            'show_disclosure'       => $result['show_disclosure'] ?? false,
            'history_disabled'      => $result['history_disabled'] ?? false,
            'batch_job'             => $result['batch_job'] ?? false,
            'job_id'                => $result['job_id'] ?? null,
            'total'                 => $result['total'] ?? null,
            'poll_endpoint'         => $result['poll_endpoint'] ?? null,
            'poll_interval'         => $result['poll_interval'] ?? null,
            'requires_confirmation' => $result['requires_confirmation'] ?? false,
            'confirmation_data'     => $result['confirmation_data'] ?? null,
        );
    }

    /**
     * Build the terminal failure payload for a failed job.
     *
     * The browser tries SSE before polling, so anything the poll endpoint
     * relays has to be relayed here too or the primary path loses it.
     *
     * @param array $job_data The failed job data.
     *
     * @return array Data for the job_error event.
     */
    private function format_failure_data( $job_data ) {
        $data = array(
            'status'   => 'failed',
            'message'  => $job_data['error'] ?? 'Job failed',
            'code'     => $job_data['error_code'] ?? null,
            'fallback' => false,
        );

        foreach ( array( 'retry_after', 'provider', 'provider_message' ) as $advisory_key ) {
            if ( isset( $job_data[ $advisory_key ] ) ) {
                $data[ $advisory_key ] = $job_data[ $advisory_key ];
            }
        }

        return $data;
    }

    /**
     * Send an SSE event.
     *
     * Formats and outputs an event in SSE format.
     *
     * Never name an event 'error': on the client, a server-sent event of that
     * name dispatches with the same type as EventSource's built-in
     * connection-error event, so the reconnect handler fires on every job
     * failure and re-streams the failure in an infinite loop. Terminal
     * failures use 'job_error'.
     *
     * @param string $event The event name.
     * @param array  $data  The event data.
     */
    private function send_sse_event( $event, $data ) {
        echo 'event: ' . esc_html( $event ) . "\n";
        echo 'data: ' . wp_json_encode( $data ) . "\n\n";

        if ( function_exists( 'flush' ) ) {
            flush();
        }
    }
}
