<?php
/**
 * Known client lookup tests.
 *
 * Mechanism tests read fixture documents shaped like the observed vendor documents, through a
 * subclass that replaces only the registry and the file reader. The tests named for the bundled
 * files read the files the plugin ships.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Client_Document;
use MainWP\Dashboard\Remote_MCP\OAuth_Known_Clients;

require_once __DIR__ . '/fixtures/client-registration-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Known_Clients
 */
class Test_MainWP_OAuth_Known_Clients extends \WP_UnitTestCase {

	use Remote_MCP_Registration_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/claude-code-client-metadata';
	const REDIRECT  = 'http://localhost:53682/callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	const BUNDLED = array(
		'https://claude.ai/oauth/mcp-oauth-client-metadata' => array( 'claude.json', 'result', 'challenge' ),
		'https://claude.ai/oauth/claude-code-client-metadata' => array( 'claude-code.json', 'elicitation', 'challenge' ),
		'https://chatgpt.com/oauth/client.json'            => array( 'chatgpt.json', 'result', 'chatgpt_meta' ),
		'https://vscode.dev/oauth/client-metadata.json'    => array( 'vscode.json', 'elicitation', 'challenge' ),
		'https://chatgpt.com/oauth/codex/client.json'      => array( 'codex-cli.json', 'result', 'result' ),
		'https://zed.dev/oauth/client-metadata.json'       => array( 'zed.json', 'result', 'result' ),
		'https://goose-docs.ai/oauth/client-metadata.json' => array( 'goose.json', 'result', 'result' ),
	);

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

	public function test_registry_holds_exactly_the_seven_bundled_clients() {
		$this->assertSame( array_keys( self::BUNDLED ), array_keys( OAuth_Known_Clients::REGISTRY ) );
		$labels = array();
		foreach ( self::BUNDLED as $client_id => list( $file, $delivery, $step_up ) ) {
			$entry = OAuth_Known_Clients::REGISTRY[ $client_id ];
			$this->assertSame( array( 'file', 'label', 'fetched', 'sha256', 'approval_delivery', 'step_up' ), array_keys( $entry ), $client_id );
			$this->assertSame( $file, $entry['file'], $client_id );
			$this->assertSame( $delivery, $entry['approval_delivery'], $client_id );
			$this->assertSame( $step_up, $entry['step_up'], $client_id );
			$this->assertNotSame( '', $entry['label'] );
			$labels[] = $entry['label'];
		}
		$this->assertSame( array( 'Claude', 'Claude Code', 'ChatGPT', 'VS Code', 'Codex CLI', 'Zed', 'Goose' ), $labels );
		$this->assertSame( array( 'mainwp-cursor', 'mainwp-gemini-cli' ), array_keys( OAuth_Known_Clients::STATIC_CLIENTS ) );

		// The fixture-backed lookup resolves every bundled id, and no other https id.
		$clients = $this->fixture_clients();
		foreach ( array_keys( self::BUNDLED ) as $client_id ) {
			$this->assertSame( 'bundled', $clients->lookup( $client_id )['kind'] ?? null, $client_id );
		}
		$this->assertNull( $clients->lookup( 'https://cursor.com/oauth/client-metadata.json' ) );
		$this->assertNull( $clients->lookup( 'https://evil.example.com/oauth/client.json' ) );
	}

	public function test_client_id_lookup_is_byte_exact() {
		$clients = $this->fixture_clients();
		foreach ( array_keys( self::BUNDLED ) as $client_id ) {
			$this->assertSame( $client_id, $clients->lookup( $client_id )['client_id'], $client_id );
			$host     = (string) wp_parse_url( $client_id, PHP_URL_HOST );
			$variants = array(
				strtoupper( $client_id ),
				'HTTPS://' . substr( $client_id, 8 ),
				str_replace( $host, strtoupper( $host ), $client_id ),
				$client_id . '/',
				$client_id . '?x=1',
				$client_id . '#',
				str_replace( '/oauth/', '/oauth%2F', $client_id ),
				str_replace( '/oauth/', '/%6Fauth/', $client_id ),
				str_replace( $host, $host . ':443', $client_id ),
				' ' . $client_id,
				$client_id . "\n",
				str_replace( 'https://', 'http://', $client_id ),
			);
			foreach ( $variants as $variant ) {
				$this->assertNull( $clients->lookup( $variant ), $variant );
			}
		}
		foreach ( array( 'MAINWP-CURSOR', 'mainwp-cursor ', 'mainwp-cursor/', 'mainwp-gemini', 'Mainwp-gemini-cli' ) as $variant ) {
			$this->assertNull( $clients->lookup( $variant ), $variant );
		}
		$this->assertSame( 'static', $clients->lookup( 'mainwp-cursor' )['kind'] );

		$registered = $this->registered_client( array( 'http://127.0.0.1/cb' ) );
		$this->assertSame( 'registered', $clients->lookup( $registered )['kind'] );
		$this->assertNull( $clients->lookup( strtoupper( $registered ) ) );
		$this->assertNull( $clients->lookup( substr( $registered, 0, -1 ) . ( 'A' === substr( $registered, -1 ) ? 'B' : 'A' ) ) );
		$this->assertNull( $clients->lookup( $registered . ' ' ) );
		$this->assertNull( $clients->lookup( 'mwpwa_' . substr( $registered, 6 ) ) );
	}

	public function test_every_bundled_document_passes_validation() {
		$clients = $this->real_clients();
		foreach ( OAuth_Known_Clients::REGISTRY as $client_id => $entry ) {
			$result = OAuth_Client_Document::validate( $this->bundled_document( $entry['file'] ), $client_id );
			$this->assertTrue( $result['ok'], $entry['file'] . ': ' . (string) $result['reason'] );
			$this->assertSame( 'bundled', $clients->lookup( $client_id )['kind'] ?? null, $client_id );
		}
		$this->assertSame( array(), $clients->bundled_errors() );
	}

	public function test_bundled_file_matches_registry_hash() {
		foreach ( OAuth_Known_Clients::REGISTRY as $client_id => $entry ) {
			$this->assertSame( $entry['sha256'], hash_file( 'sha256', MAINWP_PLUGIN_DIR . 'modules/remote-mcp/known-clients/' . $entry['file'] ), $entry['file'] );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', (string) $entry['fetched'], $entry['file'] );
		}
	}

	public function test_hash_mismatch_or_invalid_file_refuses_only_that_client() {
		$clients = $this->fixture_clients(
			array(
				// One byte changed after the hash was taken.
				'claude-code.json' => str_replace( 'Claude Code', 'Claude Codf', $this->fixture( 'claude-code-shape.json' ) ),
			)
		);
		// A document whose hash matches but which fails validation.
		$invalid                            = $this->fixture( 'negative-private-key-jwt-only.json' );
		$clients->documents['chatgpt.json'] = $invalid;
		$clients->test_registry['https://chatgpt.com/oauth/client.json']['sha256'] = hash( 'sha256', $invalid );
		// A registry entry whose file is missing.
		unset( $clients->documents['zed.json'] );

		$this->assertNull( $clients->lookup( 'https://claude.ai/oauth/claude-code-client-metadata' ) );
		$this->assertNull( $clients->lookup( 'https://chatgpt.com/oauth/client.json' ) );
		$this->assertNull( $clients->lookup( 'https://zed.dev/oauth/client-metadata.json' ) );
		foreach ( array( 'https://claude.ai/oauth/mcp-oauth-client-metadata', 'https://vscode.dev/oauth/client-metadata.json', 'https://chatgpt.com/oauth/codex/client.json', 'https://goose-docs.ai/oauth/client-metadata.json' ) as $client_id ) {
			$this->assertSame( 'bundled', $clients->lookup( $client_id )['kind'] ?? null, $client_id );
		}
		$this->assertSame( 'static', $clients->lookup( 'mainwp-cursor' )['kind'] );

		$errors = $clients->bundled_errors();
		$this->assertSame( 'hash_mismatch', $errors['https://claude.ai/oauth/claude-code-client-metadata'] );
		$this->assertSame( 'auth_method_unsupported', $errors['https://chatgpt.com/oauth/client.json'] );
		$this->assertSame( 'unreadable', $errors['https://zed.dev/oauth/client-metadata.json'] );
		$this->assertCount( 3, $errors );

		// Logged at most once a day per file, with the reason and the host only.
		$this->assertNull( $clients->lookup( 'https://claude.ai/oauth/claude-code-client-metadata' ) );
		$fresh = $this->fixture_clients( array( 'claude-code.json' => 'tampered' ) );
		$this->assertNull( $fresh->lookup( 'https://claude.ai/oauth/claude-code-client-metadata' ) );
		$logged = $this->audited( 'known_client_invalid' );
		$this->assertCount( 3, $logged );
		$this->assertSame(
			array(
				'client_host' => 'claude.ai',
				'reason'      => 'hash_mismatch',
			),
			$logged[0]
		);
	}

	public function test_known_app_label_comes_from_registry_for_exact_client_id() {
		$spoof                                  = str_replace( '"client_name":"Claude Code"', '"client_name":"MainWP Official Support Tool"', $this->fixture( 'claude-code-shape.json' ) );
		$clients                                = $this->fixture_clients( array() );
		$clients->documents['claude-code.json'] = $spoof;
		$clients->test_registry['https://claude.ai/oauth/claude-code-client-metadata']['sha256'] = hash( 'sha256', $spoof );

		$entry = $clients->lookup( 'https://claude.ai/oauth/claude-code-client-metadata' );
		$this->assertSame( 'Claude Code', $entry['label'] );
		$this->assertSame( 'MainWP Official Support Tool', $entry['client_name'] );
		$this->assertSame( 'elicitation', $entry['approval_delivery'] );
		$this->assertSame( 'challenge', $entry['step_up'] );
		$this->assertSame( array( 'none' ), $entry['token_endpoint_auth_methods'] );
		$this->assertTrue( $entry['oauth_endpoints'] );
		$this->assertTrue( $entry['enabled'] );
		$this->assertSame( array( 'client_id', 'kind', 'label', 'client_name', 'redirect_rule', 'redirect_uris', 'token_endpoint_auth_methods', 'oauth_endpoints', 'approval_delivery', 'enabled', 'step_up' ), array_keys( $entry ) );

		$this->assertSame( 'ChatGPT', $clients->lookup( 'https://chatgpt.com/oauth/client.json' )['label'] );
		$this->assertSame( 'Claude', $clients->lookup( 'https://claude.ai/oauth/mcp-oauth-client-metadata' )['label'] );
		$this->assertSame( OAuth_Known_Clients::STATIC_CLIENTS['mainwp-cursor']['label'], $clients->lookup( 'mainwp-cursor' )['label'] );
		$this->assertNull( $clients->lookup( 'mainwp-cursor' )['client_name'] );
	}

	public function test_claude_code_document_matches_random_port_callback() {
		$clients = $this->real_clients();
		$entry   = $clients->lookup( 'https://claude.ai/oauth/claude-code-client-metadata' );
		$this->assertSame( 'loopback_any_port', $entry['redirect_rule'] );
		foreach ( array( 'http://localhost:53682/callback', 'http://127.0.0.1:1/callback', 'http://localhost:65535/callback', 'http://localhost/callback', 'http://127.0.0.1/callback' ) as $uri ) {
			$this->assertTrue( $clients->redirect_allowed( $entry, $uri ), $uri );
		}
		foreach ( array( 'http://localhost:0/callback', 'http://localhost:65536/callback', 'http://localhost:/callback', 'http://localhost:53682/callback/', 'http://localhost:53682/Callback', 'http://localhost:53682/callback?x=1', 'https://localhost:53682/callback', 'http://[::1]:53682/callback', 'http://localhost:53682@evil.com/callback', 'http://evil.com:53682/callback' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $entry, $uri ), $uri );
		}
	}

	public function test_claude_connector_document_takes_singular_none_branch() {
		$result = OAuth_Client_Document::validate( $this->bundled_document( 'claude.json' ), 'https://claude.ai/oauth/mcp-oauth-client-metadata' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'none', $result['auth_method'] );
		$this->assertSame( 'singular', $result['auth_source'] );

		$clients = $this->real_clients();
		$entry   = $clients->lookup( 'https://claude.ai/oauth/mcp-oauth-client-metadata' );
		$this->assertSame( 'exact', $entry['redirect_rule'] );
		$this->assertTrue( $clients->redirect_allowed( $entry, 'https://claude.ai/api/mcp/auth_callback' ) );
		$this->assertFalse( $clients->redirect_allowed( $entry, 'https://claude.ai:443/api/mcp/auth_callback' ) );
		$this->assertFalse( $clients->redirect_allowed( $entry, 'https://claude.com/api/mcp/auth_callback' ) );

		// The same singular branch refuses anything other than none.
		$this->assertFalse( OAuth_Client_Document::validate( $this->fixture( 'negative-singular-private-key-jwt.json' ), 'https://claude.ai/oauth/mcp-oauth-client-metadata' )['ok'] );
	}

	/**
	 * Client lookups, redirect checks, registration and the store flow make no HTTP requests.
	 * The authorize endpoint and consent page need integration coverage of their own.
	 */
	public function test_client_flows_make_no_outbound_request() {
		$clients    = $this->fixture_clients();
		$registered = $this->registered_client( array( 'http://127.0.0.1/cb' ) );
		$webapp     = $this->webapp_client( array( 'https://chat.example.com/oauth/callback' ) );
		$ids        = array_merge( array_keys( self::BUNDLED ), array( 'mainwp-cursor', 'mainwp-gemini-cli', $registered, $webapp, 'mainwp-connection-token' ) );
		$unknown    = array( 'https://cursor.com/oauth/client-metadata.json', 'https://evil.example.com/client.json', 'https://127.0.0.1/client.json', 'mwplc_' . str_repeat( 'a', 43 ), 'not-a-client' );
		foreach ( array_merge( $ids, $unknown ) as $client_id ) {
			$entry = $clients->lookup( $client_id );
			if ( null !== $entry ) {
				$clients->redirect_allowed( $entry, 'http://127.0.0.1:4000/cb' );
				$clients->redirect_allowed( $entry, 'https://evil.example.com/cb' );
			}
		}
		$this->real_clients()->lookup( 'https://claude.ai/oauth/claude-code-client-metadata' );
		$this->real_clients()->lookup( 'https://evil.example.com/client.json' );

		// Direct store flow for a bundled client: consent, code and exchange.
		$this->connect();

		// A registration naming every fetchable member.
		$this->register(
			array(
				'redirect_uris' => array( 'http://127.0.0.1:3000/cb' ),
				'logo_uri'      => 'https://evil.example.com/logo.png',
				'jwks_uri'      => 'https://evil.example.com/jwks',
			)
		);
		$this->assertSame( array(), $this->outbound );
	}

	public function test_module_has_no_outbound_calls_outside_self_test() {
		$forbidden = '/^(wp_remote_\w+|wp_safe_remote_\w+|curl_\w+|fsockopen|pfsockopen|stream_socket_client|dns_get_record|gethostbyname\w*|gethostbynamel)$/i';
		$dir       = MAINWP_PLUGIN_DIR . 'modules/remote-mcp/';
		$files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$checked   = 0;
		$found     = array();
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || 'class-remote-mcp-self-test.php' === $file->getFilename() ) {
				continue;
			}
			++$checked;
			$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- module source.
			foreach ( $tokens as $index => $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ), true ) ) {
					continue;
				}
				$name = substr( (string) strrchr( '\\' . $token[1], '\\' ), 1 );
				if ( ! preg_match( $forbidden, $name ) ) {
					continue;
				}
				// A call is the name followed by an opening parenthesis.
				$next = $index + 1;
				while ( isset( $tokens[ $next ] ) && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
					++$next;
				}
				if ( isset( $tokens[ $next ] ) && '(' === $tokens[ $next ] ) {
					$found[] = $file->getFilename() . ':' . $token[2] . ' ' . $name;
				}
			}
		}
		$this->assertGreaterThan( 30, $checked );
		$this->assertSame( array(), $found );
	}

	public function test_goose_bundled_document_matches_registry_hash() {
		$goose = 'https://goose-docs.ai/oauth/client-metadata.json';
		$entry = OAuth_Known_Clients::REGISTRY[ $goose ];
		$this->assertSame( 'goose.json', $entry['file'] );
		$this->assertSame( 'Goose', $entry['label'] );
		$this->assertSame( $entry['sha256'], hash_file( 'sha256', MAINWP_PLUGIN_DIR . 'modules/remote-mcp/known-clients/goose.json' ) );
		$clients    = $this->real_clients();
		$descriptor = $clients->lookup( $goose );
		$this->assertSame( 'bundled', $descriptor['kind'] );
		$this->assertTrue( $clients->redirect_allowed( $descriptor, 'http://127.0.0.1:61234/oauth_callback' ) );
		foreach ( array( '', ':1', ':61234', ':65535' ) as $port ) {
			$this->assertTrue( $clients->redirect_allowed( $descriptor, 'http://[::1]' . $port . '/oauth_callback' ), $port );
		}
		$this->assertFalse( $clients->redirect_allowed( $descriptor, 'http://localhost:61234/oauth_callback' ) );
	}

	/**
	 * Admin-added redirects use the same HTTPS authority syntax as documents.
	 */
	public function test_webapp_rejects_empty_https_host() {
		$this->assertFalse( $this->real_clients()->validate_redirect( 'webapp', 'https://:443/callback' ) );
	}

	/**
	 * Admin-added redirects use the same HTTPS authority syntax as documents.
	 */
	public function test_webapp_rejects_nonnumeric_https_port() {
		$this->assertFalse( $this->real_clients()->validate_redirect( 'webapp', 'https://example.test:notaport/callback' ) );
	}

	/**
	 * An IPv6 HTTPS host is valid for an admin-added app.
	 */
	public function test_webapp_accepts_bracketed_ipv6_https_host() {
		$this->assertTrue( $this->real_clients()->validate_redirect( 'webapp', 'https://[2001:db8::1]/oauth/callback' ) );
	}

	/**
	 * The shared HTTPS check also applies to redirects submitted by an admin.
	 */
	public function test_webapp_rejects_unsafe_https_syntax() {
		foreach ( array( 'https://user@example.test/callback', 'https://example.test/c%62', 'https://example.test/cb?x=1', 'https://example.test/cb#x', 'https://example.test/cb\\x', 'https://éxample.test/cb', 'https://example.test:0/cb', 'https://example.test:65536/cb', 'https://[not-ipv6]/cb', 'https://bad..test/cb', 'https://example.test/c*' ) as $uri ) {
			$this->assertFalse( $this->real_clients()->validate_redirect( 'webapp', $uri ), $uri );
		}
	}

	/**
	 * VS Code's loopback port varies, while its callback path stays byte-exact.
	 */
	public function test_vscode_bundled_loopback_matches_any_port_only_at_root() {
		$clients    = $this->real_clients();
		$descriptor = $clients->lookup( 'https://vscode.dev/oauth/client-metadata.json' );
		foreach ( array( 1, 5000, 65535 ) as $port ) {
			$this->assertTrue( $clients->redirect_allowed( $descriptor, 'http://127.0.0.1:' . $port . '/' ), (string) $port );
		}
		foreach ( array( '', '/callback', '//', '/?x=1' ) as $path ) {
			$this->assertFalse( $clients->redirect_allowed( $descriptor, 'http://127.0.0.1:5000' . $path ), $path );
		}
	}

	public function test_webapp_redirect_byte_exact_https_only() {
		$clients = $this->fixture_clients();
		$this->assertTrue( $clients->validate_redirect( 'webapp', 'https://chat.example.com/oauth/callback' ) );
		foreach ( array( 'http://chat.example.com/oauth/callback', 'http://localhost:8080/oauth/callback', 'https://*.example.com/cb', 'https://chat.example.com/cb#x', 'https:///cb', 'ftp://chat.example.com/cb', 'chat.example.com/cb' ) as $uri ) {
			$this->assertFalse( $clients->validate_redirect( 'webapp', $uri ), $uri );
		}

		$webapp = $this->webapp_client( array( 'https://chat.example.com/oauth/callback', 'https://chat.example.com:8443/cb' ) );
		$entry  = $clients->lookup( $webapp );
		$this->assertSame( 'webapp', $entry['kind'] );
		$this->assertSame( 'exact', $entry['redirect_rule'] );
		$this->assertSame( 'Team Chat', $entry['label'] );
		$this->assertSame( array( 'client_secret_basic', 'client_secret_post' ), $entry['token_endpoint_auth_methods'] );
		$this->assertTrue( $clients->redirect_allowed( $entry, 'https://chat.example.com/oauth/callback' ) );
		$this->assertTrue( $clients->redirect_allowed( $entry, 'https://chat.example.com:8443/cb' ) );
		foreach ( array( 'https://chat.example.com/oauth/callback/', 'https://CHAT.example.com/oauth/callback', 'https://chat.example.com:443/oauth/callback', 'https://chat.example.com:9443/cb', 'https://chat.example.com/cb', 'http://chat.example.com/oauth/callback', 'https://chat.example.com/oauth/callback?x=1' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $entry, $uri ), $uri );
		}

		// Switching the app off refuses it at lookup, so authorize and the token endpoint refuse it.
		$this->assertTrue( $this->store->set_client_active( $webapp, false ) );
		$this->assertNull( $clients->lookup( $webapp ) );
		$this->assertFalse( $clients->redirect_allowed( $entry, 'https://chat.example.com/oauth/callback' ) );
	}
}
