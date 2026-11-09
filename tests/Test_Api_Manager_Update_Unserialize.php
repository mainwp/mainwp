<?php
/**
 * Extension update-info deserialization safety (self-audit A3).
 *
 * MainWP_Api_Manager_Plugin_Update::plugin_information() unserializes the body of
 * a wp_remote_get() to the extension upgrade server. The non-bulk path expects a
 * stdClass and reads ->package / ->new_version off it, so the fix cannot be the
 * blanket allowed_classes=>false used elsewhere in the repo: that decodes even a
 * legitimate stdClass to __PHP_Incomplete_Class, which still passes is_object()
 * and would silently break every extension update check.
 *
 * The response body must therefore allow stdClass and reject any other class,
 * which is what blocks PHP object injection (arbitrary __wakeup/__destruct
 * gadgets) during unserialize().
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Api_Manager_Plugin_Update;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * A class that must never be instantiated from a remote payload. If the fix
 * regressed to allowing arbitrary classes, unserialize() would build one of
 * these instead of an __PHP_Incomplete_Class.
 */
class Update_Unserialize_Probe {
	/**
	 * Stand-in for a gadget property.
	 *
	 * @var string
	 */
	public $package = 'attacker-controlled';
}

/**
 * Class Test_Api_Manager_Update_Unserialize
 */
class Test_Api_Manager_Update_Unserialize extends \WP_UnitTestCase {

	/**
	 * The body the mocked upgrade server should return next.
	 *
	 * @var string
	 */
	private $mock_body = '';

	/**
	 * Route wp_remote_get() through a canned 200 response carrying $mock_body.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	/**
	 * Remove the HTTP short-circuit.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * Short-circuit wp_remote_get() with a 200 response wrapping $mock_body.
	 *
	 * @return array
	 */
	public function mock_http() {
		return array(
			'body'     => $this->mock_body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	/**
	 * Minimal args accepted by plugin_information()/create_upgrade_api_url().
	 *
	 * @return array
	 */
	private function args(): array {
		return array(
			'request'     => 'pluginupdatecheck',
			'plugin_name' => 'some-extension/some-extension.php',
			'version'     => '1.0',
		);
	}

	/**
	 * A legitimate serialized stdClass response still parses and is returned as
	 * an object with its properties intact. This is the regression guard: the
	 * blanket allowed_classes=>false fix would have failed this test.
	 */
	public function test_legitimate_stdclass_response_still_parses(): void {
		$payload            = new \stdClass();
		$payload->new_version = '2.0';
		$payload->package     = 'https://example.test/extension-2.0.zip';
		$this->mock_body    = serialize( $payload );

		$response = ( new MainWP_Api_Manager_Plugin_Update() )->plugin_information( $this->args() );

		$this->assertIsObject( $response, 'a normal stdClass response must survive unserialize' );
		$this->assertSame( '2.0', $response->new_version );
		$this->assertObjectNotHasProperty( '__PHP_Incomplete_Class_Name', $response );
	}

	/**
	 * A serialized object of any other class must not be instantiated, and must
	 * not be handed back as a broken object either. It degrades to false.
	 */
	public function test_foreign_class_payload_is_rejected(): void {
		$this->mock_body = serialize( new Update_Unserialize_Probe() );

		$response = ( new MainWP_Api_Manager_Plugin_Update() )->plugin_information( $this->args() );

		$this->assertFalse( $response, 'an unexpected class must be rejected, not returned as an incomplete object' );
	}

	/**
	 * A malformed (non-serialized) body must not fatal; it returns false.
	 */
	public function test_malformed_body_returns_false(): void {
		$this->mock_body = 'this is not serialized data';

		$response = ( new MainWP_Api_Manager_Plugin_Update() )->plugin_information( $this->args() );

		$this->assertFalse( $response );
	}
}
