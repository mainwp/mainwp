<?php
/**
 * Readable transport errors and their display boundaries.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Error_Helper;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/** Checks classification, escaping and transport metadata without live requests. */
class Test_Readable_Transport_Errors extends \WP_UnitTestCase {

	/**
	 * Known failures keep the exact guidance and raw details.
	 *
	 * @dataProvider transport_errors
	 * @param int|null $errno Error number.
	 * @param string   $raw Raw message.
	 * @param string   $reason Expected reason.
	 * @param string   $copy Expected guidance.
	 * @param string   $context Request destination.
	 */
	public function test_classifier( $errno, $raw, $reason, $copy, $context = 'site' ): void {
		$result = MainWP_Error_Helper::classify_transport_error( $errno, $raw, $context );
		if ( null === $reason ) {
			$this->assertNull( $result );
			return;
		}
		$this->assertSame(
			array(
				'reason'  => $reason,
				'message' => $copy . ' Details: ' . $raw,
			),
			$result
		);
	}

	/** Classifier cases include prefixed WP_Error text.
	 *
	 * @return array Cases. */
	public static function transport_errors(): array {
		$resolver = 'The server that hosts your MainWP Dashboard could not start a new connection. This usually means your hosting account has hit a process or memory limit. Ask your host to check the process (NPROC) and memory limits on the account, or move the Dashboard to a VPS.';
		$cases    = array();
		foreach ( array( 2, 6, 7, null ) as $errno ) {
			$cases[] = array( $errno, 'getaddrinfo() thread failed to start', 'server_resources', $resolver );
		}
		foreach ( array( 2, 6 ) as $errno ) {
			$cases[] = array( null, 'cURL error ' . $errno . ': getaddrinfo() thread failed to start', 'server_resources', $resolver );
		}
		$table = array(
			6  => array( 'dns', 'The Dashboard could not look up this site\'s address. Check that the domain is spelled correctly and has a working DNS record. If every site fails this way, DNS on the Dashboard server is broken and your host needs to fix it.' ),
			7  => array( 'connect_failed', 'The Dashboard could not connect to this site. The site may be down, or a firewall may be blocking connections from the Dashboard server. If the site opens in your browser, ask its host to allow your Dashboard server\'s IP address.' ),
			28 => array( 'timeout', 'The site did not respond in time. Its server may be slow or overloaded, or a firewall may be dropping the connection. If this keeps happening, ask the site\'s host to look at server load and firewall rules.' ),
			60 => array( 'ssl_peer', 'The site\'s SSL certificate could not be verified. Check that the certificate is valid, not expired, and issued for this domain. If every site fails this way, the CA certificates on the Dashboard server are out of date and your host needs to update them.' ),
			35 => array( 'ssl_handshake', 'The secure connection to this site could not be set up. The two servers may not share a supported TLS version, or a firewall may be cutting the connection. If every site fails this way, ask your host to update the TLS libraries on the Dashboard server.' ),
			58 => array( 'ssl_local', 'The Dashboard server\'s own SSL setup is broken. It could not load its CA certificate bundle or client certificate. Ask your host to fix it.' ),
			77 => array( 'ssl_local', 'The Dashboard server\'s own SSL setup is broken. It could not load its CA certificate bundle or client certificate. Ask your host to fix it.' ),
		);
		foreach ( $table as $errno => $expected ) {
			$cases[] = array( $errno, 'fixture failure', $expected[0], $expected[1] );
			$cases[] = array( null, 'cURL error ' . $errno . ': fixture failure', $expected[0], $expected[1] );
		}
		$cases[] = array( 2, 'getaddrinfo() thread failed to start', 'server_resources', 'The server that hosts your MainWP Dashboard could not start a new connection. This usually means your hosting account has hit a process or memory limit. Ask your host to check the process (NPROC) and memory limits on the account, or move the Dashboard to a VPS.', 'mainwp_api' );
		$cases[] = array( null, 'cURL error 2: getaddrinfo() thread failed to start', 'server_resources', 'The server that hosts your MainWP Dashboard could not start a new connection. This usually means your hosting account has hit a process or memory limit. Ask your host to check the process (NPROC) and memory limits on the account, or move the Dashboard to a VPS.', 'mainwp_api' );
		$cases[] = array( 6, 'fixture failure', 'dns', 'The Dashboard server could not look up the address of the MainWP license server. DNS on the Dashboard server is broken, or a firewall is blocking lookups. Contact your host.', 'mainwp_api' );
		$cases[] = array( null, 'cURL error 6: fixture failure', 'dns', 'The Dashboard server could not look up the address of the MainWP license server. DNS on the Dashboard server is broken, or a firewall is blocking lookups. Contact your host.', 'mainwp_api' );
		$cases[] = array( 7, 'fixture failure', 'connect_failed', 'The Dashboard server could not connect to the MainWP license server. A firewall on the Dashboard server is probably blocking outbound connections. Contact your host.', 'mainwp_api' );
		$cases[] = array( null, 'cURL error 7: fixture failure', 'connect_failed', 'The Dashboard server could not connect to the MainWP license server. A firewall on the Dashboard server is probably blocking outbound connections. Contact your host.', 'mainwp_api' );
		$cases[] = array( 28, 'fixture failure', 'timeout', 'The MainWP license server did not respond in time. Try again in a few minutes. If it keeps failing, ask your host whether outbound connections from the Dashboard server are slow or filtered.', 'mainwp_api' );
		$cases[] = array( null, 'cURL error 28: fixture failure', 'timeout', 'The MainWP license server did not respond in time. Try again in a few minutes. If it keeps failing, ask your host whether outbound connections from the Dashboard server are slow or filtered.', 'mainwp_api' );
		$cases[] = array( 60, 'fixture failure', 'ssl_peer', 'The MainWP license server\'s SSL certificate could not be verified. The CA certificates on the Dashboard server are probably out of date. Ask your host to update them.', 'mainwp_api' );
		$cases[] = array( null, 'cURL error 60: fixture failure', 'ssl_peer', 'The MainWP license server\'s SSL certificate could not be verified. The CA certificates on the Dashboard server are probably out of date. Ask your host to update them.', 'mainwp_api' );
		$cases[] = array( 35, 'fixture failure', 'ssl_handshake', 'The secure connection to the MainWP license server could not be set up. The Dashboard server\'s TLS support is probably too old, or a firewall is cutting the connection. Contact your host.', 'mainwp_api' );
		$cases[] = array( null, 'cURL error 35: fixture failure', 'ssl_handshake', 'The secure connection to the MainWP license server could not be set up. The Dashboard server\'s TLS support is probably too old, or a firewall is cutting the connection. Contact your host.', 'mainwp_api' );
		$cases[] = array( 58, 'fixture failure', 'ssl_local', 'The Dashboard server\'s own SSL setup is broken. It could not load its CA certificate bundle or client certificate. Ask your host to fix it.', 'mainwp_api' );
		$cases[] = array( null, 'cURL error 58: fixture failure', 'ssl_local', 'The Dashboard server\'s own SSL setup is broken. It could not load its CA certificate bundle or client certificate. Ask your host to fix it.', 'mainwp_api' );
		$cases[] = array( 77, 'fixture failure', 'ssl_local', 'The Dashboard server\'s own SSL setup is broken. It could not load its CA certificate bundle or client certificate. Ask your host to fix it.', 'mainwp_api' );
		$cases[] = array( null, 'cURL error 77: fixture failure', 'ssl_local', 'The Dashboard server\'s own SSL setup is broken. It could not load its CA certificate bundle or client certificate. Ask your host to fix it.', 'mainwp_api' );
		$cases[] = array( 2, 'Failed initialization', null, null );
		$cases[] = array( null, 'cURL error 2: Failed initialization', null, null );
		$cases[] = array( 56, 'Connection reset', null, null );
		$cases[] = array( 0, '', null, null );
		return $cases;
	}

