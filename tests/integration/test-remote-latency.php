<?php
/**
 * Live latency baseline against the remote testbed.
 *
 * Records real-world response time for the dashboard and each designated child
 * over real geography + TLS. We deliberately do NOT assert a tight SLA (network
 * jitter makes that flaky); we record the numbers and fail only on gross
 * failure (transport error or a response slower than the ceiling). Skips when
 * REMOTE_DASH_URL is unset.
 *
 * @package MainWP\Dashboard\Tests\Integration
 */

namespace MainWP\Dashboard\Tests\Integration;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- kebab-case test file, matches tests/abilities convention.

/**
 * Class Test_Remote_Latency
 */
class Test_Remote_Latency extends Live_Test_Case {

	/**
	 * Dashboard responds within the gross-failure ceiling; record the time.
	 */
	public function test_dashboard_latency_recorded(): void {
		$probe = $this->http_probe( $this->dashboard_url() );
		$this->log_metric( sprintf( 'dashboard latency: %.3fs (curl %.3fs, HTTP %d)', $probe['latency'], $probe['curl_time'], $probe['http_code'] ) );

		$this->assertTrue( $probe['ok'], 'Dashboard transport error: ' . $probe['error'] );
		$this->assertGreaterThan( 0.0, $probe['latency'], 'No latency was measured.' );
		$this->assertLessThan(
			self::LATENCY_CEILING,
			$probe['latency'],
			sprintf( 'Dashboard responded in %.3fs (>= %.1fs ceiling).', $probe['latency'], self::LATENCY_CEILING )
		);
	}

	/**
	 * Each designated child responds within the ceiling; record per-child time.
	 */
	public function test_children_latency_recorded(): void {
		$children = $this->child_urls();
		if ( empty( $children ) ) {
			$this->markTestSkipped( 'REMOTE_TEST_CHILD_URLS not set.' );
		}

		$slow = array();
		foreach ( $children as $url ) {
			$probe = $this->http_probe( $url );
			$this->log_metric( sprintf( 'child %s latency: %.3fs (HTTP %d)', $url, $probe['latency'], $probe['http_code'] ) );
			if ( ! $probe['ok'] || $probe['latency'] >= self::LATENCY_CEILING ) {
				$slow[] = $url . ( $probe['ok'] ? sprintf( ' (%.3fs)', $probe['latency'] ) : ' (' . $probe['error'] . ')' );
			}
		}

		$this->assertSame(
			array(),
			$slow,
			'Children that errored or exceeded the latency ceiling: ' . implode( '; ', $slow )
		);
	}
}
