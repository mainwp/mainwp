<?php
/**
 * Remote MCP self-test tests.
 *
 * Every loopback probe is answered through pre_http_request; an unexpected URL gets a 404.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Self_Test;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Self_Test
 */
class Test_MainWP_Remote_MCP_Self_Test extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures {
		set_up as fixtures_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Answer per probe: path => `ours`, `foreign`, `root`, `html`, a status code, or (for `mcp`)
	 * the probe's JSON.
	 *
	 * @var array
	 */
	private $answers = array();

	/**
	 * Status of the plain-HTTP probe.
	 *
	 * @var int
	 */
	private $http_status = 400;

	/**
	 * Requests made, as [ url, args ].
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Issuer the fake "ours" documents carry.
	 *
	 * @var string
	 */
	private $issuer = 'https://dash.example.org';

	public function set_up() {
		$this->fixtures_set_up();
		foreach ( Remote_MCP_Self_Test::BUNDLED_CLIENTS as $client_id => $label ) {
			$this->known->entries[ $client_id ] = array( 'label' => $label );
		}
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	/**
	 * Answer a probe.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array( $url, $args );
		$parts            = wp_parse_url( $url );
		if ( 'http' === $parts['scheme'] ) {
			return $this->response( $this->http_status, 'text/plain', 'x' );
		}
		parse_str( $parts['query'] ?? '', $query );
		$path = $parts['path'] ?? '/';
		$key  = ( isset( $query['rest_route'] ) && Remote_MCP_Urls::ROUTE_MCP === $query['rest_route'] ) || '/wp-json' . Remote_MCP_Urls::ROUTE_MCP === substr( $path, -strlen( '/wp-json' . Remote_MCP_Urls::ROUTE_MCP ) ) ? 'mcp' : $path;
		$spec = $this->answers[ $key ] ?? 404;
		if ( 'mcp' === $key ) {
			return is_array( $spec ) ? $this->response( 200, 'application/json', (string) wp_json_encode( $spec ) ) : $this->response( (int) $spec, 'text/html', 'no' );
		}
		switch ( $spec ) {
			case 'ours':
				return $this->response( 200, 'application/json', (string) wp_json_encode( array( 'issuer' => $this->issuer ) ) );
			case 'foreign':
				return $this->response( 200, 'application/json', (string) wp_json_encode( array( 'issuer' => 'https://idp.example.net' ) ) );
			case 'root':
				return $this->response( 200, 'application/json', (string) wp_json_encode( array( 'issuer' => 'https://dash.example.org' ) ) );
			case 'html':
				return $this->response( 200, 'text/html', '<html><body>static</body></html>' );
		}
		return $this->response( (int) $spec, 'text/html', 'Not found' );
	}

	/**
	 * A fake HTTP response.
	 *
	 * @param int    $status Status.
	 * @param string $type   Content type.
	 * @param string $body   Body.
	 * @return array
	 */
	private function response( int $status, string $type, string $body ): array {
		return array(
			'headers'  => array( 'content-type' => $type ),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => 'x',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Probe answers for a working layout.
	 *
	 * @param string $p     Home path.
	 * @param array  $wells Answers for rfc_insert, oidc_insert, rfc_append, oidc_append.
	 * @param array  $mcp   MCP probe JSON.
	 */
	private function layout( string $p, array $wells, array $mcp = array() ): void {
		$this->issuer  = 'https://dash.example.org' . $p;
		$this->answers = array(
			'/.well-known/oauth-authorization-server' . $p => $wells[0],
			'/.well-known/openid-configuration' . $p       => $wells[1],
			$p . '/.well-known/oauth-authorization-server' => $wells[2],
			$p . '/.well-known/openid-configuration'       => $wells[3],
			'mcp'                                          => array_merge(
				array(
					'https'         => true,
					'authorization' => true,
				),
				$mcp
			),
		);
	}

	/**
	 * Self-test for a home URL.
	 *
	 * @param string $home Home.
	 * @return Remote_MCP_Self_Test
	 */
	private function self_test( string $home = 'https://dash.example.org' ): Remote_MCP_Self_Test {
		// The suite runs with the logs module off; the log check has its own test.
		return new class( new Remote_MCP_Panel_Test_Services( array( 'known_clients' => $this->known ), $this->store, new Remote_MCP_Urls( $home ) ) ) extends Remote_MCP_Self_Test {
			protected function activity_log_available(): bool {
				return true;
			}
		};
	}

	/**
	 * Lines by id.
	 *
	 * @param array $report run() result.
	 * @return array
	 */
	private function lines( array $report ): array {
		// Every check id is present, an absent line as status `absent`, so a missing line fails an
		// assertion instead of erroring on an undefined key.
		$ids   = array( 'scope', 'requirements', 'https_home', 'activity_log', 'kill_switch', 'competing_rules', 'non_443', 'path_routing', 'discovery', 'path_append', 'subdirectory_rule', 'well_known_conflict', 'authorization_header', 'https_requests', 'plain_http', 'auth_counters', 'known_clients', 'unknown_clients' );
		$lines = array();
		foreach ( $ids as $id ) {
			$lines[ $id ] = array(
				'id'      => $id,
				'status'  => 'absent',
				'message' => '',
				'fix'     => '',
				'clients' => array(),
				'counts'  => array(),
				'count'   => null,
			);
		}
		foreach ( $report['lines'] as $line ) {
			$lines[ $line['id'] ] = $line;
		}
		return $lines;
	}

	/**
	 * Run a subdirectory layout and return the discovery line.
	 *
	 * @param array $wells Answers.
	 * @return array [ lines, report ]
	 */
	private function run_sub( array $wells ): array {
		$this->layout( '/sub', $wells );
		$report = $this->self_test( 'https://dash.example.org/sub' )->run();
		return array( $this->lines( $report ), $report );
	}

	public function test_plain_permalinks_enable_when_path_routing_probe_passes() {
		update_option( 'permalink_structure', '' );
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );

		$self_test = $this->self_test();
		$this->assertSame( array(), $self_test->blocking_reasons() );
		$lines = $this->lines( $self_test->run() );
		$this->assertSame( 'pass', $lines['path_routing']['status'] );
		$this->assertSame( 'pass', $lines['discovery']['status'] );
		$mcp = array_filter(
			array_column( $this->requests, 0 ),
			function ( $url ) {
				return false !== strpos( $url, 'rest_route=' . Remote_MCP_Urls::ROUTE_MCP );
			}
		);
		$this->assertNotEmpty( $mcp, 'the MCP probe uses the ?rest_route= form' );

		// The connector really turns on with this self-test in its slot.
		$remote = new Remote_MCP( new Remote_MCP_Panel_Test_Services( array( 'enable_check' => $self_test ), $this->store, new Remote_MCP_Urls( 'https://dash.example.org' ) ) );
		$this->assertSame( array(), $remote->enable() );
		$this->assertTrue( Remote_MCP_Settings::enabled() );
	}

	public function test_insecure_home_reported() {
		$self_test = $this->self_test( 'http://dash.example.org' );
		$this->assertContains( 'insecure_home', $self_test->blocking_reasons() );
		$lines = $this->lines( $self_test->run() );
		$this->assertSame( 'fail', $lines['https_home']['status'] );
		$this->assertSame( 'Your site address starts with http://. Remote AI connections need https://.', $lines['https_home']['message'] );
		$this->assertSame( array(), $this->requests, 'nothing to probe without an https address' );
	}

	public function test_header_missing_count_reported() {
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			Remote_MCP_Settings::bump_counter( 'auth_header_missing' );
		}
		Remote_MCP_Settings::bump_counter( 'auth_token_rejected' );

		$line = $this->lines( $this->self_test()->run() )['auth_counters'];
		$this->assertSame( 3, $line['counts']['auth_header_missing'] );
		$this->assertSame( 1, $line['counts']['auth_token_rejected'] );
		$this->assertSame( 'Last 24 hours: requests without an Authorization header: 3; tokens rejected: 1.', $line['message'] );
		$this->assertSame( 'warn', $line['status'], 'no request carried the header, so it may be stripped' );
		$this->assertStringContainsString( 'may be stripping it', $line['fix'] );
	}

	public function test_normal_oauth_discovery_is_not_reported_as_a_stripped_header() {
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		Remote_MCP_Settings::bump_counter( 'auth_header_missing' );
		Remote_MCP_Settings::note_header_received();

		$line = $this->lines( $this->self_test()->run() )['auth_counters'];
		$this->assertSame( 1, $line['counts']['auth_header_missing'], 'the count still shows' );
		$this->assertSame( 'info', $line['status'] );
		$this->assertSame( '', $line['fix'] );
	}

	public function test_static_well_known_conflict_reported() {
		$this->layout( '', array( 'ours', 'html', 'ours', 'html' ) );
		$lines = $this->lines( $this->self_test()->run() );
		$this->assertSame( 'warn', $lines['well_known_conflict']['status'] );
		$this->assertStringContainsString( '/.well-known/openid-configuration', $lines['well_known_conflict']['message'] );
		$this->assertSame( 'pass', $lines['discovery']['status'], 'clients still find the RFC 8414 document first' );

		// Another plugin's rewrite rule for the OIDC path warns; for RFC 8414 it fails. The
		// self-test reads the rewrite table itself rather than trusting the cached result.
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		update_option( 'rewrite_rules', array( '^\.well-known/openid-configuration/?$' => 'index.php?oidc=1' ) );
		delete_option( Remote_MCP_Settings::OPTION_COMPETING_RULES );
		$this->assertSame( 'warn', $this->lines( $this->self_test()->run() )['competing_rules']['status'] );
		update_option( 'rewrite_rules', array( '^\.well-known/oauth-authorization-server/?$' => 'index.php?as=1' ) );
		delete_option( Remote_MCP_Settings::OPTION_COMPETING_RULES );
		$this->assertSame( 'fail', $this->lines( $this->self_test()->run() )['competing_rules']['status'] );
		update_option( 'rewrite_rules', array() );
		$this->assertSame( 'absent', $this->lines( $this->self_test()->run() )['competing_rules']['status'] );
		$this->assertSame( 'absent', $this->lines( $this->self_test()->run() )['well_known_conflict']['status'] );
	}

	public function test_subdirectory_rule_shown_only_when_path_append_fails() {
		list( $lines ) = $this->run_sub( array( 404, 404, 'ours', 'ours' ) );
		$this->assertSame( 'absent', $lines['subdirectory_rule']['status'], 'path-append works' );

		list( $lines ) = $this->run_sub( array( 404, 404, 404, 404 ) );
		$this->assertNotSame( 'absent', $lines['subdirectory_rule']['status'] );

		// The rule already in place (path-insert ours) with path-append blocked: nothing to add.
		list( $lines ) = $this->run_sub( array( 'ours', 'ours', 404, 404 ) );
		$this->assertSame( 'absent', $lines['subdirectory_rule']['status'] );

		// A root install has no path-insert form to route.
		$this->layout( '', array( 404, 404, 404, 404 ) );
		$this->assertSame( 'absent', $this->lines( $this->self_test()->run() )['subdirectory_rule']['status'] );
	}

	public function test_expected_path_insert_404_with_valid_path_append_needs_no_server_rule() {
		list( $lines, $report ) = $this->run_sub( array( 404, 404, 'ours', 'ours' ) );
		$this->assertSame( 'pass', $lines['discovery']['status'] );
		$this->assertSame( array_fill_keys( array_keys( Remote_MCP_Self_Test::CLIENT_ORDERS ), 'pass' ), $lines['discovery']['clients'] );
		$this->assertSame( 'pass', $lines['path_routing']['status'] );
		$this->assertSame( 'absent', $lines['subdirectory_rule']['status'] );
		$this->assertSame( array(), $report['blocking'] );
	}

	public function test_foreign_issuer_at_path_insert_warns() {
		list( $lines, $report ) = $this->run_sub( array( 'foreign', 'foreign', 'ours', 'ours' ) );
		$this->assertSame( 'warn', $lines['discovery']['status'] );
		$this->assertSame( array_fill_keys( array_keys( Remote_MCP_Self_Test::CLIENT_ORDERS ), 'warn' ), $lines['discovery']['clients'] );
		foreach ( Remote_MCP_Self_Test::CLIENT_ORDERS as $client ) {
			$this->assertStringContainsString( $client['label'], $lines['discovery']['message'] );
		}
		$this->assertNotContains( 'discovery_failed', $report['blocking'], 'a warning never blocks' );
	}

	public function test_oidc_path_insert_only_fails_for_chatgpt() {
		list( $lines, $report ) = $this->run_sub( array( 404, 'ours', 404, 404 ) );
		$this->assertSame(
			array(
				'claude_code' => 'pass',
				'claude_ai'   => 'pass',
				'vscode'      => 'pass',
				'chatgpt'     => 'fail',
			),
			$lines['discovery']['clients']
		);
		$this->assertSame( 'fail', $lines['discovery']['status'] );
		$this->assertStringContainsString( 'ChatGPT', $lines['discovery']['message'] );
		$this->assertContains( 'discovery_failed', $report['blocking'] );
	}

	public function test_only_oidc_path_append_warns_for_chatgpt() {
		list( $lines, $report ) = $this->run_sub( array( 404, 404, 404, 'ours' ) );
		$this->assertSame(
			array(
				'claude_code' => 'pass',
				'claude_ai'   => 'pass',
				'vscode'      => 'pass',
				'chatgpt'     => 'warn',
			),
			$lines['discovery']['clients']
		);
		$this->assertSame( 'warn', $lines['discovery']['status'] );
		$this->assertNotContains( 'discovery_failed', $report['blocking'] );
	}

	public function test_only_rfc8414_path_append_fails_for_spec_order_clients() {
		list( $lines, $report ) = $this->run_sub( array( 404, 404, 'ours', 404 ) );
		$this->assertSame(
			array(
				'claude_code' => 'fail',
				'claude_ai'   => 'fail',
				'vscode'      => 'fail',
				'chatgpt'     => 'pass',
			),
			$lines['discovery']['clients']
		);
		$this->assertContains( 'discovery_failed', $report['blocking'] );
	}

	public function test_discovery_fail_names_clients_and_blocks_enable() {
		list( $lines, $report ) = $this->run_sub( array( 404, 404, 404, 404 ) );
		$this->assertSame( 'fail', $lines['discovery']['status'] );
		foreach ( Remote_MCP_Self_Test::CLIENT_ORDERS as $client ) {
			$this->assertStringContainsString( $client['label'], $lines['discovery']['message'] );
		}
		$this->assertContains( 'discovery_failed', $report['blocking'] );
		$this->assertNotSame( 'absent', $lines['subdirectory_rule']['status'], 'the fix is shown' );

		$remote = new Remote_MCP( new Remote_MCP_Panel_Test_Services( array( 'enable_check' => $this->self_test( 'https://dash.example.org/sub' ) ), $this->store, new Remote_MCP_Urls( 'https://dash.example.org/sub' ) ) );
		$this->assertContains( 'discovery_failed', $remote->enable() );
		$this->assertFalse( Remote_MCP_Settings::enabled() );
	}

	public function test_root_install_requires_rfc8414_document() {
		$this->layout( '', array( 404, 'ours', 404, 'ours' ) );
		$self_test = $this->self_test();
		$report    = $self_test->run();
		$lines     = $this->lines( $report );
		$this->assertSame( array_fill_keys( array_keys( Remote_MCP_Self_Test::CLIENT_ORDERS ), 'fail' ), $lines['discovery']['clients'] );
		$this->assertContains( 'discovery_failed', $report['blocking'] );

		$this->layout( '', array( 'foreign', 'ours', 'foreign', 'ours' ) );
		$this->assertSame( array_fill_keys( array_keys( Remote_MCP_Self_Test::CLIENT_ORDERS ), 'fail' ), $this->lines( $self_test->run() )['discovery']['clients'], 'another issuer there fails too' );

		$this->layout( '', array( 'ours', 404, 'ours', 404 ) );
		$this->assertSame( array_fill_keys( array_keys( Remote_MCP_Self_Test::CLIENT_ORDERS ), 'pass' ), $this->lines( $self_test->run() )['discovery']['clients'] );
	}

	public function test_non_443_home_warns_by_default() {
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		$this->issuer = 'https://dash.example.org:8443';
		$report       = $this->self_test( 'https://dash.example.org:8443' )->run();
		$lines        = $this->lines( $report );
		$this->assertSame( 'warn', $lines['non_443']['status'] );
		$this->assertSame( 'Remote AI apps may not reach port 8443.', $lines['non_443']['message'] );
		$this->assertNotContains( 'non_443_port', $report['blocking'] );
		$this->assertSame( 'absent', $this->lines( $this->self_test()->run() )['non_443']['status'], 'no line on 443' );
	}

	public function test_activity_log_off_fails_and_blocks_enable() {
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		$services  = new Remote_MCP_Panel_Test_Services( array( 'known_clients' => $this->known ), $this->store, new Remote_MCP_Urls( 'https://dash.example.org' ) );
		$self_test = new class( $services ) extends Remote_MCP_Self_Test {
			protected function activity_log_available(): bool {
				return false;
			}
		};
		$lines     = $this->lines( $self_test->run() );
		$this->assertSame( 'fail', $lines['activity_log']['status'] );
		$this->assertSame( "Turn on the activity log first. AI apps can't make changes or use tools MainWP hasn't reviewed unless each call is recorded.", $lines['activity_log']['message'] );
		$this->assertContains( 'activity_log_off', $self_test->blocking_reasons() );
	}

	public function test_non_443_home_blocks_enable_when_policy_is_refuse() {
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		$this->issuer = 'https://dash.example.org:8443';
		$services     = new Remote_MCP_Panel_Test_Services( array( 'known_clients' => $this->known ), $this->store, new Remote_MCP_Urls( 'https://dash.example.org:8443' ) );
		$self_test    = new class( $services ) extends Remote_MCP_Self_Test {
			protected function non_443_policy(): string {
				return 'refuse';
			}
			protected function activity_log_available(): bool {
				return true;
			}
		};
		$report       = $self_test->run();
		$lines        = $this->lines( $report );
		$this->assertSame( 'fail', $lines['non_443']['status'] );
		$this->assertSame( "Claude and ChatGPT can't reach port 8443; serve the Dashboard on 443.", $lines['non_443']['message'] );
		$this->assertContains( 'non_443_port', $self_test->blocking_reasons() );
	}

	public function test_dot_path_deny_or_wrong_install_issuer_reported() {
		// Dot-path deny with no PHP fallback: path-append 404s.
		list( $lines ) = $this->run_sub( array( 404, 404, 404, 404 ) );
		$this->assertSame( 'fail', $lines['path_append']['status'] );
		$this->assertNotSame( 'absent', $lines['subdirectory_rule']['status'] );

		// With a 404-to-PHP fallback the root install answers, with its own issuer.
		list( $lines ) = $this->run_sub( array( 404, 404, 'root', 'root' ) );
		$this->assertSame( 'fail', $lines['path_append']['status'] );
		$this->assertStringContainsString( 'another WordPress install', $lines['path_append']['message'] );
		$this->assertNotSame( 'absent', $lines['subdirectory_rule']['status'] );

		list( $lines ) = $this->run_sub( array( 404, 404, 'ours', 'ours' ) );
		$this->assertSame( 'absent', $lines['path_append']['status'] );
	}

	public function test_subdirectory_rule_labels_nginx_tested_and_apache_untested() {
		list( $lines ) = $this->run_sub( array( 404, 404, 404, 404 ) );
		$rule          = $lines['subdirectory_rule'];
		$this->assertStringContainsString( 'nginx (tested)', $rule['fix'] );
		$this->assertStringContainsString( 'rewrite ^/\.well-known/(oauth-authorization-server|openid-configuration)/sub(/.*)?$ /sub/index.php last;', $rule['fix'] );
		$this->assertStringContainsString( 'Apache (untested)', $rule['fix'] );
		$this->assertStringContainsString( 'RewriteRule ^\.well-known/(oauth-authorization-server|openid-configuration)/sub(/.*)?$ /sub/index.php [L]', $rule['fix'] );
	}

	public function test_authorization_header_probe_reported() {
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ), array( 'authorization' => false ) );
		$line = $this->lines( $this->self_test()->run() )['authorization_header'];
		$this->assertSame( 'fail', $line['status'] );
		$this->assertStringContainsString( "Authorization header doesn't reach WordPress", $line['message'] );

