<?php
/**
 * Private native backup storage and consumer regression tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Diagnostics;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_TestRestore_Sandbox;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Exception;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/**
 * Exercise actual filesystem refusal and both generations of recorded paths.
 */
class MainWP_Backup_Private_Root_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Map temporary storage through the same ancestor calculation as a subdirectory install.
	 */
	private function map_probe_root(): void {
		add_filter( 'site_url', static fn() => 'https://example.org' . wp_normalize_path( realpath( ABSPATH ) ) );
		MainWP_Backup_Private_Root::reset();
	}

	/**
	 * A front controller or access denial must not be mistaken for exposed archive bytes.
	 *
	 * @dataProvider probe_http_responses
	 * @param int    $status HTTP status.
	 * @param string $body Token or unrelated response.
	 * @param bool   $refused Whether storage must be refused.
	 */
	public function test_probe_http_verdict( int $status, string $body, bool $refused ): void {
		$this->map_probe_root();
		$requests = 0;
		$probe    = '';
		$http     = function ( $pre, $args, $url ) use ( $status, $body, &$requests, &$probe ) {
			++$requests;
			$probe = rawurldecode( wp_parse_url( $url, PHP_URL_PATH ) );
			$this->assertSame( $this->private_root, dirname( $probe ) );
			$this->assertMatchesRegularExpression( '/^mainwp-probe-[a-zA-Z0-9]{24}\.zip$/', basename( $probe ) );
			$token = file_get_contents( $probe );
			$this->assertSame( 32, strlen( $token ) );
			$this->assertSame( 5, $args['timeout'] );
			$this->assertSame( 3, $args['redirection'] );
			$this->assertSame( 1024, $args['limit_response_size'] );
			$this->assertFalse( $args['sslverify'] );
			return array(
				'response' => array( 'code' => $status ),
				'body'     => 'token' === $body ? $token : $body,
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$result = MainWP_Backup_Private_Root::site_dir( 7 );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
		$this->assertSame( 1, $requests );
		$this->assertFileDoesNotExist( $probe );
		$this->assertSame( array(), glob( $this->private_root . '/mainwp-probe-*.zip' ) );
		if ( $refused ) {
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_backup_private_root_unavailable', $result->get_error_code() );
			$this->assertStringContainsString( 'the web server serves this directory', $result->get_error_message() );
			$this->assertStringContainsString( 'MAINWP_BACKUP_PRIVATE_DIR', $result->get_error_message() );
			$this->assertDirectoryDoesNotExist( $this->private_root . '/sites' );
		} else {
			$this->assertSame( $this->private_root . '/sites/7/', $result );
		}
	}

	/**
	 * Only an exact token match proves public access.
	 *
	 * @return array
	 */
	public static function probe_http_responses(): array {
		return array(
			'served filter root' => array( 200, 'token', true ),
			'not found'          => array( 404, '', false ),
			'forbidden'          => array( 403, '', false ),
			'unauthorized'       => array( 401, '', false ),
			'front controller'   => array( 200, '<html>fallback</html>', false ),
		);
	}

	/**
	 * Loopback outages allow backups and produce opt-in diagnostics without probe secrets.
	 *
	 * @dataProvider unknown_probe_responses
	 * @param bool $enabled Diagnostics setting.
	 * @param int  $status Zero for a transport error, otherwise an HTTP status.
	 */
	public function test_unknown_probe_allows_with_opt_in_diagnostics( bool $enabled, int $status ): void {
		$this->map_probe_root();
		$diag     = $this->private_root . '/diagnostics';
		$requests = 0;
		$token    = '';
		mkdir( $diag, 0700, true );
		update_option( MainWP_Backup_Diagnostics::OPTION_ENABLED, $enabled ? '1' : '0' );
		add_filter( 'mainwp_backup_diagnostics_dir', static fn() => $diag );
		$http = static function ( $pre, $args, $url ) use ( $status, &$requests, &$token ) {
			++$requests;
			$token = file_get_contents( rawurldecode( wp_parse_url( $url, PHP_URL_PATH ) ) );
			return $status ? array(
				'response' => array( 'code' => $status ),
				'body'     => '',
			) : new \WP_Error( 'http_request_failed', 'Connection timed out.' );
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$cache = get_option( 'mainwp_backup_private_root_probe' );
			$this->assertSame( 'unknown', $cache['verdict'] );
			$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $cache['expires'], 5 );
			MainWP_Backup_Private_Root::reset();
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 8 ) );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
		$this->assertSame( 1, $requests );
		$files = glob( $diag . '/*.jsonl' );
		$this->assertCount( $enabled ? 1 : 0, $files );
		if ( $enabled ) {
			$lines = file( $files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			$this->assertCount( 1, $lines );
			$event = json_decode( $lines[0], true );
			$this->assertSame( 'private_root_probe', $event['kind'] );
			$this->assertSame(
				array(
					'verdict' => 'unknown',
					'reason'  => $status ? 'http_' . $status : 'http_request_failed',
				),
				$event['payload']
			);
			$this->assertStringNotContainsString( $token, $lines[0] );
			$this->assertStringNotContainsString( $this->private_root, $lines[0] );
		}
		$this->assertSame( array(), glob( $this->private_root . '/mainwp-probe-*.zip' ) );
	}

	/**
	 * Transport errors and server failures share the allow policy.
	 *
	 * @return array
	 */
	public static function unknown_probe_responses(): array {
		return array(
			'timeout enabled'  => array( true, 0 ),
			'timeout disabled' => array( false, 0 ),
			'503 enabled'      => array( true, 503 ),
			'503 disabled'     => array( false, 503 ),
		);
	}

	/**
	 * A repaired server must be usable on the next request.
	 */
	public function test_served_then_fixed_is_not_persisted(): void {
		$this->map_probe_root();
		$requests = 0;
		$http     = static function ( $pre, $args, $url ) use ( &$requests ) {
			++$requests;
			return array(
				'response' => array( 'code' => 1 === $requests ? 200 : 404 ),
				'body'     => file_get_contents( rawurldecode( wp_parse_url( $url, PHP_URL_PATH ) ) ),
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 8 ) );
			$this->assertSame( 1, $requests, 'A request reuses its served verdict.' );
			$cache = get_option( 'mainwp_backup_private_root_probe' );
			$this->assertSame( 'served', $cache['verdict'] );
			$this->assertSame( 0, $cache['expires'] );
			MainWP_Backup_Private_Root::reset();
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertSame( 2, $requests );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
	}

	/**
	 * Recreating an exposed root must invalidate the previous allow across requests.
	 */
	public function test_recreated_served_root_replaces_cached_allow(): void {
		$this->map_probe_root();
		mkdir( $this->private_root, 0700 );
		$requests = 0;
		$http     = static function ( $pre, $args, $url ) use ( &$requests ) {
			++$requests;
			return array(
				'response' => array( 'code' => 1 === $requests ? 404 : 200 ),
				'body'     => file_get_contents( rawurldecode( wp_parse_url( $url, PHP_URL_PATH ) ) ),
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertSame( 'not_served', get_option( 'mainwp_backup_private_root_probe' )['verdict'] );
			$this->remove_private_fixture( $this->private_root );
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 8 ) );
			$this->assertSame( 2, $requests );
			MainWP_Backup_Private_Root::reset();
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 9 ) );
			$this->assertSame( 3, $requests );
			$cache = get_option( 'mainwp_backup_private_root_probe' );
			$this->assertSame( 'served', $cache['verdict'] );
			$this->assertSame( 0, $cache['expires'] );
			$this->assertContains( 'https://example.org' . $this->private_root . '/', $cache['urls'] );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
	}

	/**
	 * Root, URL, expiry and first creation determine whether an old allow still applies.
	 */
	public function test_not_served_cache_and_key_changes(): void {
		global $wpdb;
		$this->map_probe_root();
		$requests = 0;
		$http     = static function () use ( &$requests ) {
			++$requests;
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $http );
		try {
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$cache = get_option( 'mainwp_backup_private_root_probe' );
			$this->assertSame( hash( 'sha256', $this->private_root . "\n" . site_url() ), $cache['key'] );
			$this->assertSame( 'not_served', $cache['verdict'] );
			$this->assertEqualsWithDelta( time() + 7 * DAY_IN_SECONDS, $cache['expires'], 5 );
			$this->assertNotContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'mainwp_backup_private_root_probe' ) ), array( 'yes', 'on', 'auto', 'auto-on' ) );
			$this->assertIsString( MainWP_Backup_Private_Root::staging_dir( 'restore-staging', 'job' ) );
			MainWP_Backup_Private_Root::reset();
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 8 ) );
			$this->assertSame( 1, $requests );
			mkdir( $this->private_root . '-filtered', 0700 );
			$filter = fn() => $this->private_root . '-filtered';
			add_filter( 'mainwp_backup_private_root', $filter, 20 );
			MainWP_Backup_Private_Root::reset();
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertSame( 2, $requests );
			$this->assertNotSame( $cache['key'], get_option( 'mainwp_backup_private_root_probe' )['key'] );
			add_filter( 'site_url', static fn( $url ) => str_replace( 'example.org', 'changed.example.org', $url ), 20 );
			MainWP_Backup_Private_Root::reset();
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 8 ) );
			$this->assertSame( 3, $requests );
			$cache            = get_option( 'mainwp_backup_private_root_probe' );
			$cache['expires'] = time() - 1;
			update_option( 'mainwp_backup_private_root_probe', $cache, false );
			MainWP_Backup_Private_Root::reset();
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 9 ) );
			$this->assertSame( 4, $requests );
			$this->remove_private_fixture( $this->private_root . '-filtered' );
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 10 ) );
			$this->assertSame( 5, $requests, 'Recreated roots must probe even with a cached allow.' );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
	}

	/**
	 * Lookups and roots outside this host's URL space must never issue HTTP requests.
	 */
	public function test_read_only_and_unmapped_root_make_no_http_requests(): void {
		$this->map_probe_root();
		$document_root = $_SERVER['DOCUMENT_ROOT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fixture cleanup must preserve the original server value exactly.

		$_SERVER['DOCUMENT_ROOT'] = '';

		$requests = 0;
		$http     = static function () use ( &$requests ) {
			++$requests;
			return new \WP_Error( 'unexpected_http' );
		};
		add_filter( 'pre_http_request', $http );
		try {
			$this->assertIsString( MainWP_Backup_Private_Root::resolve() );
			$this->assertIsString( MainWP_Backup_Private_Root::site_path( 7 ) );
			$this->assertIsString( MainWP_Backup_Private_Root::staging_path( 'restore-staging', 'job' ) );
			$this->assertSame( '', MainWP_Backup_Private_Root::availability_reason() );
			MainWP_Backup_Private_Root::is_within_private_site_dir( $this->private_root . '/sites/7/a.zip', 7 );
			MainWP_Backup_Private_Root::is_within_site_roots( $this->private_root . '/sites/7/a.zip', 7 );
			$this->assertSame( 0, $requests );
			$this->assertDirectoryDoesNotExist( $this->private_root );
			add_filter( 'site_url', static fn() => 'https://example.org', 20 );
			MainWP_Backup_Private_Root::reset();
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertFalse( get_option( 'mainwp_backup_private_root_probe' ) );
			$this->assertSame( 0, $requests );
		} finally {
			remove_filter( 'pre_http_request', $http );
			if ( null === $document_root ) {
				unset( $_SERVER['DOCUMENT_ROOT'] );
			} else {
				$_SERVER['DOCUMENT_ROOT'] = $document_root;
			}
		}
	}

	/**
	 * A web request's docroot must not be shadowed by an earlier unmapped result.
	 */
	public function test_document_root_maps_served_storage(): void {
		$document_root = $_SERVER['DOCUMENT_ROOT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fixture cleanup must preserve the original server value exactly.

		$root                     = $this->private_root . '/backups';
		$_SERVER['DOCUMENT_ROOT'] = $this->private_root;
		add_filter( 'site_url', static fn() => 'https://example.org' );
		add_filter( 'mainwp_backup_private_root', static fn() => $root, 20 );
		mkdir( $root, 0700, true );
		update_option(
			'mainwp_backup_private_root_probe',
			array(
				'key'     => hash( 'sha256', $root . "\nhttps://example.org" ),
				'verdict' => 'unmapped',
				'expires' => time() + 7 * DAY_IN_SECONDS,
			),
			false
		);
		$requests = array();
		$http     = function ( $pre, $args, $url ) use ( &$requests ) {
			$requests[] = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => file_get_contents( $this->private_root . rawurldecode( wp_parse_url( $url, PHP_URL_PATH ) ) ),
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertCount( 1, $requests );
			$this->assertStringStartsWith( 'https://example.org/backups/mainwp-probe-', $requests[0] );
			$this->assertSame( array( 'https://example.org/backups/' ), get_option( 'mainwp_backup_private_root_probe' )['urls'] );
		} finally {
			remove_filter( 'pre_http_request', $http );
			if ( null === $document_root ) {
				unset( $_SERVER['DOCUMENT_ROOT'] );
			} else {
				$_SERVER['DOCUMENT_ROOT'] = $document_root;
			}
		}
	}

	/**
	 * Cron still needs the URL established by a web request after its verdict expires.
	 */
	public function test_cached_probe_url_is_used_when_request_cannot_map(): void {
		$document_root = $_SERVER['DOCUMENT_ROOT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fixture cleanup must preserve the original server value exactly.
		unset( $_SERVER['DOCUMENT_ROOT'] );
		add_filter( 'site_url', static fn() => 'https://example.org' );
		mkdir( $this->private_root, 0700 );
		update_option(
			'mainwp_backup_private_root_probe',
			array(
				'key'     => hash( 'sha256', $this->private_root . "\nhttps://example.org" ),
				'verdict' => 'not_served',
				'expires' => time() - 1,
				'urls'    => array( 'https://example.org/backups/' ),
			),
			false
		);
		$requests = array();
		$http     = function ( $pre, $args, $url ) use ( &$requests ) {
			$requests[] = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => file_get_contents( $this->private_root . '/' . basename( $url ) ),
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertCount( 1, $requests );
			$this->assertStringStartsWith( 'https://example.org/backups/mainwp-probe-', $requests[0] );
			$this->assertSame( 'served', get_option( 'mainwp_backup_private_root_probe' )['verdict'] );
			$this->assertSame( array( 'https://example.org/backups/' ), get_option( 'mainwp_backup_private_root_probe' )['urls'] );
		} finally {
			remove_filter( 'pre_http_request', $http );
			if ( null !== $document_root ) {
				$_SERVER['DOCUMENT_ROOT'] = $document_root;
			}
		}
	}

	/**
	 * A constant outside WordPress must still be checked against the parent docroot.
	 */
	public function test_served_constant_root_is_refused(): void {
		$root = $this->private_root . '/backups';
		$wp   = $this->private_root . '/wordpress';
		mkdir( $wp, 0700, true );
		$this->assert_served_constant_root( $wp, $root, 'https://example.org/wordpress' );
	}

	/**
	 * A candidate that is not served must not hide a later one that is.
	 */
	public function test_any_served_candidate_refuses_the_root(): void {
		$document_root = $_SERVER['DOCUMENT_ROOT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fixture cleanup must preserve the original server value exactly.
		$this->map_probe_root();
		$_SERVER['DOCUMENT_ROOT'] = dirname( $this->private_root );
		$requests                 = array();
		$http                     = function ( $pre, $args, $url ) use ( &$requests ) {
			$requests[] = $url;
			$path       = rawurldecode( wp_parse_url( $url, PHP_URL_PATH ) );
			if ( str_starts_with( $path, $this->private_root . '/' ) ) {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '',
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => file_get_contents( dirname( $this->private_root ) . $path ),
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertCount( 2, $requests );
			$this->assertStringStartsWith( 'https://example.org' . $this->private_root . '/mainwp-probe-', $requests[0] );
			$this->assertStringStartsWith( 'https://example.org/' . basename( $this->private_root ) . '/mainwp-probe-', $requests[1] );
			$this->assertSame( 'served', get_option( 'mainwp_backup_private_root_probe' )['verdict'] );
			$this->assertSame( array(), glob( $this->private_root . '/mainwp-probe-*.zip' ) );
		} finally {
			remove_filter( 'pre_http_request', $http );
			if ( null === $document_root ) {
				unset( $_SERVER['DOCUMENT_ROOT'] );
			} else {
				$_SERVER['DOCUMENT_ROOT'] = $document_root;
			}
		}
	}

	/**
	 * A cached allow from a request that saw fewer candidates must not hide a new one.
	 */
	public function test_new_candidate_reprobes_despite_cached_allow(): void {
		$document_root = $_SERVER['DOCUMENT_ROOT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fixture cleanup must preserve the original server value exactly.
		$this->map_probe_root();
		$_SERVER['DOCUMENT_ROOT'] = '';
		$requests                 = array();
		$http                     = function ( $pre, $args, $url ) use ( &$requests ) {
			$requests[] = $url;
			$path       = rawurldecode( wp_parse_url( $url, PHP_URL_PATH ) );
			if ( str_starts_with( $path, $this->private_root . '/' ) ) {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '',
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => file_get_contents( dirname( $this->private_root ) . $path ),
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$this->assertIsString( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertSame( 'not_served', get_option( 'mainwp_backup_private_root_probe' )['verdict'] );
			$cron_requests = count( $requests );
			MainWP_Backup_Private_Root::reset();
			$_SERVER['DOCUMENT_ROOT'] = dirname( $this->private_root );
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 8 ) );
			$this->assertStringStartsWith( 'https://example.org/' . basename( $this->private_root ) . '/mainwp-probe-', end( $requests ) );
			$this->assertGreaterThan( $cron_requests, count( $requests ) );
			MainWP_Backup_Private_Root::reset();
			$_SERVER['DOCUMENT_ROOT'] = '';
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 9 ) );
			$this->assertStringStartsWith( 'https://example.org/' . basename( $this->private_root ) . '/mainwp-probe-', end( $requests ) );
		} finally {
			remove_filter( 'pre_http_request', $http );
			if ( null === $document_root ) {
				unset( $_SERVER['DOCUMENT_ROOT'] );
			} else {
				$_SERVER['DOCUMENT_ROOT'] = $document_root;
			}
		}
	}

	/**
	 * A transport error on one candidate must not stop the probe before a served one.
	 */
	public function test_candidate_error_does_not_hide_a_served_candidate(): void {
		$document_root = $_SERVER['DOCUMENT_ROOT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fixture cleanup must preserve the original server value exactly.
		$this->map_probe_root();
		$_SERVER['DOCUMENT_ROOT'] = dirname( $this->private_root );
		$requests                 = 0;
		$http                     = function ( $pre, $args, $url ) use ( &$requests ) {
			++$requests;
			$path = rawurldecode( wp_parse_url( $url, PHP_URL_PATH ) );
			if ( str_starts_with( $path, $this->private_root . '/' ) ) {
				return new \WP_Error( 'http_request_failed', 'Too many redirects.' );
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => file_get_contents( dirname( $this->private_root ) . $path ),
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 7 ) );
			$this->assertSame( 2, $requests );
			$this->assertSame( 'served', get_option( 'mainwp_backup_private_root_probe' )['verdict'] );
		} finally {
			remove_filter( 'pre_http_request', $http );
			if ( null === $document_root ) {
				unset( $_SERVER['DOCUMENT_ROOT'] );
			} else {
				$_SERVER['DOCUMENT_ROOT'] = $document_root;
			}
		}
	}

	/**
	 * Every base that maps the root yields a candidate, so a wrong mapping cannot hide the served one.
	 */
	public function test_probe_url_mapping_matrix(): void {
		$t = wp_normalize_path( $this->private_root ) . '/map';
		foreach ( array( 'doc/wp', 'real/deep/wp', 'doc2', 'wordpress', 'www', 'srv/wp', 'docroot', 'releases/1', 'www2', 'doc3/wp' ) as $dir ) {
			mkdir( $t . '/' . $dir, 0700, true );
		}
		$this->assertTrue( symlink( $t . '/real/deep/wp', $t . '/doc2/wp' ) );
		$this->assertTrue( symlink( $t . '/wordpress', $t . '/www/wp' ) );
		$this->assertTrue( symlink( $t . '/releases/1', $t . '/www2/wp' ) );
		mkdir( $t . '/data/store', 0700, true );
		$this->assertTrue( symlink( $t . '/data/store', $t . '/doc/store' ) );
		$rows = array(
			'WordPress in a docroot subdirectory' => array( "$t/doc/wp/", '', 'https://e.org/wp', array( "$t/doc/mainwp-private/backups/" ), array( 'https://e.org/mainwp-private/backups/' ) ),
			'root above the URL space'            => array( "$t/doc/", '', 'https://e.org', array( "$t/private/backups/" ), array() ),
			'symlinked WordPress, deep target'    => array( "$t/doc2/wp/", '', 'https://e.org/wp', array( "$t/doc2/backups/" ), array( 'https://e.org/backups/' ) ),
			'symlinked WordPress, shallow target' => array( "$t/www/wp/", "$t/www", 'https://e.org/wp', array( "$t/www/backups/" ), array( 'https://e.org/www/backups/', 'https://e.org/backups/' ) ),
			'alias outside the docroot'           => array( "$t/srv/wp/", "$t/docroot", 'https://e.org/x/y/wp', array( "$t/docroot/backups/" ), array( 'https://e.org/x/docroot/backups/', 'https://e.org/backups/' ) ),
			'release deploy, web request'         => array( "$t/releases/1/", "$t/www2", 'https://e.org/wp', array( "$t/www2/backups/" ), array( 'https://e.org/backups/' ) ),
			'release deploy, cron'                => array( "$t/releases/1/", '', 'https://e.org/wp', array( "$t/www2/backups/" ), array() ),
			'root is the docroot'                 => array( "$t/doc3/wp/", "$t/doc3", 'https://e.org/wp', array( "$t/doc3/" ), array( 'https://e.org/' ) ),
			'port and a name that needs encoding' => array( "$t/doc/wp/", '', 'https://e.org:8443/wp', array( "$t/doc/my backups/" ), array( 'https://e.org:8443/my%20backups/' ) ),
			'relative document root is ignored'   => array( "$t/doc/wp/", 'relative/doc', 'https://e.org/wp', array( "$t/doc/backups/" ), array( 'https://e.org/backups/' ) ),
		);
		$rows['root under a symlinked docroot directory'] = array( "$t/doc/wp/", "$t/doc", 'https://e.org/wp', array( "$t/data/store/backups/", "$t/doc/store/backups" ), array( 'https://e.org/store/backups/' ) );
		if ( 'Darwin' === PHP_OS_FAMILY || 'Windows' === PHP_OS_FAMILY ) {
			$rows['ABSPATH configured with other case'] = array( strtoupper( $t ) . '/DOC/WP/', '', 'https://e.org/wp', array( "$t/doc/backups/" ), array( 'https://e.org/backups/' ) );
		}
		$method = new \ReflectionMethod( MainWP_Backup_Private_Root::class, 'probe_urls' );
		foreach ( $rows as $label => list( $abspath, $document_root, $site_url, $root, $expected ) ) {
			$this->assertSame( $expected, $method->invoke( null, $root, $site_url, $abspath, $document_root ), $label );
		}
	}

	/**
	 * A symlink must not hide storage served beside the configured WordPress directory.
	 */
	public function test_served_root_beside_symlinked_wordpress_is_refused(): void {
		$root = $this->private_root . '/docroot/backups';
		$wp   = $this->private_root . '/docroot/wp';
		$real = $this->private_root . '/real/wp';
		mkdir( dirname( $wp ), 0700, true );
		mkdir( $real, 0700, true );
		$this->assertTrue( symlink( $real, $wp ) );
		$this->assert_served_constant_root( $wp, $root, 'https://example.org/wp' );
	}

	/**
	 * Constants need their own process so fixtures cannot change the WordPress root.
	 *
	 * @param string $wp Configured WordPress directory.
	 * @param string $root Configured private root.
	 * @param string $site_url WordPress URL.
	 */
	private function assert_served_constant_root( string $wp, string $root, string $site_url ): void {
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- PHP literals preserve fixture paths in the isolated process.
		$script = 'define("ABSPATH", ' . var_export( $wp . '/', true ) . '); define("WP_CONTENT_DIR", ABSPATH . "wp-content");'
			. 'define("MAINWP_BACKUP_PRIVATE_DIR", ' . var_export( $root, true ) . ');'
			. 'define("DAY_IN_SECONDS", 86400);'
			. 'function wp_normalize_path($p) { return $p; } function untrailingslashit($p) { return rtrim($p, "/"); }'
			. 'function trailingslashit($p) { return rtrim($p, "/") . "/"; } function path_is_absolute($p) { return $p[0] === "/"; }'
			. 'function apply_filters($tag, $value) { return $value; } function __($value, $domain) { return $value; }'
			. 'function wp_mkdir_p($p) { return is_dir($p) || mkdir($p, 0700, true); }'
			. 'function wp_generate_password($length, $special) { return substr(bin2hex(random_bytes($length)), 0, $length); }'
			. 'function site_url() { return ' . var_export( $site_url, true ) . '; } function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }'
			. 'function get_option($key, $default = false) { return $default; }'
			. 'function update_option($key, $value, $autoload = null) { return true; }'
			. '$_SERVER["DOCUMENT_ROOT"] = "";'
			. 'function is_wp_error($v) { return $v instanceof WP_Error; }'
			. 'class WP_Error { public $code; public $message; function __construct($c, $m) { $this->code = $c; $this->message = $m; } }'
			. '$requests = []; function wp_remote_get($url, $args) { global $requests; $requests[] = $url; return ["response" => ["code" => 200], "body" => file_get_contents(MAINWP_BACKUP_PRIVATE_DIR . "/" . basename($url))]; }'
			. 'function wp_remote_retrieve_response_code($r) { return $r["response"]["code"]; } function wp_remote_retrieve_body($r) { return $r["body"]; }'
			. 'require ' . var_export( dirname( __DIR__, 2 ) . '/class/backup/class-mainwp-backup-private-root.php', true ) . ';'
			. '$result = MainWP\\Dashboard\\MainWP_Backup_Private_Root::site_dir(7); echo json_encode([$result instanceof WP_Error ? $result->code : null, $result instanceof WP_Error ? $result->message : $result, $requests]);';
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );
		$result = json_decode( $output, true );
		$this->assertIsArray( $result, (string) $output );
		$this->assertSame( 'mainwp_backup_private_root_unavailable', $result[0] );
		$this->assertStringContainsString( 'the web server serves this directory', $result[1] );
		$this->assertCount( 1, $result[2] );
		$this->assertStringStartsWith( 'https://example.org/backups/mainwp-probe-', $result[2][0] );
		$this->assertSame( array(), glob( $root . '/mainwp-probe-*.zip' ) );
	}

	/**
	 * Deep configured paths must be refused before any directory is created.
	 */
	public function test_deep_missing_private_root_is_refused() {
		$path = $this->private_root . '/deep' . str_repeat( '/child', 100 );
		add_filter( 'mainwp_backup_private_root', static fn() => $path );
		MainWP_Backup_Private_Root::reset();
		$result = MainWP_Backup_Private_Root::site_dir( 7 );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'too deep', $result->get_error_message() );
		$this->assertDirectoryDoesNotExist( $this->private_root . '/deep' );
	}

	/**
	 * Isolate both roots.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		delete_option( 'mainwp_backup_private_root_probe' );
		add_filter(
			'upload_dir',
			function ( $dirs ) {
				$dirs['basedir'] = $this->private_root . '-legacy';
				return $dirs;
			}
		);
	}

	/**
	 * Remove fixtures and cached resolution.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->remove_private_fixture( $this->private_root . '-legacy' );
		$this->remove_private_fixture( $this->private_root . '-filtered' );
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Filesystem aliases must not let private storage share a served directory.
	 *
	 * @dataProvider served_tree_paths
	 * @param string $variant Path variant.
	 * @param bool   $refused Expected refusal.
	 */
	public function test_served_tree_refusal_path_variants( string $variant, bool $refused ) {
		$root  = $this->private_root . '/wp';
		$child = $root . '/wp-content/backups';
		mkdir( $child, 0700, true );
		$paths = array(
			'exact'          => $root,
			'child'          => $child,
			'uppercase-all'  => strtoupper( $child ),
			'uppercase-root' => strtoupper( $root ) . '/wp-content/backups',
			'mixed-content'  => $root . '/Wp-CoNtEnT/backups',
			'sibling'        => $root . '-backups',
			'missing-child'  => $child . '/not-created',
		);
		$path  = $paths[ $variant ];
		if ( in_array( $variant, array( 'uppercase-all', 'uppercase-root', 'mixed-content' ), true ) && ! is_dir( $path ) ) {
			$this->markTestSkipped( 'This filesystem does not resolve the case variant to the same directory.' );
		}
		// Constants need an isolated process to exercise a temporary WordPress tree.
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- PHP literals preserve filesystem paths in the isolated process.
		$script = 'define("ABSPATH", ' . var_export( $root . '/', true ) . '); define("WP_CONTENT_DIR", ' . var_export( $root . '/wp-content', true ) . ');'
			. 'function wp_normalize_path($p) { return str_replace(chr(92), "/", $p); } function untrailingslashit($p) { return rtrim($p, "/"); }'
			. 'function path_is_absolute($p) { return $p[0] === "/" || $p[1] === ":"; } function __($value, $domain) { return $value; }'
			. 'require ' . var_export( dirname( __DIR__, 2 ) . '/class/backup/class-mainwp-backup-private-root.php', true ) . ';'
			. 'echo json_encode(MainWP\\Dashboard\\MainWP_Backup_Private_Root::served_tree_refusal(' . var_export( $path, true ) . '));';
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$result = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );
		$this->assertIsString( $result );
		$result = json_decode( $result, true );
		$this->assertIsString( $result );
		$this->assertSame( $refused, '' !== $result, $variant . ' must retain its served-tree classification.' );
	}

	/**
	 * Missing descendants and case aliases inherit the served-tree restriction.
	 *
	 * @return array
	 */
	public static function served_tree_paths() {
		return array(
			'exact'          => array( 'exact', true ),
			'child'          => array( 'child', true ),
			'uppercase-all'  => array( 'uppercase-all', true ),
			'uppercase-root' => array( 'uppercase-root', true ),
			'mixed-content'  => array( 'mixed-content', true ),
			'sibling'        => array( 'sibling', false ),
			'missing-child'  => array( 'missing-child', true ),
		);
	}

	/**
	 * The filter sees the default, and a successful root is cached.
	 */
	public function test_default_filter_and_cache() {
		remove_filter( 'mainwp_backup_private_root', array( $this, 'filter_private_root' ) );
		$seen = null;
		add_filter(
			'mainwp_backup_private_root',
			function ( $value ) use ( &$seen ) {
				$seen = $value;
				return $this->private_root;
			}
		);
		$root = MainWP_Backup_Private_Root::resolve();
		$this->assertSame( dirname( rtrim( ABSPATH, '/\\' ) ) . '/mainwp-private/' . basename( rtrim( ABSPATH, '/\\' ) ) . '/backups', $seen );
		$this->assertSame( $this->private_root . '/', $root );
		$this->assertDirectoryDoesNotExist( $root );
		$default = $seen;
		MainWP_Backup_Private_Root::reset();
		$this->assertSame( $root, MainWP_Backup_Private_Root::resolve() );
		$this->assertSame( $default, $seen );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		$this->assertSame( $root, MainWP_Backup_Private_Root::resolve() );
		MainWP_Backup_Private_Root::reset();
		$this->assertWPError( MainWP_Backup_Private_Root::resolve() );
	}

	/**
	 * Reject public roots before creating anything.
	 */
	public function test_public_and_invalid_roots_are_refused() {
		foreach ( array( ABSPATH, ABSPATH . 'private-test', WP_CONTENT_DIR, WP_CONTENT_DIR . '/private-test', '', false, "bad\0path", 'relative/backups' ) as $path ) {
			$filter = static function () use ( $path ) {
				return $path;
			};
			add_filter( 'mainwp_backup_private_root', $filter, 99 );
			MainWP_Backup_Private_Root::reset();
			$error = MainWP_Backup_Private_Root::resolve();
			$this->assertWPError( $error );
			$this->assertSame( 'mainwp_backup_private_root_unavailable', $error->get_error_code() );
			$this->assertStringContainsString( 'unavailable:', $error->get_error_message() );
			remove_filter( 'mainwp_backup_private_root', $filter, 99 );
		}
	}

	/**
	 * A symlink, including a public ancestor alias, cannot hide served storage.
	 */
	public function test_symlink_and_public_alias_are_refused() {
		mkdir( $this->private_root );
		symlink( ABSPATH, $this->private_root . '/link' );
		foreach ( array( '/link', '/link/private-test' ) as $suffix ) {
			$filter = function () use ( $suffix ) {
				return $this->private_root . $suffix;
			};
			add_filter( 'mainwp_backup_private_root', $filter, 99 );
			MainWP_Backup_Private_Root::reset();
			$this->assertWPError( MainWP_Backup_Private_Root::resolve() );
			remove_filter( 'mainwp_backup_private_root', $filter, 99 );
		}
	}

	/**
	 * Writability must be demonstrated before chmod can mask the failure.
	 */
	public function test_unwritable_root_is_refused() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses directory write permissions.' );
		}
		mkdir( $this->private_root, 0500 );
		try {
			$error = MainWP_Backup_Private_Root::site_dir( 1 );
			$this->assertWPError( $error );
			$this->assertStringContainsString( 'write probe', $error->get_error_message() );
			$this->assertSame( array( '.', '..' ), scandir( $this->private_root ) );
		} finally {
			chmod( $this->private_root, 0700 );
		}
	}

	/**
	 * IDs, staging names, real paths and missing-file parents stay confined.
	 */
	public function test_site_and_staging_confinement() {
		$one    = MainWP_Backup_Private_Root::site_dir( 1 );
		$twelve = MainWP_Backup_Private_Root::site_dir( 12 );
		$this->assertSame( $this->private_root . '/sites/1/', $one );
		$this->assertSame( 0700, fileperms( $one ) & 0777 );
		$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 0 ) );
		$this->assertWPError( MainWP_Backup_Private_Root::site_dir( -1 ) );
		$legacy = MainWP_Backup_Private_Root::legacy_site_dir( 1 );
		$this->assertDirectoryDoesNotExist( $legacy );
		wp_mkdir_p( $legacy );
		foreach ( array( $one, $legacy ) as $dir ) {
			file_put_contents( $dir . 'archive.zip', 'backup' );
			$this->assertTrue( MainWP_Backup_Private_Root::is_within_site_roots( $dir . 'archive.zip', 1 ) );
			$this->assertTrue( MainWP_Backup_Private_Root::is_within_site_roots( $dir . 'missing.zip', 1 ) );
			$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $dir . 'archive.zip', 12 ) );
		}
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $twelve . 'missing.zip', 1 ) );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $one . '../12/missing.zip', 1 ) );
		symlink( $twelve, $one . 'escape' );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $one . 'escape/missing.zip', 1 ) );
		foreach ( array( '', '..', 'a..b', '../job', 'a/b', 'a\\b' ) as $name ) {
			$this->assertWPError( MainWP_Backup_Private_Root::staging_dir( 'restore-staging', $name ) );
		}
		$this->assertWPError( MainWP_Backup_Private_Root::staging_dir( 'unexpected', 'job' ) );
		$this->assertSame( $this->private_root . '/restore-staging/a-b/', MainWP_Backup_Private_Root::staging_dir( 'restore-staging', 'a b' ) );
	}

	/**
	 * Both staging drivers use private storage and refuse a bad root.
	 */
	public function test_staging_consumers_and_sandbox_refusal() {
		$restore = new MainWP_Backup_Restore_Transfer_Driver();
		$this->assertSame( $this->private_root . '/restore-staging/42/', $restore->staging_dir( 42 ) );
		$upload = new \ReflectionMethod( MainWP_Backup_Upload_Driver::class, 'staging_dir' );
		$job    = (object) array(
			'snapshot_id'    => 7,
			'destination_id' => 8,
		);
		$this->assertSame( $this->private_root . '/upload-staging/7-8', $upload->invoke( MainWP_Backup_Upload_Driver::instance(), $job ) );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		MainWP_Backup_Private_Root::reset();
		$plan    = array( array( 'source' => 'remote' ) );
		$outcome = $restore->stage_artifacts( 42, $plan, microtime( true ) + 10 );
		$this->assertStringContainsString( 'Private backup directory', $outcome['error'] );
		$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $outcome['error_code'] );
		try {
			$upload->invoke( MainWP_Backup_Upload_Driver::instance(), $job );
			$this->fail( 'Upload must refuse unavailable private staging.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 10001, $error->getCode() );
			$this->assertStringContainsString( 'Private backup directory', $error->getMessage() );
		}
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		try {
			$sandbox->bring_up();
			$this->fail( 'Sandbox must refuse unavailable private staging.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $error->get_message_error_code() );
			$this->assertStringContainsString( 'Private backup directory', $error->getMessage() );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Retention sweeps both roots while preserving another site's files.
	 */
	public function test_retention_purge_and_remove_both_roots() {
		$roots  = array( MainWP_Backup_Private_Root::site_dir( 1 ), MainWP_Backup_Private_Root::legacy_site_dir( 1 ) );
		$remove = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'remove_local_dir' );
		foreach ( $roots as $root ) {
			wp_mkdir_p( $root . 'snapshot-orphan' );
			file_put_contents( $root . 'snapshot-orphan/archive.zip', 'orphan' );
			touch( $root . 'snapshot-orphan/archive.zip', time() - 100 );
			touch( $root . 'snapshot-orphan', time() - 100 );
		}
		$result = array(
			'orphan_dirs_removed' => 0,
			'errors'              => array(),
		);
		$this->purge_orphan_dirs( 1, 10, $result );
		$this->assertSame( 2, $result['orphan_dirs_removed'] );
		foreach ( $roots as $root ) {
			$this->assertDirectoryDoesNotExist( $root . 'snapshot-orphan' );
			wp_mkdir_p( $root . 'snapshot-recorded' );
			$path = $root . 'snapshot-recorded/archive.zip';
			file_put_contents( $path, 'recorded' );
			$snapshot = (object) array(
				'id'            => 999999,
				'site_id'       => 1,
				'archives'      => array( array( 'path' => $path ) ),
				'manifest_path' => '',
			);
			$errors   = array();
			$args     = array( $snapshot, &$errors );
			$this->assertTrue( $remove->invokeArgs( MainWP_Backup_Retention_Executor::instance(), $args ) );
			$this->assertSame( array(), $errors );
			$this->assertDirectoryDoesNotExist( dirname( $path ) );
		}
	}

	/**
	 * Recent-backup scans select the newest archive across both roots.
	 */
	public function test_recent_backup_uses_both_roots() {
		$private = MainWP_Backup_Private_Root::site_dir( 1 );
		$legacy  = MainWP_Backup_Private_Root::legacy_site_dir( 1 );
		wp_mkdir_p( $legacy );
		file_put_contents( $private . 'full-old.zip', 'old' );
		touch( $private . 'full-old.zip', time() - 10 * DAY_IN_SECONDS );
		$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( 1 ) );
		file_put_contents( $legacy . 'full-new.zip', 'new' );
		$this->assertTrue( MainWP_Backup_Executor::has_recent_backup( 1 ) );
		unlink( $legacy . 'full-new.zip' );
		file_put_contents( $private . 'full-new.zip', 'new' );
		$this->assertTrue( MainWP_Backup_Executor::has_recent_backup( 1 ) );
	}

	/**
	 * A confinement check must not populate a fresh site's backup directory.
	 */
	public function test_confinement_does_not_create_site_directory() {
		$path = MainWP_Backup_Private_Root::site_path( 7654321 );
		$this->assertSame( $this->private_root . '/sites/7654321/', $path );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $path . 'missing.zip', 7654321 ) );
		$this->assertDirectoryDoesNotExist( $path );
		$this->assertDirectoryDoesNotExist( $this->private_root . '/sites' );
		$this->assertDirectoryDoesNotExist( MainWP_Backup_Private_Root::legacy_site_dir( 7654321 ) );
		$other = MainWP_Backup_Private_Root::site_dir( 7654322 );
		symlink( $other, untrailingslashit( $path ) );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $path . 'missing.zip', 7654321 ) );
		$this->assertWPError( MainWP_Backup_Private_Root::site_path( 0 ) );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		MainWP_Backup_Private_Root::reset();
		$this->assertWPError( MainWP_Backup_Private_Root::site_path( 7654321 ) );
	}

	/**
	 * A shared override directory must retain its traversal and contents.
	 */
	public function test_existing_override_keeps_mode_and_no_protection_files() {
		mkdir( $this->private_root );
		chmod( $this->private_root, 0755 );
		$this->assertSame( $this->private_root . '/sites/1/', MainWP_Backup_Private_Root::site_dir( 1 ) );
		$this->assertSame( 0755, fileperms( $this->private_root ) & 0777 );
		$this->assertSame( array( '.', '..', 'sites' ), scandir( $this->private_root ) );
	}

	/**
	 * Legacy files remain readable but cannot be new download destinations.
	 */
	public function test_legacy_write_refused_and_private_size_reported() {
		$private = MainWP_Backup_Private_Root::site_dir( 1 );
		$legacy  = MainWP_Backup_Private_Root::legacy_site_dir( 1 );
		wp_mkdir_p( $legacy );
		file_put_contents( $private . 'full.zip', 'private bytes' );
		$this->assertSame( 13, \MainWP\Dashboard\MainWP_Backup_File_Manager::backup_get_file_size( $private . 'full.zip' ) );
		$this->assertTrue( MainWP_Backup_Private_Root::is_within_site_roots( $legacy . 'full.zip', 1 ) );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_private_site_dir( $legacy . 'full.zip', 1 ) );
		$this->expectException( MainWP_Exception::class );
		\MainWP\Dashboard\MainWP_Backup_File_Manager::backup_download_file( 1, 'full', 'remote', $legacy . 'full.zip' );
	}

	/**
	 * Incomplete legacy checkpoints take the fresh private destination branch.
	 */
	public function test_incomplete_legacy_checkpoint_is_discarded() {
		$private = MainWP_Backup_Private_Root::site_dir( 1 ) . 'full.zip';
		$legacy  = MainWP_Backup_Private_Root::legacy_site_dir( 1 );
		wp_mkdir_p( $legacy );
		$path   = $legacy . 'full.zip';
		$choose = new \ReflectionMethod( MainWP_Backup_Executor::class, 'resumable_download_path' );
		file_put_contents( $path, 'partial' );
		$this->assertSame( $path, $choose->invoke( null, $path, 1, 1 ) );
		$this->assertSame( '', $choose->invoke( null, $path, 0, 1 ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( $private, $choose->invoke( null, $private, 0, 1 ) );
	}

	/**
	 * Environment checks need only private storage to be writable.
	 */
	public function test_environment_checks_private_root() {
		$upload = wp_upload_dir( null, false );
		wp_mkdir_p( $upload['basedir'] . '/mainwp' );
		file_put_contents( $upload['basedir'] . '/mainwp/backup', 'blocked legacy directory' );
		\MainWP\Dashboard\MainWP_Backup_Monitor::validate_backup_environment();
		$this->assertSame( 'blocked legacy directory', file_get_contents( $upload['basedir'] . '/mainwp/backup' ) );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		MainWP_Backup_Private_Root::reset();
		try {
			\MainWP\Dashboard\MainWP_Backup_Monitor::validate_backup_environment();
			$this->fail( 'Unavailable private storage must fail validation.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $error->get_message_error_code() );
		}
		update_option( 'mainwp_enableNativeBackups', 1 );
		try {
			MainWP_Backup_Executor::trigger_backup( $this->create_test_site() );
			$this->fail( 'Storage must be resolved before a child task can start.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $error->get_message_error_code() );
		}
	}

	/**
	 * Historical site paths honor MainWP's upload filter without creating them.
	 */
	public function test_filtered_legacy_path_and_read_only_scan() {
		add_filter(
			'mainwp_get_wp_upload_dir',
			function ( $dirs, $subdir, $direct ) {
				$this->assertNull( $subdir );
				$this->assertFalse( $direct );
				$dirs['basedir'] = $this->private_root . '-filtered';
				return $dirs;
			},
			10,
			3
		);
		$this->assertSame( $this->private_root . '-filtered/mainwp/0/1/', MainWP_Backup_Private_Root::legacy_site_dir( 1 ) );
		$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( 1 ) );
		$this->assertDirectoryDoesNotExist( $this->private_root . '/sites/1' );
		$this->assertDirectoryDoesNotExist( $this->private_root . '-filtered' );
	}

	/**
	 * Selection precedence and filesystem refusals have literal outcomes.
	 */
	public function test_root_selection_matrix() {
		$rows = array(
			'WordPress directory named mainwp-private gets a sibling container' => array( 'container', null, true ),
			'sibling installs resolve to different roots' => array( 'siblings', null, true ),
			'default accepted'                            => array( 'default', null, true ),
			'constant wins over default'                  => array( 'constant', null, true ),
			'filter wins over constant'                   => array( 'filter', null, true ),
			'inside ABSPATH refused'                      => array( 'path', ABSPATH . 'private-test', false ),
			'inside WP_CONTENT_DIR refused'               => array( 'path', WP_CONTENT_DIR . '/private-test', false ),
			'symlink refused'                             => array( 'symlink', $this->private_root . '/link', false ),
			'unwritable refused'                          => array( 'unwritable', $this->private_root . '/readonly', false ),
			'relative refused'                            => array( 'path', 'relative/backups', false ),
			"'..' refused"                                => array( 'path', $this->private_root . '/../backups', false ),
		);
		mkdir( $this->private_root );
		symlink( $this->private_root, $this->private_root . '/link' );
		mkdir( $this->private_root . '/readonly', 0500 );
		foreach ( $rows as $label => $row ) {
			list( $selection, $path, $accepted ) = $row;
			if ( 'unwritable' === $selection && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
				continue;
			}
			if ( in_array( $selection, array( 'default', 'constant', 'filter', 'siblings', 'container' ), true ) ) {
				// Constants need separate processes; none of these resolutions writes to disk.
				$resolve = static function ( string $abspath ) use ( $selection ) {
					$source = dirname( __DIR__, 2 ) . '/class/backup/class-mainwp-backup-private-root.php';
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- wp_json_encode() escapes slashes, which a PHP string literal keeps.
					$script = 'define("ABSPATH", ' . var_export( $abspath, true ) . '); define("WP_CONTENT_DIR", "/public/content");'
						. ( in_array( $selection, array( 'default', 'siblings', 'container' ), true ) ? '' : 'define("MAINWP_BACKUP_PRIVATE_DIR", "/constant/backups");' )
						. 'function wp_normalize_path($p) { return $p; } function untrailingslashit($p) { return rtrim($p, "/"); }'
						. 'function trailingslashit($p) { return rtrim($p, "/") . "/"; } function path_is_absolute($p) { return $p[0] === "/"; }'
						. 'function apply_filters($tag, $value) { return ' . ( 'filter' === $selection ? '"/filtered/backups"' : '$value' ) . '; }'
						. 'function __($value, $domain) { return $value; } class WP_Error {}'
						. 'require ' . var_export( $source, true ) . '; echo MainWP\\Dashboard\\MainWP_Backup_Private_Root::resolve();'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote the source path for the isolated constant test.
					return shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );
				};
				if ( 'container' === $selection ) {
					$this->assertSame( '/srv/mainwp-private-dashboard/mainwp-private/backups/', $resolve( '/srv/mainwp-private/' ), $label );
					$this->assertSame( '/srv/mainwp-private-dashboard/Mainwp-Private/backups/', $resolve( '/srv/Mainwp-Private/' ), $label );
					$this->assertSame( '/srv/mainwp-private/site/backups/', $resolve( '/srv/site/' ), $label );
					continue;
				}
				if ( 'siblings' === $selection ) {
					$first  = $resolve( '/sites/a/' );
					$second = $resolve( '/sites/b/' );
					$this->assertSame( '/sites/mainwp-private/a/backups/', $first, $label );
					$this->assertSame( '/sites/mainwp-private/b/backups/', $second, $label );
					$this->assertNotSame( $first, $second, $label );
					continue;
				}
				$expected = array(
					'default'  => '/public/mainwp-private/wp/backups/',
					'constant' => '/constant/backups/',
					'filter'   => '/filtered/backups/',
				);
				$this->assertSame( $expected[ $selection ], $resolve( '/public/wp/' ), $label );
				continue;
			}
			$filter = static function () use ( $path ) {
				return $path;
			};
			add_filter( 'mainwp_backup_private_root', $filter, 99 );
			MainWP_Backup_Private_Root::reset();
			try {
				$result = 'unwritable' === $selection ? MainWP_Backup_Private_Root::site_dir( 1 ) : MainWP_Backup_Private_Root::resolve();
				$this->assertSame( $accepted, ! is_wp_error( $result ), $label );
				$this->assertSame( 'mainwp_backup_private_root_unavailable', $result->get_error_code(), $label );
			} finally {
				remove_filter( 'mainwp_backup_private_root', $filter, 99 );
			}
		}
	}

	/**
	 * Host fallbacks preserve existing snapshots and explicit configuration.
	 */
	public function test_sibling_fallback_selection_matrix() {
		$rows = array(
			'default writable'         => array( 'default', 0700, 'default', '' ),
			'private_html writable'    => array( 'default', 0500, 'private_html', '' ),
			'private writable'         => array( 'private', 0500, 'private', '' ),
			'both roots exist'         => array( 'both', 0700, 'default', '' ),
			'default read-only'        => array( 'default-readonly', 0500, 'default', 'directory is not writable' ),
			'existing snapshots'       => array( 'snapshots', 0700, 'private_html', '' ),
			'both siblings exist'      => array( 'both-siblings', 0700, 'private_html', '' ),
			'existing sibling wins'    => array( 'private-snapshots', 0500, 'private', '' ),
			'default symlink refused'  => array( 'default-symlink', 0700, 'error', 'directory is a symlink' ),
			'dangling default refused' => array( 'default-dangling', 0700, 'error', 'directory is a symlink' ),
			'no sibling directories'   => array( 'no-siblings', 0500, 'default', 'could not create directory' ),
			'equivalent filter path'   => array( 'trailing-slash', 0500, 'private_html', '' ),
			'constant skips ladder'    => array( 'constant', 0500, 'default', 'could not create directory' ),
			'filter skips ladder'      => array( 'filter', 0500, 'override', 'could not create directory' ),
			'nothing writable'         => array( 'blocked', 0500, 'default', 'could not create directory' ),
			'content sibling refused'  => array( 'content', 0500, 'private', '' ),
			'ABSPATH sibling refused'  => array( 'abspath', 0500, 'private', '' ),
			'symlink root refused'     => array( 'symlink', 0500, 'private', '' ),
			'symlink base refused'     => array( 'symlink-base', 0500, 'private', '' ),
		);
		foreach ( $rows as $label => $row ) {
			list( $mode, $permissions, $selected, $reason ) = $row;
			if ( 0500 === $permissions && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
				continue;
			}
			$this->remove_private_fixture( $this->private_root );
			MainWP_Backup_Private_Root::reset();
			$base    = $this->private_root;
			$folder  = 'abspath' === $mode ? 'private_html' : 'public_html';
			$abspath = $base . '/' . $folder . '/';
			$suffix  = '/mainwp-private/' . $folder . '/backups';
			$paths   = array(
				'default'      => $base . $suffix,
				'private_html' => $base . '/private_html' . $suffix,
				'private'      => $base . '/private' . $suffix,
				'override'     => $base . '/override/backups',
			);
			wp_mkdir_p( $abspath );
			if ( 'no-siblings' !== $mode ) {
				if ( 'symlink-base' === $mode ) {
					wp_mkdir_p( $base . '/elsewhere' );
					symlink( $base . '/elsewhere', $base . '/private_html' );
				} else {
					wp_mkdir_p( $base . '/private_html' );
				}
				wp_mkdir_p( $base . '/private' );
			}
			if ( in_array( $mode, array( 'both', 'snapshots', 'both-siblings', 'default-readonly' ), true ) ) {
				wp_mkdir_p( $paths['private_html'] . '/sites/1/snapshot-recorded' );
				file_put_contents( $paths['private_html'] . '/sites/1/snapshot-recorded/archive.zip', 'snapshot' );
			}
			if ( in_array( $mode, array( 'both', 'default-readonly' ), true ) ) {
				wp_mkdir_p( $paths['default'] );
			}
			if ( 'default-readonly' === $mode ) {
				chmod( $paths['default'], 0500 );
			}
			if ( in_array( $mode, array( 'private-snapshots', 'both-siblings' ), true ) ) {
				wp_mkdir_p( $paths['private'] );
			}
			if ( 'default-symlink' === $mode ) {
				wp_mkdir_p( dirname( $paths['default'] ) );
				symlink( $base . '/private', $paths['default'] );
			}
			// is_dir() is false for a dangling link, so only the refusal check keeps the ladder from picking a sibling.
			if ( 'default-dangling' === $mode ) {
				wp_mkdir_p( dirname( $paths['default'] ) );
				symlink( $base . '/missing', $paths['default'] );
			}
			if ( 'symlink' === $mode ) {
				wp_mkdir_p( dirname( $paths['private_html'] ) );
				symlink( $base . '/private', $paths['private_html'] );
			}
			if ( in_array( $mode, array( 'blocked', 'private' ), true ) ) {
				chmod( $base . '/private_html', 0500 );
			}
			if ( 'blocked' === $mode ) {
				chmod( $base . '/private', 0500 );
			}
			$before = array_map( 'is_dir', $paths );
			chmod( $base, $permissions );
			try {
				$config = array(
					'abspath'  => $abspath,
					'content'  => 'content' === $mode ? $base . '/private_html' : $abspath . 'wp-content',
					'constant' => 'constant' === $mode ? $paths['default'] : null,
					'filter'   => 'filter' === $mode ? $paths['override'] : null,
				);
				if ( 'trailing-slash' === $mode ) {
					$config['filter'] = $paths['default'] . '/';
				}
				// Separate processes let each host layout own its WordPress constants.
				$source = dirname( __DIR__, 2 ) . '/class/backup/class-mainwp-backup-private-root.php';
				// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- PHP literals preserve the fixture paths in the isolated process.
				$script = '$config = ' . var_export( $config, true ) . ';'
					. 'define("ABSPATH", $config["abspath"]); define("WP_CONTENT_DIR", $config["content"]);'
					. 'if (null !== $config["constant"]) { define("MAINWP_BACKUP_PRIVATE_DIR", $config["constant"]); }'
					. 'function wp_normalize_path($p) { return $p; } function untrailingslashit($p) { return rtrim($p, "/"); }'
					. 'function trailingslashit($p) { return rtrim($p, "/") . "/"; } function path_is_absolute($p) { return $p[0] === "/"; }'
					. 'function apply_filters($tag, $value) { global $config; return $config["filter"] ?? $value; }'
					. 'function __($value, $domain) { return $value; } class WP_Error { public $message; function __construct($code, $message) { $this->message = $message; } }'
					. 'require ' . var_export( $source, true ) . ';'
					. 'MainWP\\Dashboard\\MainWP_Backup_Private_Root::reset();'
					. '$root = MainWP\\Dashboard\\MainWP_Backup_Private_Root::resolve();'
					. '$reason = MainWP\\Dashboard\\MainWP_Backup_Private_Root::availability_reason();'
					. '$failure = new ReflectionMethod(MainWP\\Dashboard\\MainWP_Backup_Private_Root::class, "unavailable");'
					. 'echo json_encode([$root instanceof WP_Error ? null : $root, $reason, $root instanceof WP_Error ? $root->message : $failure->invoke(null, $root, $reason)->message]);';
				// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
				$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );
				$result = json_decode( $output, true );
				$this->assertIsArray( $result, $label . ': ' . $output );
				if ( 'error' === $selected ) {
					$this->assertNull( $result[0], $label );
					$this->assertStringContainsString( $reason, $result[2], $label );
					$this->assertDirectoryDoesNotExist( $base . '/private_html/mainwp-private', $label );
				} else {
					$this->assertSame( $paths[ $selected ] . '/', $result[0], $label );
				}
				$this->assertSame( $reason, $result[1], $label );
				clearstatcache();
				$this->assertSame( $before, array_map( 'is_dir', $paths ), $label );
				if ( 'no-siblings' === $mode ) {
					$this->assertDirectoryDoesNotExist( $base . '/private_html', $label );
					$this->assertDirectoryDoesNotExist( $base . '/private', $label );
				}
				if ( in_array( $mode, array( 'blocked', 'no-siblings' ), true ) ) {
					$this->assertStringContainsString( 'Sibling directories tried: private_html, private.', $result[2], $label );
				} else {
					$this->assertStringNotContainsString( 'Sibling directories tried:', $result[2], $label );
				}
			} finally {
				chmod( $base, 0700 );
				$this->remove_private_fixture( $base );
				MainWP_Backup_Private_Root::reset();
			}
		}
	}

	/**
	 * Warn before a backup tries to create storage under an unwritable parent.
	 */
	public function test_unwritable_root_notice_and_availability_cache() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses directory write permissions.' );
		}
		mkdir( $this->private_root );
		chmod( $this->private_root, 0500 );
		$path   = $this->private_root . '/backups/';
		$filter = static function () use ( $path ) {
			return $path;
		};
		add_filter( 'mainwp_backup_private_root', $filter, 99 );
		MainWP_Backup_Private_Root::reset();
		try {
			$this->assertSame( $path, MainWP_Backup_Private_Root::resolve() );
			$this->assertSame( 'could not create directory', MainWP_Backup_Private_Root::availability_reason() );
			ob_start();
			try {
				\MainWP\Dashboard\MainWP_Backup_Page::render_backup_environment_notices();
				$notice = ob_get_contents();
			} finally {
				ob_end_clean();
			}
			$this->assertStringContainsString( esc_html( $path ), $notice );
			$this->assertStringContainsString( 'could not create directory', $notice );
			$this->assertStringContainsString( esc_html( "define( 'MAINWP_BACKUP_PRIVATE_DIR', '/absolute/path/outside/the/web/root' );" ), $notice );
			$this->assertDirectoryDoesNotExist( $path );
			chmod( $this->private_root, 0700 );
			clearstatcache();
			$this->assertSame( $path, MainWP_Backup_Private_Root::resolve() );
			$this->assertSame( 'could not create directory', MainWP_Backup_Private_Root::availability_reason() );
			MainWP_Backup_Private_Root::reset();
			$this->assertSame( '', MainWP_Backup_Private_Root::availability_reason() );
			$this->assertSame( $path, MainWP_Backup_Private_Root::resolve() );
			// A root that exists but lost write access is still selected, and the page says why the write probe will fail.
			mkdir( $path );
			chmod( $path, 0500 );
			clearstatcache();
			MainWP_Backup_Private_Root::reset();
			$this->assertSame( $path, MainWP_Backup_Private_Root::resolve() );
			$this->assertSame( 'directory is not writable', MainWP_Backup_Private_Root::availability_reason() );
			chmod( $path, 0700 );
			clearstatcache();
			MainWP_Backup_Private_Root::reset();
			$this->assertSame( '', MainWP_Backup_Private_Root::availability_reason() );
		} finally {
			chmod( $this->private_root, 0700 );
			if ( is_dir( $path ) ) {
				chmod( $path, 0700 );
			}
			remove_filter( 'mainwp_backup_private_root', $filter, 99 );
			MainWP_Backup_Private_Root::reset();
		}
	}

	/**
	 * Readers never need a write probe; only writers create and protect storage.
	 */
	public function test_resolve_configuration_outcomes() {
		$rows = array( 'writable root', 'root read-only', 'root missing' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
		wp_mkdir_p( MainWP_Backup_Private_Root::legacy_site_dir( $site_id ) );
		foreach ( $rows as $label ) {
			$this->remove_private_fixture( $this->private_root );
			MainWP_Backup_Private_Root::reset();
			$site = $this->private_root . '/sites/' . $site_id . '/';
			$file = $site . 'snapshot-recorded/archive.zip';
			if ( 'root missing' !== $label ) {
				wp_mkdir_p( dirname( $file ) );
				file_put_contents( $file, 'recorded' );
				file_put_contents( $site . 'full-backup.zip', 'listable' );
				touch( $site . 'full-backup.zip', 1700000000 );
				wp_mkdir_p( $site . 'snapshot-orphan' );
				file_put_contents( $site . 'snapshot-orphan/archive.zip', 'orphan' );
				touch( $site . 'snapshot-orphan/archive.zip', time() - 100 );
				touch( $site . 'snapshot-orphan', time() - 100 );
				foreach ( array( '/restore-staging/7', '/upload-staging/7-8' ) as $staging ) {
					wp_mkdir_p( $this->private_root . $staging );
					file_put_contents( $this->private_root . $staging . '/archive.zip', 'staged' );
				}
				chmod( $this->private_root, 'root read-only' === $label ? 0500 : 0700 );
			}
			try {
				$this->assertSame( $this->private_root . '/', MainWP_Backup_Private_Root::resolve(), $label );
				$this->assertSame( $site, MainWP_Backup_Private_Root::site_path( $site_id ), $label );
				$exists = 'root missing' !== $label;
				$this->assertSame( $exists, MainWP_Backup_Private_Root::is_within_site_roots( $file, $site_id ), $label );
				$this->assertSame( $exists, MainWP_Backup_Private_Root::is_within_private_site_dir( $file, $site_id ), $label );
				$this->assertSame( $exists, \MainWP\Dashboard\MainWP_Backup_File_Manager::is_within_site_backup_dir( $file, $site_id ), $label );
				// The private mwpdl branch confines the file to resolve()'s root.
				$this->assertSame( $exists, \MainWP\Dashboard\MainWP_Backup_File_Manager::path_is_confined_to( $file, MainWP_Backup_Private_Root::resolve() ), $label );
				ob_start();
				\MainWP\Dashboard\MainWP_Manage_Sites::show_backups( $website );
				$html = ob_get_clean();
				// A non-direct transport cannot map the private root, so the column must not read through it.
				global $wp_filesystem;
				$saved_fs      = $wp_filesystem;
				$wp_filesystem = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stands in for a non-direct transport.
					public function exists( $path ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- blind transport stub.
						return false;
					}
					public function mtime( $path ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- blind transport stub.
						return false;
					}
				};
				try {
					$table  = new \MainWP\Dashboard\MainWP_Manage_Sites_List_Table();
					$column = $table->column_backup( (array) $website );
				} finally {
					$wp_filesystem = $saved_fs; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the real transport.
				}
				if ( $exists ) {
					$this->assertStringContainsString( 'full-backup.zip', $html, $label );
					$this->assertStringNotContainsString( 'No full backup has been taken yet', $html, $label );
					$this->assertStringContainsString( 'data-tooltip="' . esc_attr( \MainWP\Dashboard\MainWP_Utility::format_timestamp( \MainWP\Dashboard\MainWP_Utility::get_timestamp( 1700000000 ) ) ) . '"', $column, $label );
					$this->assertStringContainsString( \MainWP\Dashboard\MainWP_Utility::time_elapsed_string( 1700000000 ), $column, $label );
				} else {
					$this->assertStringContainsString( 'No full backup has been taken yet', $html, $label );
					$this->assertStringContainsString( 'Never', $column, $label );
				}
				ob_start();
				\MainWP\Dashboard\MainWP_Backup_Page::render_backup_environment_notices();
				$notice = ob_get_clean();
				if ( 'root read-only' === $label ) {
					$this->assertStringContainsString( 'directory is not writable', $notice, $label );
				} else {
					$this->assertStringNotContainsString( 'Private backup directory', $notice, $label );
				}
				if ( $exists ) {
					$remove   = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'remove_local_dir' );
					$snapshot = (object) array(
						'id'            => 999999,
						'site_id'       => $site_id,
						'archives'      => array( array( 'path' => $file ) ),
						'manifest_path' => '',
					);
					$errors   = array();
					$args     = array( $snapshot, &$errors );
					$this->assertTrue( $remove->invokeArgs( MainWP_Backup_Retention_Executor::instance(), $args ), $label );
					$this->assertSame( array(), $errors, $label );
					$this->assertDirectoryDoesNotExist( dirname( $file ), $label );
				}
				$result = array(
					'orphan_dirs_removed' => 0,
					'errors'              => array(),
				);
				$this->purge_orphan_dirs( $site_id, 10, $result );
				$this->assertSame( $exists ? 1 : 0, $result['orphan_dirs_removed'], $label );
				$this->assertSame( array(), $result['errors'], $label );
				$this->assertDirectoryDoesNotExist( $site . 'snapshot-orphan', $label );
				if ( $exists ) {
					$this->assertFileExists( $site . 'full-backup.zip', $label );
				}
				$restore = new MainWP_Backup_Restore_Transfer_Driver();
				$restore->cleanup_staging( 7 );
				$cleanup = new \ReflectionMethod( MainWP_Backup_Upload_Driver::class, 'cleanup_staging' );
				$job     = (object) array(
					'snapshot_id'    => 7,
					'destination_id' => 8,
				);
				$cleanup->invoke( MainWP_Backup_Upload_Driver::instance(), $job );
				$this->assertDirectoryDoesNotExist( $this->private_root . '/restore-staging/7', $label );
				$this->assertDirectoryDoesNotExist( $this->private_root . '/upload-staging/7-8', $label );
				$this->assertSame( $exists, is_dir( $this->private_root ), $label );
				$site_result    = MainWP_Backup_Private_Root::site_dir( $site_id );
				$staging_result = MainWP_Backup_Private_Root::staging_dir( 'restore-staging', 'job' );
				if ( 'root read-only' === $label ) {
					foreach ( array( $site_result, $staging_result ) as $error ) {
						$this->assertWPError( $error, $label );
						$this->assertSame( 'mainwp_backup_private_root_unavailable', $error->get_error_code(), $label );
					}
					$this->assertSame( 0500, fileperms( $this->private_root ) & 0777, $label );
					$this->assertSame( $site, MainWP_Backup_Private_Root::site_path( $site_id ), $label );
				} else {
					$this->assertSame( $site, $site_result, $label );
					$this->assertSame( $this->private_root . '/restore-staging/job/', $staging_result, $label );
					$this->assertDirectoryExists( $site_result, $label );
					$this->assertDirectoryExists( $staging_result, $label );
					if ( 'root missing' === $label ) {
						$this->assertSame( 0700, fileperms( $this->private_root ) & 0777, $label );
						$this->assertSame( 'deny from all', file_get_contents( $this->private_root . '/.htaccess' ), $label );
						$this->assertFileExists( $this->private_root . '/index.php', $label );
					}
				}
			} finally {
				if ( is_dir( $this->private_root ) ) {
					chmod( $this->private_root, 0700 );
				}
			}
		}
	}

	/**
	 * Select the orphaned snapshot directories, then remove exactly those.
	 *
	 * @param int   $site_id Site ID.
	 * @param int   $min_age Minimum directory age in seconds.
	 * @param array $result  Retention result, updated by reference.
	 *
	 * @return void
	 */
	private function purge_orphan_dirs( int $site_id, int $min_age, array &$result ): void {
		$executor = MainWP_Backup_Retention_Executor::instance();
		$select   = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'select_orphan_dirs' );
		$remove   = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'remove_orphan_dirs' );
		$dirs     = $select->invoke( $executor, $site_id, $min_age );
		$args     = array( $dirs, &$result );
		$remove->invokeArgs( $executor, $args );
	}
	/**
	 * Recorded subfolder snapshots count once per page; orphan folders do not.
	 */
	public function test_s84_backup_column_batches_recorded_snapshot_times(): void {
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$sites   = array( $this->create_test_site( array( 'userid' => $user ) ), $this->create_test_site( array( 'userid' => $user ) ) );
		$created = 1700001000;
		foreach ( $sites as $index => $site ) {
			$dir = MainWP_Backup_Private_Root::site_dir( $site );
			file_put_contents( $dir . 'full.zip', 'legacy' );
			touch( $dir . 'full.zip', 1700000000 );
			wp_mkdir_p( $dir . 'snapshot-task-' . $site );
			file_put_contents( $dir . 'snapshot-task-' . $site . '/archive.zip', 'recorded' );
			wp_mkdir_p( $dir . 'snapshot-orphan' );
			file_put_contents( $dir . 'snapshot-orphan/archive.zip', 'orphan' );
			touch( $dir . 'snapshot-orphan/archive.zip', $created + 5000 );
			$wpdb->insert(
				\MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'site_id'       => $site,
					'task_id'       => 0,
					'type'          => 'incremental',
					'created'       => $created + $index,
					'status'        => 'complete',
					'archives'      => '[]',
					'manifest_path' => '',
				)
			);
		}
		$table        = new \MainWP\Dashboard\MainWP_Manage_Sites_List_Table();
		$table->items = array_map(
			static function ( $id ) {
				return (array) \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $id );
			},
			$sites
		);
		$queries      = 0;
		$count        = static function ( $sql ) use ( &$queries ) {
			if ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'wp_backup_snapshot' ) ) {
				++$queries;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		try {
			foreach ( $table->items as $index => $item ) {
				$expected = 'data-tooltip="' . esc_attr( \MainWP\Dashboard\MainWP_Utility::format_timestamp( \MainWP\Dashboard\MainWP_Utility::get_timestamp( $created + $index ) ) ) . '"';
				$first    = $table->column_backup( $item );
				$this->assertStringContainsString( $expected, $first );
				$this->assertStringContainsString( \MainWP\Dashboard\MainWP_Utility::time_elapsed_string( $created + $index ), $first );
				$this->assertSame( $first, $table->column_backup( $item ) );
			}
			$this->assertSame( 1, $queries, 'All page rows and repeated renders must share one snapshot query.' );
		} finally {
			remove_filter( 'query', $count );
			delete_option( 'mainwp_enableNativeBackups' );
		}
	}
}
