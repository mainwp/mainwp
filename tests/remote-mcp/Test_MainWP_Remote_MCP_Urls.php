<?php
/**
 * Remote MCP canonical URL tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Urls
 */
class Test_MainWP_Remote_MCP_Urls extends \WP_UnitTestCase {

	/**
	 * $_SERVER before the test.
	 *
	 * @var array
	 */
	private $saved_server;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
		$this->saved_server = $_SERVER;
		update_option( 'permalink_structure', '/%postname%/' );
	}

	public function tear_down() {
		$_SERVER = $this->saved_server;
		parent::tear_down();
	}

	public function test_urls_built_from_stored_home_not_request_headers() {
		update_option( 'home', 'https://dash.example.org' );
		$_SERVER['HTTP_HOST']              = 'evil.example.com';
		$_SERVER['SERVER_NAME']            = 'evil.example.com';
		$_SERVER['HTTP_X_FORWARDED_HOST']  = 'evil.example.com';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
		$_SERVER['HTTPS']                  = 'off';
		$_SERVER['SERVER_PORT']            = '8080';
		add_filter(
			'home_url',
			function () {
				return 'https://filtered.example.com';
			}
		);

		$urls = new Remote_MCP_Urls();

		$this->assertSame( 'https://dash.example.org', $urls->issuer() );
		$this->assertSame( 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp', $urls->mcp_url() );
		$this->assertSame( 'https://dash.example.org/wp-json/mainwp-remote/v1/oauth-protected-resource', $urls->prm_url() );
		$this->assertSame( 'https://dash.example.org/wp-json/mainwp-remote/v1/oauth/token', $urls->token_endpoint() );
		$this->assertSame( 'https://dash.example.org/wp-json/mainwp-remote/v1/register', $urls->registration_endpoint() );
		$this->assertSame( 'https://dash.example.org/mainwp-oauth/authorize', $urls->authorization_endpoint() );

		// The stored option is what counts, read fresh by each instance.
		update_option( 'home', 'https://other.example.org' );
		$this->assertSame( 'https://other.example.org', ( new Remote_MCP_Urls() )->issuer() );
	}

	public function test_http_home_is_rejected_as_insecure() {
		update_option( 'home', 'http://dash.example.org' );
		$urls = new Remote_MCP_Urls();
		$this->assertFalse( $urls->is_secure() );
		$this->assertNull( $urls->issuer() );
		$this->assertNull( $urls->mcp_url() );
		$this->assertNull( $urls->authorization_endpoint() );

		foreach ( array( '', 'dash.example.org', '//dash.example.org', 'ftp://dash.example.org', 'https://', 'https://user:pass@dash.example.org', 'https://dash.example.org/?x=1', 'https://dash.example.org/#frag' ) as $home ) {
			$this->assertNull( Remote_MCP_Urls::canonical_base( $home ), $home );
		}

		update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
		$remote = new Remote_MCP( new Remote_MCP_Services() );
		$this->assertContains( 'insecure_home', $remote->enable() );
		$this->assertFalse( Remote_MCP_Settings::enabled() );
	}

	public function test_canonical_form_lowercases_host_and_drops_port_443() {
		$this->assertSame( 'https://dash.example.org', Remote_MCP_Urls::canonical_base( 'HTTPS://Dash.Example.ORG:443/' ) );
		$this->assertSame( 'https://dash.example.org:8443', Remote_MCP_Urls::canonical_base( 'https://DASH.example.org:8443/' ) );
		$this->assertSame( 'https://dash.example.org/Sub', Remote_MCP_Urls::canonical_base( 'https://Dash.example.org/Sub/' ), 'the path keeps its case' );

		update_option( 'home', 'HTTPS://Dash.Example.ORG:443' );
		$urls = new Remote_MCP_Urls();
		$this->assertSame( 'https://dash.example.org', $urls->issuer() );
		$this->assertSame( 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp', $urls->mcp_url() );
		$this->assertNull( $urls->port() );

		update_option( 'home', 'https://dash.example.org:8443' );
		$this->assertSame( 8443, ( new Remote_MCP_Urls() )->port() );
	}

	public function test_subdirectory_home_keeps_path_in_issuer() {
		update_option( 'home', 'https://example.com/sub/' );
		$urls = new Remote_MCP_Urls();

		$this->assertSame( 'https://example.com/sub', $urls->issuer() );
		$this->assertSame( '/sub', $urls->path() );
		$this->assertSame( 'https://example.com/sub/wp-json/mainwp-remote/v1/mcp', $urls->mcp_url() );
		$this->assertSame( 'https://example.com/sub/mainwp-oauth/authorize', $urls->authorization_endpoint() );

		update_option( 'home', 'https://example.com/a/b' );
		$this->assertSame( 'https://example.com/a/b', ( new Remote_MCP_Urls() )->issuer() );
		$this->assertSame( '/a/b', ( new Remote_MCP_Urls() )->path() );

		update_option( 'home', 'https://example.com' );
		$this->assertSame( '', ( new Remote_MCP_Urls() )->path() );
	}
}
