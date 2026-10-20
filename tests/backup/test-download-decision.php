<?php
/**
 * MainWP Download Decision Tests
 *
 * Tests for the pure resume-decision logic used by backup file downloads:
 * Content-Range parsing and the append/restart/complete decision matrix.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class MainWP_Download_Decision_Test
 */
class MainWP_Download_Decision_Test extends \WP_UnitTestCase {

	/**
	 * Parses a satisfied Content-Range value.
	 *
	 * @return void
	 */
	public function test_parse_content_range_valid_range() {
		$parsed = MainWP_Connect::parse_content_range_header( 'bytes 1024-2047/4096' );
		$this->assertSame(
			array(
				'start' => 1024,
				'end'   => 2047,
				'total' => 4096,
			),
			$parsed
		);
	}

	/**
	 * Parses with the header name prefix included.
	 *
	 * @return void
	 */
	public function test_parse_content_range_with_header_prefix() {
		$parsed = MainWP_Connect::parse_content_range_header( 'Content-Range: bytes 0-99/100' );
		$this->assertSame(
			array(
				'start' => 0,
				'end'   => 99,
				'total' => 100,
			),
			$parsed
		);
	}

	/**
	 * Parses the unsatisfied-range form returned with 416 responses.
	 *
	 * @return void
	 */
	public function test_parse_content_range_unsatisfied_form() {
		$parsed = MainWP_Connect::parse_content_range_header( 'bytes */4096' );
		$this->assertSame( array( 'total' => 4096 ), $parsed );
	}

	/**
	 * Garbage input parses to false.
	 *
	 * @return void
	 */
	public function test_parse_content_range_garbage_returns_false() {
		$this->assertFalse( MainWP_Connect::parse_content_range_header( 'not a range' ) );
		$this->assertFalse( MainWP_Connect::parse_content_range_header( '' ) );
		$this->assertFalse( MainWP_Connect::parse_content_range_header( 'bytes 5-2/10x' ) );
	}

	/**
	 * Fresh download with 200 writes from scratch.
	 *
	 * @return void
	 */
	public function test_fresh_200_returns_write_mode() {
		$this->assertSame( 'w', MainWP_Connect::decide_download_mode( 200, 0, false, 0, 4096 ) );
	}

	/**
	 * Fresh download with 206 still writes from scratch.
	 *
	 * @return void
	 */
	public function test_fresh_206_returns_write_mode() {
		$range = array(
			'start' => 0,
			'end'   => 4095,
			'total' => 4096,
		);
		$this->assertSame( 'w', MainWP_Connect::decide_download_mode( 206, 0, $range, 0, 4096 ) );
	}

	/**
	 * Resume with 206 and matching Content-Range start appends.
	 *
	 * @return void
	 */
	public function test_resume_206_matching_start_returns_append_mode() {
		$range = array(
			'start' => 1024,
			'end'   => 4095,
			'total' => 4096,
		);
		$this->assertSame( 'a', MainWP_Connect::decide_download_mode( 206, 1024, $range, 1024, 4096 ) );
	}

	/**
	 * Resume with 206 but a mismatched Content-Range start fails hard.
	 *
	 * @return void
	 */
	public function test_resume_206_mismatched_start_throws() {
		$range = array(
			'start' => 0,
			'end'   => 4095,
			'total' => 4096,
		);
		try {
			MainWP_Connect::decide_download_mode( 206, 1024, $range, 1024, 4096 );
			$this->fail( 'Expected exception for mismatched Content-Range start.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_http_error', $e->get_message_error_code() );
		}
	}

	/**
	 * Resume with 206 but no Content-Range header fails hard.
	 *
	 * @return void
	 */
	public function test_resume_206_missing_content_range_throws() {
		try {
			MainWP_Connect::decide_download_mode( 206, 1024, false, 1024, 4096 );
			$this->fail( 'Expected exception for missing Content-Range header.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_http_error', $e->get_message_error_code() );
		}
	}

	/**
	 * Resume answered with 200: server ignored Range, restart from zero.
	 *
	 * @return void
	 */
	public function test_resume_200_restarts_from_zero() {
		$this->assertSame( 'w', MainWP_Connect::decide_download_mode( 200, 1024, false, 1024, 4096 ) );
	}

	/**
	 * Resume answered with 200 when the local file already matches the
	 * expected size: nothing left to download.
	 *
	 * @return void
	 */
	public function test_resume_200_local_complete_returns_already_complete() {
		$this->assertSame( 'already_complete', MainWP_Connect::decide_download_mode( 200, 4096, false, 4096, 4096 ) );
	}

	/**
	 * Resume answered with 416 and the local size equals the server total:
	 * the file is already fully downloaded.
	 *
	 * @return void
	 */
	public function test_resume_416_local_equals_total_returns_already_complete() {
		$range = array( 'total' => 4096 );
		$this->assertSame( 'already_complete', MainWP_Connect::decide_download_mode( 416, 4096, $range, 4096, 4096 ) );
	}

	/**
	 * Resume answered with 416 and a size mismatch restarts from zero.
	 *
	 * @return void
	 */
	public function test_resume_416_size_mismatch_returns_write_mode() {
		$range = array( 'total' => 4096 );
		$this->assertSame( 'w', MainWP_Connect::decide_download_mode( 416, 1024, $range, 1024, 4096 ) );
	}

	/**
	 * Resume answered with 416 whose total matches the local file but not the
	 * advertised size: the child holds a smaller artifact than promised, so the
	 * download restarts instead of being accepted as complete.
	 *
	 * @return void
	 */
	public function test_resume_416_total_below_expected_size_returns_write_mode() {
		$range = array( 'total' => 1024 );
		$this->assertSame( 'w', MainWP_Connect::decide_download_mode( 416, 1024, $range, 1024, 4096 ) );
	}

	/**
	 * Resume answered with 416 and no advertised size: the server total is the
	 * only authority, so a matching local file is complete.
	 *
	 * @return void
	 */
	public function test_resume_416_local_equals_total_without_expected_size_returns_already_complete() {
		$range = array( 'total' => 1024 );
		$this->assertSame( 'already_complete', MainWP_Connect::decide_download_mode( 416, 1024, $range, 1024, false ) );
	}

	/**
	 * Resume answered with 416 and no parsable Content-Range restarts.
	 *
	 * @return void
	 */
	public function test_resume_416_unparsable_returns_write_mode() {
		$this->assertSame( 'w', MainWP_Connect::decide_download_mode( 416, 1024, false, 1024, 4096 ) );
	}

	/**
	 * Server errors fail hard with the HTTP error code.
	 *
	 * @return void
	 */
	public function test_http_500_throws_http_error() {
		try {
			MainWP_Connect::decide_download_mode( 500, 0, false, 0, 4096 );
			$this->fail( 'Expected exception for HTTP 500.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_http_error', $e->get_message_error_code() );
		}
	}

	/**
	 * Client errors (404) fail hard with the HTTP error code.
	 *
	 * @return void
	 */
	public function test_http_404_throws_http_error() {
		try {
			MainWP_Connect::decide_download_mode( 404, 1024, false, 1024, 4096 );
			$this->fail( 'Expected exception for HTTP 404.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_http_error', $e->get_message_error_code() );
		}
	}
}