		$probe = null;
		foreach ( $this->requests as list( $url, $args ) ) {
			if ( 0 === strpos( $url, 'https://' ) && false !== strpos( $url, 'mainwp-remote/v1/mcp' ) ) {
				$probe = $args;
			}
		}
		$this->assertNotNull( $probe );
		$this->assertMatchesRegularExpression( '/^Bearer \S+$/', $probe['headers']['Authorization'], 'the probe sends a dummy bearer' );

		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		$this->assertSame( 'pass', $this->lines( $this->self_test()->run() )['authorization_header']['status'] );
	}

	public function test_invalid_bundled_client_file_reported() {
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		unset( $this->known->entries['https://chatgpt.com/oauth/client.json'] );
		Remote_MCP_Settings::bump_counter( 'unknown_client' );

		$lines = $this->lines( $this->self_test()->run() );
		$this->assertSame( 'fail', $lines['known_clients']['status'] );
		$this->assertStringContainsString( 'ChatGPT', $lines['known_clients']['message'] );
		$this->assertStringNotContainsString( 'Claude Code', $lines['known_clients']['message'] );
		$this->assertSame( 1, $lines['unknown_clients']['count'] );

		$this->known->entries['https://chatgpt.com/oauth/client.json'] = array( 'label' => 'ChatGPT' );
		$this->assertSame( 'pass', $this->lines( $this->self_test()->run() )['known_clients']['status'] );
	}

	public function test_requests_reaching_wordpress_as_http_reported() {
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ), array( 'https' => false ) );
		$lines = $this->lines( $this->self_test()->run() );
		$this->assertSame( 'fail', $lines['https_requests']['status'] );
		$this->assertStringContainsString( 'Requests reach WordPress as http://', $lines['https_requests']['message'] );
		$this->assertSame( 'pass', $lines['plain_http']['status'], 'the plain-HTTP probe got its 400' );

		// A plain-HTTP request that succeeds is its own failure.
		$this->layout( '', array( 'ours', 'ours', 'ours', 'ours' ) );
		$this->http_status = 200;
		$lines             = $this->lines( $this->self_test()->run() );
		$this->assertSame( 'pass', $lines['https_requests']['status'] );
		$this->assertSame( 'fail', $lines['plain_http']['status'] );
		$http = array_filter(
			$this->requests,
			function ( $request ) {
				return 0 === strpos( $request[0], 'http://' );
			}
		);
		foreach ( $http as $request ) {
			$this->assertArrayNotHasKey( 'Authorization', (array) $request[1]['headers'], 'no credential goes out in clear' );
			$this->assertSame( 0, (int) $request[1]['redirection'] );
		}
	}

	public function test_unrouted_well_known_paths_reported() {
		$this->layout( '', array( 404, 404, 404, 404 ) );
		$report = $this->self_test()->run();
		$lines  = $this->lines( $report );
		$this->assertSame( 'fail', $lines['path_routing']['status'] );
		$this->assertStringContainsString( 'RewriteRule ^\.well-known/(oauth-authorization-server|openid-configuration)$ /index.php [L]', $lines['path_routing']['fix'] );
		$this->assertContains( 'path_routing', $report['blocking'] );

		update_option( 'permalink_structure', '' );
		$lines = $this->lines( $this->self_test()->run() );
		$this->assertStringContainsString( 'Turn on pretty permalinks', $lines['path_routing']['fix'] );
	}
}