	/** HTML consumers escape once and machine consumers receive plain details. */
	public function test_helper_filters_and_console_keep_literal_details(): void {
		$raw        = 'getaddrinfo() thread failed to start <img src=x> > & "quoted"';
		$classified = MainWP_Error_Helper::classify_transport_error( 2, $raw );
		$exception  = new MainWP_Exception( 'HTTPERROR', $classified['message'] );
		$plain      = 'HTTP error - ' . $classified['message'];
		$this->assertSame( $plain, MainWP_Error_Helper::get_error_message( $exception, false, true ) );
		$this->assertStringContainsString( '<img src=x>', MainWP_Error_Helper::get_error_message( $exception, false, true ) );
		$nomainwp = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP' ), false, true );
		$this->assertStringNotContainsString( '<', $nomainwp );
		$this->assertStringContainsString( 'MainWP Community', $nomainwp );
		$this->assertSame( $plain, MainWP_Error_Helper::get_console_error_message( $exception ) );
		$this->assertSame( esc_html( $plain ), MainWP_Error_Helper::get_error_message( $exception ) );
		$wrapped = new MainWP_Exception( esc_html( $plain ) );
		$this->assertSame( $plain, MainWP_Error_Helper::get_error_message( $wrapped, false, true ) );
		$this->assertSame( $plain, MainWP_Error_Helper::get_console_error_message( $wrapped ) );
		foreach ( array( 'mainwp_getErrorMessage', 'mainwp_get_error_message' ) as $hook ) {
			$this->assertNotFalse( has_filter( $hook ) );
			$this->assertSame( esc_html( $plain ), apply_filters( $hook, 'HTTPERROR', $classified['message'] ) );
			$this->assertSame( MainWP_Error_Helper::get_error_not_detected_connect(), apply_filters( $hook, 'NOMAINWP', '' ) );
		}
	}

