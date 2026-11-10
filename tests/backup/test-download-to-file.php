<?php
/**
 * MainWP download_to_file() Integration Tests
 *
 * Exercises the rewritten download path against a local fixture HTTP
 * server that simulates child site behaviors: Range-aware resume,
 * Range-ignoring old children, 416 handling, error bodies, stalls,
 * timeouts, and interrupted transfers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Exception;

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct file IO in tests.

/**
 * Class MainWP_Download_To_File_Test
 */
class MainWP_Download_To_File_Test extends MainWP_Backup_Test_Case {

	/**
	 * A compromised Child that streams twice its declared size is cut off near
	 * that size, and nothing is left behind as a completed artifact.
	 *
	 * @dataProvider child_stream_prefixes
	 * @param int $prefix Bytes already on disk, which sends a Range the route ignores.
	 */
	public function test_compromised_child_streaming_past_declared_size_aborts( $prefix ) {
		$declared = 1048576;
		$file     = $this->temp_download_path();
		if ( $prefix ) {
			file_put_contents( $file, static::fixture_content( $prefix ) );
		}
		$received = 0;
		$meter    = static function ( $options ) use ( &$received ) {
			$options[ CURLOPT_NOPROGRESS ]       = false;
			$options[ CURLOPT_XFERINFOFUNCTION ] = static function ( $handle, $download_total, $downloaded ) use ( &$received ) {
				$received = max( $received, (int) $downloaded );
				return 0;
			};
			return $options;
		};
		add_filter( 'mainwp_download_to_file_curl_options', $meter );
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/oversized-stream?size=' . $declared ), $file, $declared );
			$this->fail( 'The oversized stream completed.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $error->get_message_error_code() );
			$this->assertStringContainsString( 'exceeds declared size', (string) $error->get_message_extra() );
		} finally {
			remove_filter( 'mainwp_download_to_file_curl_options', $meter );
		}
		$this->assertLessThan( (int) ( $declared * 1.1 ), $received, 'The transfer ran on past the declared size.' );
		clearstatcache( true, $file );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * Fresh and Range-ignored downloads.
	 *
	 * @return array
	 */
	public static function child_stream_prefixes() {
		return array(
			'fresh'         => array( 0 ),
			'Range ignored' => array( 1024 ),
		);
	}

	/**
	 * Transfer failures describe the bytes actually retained for the retry.
	 *
	 * @dataProvider interrupted_data_cases
	 * @param int|false $expected Expected total size.
	 * @param int       $prefix Existing resumable bytes.
	 * @param int       $sent Bytes returned before the connection closes.
	 * @param string    $message Expected failure message.
	 */
	public function test_b311_interrupted_download_message( $expected, $prefix, $sent, $message ) {
		$file = $this->temp_download_path();
		if ( $prefix > 0 ) {
			file_put_contents( $file, substr( static::fixture_content( 4096 ), 0, $prefix ) );
		}
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/partial?size=4096&send=' . $sent ), $file, $expected );
			$this->fail( 'Expected an interrupted transfer.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( $message, $error->getMessage() );
			$this->assertNotEmpty( $error->get_message_error_code() );
		}
		clearstatcache( true, $file );
		$this->assertSame( $sent > 0 ? $sent : $prefix, is_file( $file ) ? filesize( $file ) : 0 );
	}

	/**
	 * Unknown-size downloads restart even when partial bytes remain on disk.
	 *
	 * @return array Message cases.
	 */
	public static function interrupted_data_cases() {
		return array(
			'no data'       => array( 4096, 0, 0, 'The backup download was interrupted before completing. No partial data was saved. Try the download again.' ),
			'kept prefix'   => array( 4096, 512, 0, 'The backup download was interrupted before completing. Partial data was kept so the next attempt can resume.' ),
			'kept new data' => array( 4096, 0, 1024, 'The backup download was interrupted before completing. Partial data was kept so the next attempt can resume.' ),
			'Range ignored' => array( 4096, 512, 1024, 'The backup download was interrupted before completing. Partial data was kept. Try the download again.' ),
			'unknown size'  => array( false, 0, 1024, 'The backup download was interrupted before completing. Partial data was kept. Try the download again.' ),
		);
	}

	/**
	 * Use short timeouts so stall/timeout tests run fast.
	 *
	 * @var callable|null
	 */
	private $timeouts_filter = null;

	/**
	 * Remove any timeout filter overrides.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( null !== $this->timeouts_filter ) {
			remove_filter( 'mainwp_download_to_file_timeouts', $this->timeouts_filter );
			$this->timeouts_filter = null;
		}
		if ( null !== $this->segment_filter ) {
			remove_filter( 'mainwp_download_to_file_segment_bytes', $this->segment_filter );
			$this->segment_filter = null;
		}
		foreach ( $this->range_logs as $log ) {
			if ( is_file( sys_get_temp_dir() . '/' . $log ) ) {
				unlink( sys_get_temp_dir() . '/' . $log );
			}
		}
		$this->range_logs = array();
		parent::tear_down();
	}

	/**
	 * Apply short timeouts for the current test.
	 *
	 * @param array $overrides Timeout keys to override.
	 *
	 * @return void
	 */
	private function use_timeouts( $overrides ) {
		$this->timeouts_filter = function ( $timeouts ) use ( $overrides ) {
			return array_merge( $timeouts, $overrides );
		};
		add_filter( 'mainwp_download_to_file_timeouts', $this->timeouts_filter );
	}

	/**
	 * A fresh download writes the complete file.
	 *
	 * @return void
	 */
	public function test_fresh_download_creates_file() {
		$file = $this->temp_download_path();

		MainWP_Connect::download_to_file( static::fixture_url( '/200?size=4096' ), $file, 4096 );

		$this->assertFileExists( $file );
		$this->assertSame( static::fixture_content( 4096 ), file_get_contents( $file ) );
	}

	/**
	 * Backup downloads must honor the child's configured TLS policy.
	 */
	public function test_site_tls_settings_apply_to_download_request() {
		$captured        = null;
		$filter          = static function ( $options ) use ( &$captured ) {
			$captured = $options;
			return $options;
		};
		$original_verify = get_option( 'mainwp_sslVerifyCertificate' );
		$url             = static::fixture_url( '/200?size=4096' );
		$http_version    = function ( $value, $site_id, $request_url ) use ( $url ) {
			$this->assertSame( 42, $site_id );
			$this->assertSame( $url, $request_url );
			return CURL_HTTP_VERSION_1_1;
		};
		$resolve         = function ( $value, $site_id, $site_url ) use ( $url ) {
			$this->assertSame( 42, $site_id );
			$this->assertSame( $url, $site_url );
			return array( 'example.test:443:127.0.0.1' );
		};
		add_filter( 'mainwp_download_to_file_curl_options', $filter );
		add_filter( 'mainwp_curl_http_version', $http_version, 10, 3 );
		add_filter( 'mainwp_curl_curlopt_resolve', $resolve, 10, 3 );
		try {
			$cases = array(
				array( 0, 1, false, 0 ),
				array( 2, 1, true, 0 ),
				array( 2, 0, false, 0 ),
				array( null, 1, true, 0 ),
				array( null, 0, false, 0 ),
				array( 0, 0, false, 1 ),
			);
			foreach ( $cases as list( $certificate, $global_verify, $expected, $ipv4 ) ) {
				update_option( 'mainwp_sslVerifyCertificate', $global_verify );
				$website = (object) array(
					'id'             => 42,
					'url'            => $url,
					'ssl_version'    => CURL_SSLVERSION_TLSv1_2,
					'force_use_ipv4' => $ipv4,
				);
				if ( null !== $certificate ) {
					$website->verify_certificate = $certificate;
				}
				MainWP_Connect::download_to_file( $url, $this->temp_download_path(), 4096, null, null, $website );
				$this->assertSame( $expected, $captured[ CURLOPT_SSL_VERIFYPEER ] );
				$this->assertSame( $expected ? 2 : false, $captured[ CURLOPT_SSL_VERIFYHOST ] );
				$this->assertSame( CURL_SSLVERSION_TLSv1_2, $captured[ CURLOPT_SSLVERSION ] );
				$this->assertSame( CURL_HTTP_VERSION_1_1, $captured[ CURLOPT_HTTP_VERSION ] );
				$this->assertSame( array( 'example.test:443:127.0.0.1' ), $captured[ CURLOPT_RESOLVE ] );
				$this->assertFalse( $captured[ CURLOPT_DNS_USE_GLOBAL_CACHE ] );
				if ( $ipv4 ) {
					$this->assertSame( CURL_IPRESOLVE_V4, $captured[ CURLOPT_IPRESOLVE ] );
				} else {
					$this->assertArrayNotHasKey( CURLOPT_IPRESOLVE, $captured );
				}
			}
		} finally {
			remove_filter( 'mainwp_download_to_file_curl_options', $filter );
			remove_filter( 'mainwp_curl_http_version', $http_version );
			remove_filter( 'mainwp_curl_curlopt_resolve', $resolve );
			if ( false === $original_verify ) {
				delete_option( 'mainwp_sslVerifyCertificate' );
			} else {
				update_option( 'mainwp_sslVerifyCertificate', $original_verify );
			}
		}
	}

	/**
	 * A short transfer needs one heartbeat, not one per cURL write.
	 */
	public function test_download_progress_reports_stored_bytes_once() {
		$file     = $this->temp_download_path();
		$events   = array();
		$listener = static function ( $path, $bytes ) use ( &$events ) {
			$events[] = array( $path, $bytes );
		};
		add_action( 'mainwp_download_to_file_progress', $listener, 10, 2 );
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/200?size=65536' ), $file, 65536 );
			$this->assertCount( 1, $events );
			$this->assertSame( $file, $events[0][0] );
			$this->assertGreaterThan( 0, $events[0][1] );
			$this->assertLessThanOrEqual( 65536, $events[0][1] );
		} finally {
			remove_action( 'mainwp_download_to_file_progress', $listener );
		}
	}

