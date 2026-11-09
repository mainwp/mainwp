<?php
/**
 * Tests for provider-failure mapping and the transports that relay it.
 *
 * Core reports every provider failure as one of a handful of generic prompt_*
 * codes with the HTTP status buried in the error data, so the chat panel
 * cannot tell a rate limit from a rejected key. MainWP_Assistant rewrites the
 * ones a user can act on and hands the poll, SSE, and check-provider surfaces
 * the advisory state that goes with them.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use MainWP\Dashboard\MainWP_Assistant_SSE;

/**
 * Testable subclass that returns a canned chat outcome without any AI call.
 *
 * Mirrors Testable_Async_Assistant in test-assistant-chat.php, kept local so
 * this file runs on its own.
 */
class Testable_Rate_Limit_Assistant extends MainWP_Assistant {

    /**
     * Canned execute_ai_chat() outcome.
     *
     * @var array|\WP_Error
     */
    public $ai_chat_response = null;

    /**
     * Override: skip the AI Client entirely and return the canned outcome.
     *
     * @param string $message         User message.
     * @param int    $conversation_id Conversation ID.
     * @param array  $context         Request context.
     * @param int    $regenerate      Bound assistant answer ID (0 for a plain send).
     * @param int    $user_id         User ID.
     * @param bool   $show_disclosure Whether to show the disclosure banner.
     *
     * @return array|\WP_Error
     */
    protected function execute_ai_chat( $message, $conversation_id, $context, $regenerate, $user_id, $show_disclosure = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Matches parent signature.
        return $this->ai_chat_response;
    }
}

/**
 * Testable subclass whose generate call fires core's before-generate event
 * for a chosen provider, then fails with a canned error.
 *
 * The smallest fixture that exercises the real listener registration around
 * the generate call: the real generate_text_result() would need HTTP. The
 * model is built from the registered provider class directly, because
 * getProviderModel() looks the id up through the provider's list-models API
 * and that is HTTP too. The event carries the builder's own message objects,
 * as core's PromptBuilder passes them, so the listener's turn check sees
 * what production sees.
 */
class Testable_Event_Firing_Assistant extends MainWP_Assistant {

    /**
     * Provider whose model the fake generate call resolves.
     *
     * @var string
     */
    public $event_provider_id = '';

    /**
     * Model id the fake generate call resolves.
     *
     * @var string
     */
    public $event_model_id = '';

    /**
     * Provider of a nested generation another plugin runs inside this call,
     * fired before this turn's own event reaches the listener. Empty for none.
     *
     * @var string
     */
    public $nested_provider_id = '';

    /**
     * Where the nested generation runs: 'foreign' (its own messages, same
     * hook depth), 'prevent_prompt' (inside that filter, reusing this turn's
     * messages), or 'earlier_listener' (an earlier callback on the event,
     * reusing the event's messages).
     *
     * @var string
     */
    public $nested_mode = 'foreign';

    /**
     * Canned generate_ai_result() outcome.
     *
     * @var \WP_Error|null
     */
    public $canned_result = null;

    /**
     * Override: fire the before-generate event as core would, then fail.
     *
     * @param object $builder Configured prompt builder.
     *
     * @return \WP_Error|null
     */
    protected function generate_ai_result( $builder ) {
        $wrapped = new \ReflectionProperty( $builder, 'builder' );
        $wrapped->setAccessible( true );
        $inner    = $wrapped->getValue( $builder );
        $property = new \ReflectionProperty( $inner, 'messages' );
        $property->setAccessible( true );
        $messages = $property->getValue( $inner );

        $nested_listener = null;

        if ( '' !== $this->nested_provider_id ) {
            $nested_model = $this->make_model( $this->nested_provider_id );

            if ( 'foreign' === $this->nested_mode ) {
                $foreign = array(
                    new \WordPress\AiClient\Messages\DTO\UserMessage(
                        array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'Another plugin prompt' ) )
                    ),
                );
                do_action( 'wp_ai_client_before_generate_result', new \WordPress\AiClient\Events\BeforeGenerateResultEvent( $foreign, $nested_model, null ) );
            } elseif ( 'prevent_prompt' === $this->nested_mode ) {
                $nested_filter = function ( $prevent ) use ( $messages, $nested_model ) {
                    do_action( 'wp_ai_client_before_generate_result', new \WordPress\AiClient\Events\BeforeGenerateResultEvent( $messages, $nested_model, null ) );
                    return $prevent;
                };
                add_filter( 'wp_ai_client_prevent_prompt', $nested_filter );
                apply_filters( 'wp_ai_client_prevent_prompt', false );
                remove_filter( 'wp_ai_client_prevent_prompt', $nested_filter );
            } elseif ( 'earlier_listener' === $this->nested_mode ) {
                $nested_listener = function ( $event ) use ( $nested_model, &$nested_listener ) {
                    remove_action( 'wp_ai_client_before_generate_result', $nested_listener, 5 );
                    do_action( 'wp_ai_client_before_generate_result', new \WordPress\AiClient\Events\BeforeGenerateResultEvent( $event->getMessages(), $nested_model, null ) );
                };
                add_action( 'wp_ai_client_before_generate_result', $nested_listener, 5 );
            }
        }

        do_action( 'wp_ai_client_before_generate_result', new \WordPress\AiClient\Events\BeforeGenerateResultEvent( $messages, $this->make_model( $this->event_provider_id ), null ) );

        if ( null !== $nested_listener ) {
            remove_action( 'wp_ai_client_before_generate_result', $nested_listener, 5 );
        }

        return $this->canned_result;
    }

    /**
     * Build a text model for a registered provider without HTTP.
     *
     * @param string $provider_id Registered provider id.
     *
     * @return \WordPress\AiClient\Providers\Models\Contracts\ModelInterface
     */
    private function make_model( string $provider_id ) {
        $class_name = \WordPress\AiClient\AiClient::defaultRegistry()->getProviderClassName( $provider_id );
        $metadata   = new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata(
            $this->event_model_id,
            $this->event_model_id,
            array( \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ),
            array()
        );

        $create_model = new \ReflectionMethod( $class_name, 'createModel' );
        $create_model->setAccessible( true );

        return $create_model->invoke( null, $metadata, $class_name::metadata() );
    }
}

/**
 * Testable subclass that runs the real chat path but lets core's own prompt
 * builder decide the outcome, with every HTTP request refused.
 *
 * With no credentialed connector, core finds no model before any request is
 * sent and returns its real "No models found" error, so the test exercises
 * the text core actually produces rather than a copy of it.
 */
class Testable_Real_Builder_Assistant extends MainWP_Assistant {

    /**
     * Override: call core with outbound HTTP blocked.
     *
     * @param object $builder Configured prompt builder.
     *
     * @return object|\WP_Error
     */
    protected function generate_ai_result( $builder ) {
        $block = function () {
            return new \WP_Error( 'http_blocked', 'Tests do not send provider requests.' );
        };
        add_filter( 'pre_http_request', $block );

        try {
            return parent::generate_ai_result( $builder );
        } finally {
            remove_filter( 'pre_http_request', $block );
        }
    }
}

/**
 * Class Test_Assistant_Rate_Limit
 */
class Test_Assistant_Rate_Limit extends MainWP_Assistant_Test_Case {

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
     * Assistant singleton replaced during a test, restored in tearDown.
     *
     * @var MainWP_Assistant|null
     */
    protected $previous_assistant = null;

    /**
     * Whether the singleton was swapped and needs restoring.
     *
     * @var bool
     */
    protected $assistant_swapped = false;

    /**
     * Cooldown transients seeded or written during a test.
     *
     * @var array
     */
    protected $seeded_cooldowns = array();

    /**
     * Chat job transients seeded during a test.
     *
     * @var array
     */
    protected $seeded_jobs = array();

    /**
     * Whether the second AI connector was registered by a test.
     *
     * @var bool
     */
    protected $second_connector_registered = false;

    /**
     * Connector ID of the stub provider used for multi-provider attribution.
     *
     * @var string
     */
    const SECOND_CONNECTOR_ID = 'mainwp_test_provider';

