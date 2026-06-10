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

use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Exception;

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct file IO in tests.

/**
 * Class MainWP_Download_To_File_Test
 */
class MainWP_Download_To_File_Test extends MainWP_Backup_Test_Case {

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
	 * A 416 whose reported total matches the local file size means the file
	 * is already complete; the partial-file content is kept untouched.
	 *
	 * @return void
	 */
	public function test_416_with_matching_total_treats_file_as_complete() {
		$file    = $this->temp_download_path();
		$content = static::fixture_content( 4096 );
		file_put_contents( $file, $content );

		// Expected size differs slightly (within 1% tolerance) so a resume is attempted.
		MainWP_Connect::download_to_file( static::fixture_url( '/206?size=4096' ), $file, 4100 );

		$this->assertSame( $content, file_get_contents( $file ) );
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
