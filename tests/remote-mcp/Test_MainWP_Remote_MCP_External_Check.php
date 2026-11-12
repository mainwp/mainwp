<?php
/**
 * Remote MCP external check caller tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Self_Test;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_External_Check
 */
class Test_MainWP_Remote_MCP_External_Check extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Outbound requests, as [ url, args ].
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Answer every outbound request with $response.
	 *
	 * @param array|\WP_Error $response Response.
	 */
	private function answer( $response ): void {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $response ) {
				$this->requests[] = array( $url, $args );
				return $response;
			},
			10,
			3
		);
	}

	/**
	 * A JSON response.
	 *
	 * @param int   $status Status.
	 * @param mixed $body   Body.
	 * @return array
	 */
	private function json( int $status, $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 'x',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Option rows, to prove nothing is stored.
	 *
	 * @return int
	 */
	private function option_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
	}

	public function test_external_check_describes_each_success_and_does_not_call_a_failure_passed() {
		$checks = array_map(
			fn( $id ) => array( 'id' => $id, 'status' => 'pass', 'code' => null ),
			Remote_MCP_Self_Test::EXTERNAL_CHECKS
		);
		$success_messages = array(
			'DNS: Your Dashboard\'s domain resolves to a public IP address.',
			'TLS: Your Dashboard accepts a secure connection with a valid certificate.',
			'Discovery: Apps can reach the well-known sign-in discovery address.',
			'Metadata: The sign-in information contains the required fields and matches your Dashboard\'s address.',
			'MCP sign-in: The MCP address returns HTTP 401 with a valid sign-in request.',
		);
		add_filter(
			'gettext_mainwp',
			static fn( $translation, $text ) => 'DNS' === $text || in_array( $text, $success_messages, true ) ? 'Translated ' . $text : $translation,
			10,
			2
		);
		$this->answer( $this->json( 200, array( 'schema' => 1, 'checks' => $checks ) ) );
		$self_test = new Remote_MCP_Self_Test( $this->panel_services() );
		$lines     = $self_test->external_check();
		$this->assertSame( array_map( static fn( $text ) => 'Translated ' . $text, $success_messages ), array_column( $lines, 'message' ) );
		$this->assertCount( 5, array_unique( array_column( $lines, 'message' ) ) );
		foreach ( array( 'warn', 'fail', 'skipped' ) as $status ) {
			remove_all_filters( 'pre_http_request' );
			$checks[0]['status'] = $status;
			$this->answer( $this->json( 200, array( 'schema' => 1, 'checks' => $checks ) ) );
			$line = $self_test->external_check()[0];
			$this->assertSame( $status, $line['status'] );
			$this->assertStringStartsWith( 'Translated DNS:', $line['message'] );
			$this->assertStringNotContainsString( 'public IP address', $line['message'] );
		}
	}

	public function test_external_check_sends_only_dashboard_url_and_maps_contract_codes() {
		$self_test = new Remote_MCP_Self_Test( $this->panel_services() );
		$checks    = array(
			array(
				'id'     => 'dns',
				'status' => 'pass',
				'code'   => null,
			),
			array(
				'id'     => 'tls',
				'status' => 'pass',
				'code'   => null,
			),
			array(
				'id'     => 'discovery',
				'status' => 'fail',
				'code'   => 'discovery_blocked',
			),
			array(
				'id'     => 'metadata',
				'status' => 'skipped',
				'code'   => 'skipped_after_failure',
			),
			array(
				'id'     => 'mcp_401',
				'status' => 'skipped',
				'code'   => 'not_a_contract_code',
			),
		);
		$this->answer(
			$this->json(
				200,
				array(
					'schema'  => 1,
					'overall' => 'fail',
					'checks'  => $checks,
				)
			)
		);
		$rows  = $this->option_rows();
		$lines = $self_test->external_check();

		// One request, to the checker, carrying the canonical home and nothing else.
		$this->assertCount( 1, $this->requests );
		list( $url, $args ) = $this->requests[0];
		$this->assertSame( 'https://ai-bridge.mainwp.com/reachability/check', $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame(
			array(
				'schema'        => 1,
				'dashboard_url' => 'https://dash.example.org',
			),
			json_decode( $args['body'], true )
		);
		$this->assertSame( 'MainWP Remote MCP', $args['user-agent'] );
		$this->assertSame( 30, (int) $args['timeout'] );
		$this->assertSame( array(), $args['cookies'] );
		$this->assertSame( 0, (int) $args['redirection'] );
		$headers = array_change_key_case( (array) $args['headers'] );
		$this->assertSame( array( 'content-type' ), array_keys( $headers ), 'no Authorization or other header' );
		$this->assertTrue( (bool) $args['reject_unsafe_urls'], 'sent through wp_safe_remote_post()' );

		// One line per check, mapped by code; an unknown code reads as the generic line.
		$this->assertSame( array( 'dns', 'tls', 'discovery', 'metadata', 'mcp_401' ), array_column( $lines, 'id' ) );
		$this->assertSame( array( 'pass', 'pass', 'fail', 'skipped', 'skipped' ), array_column( $lines, 'status' ) );
		$this->assertSame( Remote_MCP_Self_Test::EXTERNAL_CODES['discovery_blocked'], $lines[2]['message'] );
		$this->assertNotEmpty( $lines[2]['help'] );
		$this->assertSame( Remote_MCP_Self_Test::EXTERNAL_CODES['skipped_after_failure'], $lines[3]['message'] );
		$this->assertSame( Remote_MCP_Self_Test::EXTERNAL_GENERIC, $lines[4]['message'] );
		$this->assertSame( $rows, $this->option_rows(), 'nothing is stored' );

		// Every error maps to the one generic line, 429 included (no Retry-After promise).
		$errors = array(
			$this->json( 400, array( 'error' => 'invalid_url' ) ),
			$this->json( 400, array( 'error' => 'invalid_request' ) ),
			$this->json( 429, array( 'error' => 'rate_limited' ) ),
			$this->json( 503, array( 'error' => 'unavailable' ) ),
			$this->json( 200, 'not json' ),
			$this->json( 200, array( 'schema' => 2 ) ),
			new \WP_Error( 'http_request_failed', 'timed out' ),
		);
		foreach ( $errors as $i => $error ) {
			remove_all_filters( 'pre_http_request' );
			$this->answer( $error );
			$lines = $self_test->external_check();
			$this->assertCount( 1, $lines, 'error ' . $i );
			$this->assertSame( 'external', $lines[0]['id'] );
			$this->assertSame( 'fail', $lines[0]['status'] );
			$this->assertSame( Remote_MCP_Self_Test::EXTERNAL_GENERIC, $lines[0]['message'] );
		}

		// Only a nonce-checked click sends it.
		remove_all_filters( 'pre_http_request' );
		$this->answer( $this->json( 503, array( 'error' => 'unavailable' ) ) );
		$this->assertSame( 'bad_nonce', $this->post( 'external_check', '', array(), 'bad' )['code'] );
		$this->assertCount( 0, $this->requests );
		$clicked = $this->post( 'external_check' );
		$this->assertTrue( $clicked['ok'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( Remote_MCP_Self_Test::EXTERNAL_GENERIC, $clicked['lines'][0]['message'] );
	}
}
