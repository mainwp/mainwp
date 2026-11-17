<?php
/**
 * Tests for the key write, validation taxonomy, revert, redaction and model pin.
 *
 * The provider call is replaced through the list_provider_models() seam, so
 * every branch runs without touching the network.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_Onboarding;

/**
 * Class Test_Assistant_Onboarding_Validation
 */
class Test_Assistant_Onboarding_Validation extends MainWP_Assistant_Test_Case {

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
	 * Connector option the endpoint writes.
	 *
	 * @var string
	 */
	protected $setting_name = 'connectors_ai_google_api_key';

	/**
	 * A well-formed key used across the taxonomy tests.
	 *
	 * @var string
	 */
	protected $key = 'AIzaSy/Test+Onboarding_Key_5678';

	/**
	 * Set up, skipping when the AI Client SDK exceptions are unavailable.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_get_connectors' ) || ! class_exists( '\WordPress\AiClient\Providers\Http\Exception\ClientException' ) ) {
			$this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
		}

		Testable_Assistant_Onboarding::reset();
		$this->set_current_user_as_admin();
	}

	/**
	 * Remove everything the endpoint stored.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( $this->setting_name );
		delete_option( MainWP_Assistant_Onboarding::MODEL_OPTION );
		delete_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT );
		delete_transient( 'mainwp_assistant_onboarding_attempts_' . get_current_user_id() );
		Testable_Assistant_Onboarding::reset();

		parent::tearDown();
	}

	/**
	 * Return the wp_die handler used while the endpoint runs.
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
	 * Run save_key through the seam subclass and return the raw and decoded response.
	 *
	 * @param string $key Key to submit.
	 *
	 * @return array{raw:string,decoded:array}
	 */
	protected function save_key( string $key ): array {
		$this->ajax_response = '';

		add_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->ajax_error_level = error_reporting();
		error_reporting( $this->ajax_error_level & ~E_WARNING );

		$_POST    = array(
			'api_key' => $key,
			'nonce'   => wp_create_nonce( MainWP_Assistant_Onboarding::NONCE_ACTION ),
		);
		$_REQUEST = $_POST;

		$ob_level = ob_get_level();
		ob_start();

		try {
			Testable_Assistant_Onboarding::handle_save_key();
		} catch ( \WPAjaxDieContinueException $e ) {
			// Expected.
		} catch ( \WPAjaxDieStopException $e ) {
			// Expected.
		}

		if ( ob_get_level() > $ob_level ) {
			$this->ajax_response .= (string) ob_get_clean();
		}

		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_ajax_die_handler' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		error_reporting( $this->ajax_error_level );

		$_POST    = array();
		$_REQUEST = array();

		$raw     = trim( $this->ajax_response );
		$decoded = json_decode( $raw, true );

		return array(
			'raw'     => $raw,
			'decoded' => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Build a ClientException carrying an HTTP status in its code.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Exception message.
	 *
	 * @return \WordPress\AiClient\Providers\Http\Exception\ClientException
	 */
	protected function client_exception( int $status, string $message = 'Client error' ) {
		return new \WordPress\AiClient\Providers\Http\Exception\ClientException( $message, $status );
	}

	// =========================================================================
	// SUCCESS
	// =========================================================================

	/**
	 * A verified key is stored and never echoed back.
	 */
	public function test_successful_save_stores_the_key_and_never_returns_it() {
		Testable_Assistant_Onboarding::$models = array( 'gemini-2.5-flash', 'gemini-2.5-pro' );

		$result = $this->save_key( $this->key );

		$this->assertTrue( $result['decoded']['success'] ?? false, 'A clean list-models call means the key is verified.' );
		$this->assertTrue( $result['decoded']['data']['verified'] );
		$this->assertSame( $this->key, (string) get_option( $this->setting_name, '' ) );
		$this->assertStringEndsWith( '5678', $result['decoded']['data']['key_masked'] );
		$this->assertStringNotContainsString(
			'Test+Onboarding',
			$result['raw'],
			'No recognisable fragment of the raw key may appear anywhere in the response payload.'
		);
		$this->assertNotEmpty( $result['decoded']['data']['suggested_prompt'] );
	}

	/**
	 * The cached model allowlist is dropped once a new key is verified.
	 */
	public function test_successful_save_clears_the_cached_model_allowlist() {
		set_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT, array( 'stale-model' => array( 'google' ) ), HOUR_IN_SECONDS );
		Testable_Assistant_Onboarding::$models = array( 'gemini-2.5-flash' );

		$this->save_key( $this->key );

		$this->assertFalse( get_transient( MainWP_Assistant_Onboarding::ALLOWLIST_TRANSIENT ), 'A new key can change the model list, so the cached allowlist must go.' );
	}

	/**
	 * The cache flush is attempted before the models are listed.
	 */
	public function test_cache_invalidation_is_attempted_before_listing_models() {
		Testable_Assistant_Onboarding::$models = array( 'gemini-2.5-flash' );

		$this->save_key( $this->key );

		$this->assertSame(
			array( 'invalidate:google', 'list:google' ),
			Testable_Assistant_Onboarding::$calls,
			'Listing models before invalidating would validate against cached metadata on sites with a persistent SDK cache.'
		);
	}

	// =========================================================================
	// FAILURE TAXONOMY
	// =========================================================================

	/**
	 * A rejected credential is the only case that reverts the stored key.
	 */
	public function test_rejected_credential_reverts_to_the_previous_key() {
		update_option( $this->setting_name, 'AIzaSyPreviousKey0000' );

		foreach ( array( 400, 401, 403 ) as $status ) {
			update_option( $this->setting_name, 'AIzaSyPreviousKey0000' );
			Testable_Assistant_Onboarding::reset();
			Testable_Assistant_Onboarding::$throw = $this->client_exception( $status, 'API key not valid. Please pass a valid API key.' );

			$result = $this->save_key( $this->key );

			$this->assertFalse( $result['decoded']['success'] ?? true, 'HTTP ' . $status . ' must fail.' );
			$this->assertSame( 'invalid_key', $result['decoded']['data']['error_code'] ?? '', 'HTTP ' . $status . ' means the credential itself was rejected.' );
			$this->assertSame( 'AIzaSyPreviousKey0000', (string) get_option( $this->setting_name, '' ), 'HTTP ' . $status . ' must restore the previous key.' );
		}
	}

	/**
	 * With nothing stored before, a rejected key leaves no option behind.
	 */
	public function test_rejected_credential_with_no_previous_key_removes_the_option() {
		Testable_Assistant_Onboarding::$throw = $this->client_exception( 400, 'API key not valid.' );

		$this->save_key( $this->key );

		$this->assertSame( '', (string) get_option( $this->setting_name, '' ) );
	}

	/**
	 * The full rollback decision table for a rejected credential, plus the one
	 * outcome that keeps the key.
	 *
	 * The third row is the one a plain "previous key" test cannot see: the user
	 * clicks Verify again, which resubmits the same key, so the value to
	 * "restore" is the value Google just rejected. Writing it back would leave
	 * Sites mode looking configured with a dead credential.
	 */
	public function test_rejection_rollback_matrix() {
		$rows = array(
			'nothing stored before'      => array( '', 400, '', false ),
			'a different key stored'     => array( 'AIzaSyPreviousKey0000', 401, 'AIzaSyPreviousKey0000', false ),
			're-verify of the same key'  => array( $this->key, 403, '', false ),
			'never rejected, so kept'    => array( '', 429, $this->key, true ),
		);

		foreach ( $rows as $label => $row ) {
			list( $previous, $status, $expected_stored, $expected_kept ) = $row;

			delete_option( $this->setting_name );

			if ( '' !== $previous ) {
				update_option( $this->setting_name, $previous );
			}

			Testable_Assistant_Onboarding::reset();
			Testable_Assistant_Onboarding::$throw = $this->client_exception( $status, 'Rejected' );

			$result = $this->save_key( $this->key );

			$this->assertSame( $expected_stored, (string) get_option( $this->setting_name, '' ), $label );
			$this->assertSame( $expected_kept, $result['decoded']['data']['key_kept'] ?? null, $label . ': key_kept must describe what is actually stored.' );
		}

		// The same three rows again as the sequence that produces them: a
		// transport failure keeps the key, the user clicks Verify again, and
		// this time the credential itself is refused.
		delete_option( $this->setting_name );
		Testable_Assistant_Onboarding::reset();
		Testable_Assistant_Onboarding::$throw = $this->client_exception( 429, 'Too Many Requests (429)' );

		$kept = $this->save_key( $this->key );

		$this->assertTrue( $kept['decoded']['data']['key_kept'] ?? false, 'A rate limited check keeps the key.' );
		$this->assertSame( $this->key, (string) get_option( $this->setting_name, '' ) );

		Testable_Assistant_Onboarding::reset();
		Testable_Assistant_Onboarding::$throw = $this->client_exception( 400, 'API key not valid.' );

		$rejected = $this->save_key( $this->key );

		$this->assertSame( '', (string) get_option( $this->setting_name, '' ), 'The rejected key must not be restored over itself.' );
		$this->assertFalse( $rejected['decoded']['data']['key_kept'] ?? true );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Assistant::agent_mode_available(), 'A dashboard whose only key was just rejected has no usable Sites mode.' );
	}

