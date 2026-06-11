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
	 *
	 * @return object Buffer holder with public $bytes.
	 */
	private function mock_receiver( $fail_calls = array() ) {
		$state = new \stdClass();

		$state->bytes  = '';
		$state->mtoken = 'tok0123456789abc';
		$call_no       = 0;

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $state, $fail_calls, &$call_no ) {
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
