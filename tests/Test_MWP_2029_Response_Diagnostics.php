<?php
/**
 * Connection response diagnostics.
 *
 * @package MainWP/Dashboard/Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect_Failure_Ux;

/**
 * Exercise capped diagnostics without displaying the remote body.
 */
class Test_MWP_2029_Response_Diagnostics extends \WP_UnitTestCase {

	/**
	 * Empty response has a next action.
	 */
	public function test_empty_response_has_a_next_action(): void {
		$text = MainWP_Connect_Failure_Ux::describe_received_response( '' );
		$this->assertStringContainsString( 'empty response', $text );
		$this->assertStringContainsString( 'host', $text );
	}

	/**
	 * Html title identifies replacement page.
	 */
	public function test_html_title_identifies_replacement_page(): void {
		$text = MainWP_Connect_Failure_Ux::describe_received_response( '<html><title>Maintenance window</title><p>Try later</p></html>' );
		$this->assertStringContainsString( 'HTML page', $text );
		$this->assertStringContainsString( 'Maintenance window', $text );
		$this->assertStringNotContainsString( '<title>', $text );
	}

	/**
	 * Challenge page is identified.
	 */
	public function test_challenge_page_is_identified(): void {
		$text = MainWP_Connect_Failure_Ux::describe_received_response( '<html><title>Just a moment</title><p>Checking your browser</p></html>' );
		$this->assertStringContainsString( 'login or challenge page', $text );
	}

	/**
	 * Plain response has a short excerpt.
	 */
	public function test_plain_response_has_a_short_excerpt(): void {
		$text = MainWP_Connect_Failure_Ux::describe_received_response( 'Temporarily unavailable' );
		$this->assertStringContainsString( 'text instead of the MainWP response', $text );
		$this->assertStringContainsString( 'Temporarily unavailable', $text );
	}

	/**
	 * Mainwp frame is never shown or decoded for display.
	 */
	public function test_mainwp_frame_is_never_shown_or_decoded_for_display(): void {
		$payload = base64_encode( '{"register":"OK","uniqueId":"obviously-fake-private-value"}' );
		foreach ( array( '<mainwp>' . $payload . '</mainwp>', '<mainwp>broken', '<mainwp></mainwp>', '&lt;mainwp&gt;' . $payload . '&lt;/mainwp&gt;', rawurlencode( '<mainwp>' . $payload . '</mainwp>' ) ) as $body ) {
			$text = MainWP_Connect_Failure_Ux::describe_received_response( $body );
			$this->assertStringContainsString( 'MainWP response was found', $text );
			$this->assertStringNotContainsString( $payload, $text );
			$this->assertStringNotContainsString( 'obviously-fake-private-value', $text );
		}
	}

	/**
	 * Binary response is hidden.
	 */
	public function test_binary_response_is_hidden(): void {
		$text = MainWP_Connect_Failure_Ux::describe_received_response( "\x1f\x8b\x08\x00fake-binary" );
		$this->assertStringContainsString( 'encoded or compressed', $text );
		$this->assertStringNotContainsString( 'fake-binary', $text );
	}

	/**
	 * Sensitive html and encoded fields are hidden.
	 */
	public function test_sensitive_html_and_encoded_fields_are_hidden(): void {
		foreach ( array(
			'<html><title>Access denied</title><p>userpwd=obviously-fake-password</p></html>',
			'<title>Access denied</title><input value="obviously-fake-password" name="password">',
			'<title>obviously-fake-password</title><input name="password">',
			'nonce%3Dobviously-fake-password',
			'Authorization: Bearer obviously-fake-password',
			'Cookie: session=obviously-fake-password',
		) as $body ) {
			$text = MainWP_Connect_Failure_Ux::describe_received_response( $body );
			$this->assertStringNotContainsString( 'obviously-fake-password', $text );
			$this->assertStringContainsString( 'hidden', $text );
		}
	}

	/** Cookie names need no password or token marker to be private. */
	public function test_cookie_header_excerpts_are_hidden(): void {
		foreach ( array( 'Cookie: wordpress_logged_in_abc=OBVIOUS_FAKE_VALUE', 'Set-Cookie: wpfoo=OBVIOUS_FAKE_VALUE; Path=/', 'Cookie%3A%20wordpress_logged_in_abc%3DOBVIOUS_FAKE_VALUE' ) as $body ) {
			$text = MainWP_Connect_Failure_Ux::describe_received_response( $body );
			$this->assertStringContainsString( 'hidden', $text );
			$this->assertStringNotContainsString( 'OBVIOUS_FAKE_VALUE', $text );
		}
	}

	/**
	 * Large body is capped before processing.
	 */
	public function test_large_body_is_capped_before_processing(): void {
		$body  = str_repeat( 'Safe content ', 18000 ) . ' password=obviously-fake-password';
		$start = microtime( true );
		$text  = MainWP_Connect_Failure_Ux::describe_received_response( $body );
		$this->assertLessThan( 1.0, microtime( true ) - $start, 'A 200 KB response must not be redacted in full.' );
		$this->assertLessThan( 600, strlen( $text ) );
		$this->assertStringNotContainsString( 'hidden', $text );
		$this->assertStringNotContainsString( 'obviously-fake-password', $text );
		$this->assertSame( MainWP_Connect_Failure_Ux::describe_received_response( substr( $body, 0, 4096 ) ), $text );
	}