	/**
	 * A concurrent administrator's newer key is never clobbered by the rollback.
	 */
	public function test_revert_is_skipped_when_another_write_won_the_race() {
		update_option( $this->setting_name, 'AIzaSyPreviousKey0000' );

		Testable_Assistant_Onboarding::$throw        = $this->client_exception( 401, 'Unauthorized' );
		Testable_Assistant_Onboarding::$before_throw = function () {
			update_option( 'connectors_ai_google_api_key', 'AIzaSyOtherAdminKey9999' );
		};

		$result = $this->save_key( $this->key );

		$this->assertSame( 'invalid_key', $result['decoded']['data']['error_code'] ?? '' );
		$this->assertSame(
			'AIzaSyOtherAdminKey9999',
			(string) get_option( $this->setting_name, '' ),
			'A stale rollback must not delete a key another administrator stored while this request was in flight.'
		);
	}

	/**
	 * The rollback is one conditional statement, so a row holding anything but
	 * this request's key is left exactly as it is.
	 */
	public function test_conditional_write_skips_when_the_stored_value_differs() {
		$revert = new \ReflectionMethod( MainWP_Assistant_Onboarding::class, 'revert_key' );
		$revert->setAccessible( true );

		update_option( $this->setting_name, 'AIzaSyOtherAdminKey9999' );

		$this->assertFalse(
			$revert->invoke( null, $this->setting_name, $this->key, 'AIzaSyPreviousKey0000' ),
			'Restoring a previous key must not overwrite a value this request never wrote.'
		);
		$this->assertSame( 'AIzaSyOtherAdminKey9999', (string) get_option( $this->setting_name, '' ) );

		$this->assertFalse(
			$revert->invoke( null, $this->setting_name, $this->key, '' ),
			'Deleting must not remove a value this request never wrote.'
		);
		$this->assertSame( 'AIzaSyOtherAdminKey9999', (string) get_option( $this->setting_name, '' ) );
	}