    /**
     * Key option of the stub provider connector.
     *
     * @var string
     */
    const SECOND_SETTING_NAME = 'connectors_ai_mainwp_test_provider_api_key';

    /**
     * Clean up singleton swaps and seeded transients.
     *
     * @return void
     */
    public function tearDown(): void {
        if ( $this->assistant_swapped ) {
            $this->swap_assistant_instance( $this->previous_assistant );
            $this->assistant_swapped = false;
        }

        foreach ( $this->seeded_cooldowns as $provider_id ) {
            delete_transient( 'mainwp_assistant_provider_cooldown_' . $provider_id );
        }

        foreach ( $this->seeded_jobs as $job_id ) {
            delete_transient( 'mainwp_chat_job_' . $job_id );
            delete_option( 'mainwp_chat_job_lock_' . $job_id );
        }

        if ( $this->second_connector_registered ) {
            \WP_Connector_Registry::get_instance()->unregister( self::SECOND_CONNECTOR_ID );
            $this->second_connector_registered = false;
        }

        delete_option( self::SECOND_SETTING_NAME );
        delete_option( 'connectors_ai_google_api_key' );

        $this->seeded_cooldowns = array();
        $this->seeded_jobs      = array();

        parent::tearDown();
    }

    // =========================================================================
    // HARNESS
    // =========================================================================

    /**
     * Invoke the private mapper.
     *
     * @param \WP_Error $error       Provider error.
     * @param string    $provider_id Provider resolved for the turn.
     *
     * @return \WP_Error
     */
    private function map_error( \WP_Error $error, string $provider_id = 'google' ) {
        $instance = MainWP_Assistant::instance();
        $method   = new \ReflectionMethod( $instance, 'map_provider_wp_error' );
        $method->setAccessible( true );

        $this->seeded_cooldowns[] = $provider_id;

        return $method->invoke( $instance, $error, $provider_id );
    }

    /**
     * Build a WP_Error shaped like core's exception_to_wp_error() output.
     *
     * @param string $code    Core error code.
     * @param int    $status  HTTP status core recorded.
     * @param string $message Provider message.
     *
     * @return \WP_Error
     */
    private function core_error( string $code, int $status, string $message ) {
        return new \WP_Error(
            $code,
            $message,
            array(
                'status'          => $status,
                'exception_class' => 'WordPress\\AiClient\\Providers\\Http\\Exception\\ClientException',
            )
        );
    }

    /**
     * Replace the assistant singleton.
     *
     * @param MainWP_Assistant|null $instance Replacement instance.
     *
     * @return MainWP_Assistant|null Previous instance.
     */
    private function swap_assistant_instance( $instance ) {
        $reflection = new \ReflectionProperty( MainWP_Assistant::class, 'instance' );
        $reflection->setAccessible( true );
        $previous = $reflection->getValue();
        $reflection->setValue( null, $instance );

        return $previous;
    }

    /**
     * Seed a chat-job transient in a terminal failed state.
     *
     * @param string $job_id    Job ID (without the transient prefix).
     * @param int    $user_id   Owning user ID.
     * @param array  $advisories Extra advisory keys to store beside the error.
     *
     * @return void
     */
    private function seed_failed_job( string $job_id, int $user_id, array $advisories = array() ): void {
        $this->seeded_jobs[] = $job_id;

        set_transient(
            'mainwp_chat_job_' . $job_id,
            array_merge(
                array(
                    'status'     => 'failed',
                    'user_id'    => $user_id,
                    'error'      => 'Too Many Requests (429) - Quota exceeded.',
                    'error_code' => 'provider_rate_limited',
                ),
                $advisories
            ),
            300
        );
    }

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
     * Dispatch a nonce-signed request to an assistant AJAX handler.
     *
     * @param string $method_name Method name on MainWP_Assistant.
     * @param string $action      Nonce action name.
     * @param array  $request     POST payload.
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
     * Run the SSE loop for one job and return everything it wrote.
     *
     * @param string $job_id Job ID.
     *
     * @return string Raw SSE output.
     */
    private function run_sse_loop_for( string $job_id ): string {
        $instance = MainWP_Assistant_SSE::instance();
        $method   = new \ReflectionMethod( $instance, 'run_sse_loop' );
        $method->setAccessible( true );

        ob_start();
        $method->invoke( $instance, $job_id );

        return (string) ob_get_clean();
    }

    /**
     * Connector IDs the assistant currently counts as credentialed.
     *
     * @return array
     */
    private function credentialed_provider_ids(): array {
        $method = new \ReflectionMethod( MainWP_Assistant::class, 'credentialed_provider_ids' );
        $method->setAccessible( true );

        return (array) $method->invoke( null );
    }

    /**
     * Provider this turn would be attributed to before any result comes back.
     *
     * @return string
     */
    private function turn_provider_for_a_fresh_turn(): string {
        $instance = MainWP_Assistant::instance();

        $method = new \ReflectionMethod( $instance, 'build_prompt_builder' );
        $method->setAccessible( true );
        $method->invoke( $instance, array(), array(), null );

        $property = new \ReflectionProperty( MainWP_Assistant::class, 'turn_provider_id' );
        $property->setAccessible( true );

        return (string) $property->getValue( $instance );
    }

    /**
     * Skip unless this harness has the AI Client and a registered google provider.
     *
     * @return void
     */
    private function require_ai_provider_harness(): void {
        if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() || ! class_exists( '\WP_Connector_Registry' ) ) {
            $this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
        }

        $connectors = wp_get_connectors();