	/**
	 * Each HTTPERROR branch exposes the same metadata.
	 *
	 * @dataProvider child_http_errors
	 * @param bool  $multi Whether multi-cURL is available.
	 * @param mixed $body Response body.
	 * @param int   $status HTTP status.
	 */
	public function test_fetch_http_error_metadata( $multi, $body, $status ): void {
		$raw    = 'getaddrinfo() thread failed to start <raw> & "quoted"';
		$result = $this->probe(
			array(
				'multi'   => $multi,
				'body'    => $body,
				'status'  => $status,
				'message' => $raw,
			)
		);
		$this->assertSame( 'HTTPERROR', $result['code'] );
		$this->assertSame( 'http_error', $result['output']['error_category'] );
		$this->assertSame( 'http_request_failed', $result['output']['error_code'] );
		$this->assertSame( 'server_resources', $result['output']['error_reason'] );
		$this->assertSame( $result['extra'], $result['output']['error_message'] );
		$this->assertSame( $raw, $result['output']['error_detail'] );
		$this->assertStringContainsString( 'Details: ' . $raw, $result['extra'] );
	}

	/** Response bodies exercise all three HTTPERROR branches.
	 *
	 * @return array Cases. */
	public static function child_http_errors(): array {
		$cases = array();
		foreach ( array( true, false ) as $multi ) {
			$cases[] = array( $multi, false, 0 );
			$cases[] = array( $multi, '', 0 );
			$cases[] = array( $multi, 'partial body', 200 );
		}
		return $cases;
	}

	/** An unknown failure keeps its original details and an empty reason. */
	public function test_unknown_fetch_error_keeps_raw_message(): void {
		$raw    = 'unclassified <raw> & "quoted"';
		$result = $this->probe(
			array(
				'errno'   => 2,
				'message' => $raw,
			)
		);
		$this->assertSame( '', $result['output']['error_reason'] );
		$this->assertSame( $raw, $result['output']['error_message'] );
		$this->assertSame( $raw, $result['extra'] );
	}

	/** Raw diagnostic markup remains visible as text. */
	public function test_test_connection_escapes_details_once(): void {
		$raw = 'getaddrinfo() thread failed to start <img src=x> > & "quoted"';
		foreach ( array( true, false ) as $multi ) {
			$result   = $this->probe(
				array(
					'mode'    => 'visit',
					'multi'   => $multi,
					'message' => $raw,
				)
			);
			$expected = MainWP_Error_Helper::classify_transport_error( 2, $raw );
			$this->assertSame( esc_html( $expected['message'] ), $result['error'] );
			$this->assertStringNotContainsString( '<img', $result['error'] );
		}
	}

	/**
	 * The producer preserves the string while the parser receives errno.
	 *
	 * @dataProvider uptime_errors
	 * @param int    $errno Error number.
	 * @param int    $status Expected parser status.
	 * @param string $copy Expected guidance.
	 */
	public function test_uptime_producer_to_parser( $errno, $status, $copy ): void {
		$raw    = 'fixture transport error';
		$result = $this->probe(
			array(
				'mode'    => 'uptime',
				'errno'   => $errno,
				'message' => $raw,
			)
		);
		$this->assertSame( $errno, $result['info']['http_errno'] );
		$this->assertSame( $raw, $result['info']['http_error'] );
		$this->assertSame( $status, $result['parsed']['status'] );
		$this->assertStringStartsWith( $copy, $result['parsed']['error'] );
		$this->assertStringContainsString( 'Details: ' . $raw, $result['parsed']['error'] );
	}