	/**
	 * Optional progress listeners cannot interrupt or corrupt the transfer.
	 */
	public function test_throwing_progress_listener_does_not_interrupt_download() {
		$file     = $this->temp_download_path();
		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
			throw new \RuntimeException( 'Listener failed.' );
		};
		add_action( 'mainwp_download_to_file_progress', $listener );
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/200?size=65536' ), $file, 65536 );
			$this->assertSame( 1, $calls );
			$this->assertSame( static::fixture_content( 65536 ), file_get_contents( $file ) );
		} finally {
			remove_action( 'mainwp_download_to_file_progress', $listener );
		}
	}

	/**
	 * A resume request appends the confirmed partial response without
	 * duplicating bytes.
	 *
	 * @return void
	 */
	public function test_resume_appends_confirmed_partial_response() {
		$file = $this->temp_download_path();
		file_put_contents( $file, substr( static::fixture_content( 4096 ), 0, 1024 ) );

		MainWP_Connect::download_to_file( static::fixture_url( '/206?size=4096' ), $file, 4096 );

		$this->assertSame( static::fixture_content( 4096 ), file_get_contents( $file ) );
	}

	/**
	 * When the server ignores Range and answers 200, the download restarts
	 * from byte zero instead of appending the full body to the partial file.
	 *
	 * @return void
	 */
	public function test_range_ignored_restarts_from_zero_without_duplication() {
		$file = $this->temp_download_path();
		file_put_contents( $file, substr( static::fixture_content( 4096 ), 0, 1024 ) );

		MainWP_Connect::download_to_file( static::fixture_url( '/range-ignored?size=4096' ), $file, 4096 );

		$this->assertSame( 4096, filesize( $file ), 'Appending the full body would have produced 5120 bytes.' );
		$this->assertSame( static::fixture_content( 4096 ), file_get_contents( $file ) );
	}

	/**
	 * A 416 whose reported total matches the local file but falls short of the
	 * advertised size is a smaller artifact than promised, not a finished
	 * download: the transfer restarts and the final size check refuses it
	 * instead of announcing an undersized file as complete.
	 *
	 * @return void
	 */
	public function test_416_with_total_below_advertised_size_is_refused() {
		$file    = $this->temp_download_path();
		$content = static::fixture_content( 4096 );
		file_put_contents( $file, $content );

		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/206?size=4096' ), $file, 4100 );
			$this->fail( 'Expected the undersized artifact to be refused.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL, $e->get_message_error_code() );
		}
	}

	/**
	 * A 416 with a mismatched total restarts the download from scratch.
	 *
	 * @return void
	 */
	public function test_416_with_mismatched_total_restarts_fresh() {
		$file = $this->temp_download_path();
		file_put_contents( $file, str_repeat( 'X', 1024 ) ); // Garbage partial data.

		MainWP_Connect::download_to_file( static::fixture_url( '/416-then-200?size=4096' ), $file, 4096 );

		$this->assertSame( static::fixture_content( 4096 ), file_get_contents( $file ) );
	}

	/**
	 * Error response bodies are never written into the backup file.
	 *
	 * @return void
	 */
	public function test_500_error_body_never_written_to_file() {
		$file = $this->temp_download_path();

		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/500?size=4096' ), $file, 4096 );
			$this->fail( 'Expected exception for HTTP 500.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_http_error', $e->get_message_error_code() );
		}

		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * A 206 confirming the wrong start offset aborts without touching the file.
	 *
	 * @return void
	 */
	public function test_206_wrong_start_aborts_and_preserves_partial_file() {
		$file    = $this->temp_download_path();
		$partial = substr( static::fixture_content( 4096 ), 0, 1024 );
		file_put_contents( $file, $partial );

		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/206-wrong-start?size=4096' ), $file, 4096 );
			$this->fail( 'Expected exception for mismatched Content-Range start.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_http_error', $e->get_message_error_code() );
		}

		$this->assertSame( $partial, file_get_contents( $file ), 'The partial file must stay untouched after an unsafe response.' );
	}

	/**
	 * A transfer that drips below the speed threshold aborts as stalled.
	 *
	 * @return void
	 */
	public function test_stalled_transfer_throws_stalled_error() {
		$this->use_timeouts(
			array(
				'low_speed_limit' => 1048576,
				'low_speed_time'  => 1,
			)
		);

		$file = $this->temp_download_path();

		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/slow-drip?size=4096' ), $file, 4096 );
			$this->fail( 'Expected stall exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_stalled', $e->get_message_error_code() );
		}
	}

	/**
	 * A transfer exceeding the total timeout aborts with the timeout error.
	 *
	 * @return void
	 */
	public function test_timeout_throws_timeout_error() {
		$this->use_timeouts( array( 'timeout' => 1 ) );

		$file = $this->temp_download_path();

		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/sleep?s=4&size=64' ), $file, 64 );
			$this->fail( 'Expected timeout exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_timeout', $e->get_message_error_code() );
		}
	}

	/**
	 * A complete download smaller than the expected size is rejected.
	 *
	 * @return void
	 */
	public function test_download_smaller_than_expected_throws_too_small() {
		$file = $this->temp_download_path();

		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/200?size=1000' ), $file, 4096 );
			$this->fail( 'Expected size-mismatch exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_file_too_small', $e->get_message_error_code() );
		}
	}

	/**
	 * An interrupted transfer keeps the valid partial bytes so the next
	 * attempt can resume, and the resume completes the file.
	 *
	 * @return void
	 */
	public function test_interrupted_transfer_preserves_partial_for_resume() {
		$file = $this->temp_download_path();

		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/partial?size=4096&send=1024' ), $file, 4096 );
			$this->fail( 'Expected exception for interrupted transfer.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertNotEmpty( $e->get_message_error_code() );
		}

		$this->assertFileExists( $file );
		$this->assertSame( substr( static::fixture_content( 4096 ), 0, 1024 ), file_get_contents( $file ), 'Partial bytes must be preserved for resume.' );

		// The next attempt resumes from the preserved offset and completes.
		MainWP_Connect::download_to_file( static::fixture_url( '/206?size=4096' ), $file, 4096 );
		$this->assertSame( static::fixture_content( 4096 ), file_get_contents( $file ) );
	}
	/**
	 * Scheme changes and the www alias do not change the Child's host.
	 *
	 * @return array Host comparisons.
	 */
	public static function download_host_pairs(): array {
		return array(
			'same'      => array( 'https://example.org/', 'https://example.org/file', true ),
			'www'       => array( 'https://www.example.org/', 'https://example.org/file', true ),
			'www added' => array( 'https://example.org/', 'https://www.example.org/file', true ),
			'different' => array( 'https://example.org/', 'https://other.example/file', false ),
			'scheme'    => array( 'http://example.org/', 'https://example.org/file', true ),
			'private'   => array( 'http://192.168.1.2/', 'http://192.168.1.2/file', true ),
			'missing'   => array( 'https://example.org/', '/file', false ),
		);
	}

	/**
	 * Download authorization follows the site host rather than the URL path.
	 *
	 * @dataProvider download_host_pairs
	 * @param string $site Site URL.
	 * @param string $effective Effective URL.
	 * @param bool   $expected Whether hosts match.
	 */
	public function test_download_hosts_match( string $site, string $effective, bool $expected ): void {
		$this->assertSame( $expected, MainWP_Connect::download_hosts_match( $site, $effective ) );
	}

	/** Unsafe protocols and redirect loops cannot consume the download lane. */
	public function test_download_redirect_options_are_bounded(): void {
		$captured = array();
		$observe  = static function ( $options ) use ( &$captured ) {
			$captured = $options;
			return $options;
		};
		add_filter( 'mainwp_download_to_file_curl_options', $observe );
		$file = $this->temp_download_path();
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/200?size=4096' ), $file, 4096 );
			$this->assertSame( CURLPROTO_HTTP | CURLPROTO_HTTPS, $captured[ CURLOPT_PROTOCOLS ] ?? null );
			$this->assertSame( CURLPROTO_HTTP | CURLPROTO_HTTPS, $captured[ CURLOPT_REDIR_PROTOCOLS ] ?? null );
			$this->assertSame( 3, $captured[ CURLOPT_MAXREDIRS ] ?? null );
		} finally {
			remove_filter( 'mainwp_download_to_file_curl_options', $observe );
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** An HTTPS transfer permits only HTTPS redirects before contacting the Child. */
	public function test_https_download_restricts_redirect_protocol(): void {
		$observe = function ( $options ) {
			$this->assertSame( CURLPROTO_HTTPS, $options[ CURLOPT_REDIR_PROTOCOLS ] ?? null );
			throw new \RuntimeException( 'Captured redirect options before the request.' );
		};
		add_filter( 'mainwp_download_to_file_curl_options', $observe );
		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Captured redirect options before the request.' );
			MainWP_Connect::download_to_file( 'https://example.test/archive.zip', $this->temp_download_path(), 4096 );
		} finally {
			remove_filter( 'mainwp_download_to_file_curl_options', $observe );
		}
	}

	/** A successful response from a different host must never become an archive. */
	public function test_download_refuses_effective_host_outside_site(): void {
		$file    = $this->temp_download_path();
		$website = (object) array(
			'id'  => 42,
			'url' => 'https://other.example/',
		);
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/200?size=4096' ), $file, 4096, null, null, $website );
			$this->fail( 'A different effective host was accepted.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( 'mainwp_backup_http_error', $error->get_message_error_code() );
			$this->assertFileDoesNotExist( $file );
		} finally {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** A Child that redirects within its own host still delivers the archive. */
	public function test_same_host_redirect_is_followed(): void {
		$file = $this->temp_download_path();
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/redirect-same-host?size=4096' ), $file, 4096 );
			$this->assertSame( static::fixture_content( 4096 ), file_get_contents( $file ) );
		} finally {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** A site stored without url is held to the host the request was built for. */
	public function test_empty_site_url_uses_request_host(): void {
		$file    = $this->temp_download_path();
		$website = (object) array(
			'id'  => 42,
			'url' => '',
		);
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/200?size=4096' ), $file, 4096, null, null, $website );
			$this->assertSame( static::fixture_content( 4096 ), file_get_contents( $file ) );
		} finally {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** The request-host fallback for a site without url still refuses a redirect to another host. */
	public function test_empty_site_url_refuses_cross_host_redirect(): void {
		$token   = 'mainwp-redirect-' . bin2hex( random_bytes( 8 ) );
		$marker  = sys_get_temp_dir() . '/' . $token;
		$file    = $this->temp_download_path();
		$website = (object) array(
			'id'  => 42,
			'url' => '',
		);
		try {
			try {
				MainWP_Connect::download_to_file( static::fixture_url( '/redirect-other-host?marker=' . $token ), $file, 4096, null, null, $website );
				$this->fail( 'The redirect was accepted.' );
			} catch ( MainWP_Exception $error ) {
				$this->assertSame( 'mainwp_backup_http_error', $error->get_message_error_code() );
			}
			$this->assertFileDoesNotExist( $marker, 'cURL contacted the redirect target.' );
			$this->assertFileDoesNotExist( $file );
		} finally {
			foreach ( array( $file, $marker ) as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
	}

	/** Final-host validation must not permit an earlier request to another host. */
	public function test_cross_host_redirect_is_refused_before_contact(): void {
		$token  = 'mainwp-redirect-' . bin2hex( random_bytes( 8 ) );
		$marker = sys_get_temp_dir() . '/' . $token;
		$file   = $this->temp_download_path();
		try {
			try {
				MainWP_Connect::download_to_file( static::fixture_url( '/redirect-other-host?marker=' . $token ), $file, 4096 );
				$this->fail( 'The redirect was accepted.' );
			} catch ( MainWP_Exception $error ) {
				$this->assertSame( 'mainwp_backup_http_error', $error->get_message_error_code() );
			}
			$this->assertFileDoesNotExist( $marker, 'cURL contacted the redirect target.' );
			$this->assertFileDoesNotExist( $file );
		} finally {
			foreach ( array( $file, $marker ) as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
	}

	/**
	 * Segment size override for the current test.
	 *
	 * @var callable|null
	 */
	private $segment_filter = null;

	/**
	 * Range log files the fixture server wrote for the current test.
	 *
	 * @var string[]
	 */
	private $range_logs = array();

	/**
	 * Use small segments so a few KB exercise the multi-part path.
	 *
	 * @param int $bytes Segment size.
	 * @return void
	 */
	private function use_segment_bytes( $bytes ) {
		$this->segment_filter = static function () use ( $bytes ) {
			return $bytes;
		};
		add_filter( 'mainwp_download_to_file_segment_bytes', $this->segment_filter );
	}

	/**
	 * A fresh range log name the fixture server appends to.
	 *
	 * @return string Log basename.
	 */
	private function range_log() {
		$log                = 'mainwp-ranges-' . bin2hex( random_bytes( 6 ) );
		$this->range_logs[] = $log;
		return $log;
	}

	/**
	 * Requests the fixture server saw, as [request marker, Range header] pairs.
	 *
	 * @param string $log Log basename.
	 * @return array[]
	 */
	private function logged_requests( $log ) {
		$path = sys_get_temp_dir() . '/' . $log;
		if ( ! is_file( $path ) ) {
			return array();
		}
		return array_map(
			static function ( $line ) {
				return array_slice( explode( '|', $line ), 0, 2 );
			},
			file( $path, FILE_IGNORE_NEW_LINES )
		);
	}

	/**
	 * Stand-in for per-request Child authorization: each call yields a distinct URL.
	 *
	 * @param string $route Route with query, without the request marker.
	 * @param int    $calls By-ref count of renewals.
	 * @return callable
	 */
	private function renewing_urls( $route, &$calls ) {
		return static function () use ( $route, &$calls ) {
			++$calls;
			return static::fixture_url( $route . '&m=' . ( $calls + 1 ) );
		};
	}

	/**
	 * Run a segmented download against the bounded route.
	 *
	 * @param string   $route Route with query.
	 * @param string   $file  Target path.
	 * @param int      $size  Declared size.
	 * @param int|null $calls By-ref renewal count.
	 * @return void
	 */
	private function segmented_download( $route, $file, $size, &$calls = null ) {
		$calls = 0;
		MainWP_Connect::download_to_file( static::fixture_url( $route . '&m=1' ), $file, $size, null, null, null, $this->renewing_urls( $route, $calls ) );
	}

	/**
	 * MWP-2183: an archive larger than the server's response cap arrives in
	 * bounded parts from byte 0, each part on fresh authorization.
	 *
	 * @return void
	 */
	public function test_large_download_uses_bounded_segments_from_byte_zero() {
		$this->use_segment_bytes( 4096 );
		$log  = $this->range_log();
		$file = $this->temp_download_path();

		$this->segmented_download( '/bounded?size=10000&limit=4096&log=' . $log, $file, 10000, $calls );

		$this->assertSame( static::fixture_content( 10000 ), file_get_contents( $file ) );
		$this->assertSame(
			array(
				array( '1', 'bytes=0-4095' ),
				array( '2', 'bytes=4096-8191' ),
				array( '3', 'bytes=8192-9999' ),
			),
			$this->logged_requests( $log )
		);
		$this->assertSame( 2, $calls, 'Every request after the first must use a renewed URL.' );
	}

	/**
	 * MWP-2183: a resume from a non-aligned offset stays bounded.
	 *
	 * @return void
	 */
	public function test_resumed_download_requests_bounded_segments() {
		$this->use_segment_bytes( 4096 );
		$log  = $this->range_log();
		$file = $this->temp_download_path();
		file_put_contents( $file, substr( static::fixture_content( 10000 ), 0, 1000 ) );

		$this->segmented_download( '/bounded?size=10000&limit=4096&log=' . $log, $file, 10000, $calls );

		$this->assertSame( static::fixture_content( 10000 ), file_get_contents( $file ) );
		$this->assertSame(
			array(
				array( '1', 'bytes=1000-5095' ),
				array( '2', 'bytes=5096-9191' ),
				array( '3', 'bytes=9192-9999' ),
			),
			$this->logged_requests( $log )
		);
	}

	/**
	 * MWP-2183: the last part asks for exactly the remaining bytes.
	 *
	 * @return void
	 */
	public function test_final_short_segment_requests_only_remaining_bytes() {
		$this->use_segment_bytes( 4096 );
		$log  = $this->range_log();
		$file = $this->temp_download_path();

		$this->segmented_download( '/bounded?size=8200&limit=4096&log=' . $log, $file, 8200, $calls );

		$requests = $this->logged_requests( $log );
		$this->assertCount( 3, $requests );
		$this->assertSame( 'bytes=8192-8199', $requests[2][1] );
		$this->assertSame( static::fixture_content( 8200 ), file_get_contents( $file ) );
	}

	/**
	 * Without a URL renewer the transfer keeps the single-request contract.
	 *
	 * @return void
	 */
	public function test_download_without_url_renewer_stays_single_request() {
		$this->use_segment_bytes( 4096 );
		$log  = $this->range_log();
		$file = $this->temp_download_path();

		MainWP_Connect::download_to_file( static::fixture_url( '/bounded?size=10000&log=' . $log . '&m=1' ), $file, 10000 );

		$this->assertSame( array( array( '1', '' ) ), $this->logged_requests( $log ) );
		$this->assertSame( static::fixture_content( 10000 ), file_get_contents( $file ) );
	}

	/**
	 * MWP-2183: a later part whose Content-Range or Content-Length disagrees
	 * with the request is refused before any of its bytes are appended.
	 *
	 * @dataProvider mismatched_segment_cases
	 * @param string $fault Fixture fault on the second request.
	 * @param string $code  Expected error code.
	 * @return void
	 */
	public function test_mismatched_segment_is_rejected_before_append( $fault, $code ) {
		$this->use_segment_bytes( 4096 );
		$file = $this->temp_download_path();

		try {
			$this->segmented_download( '/bounded?size=10000&fault=' . $fault . '&at=2&log=' . $this->range_log(), $file, 10000 );
			$this->fail( 'The mismatched segment was accepted.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( $code, $error->get_message_error_code() );
		}

		clearstatcache( true, $file );
		$this->assertSame( substr( static::fixture_content( 10000 ), 0, 4096 ), file_get_contents( $file ), 'Only the validated first part may remain.' );
	}

	/**
	 * Responses that must not reach the archive.
	 *
	 * @return array
	 */
	public static function mismatched_segment_cases() {
		return array(
			'Content-Range end'   => array( 'wrong-end', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR ),
			'Content-Range total' => array( 'wrong-total', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR ),
			'Content-Length'      => array( 'wrong-length', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR ),
			'body past range end' => array( 'overflow', MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ),
			'error body'          => array( '500', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR ),
		);
	}

	/**
	 * MWP-2183: a part that closes early keeps its valid bytes, and the next
	 * call resumes from there.
	 *
	 * @return void
	 */
	public function test_short_segment_keeps_valid_prefix_for_resume() {
		$this->use_segment_bytes( 4096 );
		$file = $this->temp_download_path();

		try {
			$this->segmented_download( '/bounded?size=10000&fault=short&at=2&log=' . $this->range_log(), $file, 10000 );
			$this->fail( 'The short segment was accepted.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL, $error->get_message_error_code() );
		}
		clearstatcache( true, $file );
		$this->assertSame( 8182, filesize( $file ) );

		$log = $this->range_log();
		$this->segmented_download( '/bounded?size=10000&log=' . $log, $file, 10000 );

		$this->assertSame( 'bytes=8182-9999', $this->logged_requests( $log )[0][1] );
		$this->assertSame( static::fixture_content( 10000 ), file_get_contents( $file ) );
	}

	/**
	 * MWP-2183: a server that ignores Range answers 200 with the whole file;
	 * it is written once from byte 0 and the part loop stops.
	 *
	 * @dataProvider range_ignored_requests
	 * @param int $at Request that ignores Range.
	 * @return void
	 */
	public function test_range_ignored_writes_whole_file_once_and_stops( $at ) {
		$this->use_segment_bytes( 4096 );
		$log  = $this->range_log();
		$file = $this->temp_download_path();

		$this->segmented_download( '/bounded?size=10000&fault=ignore&at=' . $at . '&log=' . $log, $file, 10000, $calls );

		$this->assertSame( static::fixture_content( 10000 ), file_get_contents( $file ) );
		$requests = $this->logged_requests( $log );
		$this->assertCount( $at, $requests, 'No request may follow the 200.' );
		$this->assertSame( 'bytes=0-4095', $requests[0][1] );
		$this->assertSame( $at - 1, $calls );
	}

	/**
	 * Request numbers that ignore Range.
	 *
	 * @return array
	 */
	public static function range_ignored_requests() {
		return array(
			'first part' => array( 1 ),
			'later part' => array( 2 ),
		);
	}

	/**
	 * MWP-2183: a 416 on a later part restarts from byte 0 on a renewed URL;
	 * the Child consumed the previous URL's token before answering.
	 *
	 * @return void
	 */
	public function test_416_on_later_segment_restarts_on_renewed_url() {
		$this->use_segment_bytes( 4096 );
		$log  = $this->range_log();
		$file = $this->temp_download_path();

		$this->segmented_download( '/bounded?size=10000&fault=416&at=2&log=' . $log, $file, 10000, $calls );

		$this->assertSame( static::fixture_content( 10000 ), file_get_contents( $file ) );
		$this->assertSame(
			array( 'bytes=0-4095', 'bytes=4096-8191', 'bytes=0-4095', 'bytes=4096-8191', 'bytes=8192-9999' ),
			array_column( $this->logged_requests( $log ), 1 )
		);
		$this->assertSame( array( '1', '2', '3', '4', '5' ), array_column( $this->logged_requests( $log ), 0 ), 'Each request needs its own URL.' );
		$this->assertSame( 4, $calls );
	}

	/**
	 * MWP-2183: a 413 gets its own code and an explanation of response limits.
	 *
	 * @return void
	 */
	public function test_http_413_reports_download_too_large() {
		$file = $this->temp_download_path();

		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/bounded?size=10000&limit=100' ), $file, 10000 );
			$this->fail( 'The 413 was accepted.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_DOWNLOAD_TOO_LARGE, $error->get_message_error_code() );
			$this->assertSame( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_DOWNLOAD_TOO_LARGE ), $error->getMessage() );
			$this->assertSame( 'HTTP 413', $error->get_message_extra( false ) );
		}
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * Host-specific request headers must survive the generated Range header.
	 *
	 * @dataProvider range_header_download_cases
	 * @param bool $bounded Whether to renew URLs for bounded parts.
	 * @return void
	 */
	public function test_range_preserves_filtered_request_headers( $bounded ) {
		$this->use_segment_bytes( 4096 );
		$log    = $this->range_log();
		$file   = $this->temp_download_path();
		$filter = static function ( $options ) {
			$options[ CURLOPT_HTTPHEADER ] = array( 'X-MainWP-Probe: retained', 'rAnGe: bytes=99999-' );
			return $options;
		};
		add_filter( 'mainwp_download_to_file_curl_options', $filter );
		try {
			$route = '/bounded?size=10000&log=' . $log;
			if ( $bounded ) {
				$this->segmented_download( $route, $file, 10000 );
			} else {
				file_put_contents( $file, substr( static::fixture_content( 10000 ), 0, 1000 ) );
				MainWP_Connect::download_to_file( static::fixture_url( $route . '&m=1' ), $file, 10000 );
			}
		} finally {
			remove_filter( 'mainwp_download_to_file_curl_options', $filter );
		}
		$this->assertSame( static::fixture_content( 10000 ), file_get_contents( $file ) );
		$this->assertSame(
			$bounded ? array( 'bytes=0-4095', 'bytes=4096-8191', 'bytes=8192-9999' ) : array( 'bytes=1000-' ),
			array_column( $this->logged_requests( $log ), 1 )
		);
		$lines = file( sys_get_temp_dir() . '/' . $log, FILE_IGNORE_NEW_LINES );
		foreach ( $lines as $line ) {
			$this->assertSame( 'retained', explode( '|', $line )[2], 'A request lost the filtered header.' );
		}
	}

	/**
	 * Both generated Range paths share the header merge contract.
	 *
	 * @return array
	 */
	public static function range_header_download_cases() {
		return array(
			'bounded parts'     => array( true ),
			'open-ended resume' => array( false ),
		);
	}

	/**
	 * A successful part must not replenish the restart allowance.
	 *
	 * @return void
	 */
	public function test_repeated_416_stops_after_one_restart() {
		$this->use_segment_bytes( 4096 );
		$log  = $this->range_log();
		$file = $this->temp_download_path();
		try {
			$this->segmented_download( '/bounded?size=10000&fault=416&at=2,4&log=' . $log, $file, 10000, $calls );
			$this->fail( 'A second restart was allowed.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL, $error->get_message_error_code() );
		}
		$this->assertSame(
			array( 'bytes=0-4095', 'bytes=4096-8191', 'bytes=0-4095', 'bytes=4096-8191' ),
			array_column( $this->logged_requests( $log ), 1 )
		);
		$this->assertSame( 3, $calls );
	}

	/**
	 * Failed authorization must stop before another GET and retain validated bytes.
	 *
	 * @return void
	 */
	public function test_renewer_exception_keeps_prefix_and_stops_requests() {
		$this->use_segment_bytes( 4096 );
		$log     = $this->range_log();
		$file    = $this->temp_download_path();
		$failure = new MainWP_Exception( 'Renewal failed.', '', MainWP_Backup_Error_Codes::ERR_DL_TOKEN_INVALID );
		$calls   = 0;
		$renew   = static function () use ( $failure, &$calls ) {
			++$calls;
			throw $failure;
		};
		try {
			MainWP_Connect::download_to_file( static::fixture_url( '/bounded?size=10000&log=' . $log . '&m=1' ), $file, 10000, null, null, null, $renew );
			$this->fail( 'A failed renewal was ignored.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( $failure, $error );
		}
		$this->assertSame( 1, $calls );
		$this->assertSame( array( array( '1', 'bytes=0-4095' ) ), $this->logged_requests( $log ) );
		$this->assertSame( substr( static::fixture_content( 10000 ), 0, 4096 ), file_get_contents( $file ) );
	}

	/**
	 * Content-Range and measured growth suffice when Content-Length is absent.
	 *
	 * @return void
	 */
	public function test_bounded_download_without_content_length() {
		$this->use_segment_bytes( 4096 );
		$log  = $this->range_log();
		$file = $this->temp_download_path();
		$this->segmented_download( '/bounded?size=10000&fault=no-length&at=1,2,3&log=' . $log, $file, 10000, $calls );
		$this->assertSame( static::fixture_content( 10000 ), file_get_contents( $file ) );
		$this->assertCount( 3, $this->logged_requests( $log ) );
		$this->assertSame( 2, $calls );
	}
}