        if ( ! isset( $connectors['google']['authentication']['setting_name'] ) || ! class_exists( Testable_Second_Ai_Provider::class ) ) {
            $this->markTestSkipped( 'No registered google AI provider on this harness.' );
        }
    }

    /**
     * Add a second credentialed AI connector backed by the stub provider.
     *
     * @return void
     */
    private function register_second_ai_connector(): void {
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();

        if ( ! $registry->hasProvider( self::SECOND_CONNECTOR_ID ) ) {
            $registry->registerProvider( Testable_Second_Ai_Provider::class );
        }

        $connectors = \WP_Connector_Registry::get_instance();

        if ( ! $connectors->is_registered( self::SECOND_CONNECTOR_ID ) ) {
            $connectors->register(
                self::SECOND_CONNECTOR_ID,
                array(
                    'name'           => 'MainWP Test Provider',
                    'type'           => 'ai_provider',
                    'authentication' => array(
                        'method'       => 'api_key',
                        'setting_name' => self::SECOND_SETTING_NAME,
                    ),
                )
            );

            $this->second_connector_registered = true;
        }

        update_option( self::SECOND_SETTING_NAME, 'test-second-provider-key' );
    }

    // =========================================================================
    // MAPPING
    // =========================================================================

    /**
     * A 429 becomes provider_rate_limited and carries the advisory cooldown.
     *
     * @return void
     */
    public function test_client_rate_limit_maps_to_provider_rate_limited() {
        $mapped = $this->map_error(
            $this->core_error( 'prompt_client_error', 429, 'Too Many Requests (429) - Quota exceeded.' ),
            'google'
        );

        $this->assertWPErrorWithCode( $mapped, 'provider_rate_limited' );

        $data = $mapped->get_error_data();
        $this->assertSame( 'google', $data['provider'], 'The turn provider must be attributed on the error.' );
        $this->assertSame( 60, $data['retry_after'], 'The default advisory cooldown is 60 seconds.' );
        $this->assertSame(
            'Too Many Requests (429) - Quota exceeded.',
            $data['provider_message'],
            'The provider text is what tells a user which cap they hit, so it must survive mapping.'
        );
    }

    /**
     * A Google 429 splits on what the provider's text names: a possible daily
     * free-tier quota gets its own code with no cooldown, because a countdown
     * cannot end it; every other 429, and any 429 from another or unknown
     * provider, keeps the advisory cooldown.
     *
     * @dataProvider data_429_shapes
     *
     * @param string $provider      Turn provider id handed to the mapper.
     * @param string $message       Provider message on the 429.
     * @param string $expected_code Mapped error code.
     * @param bool   $has_cooldown  Whether retry_after is expected.
     *
     * @return void
     */
    public function test_429_splits_daily_quota_from_rate_limit( string $provider, string $message, string $expected_code, bool $has_cooldown ) {
        $mapped = $this->map_error( $this->core_error( 'prompt_client_error', 429, $message ), $provider );

        $this->assertWPErrorWithCode( $mapped, $expected_code );

        $data = $mapped->get_error_data();
        $this->assertSame( $provider, $data['provider'] );
        $this->assertSame( $message, $data['provider_message'], 'The provider text must survive mapping.' );

        if ( $has_cooldown ) {
            $this->assertSame( 60, $data['retry_after'] );
            if ( '' !== $provider ) {
                $this->assertNotFalse( get_transient( 'mainwp_assistant_provider_cooldown_' . $provider ) );
            }
        } else {
            $this->assertArrayNotHasKey( 'retry_after', $data, 'A daily quota has no cooldown the bubble could count down.' );
            $this->assertFalse( get_transient( 'mainwp_assistant_provider_cooldown_' . $provider ), 'A daily quota must not cool the provider down.' );
        }
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public function data_429_shapes(): array {
        $drill_text = 'Too Many Requests (429) - Quota exceeded for metric: generate_content_free_tier_requests';

        return array(
            'daily quota metric (sprint-3 drill text)' => array(
                'google',
                $drill_text,
                'provider_daily_quota_exhausted',
                false,
            ),
            'daily quota in prose'                     => array(
                'google',
                'Too Many Requests (429) - You exceeded your current quota of 20 requests per day.',
                'provider_daily_quota_exhausted',
                false,
            ),
            'daily quota id'                           => array(
                'google',
                'Too Many Requests (429) - quotaId: GenerateRequestsPerDayPerProjectPerModel-FreeTier',
                'provider_daily_quota_exhausted',
                false,
            ),
            'per-minute 429 naming the same metric'    => array(
                'google',
                'Too Many Requests (429) - Quota exceeded for metric: generate_content_free_tier_requests, quotaId: GenerateRequestsPerMinutePerProjectPerModel-FreeTier',
                'provider_rate_limited',
                true,
            ),
            'per-minute 429'                           => array(
                'google',
                'Too Many Requests (429) - Rate limit exceeded: 15 requests per minute.',
                'provider_rate_limited',
                true,
            ),
            'bare 429'                                 => array(
                'google',
                'Too Many Requests (429)',
                'provider_rate_limited',
                true,
            ),
            'daily wording from another provider'      => array(
                'openai',
                'Too Many Requests (429) - Daily quota exceeded; retry after the daily reset.',
                'provider_rate_limited',
                true,
            ),
            'daily quota metric, provider unknown'     => array(
                '',
                $drill_text,
                'provider_rate_limited',
                true,
            ),
        );
    }

    /**
     * 401 and 403 are credential rejections.
     *
     * @dataProvider data_auth_statuses
     *
     * @param int $status HTTP status.
     *
     * @return void
     */
    public function test_client_auth_status_maps_to_key_rejected( int $status ) {
        $mapped = $this->map_error(
            $this->core_error( 'prompt_client_error', $status, 'Request had invalid authentication credentials.' )
        );

        $this->assertWPErrorWithCode( $mapped, 'provider_key_rejected' );
        $this->assertArrayNotHasKey( 'retry_after', $mapped->get_error_data(), 'Only a rate limit advertises a cooldown.' );
    }

    /**
     * Auth statuses.
     *
     * @return array
     */
    public function data_auth_statuses(): array {
        return array(
            '401' => array( 401 ),
            '403' => array( 403 ),
        );
    }

    /**
     * Google rejects a bad key with 400, so its own message shape is what
     * promotes a 400 to a credential rejection.
     *
     * @dataProvider data_invalid_key_messages
     *
     * @param string $message Provider message.
     *
     * @return void
     */
    public function test_google_invalid_key_400_maps_to_key_rejected( string $message ) {
        $mapped = $this->map_error( $this->core_error( 'prompt_client_error', 400, $message ) );

        $this->assertWPErrorWithCode( $mapped, 'provider_key_rejected' );
    }

    /**
     * Invalid-key message shapes Google returns.
     *
     * @return array
     */
    public function data_invalid_key_messages(): array {
        return array(
            'reason code' => array( 'Bad Request (400) - API key not valid. Please pass a valid API key. [API_KEY_INVALID]' ),
            'prose only'  => array( 'Bad Request (400) - API key not valid. Please pass a valid API key.' ),
        );
    }

    /**
     * Any other 400 is a request problem and must not be blamed on the key.
     *
     * @return void
     */
    public function test_generic_client_400_passes_through_unchanged() {
        $error  = $this->core_error( 'prompt_client_error', 400, 'Bad Request (400) - Invalid JSON payload received.' );
        $mapped = $this->map_error( $error );

        $this->assertSame( $error, $mapped, 'A generic 400 must reach the client untouched.' );
    }

    /**
     * Core's "no model found" failure means no provider is usable, so it maps
     * to setup advice and drops the SDK text instead of relaying it.
     *
     * @dataProvider data_no_model_messages
     *
     * @param string $message Core message.
     *
     * @return void
     */
    public function test_no_model_found_maps_to_no_usable_provider( string $message ) {
        $mapped = $this->map_error(
            new \WP_Error(
                'prompt_invalid_argument',
                $message,
                array(
                    'status'          => 400,
                    'exception_class' => 'WordPress\\AiClient\\Common\\Exception\\InvalidArgumentException',
                )
            )
        );

        $this->assertWPErrorWithCode( $mapped, 'no_usable_provider' );
        $this->assertSame(
            'Sites mode needs an AI provider with a working API key. Add or check one in Settings > Connectors, then try again.',
            $mapped->get_error_message()
        );
        $this->assertArrayNotHasKey( 'provider_message', $mapped->get_error_data(), 'The SDK text must not reach the bubble as a detail line.' );
        $this->assertArrayNotHasKey( 'retry_after', $mapped->get_error_data() );
    }

    /**
     * Both shapes of core's message: any provider, and a locked provider.
     *
     * @return array
     */
    public function data_no_model_messages(): array {
        return array(
            'any provider'    => array( 'No models found that support text_generation for this prompt.' ),
            'locked provider' => array( 'No models found for provider "google" that support text_generation for this prompt.' ),
        );
    }

    /**
     * Other invalid-argument failures are prompt problems, not setup ones.
     *
     * @return void
     */
    public function test_other_invalid_argument_passes_through_unchanged() {
        $error  = new \WP_Error( 'prompt_invalid_argument', 'The first message must be from a user role.', array( 'status' => 400 ) );
        $mapped = $this->map_error( $error );

        $this->assertSame( $error, $mapped );
    }

    /**
     * A provider SDK may echo the request URL or the key it was handed; both
     * the bubble message and provider_message leave the server scrubbed, on
     * the mapped and the pass-through path alike.
     *
     * @return void
     */
    public function test_secret_shapes_are_scrubbed_before_the_error_leaves_the_mapper() {
        $google_key = 'AIzaSyA1234567890abcdefghijklmnopqrstuv';
        $leaky      = 'Unauthorized (401) - Incorrect API key provided: sk-abcdefghijklmnopqrstuvwxyz for https://api.example.test/v1/models?key=AIzaSyLeak'
            . ' Google echoed ' . $google_key . ' and {"key":"' . $google_key . '"} Authorization: Bearer abc~defghijklmnop';

        $mapped = $this->map_error( $this->core_error( 'prompt_client_error', 401, $leaky ) );
        $this->assertSame( 'provider_key_rejected', $mapped->get_error_code() );
        foreach ( array( $mapped->get_error_message(), $mapped->get_error_data()['provider_message'] ) as $text ) {
            $this->assertStringNotContainsString( 'sk-abcdefghijklmnopqrstuvwxyz', $text );
            $this->assertStringNotContainsString( 'AIzaSyLeak', $text );
            $this->assertStringNotContainsString( $google_key, $text );
            $this->assertStringNotContainsString( 'abc~defghijklmnop', $text );
            $this->assertStringContainsString( '[redacted]', $text );
        }

        $passthrough = $this->map_error( $this->core_error( 'prompt_client_error', 400, 'Bad Request (400) - ' . $leaky ) );
        $this->assertSame( 'prompt_client_error', $passthrough->get_error_code(), 'Scrubbing must not change a pass-through code.' );
        $this->assertStringNotContainsString( 'sk-abcdefghijklmnopqrstuvwxyz', $passthrough->get_error_message() );
        $this->assertStringNotContainsString( 'AIzaSyLeak', $passthrough->get_error_message() );
    }

    /**
     * The scrub's input matrix: every secret shape a provider or child site
     * has been seen to echo must vanish, and the provider messages the panel
     * shows (and the mapper keys on) must survive verbatim.
     *
     * @dataProvider data_secret_shapes
     *
     * @param string $input  Text as a provider would send it.
     * @param string $secret The fragment that must not survive.
     * @param string $kept   Optional fragment that must survive next to it.
     *
     * @return void
     */
    public function test_secret_shapes_are_redacted( string $input, string $secret, string $kept = '' ) {
        $scrubbed = MainWP_Assistant::redact_secret_shapes( $input );

        $this->assertStringNotContainsString( $secret, $scrubbed );
        $this->assertStringContainsString( '[redacted]', $scrubbed );
        if ( '' !== $kept ) {
            $this->assertStringContainsString( $kept, $scrubbed, 'The diagnostic around the secret must survive.' );
        }
    }

    /**
     * @return array<string, array{string, string, 2?: string}>
     */
    public function data_secret_shapes() {
        $google_key = 'AIzaSyA1234567890abcdefghijklmnopqrstuv';

        return array(
            'bearer header'        => array( 'Authorization: Bearer abc~defghijklmnop', 'abc~defghijklmnop' ),
            'basic header'         => array( 'Authorization: Basic dXNlcjpwYXNz', 'dXNlcjpwYXNz' ),
            'unknown scheme'       => array( 'Authorization: ApiKey secret-value', 'secret-value' ),
            'header in json'       => array( '{"Authorization":"Token tok_123456789"}', 'tok_123456789' ),
            'bearer in prose'      => array( 'sent bearer eyJhbGciOiJIUzI1NiJ9 upstream', 'eyJhbGciOiJIUzI1NiJ9' ),
            'query api_key'        => array( 'api_key=abc123 rejected', 'abc123' ),
            'goog header'          => array( 'x-goog-api-key: ' . $google_key, $google_key ),
            'json password'        => array( '{"password":"hunter2"}', 'hunter2' ),
            'quoted with spaces'   => array( 'password="correct horse battery staple" rejected', 'horse battery' ),
            'escaped quote'        => array( '{"password":"abc\\"def"}', 'def' ),
            'single quoted'        => array( "secret='hunter two' rejected", 'two' ),
            'camel json key'       => array( '{"apiKey":"value123"}', 'value123' ),
            'camel token'          => array( 'accessToken=tok_abcdef rejected', 'tok_abcdef' ),
            'camel secret'         => array( 'clientSecret: s3cr3t-value', 's3cr3t' ),
            'attached prefix'      => array( 'apikey=secret-value rejected', 'secret-value', 'rejected' ),
            'attached token'       => array( 'accesstoken: tok_abcdef rejected', 'tok_abcdef' ),
            'passphrase'           => array( 'passphrase="open sesame" rejected', 'sesame' ),
            'escaped json'         => array( 'Validation failed for payload {\\"password\\":\\"hunter2\\"}', 'hunter2' ),
            'credential pair'      => array( 'Child error: credential=dXNlcjpwYXNzd29yZDEyMzQ1Njc4OTA=', 'dXNlcjpw' ),
            'signature scheme'     => array( 'Authorization: Signature credential="secret-value", signedHeaders="host"', 'secret-value' ),
            'github token'         => array( 'updater rejected ghp_1234567890abcdefghijklmnopqrstuvwxyz', 'ghp_1234567890' ),
            'slack token'          => array( 'rejected xoxb-123456789012-123456789012-abcdefghijklmnopqrstuvwx', 'xoxb-123456789012' ),
            'jwt'                  => array( 'rejected JWT eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJl', 'eyJzdWIiOiIxMjM0NTY3ODkwIn0' ),
            'pem block'            => array( "private_key=\"-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA\n-----END RSA PRIVATE KEY-----\"", 'MIIEowIBAAKCAQEA' ),
            'truncated pem'        => array( "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg", 'MIIEvQIBADANBg' ),
            'escaped url query'    => array( '{"url":"https:\\/\\/child.example.test\\/path?sig=c2lnbmVk"}', 'c2lnbmVk' ),
            'escaped url auth'     => array( '{"url":"https:\\/\\/user:pa55@child.example.test\\/wp-json"}', 'pa55' ),
            'escaped url in query' => array( '{"error":"fetch https:\\/\\/api.example\\/run?next=https:\\/\\/callback.example\\/done&opaque=secret failed"}', 'opaque=secret', 'failed' ),
            'escaped url in json'  => array( 'Child returned {\\"url\\":\\"https:\\/\\/child.example.test\\/wp-json?mainwpsignature=c2lnbmVk\\",\\"error\\":\\"invalid_signature\\"}', 'c2lnbmVk', 'invalid_signature' ),
            'unterminated quote'   => array( 'Provider error: password="correct horse battery', 'horse battery' ),
            'unterminated single'  => array( "Provider error: secret='correct horse battery", 'horse battery' ),
            'unterminated escaped' => array( 'payload {\\"password\\":\\"correct horse battery', 'horse battery' ),
            'lowercase bearer'     => array( 'Provider rejected Bearer abcdefghijklmnopqrstuvwxyzabcdef upstream', 'abcdefghijklmnopqrstuvwxyzabcdef', 'upstream' ),
            'uppercase bearer'     => array( 'Provider rejected Bearer ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEF upstream', 'ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEF', 'upstream' ),
            'upper-to-lower bearer' => array( 'Provider rejected Bearer ABCDEFGHIJKLMNOPQRSTuvwx upstream', 'ABCDEFGHIJKLMNOPQRSTuvwx', 'upstream' ),
            'single-quoted object' => array( "Provider rejected {'password':'hunter2','error':'invalid_credentials'}", 'hunter2', 'invalid_credentials' ),
            'url in single quotes' => array( "Child returned {'url':'https://child.example.test/wp-json?mainwpsignature=c2lnbmVk','error':'invalid_signature'}", 'c2lnbmVk', 'invalid_signature' ),
            'long quoted value'    => array( 'Provider failure: password="' . str_repeat( 'a', 15958 ) . '"; retry later', 'aaaaaaaa', '; retry later' ),
            'json bare key'        => array( '{"key":"' . $google_key . '"}', $google_key ),
            'openai key in prose'  => array( 'Incorrect API key provided: sk-abcdefghijklmnopqrstuvwxyz', 'sk-abcdefghijklmnopqrstuvwxyz' ),
            'google key in prose'  => array( 'Incorrect API key provided: ' . $google_key, $google_key ),
            'url query string'     => array( 'POST https://api.example.test/v1/models?key=' . $google_key . ' failed', $google_key ),
            'url basic auth'       => array( 'GET https://user:pa55@child.example.test/wp-json failed', 'pa55' ),
            'signed child url'     => array( 'HTTP 403 from https://child.example.test/?mainwpsignature=c2lnbmVk&uniqueid=abc', 'c2lnbmVk' ),
        );
    }

    /**
     * Bearer tokens need no digits or symbols to be credentials.
     */
    public function test_redact_secret_shapes_alphabetic_bearer_tokens() {
        $this->assertSame( 'Bearer [redacted]', MainWP_Assistant::redact_secret_shapes( 'Bearer abcdefghijklmnop' ) );
        $this->assertSame( 'bearer [redacted]', MainWP_Assistant::redact_secret_shapes( 'bearer ABCDEFGHIJKLMNOPQRSTUVWXYZ' ) );
        $this->assertSame( 'Bearer [redacted]', MainWP_Assistant::redact_secret_shapes( 'Bearer abcdefgh' ) );
    }

    /**
     * An ordinary short word after bearer must survive.
     */
    public function test_redact_secret_shapes_preserves_bearer_prose() {
        $text = 'the bearer of bad news';
        $this->assertSame( $text, MainWP_Assistant::redact_secret_shapes( $text ) );
    }

    /**
     * The compound-name prefix is bounded, so a long hyphenated diagnostic
     * (50 KB of it took seconds before the bound) scrubs in linear time.
     *
     * @return void
     */
    public function test_scrub_stays_linear_on_long_hyphenated_text() {
        $text  = str_repeat( 'x-', 50000 ) . 'ordinary=value';
        $start = microtime( true );

        $scrubbed = MainWP_Assistant::redact_secret_shapes( $text );

        $this->assertLessThan( 1.0, microtime( true ) - $start );
        $this->assertSame( $text, $scrubbed );
    }

    /**
     * A long escaped query (100 KB of encoded slashes) scrubs without
     * exhausting the PCRE stack, so the diagnostic after the URL survives
     * instead of the whole message collapsing to the fallback.
     *
     * @dataProvider data_long_escaped_queries
     *
     * @param string $query Query-string filler.
     *
     * @return void
     */
    public function test_scrub_keeps_the_diagnostic_after_a_long_escaped_query( string $query ) {
        $text  = 'fetch https:\\/\\/child.example.test\\/wp-json?' . $query . ' failed';
        $start = microtime( true );

        $scrubbed = MainWP_Assistant::redact_secret_shapes( $text );

        $this->assertLessThan( 1.0, microtime( true ) - $start );
        $this->assertSame( 'fetch https:\\/\\/child.example.test\\/wp-json?[redacted] failed', $scrubbed );
    }

    /**
     * @return array<string, array{string}>
     */
    public function data_long_escaped_queries(): array {
        return array(
            'escaped slashes'          => array( str_repeat( '\\/', 50000 ) ),
            'escaped slashes and text' => array( str_repeat( 'x\\/', 40000 ) ),
        );
    }

    /**
     * @dataProvider data_plain_provider_messages
     *
     * @param string $message Provider message with nothing secret in it.
     *
     * @return void
     */
    public function test_plain_provider_messages_survive_the_scrub( string $message ) {
        $this->assertSame( $message, MainWP_Assistant::redact_secret_shapes( $message ) );
    }

    /**
     * @return array<string, array{string}>
     */
    public function data_plain_provider_messages() {
        return array(
            'daily quota'   => array( 'Too Many Requests (429) - Quota exceeded for metric: generate_content_free_tier_requests' ),
            'bad key'       => array( 'Bad Request (400) - API key not valid. Please pass a valid API key.' ),
            'unauthorized'  => array( 'Unauthorized (401)' ),
            'bad payload'   => array( 'Bad Request (400) - Invalid JSON payload received.' ),
            'retired model' => array( 'Not Found (404) - models/gemini-2.0-flash is not found for API version v1beta.' ),
            'field named like a secret' => array( 'Bad Request (400) - Invalid field keyboard: unsupported' ),
            'token count'   => array( 'Bad Request (400) - max_output_tokens: 99999 is out of range' ),
            'author field'  => array( 'Bad Request (400) - author: must be a string' ),
            'camel count'   => array( 'Bad Request (400) - maxOutputTokens: 99999 is out of range' ),
            'camel field'   => array( 'Bad Request (400) - Invalid field Keyboard: unsupported' ),
            'monkey field'  => array( 'Bad Request (400) - Invalid field monkey: unsupported' ),
            'basic prose'   => array( 'Unauthorized (401) - Basic authentication is required for this endpoint.' ),
            'basic title'   => array( 'Unauthorized (401) - Basic Authentication is required for this endpoint.' ),
            'token prose'   => array( 'Unauthorized (401) - Token expired. Reconnect the provider.' ),
            'bare header'   => array( "Bad Request (400)\nAuthorization:\nheader is required" ),
            'network'       => array( 'Network error occurred while sending POST request to https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent: cURL error 28' ),
        );
    }

    /**
     * A transport failure is a reachability problem.
     *
     * @return void
     */
    public function test_network_error_maps_to_provider_unreachable() {
        $mapped = $this->map_error(
            new \WP_Error( 'prompt_network_error', 'Could not resolve host.', array( 'status' => 503 ) )
        );

        $this->assertWPErrorWithCode( $mapped, 'provider_unreachable' );
        $this->assertSame( 'google', $mapped->get_error_data()['provider'] );
    }

    /**
     * A provider-side 5xx is an upstream outage.
     *
     * @return void
     */
    public function test_upstream_server_error_maps_to_provider_unavailable_upstream() {
        $mapped = $this->map_error(
            new \WP_Error( 'prompt_upstream_server_error', 'Internal Server Error (500)', array( 'status' => 500 ) )
        );

        $this->assertWPErrorWithCode( $mapped, 'provider_unavailable_upstream' );
    }

    /**
     * Assistant-owned codes must never be rewritten by provider mapping.
     *
     * @dataProvider data_pass_through_codes
     *
     * @param string $code Error code.
     *
     * @return void
     */
    public function test_unknown_codes_pass_through_unchanged( string $code ) {
        $error  = new \WP_Error( $code, 'Original message.', array( 'status' => 429 ) );
        $mapped = $this->map_error( $error );

        $this->assertSame( $error, $mapped, "The {$code} error must survive the mapper unchanged." );
        $this->assertArrayNotHasKey( 'retry_after', $mapped->get_error_data() );
    }

    /**
     * Codes owned outside the provider layer, seeded with a 429 status so a
     * status-only mapper would rewrite them.
     *
     * @return array
     */
    public function data_pass_through_codes(): array {
        return array(
            'no_provider'      => array( 'no_provider' ),
            'tool_loop_limit'  => array( 'tool_loop_limit' ),
            'assistant_stopped' => array( 'assistant_stopped' ),
            'token limit'      => array( 'prompt_token_limit_reached' ),
        );
    }

    /**
     * The cooldown is filterable.
     *
     * @return void
     */
    public function test_cooldown_filter_overrides_the_default() {
        add_filter( 'mainwp_assistant_provider_cooldown_seconds', array( $this, 'filter_cooldown_to_fifteen' ), 10, 2 );

        $mapped = $this->map_error( $this->core_error( 'prompt_client_error', 429, 'Too Many Requests (429)' ) );

        remove_filter( 'mainwp_assistant_provider_cooldown_seconds', array( $this, 'filter_cooldown_to_fifteen' ), 10 );

        $this->assertSame( 15, $mapped->get_error_data()['retry_after'] );
    }

    /**
     * Cooldown filter callback.
     *
     * @param int    $seconds     Default cooldown.
     * @param string $provider_id Provider the limit came from.
     *
     * @return int
     */
    public function filter_cooldown_to_fifteen( $seconds, $provider_id ) {
        $this->assertSame( 'google', $provider_id, 'The filter must receive the attributed provider.' );

        return 15;
    }

    /**
     * The SDK drops Google's RetryInfo before the message is built, so any
     * delay left in the text is not a machine-readable hint. Reading one would
     * hand the user a promise the provider never made.
     *
     * @return void
     */
    public function test_retry_after_is_never_read_from_the_provider_message() {
        $mapped = $this->map_error(
            $this->core_error(
                'prompt_client_error',
                429,
                'Too Many Requests (429) - Quota exceeded. Please retry in 42.7s (retryDelay: "42s").'
            )
        );

        $this->assertSame( 60, $mapped->get_error_data()['retry_after'], 'The advisory cooldown is fixed, never parsed.' );
    }

    /**
     * The cooldown is recorded per provider, so a limit on one key says
     * nothing about a dashboard that switches providers later.
     *
     * @return void
     */
    public function test_rate_limit_records_a_per_provider_cooldown_transient() {
        $before = time();

        $this->map_error( $this->core_error( 'prompt_client_error', 429, 'Too Many Requests (429)' ), 'google' );

        $stored = get_transient( 'mainwp_assistant_provider_cooldown_google' );

        $this->assertNotFalse( $stored, 'A 429 must record a cooldown for the provider that returned it.' );
        $this->assertGreaterThanOrEqual( $before + 60, (int) $stored );
        $this->assertFalse(
            get_transient( 'mainwp_assistant_provider_cooldown_openai' ),
            'A limit on one provider must not cool down another.'
        );
    }

    /**
     * With no provider resolved there is nothing to key a cooldown on, but the
     * error still maps so the bubble is right.
     *
     * @return void
     */
    public function test_unattributed_rate_limit_maps_without_a_cooldown_transient() {
        $mapped = $this->map_error( $this->core_error( 'prompt_client_error', 429, 'Too Many Requests (429)' ), '' );

        $this->assertWPErrorWithCode( $mapped, 'provider_rate_limited' );
        $this->assertSame( '', $mapped->get_error_data()['provider'] );
        $this->assertFalse( get_transient( 'mainwp_assistant_provider_cooldown_' ) );
    }

    /**
     * Provider text reaches a chat bubble, so it is stripped and capped first.
     *
     * @return void
     */
    public function test_provider_message_is_stripped_and_capped() {
        $mapped = $this->map_error(
            $this->core_error(
                'prompt_client_error',
                429,
                '<b onclick="alert(1)">Quota exceeded.</b> ' . str_repeat( 'x', 400 )
            )
        );

        $message = $mapped->get_error_data()['provider_message'];

        $this->assertStringNotContainsString( '<', $message, 'Markup must not survive into the bubble.' );
        $this->assertLessThanOrEqual( 300, strlen( $message ) );
    }

    // =========================================================================
    // TURN ATTRIBUTION
    // =========================================================================

    /**
     * With a single credentialed connector there is nothing else the turn
     * could have run on, so a failure before any result is attributable.
     *
     * @return void
     */
    public function test_turn_is_attributed_when_one_connector_is_credentialed() {
        $this->require_ai_provider_harness();

        update_option( 'connectors_ai_google_api_key', 'AIzaSyTestAttributionKey' );

        if ( array( 'google' ) !== $this->credentialed_provider_ids() ) {
            $this->markTestSkipped( 'This harness credentials connectors beyond google.' );
        }

        $this->assertSame( 'google', $this->turn_provider_for_a_fresh_turn() );
    }

    /**
     * Core resolves the serving provider lazily and the model preference can
     * send the turn to either key, so the id stays empty after
     * build_prompt_builder() until the before-generate event names the model
     * core resolved.
     *
     * @return void
     */
    public function test_turn_is_unattributed_at_build_time_when_several_connectors_are_credentialed() {
        $this->require_ai_provider_harness();

        update_option( 'connectors_ai_google_api_key', 'AIzaSyTestAttributionKey' );
        $this->register_second_ai_connector();

        $this->assertGreaterThan(
            1,
            count( $this->credentialed_provider_ids() ),
            'The scenario needs two credentialed connectors to say anything.'
        );

        $provider_id = $this->turn_provider_for_a_fresh_turn();

        $this->assertSame( '', $provider_id, 'A turn either key could have served names neither.' );
    }

    /**
     * With two keys credentialed, the before-generate event pins the turn to
     * the provider core resolved, so a Google daily-quota 429 is reported as
     * the daily quota and cools nothing down.
     *
     * @return void
     */
    public function test_multi_connector_first_turn_attributes_the_resolved_provider_before_the_429() {
        $result = $this->drive_multi_connector_turn_resolved_to( 'google' );

        $this->assertWPErrorWithCode( $result, 'provider_daily_quota_exhausted' );
        $this->assertSame( 'google', $result->get_error_data()['provider'], 'The turn must be pinned to the provider the event named.' );
        $this->assertFalse(
            get_transient( 'mainwp_assistant_provider_cooldown_google' ),
            'A daily quota must not cool the provider down.'
        );
        $this->assertFalse(
            get_transient( 'mainwp_assistant_provider_cooldown_' . self::SECOND_CONNECTOR_ID ),
            'A limit on one provider must not cool down another.'
        );
    }

    /**
     * The same daily-quota text from the other connector is not Google's, so
     * it keeps the rate-limit mapping and cools down only that connector.
     *
     * @return void
     */
    public function test_multi_connector_first_turn_resolved_to_the_second_connector_keeps_the_rate_limit() {
        $result = $this->drive_multi_connector_turn_resolved_to( self::SECOND_CONNECTOR_ID );

        $this->assertWPErrorWithCode( $result, 'provider_rate_limited' );
        $this->assertSame( self::SECOND_CONNECTOR_ID, $result->get_error_data()['provider'], 'The turn must be pinned to the provider the event named.' );
        $this->assertNotFalse(
            get_transient( 'mainwp_assistant_provider_cooldown_' . self::SECOND_CONNECTOR_ID ),
            'A rate limit must cool down the provider that returned it.'
        );
        $this->assertFalse(
            get_transient( 'mainwp_assistant_provider_cooldown_google' ),
            'A limit on one provider must not cool down another.'
        );
    }

    /**
     * Where another plugin can run a generation inside this turn's call.
     *
     * @return array
     */
    public function nested_generation_provider() {
        return array(
            'foreign messages at the same depth'       => array( 'foreign' ),
            'inside prevent_prompt with turn messages' => array( 'prevent_prompt' ),
            'earlier listener reusing event messages'  => array( 'earlier_listener' ),
        );
    }

    /**
     * A nested generation's event names a different provider and must not
     * pin the turn, or Google would be reported and the real provider never
     * cooled, whichever way it reaches the listener first.
     *
     * @dataProvider nested_generation_provider
     *
     * @param string $nested_mode Where the nested generation runs.
     *
     * @return void
     */
    public function test_nested_generation_event_does_not_pin_the_turn( string $nested_mode ) {
        $result = $this->drive_multi_connector_turn_resolved_to( self::SECOND_CONNECTOR_ID, 'google', $nested_mode );

        $this->assertWPErrorWithCode( $result, 'provider_rate_limited' );
        $this->assertSame( self::SECOND_CONNECTOR_ID, $result->get_error_data()['provider'], 'Only the event carrying this turn\'s messages may pin it.' );
        $this->assertNotFalse( get_transient( 'mainwp_assistant_provider_cooldown_' . self::SECOND_CONNECTOR_ID ) );
        $this->assertFalse( get_transient( 'mainwp_assistant_provider_cooldown_google' ), 'The nested provider did not return the limit.' );
    }

    /**
     * Run one fresh turn with two credentialed connectors, where the generate
     * call resolves $provider_id and fails with Google's daily-quota 429 text.
     * Also checks the attribution listener is gone afterwards without
     * touching listeners anything else registered on the hook.
     *
     * @param string $provider_id        Provider the before-generate event names.
     * @param string $nested_provider_id Provider of a nested generation fired first, or ''.
     * @param string $nested_mode        Where the nested generation runs.
     *
     * @return mixed core_chat_turn() outcome.
     */
    private function drive_multi_connector_turn_resolved_to( string $provider_id, string $nested_provider_id = '', string $nested_mode = 'foreign' ) {
        $this->require_ai_provider_harness();

        update_option( 'connectors_ai_google_api_key', 'AIzaSyTestAttributionKey' );
        $this->register_second_ai_connector();

        $this->assertGreaterThan(
            1,
            count( $this->credentialed_provider_ids() ),
            'The scenario needs two credentialed connectors to say anything.'
        );

        $this->seeded_cooldowns[] = 'google';
        $this->seeded_cooldowns[] = self::SECOND_CONNECTOR_ID;

        $user_id = $this->set_current_user_as_admin();

        $assistant                    = new Testable_Event_Firing_Assistant();
        $assistant->event_provider_id  = $provider_id;
        $assistant->nested_provider_id = $nested_provider_id;
        $assistant->nested_mode        = $nested_mode;
        $assistant->event_model_id     = 'gemini-3.6-flash';
        $assistant->canned_result     = $this->core_error(
            'prompt_client_error',
            429,
            'Too Many Requests (429) - Quota exceeded for metric: generate_content_free_tier_requests'
        );

        $this->previous_assistant = $this->swap_assistant_instance( $assistant );
        $this->assistant_swapped  = true;

        $method = new \ReflectionMethod( $assistant, 'core_chat_turn' );
        $method->setAccessible( true );

        $resolved         = null;
        $listeners_before = $this->count_before_generate_listeners();

        $result = $method->invokeArgs( $assistant, array( 'Hello', array(), array(), $user_id, &$resolved, false ) );

        $this->assertSame( $listeners_before, $this->count_before_generate_listeners(), 'The attribution listener must not outlive the turn.' );

        return $result;
    }

    /**
     * Count every callback on core's before-generate hook.
     *
     * @return int
     */
    private function count_before_generate_listeners(): int {
        global $wp_filter;

        if ( ! isset( $wp_filter['wp_ai_client_before_generate_result'] ) ) {
            return 0;
        }

        return array_sum( array_map( 'count', $wp_filter['wp_ai_client_before_generate_result']->callbacks ) );
    }

    // =========================================================================
    // JOB STORE AND POLL RELAY
    // =========================================================================

    /**
     * A failing job stores the advisory keys beside the error, because the
     * transports can only relay what the job transient holds.
     *
     * @return void
     */
    public function test_process_chat_job_stores_provider_advisories() {
        $user_id = $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $job_id = 'test-rate-limit-store-' . wp_generate_uuid4();
        $this->seeded_jobs[] = $job_id;

        set_transient(
            'mainwp_chat_job_' . $job_id,
            array(
                'status'          => 'queued',
                'user_id'         => $user_id,
                'message'         => 'Test rate limit message',
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

        $assistant                   = new Testable_Rate_Limit_Assistant();
        $assistant->ai_chat_response = new \WP_Error(
            'provider_rate_limited',
            'Too Many Requests (429) - Quota exceeded.',
            array(
                'status'           => 429,
                'provider'         => 'google',
                'retry_after'      => 60,
                'provider_message' => 'Too Many Requests (429) - Quota exceeded.',
            )
        );

        $assistant->process_chat_job( $job_id );

        $job_data = get_transient( 'mainwp_chat_job_' . $job_id );

        $this->assertSame( 'failed', $job_data['status'] );
        $this->assertSame( 'provider_rate_limited', $job_data['error_code'] );
        $this->assertSame( 60, $job_data['retry_after'] );
        $this->assertSame( 'google', $job_data['provider'] );
        $this->assertSame( 'Too Many Requests (429) - Quota exceeded.', $job_data['provider_message'] );
    }

    /**
     * A plain failure stores no advisory keys at all, so the bubble has
     * nothing to render a countdown from.
     *
     * @return void
     */
    public function test_process_chat_job_stores_no_advisories_for_a_plain_failure() {
        $user_id = $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $job_id = 'test-rate-limit-plain-' . wp_generate_uuid4();
        $this->seeded_jobs[] = $job_id;

        set_transient(
            'mainwp_chat_job_' . $job_id,
            array(
                'status'          => 'queued',
                'user_id'         => $user_id,
                'message'         => 'Test plain failure',
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

        $assistant                   = new Testable_Rate_Limit_Assistant();
        $assistant->ai_chat_response = new \WP_Error( 'no_provider', 'No AI connection is configured.' );

        $assistant->process_chat_job( $job_id );

        $job_data = get_transient( 'mainwp_chat_job_' . $job_id );

        $this->assertSame( 'no_provider', $job_data['error_code'] );
        $this->assertArrayNotHasKey( 'retry_after', $job_data );
        $this->assertArrayNotHasKey( 'provider_message', $job_data );
    }

    /**
     * A Sites mode job on a dashboard with no usable provider fails with the
     * setup code and message, built from the error core really returns.
     *
     * @return void
     */
    public function test_process_chat_job_without_a_usable_provider_stores_setup_advice() {
        if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() || ! function_exists( 'wp_ai_client_prompt' ) ) {
            $this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
        }

        if ( array() !== $this->credentialed_provider_ids() ) {
            $this->markTestSkipped( 'This harness has a credentialed AI connector.' );
        }

        $user_id = $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $job_id = 'test-no-usable-provider-' . wp_generate_uuid4();
        $this->seeded_jobs[] = $job_id;

        set_transient(
            'mainwp_chat_job_' . $job_id,
            array(
                'status'          => 'queued',
                'user_id'         => $user_id,
                'message'         => 'Which of my sites need updates?',
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

        ( new Testable_Real_Builder_Assistant() )->process_chat_job( $job_id );

        $job_data = get_transient( 'mainwp_chat_job_' . $job_id );

        $this->assertSame( 'failed', $job_data['status'] );
        $this->assertSame( 'no_usable_provider', $job_data['error_code'] );
        $this->assertSame(
            'Sites mode needs an AI provider with a working API key. Add or check one in Settings > Connectors, then try again.',
            $job_data['error']
        );
        $this->assertArrayNotHasKey( 'provider_message', $job_data );
    }

    /**
     * The poll endpoint relays the advisories to the browser.
     *
     * @return void
     */
    public function test_poll_relays_provider_advisories() {
        $user_id = $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $job_id = 'test-rate-limit-poll-' . wp_generate_uuid4();
        $this->seed_failed_job(
            $job_id,
            $user_id,
            array(
                'retry_after'      => 60,
                'provider'         => 'google',
                'provider_message' => 'Quota exceeded for requests per minute.',
            )
        );

        $response = $this->dispatch_assistant_ajax(
            'ajax_poll_chat_job',
            'mainwp_assistant_poll_chat_job',
            array( 'job_id' => $job_id )
        );

        $this->assertFalse( $response['success'] ?? true, 'A failed job polls as an error.' );
        $this->assertSame( 'provider_rate_limited', $response['data']['error_code'] );
        $this->assertSame( 60, $response['data']['retry_after'] );
        $this->assertSame( 'google', $response['data']['provider'] );
        $this->assertSame( 'Quota exceeded for requests per minute.', $response['data']['provider_message'] );
    }

    /**
     * A failure without advisories relays none, so the client never renders a
     * countdown from a stale key.
     *
     * @return void
     */
    public function test_poll_omits_advisories_when_the_job_has_none() {
        $user_id = $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $job_id = 'test-rate-limit-poll-plain-' . wp_generate_uuid4();
        $this->seed_failed_job( $job_id, $user_id );

        $response = $this->dispatch_assistant_ajax(
            'ajax_poll_chat_job',
            'mainwp_assistant_poll_chat_job',
            array( 'job_id' => $job_id )
        );

        $this->assertArrayNotHasKey( 'retry_after', $response['data'] );
        $this->assertArrayNotHasKey( 'provider', $response['data'] );
    }

    /**
     * The synchronous fallback runs when no worker ever claimed the job, so it
     * is the only surface that client sees and it relays the same advisories.
     *
     * @return void
     */
    public function test_fallback_relays_provider_advisories() {
        $user_id = $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $job_id = 'test-rate-limit-fallback-' . wp_generate_uuid4();
        $this->seed_failed_job(
            $job_id,
            $user_id,
            array(
                'retry_after'      => 60,
                'provider'         => 'google',
                'provider_message' => 'Quota exceeded for requests per minute.',
            )
        );

        $response = $this->dispatch_assistant_ajax(
            'ajax_process_chat_fallback',
            'mainwp_assistant_process_chat_fallback',
            array( 'job_id' => $job_id )
        );

        $this->assertFalse( $response['success'] ?? true, 'A failed job comes back as an error.' );
        $this->assertSame( 'provider_rate_limited', $response['data']['error_code'] );
        $this->assertSame( 60, $response['data']['retry_after'] );
        $this->assertSame( 'google', $response['data']['provider'] );
        $this->assertSame( 'Quota exceeded for requests per minute.', $response['data']['provider_message'] );
    }

    // =========================================================================
    // SSE RELAY
    // =========================================================================

    /**
     * The terminal-failure emitter relays the advisories. The browser opens SSE
     * before it ever polls, so this is the primary path, not a fallback.
     *
     * @return void
     */
    public function test_sse_failed_job_relays_provider_advisories() {
        $user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $job_id = 'test-rate-limit-sse-' . wp_generate_uuid4();
        $this->seed_failed_job(
            $job_id,
            $user_id,
            array(
                'retry_after'      => 60,
                'provider'         => 'google',
                'provider_message' => 'Quota exceeded for requests per minute.',
            )
        );

        $output = $this->run_sse_loop_for( $job_id );

        $this->assertStringContainsString( 'event: job_error', $output );
        $this->assertStringContainsString( '"retry_after":60', $output );
        $this->assertStringContainsString( '"provider":"google"', $output );
        $this->assertStringContainsString( 'Quota exceeded for requests per minute.', $output );
    }

    /**
     * The stream also runs queued jobs itself, and that second failure emitter
     * has to relay the same advisories.
     *
     * @return void
     */
    public function test_sse_queued_job_failure_relays_provider_advisories() {
        $user_id = $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $job_id = 'test-rate-limit-sse-queued-' . wp_generate_uuid4();
        $this->seeded_jobs[] = $job_id;

        set_transient(
            'mainwp_chat_job_' . $job_id,
            array(
                'status'          => 'queued',
                'user_id'         => $user_id,
                'message'         => 'Test queued rate limit',
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

        $assistant                   = new Testable_Rate_Limit_Assistant();
        $assistant->ai_chat_response = new \WP_Error(
            'provider_rate_limited',
            'Too Many Requests (429) - Quota exceeded.',
            array(
                'status'           => 429,
                'provider'         => 'google',
                'retry_after'      => 60,
                'provider_message' => 'Too Many Requests (429) - Quota exceeded.',
            )
        );

        // The stream runs a queued job through the assistant singleton.
        $this->previous_assistant = $this->swap_assistant_instance( $assistant );
        $this->assistant_swapped  = true;

        $output = $this->run_sse_loop_for( $job_id );

        $this->assertStringContainsString( 'event: job_error', $output );
        $this->assertStringContainsString( '"retry_after":60', $output );
        $this->assertStringContainsString( '"provider":"google"', $output );
    }

    // =========================================================================
    // CHECK PROVIDER
    // =========================================================================

    /**
     * The panel needs a machine-readable reason and a wizard gate, not just the
     * sentence it already had.
     *
     * @return void
     */
    public function test_check_provider_reports_reason_code_and_wizard_availability() {
        $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        add_filter( 'mainwp_assistant_agent_mode_available', '__return_false' );

        $response = $this->dispatch_assistant_ajax( 'handle_check_provider', MainWP_Assistant::AJAX_CHECK_PROVIDER );

        remove_filter( 'mainwp_assistant_agent_mode_available', '__return_false' );

        $wp_version_ok = function_exists( 'wp_supports_ai' ) && function_exists( 'wp_ai_client_prompt' );

        $this->assertTrue( $response['success'] ?? false );
        $this->assertSame(
            $wp_version_ok ? 'no_provider' : 'wp_version',
            $response['data']['reason_code'],
            'The reason code has to separate an old WordPress from a missing key.'
        );
        $this->assertSame(
            $wp_version_ok && wp_supports_ai(),
            $response['data']['wizard_available'],
            'An unrestricted admin may run the wizard on WordPress 7.0+.'
        );
    }

    /**
     * A site can carry the AI Client functions with AI support switched off,
     * and the wizard would then open only to land on its blocked panel.
     *
     * @return void
     */
    public function test_check_provider_denies_wizard_when_ai_support_is_off() {
        if ( ! function_exists( 'wp_supports_ai' ) ) {
            $this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
        }

        $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        add_filter( 'wp_supports_ai', '__return_false' );

        $response = $this->dispatch_assistant_ajax( 'handle_check_provider', MainWP_Assistant::AJAX_CHECK_PROVIDER );

        remove_filter( 'wp_supports_ai', '__return_false' );

        $this->assertTrue( $response['success'] ?? false );
        $this->assertFalse( $response['data']['available'], 'No provider can serve a turn while AI support is off.' );
        $this->assertFalse( $response['data']['wizard_available'], 'The wizard cannot connect a provider on a site with AI support off.' );
    }

    /**
     * The wizard writes a site option, so an admin without MainWP settings
     * rights must not be offered it even though chat itself works for them.
     *
     * @return void
     */
    public function test_check_provider_denies_wizard_without_settings_rights() {
        $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        add_filter( 'mainwp_assistant_agent_mode_available', '__return_false' );
        add_filter( 'mainwp_currentusercan', array( $this, 'filter_deny_settings_rights' ), 10, 3 );

        $response = $this->dispatch_assistant_ajax( 'handle_check_provider', MainWP_Assistant::AJAX_CHECK_PROVIDER );

        remove_filter( 'mainwp_currentusercan', array( $this, 'filter_deny_settings_rights' ), 10 );
        remove_filter( 'mainwp_assistant_agent_mode_available', '__return_false' );

        $this->assertTrue( $response['success'] ?? false );
        $this->assertFalse( $response['data']['wizard_available'] );
    }

    /**
     * Deny manage_dashboard_settings while leaving chat access intact.
     *
     * @param bool   $result   Current decision.
     * @param string $cap_type Capability type.
     * @param string $cap      Capability.
     *
     * @return bool
     */
    public function filter_deny_settings_rights( $result, $cap_type, $cap ) {
        if ( 'dashboard' === $cap_type && 'manage_dashboard_settings' === $cap ) {
            return false;
        }

        return $result;
    }

    /**
     * The cooldown is reported for the provider actually in use.
     *
     * @return void
     */
    public function test_check_provider_surfaces_the_provider_cooldown() {
        $this->set_current_user_as_admin();
        $this->set_assistant_enabled( true );
        $this->set_assistant_consent( true );

        $method = new \ReflectionMethod( MainWP_Assistant::class, 'first_credentialed_provider_id' );
        $method->setAccessible( true );
        $provider_id = (string) $method->invoke( null );

        if ( '' === $provider_id ) {
            // No credentialed provider on this harness, so there is nothing to
            // key a cooldown on and the endpoint must say so rather than guess.
            $response = $this->dispatch_assistant_ajax( 'handle_check_provider', MainWP_Assistant::AJAX_CHECK_PROVIDER );

            $this->assertSame( 0, $response['data']['cooldown_until'] );
            return;
        }

        $until                    = time() + 60;
        $this->seeded_cooldowns[] = $provider_id;
        set_transient( 'mainwp_assistant_provider_cooldown_' . $provider_id, $until, 60 );

        $response = $this->dispatch_assistant_ajax( 'handle_check_provider', MainWP_Assistant::AJAX_CHECK_PROVIDER );

        $this->assertSame( $until, $response['data']['cooldown_until'] );
    }
}

// The parent class only exists once the vendored provider autoloader has run,
// so the declaration is conditional; the attribution tests skip without it.
if ( class_exists( '\WordPress\GoogleAiProvider\Provider\GoogleProvider' ) ) {

    /**
     * A second registrable AI provider, so attribution can face more than one key.
     *
     * Only the identity differs from the vendored Google provider; nothing in
     * these tests ever calls a model on it.
     */
    class Testable_Second_Ai_Provider extends \WordPress\GoogleAiProvider\Provider\GoogleProvider {

        /**
         * Identify as a provider of its own.
         *
         * @return \WordPress\AiClient\Providers\DTO\ProviderMetadata
         */
        protected static function createProviderMetadata(): \WordPress\AiClient\Providers\DTO\ProviderMetadata {
            return new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
                Test_Assistant_Rate_Limit::SECOND_CONNECTOR_ID,
                'MainWP Test Provider',
                \WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud(),
                'https://example.org/api-keys',
                \WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod::apiKey()
            );
        }
    }
}
