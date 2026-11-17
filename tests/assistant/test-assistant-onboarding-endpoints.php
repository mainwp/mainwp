<?php
/**
 * Tests for the onboarding AJAX endpoints: gates, flag ordering, key source.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_Onboarding;

/**
 * Class Test_Assistant_Onboarding_Endpoints
 */
class Test_Assistant_Onboarding_Endpoints extends MainWP_Assistant_Test_Case {

	/**
	 * Captured handler output.
	 *
	 * @var string
	 */
	protected $ajax_response = '';

	/**
	 * Saved error reporting level.
	 *
	 * @var int
	 */
	protected $ajax_error_level = 0;

	/**
	 * Connector option the endpoints write.
	 *
	 * @var string
	 */
	protected $setting_name = 'connectors_ai_google_api_key';

	/**
	 * Skip the file when the Connectors API is missing.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_get_connectors' ) || ! function_exists( 'wp_supports_ai' ) ) {
			$this->markTestSkipped( 'Connectors API not available in this WordPress version.' );
		}
	}

	/**
	 * Remove anything the endpoints stored.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( $this->setting_name );
		delete_option( MainWP_Assistant_Onboarding::MODEL_OPTION );
		delete_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT );
		delete_transient( 'mainwp_assistant_onboarding_attempts_' . get_current_user_id() );
		putenv( 'GOOGLE_API_KEY' );

		parent::tearDown();
	}

	/**
	 * Skip when a capability-bypass constant has leaked into the process.
	 *
	 * @return void
	 */
	protected function skip_if_capability_checks_bypassed(): void {
		foreach ( array( 'MAINWP_REST_API_DOING', 'DOING_CRON', 'WP_CLI' ) as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				$this->markTestSkipped( $constant . ' is defined, so mainwp_current_user_have_right() bypasses capability checks; denial cannot be tested in this process.' );
			}
		}
	}

	/**
	 * Return the wp_die handler used while an endpoint runs.
	 *
	 * @return callable
	 */
	public function get_ajax_die_handler(): callable {
		return array( $this, 'ajax_die_handler' );
	}

	/**
	 * Capture output and unwind instead of dying.
	 *
	 * @param string $message Die message.
	 *
	 * @throws \WPAjaxDieContinueException Always, to stop the handler.
	 */
	public function ajax_die_handler( $message ): void {
		$this->ajax_response .= (string) ob_get_clean();

		throw new \WPAjaxDieContinueException( $message );
	}

	/**
	 * Run an onboarding endpoint and return its decoded response.
	 *
	 * The onboarding endpoints read their nonce from 'nonce', so the payload
	 * carries it directly instead of the settings page's 'security' key.
	 *
	 * @param callable $handler   Endpoint callable.
	 * @param array    $post_data POST payload.
	 * @param bool     $send_nonce Whether to include a valid nonce.
	 *
	 * @return array Decoded response.
	 */
	protected function invoke_endpoint( callable $handler, array $post_data = array(), bool $send_nonce = true ): array {
		$this->ajax_response = '';

		add_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->ajax_error_level = error_reporting();
		error_reporting( $this->ajax_error_level & ~E_WARNING );

		$_POST = $post_data;
		if ( $send_nonce ) {
			$_POST['nonce'] = wp_create_nonce( MainWP_Assistant_Onboarding::NONCE_ACTION );
		}
		$_REQUEST = $_POST;

		$ob_level = ob_get_level();
		ob_start();

		try {
			call_user_func( $handler );
		} catch ( \WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json calls wp_die, which the handler above intercepts.
		} catch ( \WPAjaxDieStopException $e ) {
			// Expected for hard stops such as a failed nonce.
		}

		if ( ob_get_level() > $ob_level ) {
			$this->ajax_response .= (string) ob_get_clean();
		}

		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		error_reporting( $this->ajax_error_level );

		$_POST    = array();
		$_REQUEST = array();

		$decoded = json_decode( trim( $this->ajax_response ), true );

		return is_array( $decoded ) ? $decoded : array(
			'success' => false,
			'data'    => array( 'raw' => trim( $this->ajax_response ) ),
		);
	}

	// =========================================================================
	// GATES
	// =========================================================================

	/**
	 * Both endpoints are registered on their AJAX actions.
	 */
	public function test_endpoints_are_registered() {
		$this->assertNotFalse( has_action( 'wp_ajax_' . MainWP_Assistant_Onboarding::AJAX_STATUS ) );
		$this->assertNotFalse( has_action( 'wp_ajax_' . MainWP_Assistant_Onboarding::AJAX_SAVE_KEY ) );
	}

	/**
	 * A request without the nonce never reaches the handler body.
	 */
	public function test_status_requires_a_valid_nonce() {
		$this->set_current_user_as_admin();

		$response = $this->invoke_endpoint( array( MainWP_Assistant_Onboarding::class, 'handle_status' ), array(), false );

		$this->assertFalse( $response['success'] ?? true, 'The status endpoint must refuse a request with no nonce.' );
	}

	/**
	 * A chat-only user is refused by both endpoints.
	 *
	 * wizard_available carries this same gate, so the UI can never offer a CTA
	 * the endpoints would reject.
	 */
	public function test_chat_only_user_is_forbidden_by_both_endpoints() {
		$this->skip_if_capability_checks_bypassed();
		$this->set_current_user_as_chat_only();

		foreach ( array( 'handle_status', 'handle_save_key' ) as $method ) {
			$response = $this->invoke_endpoint(
				array( MainWP_Assistant_Onboarding::class, $method ),
				array( 'api_key' => 'AIzaSyTestKeyValue1234' )
			);

			$this->assertFalse( $response['success'] ?? true, $method . ' must refuse a user without manage_dashboard_settings.' );
			$this->assertSame( 'forbidden', $response['data']['error_code'] ?? '', $method . ' must refuse on the capability gate, not on validation.' );
		}
	}

	// =========================================================================
	// STATUS FLAGS
	// =========================================================================

	/**
	 * A healthy dashboard reports the full flag set.
	 */
	public function test_status_reports_the_documented_flags() {
		$this->set_current_user_as_admin();

		$response = $this->invoke_endpoint( array( MainWP_Assistant_Onboarding::class, 'handle_status' ) );

		$this->assertTrue( $response['success'] ?? false );

		foreach ( array( 'wp_version_ok', 'ai_supported', 'contract_ok', 'provider_active', 'key_source', 'key_masked', 'agent_available', 'effective_model', 'default_model', 'credentials_url', 'fallback_url' ) as $key ) {
			$this->assertArrayHasKey( $key, $response['data'], 'The status payload must always carry "' . $key . '".' );
		}

		$this->assertTrue( $response['data']['wp_version_ok'] );
		$this->assertTrue( $response['data']['ai_supported'] );
		$this->assertTrue( $response['data']['contract_ok'] );
		$this->assertSame( 'gemini-3.6-flash', $response['data']['default_model'] );
	}

	/**
	 * AI support off short-circuits before the contract flag.
	 */
	public function test_status_short_circuits_at_ai_supported() {
		$this->set_current_user_as_admin();

		add_filter( 'wp_supports_ai', '__return_false' );
		$response = $this->invoke_endpoint( array( MainWP_Assistant_Onboarding::class, 'handle_status' ) );
		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertTrue( $response['success'] ?? false );
		$this->assertTrue( $response['data']['wp_version_ok'], 'The AI Client is present, so the version flag stays true.' );
		$this->assertFalse( $response['data']['ai_supported'] );
		$this->assertFalse( $response['data']['contract_ok'], 'contract_ok must stay false rather than reporting a mismatch that has not been observed.' );
		$this->assertSame( 'ai_unsupported', $response['data']['error_code'] );
		$this->assertNotEmpty( $response['data']['fallback_url'], 'The Connectors deep link is exactly what the degraded UI needs.' );
	}

	/**
	 * A stored key is reported as a database source, masked to the last four.
	 */
	public function test_status_reports_a_stored_key_masked_to_the_last_four() {
		$this->set_current_user_as_admin();

		update_option( $this->setting_name, 'AIzaSyTestOnboardingKey9876' );

		$response = $this->invoke_endpoint( array( MainWP_Assistant_Onboarding::class, 'handle_status' ) );

		$this->assertSame( 'database', $response['data']['key_source'] );
		$this->assertStringEndsWith( '9876', $response['data']['key_masked'] );
		$this->assertStringNotContainsString( 'AIzaSyTestOnboardingKey', $response['data']['key_masked'], 'Only the last four characters may leave the server.' );
	}

	/**
	 * An environment override is reported instead of the stored key.
	 */
	public function test_status_reports_an_environment_override() {
		$this->set_current_user_as_admin();

		update_option( $this->setting_name, 'AIzaSyStoredKey1111' );
		putenv( 'GOOGLE_API_KEY=AIzaSyEnvKey2222' );

		$response = $this->invoke_endpoint( array( MainWP_Assistant_Onboarding::class, 'handle_status' ) );

		$this->assertSame( 'env', $response['data']['key_source'] );
		$this->assertSame( '', $response['data']['key_masked'], 'Only a database key is masked back to the browser.' );
	}

	// =========================================================================
	// KEY SOURCE AND MASKING HELPERS
	// =========================================================================

	/**
	 * key_source() follows core's env, constant, option precedence.
	 */
	public function test_key_source_precedence() {
		$contract = array(
			'env_var_name'  => 'MAINWP_TEST_ONBOARDING_ENV',
			'constant_name' => 'MAINWP_TEST_ONBOARDING_MISSING',
			'setting_name'  => $this->setting_name,
		);

		$this->assertSame( 'none', MainWP_Assistant_Onboarding::key_source( $contract ) );

		update_option( $this->setting_name, 'AIzaSyStoredKey1111' );
		$this->assertSame( 'database', MainWP_Assistant_Onboarding::key_source( $contract ) );

		// ABSPATH stands in for a defined non-empty key constant: constants
		// cannot be undefined, so defining GOOGLE_API_KEY here would poison
		// every later test in the process.
		$constant_contract                  = $contract;
		$constant_contract['constant_name'] = 'ABSPATH';
		$this->assertSame( 'constant', MainWP_Assistant_Onboarding::key_source( $constant_contract ) );

		putenv( 'MAINWP_TEST_ONBOARDING_ENV=AIzaSyEnvKey2222' );
		$this->assertSame( 'env', MainWP_Assistant_Onboarding::key_source( $constant_contract ) );
		putenv( 'MAINWP_TEST_ONBOARDING_ENV' );
	}

	/**
	 * mask_key() reveals four characters and never fewer than four bullets.
	 */
	public function test_mask_key_reveals_only_the_last_four() {
		$masked = MainWP_Assistant_Onboarding::mask_key( 'AIzaSyAbcdefghijklmn4321' );

		$this->assertStringEndsWith( '4321', $masked );
		$this->assertStringNotContainsString( 'AIzaSyAbcdefghijklmn', $masked );

		$this->assertStringNotContainsString( 'abcd', MainWP_Assistant_Onboarding::mask_key( 'abcd' ), 'A key short enough to be fully revealed must be masked entirely.' );
		$this->assertSame( MainWP_Assistant_Onboarding::mask_key( '' ), MainWP_Assistant_Onboarding::mask_key( 'abc' ) );
	}

	// =========================================================================
	// SAVE KEY INPUT GATES
	// =========================================================================

	/**
	 * Input is rejected before any option write or HTTP call.
	 */
	public function test_save_key_rejects_bad_input_before_writing() {
		$this->set_current_user_as_admin();

		$cases = array(
			'empty_key'          => '',
			'malformed_key'      => 'AIza has a space',
			'wrong_provider_key' => 'sk-proj-abcdefghijklmnop',
		);

		foreach ( $cases as $expected_code => $value ) {
			$response = $this->invoke_endpoint(
				array( MainWP_Assistant_Onboarding::class, 'handle_save_key' ),
				array( 'api_key' => $value )
			);

			$this->assertFalse( $response['success'] ?? true, $expected_code . ' must be a failure.' );
			$this->assertSame( $expected_code, $response['data']['error_code'] ?? '', 'Input "' . $value . '" must be refused as ' . $expected_code . '.' );
			$this->assertSame( '', (string) get_option( $this->setting_name, '' ), 'Nothing may be written for rejected input.' );
		}
	}

	/**
	 * An over-long key is refused without an HTTP request.
	 */
	public function test_save_key_rejects_an_over_long_key() {
		$this->set_current_user_as_admin();

		$response = $this->invoke_endpoint(
			array( MainWP_Assistant_Onboarding::class, 'handle_save_key' ),
			array( 'api_key' => str_repeat( 'A', 201 ) )
		);

		$this->assertSame( 'malformed_key', $response['data']['error_code'] ?? '' );
	}

	/**
	 * The unchanged sentinel leaves storage alone.
	 */
	public function test_save_key_treats_the_sentinel_as_unchanged() {
		$this->set_current_user_as_admin();

		update_option( $this->setting_name, 'AIzaSyStoredKey1111' );

		$response = $this->invoke_endpoint(
			array( MainWP_Assistant_Onboarding::class, 'handle_save_key' ),
			array( 'api_key' => \MainWP\Dashboard\MainWP_Credential_Render::SENTINEL )
		);

		$this->assertSame( 'unchanged', $response['data']['error_code'] ?? '' );
		$this->assertSame( 'AIzaSyStoredKey1111', (string) get_option( $this->setting_name, '' ) );
	}

	/**
	 * A server-supplied key means a database write would be a lie.
	 */
	public function test_save_key_refuses_when_the_environment_supplies_the_key() {
		$this->set_current_user_as_admin();

		putenv( 'GOOGLE_API_KEY=AIzaSyEnvKey2222' );

		$response = $this->invoke_endpoint(
			array( MainWP_Assistant_Onboarding::class, 'handle_save_key' ),
			array( 'api_key' => 'AIzaSyPastedKey3333' )
		);

		$this->assertSame( 'key_source_override', $response['data']['error_code'] ?? '' );
		$this->assertSame( 'env', $response['data']['key_source'] ?? '' );
		$this->assertSame( '', (string) get_option( $this->setting_name, '' ), 'The pasted key must not be stored behind an override.' );
	}

	/**
	 * An inactive provider stops the write, not just the paste step.
	 *
	 * register_google_provider() swallows a missing or broken vendored
	 * autoloader on purpose, so the connector still reads fine while nothing
	 * on the site can use a key stored for it.
	 */
	public function test_save_key_refuses_when_the_provider_is_inactive() {
		$this->set_current_user_as_admin();

		$registry = \WP_Connector_Registry::get_instance();

		if ( null === $registry ) {
			$this->markTestSkipped( 'Connector registry instance not available.' );
		}

		$original = $registry->unregister( 'google' );
		$this->assertIsArray( $original, 'Could not take the google connector out of the registry.' );

		$inactive                        = $original;
		$inactive['plugin']['is_active'] = '__return_false';
		$registry->register( 'google', $inactive );

		$response = $this->invoke_endpoint(
			array( MainWP_Assistant_Onboarding::class, 'handle_save_key' ),
			array( 'api_key' => 'AIzaSyPastedKey3333' )
		);

		$registry->unregister( 'google' );
		$registry->register( 'google', $original );

		$this->assertFalse( $response['success'] ?? true );
		$this->assertSame( 'provider_inactive', $response['data']['error_code'] ?? '' );
		$this->assertNotEmpty( $response['data']['fallback_url'] ?? '', 'The Connectors deep link is the only way out of this state.' );
		$this->assertSame( '', (string) get_option( $this->setting_name, '' ), 'No key may be stored for a provider nothing can use.' );
	}

	/**
	 * Repeated attempts are throttled per user.
	 */
	public function test_save_key_throttles_repeated_attempts() {
		$this->set_current_user_as_admin();

		$last = array();

		for ( $i = 0; $i < MainWP_Assistant_Onboarding::THROTTLE_LIMIT + 1; $i++ ) {
			$last = $this->invoke_endpoint(
				array( MainWP_Assistant_Onboarding::class, 'handle_save_key' ),
				array( 'api_key' => '' )
			);
		}

		$this->assertSame( 'too_many_attempts', $last['data']['error_code'] ?? '', 'The eleventh attempt in the window must be refused.' );
	}
}
