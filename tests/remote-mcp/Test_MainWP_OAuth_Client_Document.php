<?php
/**
 * Client document checks, over fixture documents in fixtures/clients and the bundled
 * documents in known-clients.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Client_Document;
use MainWP\Dashboard\Remote_MCP\OAuth_Known_Clients;

require_once __DIR__ . '/fixtures/client-registration-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Client_Document
 */
class Test_MainWP_OAuth_Client_Document extends \WP_UnitTestCase {

	const CLAUDE_CODE = 'https://claude.ai/oauth/claude-code-client-metadata';
	const CHATGPT     = 'https://chatgpt.com/oauth/client.json';

	/**
	 * Skip when the module isn't loaded.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
	}

	/**
	 * Fixture document.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/fixtures/clients/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture file.
	}

	/**
	 * Raw bytes of a bundled document as the plugin ships it.
	 *
	 * @param string $file File name under known-clients/.
	 * @return string
	 */
	private function bundled_document( string $file ): string {
		$raw = file_get_contents( MAINWP_PLUGIN_DIR . 'modules/remote-mcp/known-clients/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file.
		$this->assertIsString( $raw, $file );
		return $raw;
	}

	/**
	 * Validate a fixture for a client id.
	 *
	 * @param string $name      Fixture.
	 * @param string $client_id Registry key.
	 * @return array
	 */
	private function check( string $name, string $client_id = self::CLAUDE_CODE ): array {
		return OAuth_Client_Document::validate( $this->fixture( $name ), $client_id );
	}

	/**
	 * Valid Claude Code shape with a field replaced.
	 *
	 * @param array $changes Field => value; null removes the field.
	 * @return string
	 */
	private function variant( array $changes ): string {
		$doc = json_decode( $this->fixture( 'claude-code-shape.json' ), true );
		foreach ( $changes as $key => $value ) {
			if ( null === $value ) {
				unset( $doc[ $key ] );
			} else {
				$doc[ $key ] = $value;
			}
		}
		return (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Bundled metadata must distinguish a JSON list from an object.
	 */
	public function test_document_rejects_numeric_object_redirect_uris() {
		$this->assertSame( 'redirect_uris_invalid', $this->check( 'negative-numeric-object-redirect-uris.json' )['reason'] );
	}

	/**
	 * Bundled metadata must distinguish a JSON list from an object.
	 */
	public function test_document_rejects_numeric_object_grant_types() {
		$this->assertSame( 'grant_types_invalid', $this->check( 'negative-numeric-object-grant-types.json' )['reason'] );
	}

	/**
	 * Bundled metadata must distinguish a JSON list from an object.
	 */
	public function test_document_rejects_numeric_object_response_types() {
		$this->assertSame( 'response_types_invalid', $this->check( 'negative-numeric-object-response-types.json' )['reason'] );
	}

	/**
	 * Bundled metadata must distinguish a JSON list from an object.
	 */
	public function test_document_rejects_numeric_object_token_endpoint_auth_methods_supported() {
		$this->assertSame( 'auth_method_unsupported', $this->check( 'negative-numeric-object-token-endpoint-auth-methods-supported.json' )['reason'] );
	}

	/**
	 * A redirect needs a valid HTTPS authority before it enters the registry.
	 */
	public function test_document_rejects_empty_https_host() {
		$this->assertSame( 'redirect_uris_invalid', OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => array( 'https://:443/callback' ) ) ), self::CLAUDE_CODE )['reason'] );
	}

