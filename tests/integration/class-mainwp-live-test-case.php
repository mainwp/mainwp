<?php
/**
 * Base test case for the live (real-server) Integration suite.
 *
 * Unlike the rest of the suite, these tests are NOT WordPress unit tests: they
 * extend the plain PHPUnit TestCase, boot no harness, and touch no database.
 * They make real outbound HTTP to the remote testbed configured via REMOTE_*
 * environment variables (see network-testbed/.env) and assert reachability,
 * latency, and TLS validity.
 *
 * Honest scope: a child's public URL returns 200 even when its mainwp-child
 * plugin is deactivated, so this tier proves host-up + TLS + latency only. It
 * does NOT prove MainWP connection health -- that is the Tier 2 (browser E2E)
 * concern.
 *
 * The whole suite skips when REMOTE_DASH_URL is unset, which is the opt-in gate.
 *
 * @package MainWP\Dashboard\Tests\Integration
 */

namespace MainWP\Dashboard\Tests\Integration;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- kebab-case test file, matches tests/abilities convention.

/**
 * Class Live_Test_Case
 */
abstract class Live_Test_Case extends TestCase {

	/**
	 * Gross-failure latency ceiling in seconds. We do NOT assert a tight SLA
	 * (network/VPS jitter makes that flaky); we only fail on an obviously broken
	 * response and otherwise record the measured number.
	 *
	 * @var float
	 */
	protected const LATENCY_CEILING = 10.0;

	/**
	 * Skip the entire suite unless the remote testbed is configured.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( '' === $this->dashboard_url() ) {
			$this->markTestSkipped( 'Remote testbed not configured (set REMOTE_DASH_URL in network-testbed/.env to run the live suite).' );
		}
	}

	/**
	 * Remote dashboard base URL, trailing slash trimmed.
	 *
	 * @return string Empty string when unset.
	 */
	protected function dashboard_url(): string {
		return rtrim( (string) getenv( 'REMOTE_DASH_URL' ), '/' );
	}

	/**
	 * Configured remote child URLs.
	 *
	 * @return string[] Trailing-slash-trimmed URLs; empty when unset.
	 */
	protected function child_urls(): array {
		$raw = trim( (string) getenv( 'REMOTE_TEST_CHILD_URLS' ) );
		if ( '' === $raw ) {
			return array();
		}
		$urls = array_map(
			static function ( $u ) {
				return rtrim( trim( $u ), '/' );
			},
			explode( ',', $raw )
		);
		return array_values( array_filter( $urls ) );
	}

	/**
	 * The single small child designated for the backup/restore scenario.
	 *
	 * @return string Empty string when unset.
	 */
	protected function backup_child_url(): string {
		return rtrim( (string) getenv( 'REMOTE_TEST_BACKUP_CHILD_URL' ), '/' );
	}

	/**
	 * Make one real HTTP request and return a normalized result.
	 *
	 * Follows redirects (the dashboard root 302s to wp-login), verifies TLS, and
	 * sends a normal User-Agent so a WAF is less likely to bot-challenge it.
	 *
	 * @param string $url     Absolute URL.
	 * @param array  $options Optional: 'method' (GET|HEAD), 'timeout' seconds.
	 * @return array Normalized probe result.
	 */
	protected function http_probe( string $url, array $options = array() ): array {
		$method  = isset( $options['method'] ) ? strtoupper( (string) $options['method'] ) : 'GET';
		$timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : 15;

		$handle = curl_init();
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_NOBODY         => ( 'HEAD' === $method ),
				CURLOPT_USERAGENT      => 'MainWP-Live-Tests/1.0 (+reachability)',
				CURLOPT_ENCODING       => '',
			)
		);

		$start   = microtime( true );
		curl_exec( $handle );
		$latency = microtime( true ) - $start;

		$result = array(
			'ok'        => 0 === curl_errno( $handle ),
			'errno'     => curl_errno( $handle ),
			'error'     => curl_error( $handle ),
			'http_code' => (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ),
			'latency'   => $latency,
			'curl_time' => (float) curl_getinfo( $handle, CURLINFO_TOTAL_TIME ),
			'effective' => (string) curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL ),
		);
		curl_close( $handle );
		return $result;
	}

	/**
	 * Read the peer certificate's notAfter timestamp via a raw TLS handshake.
	 *
	 * @param string $url Absolute https URL.
	 * @return int|null Unix timestamp of expiry, or null if not https / handshake failed.
	 */
	protected function tls_not_after( string $url ): ?int {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WP loaded in this suite.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['scheme'] ) && 'https' !== $parts['scheme'] ) {
			return null;
		}
		$host = $parts['host'];
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;

		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => true,
					'verify_peer_name'  => true,
					'SNI_enabled'       => true,
				),
			)
		);

		$errno  = 0;
		$errstr = '';
		$client = @stream_socket_client( "ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $client ) {
			return null;
		}

		$params = stream_context_get_params( $client );
		fclose( $client );

		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			return null;
		}
		$cert = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		return isset( $cert['validTo_time_t'] ) ? (int) $cert['validTo_time_t'] : null;
	}

	/**
	 * Emit a measured metric to STDERR so it survives PHPUnit output capture.
	 *
	 * @param string $line Human-readable metric line.
	 */
	protected function log_metric( string $line ): void {
		fwrite( STDERR, '  [live] ' . $line . PHP_EOL );
	}
}