	/**
	 * Both branches write straight to the row, so the option caches have to end
	 * up agreeing with it.
	 */
	public function test_revert_leaves_no_stale_option_cache_behind() {
		$revert = new \ReflectionMethod( MainWP_Assistant_Onboarding::class, 'revert_key' );
		$revert->setAccessible( true );

		update_option( $this->setting_name, $this->key );

		$this->assertTrue( $revert->invoke( null, $this->setting_name, $this->key, 'AIzaSyPreviousKey0000' ) );
		$this->assertSame( 'AIzaSyPreviousKey0000', (string) get_option( $this->setting_name, '' ) );

		update_option( $this->setting_name, $this->key );

		$this->assertTrue( $revert->invoke( null, $this->setting_name, $this->key, '' ) );
		$this->assertSame( '', (string) get_option( $this->setting_name, '' ) );
	}

	/**
	 * Transport and quota failures keep the key and say so.
	 */
	public function test_transport_failures_keep_the_key() {
		$cases = array(
			'validation_network'       => new \WordPress\AiClient\Providers\Http\Exception\NetworkException( 'Connection timed out' ),
			'validation_provider_down' => new \WordPress\AiClient\Providers\Http\Exception\ServerException( 'Internal Server Error (500)', 500 ),
			'validation_quota'         => $this->client_exception( 429, 'Too Many Requests (429) - Resource has been exhausted' ),
		);

		foreach ( $cases as $expected_code => $exception ) {
			delete_option( $this->setting_name );
			Testable_Assistant_Onboarding::reset();
			Testable_Assistant_Onboarding::$throw = $exception;

			$result = $this->save_key( $this->key );

			$this->assertSame( $expected_code, $result['decoded']['data']['error_code'] ?? '', 'Expected ' . $expected_code . '.' );
			$this->assertFalse( $result['decoded']['data']['verified'] );
			$this->assertTrue( $result['decoded']['data']['key_kept'] );
			$this->assertSame( $this->key, (string) get_option( $this->setting_name, '' ), $expected_code . ' must not delete a key that was never rejected.' );
		}
	}

