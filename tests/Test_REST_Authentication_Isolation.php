<?php
/**
 * MainWP REST Authentication Isolation Tests
 *
 * Tests that MainWP REST authentication is correctly isolated to MainWP endpoints
 * and does not interfere with other REST APIs (e.g., WordPress Abilities API).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_REST_Authentication_Isolation
 *
 * Tests the is_request_to_rest_api() method to ensure proper namespace isolation.
 */
class Test_REST_Authentication_Isolation extends \WP_UnitTestCase {

	/**
	 * Test helper to check if a URI is considered a MainWP REST request.
	 *
	 * Uses reflection to call the protected is_request_to_rest_api() method.
	 *
	 * @param string $request_uri The REQUEST_URI to test.
	 * @return bool Whether the URI is considered a MainWP REST request.
	 */
	private function is_mainwp_rest_request( string $request_uri ): bool {
		// Reset the singleton to get fresh state.
		// Note: This directly manipulates MainWP_REST_Authentication::$instance which couples
		// this test to the singleton implementation. If the property name changes, update this line.
		// Consider adding a reset() helper method to MainWP_REST_Authentication in future.
		\MainWP_REST_Authentication::$instance = null;

		// The classifier reads the route WordPress dispatches, which for these URIs is the
		// path after the REST prefix.
		$_SERVER['REQUEST_URI']                  = $request_uri;
		$GLOBALS['wp']->query_vars['rest_route'] = 0 === strpos( $request_uri, '/wp-json/' ) ? substr( $request_uri, strlen( '/wp-json' ) ) : '';

		$auth       = \MainWP_REST_Authentication::get_instance();
		$reflection = new \ReflectionMethod( $auth, 'is_request_to_rest_api' );
		$reflection->setAccessible( true );

		return $reflection->invoke( $auth );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'], $GLOBALS['wp']->query_vars['rest_route'] );
		\MainWP_REST_Authentication::$instance = null;
		parent::tearDown();
	}

	/**
	 * Test that MainWP REST v2 sites endpoint is recognized.
	 */
	public function test_mainwp_rest_v2_sites_is_recognized(): void {
		$this->assertTrue(
			$this->is_mainwp_rest_request( '/wp-json/mainwp/v2/sites' ),
			'MainWP REST v2 sites endpoint should be recognized as MainWP API'
		);
	}

	/**
	 * Test that MainWP REST v2 updates endpoint is recognized.
	 */
	public function test_mainwp_rest_v2_updates_is_recognized(): void {
		$this->assertTrue(
			$this->is_mainwp_rest_request( '/wp-json/mainwp/v2/updates' ),
			'MainWP REST v2 updates endpoint should be recognized as MainWP API'
		);
	}

	/**
	 * Test that MainWP extension endpoints are recognized.
	 */
	public function test_mainwp_extension_endpoint_is_recognized(): void {
		$this->assertTrue(
			$this->is_mainwp_rest_request( '/wp-json/mainwp-extension/v1/foo' ),
			'MainWP extension endpoints should be recognized as MainWP API'
		);
	}

	/**
	 * Test that MainWP extension with different names are recognized.
	 */
	public function test_mainwp_backups_extension_is_recognized(): void {
		$this->assertTrue(
			$this->is_mainwp_rest_request( '/wp-json/mainwp-backups/v1/jobs' ),
			'MainWP backups extension endpoint should be recognized as MainWP API'
		);
	}

	/**
	 * Test that WordPress Abilities API with mainwp provider is NOT recognized as MainWP API.
	 *
	 * This is critical: the Abilities API path contains 'mainwp' as the ability provider
	 * namespace, but it should NOT be handled by MainWP's REST authentication system.
	 * The Abilities API uses native WordPress authentication (cookies, application passwords).
	 */
	public function test_abilities_api_mainwp_provider_is_not_recognized(): void {
		$this->assertFalse(
			$this->is_mainwp_rest_request( '/wp-json/wp-abilities/v1/abilities/mainwp/list-sites-v1' ),
			'Abilities API mainwp/list-sites-v1 should NOT be recognized as MainWP REST API'
		);
	}