	/** Error numbers with dedicated uptime categories.
	 *
	 * @return array Cases. */
	public static function uptime_errors(): array {
		return array(
			array( 28, MainWP_Uptime_Monitoring_Connect::TIMEOUTED_ERROR, 'The site did not respond in time. Its server may be slow or overloaded, or a firewall may be dropping the connection. If this keeps happening, ask the site\'s host to look at server load and firewall rules.' ),
			array( 60, MainWP_Uptime_Monitoring_Connect::CERT_ERROR, 'The site\'s SSL certificate could not be verified. Check that the certificate is valid, not expired, and issued for this domain. If every site fails this way, the CA certificates on the Dashboard server are out of date and your host needs to update them.' ),
			array( 6, MainWP_Uptime_Monitoring_Connect::RESOLVEHOST_ERROR, 'The Dashboard could not look up this site\'s address. Check that the domain is spelled correctly and has a working DNS record. If every site fails this way, DNS on the Dashboard server is broken and your host needs to fix it.' ),
		);
	}

	/** Unknown errors and successful HTTP results keep their existing behavior. */
	public function test_uptime_default_keeps_raw_string(): void {
		$parser = new MainWP_Uptime_Monitoring_Connect();
		$result = $parser->parse_response_status( 0, 'unmapped error', 56 );
		$this->assertSame( MainWP_Uptime_Monitoring_Connect::UNDEFINED_ERROR, $result['status'] );
		$this->assertSame( 'Error: unmapped error', $result['error'] );
		$this->assertSame( MainWP_Uptime_Monitoring_Connect::UP, $parser->parse_response_status( 200 )['status'] );
	}

	/** The public result filter retains its string while errno reaches the parser. */
	public function test_uptime_filter_keeps_error_string(): void {
		$captured = null;
		$filter   = static function ( $info ) use ( &$captured ) {
			$captured = $info;
			return $info;
		};
		$parser   = new class() extends MainWP_Uptime_Monitoring_Connect {
			/**
			 * Stops before persistence after recording the real handler arguments.
			 *
			 * @param int    $httpCode HTTP status.
			 * @param string $error Raw transport message.
			 * @param int    $errno Error number.
			 * @throws \RuntimeException Captured parser input.
			 */
			public function parse_response_status( $httpCode, $error = false, $errno = 0 ) {
				throw new \RuntimeException( wp_json_encode( array( $httpCode, $error, $errno ) ) );
			}
		};
		$info     = array(
			'http_code'  => 0,
			'http_error' => 'fixture error',
			'http_errno' => 28,
		);
		$output   = (object) array(
			'requests_info'   => array( 1 => $info ),
			'global_settings' => array(),
		);
		add_filter( 'mainwp_uptime_monitor_check_result', $filter );
		try {
			$parser->handle_response_fetch_uptime( '', (object) array( 'monitor_id' => 1 ), $output );
			$this->fail( 'The parser must receive the filtered result.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( array( 0, 'fixture error', 28 ), json_decode( $error->getMessage(), true ) );
			$this->assertSame( $info, $captured );
		} finally {
			remove_filter( 'mainwp_uptime_monitor_check_result', $filter );
		}
	}

	/**
	 * Dashboard handlers use per-site diagnostics while callbacks keep their body.
	 *
	 * @dataProvider batch_paths
	 * @param bool $multi Whether multi-cURL is available.
	 */
	public function test_bulk_failure_reaches_dashboard_handler( $multi ): void {
		$raw      = 'getaddrinfo() thread failed to start <img src=x> > & "quoted"';
		$result   = $this->probe(
			array(
				'mode'              => 'bulk',
				'multi'             => $multi,
				'dashboard_handler' => true,
				'sites'             => array(
					array(
						'body'    => '',
						'errno'   => 2,
						'message' => $raw,
						'status'  => 0,
					),
					array(
						'body'    => '',
						'errno'   => 0,
						'message' => '',
						'status'  => 200,
					),
				),
			)
		);
		$expected = MainWP_Error_Helper::classify_transport_error( 2, $raw );
		$this->assertSame( $expected, $result['output']['transport_errors'][1] );
		$this->assertArrayNotHasKey( 2, $result['output']['transport_errors'] );
		$this->assertSame( esc_html( $expected['message'] ), $result['output']['errors'][1] );
		$this->assertSame( MainWP_Error_Helper::get_error_not_detected_connect(), $result['output']['errors'][2] );
		$this->assertSame( $result['expected'], array_values( $result['received'] ) );
		$this->assertSame( $multi ? array( 'https://fixture-1.test/wp-admin/admin-ajax.php', 'https://fixture-1.test/' ) : array( 'https://fixture-1.test/wp-admin/admin-ajax.php' ), $result['request_urls'][0] );
	}

	/**
	 * Array output remains supported without affecting callbacks that read only data.
	 *
	 * @dataProvider batch_paths
	 * @param bool $multi Whether multi-cURL is available.
	 */
	public function test_bulk_preserves_body_only_callback_and_array_output( $multi ): void {
		$result = $this->probe(
			array(
				'mode'               => 'bulk',
				'multi'              => $multi,
				'body_only_callback' => true,
				'array_output'       => true,
				'sites'              => array(
					array(
						'body'    => 'partial <mainwp>',
						'errno'   => 6,
						'message' => 'lookup failed',
						'status'  => 0,
					),
				),
			)
		);
		$this->assertSame( $result['expected'], $result['received'] );
		$this->assertSame( 'kept', $result['output']['existing'] );
		$this->assertSame( 'dns', $result['output']['transport_errors'][1]['reason'] );
	}

	/**
	 * Timeout envelopes, complete envelopes and unknown errors do not add diagnostics.
	 *
	 * @dataProvider batch_unchanged_responses
	 * @param bool  $multi Whether multi-cURL is available.
	 * @param array $response Raw transport result.
	 * @param bool  $timeout Whether a timeout envelope should replace the body.
	 */
	public function test_bulk_preserves_existing_envelopes( $multi, $response, $timeout ): void {
		$result = $this->probe(
			array(
				'mode'               => 'bulk',
				'multi'              => $multi,
				'sites'              => array( $response ),
				'body_only_callback' => true,
			)
		);
		$this->assertSame( $result['expected'], $result['received'] );
		$this->assertArrayNotHasKey( 'transport_errors', $result['output'] );
		if ( $timeout ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Connect::batch_handler_response_data( $response['body'], $response['errno'], $response['message'], $response['status'] ), $result['received'][0] );
			$this->assertNotSame( $response['body'], $result['received'][0] );
			$this->assertStringContainsString( '<mainwp>', $result['received'][0] );
		} else {
			$this->assertSame( $response['body'], $result['received'][0] );
		}
	}