	/**
	 * Existing callers still hide bodies.
	 */
	public function test_existing_callers_still_hide_bodies(): void {
		$summary = MainWP_Connect_Failure_Ux::summarize_response( 200, '', '<title>Private remote content</title>' );
		$this->assertSame( '', $summary['body'] );
		$this->assertStringNotContainsString( 'Private remote content', $summary['text'] );
	}

	/**
	 * Diagnostic survives output and exception rendering.
	 */
	public function test_diagnostic_survives_output_and_exception_rendering(): void {
		set_transient(
			'mainwp_public_dashboard_ip_data',
			array(
				'value'           => '203.0.113.10',
				'status'          => 'pass',
				'community_value' => 'Detected',
			),
			HOUR_IN_SECONDS
		);
		$diagnostic = MainWP_Connect_Failure_Ux::describe_received_response( '<title>Maintenance window</title>' );
		$output     = MainWP_Connect_Failure_Ux::enrich_output(
			array( 'http_status' => 200 ),
			array(
				'http_status'         => 200,
				'response_diagnostic' => $diagnostic,
				'skip_outbound_ip'    => true,
			)
		);
		$details    = MainWP_Connect_Failure_Ux::from_exception( null, $output );
		$this->assertStringContainsString( 'Maintenance window', $details['response']['text'] );
		$this->assertStringNotContainsString( 'no readable child response', $details['response']['text'] );
		$this->assertSame( '', $output['response_body'] );
		$this->assertSame( $diagnostic, $output['response_diagnostic'] );
		$this->assertStringContainsString( 'Maintenance window', MainWP_Connect_Failure_Ux::render_html( $details ) );
	}
	/** Rendering escapes metadata even if its source contains markup. */
	public function test_rendering_escapes_diagnostic_metadata(): void {
		set_transient(
			'mainwp_public_dashboard_ip_data',
			array(
				'value'           => '203.0.113.10',
				'status'          => 'pass',
				'community_value' => 'Detected',
			),
			HOUR_IN_SECONDS
		);
		$details = MainWP_Connect_Failure_Ux::build_details(
			array(
				'http_status'         => 200,
				'response_diagnostic' => '<img src=x onerror=alert(1)>',
				'skip_outbound_ip'    => true,
			)
		);
		$html    = MainWP_Connect_Failure_Ux::render_html( $details );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
	}

	/** The real cURL path retains a safe description while hiding the body. */
	public function test_transport_html_failure_keeps_diagnostic_under_default_hide_filter(): void {
		list( $information, $output, $exception ) = $this->request_fixture( '<html><title>Maintenance window</title><p>userpwd=obviously-fake-password</p></html>' );
		$this->assertInstanceOf( \MainWP\Dashboard\MainWP_Exception::class, $exception );
		$this->assertSame( 'NOMAINWP', $exception->getMessage() );
		$this->assertSame( 200, $output['http_status'] );
		$this->assertSame( '', $output['response_body'] );
		$this->assertStringContainsString( 'HTML page', $output['failure_ux'] );
		$this->assertStringContainsString( 'hidden', $output['failure_ux'] );
		$this->assertStringNotContainsString( 'obviously-fake-password', wp_json_encode( $output ) );
		$this->assertStringContainsString( 'HTML page', MainWP_Connect_Failure_Ux::readable_response_from_output( array(), $exception ) );
	}

	/** A matching frame is insufficient when its JSON or registration shape is invalid. */
	public function test_transport_unreadable_frames_keep_diagnostic(): void {
		foreach ( array( 'not JSON', 'null', '[]', '{"other":"value"}' ) as $payload ) {
			list( $information, $output, $exception ) = $this->request_fixture( '<mainwp>' . base64_encode( $payload ) . '</mainwp>' );
			$this->assertNull( $exception );
			$this->assertArrayHasKey( 'failure_ux', $output );
			$this->assertStringContainsString( 'MainWP response was found', $output['failure_ux'], $payload );
			$this->assertStringNotContainsString( 'no readable child response', $output['failure_ux'], $payload );
			$this->assertSame( '', $output['response_body'] );
		}
	}

	/** Successfully decoded errors are described as readable errors. */
	public function test_transport_specific_child_error_is_preserved(): void {
		$payload                                  = array(
			'error'      => 'Administrator user does not exist.',
			'error_code' => 'REG_ERROR8',
		);
		list( $information, $output, $exception ) = $this->request_fixture( '<mainwp>' . base64_encode( wp_json_encode( $payload ) ) . '</mainwp>' );
		$this->assertNull( $exception );
		$this->assertSame( $payload['error'], $information['error'] );
		$this->assertSame( 'REG_ERROR8', $output['child_error_code'] );
		$this->assertStringContainsString( 'MainWP error response', $output['response_diagnostic'] );
		$this->assertStringNotContainsString( 'could not be read', $output['failure_ux'] );
	}