	/**
	 * Client errors outside the rejection set keep the key as unverified.
	 */
	public function test_other_client_errors_and_unknown_throwables_keep_the_key() {
		$cases = array(
			$this->client_exception( 404, 'Not Found (404)' ),
			$this->client_exception( 422, 'Unprocessable Entity (422)' ),
			new \RuntimeException( 'Something else went wrong' ),
		);

		foreach ( $cases as $exception ) {
			delete_option( $this->setting_name );
			Testable_Assistant_Onboarding::reset();
			Testable_Assistant_Onboarding::$throw = $exception;

			$result = $this->save_key( $this->key );

			$this->assertSame( 'validation_unknown', $result['decoded']['data']['error_code'] ?? '' );
			$this->assertSame( $this->key, (string) get_option( $this->setting_name, '' ) );
		}
	}

	/**
	 * A write that does not read back is reported instead of assumed.
	 */
	public function test_readback_mismatch_reports_save_failed() {
		$override = static function () {
			return 'AIzaSySomethingElse4444';
		};

		add_filter( 'option_' . $this->setting_name, $override );
		Testable_Assistant_Onboarding::$models = array( 'gemini-2.5-flash' );

		$result = $this->save_key( $this->key );

		remove_filter( 'option_' . $this->setting_name, $override );

		$this->assertSame( 'save_failed', $result['decoded']['data']['error_code'] ?? '', 'update_option() returns false for both no-change and a failed write, so only a readback proves the key landed.' );
		$this->assertSame( array(), Testable_Assistant_Onboarding::$calls, 'A failed write must never reach the provider.' );
	}

	// =========================================================================
	// REDACTION
	// =========================================================================

