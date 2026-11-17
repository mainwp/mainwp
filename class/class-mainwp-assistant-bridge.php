<?php
/**
 * Bridge HTTP client for the MainWP Assistant.
 *
 * Handles all HTTP communication with the Assistant Bridge service,
 * including bearer token authentication, lazy registration,
 * rate-limit tracking, retry logic, and response normalization.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Assistant_Bridge
 *
 * Singleton HTTP client for the Assistant Bridge API.
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_Bridge {

    /**
     * Timeout for Bridge requests, allowing the 60-second gateway deadline to return.
     *
     * @var int
     */
    const TIMEOUT = 70;

    /**
     * Redirect follow count for Bridge requests.
     *
     * The Bridge API never issues redirects, and following one would replay
     * the bearer token, prompt, and site URL to whatever host the redirect
     * names. A redirect is treated as an error, never followed.
     *
     * @var int
     */
    const REDIRECTION = 0;

    /**
     * Singleton instance.
     *
     * @var MainWP_Assistant_Bridge|null
     */
    private static $instance = null;

    /**
     * Whether a 401 retry has already been attempted this request.
     *
     * @var bool
     */
    private $retried_401 = false;

    /**
     * Whether a 409 retry has already been attempted this request.
     *
     * @var bool
     */
    private $retried_409 = false;

    /**
     * Whether a transient-error retry has already been attempted this request.
     *
     * @var bool
     */
    private $retried_transient = false;

    /**
     * Track nested request() calls so retries do not reset retry flags.
     *
     * @var int
     */
    private $request_depth = 0;

    /**
     * Stable message ID for the current top-level request and its retries.
     *
     * @var string|null
     */
    private $current_message_id = null;

    /**
     * Get singleton instance.
     *
     * @return MainWP_Assistant_Bridge
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Execute an HTTP request to the Assistant Bridge.
     *
     * Handles URL resolution, bearer token authentication, lazy registration,
     * rate-limit tracking, and retry logic for transient errors.
     *
     * @param string $message User message.
     * @param array  $context Request context.
     *
     * @return array|\WP_Error Decoded response body on HTTP 200, WP_Error on failure.
     */
    public function request( $message, $context = array() ) {
        if ( 0 === $this->request_depth ) {
            // Reset retry flags so each top-level call starts fresh (singleton).
            $this->retried_401        = false;
            $this->retried_409        = false;
            $this->retried_transient  = false;
            $this->current_message_id = wp_generate_uuid4();
        }

        ++$this->request_depth;

        try {

            // Fixed production endpoint. Only the MAINWP_ASSISTANT_BRIDGE_URL constant can point this
            // elsewhere (for bridge development); there is no option and no filter, so a compromised
            // database cannot redirect the bearer token and prompts to another host.
            $bridge_url = defined( 'MAINWP_ASSISTANT_BRIDGE_URL' ) ? MAINWP_ASSISTANT_BRIDGE_URL : 'https://ai-bridge.fly.dev';

            if ( empty( $bridge_url ) ) {
                return new \WP_Error( 'bridge_not_configured', __( 'Documentation service not configured. Please configure an AI provider in settings.', 'mainwp' ) );
            }

            if ( ! $this->is_allowed_bridge_url( $bridge_url ) ) {
                return new \WP_Error( 'bridge_insecure_url', __( 'The documentation service URL must use HTTPS.', 'mainwp' ) );
            }

            if ( ! MainWP_Assistant_Policies::instance()->has_consent() ) {
                return new \WP_Error( 'bridge_no_consent', __( 'Assistant consent is required before connecting to the documentation service.', 'mainwp' ) );
            }

            $bearer_token = MainWP_Keys_Manager::instance()->get_keys_value( 'mainwp_assistant_bridge_token', '' );

            // Lazy registration: if consent exists but no bearer token, attempt registration.
            if ( empty( $bearer_token ) ) {
                $reg_result = $this->register( $bridge_url );
                if ( is_wp_error( $reg_result ) ) {
                    if ( 'http_request_failed' === $reg_result->get_error_code() ) {
                        return new \WP_Error( 'bridge_request_failed', __( 'The documentation service did not respond. Please try again.', 'mainwp' ) );
                    }
                    return $reg_result;
                }
                $bearer_token = MainWP_Keys_Manager::instance()->get_keys_value( 'mainwp_assistant_bridge_token', '' );
            }

            // Normalize site URL.
            $normalized_site_url = $this->normalize_url( site_url() );

            // Build request body.
            $request_body = array(
                'message'  => $message,
                'mode'     => 'docs', // Wire value expected by Bridge API, do not rename.
                'site_url' => $normalized_site_url,
                'context'  => $context,
            );

            $json_body = wp_json_encode( $request_body );
            if ( false === $json_body || null === $json_body ) {
                return new \WP_Error(
                    'bridge_json_encode_failed',
                    sprintf(
                        /* translators: %s: JSON encoding error message. */
                        __( 'Failed to encode the assistant bridge request body as JSON: %s', 'mainwp' ),
                        json_last_error_msg()
                    )
                );
            }

            $headers = array(
                'Content-Type'      => 'application/json',
                'Authorization'     => 'Bearer ' . $bearer_token,
                'X-Chat-Message-Id' => $this->current_message_id,
                'X-Site-Url'        => $normalized_site_url,
            );

            // Send request to Bridge.
            $response = wp_remote_post(
                esc_url_raw( trailingslashit( $bridge_url ) . 'assistant/chat' ),
                array(
                    'headers'     => $headers,
                    'body'        => $json_body,
                    'timeout'     => self::TIMEOUT,
                    'redirection' => self::REDIRECTION,
                    'sslverify'   => true,
                )
            );

            // Handle HTTP errors.
            if ( is_wp_error( $response ) ) {
                MainWP_Logger::instance()->debug( 'Assistant Bridge chat transport failed.' );
                return new \WP_Error( 'bridge_request_failed', __( 'The documentation service did not respond. Please try again.', 'mainwp' ) );
            }

            $code          = wp_remote_retrieve_response_code( $response );
            $raw_body      = wp_remote_retrieve_body( $response );
            $body          = json_decode( $raw_body, true );
            $decoded_array = JSON_ERROR_NONE === json_last_error() && is_array( $body );
            $body_error    = $this->extract_bridge_error_message( $decoded_array ? $body : array(), $raw_body );

            if ( ! $decoded_array ) {
                $body = array();
            }

            // Store rate-limit header if present.
            $quota_remaining = wp_remote_retrieve_header( $response, 'X-RateLimit-Remaining-Month' );
            if ( '' !== $quota_remaining ) {
                set_transient( 'mainwp_bridge_quota_remaining', (int) $quota_remaining, HOUR_IN_SECONDS );
            }

            // Handle specific error codes before generic non-200.
            if ( 401 === $code ) {
                if ( ! $this->retried_401 ) {
                    $this->retried_401 = true;
                    $re_reg            = $this->register( $bridge_url );
                    if ( ! is_wp_error( $re_reg ) ) {
                        return $this->request( $message, $context );
                    }
                }
                MainWP_Logger::instance()->debug( 'Assistant Bridge auth failed (401).' );
                return new \WP_Error( 'bridge_unauthorized', __( 'The documentation service rejected this dashboard\'s credentials. Please try again in a few minutes.', 'mainwp' ) );
            }

            if ( 409 === $code ) {
                if ( ! $this->retried_409 ) {
                    $this->retried_409 = true;
                    return $this->request( $message, $context );
                }
                MainWP_Logger::instance()->debug( 'Assistant Bridge message conflict (409).' );
                return new \WP_Error( 'bridge_conflict', __( 'Message delivery failed. Please try again.', 'mainwp' ) );
            }

            if ( 429 === $code ) {
                if ( 'quota_exceeded' === $body_error ) {
                    set_transient( 'mainwp_bridge_quota_remaining', 0, HOUR_IN_SECONDS );
                    MainWP_Logger::instance()->debug( 'Assistant Bridge quota exceeded (429).' );
                    return new \WP_Error( 'bridge_quota_exceeded', __( 'Monthly usage limit reached. Please try again later.', 'mainwp' ) );
                }
                MainWP_Logger::instance()->debug( 'Assistant Bridge rate limited (429).' );
                return new \WP_Error( 'bridge_rate_limited', __( 'Too many documentation requests right now. Please try again later.', 'mainwp' ) );
            }

            // A 504 has already exhausted the gateway deadline, so retrying doubles the wait.
            if ( ! $this->retried_transient && 200 !== $code && 504 !== $code
                && ( $code >= 500 || ( ! empty( $body_error ) && false !== strpos( $body_error, 'Invalid response' ) ) )
            ) {
                $this->retried_transient = true;
                MainWP_Logger::instance()->debug( 'Bridge transient error (HTTP ' . $code . '), retrying once.' );
                return $this->request( $message, $context );
            }

            if ( 503 === $code ) {
                $error_msg = ! empty( $body_error ) ? $body_error : __( 'The documentation service is temporarily unavailable. Please try again later.', 'mainwp' );
                MainWP_Logger::instance()->debug( 'Assistant Bridge unavailable (503): ' . $error_msg );
                return new \WP_Error( 'bridge_unavailable', $error_msg );
            }

            // Successful responses must return a valid JSON object payload.
            if ( 200 === $code && ! $decoded_array ) {
                MainWP_Logger::instance()->debug( 'Assistant Bridge returned invalid JSON: ' . json_last_error_msg() );
                return new \WP_Error( 'bridge_invalid_response', __( 'Invalid response from the documentation service.', 'mainwp' ) );
            }

            // Handle other non-200 responses.
            if ( 200 !== $code ) {
                MainWP_Logger::instance()->debug(
                    sprintf(
                        'Bridge docs error — URL: %s, HTTP %d, body: %s',
                        trailingslashit( $bridge_url ) . 'assistant/chat',
                        $code,
                        sprintf( '[redacted body_length=%d decoded_json=%s body_error=%s]', strlen( $raw_body ), $decoded_array ? 'yes' : 'no', ! empty( $body_error ) ? 'yes' : 'no' )
                    )
                );

                if ( ! empty( $body_error ) ) {
                    // Upstream error text can name the provider, the model, or an internal path, so it
                    // only reaches the screen on a debug install. Everyone else gets the generic string.
                    $error_msg = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
                        ? sprintf( __( 'Docs service error: %s', 'mainwp' ), $body_error )
                        : __( 'The documentation service encountered a temporary issue. Please try again.', 'mainwp' );
                } else {
                    $error_msg = __( 'The documentation service encountered a temporary issue. Please try again.', 'mainwp' );
                }

                return new \WP_Error( 'bridge_error', $error_msg );
            }

            return $body;
        } finally {
            --$this->request_depth;
        }
    }

    /**
     * Normalize a URL per Bridge specification.
     *
     * Strips query, hash, and trailing slash. Lowercases scheme and host.
     *
     * @param string $url URL to normalize.
     *
     * @return string Normalized URL.
     */
    public function normalize_url( $url ) {
        $parsed = wp_parse_url( $url );

        $scheme       = strtolower( $parsed['scheme'] ?? 'https' );
        $host         = strtolower( $parsed['host'] ?? '' );
        $parsed_port  = isset( $parsed['port'] ) ? (int) $parsed['port'] : 0;
        $default_port = 'http' === $scheme ? 80 : 443;
        $port         = ( $parsed_port > 0 && $parsed_port !== $default_port ) ? ':' . $parsed_port : '';
        $path         = rtrim( $parsed['path'] ?? '', '/' );

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Validate a Bridge base URL, requiring HTTPS for non-loopback hosts.
     *
     * Every bridge request carries the bearer token, and registration returns
     * it, so a plain-http URL would expose the token in transit. Loopback
     * hosts stay allowed over http for local bridge development.
     *
     * @param string $url Bridge base URL.
     *
     * @return bool True when the URL is safe to send credentials to.
     */
    public function is_allowed_bridge_url( $url ) {
        $parsed = wp_parse_url( $url );

        if ( ! is_array( $parsed ) ) {
            return false;
        }

        $scheme = strtolower( $parsed['scheme'] ?? '' );
        $host   = strtolower( $parsed['host'] ?? '' );

        if ( '' === $host ) {
            return false;
        }

        if ( 'https' === $scheme ) {
            return true;
        }

        return 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
    }

    /**
     * Extract a safe bridge error message from JSON or raw body text.
     *
     * @param array  $body     Decoded JSON response body.
     * @param string $raw_body Raw response body.
     *
     * @return string
     */
    private function extract_bridge_error_message( $body, $raw_body ) {
        if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
            return sanitize_text_field( $body['error'] );
        }

        $body_text = trim( wp_strip_all_tags( (string) $raw_body ) );
        if ( '' === $body_text ) {
            return '';
        }

        return sanitize_text_field( substr( $body_text, 0, 200 ) );
    }

    /**
     * Register (or re-register) this site with the Assistant Bridge.
     *
     * Posts to the bridge /assistant/register endpoint and stores the returned
     * bearer token and UUID in encrypted storage.
     *
     * @param string $bridge_url Bridge base URL.
     *
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function register( $bridge_url ) {
        if ( ! MainWP_Assistant_Policies::instance()->has_consent() ) {
            return new \WP_Error( 'bridge_no_consent', __( 'Assistant consent is required before connecting to the documentation service.', 'mainwp' ) );
        }

        if ( ! $this->is_allowed_bridge_url( $bridge_url ) ) {
            return new \WP_Error( 'bridge_insecure_url', __( 'The documentation service URL must use HTTPS.', 'mainwp' ) );
        }

        // Respect backoff from a previous 429 on the registration endpoint.
        if ( get_transient( 'mainwp_bridge_register_backoff' ) ) {
            return new \WP_Error( 'bridge_registration_throttled', __( 'Registration is rate-limited. Please try again later.', 'mainwp' ) );
        }

        $normalized_site_url = $this->normalize_url( site_url() );

        $response = wp_remote_post(
            esc_url_raw( trailingslashit( $bridge_url ) . 'assistant/register' ),
            array(
                'headers'     => array( 'Content-Type' => 'application/json' ),
                'body'        => wp_json_encode( array( 'site_url' => $normalized_site_url ) ),
                'timeout'     => 10,
                'redirection' => self::REDIRECTION,
                'sslverify'   => true,
            )
        );

        if ( is_wp_error( $response ) ) {
            MainWP_Logger::instance()->debug( 'Assistant Bridge re-registration failed: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 429 === $code ) {
            set_transient( 'mainwp_bridge_register_backoff', true, 15 * MINUTE_IN_SECONDS );
            MainWP_Logger::instance()->debug( 'Assistant Bridge registration rate-limited (429).' );
            return new \WP_Error( 'bridge_registration_throttled', __( 'Registration is rate-limited. Please try again later.', 'mainwp' ) );
        }

        if ( 200 !== $code ) {
            $err_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $err_msg  = isset( $err_body['error'] ) ? sanitize_text_field( $err_body['error'] ) : 'HTTP ' . $code;
            MainWP_Logger::instance()->debug( 'Assistant Bridge re-registration returned ' . $code . ': ' . $err_msg );
            return new \WP_Error( 'bridge_registration_failed', $err_msg );
        }

            $reg_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $reg_body ) || empty( $reg_body ) ) {
            MainWP_Logger::instance()->debug( 'Assistant Bridge re-registration returned malformed JSON data.' );
            return new \WP_Error( 'bridge_registration_failed', __( 'Documentation service registration returned malformed data.', 'mainwp' ) );
        }

        if ( empty( $reg_body['uuid'] ) || empty( $reg_body['token'] ) ) {
            MainWP_Logger::instance()->debug( 'Assistant Bridge re-registration returned incomplete data.' );
            return new \WP_Error( 'bridge_registration_failed', __( 'Documentation service registration returned incomplete data.', 'mainwp' ) );
        }

        MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', sanitize_text_field( $reg_body['token'] ) );
        update_option( 'mainwp_assistant_bridge_uuid', sanitize_text_field( $reg_body['uuid'] ) );

        MainWP_Logger::instance()->debug( 'Assistant Bridge re-registration succeeded.' );

        return true;
    }

    /**
     * Rate an existing Docs answer using the installation that created it.
     *
     * @param string      $conversation_id Local conversation ID.
     * @param string      $message_id      Upstream answer ID from saved metadata.
     * @param string|null $rating          Positive, negative, or cleared.
     * @return true|\WP_Error
     */
    public function feedback( $conversation_id, $message_id, $rating ) {
        $error = new \WP_Error( 'feedback_failed', __( 'Could not save feedback. Please try again.', 'mainwp' ) );
        if ( ! MainWP_Assistant_Policies::instance()->has_consent() || ! MainWP_Assistant_Policies::instance()->is_assistant_enabled() ) {
            return $error;
        }
        $bridge_url = defined( 'MAINWP_ASSISTANT_BRIDGE_URL' ) ? MAINWP_ASSISTANT_BRIDGE_URL : 'https://ai-bridge.fly.dev';
        $token      = MainWP_Keys_Manager::instance()->get_keys_value( 'mainwp_assistant_bridge_token', '' );
        if ( ! $this->is_allowed_bridge_url( $bridge_url ) || empty( $token ) || ! in_array( $rating, array( 'positive', 'negative', null ), true ) ) {
            return $error;
        }
        // Re-registering would change the installation and lose ownership of this answer.
        $response = wp_remote_post(
            rtrim( $bridge_url, '/' ) . '/assistant/feedback',
            array(
                'timeout'     => 20,
                'redirection' => self::REDIRECTION,
                'headers'     => array(
                    'Content-Type'      => 'application/json',
                    'Authorization'     => 'Bearer ' . $token,
                    'X-Chat-Message-Id' => wp_generate_uuid4(),
                ),
                'body'        => wp_json_encode(
                    array(
                        'conversation_id' => (string) $conversation_id,
                        'message_id'      => $message_id,
                        'feedback'        => $rating,
                    )
                ),
            )
        );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return $error;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) && isset( $body['success'] ) && true === $body['success'] ? true : $error;
    }

    /**
     * Send a support request without changing the installation's registration.
     *
     * @param string $email      Reply address.
     * @param string $note       Customer's note.
     * @param array  $transcript Stored user and assistant messages.
     * @param array  $context    Dashboard, WordPress and PHP versions.
     * @return true|\WP_Error
     */
    public function escalate( $email, $note, $transcript, $context ) {
        $error = new \WP_Error( 'support_request_failed' );
        if ( ! MainWP_Assistant_Policies::instance()->has_consent() || ! MainWP_Assistant_Policies::instance()->is_assistant_enabled() ) {
            return $error;
        }
        $bridge_url = defined( 'MAINWP_ASSISTANT_BRIDGE_URL' ) ? MAINWP_ASSISTANT_BRIDGE_URL : 'https://ai-bridge.fly.dev';
        $token      = MainWP_Keys_Manager::instance()->get_keys_value( 'mainwp_assistant_bridge_token', '' );
        if ( ! $this->is_allowed_bridge_url( $bridge_url ) || empty( $token ) ) {
            return $error;
        }
        $response = wp_remote_post(
            rtrim( $bridge_url, '/' ) . '/assistant/escalate',
            array(
                'timeout'     => 20,
                'redirection' => self::REDIRECTION,
                'headers'     => array(
                    'Content-Type'      => 'application/json',
                    'Authorization'     => 'Bearer ' . $token,
                    'X-Chat-Message-Id' => wp_generate_uuid4(),
                ),
                'body'        => wp_json_encode(
                    array(
                        'email'      => $email,
                        'note'       => $note,
                        'transcript' => $transcript,
                        'context'    => $context,
                    )
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $error;
        }
        $status = wp_remote_retrieve_response_code( $response );
        if ( 503 === $status ) {
            return new \WP_Error( 'support_not_configured' );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return 200 === $status && is_array( $body ) && isset( $body['success'] ) && true === $body['success'] ? true : $error;
    }

    /**
     * Normalize Bridge response to the legacy schema expected by frontend JS.
     *
     * The frontend JS expects:
     * - response.data.response (string): The assistant's text response
     * - response.data.sources (array): Array of source citations
     *
     * @param array $response The raw response from Bridge.
     *
     * @return array Normalized response with text, sources, mode, and truncation metadata.
     */
    public function normalize_response( $response ) {
        // The Bridge is versioned independently of the Dashboard and has renamed this field across
        // deployments, so every name it has shipped is accepted rather than pinning one.
        $text_fields = array( 'response', 'message', 'answer', 'text', 'content', 'reply' );
        $text        = '';

        foreach ( $text_fields as $field ) {
            if ( isset( $response[ $field ] ) && is_string( $response[ $field ] ) ) {
                $text = $response[ $field ];
                break;
            }
        }

        // Same reason as the text field above: the citation key has changed names between Bridge releases.
        $source_fields = array( 'sources', 'citations', 'references', 'links' );
        $sources       = array();

        foreach ( $source_fields as $field ) {
            if ( isset( $response[ $field ] ) && is_array( $response[ $field ] ) ) {
                $sources = $response[ $field ];
                break;
            }
        }

        $truncated        = isset( $response['truncated'] ) && true === $response['truncated'];
        $truncated_fields = array();

        if ( $truncated && isset( $response['truncated_fields'] ) && is_array( $response['truncated_fields'] ) ) {
            foreach ( $response['truncated_fields'] as $field ) {
                if ( in_array( $field, array( 'answer', 'sources' ), true ) && ! in_array( $field, $truncated_fields, true ) ) {
                    $truncated_fields[] = $field;
                }
            }
        }

        $normalized = array(
            'response'         => $text,
            'sources'          => $sources,
            'mode'             => 'ask', // Flag for UI to show docs mode indicator.
            'truncated'        => $truncated,
            'truncated_fields' => $truncated_fields,
        );
        if ( isset( $response['feedback_message_id'] ) && is_string( $response['feedback_message_id'] ) && preg_match( '/\A[A-Za-z0-9_-]{1,200}\z/', $response['feedback_message_id'] ) ) {
            $normalized['feedback_message_id'] = $response['feedback_message_id'];
        }
        return $normalized;
    }
}
