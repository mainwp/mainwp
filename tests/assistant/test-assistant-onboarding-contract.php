<?php
/**
 * Tests for the core Connectors contract the onboarding wizard depends on.
 *
 * These assertions are deliberately loud: they are the tripwire for a core or
 * SDK change that would otherwise make the wizard write a key to a dead option
 * or silently stop validating.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_Onboarding;

/**
 * Class Test_Assistant_Onboarding_Contract
 */
class Test_Assistant_Onboarding_Contract extends MainWP_Assistant_Test_Case {

	/**
	 * Skip the whole file on WordPress versions without the Connectors API.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_get_connectors' ) || ! function_exists( 'wp_supports_ai' ) ) {
			$this->markTestSkipped( 'Connectors API not available in this WordPress version.' );
		}
	}

	// =========================================================================
	// CORE CONNECTOR SHAPE
	// =========================================================================

	/**
	 * The google connector must keep the exact shape the wizard reads.
	 */
	public function test_google_connector_keeps_expected_shape() {
		$connectors = wp_get_connectors();

		$this->assertArrayHasKey(
			'google',
			$connectors,
			'Core no longer registers a "google" connector. Re-check _wp_connectors_register_default_ai_providers() in wp-includes/connectors.php.'
		);

		$connector = $connectors['google'];

		$this->assertSame(
			'ai_provider',
			$connector['type'] ?? '',
			'The google connector is no longer typed ai_provider. Re-check wp-includes/connectors.php.'
		);

		$auth = $connector['authentication'] ?? array();

		$this->assertSame(
			'api_key',
			$auth['method'] ?? '',
			'The google connector no longer authenticates with an api_key. Re-check wp-includes/connectors.php.'
		);

		$this->assertSame(
			'connectors_ai_google_api_key',
			$auth['setting_name'] ?? '',
			'Core renamed the google API key option. The wizard reads this name from the connector, but the Providers tab copy and this test both assume it. Re-check wp-includes/connectors.php.'
		);

		$this->assertSame(
			'GOOGLE_API_KEY',
			$auth['env_var_name'] ?? '',
			'Core changed the google env var name. key_source() reports overrides from it. Re-check wp-includes/connectors.php.'
		);

		$this->assertSame(
			'GOOGLE_API_KEY',
			$auth['constant_name'] ?? '',
			'Core changed the google constant name. key_source() reports overrides from it. Re-check wp-includes/connectors.php.'
		);

		$this->assertTrue(
			is_callable( $connector['plugin']['is_active'] ?? null ),
			'The google connector no longer exposes a callable plugin.is_active. The wizard derives provider_active from it. Re-check wp-includes/connectors.php.'
		);
	}

	/**
	 * The SDK must keep the cache interface the wizard invalidates through.
	 */
	public function test_sdk_still_exposes_the_cache_invalidation_interface() {
		if ( ! interface_exists( '\WordPress\AiClient\Common\Contracts\CachesDataInterface' ) ) {
			$this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
		}

		$this->assertTrue(
			method_exists( '\WordPress\AiClient\Common\Contracts\CachesDataInterface', 'invalidateCaches' ),
			'CachesDataInterface::invalidateCaches() is gone. The defensive cache flush before key validation needs a new path.'
		);
	}

	// =========================================================================
	// CONTRACT READER
	// =========================================================================

	/**
	 * The reader returns the parts the endpoints consume.
	 */
	public function test_contract_reader_returns_the_expected_parts() {
		$contract = MainWP_Assistant_Onboarding::google_connector_contract();

		$this->assertNotWPError( $contract, 'The reader must resolve the google connector on a supported WordPress version.' );

		foreach ( array( 'name', 'setting_name', 'env_var_name', 'constant_name', 'credentials_url', 'is_active' ) as $key ) {
			$this->assertArrayHasKey( $key, $contract, 'Contract is missing the "' . $key . '" part.' );
		}

		$this->assertSame( 'connectors_ai_google_api_key', $contract['setting_name'] );
		$this->assertNotEmpty( $contract['credentials_url'], 'A credentials URL must always be present, falling back to the AI Studio key page.' );
		$this->assertTrue( is_callable( $contract['is_active'] ) );
	}