	/**
	 * Every encoded form of the key is scrubbed, and scrubbing runs first.
	 */
	public function test_provider_message_redacts_raw_urlencoded_and_json_forms() {
		$key = $this->key;

		$message = str_repeat( 'a', 290 ) . ' raw=' . $key
			. ' url=' . rawurlencode( $key )
			. ' json=' . trim( wp_json_encode( $key ), '"' );

		Testable_Assistant_Onboarding::$throw = $this->client_exception( 400, $message );

		$result = $this->save_key( $key );

		$provider_message = $result['decoded']['data']['provider_message'] ?? '';

		$this->assertNotSame( '', $provider_message, 'Google\'s own text is what tells a user about a retired key; it must be relayed.' );
		$this->assertStringNotContainsString( $key, $result['raw'], 'The raw key must not survive anywhere in the payload.' );
		$this->assertStringNotContainsString( rawurlencode( $key ), $result['raw'], 'Provider errors echo request URLs, so the urlencoded form has to be scrubbed too.' );
		$this->assertStringNotContainsString( trim( wp_json_encode( $key ), '"' ), $result['raw'], 'Provider errors echo JSON payloads, so the escaped form has to be scrubbed too.' );
		$this->assertStringNotContainsString( 'AIzaSy', $result['raw'], 'Truncating before redacting would leave a boundary-cut fragment of the key behind.' );
		$this->assertStringNotContainsString( 'Test+Onboarding', $result['raw'] );
		$this->assertLessThanOrEqual( 300, mb_strlen( $provider_message ) );
	}

	/**
	 * Markup in provider text never reaches the browser.
	 */
	public function test_provider_message_is_stripped_of_tags() {
		Testable_Assistant_Onboarding::$throw = $this->client_exception( 400, 'Bad Request - <script>alert(1)</script>rejected' );

		$result = $this->save_key( $this->key );

		$this->assertStringNotContainsString( '<script>', $result['decoded']['data']['provider_message'] ?? '' );
	}

	// =========================================================================
	// MODEL PIN
	// =========================================================================

	/**
	 * An empty preference is pinned to the default model.
	 */
	public function test_model_is_pinned_when_no_preference_is_set() {
		Testable_Assistant_Onboarding::$models = array( 'gemini-3.6-flash' );

		$result = $this->save_key( $this->key );

		$this->assertTrue( $result['decoded']['data']['model_pinned'] );
		$this->assertSame( 'gemini-3.6-flash', (string) get_option( MainWP_Assistant_Onboarding::MODEL_OPTION, '' ) );
		$this->assertSame( 'gemini-3.6-flash', $result['decoded']['data']['effective_model'] );
	}

	/**
	 * A stale auto-pin of the retired default migrates on the next successful connect.
	 *
	 * Google 404s gemini-2.5-flash for newly connected keys, so a preference
	 * the old wizard pinned must follow the default forward or the first turn
	 * after onboarding fails.
	 */
	public function test_retired_default_pin_migrates_on_save() {
		update_option( MainWP_Assistant_Onboarding::MODEL_OPTION, 'gemini-2.5-flash' );
		Testable_Assistant_Onboarding::$models = array( 'gemini-3.6-flash' );

		$result = $this->save_key( $this->key );

		$this->assertTrue( $result['decoded']['data']['model_pinned'] );
		$this->assertSame( 'gemini-3.6-flash', (string) get_option( MainWP_Assistant_Onboarding::MODEL_OPTION, '' ) );
	}

	/**
	 * A retired value the user explicitly chose is not migrated.
	 *
	 * The autopin marker records provenance: 'user' means the settings page
	 * saved the value, and the migration must leave it alone even though the
	 * model id is on the retired list.
	 */
	public function test_explicitly_chosen_retired_model_is_not_migrated() {
		update_option( MainWP_Assistant_Onboarding::MODEL_OPTION, 'gemini-2.5-flash' );
		update_option( MainWP_Assistant_Onboarding::MODEL_PIN_SOURCE_OPTION, 'user' );
		Testable_Assistant_Onboarding::$models = array( 'gemini-3.6-flash' );

		$result = $this->save_key( $this->key );

		$this->assertFalse( $result['decoded']['data']['model_pinned'] );
		$this->assertSame( 'gemini-2.5-flash', (string) get_option( MainWP_Assistant_Onboarding::MODEL_OPTION, '' ) );
	}