	/**
	 * Both dispatch paths use the same output contract.
	 *
	 * @return array Paths.
	 */
	public static function batch_paths(): array {
		return array( array( false ), array( true ) );
	}

	/**
	 * Existing envelopes take precedence over transport metadata.
	 *
	 * @return array Cases.
	 */
	public static function batch_unchanged_responses(): array {
		$cases = array();
		foreach ( array( false, true ) as $multi ) {
			$cases[] = array(
				$multi,
				array(
					'body'    => '',
					'errno'   => 28,
					'message' => 'Operation timed out',
					'status'  => 0,
				),
				true,
			);
			$cases[] = array(
				$multi,
				array(
					'body'    => '<mainwp>e30=</mainwp>',
					'errno'   => 6,
					'message' => 'lookup failed',
					'status'  => 200,
				),
				false,
			);
			$cases[] = array(
				$multi,
				array(
					'body'    => '',
					'errno'   => 2,
					'message' => 'Failed initialization',
					'status'  => 0,
				),
				false,
			);
			$cases[] = array(
				$multi,
				array(
					'body'    => '',
					'errno'   => 0,
					'message' => 'getaddrinfo() thread failed to start',
					'status'  => 0,
				),
				false,
			);
		}
		return $cases;
	}

	/** Runs the real transport against isolated cURL responses.
	 *
	 * @param array $overrides Fixture input.
	 * @return array Transport output. */
	private function probe( array $overrides ): array {
		$input = array_merge(
			array(
				'mode'    => 'fetch',
				'multi'   => false,
				'body'    => '',
				'status'  => 0,
				'errno'   => 2,
				'message' => 'getaddrinfo() thread failed to start',
			),
			$overrides
		);
		$pipes = array();
		$proc  = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/readable-transport.inc' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		fwrite( $pipes[0], wp_json_encode( $input ) );
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $proc ), $stderr );
		$result = json_decode( $stdout, true );
		$this->assertIsArray( $result, $stdout . $stderr );
		return $result;
	}
}