	/**
	 * A redirect needs a valid HTTPS authority before it enters the registry.
	 */
	public function test_document_rejects_nonnumeric_https_port() {
		$this->assertSame( 'redirect_uris_invalid', OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => array( 'https://example.test:notaport/callback' ) ) ), self::CLAUDE_CODE )['reason'] );
	}

	/**
	 * The authority check accepts DNS, IPv4 and bracketed IPv6 without rewriting them.
	 */
	public function test_document_accepts_https_hosts_and_port_boundaries() {
		foreach ( array( 'https://example.test/callback', 'https://127.0.0.1:1/callback', 'https://[2001:db8::1]:65535/oauth/callback' ) as $uri ) {
			$this->assertSame( array( $uri ), OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => array( $uri ) ) ), self::CLAUDE_CODE )['redirect_uris'], $uri );
		}
	}

	/**
	 * Ambiguous HTTPS syntax must not enter a document's redirect set.
	 */
	public function test_document_rejects_unsafe_https_syntax() {
		foreach ( array( 'https://user@example.test/callback', 'https://example.test/c%62', 'https://example.test/cb?x=1', 'https://example.test/cb#x', 'https://example.test/cb\\x', 'https://éxample.test/cb', 'https://example.test:0/cb', 'https://example.test:65536/cb', 'https://[not-ipv6]/cb', 'https://bad..test/cb' ) as $uri ) {
			$this->assertSame( 'redirect_uris_invalid', OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => array( $uri ) ) ), self::CLAUDE_CODE )['reason'], $uri );
		}
	}

	public function test_document_client_id_must_equal_registry_key() {
		$this->assertTrue( $this->check( 'claude-code-shape.json' )['ok'] );
		$this->assertSame( 'client_id_mismatch', $this->check( 'negative-client-id-mismatch.json' )['reason'] );
		// A valid document under another registry key.
		$this->assertSame( 'client_id_mismatch', $this->check( 'claude-code-shape.json', 'https://claude.ai/oauth/mcp-oauth-client-metadata' )['reason'] );
		$this->assertSame( 'client_id_mismatch', OAuth_Client_Document::validate( $this->variant( array( 'client_id' => strtoupper( self::CLAUDE_CODE ) ) ), self::CLAUDE_CODE )['reason'] );
		$this->assertSame( 'client_id_mismatch', OAuth_Client_Document::validate( $this->variant( array( 'client_id' => null ) ), self::CLAUDE_CODE )['reason'] );
		foreach ( array( '', 'null', '[]', '"x"', '[{"client_id":"' . self::CLAUDE_CODE . '"}]', '{"client_id":' ) as $raw ) {
			$this->assertFalse( OAuth_Client_Document::validate( $raw, self::CLAUDE_CODE )['ok'], $raw );
		}
	}

	public function test_redirect_uris_must_be_array_of_strings() {
		foreach ( array( 'negative-redirect-uris-not-strings.json', 'negative-redirect-uris-object.json', 'negative-redirect-uris-string.json' ) as $name ) {
			$this->assertSame( 'redirect_uris_invalid', $this->check( $name )['reason'], $name );
		}
		$too_many = array();
		for ( $i = 1; $i <= 21; $i++ ) {
			$too_many[] = 'http://127.0.0.1/cb' . $i;
		}
		$cases = array(
			array(),
			$too_many,
			array( 'http://127.0.0.1/' . str_repeat( 'a', 2040 ) ),
			array( 'http://localhost/callback', null ),
			array( 'http://localhost/callback', array( 'http://localhost/x' ) ),
			array( 'http://evil.example.com/callback' ),
			array( 'ftp://localhost/callback' ),
			array( '/callback' ),
			array( 'http://localhost/call back' ),
		);
		foreach ( $cases as $uris ) {
			$this->assertSame( 'redirect_uris_invalid', OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => $uris ) ), self::CLAUDE_CODE )['reason'], (string) wp_json_encode( $uris ) );
		}
		$this->assertSame( 'redirect_uris_invalid', OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => null ) ), self::CLAUDE_CODE )['reason'] );
		$this->assertTrue( OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => array_slice( $too_many, 0, 20 ) ) ), self::CLAUDE_CODE )['ok'] );
	}

	public function test_document_redirect_uri_with_fragment_rejected() {
		$this->assertSame( 'redirect_uris_invalid', $this->check( 'negative-redirect-fragment.json' )['reason'] );
		$this->assertSame( 'redirect_uris_invalid', OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => array( 'http://localhost/callback#' ) ) ), self::CLAUDE_CODE )['reason'] );
		$this->assertTrue( OAuth_Client_Document::validate( $this->variant( array( 'redirect_uris' => array( 'https://app.example.com/callback' ) ) ), self::CLAUDE_CODE )['ok'] );
	}

	public function test_document_with_client_secret_rejected() {
		$this->assertSame( 'secret_material', $this->check( 'negative-client-secret.json' )['reason'] );
		$this->assertSame( 'secret_material', $this->check( 'negative-symmetric-method.json' )['reason'] );
		$this->assertSame( 'secret_material', $this->check( 'negative-private-key-material.json' )['reason'] );
		foreach ( array( 'client_secret_basic', 'client_secret_post', 'client_secret_jwt' ) as $method ) {
			$this->assertSame( 'secret_material', OAuth_Client_Document::validate( $this->variant( array( 'token_endpoint_auth_method' => $method ) ), self::CLAUDE_CODE )['reason'], $method );
		}
		$this->assertSame( 'secret_material', OAuth_Client_Document::validate( $this->variant( array( 'client_secret' => '' ) ), self::CLAUDE_CODE )['reason'] );
	}

	public function test_missing_or_nonstring_client_name_rejected() {
		foreach ( array( 'negative-no-client-name.json', 'negative-nonstring-client-name.json', 'negative-empty-client-name.json' ) as $name ) {
			$this->assertSame( 'client_name_invalid', $this->check( $name )['reason'], $name );
		}
		$this->assertSame( 'client_name_invalid', OAuth_Client_Document::validate( $this->variant( array( 'client_name' => str_repeat( 'n', 201 ) ) ), self::CLAUDE_CODE )['reason'] );
		$this->assertSame( 'client_name_invalid', OAuth_Client_Document::validate( $this->variant( array( 'client_name' => array( 'Claude' ) ) ), self::CLAUDE_CODE )['reason'] );
		$this->assertSame( str_repeat( 'n', 200 ), OAuth_Client_Document::validate( $this->variant( array( 'client_name' => str_repeat( 'n', 200 ) ) ), self::CLAUDE_CODE )['client_name'] );
	}

	public function test_chatgpt_document_negotiates_none_from_plural_list() {
		$result = OAuth_Client_Document::validate( $this->bundled_document( 'chatgpt.json' ), self::CHATGPT );
		$this->assertTrue( $result['ok'], (string) $result['reason'] );
		$this->assertSame( 'none', $result['auth_method'] );
		$this->assertSame( 'plural', $result['auth_source'] );
		$this->assertSame( array( 'https://chatgpt.com/connector_platform_oauth_redirect' ), $result['redirect_uris'] );
	}

	public function test_document_offering_no_supported_auth_method_fails() {
		$this->assertSame( 'auth_method_unsupported', $this->check( 'negative-private-key-jwt-only.json', self::CHATGPT )['reason'] );
		$this->assertSame( 'auth_method_unsupported', $this->check( 'negative-no-auth-method.json', 'https://claude.ai/oauth/mcp-oauth-client-metadata' )['reason'] );
		foreach ( array( array(), array( 'None' ), array( 'none ' ), 'none', array( array( 'none' ) ) ) as $plural ) {
			$this->assertSame(
				'auth_method_unsupported',
				OAuth_Client_Document::validate(
					$this->variant(
						array(
							'token_endpoint_auth_method' => 'none',
							'token_endpoint_auth_methods_supported' => $plural,
						)
					),
					self::CLAUDE_CODE
				)['reason'],
				(string) wp_json_encode( $plural )
			);
		}
	}

	public function test_singular_auth_method_used_only_when_plural_absent() {
		// Plural present: the singular field is ignored, whatever it says.
		$plural_wins = OAuth_Client_Document::validate(
			$this->variant(
				array(
					'token_endpoint_auth_method' => 'private_key_jwt',
					'token_endpoint_auth_methods_supported' => array( 'private_key_jwt', 'none' ),
				)
			),
			self::CLAUDE_CODE
		);
		$this->assertTrue( $plural_wins['ok'] );
		$this->assertSame( 'plural', $plural_wins['auth_source'] );
		$this->assertSame(
			'auth_method_unsupported',
			OAuth_Client_Document::validate(
				$this->variant(
					array(
						'token_endpoint_auth_method' => 'none',
						'token_endpoint_auth_methods_supported' => array( 'private_key_jwt' ),
					)
				),
				self::CLAUDE_CODE
			)['reason']
		);
		// Plural absent: the singular field decides.
		$singular = OAuth_Client_Document::validate( $this->variant( array( 'token_endpoint_auth_methods_supported' => null ) ), self::CLAUDE_CODE );
		$this->assertSame( 'singular', $singular['auth_source'] );
		$this->assertSame( 'auth_method_unsupported', OAuth_Client_Document::validate( $this->variant( array( 'token_endpoint_auth_method' => 'private_key_jwt' ) ), self::CLAUDE_CODE )['reason'] );
		$this->assertSame( 'auth_method_unsupported', OAuth_Client_Document::validate( $this->variant( array( 'token_endpoint_auth_method' => array( 'none' ) ) ), self::CLAUDE_CODE )['reason'] );
	}

	public function test_bundled_client_auth_negotiation_still_resolves_to_none() {
		foreach ( OAuth_Known_Clients::REGISTRY as $client_id => $entry ) {
			$result = OAuth_Client_Document::validate( $this->bundled_document( $entry['file'] ), $client_id );
			$this->assertSame( 'none', $result['auth_method'], $entry['file'] );
		}
	}
}
