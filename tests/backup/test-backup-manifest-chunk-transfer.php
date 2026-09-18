<?php
/**
 * Manifest chunk transfer tests: the send loop, gap realignment,
 * adaptive chunk shrink on transport failure, and token threading.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Manifest_Chunk_Transfer;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Manifest_Chunk_Transfer_Test
 */
class MainWP_Backup_Manifest_Chunk_Transfer_Test extends MainWP_Abilities_Test_Case {

	/**
	 * A gap response cannot confirm the final hash or issue the manifest token.
	 */
	public function test_realign_at_manifest_size_fails() {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () {
				return array(
					'bytes_received' => 100,
					'error_code'     => 'mainwp_restore_chunk_gap',
				);
			}
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 100 );
		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertStringContainsString( 'acknowledged bytes outside the transferred range', $outcome['error'] );
	}

	/**
	 * The final acknowledgement may equal the manifest size.
	 */
	public function test_ok_at_manifest_size_completes() {
		$calls = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () use ( &$calls ) {
				++$calls;
				return array(
					'bytes_received' => 100,
					'mtoken'         => 'complete-token',
				);
			}
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 100 );
		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( 'complete-token', $outcome['mtoken'] );
		$this->assertSame( 1, $calls );
	}

	/**
	 * An acknowledgement at the sent chunk boundary permits the next chunk.
	 */
	public function test_ok_at_sent_chunk_boundary_advances() {
		$offsets = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $pre, $website, $what, $params ) use ( &$offsets ) {
				$offsets[] = $params['offset'];
				return array(
					'bytes_received' => $params['offset'] + 50,
					'mtoken'         => 'complete-token',
				);
			},
			10,
			4
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 50 );
		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( array( 0, 50 ), $offsets );
		$this->assertSame( 'complete-token', $outcome['mtoken'] );
	}

	/**
	 * Child acknowledgements must stay within the permitted byte range.
	 */
	public function test_ok_beyond_manifest_size_fails() {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () {
				return array(
					'bytes_received' => 101,
					'mtoken'         => 'test-token',
				);
			}
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 100 );
		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertStringContainsString( 'acknowledged bytes outside the transferred range', $outcome['error'] );
	}

	/**
	 * Child acknowledgements must stay within the permitted byte range.
	 */
	public function test_ok_beyond_sent_chunk_fails() {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () {
				return array(
					'bytes_received' => 51,
					'mtoken'         => 'test-token',
				);
			}
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 50 );
		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertStringContainsString( 'acknowledged bytes outside the transferred range', $outcome['error'] );
	}

	/**
	 * Child acknowledgements must stay within the permitted byte range.
	 */
	public function test_negative_realign_fails() {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () {
				return array(
					'bytes_received' => -1,
					'error_code'     => 'mainwp_restore_chunk_gap',
				);
			}
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 50 );
		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertStringContainsString( 'acknowledged bytes outside the transferred range', $outcome['error'] );
	}

	/**
	 * A stuck receiver must terminate without exhausting a request worker.
	 */
	public function test_nonadvancing_realigns_fail_after_three_attempts() {
		$calls = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () use ( &$calls ) {
				++$calls;
				if ( $calls > 6 ) {
					return array( 'error' => 'Test receiver safety stop.' );
				}
				return array(
					'error_code'     => 'mainwp_restore_chunk_gap',
					'bytes_received' => 0,
				);
			}
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 50 );
		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertSame( 4, $calls );
	}

	/**
	 * Revisiting an earlier offset must not replenish the retry budget.
	 */
	public function test_oscillating_realigns_fail_without_progress() {
		$calls = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () use ( &$calls ) {
				++$calls;
				if ( $calls > 6 ) {
					return array( 'error' => 'Test receiver safety stop.' );
				}
				return array(
					'error_code'     => 'mainwp_restore_chunk_gap',
					'bytes_received' => $calls % 2 ? 1000 : 0,
				);
			}
		);
		$outcome = $this->push( str_repeat( 'x', 2000 ), 1000 );
		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertSame( 4, $calls );
	}

	/**
	 * Progress resets the retry allowance and the sender follows the new offset.
	 */
	public function test_advancing_realigns_reset_retry_count() {
		$offsets = array( 0, 0, 0, 10, 10, 10, 20 );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$offsets ) {
				$this->calls[] = $params;
				if ( $offsets ) {
					return array(
						'error_code'     => 'mainwp_restore_chunk_gap',
						'bytes_received' => array_shift( $offsets ),
					);
				}
				return array(
					'bytes_received' => 100,
					'mtoken'         => 'complete-token',
				);
			},
			10,
			4
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 100 );
		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( array( 0, 0, 0, 0, 10, 10, 10, 20 ), array_column( $this->calls, 'offset' ) );
		$this->assertSame( 'complete-token', $outcome['mtoken'] );
	}

	/**
	 * Losing the child buffer once must allow the transfer to resume at zero.
	 */
	public function test_initial_zero_realign_allows_subsequent_progress() {
		$calls = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () use ( &$calls ) {
				if ( 1 === ++$calls ) {
					return array(
						'error_code'     => 'mainwp_restore_chunk_gap',
						'bytes_received' => 0,
					);
				}
				return array(
					'bytes_received' => 100,
					'mtoken'         => 'complete-token',
				);
			}
		);
		$outcome = $this->push( str_repeat( 'x', 100 ), 100 );
		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( 'complete-token', $outcome['mtoken'] );
		$this->assertSame( 2, $calls );
	}

	/**
	 * Captured chunk calls.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Manifest fixture path.
	 *
	 * @var string
	 */
	private $manifest;

	/**
	 * Build the manifest fixture.
	 */
	public function set_up() {
		parent::set_up();
		$this->calls    = array();
		$this->manifest = tempnam( sys_get_temp_dir(), 'mainwp-manifest-' );
	}

	/**
	 * Remove the fixture.
	 */
	public function tear_down() {
		if ( file_exists( $this->manifest ) ) {
			unlink( $this->manifest );
		}
		parent::tear_down();
	}

	/**
	 * Mock the child receiver: applies the real append semantics over an
	 * in-memory buffer, optionally failing scripted call numbers.
	 *
	 * @param array $fail_calls Call numbers (1-based) that throw transport errors.
	 * @param array $lost_responses Call numbers that append successfully before losing their response.
	 *
	 * @return object Buffer holder with public $bytes.
	 */
	private function mock_receiver( $fail_calls = array(), $lost_responses = array() ) {
		$state = new \stdClass();

		$state->bytes  = '';
		$state->ready  = false;
		$state->mtoken = 'tok0123456789abc';
		$call_no       = 0;

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $state, $fail_calls, $lost_responses, &$call_no ) {
				unset( $website );
				if ( 'backup_manifest_chunk' !== $what ) {
					return $result;
				}
				++$call_no;
				$this->calls[] = $params;

				if ( in_array( $call_no, $fail_calls, true ) ) {
					throw new \MainWP\Dashboard\MainWP_Exception( 'cURL error 52: empty reply' );
				}

				$raw    = base64_decode( $params['chunk_b64'] );
				$offset = (int) $params['offset'];
				$size   = strlen( $state->bytes );

				if ( $offset > $size ) {
					return array(
						'error'          => 'gap',
						'error_code'     => 'mainwp_restore_chunk_gap',
						'bytes_received' => $size,
					);
				}
				if ( $offset < $size ) {
					return array(
						'result'         => 'ok',
						'mtoken'         => $state->mtoken,
						'bytes_received' => $size,
					);
				}
				$state->bytes .= $raw;
				if ( ! empty( $params['final'] ) ) {
					$state->ready = hash_equals( $params['total_sha256'], hash( 'sha256', $state->bytes ) );
					if ( ! $state->ready ) {
						return array( 'error' => 'Manifest hash mismatch.' );
					}
				}
				if ( in_array( $call_no, $lost_responses, true ) ) {
					throw new \MainWP\Dashboard\MainWP_Exception( 'cURL error 52: response lost after append' );
				}
				return array(
					'result'         => 'ok',
					'mtoken'         => $state->mtoken,
					'bytes_received' => strlen( $state->bytes ),
				);
			},
			10,
			4
		);

		return $state;
	}

	/**
	 * Run a push against a test site.
	 *
	 * @param string $content     Manifest bytes.
	 * @param int    $chunk_bytes Starting chunk size.
	 *
	 * @return array Push outcome.
	 */
	private function push( $content, $chunk_bytes ) {
		file_put_contents( $this->manifest, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$website = MainWP_DB::instance()->get_website_by_id( $this->create_test_site() );
		return MainWP_Backup_Manifest_Chunk_Transfer::instance()->push( $website, $this->manifest, hash( 'sha256', $content ), $chunk_bytes );
	}

	/**
	 * A manifest under the chunk size completes in one final call.
	 */
	public function test_single_chunk_manifest_completes_in_one_call() {
		$state   = $this->mock_receiver();
		$content = str_repeat( 'm', 100 );

		$outcome = $this->push( $content, 1024 );

		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( 'tok0123456789abc', $outcome['mtoken'] );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 1, (int) $this->calls[0]['final'] );
		$this->assertSame( '', $this->calls[0]['mtoken'], 'The first chunk opens the session with no token.' );
		$this->assertSame( $content, $state->bytes );
	}

	/**
	 * A multi-chunk manifest sends every byte in order, threading the
	 * token from the first ack and marking only the last chunk final.
	 */
	public function test_multi_chunk_manifest_sends_all_bytes() {
		$state   = $this->mock_receiver();
		$content = str_repeat( 'x', 250 );

		$outcome = $this->push( $content, 100 );

		$this->assertTrue( $outcome['ok'] );
		$this->assertCount( 3, $this->calls );
		$this->assertSame( array( 0, 100, 200 ), array_map( 'intval', array_column( $this->calls, 'offset' ) ) );
		$this->assertSame( 'tok0123456789abc', $this->calls[1]['mtoken'] );
		$this->assertSame( array( 0, 0, 1 ), array_map( 'intval', array_column( $this->calls, 'final' ) ) );
		$this->assertSame( $content, $state->bytes );
		$this->assertSame( hash( 'sha256', $content ), $this->calls[2]['total_sha256'] );
	}

	/**
	 * A transport failure shrinks the chunk size and the transfer
	 * resumes from the receiver's confirmed bytes - every byte still
	 * arrives exactly once.
	 */
	public function test_transport_failure_shrinks_and_resumes() {
		$state   = $this->mock_receiver( array( 1 ) );
		$content = str_repeat( 'y', 600000 );

		$outcome = $this->push( $content, 2 * MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES );

		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( $content, $state->bytes );

		$post_failure = $this->calls[1];
		$this->assertSame( MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES, strlen( base64_decode( $post_failure['chunk_b64'] ) ), 'The chunk size halves after a transport failure.' );
	}

	/**
	 * A known session may have appended the larger chunk before its reply was lost.
	 */
	public function test_lost_append_response_accepts_larger_duplicate_ack() {
		$chunk   = 2 * MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES;
		$content = str_repeat( 'a', 3 * $chunk );
		$state   = $this->mock_receiver( array(), array( 2 ) );

		$outcome = $this->push( $content, $chunk );

		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( $state->mtoken, $outcome['mtoken'] );
		$this->assertSame( $content, $state->bytes );
		$this->assertTrue( $state->ready );
		$this->assertCount( 5, $this->calls );
		$this->assertSame( $state->mtoken, $this->calls[1]['mtoken'] );
		$this->assertSame( $chunk, $this->calls[1]['offset'] );
		$this->assertSame( $chunk, $this->calls[2]['offset'] );
		$this->assertSame( $chunk, strlen( base64_decode( $this->calls[1]['chunk_b64'] ) ) );
		$this->assertSame( MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES, strlen( base64_decode( $this->calls[2]['chunk_b64'] ) ) );
		$this->assertSame( 2 * $chunk, $this->calls[3]['offset'] );
	}

	/**
	 * The child verifies the final hash before losing the reply; its duplicate ack carries the token.
	 */
	public function test_lost_final_response_accepts_duplicate_completion() {
		$chunk   = 2 * MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES;
		$content = str_repeat( 'f', 2 * $chunk );
		$state   = $this->mock_receiver( array(), array( 2 ) );

		$outcome = $this->push( $content, $chunk );

		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( $state->mtoken, $outcome['mtoken'] );
		$this->assertSame( $content, $state->bytes );
		$this->assertTrue( $state->ready );
		$this->assertCount( 3, $this->calls );
		$this->assertSame( 1, $this->calls[1]['final'] );
		$this->assertSame( hash( 'sha256', $content ), $this->calls[1]['total_sha256'] );
		$this->assertSame( 0, $this->calls[2]['final'] );
		$this->assertSame( $chunk, $this->calls[2]['offset'] );
		$this->assertSame( MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES, strlen( base64_decode( $this->calls[2]['chunk_b64'] ) ) );
	}

	/**
	 * A gap response is not the child's duplicate acknowledgement and cannot confirm completion.
	 */
	public function test_realign_at_size_after_lost_final_response_is_rejected() {
		$chunk   = 2 * MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES;
		$content = str_repeat( 'r', 2 * $chunk );
		$state   = $this->mock_receiver( array(), array( 2 ) );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $response ) use ( $state ) {
				if ( 3 === count( $this->calls ) ) {
					return array(
						'error_code'     => 'mainwp_restore_chunk_gap',
						'bytes_received' => strlen( $state->bytes ),
					);
				}
				return $response;
			},
			11
		);

		$outcome = $this->push( $content, $chunk );

		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertStringContainsString( 'acknowledged bytes outside the transferred range', $outcome['error'] );
		$this->assertSame( '', $outcome['mtoken'] );
		$this->assertTrue( $state->ready );
		$this->assertCount( 3, $this->calls );
	}

	/**
	 * A successful duplicate acknowledgement must not relax bounds at the next offset.
	 */
	public function test_lost_response_exception_is_limited_to_retry_offset() {
		$chunk   = 2 * MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES;
		$content = str_repeat( 'b', 3 * $chunk );
		$this->mock_receiver( array(), array( 2 ) );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $response ) use ( $content ) {
				if ( 4 === count( $this->calls ) ) {
					$response['bytes_received'] = strlen( $content );
				}
				return $response;
			},
			11
		);

		$outcome = $this->push( $content, $chunk );

		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertStringContainsString( 'acknowledged bytes outside the transferred range', $outcome['error'] );
		$this->assertCount( 4, $this->calls );
	}

	/**
	 * Persistent transport failure at the smallest chunk size gives up
	 * with the send-failed code so the caller falls back to a full run.
	 */
	public function test_shrink_floor_reached_reports_send_failed() {
		$this->mock_receiver( array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ) );
		$content = str_repeat( 'z', 100 );

		$outcome = MainWP_Backup_Manifest_Chunk_Transfer::instance()->push(
			$this->website_for( $content ),
			$this->manifest,
			hash( 'sha256', $content ),
			MainWP_Backup_Manifest_Chunk_Transfer::CHUNK_MIN_BYTES
		);

		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
	}

	/**
	 * A gap response realigns the cursor to the receiver's confirmed
	 * bytes instead of failing the transfer.
	 */
	public function test_gap_response_realigns_cursor() {
		$state = $this->mock_receiver();
		// Pre-seed the receiver as if 40 bytes already landed from an
		// interrupted earlier attempt under the same session.
		$content      = str_repeat( 'g', 120 );
		$state->bytes = substr( $content, 0, 40 );

		$outcome = $this->push( $content, 1024 );

		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( $content, $state->bytes, 'The realigned transfer completes the file without duplication.' );
	}

	/**
	 * An ok answer that confirms no new bytes fails the transfer instead
	 * of resending the same chunk forever.
	 */
	public function test_ok_ack_with_no_new_bytes_fails_send() {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) {
				unset( $website );
				if ( 'backup_manifest_chunk' !== $what ) {
					return $result;
				}
				$this->calls[] = $params;
				return array(
					'result'         => 'ok',
					'mtoken'         => 'tok0123456789abc',
					'bytes_received' => 0,
				);
			},
			10,
			4
		);

		$outcome = $this->push( str_repeat( 'n', 100 ), 1024 );

		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED, $outcome['error_code'] );
		$this->assertCount( 1, $this->calls, 'The transfer gives up on the first no-progress ack instead of looping.' );
	}

	/**
	 * Write the fixture and build a website row.
	 *
	 * @param string $content Manifest bytes.
	 *
	 * @return object Website.
	 */
	private function website_for( $content ) {
		file_put_contents( $this->manifest, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		return MainWP_DB::instance()->get_website_by_id( $this->create_test_site() );
	}
}
