<?php
/**
 * Authorization server and protected resource metadata tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Response;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/class-oauth-authorize-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase

/**
 * Class Test_MainWP_OAuth_Metadata
 */
class Test_MainWP_OAuth_Metadata extends OAuth_Authorize_Test_Case {

	/**
	 * GET a metadata route.
	 *
	 * @param string $slot   Slot.
	 * @param string $query  Raw query.
	 * @param array  $server Extra server variables.
	 * @return Remote_MCP_Response
	 */
	private function fetch( string $slot, string $query = '', array $server = array() ): Remote_MCP_Response {
		return $this->handler( $slot )->handle( new Remote_MCP_Request( $slot, 'GET', $query, '', array_merge( $_SERVER, $server ) ) );
	}

	/**
	 * Decoded JSON body of a 200 response.
	 *
	 * @param Remote_MCP_Response $response Response.
	 * @return array
	 */
	private function document( Remote_MCP_Response $response ): array {
		$this->assertSame( 200, $response->status(), $response->body() );
		$this->assertSame( 'application/json', $response->header( 'Content-Type' ) );
		$document = json_decode( $response->body(), true );
		$this->assertIsArray( $document );
		return $document;
	}

	/**
	 * Fixture document.
	 *
	 * @param string $name File name.
	 * @return array
	 */
	private function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/' . $name ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	/**
	 * Compare a served document with its fixture field by field, and the field set exactly.
	 *
	 * @param array $expected Fixture.
	 * @param array $actual   Served document.
	 */
	private function assert_document( array $expected, array $actual ): void {
		foreach ( $expected as $field => $value ) {
			$this->assertArrayHasKey( $field, $actual, $field );
			$this->assertSame( $value, $actual[ $field ], $field );
		}
		$this->assertSame( array(), array_diff( array_keys( $actual ), array_keys( $expected ) ), 'no extra fields' );
	}

	public function test_as_metadata_matches_root_fixture() {
		$expected = $this->fixture( 'as-metadata-root.json' );
		$this->assert_document( $expected, $this->document( $this->fetch( 'route_as_metadata' ) ) );
		// The OpenID path serves the same document; no ID-token fields are added.
		$this->assert_document( $expected, $this->document( $this->fetch( 'route_openid' ) ) );
	}

	public function test_as_metadata_matches_subdirectory_fixture() {
		$this->set_site_option( 'home', 'https://example.com/sub' );
		$expected = $this->fixture( 'as-metadata-subdir.json' );
		$this->assert_document( $expected, $this->document( $this->fetch( 'route_as_metadata' ) ) );
		$this->assert_document( $expected, $this->document( $this->fetch( 'route_openid' ) ) );
	}

	public function test_prm_omits_offline_access_and_lists_one_server() {
		$document = $this->document( $this->fetch( 'route_prm' ) );
		$this->assert_document( $this->fixture( 'prm-root.json' ), $document );
		$this->assertCount( 1, $document['authorization_servers'], 'hosted Claude reads only the first' );
		$this->assertStringNotContainsString( 'offline_access', wp_json_encode( $document ) );

		$this->set_site_option( 'home', 'https://example.com/sub' );
		$this->assert_document( $this->fixture( 'prm-subdir.json' ), $this->document( $this->fetch( 'route_prm' ) ) );
	}

	public function test_prm_resource_is_never_echoed_from_request() {
		$document = $this->document(
			$this->fetch(
				'route_prm',
				'resource=' . rawurlencode( 'https://evil.example/wp-json/mainwp-remote/v1/mcp' ),
				array(
					'HTTP_HOST'             => 'evil.example',
					'HTTP_X_FORWARDED_HOST' => 'evil.example',
					'SERVER_NAME'           => 'evil.example',
				)
			)
		);
		$this->assertSame( self::RESOURCE, $document['resource'] );
		$this->assertSame( array( self::HOME ), $document['authorization_servers'] );
		$this->assertStringNotContainsString( 'evil', wp_json_encode( $document ) );
	}

	/**
	 * Metadata responses disable page caching.
	 */
	public function test_metadata_responses_send_no_store_and_donotcachepage() {
		$this->reset_page_cache_flag();
		$this->assertFalse( \MainWP\Dashboard\Remote_MCP\OAuth_Metadata::page_cache_disabled(), 'not requested before a handler runs' );
		foreach ( array( 'route_as_metadata', 'route_openid', 'route_prm' ) as $slot ) {
			$response = $this->fetch( $slot );
			$this->assertSame( 200, $response->status(), $slot );
			$this->assertStringContainsString( 'no-store', (string) $response->header( 'Cache-Control' ), $slot );
		}
		// Page caches read the constant, not headers.
		$this->assertTrue( \MainWP\Dashboard\Remote_MCP\OAuth_Metadata::page_cache_disabled(), 'requested by the handler' );
		$this->assertTrue( DONOTCACHEPAGE );
	}

	public function test_metadata_advertises_none_and_both_secret_methods() {
		$document = $this->document( $this->fetch( 'route_as_metadata' ) );
		// Web apps authenticate with a secret; every other kind is held to `none` at the token endpoint.
		$this->assertSame( array( 'none', 'client_secret_basic', 'client_secret_post' ), $document['token_endpoint_auth_methods_supported'] );
		$this->assertTrue( $document['client_id_metadata_document_supported'] );
		$this->assertSame( array( 'S256' ), $document['code_challenge_methods_supported'] );
	}

	public function test_registration_endpoint_advertised_in_metadata() {
		$document = $this->document( $this->fetch( 'route_as_metadata' ) );
		$this->assertSame( self::HOME . '/wp-json/mainwp-remote/v1/register', $document['registration_endpoint'], 'on by default' );

		Remote_MCP_Settings::set_registration_enabled( false );
		$this->assert_document( $this->fixture( 'as-metadata-root-no-registration.json' ), $this->document( $this->fetch( 'route_as_metadata' ) ) );

		// A malformed switch reads as off.
		update_option( Remote_MCP_Settings::OPTION_REGISTRATION_ENABLED, array( 'yes' ) );
		$this->assertArrayNotHasKey( 'registration_endpoint', $this->document( $this->fetch( 'route_openid' ) ) );

		Remote_MCP_Settings::set_registration_enabled( true );
		$this->assertArrayHasKey( 'registration_endpoint', $this->document( $this->fetch( 'route_openid' ) ) );
	}
}