	/** Older Child success fields still return through the existing parser. */
	public function test_transport_success_does_not_add_failure_ui(): void {
		$payload                                  = array(
			'register'  => 'OK',
			'version'   => '6.2',
			'wpversion' => '6.8',
			'wpe'       => 0,
		);
		list( $information, $output, $exception ) = $this->request_fixture( '<mainwp>' . base64_encode( wp_json_encode( $payload ) ) . '</mainwp>' );
		$this->assertNull( $exception );
		$this->assertSame( $payload, $information );
		$this->assertArrayNotHasKey( 'failure_ux', $output );
	}

	/** Rendered excerpts cannot introduce markup into the diagnostics block. */
	public function test_transport_excerpt_is_escaped_for_html(): void {
		list( $information, $output, $exception ) = $this->request_fixture( '<html><title>A &amp; B</title><script>alert(1)</script></html>' );
		$this->assertInstanceOf( \MainWP\Dashboard\MainWP_Exception::class, $exception );
		$this->assertStringContainsString( 'A &amp; B', $output['failure_ux'] );
		$this->assertStringNotContainsString( '<script>', $output['failure_ux'] );
		$this->assertStringNotContainsString( 'alert(1)', $output['failure_ux'] );
	}

	/** A successful HTTP probe cannot establish that registration will succeed. */
	public function test_http_probe_does_not_replace_registration_result(): void {
		list( $probe ) = $this->request_fixture( '<html><title>Maintenance</title></html>', true );
		$this->assertSame( 200, $probe['httpCode'] );
		$this->assertSame( '', $probe['error'] );
		$this->assertStringContainsString( 'HTML page', $probe['response_diagnostic'] );
		$this->assertStringContainsString( 'Maintenance', $probe['response_readable'] );
		list( $information, $output, $exception ) = $this->request_fixture( '<mainwp>' . base64_encode( '{"error":"Administrator user does not exist.","error_code":"REG_ERROR8"}' ) . '</mainwp>' );
		$this->assertNull( $exception );
		$this->assertSame( 'Administrator user does not exist.', $information['error'] );
		$this->assertSame( 'REG_ERROR8', $output['child_error_code'] );
		$this->assertStringNotContainsString( 'Maintenance', $output['response_readable'] );
	}

	/**
	 * Serve one local HTTP response and exercise production transport code.
	 *
	 * @param string $body Fake response body.
	 * @param bool   $probe Whether to run the HTTP reachability probe.
	 * @return array Response information, diagnostic output and exception.
	 */
	private function request_fixture( $body, $probe = false ) {
		$script = <<<'FIXTURE'
$body = base64_decode(trim(fgets(STDIN)));
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) { fwrite(STDERR, 'Cannot open loopback fixture'); exit(1); }
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
$client = stream_socket_accept($server, 10);
if (!$client) { fclose($server); exit(2); }
stream_set_timeout($client, 5);
$headers = '';
while (false !== ($line = fgets($client)) && "\r\n" !== $line) { $headers .= $line; }
$remaining = preg_match('/Content-Length:\s*(\d+)/i', $headers, $match) ? (int)$match[1] : 0;
while ($remaining > 0 && !feof($client)) {
    $chunk = fread($client, $remaining);
    if (false === $chunk || '' === $chunk) { break; }
    $remaining -= strlen($chunk);
}
fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
fclose($client);
fclose($server);
FIXTURE;
		$server = proc_open( array( PHP_BINARY, '-r', $script ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $server, 'Loopback HTTP fixture must start.' );
		fwrite( $pipes[0], base64_encode( $body ) . "\n" );
		fclose( $pipes[0] );
		stream_set_timeout( $pipes[1], 5 );
		$address = trim( (string) fgets( $pipes[1] ) );
		$this->assertNotSame( '', $address, 'Loopback fixture must publish its bound address.' );
		set_transient(
			'mainwp_public_dashboard_ip_data',
			array(
				'value'           => '203.0.113.10',
				'status'          => 'pass',
				'community_value' => 'Detected',
			),
			HOUR_IN_SECONDS
		);
		$information = null;
		$output      = array();
		$exception   = null;
		try {
			if ( $probe ) {
				$information = \MainWP\Dashboard\MainWP_Connect::try_visit( 'http://' . $address . '/', false );
			} else {
				$website     = null;
				$information = \MainWP\Dashboard\MainWP_Connect::fetch_url_site( $website, 'http://' . $address . '/wp-admin/admin-ajax.php', 'function=register&user=fixture', false, false, null, null, 0, array( 'function' => 'register' ), $output );
			}
		} catch ( \MainWP\Dashboard\MainWP_Exception $caught ) {
			$exception = $caught;
		} finally {
			proc_terminate( $server );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $server );
		}
		return array( $information, $output, $exception );
	}
}
