<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit discovers test classes by filename.
/**
 * Isolated regressions for SEC-32 and SEC-33, without network or WordPress storage.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use PHPUnit\Framework\TestCase;

/** Exercise the real update decoder, licence client and legacy TLS callbacks. */
class Test_Addon_Update_Security extends TestCase {

	private const TLS_ERROR = 'Could not verify the TLS certificate of the MainWP license server. Check that the CA certificates on the server hosting this Dashboard are up to date and that no proxy is intercepting TLS, then try again.';

	/**
	 * Invoke the production classes in a fresh process with isolated WP services.
	 *
	 * @param array $input Scenario controls.
	 * @return array Observed response and side effects.
	 */
	private function probe( array $input ): array {
		$fixture = 'purchased' === $input['mode'] ? 'purchased-addons-response.inc' : 'addon-update-security.inc';
		$process = proc_open(
			array( PHP_BINARY, __DIR__ . '/fixtures/' . $fixture, json_encode( $input, JSON_THROW_ON_ERROR ) ),
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ),
			$pipes
		);
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertSame( '', $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * The local API Manager implementation returns these legacy stdClass shapes.
	 *
	 * @return array Request and response scenarios.
	 */
	public static function legacy_responses(): array {
		return array(
			'update'      => array( 'update' ),
			'information' => array( 'information' ),
			'api error'   => array( 'api_error' ),
			'nested data' => array( 'nested_data' ),
		);
	}

	/**
	 * Preserve legacy object fields and the package URL filter.
	 *
	 * @dataProvider legacy_responses
	 * @param string $body Response fixture.
	 */
	public function test_legacy_responses_remain_compatible( string $body ): void {
		$result = $this->probe(
			array(
				'mode' => 'decode',
				'body' => $body,
			)
		);
		$this->assertSame( $result['expected'], $result['response'] );
		$this->assertSame( 0, $result['wakeup_calls'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertTrue( $result['error_handler_restored'] );
	}

	/**
	 * Refuse objects outside the response contract, malformed data and scalars.
	 *
	 * @return array Invalid serialized response scenarios.
	 */
	public static function rejected_responses(): array {
		return array_map(
			static fn( $body ) => array( $body ),
			array( 'foreign_object', 'unknown_object', 'malformed', 'empty', 'array', 'scalar', 'false', 'too_deep', 'trailing_data', 'trailing_object', 'trailing_whitespace' )
		);
	}

	/**
	 * A malicious object cannot execute its wakeup before response validation.
	 *
	 * @dataProvider rejected_responses
	 * @param string $body Response fixture.
	 */
	public function test_unsafe_responses_fail_closed( string $body ): void {
		$result = $this->probe(
			array(
				'mode' => 'decode',
				'body' => $body,
			)
		);
		$this->assertSame( 0, $result['wakeup_calls'] );
		$this->assertFalse( $result['response'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertTrue( $result['error_handler_restored'] );
	}

	/** Disallowed nested classes must not execute even inside a normal response. */
	public function test_nested_object_cannot_execute_wakeup(): void {
		$result = $this->probe(
			array(
				'mode' => 'decode',
				'body' => 'nested_object',
			)
		);
		$this->assertSame( 0, $result['wakeup_calls'] );
		$this->assertSame( '__PHP_Incomplete_Class', $result['nested_class'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/** The bulk JSON protocol does not go through the legacy decoder. */
	public function test_bulk_json_response_is_preserved(): void {
		$result = $this->probe(
			array(
				'mode' => 'decode',
				'body' => 'bulk',
			)
		);
		$this->assertSame( $result['expected'], $result['response'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * Existing installations may still have a saved certificate-verification opt-out.
	 *
	 * @return array Licence request and saved option combinations.
	 */
	public static function licence_requests(): array {
		$cases = array();
		foreach ( array( false, 0, '0', 1 ) as $option ) {
			foreach ( array( 'activate', 'deactivate', 'grab_api_key', 'verify_api_key', 'get_purchased_software', 'purchase_software' ) as $method ) {
				$cases[] = array( $method, $option );
			}
		}
		return $cases;
	}

	/**
	 * Every licence request verifies TLS, regardless of the obsolete saved option.
	 *
	 * @dataProvider licence_requests
	 * @param string $method Client operation.
	 * @param mixed  $option Saved legacy setting, or false when absent.
	 */
	public function test_licence_requests_verify_certificates( string $method, $option ): void {
		$result = $this->probe(
			array(
				'mode'   => 'licence',
				'method' => $method,
				'option' => $option,
			)
		);
		$this->assertSame( 1, $result['sslverify'] );
		$this->assertSame( '{"success":true}', $result['response'] );
	}

	/**
	 * Certificate failures need guidance; other transport errors keep their message.
	 *
	 * @return array Transport messages and expected escaped errors.
	 */
	public static function transport_failures(): array {
		return array(
			'cURL 60'   => array( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', self::TLS_ERROR ),
			'cURL 77'   => array( 'cURL error 77: error setting certificate verify locations', self::TLS_ERROR ),
			'hostname'  => array( 'cURL error 51: SSL: no alternative certificate subject name matches target host name', self::TLS_ERROR ),
			'streams'   => array( 'The SSL certificate for the host could not be verified.', self::TLS_ERROR ),
			'timeout'   => array( 'cURL error 28: Operation timed out', 'cURL error 28: Operation timed out' ),
			'handshake' => array( 'cURL error 35: Connection reset by peer', 'cURL error 35: Connection reset by peer' ),
			'HTML'      => array( '<b>Transport failure</b>', '&lt;b&gt;Transport failure&lt;/b&gt;' ),
		);
	}

	/**
	 * Login and purchased add-ons must report errors without an unverified retry.
	 *
	 * @dataProvider transport_failures
	 * @param string $message Transport error.
	 * @param string $expected Expected user-facing error.
	 */
	public function test_transport_failures_reach_the_caller( string $message, string $expected ): void {
		foreach ( array( 'verify', 'purchased' ) as $operation ) {
			$result = $this->probe(
				array(
					'mode'      => 'purchased',
					'operation' => $operation,
					'error'     => $message,
				)
			);
			$this->assertSame( array( 'error' => $expected ), $result['response'] );
			$this->assertSame( 1, $result['sslverify'] );
			$this->assertSame( 1, $result['requests'] );
			$this->assertSame( array(), $result['warnings'] );
		}
	}

	/**
	 * An unsuccessful lookup must not be rendered as an empty purchase list.
	 *
	 * @return array HTTP and response-body failure cases.
	 */
	public static function failed_purchase_responses(): array {
		$error = 'Could not retrieve purchased add-ons from the MainWP license server. Please try again later.';
		return array(
			'HTTP error'            => array( 503, 'Service unavailable', $error ),
			'malformed JSON'        => array( 200, 'not JSON', $error ),
			'empty body'            => array( 200, '', $error ),
			'unsuccessful response' => array( 200, '{"success":false}', $error ),
			'API error'             => array( 200, '{"success":false,"error":"Fixture license error"}', 'Fixture license error' ),
		);
	}

	/**
	 * Keep API errors and reject unusable responses before generating add-on rows.
	 *
	 * @dataProvider failed_purchase_responses
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @param string $expected Expected error.
	 */
	public function test_failed_purchase_responses_do_not_report_success( int $code, string $body, string $expected ): void {
		$result = $this->probe(
			array(
				'mode' => 'purchased',
				'code' => $code,
				'body' => $body,
			)
		);
		$this->assertSame( array( 'error' => $expected ), $result['response'] );
		$this->assertSame( 1, $result['sslverify'] );
		$this->assertSame( 1, $result['requests'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/** Valid ownership and empty entitlement responses still render their proper rows. */
	public function test_successful_purchase_responses_preserve_ownership(): void {
		foreach ( array( false, true ) as $owned ) {
			$result = $this->probe(
				array(
					'mode'  => 'purchased',
					'owned' => $owned,
				)
			);
			$this->assertSame( 'SUCCESS', $result['response']['result'] );
			$this->assertSame( $owned, str_contains( $result['response']['data'], 'class="item extension extension-to-install ' ) );
			$this->assertSame( ! $owned, str_contains( $result['response']['data'], 'Add-on not purchased.' ) );
			$this->assertSame( 1, $result['sslverify'] );
			$this->assertSame( 1, $result['requests'] );
			$this->assertSame( array(), $result['warnings'] );
		}
	}

	/** Old PHP callbacks must not provide another way to turn TLS off. */
	public function test_legacy_download_callbacks_preserve_tls(): void {
		$result = $this->probe( array( 'mode' => 'download_filters' ) );
		$this->assertSame( $result['expected'], $result['install'] );
		$this->assertSame( $result['expected'], $result['upgrade'] );
		$this->assertSame( $result['expected'], $result['unrelated'] );
	}

	/** Even a fully privileged user cannot register the retired AJAX opt-out. */
	public function test_tls_opt_out_ajax_is_not_registered(): void {
		$result = $this->probe( array( 'mode' => 'ajax_hooks' ) );
		$this->assertNotContains( 'wp_ajax_mainwp_extension_apisslverifycertificate', $result['hooks'] );
		$this->assertContains( 'wp_ajax_mainwp_extension_downloadandinstall', $result['hooks'] );
	}
}
