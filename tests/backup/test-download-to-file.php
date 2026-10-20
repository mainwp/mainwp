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
}