	/**
	 * AI support being off is its own state, never a contract mismatch.
	 *
	 * Core registers no connectors at all when wp_supports_ai() is false, so
	 * reporting a mismatch there would send every AI-disabled dashboard to the
	 * "your WordPress is broken" copy.
	 */
	public function test_contract_reader_reports_ai_unsupported_before_inspecting_the_shape() {
		add_filter( 'wp_supports_ai', '__return_false' );

		$contract = MainWP_Assistant_Onboarding::google_connector_contract();

		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertWPErrorWithCode( $contract, 'ai_unsupported' );
	}

	/**
	 * A connector that stops authenticating with an API key is a mismatch.
	 *
	 * The registry synthesises a setting_name for every api_key connector, so
	 * the shape only breaks by the method changing - and when it does there is
	 * no option to write a pasted key into.
	 */
	public function test_contract_reader_reports_mismatch_when_the_auth_method_changes() {
		$registry = \WP_Connector_Registry::get_instance();

		if ( null === $registry ) {
			$this->markTestSkipped( 'Connector registry instance not available.' );
		}

		$original = $registry->unregister( 'google' );
		$this->assertIsArray( $original, 'Could not take the google connector out of the registry.' );

		$changed                             = $original;
		$changed['authentication']['method'] = 'none';
		$registry->register( 'google', $changed );

		$contract = MainWP_Assistant_Onboarding::google_connector_contract();

		$registry->unregister( 'google' );
		$registry->register( 'google', $original );

		$this->assertWPErrorWithCode( $contract, 'connector_contract_mismatch' );
	}

	/**
	 * A missing connector is a mismatch too.
	 */
	public function test_contract_reader_reports_mismatch_when_the_connector_is_absent() {
		$registry = \WP_Connector_Registry::get_instance();

		if ( null === $registry ) {
			$this->markTestSkipped( 'Connector registry instance not available.' );
		}

		$original = $registry->unregister( 'google' );
		$this->assertIsArray( $original );

		$contract = MainWP_Assistant_Onboarding::google_connector_contract();

		$registry->register( 'google', $original );

		$this->assertWPErrorWithCode( $contract, 'connector_contract_mismatch' );
	}

	// =========================================================================
	// VENDORED PROVIDER REGISTRATION
	// =========================================================================

	/**
	 * Registration must run before core wires stored keys at init priority 20.
	 */
	public function test_provider_registration_is_hooked_at_init_priority_six() {
		$this->assertSame(
			6,
			has_action( 'init', array( 'MainWP\Dashboard\MainWP_Assistant_Onboarding', 'register_google_provider' ) ),
			'The vendored provider must register at init priority 6: after provider plugins register at 5, and before core passes stored keys to the AI Client at 20.'
		);
	}

	/**
	 * The vendored provider ends up in the AI Client registry.
	 */
	public function test_vendored_provider_is_registered() {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
		}

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();

		$this->assertTrue( $registry->hasProvider( 'google' ), 'The vendored Google provider did not reach the AI Client registry.' );
		$this->assertTrue(
			class_exists( '\WordPress\GoogleAiProvider\Provider\GoogleProvider' ),
			'The vendored package under libs/ai-provider-for-google was not loaded.'
		);
	}

	/**
	 * A provider another plugin already registered under "google" must win.
	 */
	public function test_provider_registration_yields_to_an_existing_google_provider() {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) || ! class_exists( __NAMESPACE__ . '\Stub_Google_Provider' ) ) {
			$this->markTestSkipped( 'WP AI Client or the vendored Google provider is not available in this WordPress version.' );
		}

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		$original = $registry->getProviderClassName( 'google' );

		// registerProvider() overwrites by id, so without the hasProvider guard
		// this stub would be replaced by MainWP's copy.
		$registry->registerProvider( Stub_Google_Provider::class );

		MainWP_Assistant_Onboarding::register_google_provider();

		$after = $registry->getProviderClassName( 'google' );

		$registry->registerProvider( $original );

		$this->assertSame(
			Stub_Google_Provider::class,
			$after,
			'MainWP overwrote a Google provider that was already registered. The hasProvider() guard is not doing its job.'
		);
	}
}

// Declared conditionally so the file still parses below WordPress 7.0, where
// the vendored provider class does not exist.
if ( class_exists( '\WordPress\GoogleAiProvider\Provider\GoogleProvider' ) && ! class_exists( __NAMESPACE__ . '\Stub_Google_Provider' ) ) {
	/**
	 * Stands in for a third-party plugin's own "google" provider.
	 */
	class Stub_Google_Provider extends \WordPress\GoogleAiProvider\Provider\GoogleProvider {
	}
}