	/**
	 * An explicitly cleared preference stays cleared.
	 *
	 * Saving an empty value in settings means "let WordPress choose"; a later
	 * key verification must not replace that choice with the Gemini default.
	 */
	public function test_explicitly_cleared_preference_is_not_pinned() {
		delete_option( MainWP_Assistant_Onboarding::MODEL_OPTION );
		update_option( MainWP_Assistant_Onboarding::MODEL_PIN_SOURCE_OPTION, 'user' );
		Testable_Assistant_Onboarding::$models = array( 'gemini-3.6-flash' );

		$result = $this->save_key( $this->key );

		$this->assertFalse( $result['decoded']['data']['model_pinned'] );
		$this->assertSame( '', (string) get_option( MainWP_Assistant_Onboarding::MODEL_OPTION, '' ) );
	}

	/**
	 * A fresh pin records its provenance in the autopin marker.
	 */
	public function test_pin_records_autopin_marker() {
		Testable_Assistant_Onboarding::$models = array( 'gemini-3.6-flash' );

		$this->save_key( $this->key );

		$this->assertSame( 'gemini-3.6-flash', (string) get_option( MainWP_Assistant_Onboarding::MODEL_PIN_SOURCE_OPTION, '' ) );
	}

	/**
	 * An explicit choice is never overwritten.
	 */
	public function test_existing_model_preference_is_never_clobbered() {
		update_option( MainWP_Assistant_Onboarding::MODEL_OPTION, 'claude-sonnet-4-5' );
		Testable_Assistant_Onboarding::$models = array( 'gemini-2.5-flash' );

		$result = $this->save_key( $this->key );

		$this->assertFalse( $result['decoded']['data']['model_pinned'] );
		$this->assertSame( 'claude-sonnet-4-5', (string) get_option( MainWP_Assistant_Onboarding::MODEL_OPTION, '' ) );
	}

	/**
	 * The pinned model is filterable.
	 */
	public function test_default_model_filter_is_honored() {
		$filter = static function () {
			return 'gemini-2.5-flash-lite';
		};

		add_filter( 'mainwp_assistant_gemini_default_model', $filter );
		Testable_Assistant_Onboarding::$models = array( 'gemini-2.5-flash-lite' );

		$this->save_key( $this->key );

		remove_filter( 'mainwp_assistant_gemini_default_model', $filter );

		$this->assertSame( 'gemini-2.5-flash-lite', (string) get_option( MainWP_Assistant_Onboarding::MODEL_OPTION, '' ) );
	}
}

/**
 * Onboarding class with the provider call and cache flush replaced.
 */
class Testable_Assistant_Onboarding extends MainWP_Assistant_Onboarding {

	/**
	 * Model ids the stubbed provider call returns.
	 *
	 * @var array
	 */
	public static $models = array();

	/**
	 * Throwable the stubbed provider call raises, if any.
	 *
	 * @var \Throwable|null
	 */
	public static $throw = null;

	/**
	 * Callback run just before the stubbed provider call throws.
	 *
	 * Used to simulate a concurrent write landing mid-request.
	 *
	 * @var callable|null
	 */
	public static $before_throw = null;

	/**
	 * Ordered log of seam calls.
	 *
	 * @var array
	 */
	public static $calls = array();

	/**
	 * Clear all recorded state.
	 *
	 * @return void
	 */
	public static function reset() {
		static::$models       = array();
		static::$throw        = null;
		static::$before_throw = null;
		static::$calls        = array();
	}

	/**
	 * Stubbed provider model listing.
	 *
	 * @param string $provider_id Provider id.
	 *
	 * @return array Model ids.
	 *
	 * @throws \Throwable The configured throwable, when one is set.
	 */
	protected static function list_provider_models( $provider_id ) {
		static::$calls[] = 'list:' . $provider_id;

		if ( null !== static::$throw ) {
			if ( is_callable( static::$before_throw ) ) {
				call_user_func( static::$before_throw );
			}
			throw static::$throw;
		}

		return static::$models;
	}

	/**
	 * Stubbed cache flush.
	 *
	 * @param string $provider_id Provider id.
	 *
	 * @return void
	 */
	protected static function invalidate_model_cache( $provider_id ) {
		static::$calls[] = 'invalidate:' . $provider_id;
	}
}
