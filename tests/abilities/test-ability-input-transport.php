<?php
/**
 * MainWP Abilities API input transport tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Verify the typed JSON carriers used by GET and DELETE ability requests.
 */
class MainWP_Ability_Input_Transport_Test extends WP_UnitTestCase {

	/**
	 * Ensure the production filter is initialized.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		MainWP_Abilities::init();
	}

	/**
	 * Apply the REST pre-callback filters to a request.
	 *
	 * @param WP_REST_Request $request Request to normalize.
	 * @return mixed
	 */
	private function normalize( WP_REST_Request $request ) {
		return apply_filters( 'rest_request_before_callbacks', null, array(), $request );
	}

	/**
	 * Build a MainWP ability run request.
	 *
	 * @param string $method HTTP method.
	 * @return WP_REST_Request
	 */
	private function request( string $method ): WP_REST_Request {
		return new WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/test-transport-v1/run' );
	}

	/**
	 * Assert a transport error without exposing request content.
	 *
	 * @param mixed $result Filter result.
	 * @return void
	 */
	private function assert_transport_error( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mainwp_abilities_invalid_input_transport', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * GET input_json preserves nested nulls and does not coerce the string "null".
	 *
	 * @return void
	 */
	public function test_get_input_json_preserves_json_types(): void {
		$request = $this->request( 'GET' );
		$request->set_query_params(
			array(
				'input_json' => '{"property_ref":null,"literal":"null","nested":{"value":null},"items":[null,"null"]}',
			)
		);

		$result = $this->normalize( $request );

		$this->assertNull( $result );
		$this->assertSame(
			array(
				'property_ref' => null,
				'literal'      => 'null',
				'nested'       => array( 'value' => null ),
				'items'        => array( null, 'null' ),
			),
			$request->get_param( 'input' )
		);
		$this->assertNull( $request->get_param( 'input_json' ) );
	}

	/**
	 * DELETE reads typed input from an application/json body, including write-only data.
	 *
	 * @return void
	 */
	public function test_delete_json_body_preserves_null_and_avoids_url_credentials(): void {
		$request = $this->request( 'DELETE' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"input":{"api_token":"secret-value","property_ref":null}}' );

		$result = $this->normalize( $request );

		$this->assertNull( $result );
		$this->assertSame(
			array(
				'api_token'   => 'secret-value',
				'property_ref' => null,
			),
			$request->get_param( 'input' )
		);
		$this->assertSame( array(), $request->get_url_params() );
		$this->assertStringNotContainsString( 'secret-value', $request->get_route() );
	}

	/**
	 * Existing bracket-style query input remains compatible when no JSON carrier exists.
	 *
	 * @return void
	 */
	public function test_legacy_query_input_remains_unchanged(): void {
		$legacy  = array( 'site_id' => '7', 'after' => '' );
		$request = $this->request( 'GET' );
		$request->set_query_params( array( 'input' => $legacy ) );

		$result = $this->normalize( $request );

		$this->assertNull( $result );
		$this->assertSame( $legacy, $request->get_param( 'input' ) );
	}

	/**
	 * Mixed GET carriers are rejected instead of choosing one interpretation.
	 *
	 * @return void
	 */
	public function test_get_rejects_mixed_legacy_and_json_carriers(): void {
		$request = $this->request( 'GET' );
		$request->set_query_params(
			array(
				'input'      => array( 'property_ref' => '' ),
				'input_json' => '{"property_ref":null}',
			)
		);

		$this->assert_transport_error( $this->normalize( $request ) );
	}

	/**
	 * Mixed DELETE URL/body carriers are rejected.
	 *
	 * @return void
	 */
	public function test_delete_rejects_mixed_query_and_json_body_carriers(): void {
		$request = $this->request( 'DELETE' );
		$request->set_query_params( array( 'input' => array( 'api_token' => 'query-secret' ) ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"input":{"api_token":"body-secret"}}' );

		$result = $this->normalize( $request );

		$this->assert_transport_error( $result );
		$this->assertStringNotContainsString( 'query-secret', $result->get_error_message() );
		$this->assertStringNotContainsString( 'body-secret', $result->get_error_message() );
	}

	/**
	 * GET rejects malformed, scalar, array, and oversized JSON carriers.
	 *
	 * @dataProvider invalid_get_carrier_provider
	 * @param mixed $input_json Invalid carrier.
	 * @return void
	 */
	public function test_get_rejects_invalid_json_carriers( $input_json ): void {
		$request = $this->request( 'GET' );
		$request->set_query_params( array( 'input_json' => $input_json ) );

		$this->assert_transport_error( $this->normalize( $request ) );
	}

	/**
	 * Invalid GET carrier fixtures.
	 *
	 * @return array
	 */
	public function invalid_get_carrier_provider(): array {
		return array(
			'malformed'       => array( '{"value":' ),
			'scalar'          => array( 'null' ),
			'array'           => array( '[null]' ),
			'parameter array' => array( array( '{}' ) ),
			'oversized'       => array( '{"value":"' . str_repeat( 'x', 8192 ) . '"}' ),
		);
	}

	/**
	 * DELETE requires an exact application/json object containing only input.
	 *
	 * @dataProvider invalid_delete_carrier_provider
	 * @param string $content_type Content-Type value.
	 * @param string $body         Request body.
	 * @return void
	 */
	public function test_delete_rejects_invalid_json_carriers( string $content_type, string $body ): void {
		$request = $this->request( 'DELETE' );
		$request->set_header( 'Content-Type', $content_type );
		$request->set_body( $body );

		$this->assert_transport_error( $this->normalize( $request ) );
	}

	/**
	 * Invalid DELETE carrier fixtures.
	 *
	 * @return array
	 */
	public function invalid_delete_carrier_provider(): array {
		return array(
			'wrong media type' => array( 'text/plain', '{"input":{}}' ),
			'malformed'        => array( 'application/json', '{"input":' ),
			'array root'       => array( 'application/json', '[]' ),
			'missing input'    => array( 'application/json', '{"other":{}}' ),
			'extra key'        => array( 'application/json', '{"input":{},"other":{}}' ),
			'scalar input'     => array( 'application/json', '{"input":null}' ),
			'array input'      => array( 'application/json', '{"input":[]}' ),
			'oversized'        => array( 'application/json', '{"input":{"value":"' . str_repeat( 'x', 1048576 ) . '"}}' ),
		);
	}

	/**
	 * The adapter ignores non-MainWP routes and methods.
	 *
	 * @return void
	 */
	public function test_transport_adapter_is_route_and_method_scoped(): void {
		$foreign = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/vendor/test-v1/run' );
		$foreign->set_query_params( array( 'input_json' => '{"value":null}' ) );
		$post = $this->request( 'POST' );
		$post->set_query_params( array( 'input_json' => '{"value":null}' ) );

		$this->assertNull( $this->normalize( $foreign ) );
		$this->assertNull( $foreign->get_param( 'input' ) );
		$this->assertSame( '{"value":null}', $foreign->get_param( 'input_json' ) );
		$this->assertNull( $this->normalize( $post ) );
		$this->assertNull( $post->get_param( 'input' ) );
		$this->assertSame( '{"value":null}', $post->get_param( 'input_json' ) );
	}
}