	/**
	 * Test that Abilities API run endpoint is NOT recognized.
	 */
	public function test_abilities_api_run_endpoint_is_not_recognized(): void {
		$this->assertFalse(
			$this->is_mainwp_rest_request( '/wp-json/wp-abilities/v1/abilities/mainwp/list-sites-v1/run' ),
			'Abilities API run endpoint should NOT be recognized as MainWP REST API'
		);
	}

	/**
	 * Test that Abilities API abilities listing is NOT recognized.
	 */
	public function test_abilities_api_list_endpoint_is_not_recognized(): void {
		$this->assertFalse(
			$this->is_mainwp_rest_request( '/wp-json/wp-abilities/v1/abilities' ),
			'Abilities API list endpoint should NOT be recognized as MainWP REST API'
		);
	}

	/**
	 * Test that WordPress core REST API is NOT recognized.
	 */
	public function test_wp_core_rest_api_is_not_recognized(): void {
		$this->assertFalse(
			$this->is_mainwp_rest_request( '/wp-json/wp/v2/posts' ),
			'WordPress core REST API should NOT be recognized as MainWP REST API'
		);
	}

	/**
	 * Test that third-party plugin REST APIs are NOT recognized.
	 */
	public function test_third_party_rest_api_is_not_recognized(): void {
		$this->assertFalse(
			$this->is_mainwp_rest_request( '/wp-json/wc/v3/products' ),
			'Third-party REST APIs should NOT be recognized as MainWP REST API'
		);
	}

	/**
	 * Test that empty REQUEST_URI returns false.
	 */
	public function test_empty_request_uri_returns_false(): void {
		$this->assertFalse(
			$this->is_mainwp_rest_request( '' ),
			'Empty REQUEST_URI should return false'
		);
	}

	/**
	 * Test that a MainWP-looking REQUEST_URI is ignored when WordPress is not dispatching a REST route.
	 */
	public function test_mainwp_request_uri_without_rest_route_is_not_recognized(): void {
		\MainWP_REST_Authentication::$instance = null;
		$_SERVER['REQUEST_URI']                  = '/wp-admin/admin-ajax.php?x=/wp-json/mainwp/v2/sites';
		$GLOBALS['wp']->query_vars['rest_route'] = '';

		$auth       = \MainWP_REST_Authentication::get_instance();
		$reflection = new \ReflectionMethod( $auth, 'is_request_to_rest_api' );
		$reflection->setAccessible( true );

		$this->assertFalse( $reflection->invoke( $auth ), 'REQUEST_URI alone must not classify a request as MainWP REST' );
	}

	/**
	 * Test that URLs with mainwp in a different path position are not recognized.
	 *
	 * For example, /wp-json/other-plugin/v1/mainwp/something should NOT be recognized.
	 */
	public function test_mainwp_in_subpath_is_not_recognized(): void {
		$this->assertFalse(
			$this->is_mainwp_rest_request( '/wp-json/other-plugin/v1/mainwp/something' ),
			'mainwp in a subpath should NOT be recognized as MainWP REST API'
		);
	}

	/**
	 * Test constants are defined correctly.
	 */
	public function test_namespace_constants_are_defined(): void {
		$this->assertSame(
			'mainwp/',
			\MainWP_REST_Authentication::MAINWP_REST_NAMESPACE,
			'MAINWP_REST_NAMESPACE constant should be mainwp/'
		);

		$this->assertSame(
			'mainwp-',
			\MainWP_REST_Authentication::MAINWP_EXTENSION_REST_NAMESPACE,
			'MAINWP_EXTENSION_REST_NAMESPACE constant should be mainwp-'
		);
	}
}
