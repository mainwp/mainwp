<?php
/**
 * Live reachability + TLS checks against the remote testbed.
 *
 * Proves the remote dashboard and the designated test children are up and
 * serving valid TLS. It does NOT prove MainWP connection health (see the
 * Live_Test_Case docblock). Skips entirely when REMOTE_DASH_URL is unset.
 *
 * @package MainWP\Dashboard\Tests\Integration
 */

namespace MainWP\Dashboard\Tests\Integration;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- kebab-case test file, matches tests/abilities convention.

/**
 * Class Test_Remote_Reachability
 */
class Test_Remote_Reachability extends Live_Test_Case {

	/**
	 * Minimum days of TLS validity we require before flagging a near-expiry cert.
	 *
	 * @var int
	 */
	private const TLS_MIN_DAYS = 7;

	/**
	 * Seconds in a day (the harness constant DAY_IN_SECONDS is not loaded here).
	 *
	 * @var int
	 */
	private const SECONDS_PER_DAY = 86400;

	/**
	 * The dashboard answers HTTP without a transport error and below 500.
	 *
	 * A WAF may return 403/503 to a bare client; that still proves the host is
	 * up, so we assert on the transport succeeding and a non-5xx code, and log
	 * the actual code for inspection.
	 */
	public function test_dashboard_responds(): void {
		$probe = $this->http_probe( $this->dashboard_url() );
		$this->log_metric( sprintf( 'dashboard %s -> HTTP %d (%.3fs)', $this->dashboard_url(), $probe['http_code'], $probe['latency'] ) );

		$this->assertTrue( $probe['ok'], 'Dashboard transport error: ' . $probe['error'] );
		$this->assertGreaterThan( 0, $probe['http_code'], 'Dashboard returned no HTTP status.' );
		$this->assertLessThan( 500, $probe['http_code'], 'Dashboard returned a server error.' );
	}

	/**
	 * The dashboard login page is served (the authenticated entry point exists).
	 */
	public function test_dashboard_login_page_responds(): void {
		$probe = $this->http_probe( $this->dashboard_url() . '/wp-login.php' );
		$this->log_metric( sprintf( 'wp-login.php -> HTTP %d', $probe['http_code'] ) );

		$this->assertTrue( $probe['ok'], 'wp-login transport error: ' . $probe['error'] );
		$this->assertLessThan( 500, $probe['http_code'], 'wp-login.php returned a server error.' );
	}

	/**
	 * Every designated test child answers HTTP (host is up).
	 *
	 * A child deliberately taken offline for a break/recover scenario will fail
	 * here by design; that is the intended host-down signal.
	 */
	public function test_children_respond(): void {
		$children = $this->child_urls();
		if ( empty( $children ) ) {
			$this->markTestSkipped( 'REMOTE_TEST_CHILD_URLS not set.' );
		}

		$unreachable = array();
		foreach ( $children as $url ) {
			$probe = $this->http_probe( $url );
			$this->log_metric( sprintf( 'child %s -> HTTP %d (%.3fs)', $url, $probe['http_code'], $probe['latency'] ) );
			if ( ! $probe['ok'] || $probe['http_code'] <= 0 || $probe['http_code'] >= 500 ) {
				$unreachable[] = $url . ' (' . ( $probe['ok'] ? 'HTTP ' . $probe['http_code'] : $probe['error'] ) . ')';
			}
		}

		$this->assertSame(
			array(),
			$unreachable,
			'Unreachable children: ' . implode( '; ', $unreachable )
		);
	}

	/**
	 * The dashboard TLS certificate is valid and not near expiry.
	 */
	public function test_dashboard_tls_valid(): void {
		$not_after = $this->tls_not_after( $this->dashboard_url() );
		if ( null === $not_after ) {
			$this->markTestSkipped( 'Dashboard is not https or the TLS handshake could not be inspected.' );
		}

		$days_left = (int) floor( ( $not_after - time() ) / self::SECONDS_PER_DAY );
		$this->log_metric( sprintf( 'dashboard TLS valid for %d more days', $days_left ) );
		$this->assertGreaterThanOrEqual(
			self::TLS_MIN_DAYS,
			$days_left,
			sprintf( 'Dashboard cert expires in %d days (< %d).', $days_left, self::TLS_MIN_DAYS )
		);
	}

	/**
	 * Each https test child presents a valid, non-near-expiry certificate.
	 */
	public function test_children_tls_valid(): void {
		$children = $this->child_urls();
		if ( empty( $children ) ) {
			$this->markTestSkipped( 'REMOTE_TEST_CHILD_URLS not set.' );
		}

		$expiring = array();
		$checked  = 0;
		foreach ( $children as $url ) {
			$not_after = $this->tls_not_after( $url );
			if ( null === $not_after ) {
				continue; // Non-https child or unreachable; reachability test covers up/down.
			}
			++$checked;
			$days_left = (int) floor( ( $not_after - time() ) / self::SECONDS_PER_DAY );
			$this->log_metric( sprintf( 'child %s TLS valid for %d more days', $url, $days_left ) );
			if ( $days_left < self::TLS_MIN_DAYS ) {
				$expiring[] = $url . " ({$days_left}d)";
			}
		}

		if ( 0 === $checked ) {
			$this->markTestSkipped( 'No https children were inspectable.' );
		}
		$this->assertSame( array(), $expiring, 'Children with near-expiry certs: ' . implode( '; ', $expiring ) );
	}
}
