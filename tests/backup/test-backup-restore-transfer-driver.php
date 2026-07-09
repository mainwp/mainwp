<?php
/**
 * Restore transfer driver tests: staging from local and remote
 * sources, chunked transfer with realignment and adaptive sizing.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Storage_Local;

/**
 * Driver with the remote destination pinned to a local-provider stub.
 */
class Testable_Restore_Transfer_Driver extends MainWP_Backup_Restore_Transfer_Driver {

	/**
	 * Base path the stub provider serves objects from.
	 *
	 * @var string
	 */
	public $remote_base = '';

	/**
	 * Scripted chunk responses (consumed in order); null falls through
	 * to the real sender.
	 *
	 * @var array|null
	 */
	public $chunk_script = null;

	/**
	 * Sent chunks for assertions.
	 *
	 * @var array
	 */
	public $sent = array();

	/**
	 * Recorded floor-retry pauses, so tests stay instant.
	 *
	 * @var array
	 */
	public $sleeps = array();

	/**
	 * Scripted free-space answer; null keeps the guard's default
	 * "host does not support disk_free_space()" skip behavior.
	 *
	 * @var int|null
	 */
	public $fake_free_bytes = null;

	/**
	 * Record the pause instead of sleeping.
	 *
	 * @param int $seconds Sleep duration.
	 */
	protected function floor_retry_sleep( int $seconds ): void {
		$this->sleeps[] = $seconds;
	}

	/**
	 * Scripted free-space seam.
	 *
	 * @param string $dir Directory (ignored).
	 *
	 * @return int|null Scripted free bytes.
	 */
	protected function free_space_bytes( string $dir ): ?int {
		unset( $dir );
		return $this->fake_free_bytes;
	}

	/**
	 * Serve remote objects from the pinned base path via the real local
	 * provider (bypasses destination row decryption).
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return \MainWP\Dashboard\MainWP_Backup_Storage_Provider|null Provider.
	 */
	protected function provider_for_snapshot( int $snapshot_id ): ?\MainWP\Dashboard\MainWP_Backup_Storage_Provider {
		if ( '' === $this->remote_base ) {
			return null;
		}
		return new MainWP_Backup_Storage_Local( array( 'base_path' => $this->remote_base ) );
	}

	/**
	 * Scripted chunk sender.
	 *
	 * @param object $website Website.
	 * @param string $task_id Task id.
	 * @param array  $entry   Plan entry.
	 * @param int    $offset  Offset.
	 * @param string $raw     Bytes.
	 * @param bool   $last    Final flag.
	 *
	 * @return array Outcome.
	 */
	protected function send_chunk( object $website, string $task_id, array $entry, int $offset, string $raw, bool $last ): array {
		$this->sent[] = array(
			'file'   => $entry['file'],
			'offset' => $offset,
			'bytes'  => strlen( $raw ),
			'last'   => $last,
		);
		if ( null !== $this->chunk_script && ! empty( $this->chunk_script ) ) {
			return array_shift( $this->chunk_script );
		}
		return array(
			'result'         => 'ok',
			'bytes_received' => $offset + strlen( $raw ),
			'error'          => null,
			'retryable'      => false,
		);
	}
}

/**
 * Class MainWP_Backup_Restore_Transfer_Driver_Test
 */
class MainWP_Backup_Restore_Transfer_Driver_Test extends \WP_UnitTestCase {

	/**
	 * Scratch dir.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Driver under test.
	 *
	 * @var Testable_Restore_Transfer_Driver
	 */
	private $driver;

	/**
	 * Build scratch dirs.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-transfer-test-' . uniqid();
		mkdir( $this->dir );
		$this->driver = new Testable_Restore_Transfer_Driver();
	}

	/**
	 * Cleanup.
	 */
	public function tear_down() {
		$this->driver->cleanup_staging( 777001 );
		$this->rrmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Recursive cleanup.
	 *
	 * @param string $dir Directory.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * A local-source artifact stages in place with its hash computed.
	 */
	public function test_stage_local_artifact_hashed_in_place() {
		$path = $this->dir . '/full.zip';
		file_put_contents( $path, 'local-artifact' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'full',
				'file'        => 'full.zip',
				'size'        => 14,
				'sha256'      => '',
				'snapshot_id' => 1,
				'source'      => 'local',
				'local_path'  => $path,
				'remote_key'  => '',
				'adopt_only'  => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( $path, $plan[0]['staged_path'] );
		$this->assertSame( hash( 'sha256', 'local-artifact' ), $plan[0]['sha256'] );
	}

	/**
	 * A remote-source artifact downloads into staging and hashes.
	 */
	public function test_stage_remote_artifact_downloads() {
		$remote = $this->dir . '/remote';
		mkdir( $remote . '/4/8/files', 0777, true );
		file_put_contents( $remote . '/4/8/files/db.tar.gz', 'remote-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$this->driver->remote_base = $remote;

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'db.tar.gz',
				'size'        => 12,
				'sha256'      => '',
				'snapshot_id' => 8,
				'source'      => 'remote',
				'local_path'  => '',
				'remote_key'  => '4/8/files/db.tar.gz',
				'adopt_only'  => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertFileExists( $plan[0]['staged_path'] );
		$this->assertSame( hash( 'sha256', 'remote-bytes' ), $plan[0]['sha256'] );
	}

	/**
	 * A downloaded whole-file remote artifact whose bytes do not match the
	 * recorded backup-time hash fails staging closed instead of restoring
	 * unverified bytes; the staged file is removed.
	 */
	public function test_stage_remote_artifact_integrity_mismatch_fails() {
		$remote = $this->dir . '/remote';
		mkdir( $remote . '/4/8/files', 0777, true );
		file_put_contents( $remote . '/4/8/files/db.tar.gz', 'tampered-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$this->driver->remote_base = $remote;

		$plan = array(
			array(
				'role'            => 'db',
				'file'            => 'db.tar.gz',
				'size'            => 14,
				'sha256'          => '',
				'expected_sha256' => hash( 'sha256', 'original-bytes' ),
				'snapshot_id'     => 8,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/8/files/db.tar.gz',
				'adopt_only'      => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertStringContainsString( 'integrity', $outcome['error'] );
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 777001 ) . 'db.tar.gz' );
	}

	/**
	 * A downloaded whole-file remote artifact that matches the recorded
	 * backup-time hash stages normally.
	 */
	public function test_stage_remote_artifact_integrity_match_passes() {
		$remote = $this->dir . '/remote';
		mkdir( $remote . '/4/8/files', 0777, true );
		file_put_contents( $remote . '/4/8/files/db.tar.gz', 'remote-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$this->driver->remote_base = $remote;

		$plan = array(
			array(
				'role'            => 'db',
				'file'            => 'db.tar.gz',
				'size'            => 12,
				'sha256'          => '',
				'expected_sha256' => hash( 'sha256', 'remote-bytes' ),
				'snapshot_id'     => 8,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/8/files/db.tar.gz',
				'adopt_only'      => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertFileExists( $plan[0]['staged_path'] );
		$this->assertSame( hash( 'sha256', 'remote-bytes' ), $plan[0]['sha256'] );
	}

	/**
	 * A whole-file remote download refuses before touching the network
	 * when the dashboard does not have enough free disk space to stage
	 * it - no partial ".downloading" file is left behind.
	 */
	public function test_stage_remote_artifact_refuses_when_disk_full() {
		$remote = $this->dir . '/remote';
		mkdir( $remote . '/4/8/files', 0777, true );
		file_put_contents( $remote . '/4/8/files/db.tar.gz', 'remote-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$this->driver->remote_base    = $remote;
		$this->driver->fake_free_bytes = 5; // Less than the 12-byte entry size.

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'db.tar.gz',
				'size'        => 12,
				'sha256'      => '',
				'snapshot_id' => 8,
				'source'      => 'remote',
				'local_path'  => '',
				'remote_key'  => '4/8/files/db.tar.gz',
				'adopt_only'  => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_DISK_FULL, $outcome['error_code'] );
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 777001 ) . 'db.tar.gz' );
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 777001 ) . 'db.tar.gz.downloading' );
	}

	/**
	 * Build a chunked remote layout: manifest plus content-addressed
	 * pieces under {site 0}/{snapshot 44}/.
	 *
	 * @param string $content Artifact content split into two chunks.
	 * @param bool   $corrupt Whether to corrupt the second chunk's bytes.
	 * @param string $algo    Chunk content-address algorithm.
	 *
	 * @return array Plan entry for the chunked artifact.
	 */
	private function make_chunked_remote( $content, $corrupt = false, $algo = 'sha256' ) {
		$remote = $this->dir . '/remote';
		$half   = (int) ( strlen( $content ) / 2 );
		$parts  = array( substr( $content, 0, $half ), substr( $content, $half ) );

		mkdir( $remote . '/0/44/chunks', 0777, true );

		$chunks = array();
		$offset = 0;
		foreach ( $parts as $index => $piece ) {
			$hash     = hash( $algo, $piece );
			$chunks[] = array(
				'hash'   => $hash,
				'algo'   => $algo,
				'offset' => $offset,
				'length' => strlen( $piece ),
			);
			$bytes    = ( $corrupt && 1 === $index ) ? str_repeat( 'X', strlen( $piece ) ) : $piece;
			file_put_contents( $remote . '/0/44/chunks/' . $hash . '.bin', $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$offset += strlen( $piece );
		}

		$manifest = array(
			'format'    => 1,
			'artifacts' => array(
				array(
					'file'   => 'big.zip',
					'size'   => strlen( $content ),
					'chunks' => $chunks,
				),
			),
		);
		file_put_contents( $remote . '/0/44/manifest.json', wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$this->driver->remote_base = $remote;

		return array(
			'role'        => 'full',
			'file'        => 'big.zip',
			'size'        => strlen( $content ),
			'sha256'      => '',
			'snapshot_id' => 44,
			'source'      => 'remote_chunked',
			'local_path'  => '',
			'remote_key'  => '',
			'adopt_only'  => false,
		);
	}

	/**
	 * A chunked-remote artifact reassembles from its pieces, resumes
	 * across budget yields, and hashes the verified result.
	 */
	public function test_stage_remote_chunked_reassembles_with_resume() {
		$content = str_repeat( 'chunky-data-', 50 );
		$plan    = array( $this->make_chunked_remote( $content ) );

		// An expired budget yields after the manifest fetch.
		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) - 1 );
		$this->assertFalse( $outcome['done'] );
		$this->assertNull( $outcome['error'] );
		$this->assertNotEmpty( $plan[0]['chunks'] );

		// The next tick finishes the reassembly.
		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( $content, file_get_contents( $plan[0]['staged_path'] ) );
		$this->assertSame( hash( 'sha256', $content ), $plan[0]['sha256'] );
	}

	/**
	 * A fresh chunked reassembly (no bytes on disk yet) refuses before
	 * downloading any chunk when free space is less than the artifact's
	 * remaining size - no assembly file is left behind.
	 */
	public function test_stage_remote_chunked_refuses_when_disk_full_at_start() {
		$content = str_repeat( 'chunky-data-', 50 );
		$plan    = array( $this->make_chunked_remote( $content ) );
		$this->driver->fake_free_bytes = 5; // Less than strlen( $content ).

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_DISK_FULL, $outcome['error_code'] );
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 777001 ) . 'big.zip.assembling' );
	}

	/**
	 * Gotcha #14: the disk-full guard gates on actual on-disk reassembly
	 * bytes, not on the presence of the 'reassembly' cursor key. Seeded the
	 * way production seeds a resumed slice - a real partial ".assembling"
	 * file on disk matching the cursor - a low free-space reading on the
	 * resuming tick must not re-block it; only a fresh start (0 bytes on
	 * disk) is guarded.
	 */
	public function test_stage_remote_chunked_disk_full_guard_skips_on_resume() {
		$content = str_repeat( 'chunky-data-', 50 );
		$plan    = array( $this->make_chunked_remote( $content ) );

		$manifest    = json_decode( file_get_contents( $this->dir . '/remote/0/44/manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$half        = (int) ( strlen( $content ) / 2 );
		$first_piece = substr( $content, 0, $half );

		// Seed the state a real first-slice yield would have left: the
		// manifest already resolved and the first chunk already appended.
		$plan[0]['chunks']      = $manifest['artifacts'][0]['chunks'];
		$plan[0]['total_size']  = strlen( $content );
		$plan[0]['chunk_prefix'] = '0/44/';
		$plan[0]['reassembly']  = array(
			'next'  => 1,
			'bytes' => strlen( $first_piece ),
		);

		$staging = $this->driver->staging_dir( 777001 );
		wp_mkdir_p( $staging );
		file_put_contents( $staging . 'big.zip.assembling', $first_piece ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		// Far below the remaining need if the guard mistakenly fired.
		$this->driver->fake_free_bytes = 1;

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'], 'A resumed reassembly must not be re-blocked by the disk-full guard.' );
		$this->assertSame( $content, file_get_contents( $plan[0]['staged_path'] ) );
	}

	/**
	 * Chunk pieces verify with the algorithm the upload recorded, not a
	 * hardcoded one: the production chunker content-addresses with
	 * xxh128 where available, and reassembly must honor that.
	 */
	public function test_stage_remote_chunked_verifies_with_recorded_algo() {
		$algo = \MainWP\Dashboard\MainWP_Backup_Chunker::hash_algo();
		if ( 'sha256' === $algo ) {
			$this->markTestSkipped( 'No non-sha256 chunk algorithm available on this PHP.' );
		}

		$content = str_repeat( 'chunky-data-', 50 );
		$plan    = array( $this->make_chunked_remote( $content, false, $algo ) );

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );

		$this->assertNull( $outcome['error'] );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( $content, file_get_contents( $plan[0]['staged_path'] ) );
	}

	/**
	 * A corrupted chunk fails the reassembly closed.
	 */
	public function test_stage_remote_chunked_corrupt_chunk_fails() {
		$content = str_repeat( 'chunky-data-', 50 );
		$plan    = array( $this->make_chunked_remote( $content, true ) );

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertStringContainsString( 'integrity', $outcome['error'] );
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 777001 ) . 'big.zip' );
	}

	/**
	 * Transfer chunks an artifact in order and flags the final chunk.
	 */
	public function test_transfer_chunks_in_order() {
		$staged = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'A', 700 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'payload.zip',
				'size'        => 700,
				'sha256'      => hash_file( 'sha256', $staged ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);

		$cursor = array( 'chunk_bytes' => 300 );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 3, $this->driver->sent );
		$this->assertSame( array( 0, 300, 600 ), array_column( $this->driver->sent, 'offset' ) );
		$this->assertTrue( end( $this->driver->sent )['last'] );
	}

	/**
	 * A gap response realigns the offset to what the child holds.
	 */
	public function test_gap_response_realigns_offset() {
		$staged = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'B', 400 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'payload.zip',
				'size'        => 400,
				'sha256'      => hash_file( 'sha256', $staged ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);

		// First send comes back as a gap pointing at byte 100.
		$this->driver->chunk_script = array(
			array(
				'result'         => 'realign',
				'bytes_received' => 100,
				'error'          => null,
				'retryable'      => true,
			),
		);

		$cursor = array(
			'offset'      => 300,
			'chunk_bytes' => 500,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		// After the realign, the resend starts at 100.
		$this->assertSame( 100, $this->driver->sent[1]['offset'] );
	}

	/**
	 * A shrink outcome halves the chunk size and retries; at the floor
	 * it surfaces as retryable.
	 */
	public function test_shrink_halves_chunk_size() {
		$staged = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'C', 1000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'payload.zip',
				'size'        => 1000,
				'sha256'      => hash_file( 'sha256', $staged ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'too big',
				'retryable'      => true,
			),
		);

		$cursor = array( 'chunk_bytes' => 2097152 );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 1048576, $cursor['chunk_bytes'] );
		$this->assertSame( 2097152, $cursor['ceiling_bytes'], 'The failed size must be remembered as the ceiling.' );
		$this->assertTrue( $cursor['ceiling_soft'], 'An unclassified transport error must leave the ceiling soft.' );
		$this->assertSame( 1048576, $cursor['good_bytes'], 'The recovering send must record the size that worked.' );
	}

	/**
	 * An ok ack that confirms no new bytes fails the transfer terminally
	 * instead of resending the same chunk forever.
	 */
	public function test_ok_ack_with_no_new_bytes_fails_terminal() {
		$staged = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'D', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'payload.zip',
				'size'        => 100,
				'sha256'      => hash_file( 'sha256', $staged ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);

		$this->driver->chunk_script = array(
			array(
				'result'         => 'ok',
				'bytes_received' => 0,
				'error'          => null,
				'retryable'      => false,
			),
		);

		$cursor = array();
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertFalse( $result['done'] );
		$this->assertNotNull( $result['error'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertCount( 1, $this->driver->sent, 'The transfer gives up on the first no-progress ack instead of looping.' );
	}

	/**
	 * The mainwp_backup_restore_chunk_size filter sets the starting chunk
	 * size for a fresh cursor: the first chunk crosses the wire at the
	 * filtered size and the cursor carries it forward.
	 */
	public function test_chunk_size_filter_applies_to_fresh_cursor() {
		// A 1 MiB override is within [CHUNK_MIN_BYTES, CHUNK_MAX_BYTES] and
		// below the 3 MiB default, so it is observable as the first chunk.
		$one_mib = 1024 * 1024;
		$staged  = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'A', $one_mib + ( $one_mib / 2 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'payload.zip',
				'size'        => $one_mib + ( $one_mib / 2 ),
				'sha256'      => hash_file( 'sha256', $staged ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);

		$filter = static function () use ( $one_mib ) {
			return $one_mib;
		};
		add_filter( 'mainwp_backup_restore_chunk_size', $filter );
		$cursor = array();
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );
		remove_filter( 'mainwp_backup_restore_chunk_size', $filter );

		$this->assertTrue( $result['done'] );
		$this->assertSame( $one_mib, $cursor['chunk_bytes'] );
		$this->assertSame( $one_mib, $this->driver->sent[0]['bytes'] );
	}

	/**
	 * A filter value below the floor clamps up to CHUNK_MIN_BYTES so the
	 * loop cannot stall on a too-small chunk.
	 */
	public function test_chunk_size_filter_clamps_to_floor() {
		$staged = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'A', 64 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'payload.zip',
				'size'        => 64,
				'sha256'      => hash_file( 'sha256', $staged ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);

		$filter = static function () {
			return 1;
		};
		add_filter( 'mainwp_backup_restore_chunk_size', $filter );
		$cursor = array();
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );
		remove_filter( 'mainwp_backup_restore_chunk_size', $filter );

		$this->assertTrue( $result['done'] );
		$this->assertSame( MainWP_Backup_Restore_Transfer_Driver::CHUNK_MIN_BYTES, $cursor['chunk_bytes'] );
	}

	/**
	 * A filter value above the ceiling clamps down to CHUNK_MAX_BYTES so a
	 * stray value cannot exhaust memory or overrun the child request body.
	 */
	public function test_chunk_size_filter_clamps_to_ceiling() {
		$staged = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'A', 64 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'payload.zip',
				'size'        => 64,
				'sha256'      => hash_file( 'sha256', $staged ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);

		$filter = static function () {
			return 1024 * 1024 * 1024;
		};
		add_filter( 'mainwp_backup_restore_chunk_size', $filter );
		$cursor = array();
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );
		remove_filter( 'mainwp_backup_restore_chunk_size', $filter );

		$this->assertTrue( $result['done'] );
		$this->assertSame( MainWP_Backup_Restore_Transfer_Driver::CHUNK_MAX_BYTES, $cursor['chunk_bytes'] );
	}

	/**
	 * Build a staged single-artifact plan of the given size.
	 *
	 * @param int $bytes Artifact size.
	 *
	 * @return array Plan.
	 */
	private function staged_plan( $bytes ) {
		$staged = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'E', $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		return array(
			array(
				'role'        => 'db',
				'file'        => 'payload.zip',
				'size'        => $bytes,
				'sha256'      => hash_file( 'sha256', $staged ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);
	}

	/**
	 * At the chunk-size floor, a transport failure pauses and resends the
	 * same chunk instead of failing the job; the third consecutive floor
	 * failure surfaces as retryable.
	 */
	public function test_min_size_shrink_retries_in_slice_before_failing() {
		$plan  = $this->staged_plan( 100 );
		$fault = array(
			'result'         => 'shrink',
			'bytes_received' => 0,
			'error'          => 'connection dropped',
			'retryable'      => true,
		);

		$this->driver->chunk_script = array( $fault, $fault, $fault );

		$cursor = array( 'chunk_bytes' => MainWP_Backup_Restore_Transfer_Driver::CHUNK_MIN_BYTES );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertFalse( $result['done'] );
		$this->assertSame( 'connection dropped', $result['error'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertCount( 3, $this->driver->sent, 'The failing chunk must be retried twice before the failure surfaces.' );
		$this->assertSame( array( 0, 0, 0 ), array_column( $this->driver->sent, 'offset' ), 'Every retry must resend the same offset.' );
		$this->assertCount( 2, $this->driver->sleeps, 'Each in-slice retry must pause first.' );
		$this->assertSame( 0, $cursor['floor_fails'], 'The surfaced failure must reset the floor streak for the next attempt.' );
		$this->assertSame( 0, $cursor['ceiling_bytes'], 'A floor-level blip must not poison the ceiling to the floor value.' );
	}

	/**
	 * A floor failure followed by a clean send recovers in-slice: no error
	 * ever surfaces and the streak clears.
	 */
	public function test_min_size_retry_recovers_without_job_failure() {
		$plan  = $this->staged_plan( 100 );
		$fault = array(
			'result'         => 'shrink',
			'bytes_received' => 0,
			'error'          => 'connection dropped',
			'retryable'      => true,
		);

		// Two faults, then the script empties and the default ok applies.
		$this->driver->chunk_script = array( $fault, $fault );

		$cursor = array( 'chunk_bytes' => MainWP_Backup_Restore_Transfer_Driver::CHUNK_MIN_BYTES );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertNull( $result['error'] );
		$this->assertCount( 3, $this->driver->sent );
		$this->assertCount( 2, $this->driver->sleeps );
		$this->assertSame( 0, $cursor['floor_fails'] );
	}

	/**
	 * A floor failure with no slice budget left for the pause ends the
	 * slice benignly - no attempt burned - and keeps the streak so the
	 * next slice continues counting.
	 */
	public function test_min_size_shrink_without_budget_ends_slice() {
		$plan = $this->staged_plan( 100 );

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'connection dropped',
				'retryable'      => true,
			),
		);

		$cursor = array( 'chunk_bytes' => MainWP_Backup_Restore_Transfer_Driver::CHUNK_MIN_BYTES );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 2 );

		$this->assertFalse( $result['done'] );
		$this->assertNull( $result['error'], 'A budgetless floor retry must end the slice, not surface a failure.' );
		$this->assertCount( 1, $this->driver->sent );
		$this->assertEmpty( $this->driver->sleeps, 'No pause fits a spent slice budget.' );
		$this->assertSame( 1, $cursor['floor_fails'], 'The streak must persist so the next slice keeps counting.' );
	}

	/**
	 * After a clean streak, a shrunk chunk size doubles back toward the
	 * default instead of dragging one bad minute across the whole transfer.
	 */
	public function test_chunk_size_grows_back_after_clean_streak() {
		$plan = $this->staged_plan( 32 * 300 + 600 );

		$cursor = array( 'chunk_bytes' => 300 );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 33, $this->driver->sent );
		$this->assertSame( 300, $this->driver->sent[31]['bytes'] );
		$this->assertSame( 600, $this->driver->sent[32]['bytes'], 'The 33rd chunk must ride the doubled size.' );
		$this->assertSame( 600, $cursor['chunk_bytes'] );
		$this->assertSame( 0, $cursor['ceiling_bytes'], 'No failure ever happened, so no ceiling exists and plain doubling ran.' );
	}

	/**
	 * Grow-back never exceeds the starting default: doubling clamps to
	 * default_chunk_bytes(), filter override included.
	 */
	public function test_chunk_grow_back_caps_at_default() {
		$floor = MainWP_Backup_Restore_Transfer_Driver::CHUNK_MIN_BYTES;
		$plan  = $this->staged_plan( 32 * 200000 + 2 * $floor );

		// A filter value of 1 clamps the default down to the floor, so the
		// cap is observable without a hundred-megabyte fixture.
		$filter = static function () {
			return 1;
		};
		add_filter( 'mainwp_backup_restore_chunk_size', $filter );
		$cursor = array( 'chunk_bytes' => 200000 );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );
		remove_filter( 'mainwp_backup_restore_chunk_size', $filter );

		$this->assertTrue( $result['done'] );
		$this->assertSame( $floor, $this->driver->sent[32]['bytes'], 'Doubling 200000 must clamp to the default, not reach 400000.' );
		$this->assertSame( $floor, $cursor['chunk_bytes'] );
		$this->assertSame( 0, $cursor['ceiling_bytes'], 'No failure ever happened, so no ceiling exists and plain doubling ran.' );
		foreach ( $this->driver->sent as $send ) {
			$this->assertLessThanOrEqual( $floor, $send['bytes'] );
		}
	}

	/**
	 * A NOMAINWP rejection is a deterministic host body cap, not a blip:
	 * the ceiling it sets is hard.
	 */
	public function test_nomainwp_shrink_sets_hard_ceiling() {
		$plan = $this->staged_plan( 1000 );

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'NOMAINWP - https://example.com/wp-admin/admin-ajax.php',
				'retryable'      => true,
			),
		);

		$cursor = array( 'chunk_bytes' => 786432 );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 786432, $cursor['ceiling_bytes'] );
		$this->assertFalse( $cursor['ceiling_soft'], 'A NOMAINWP rejection must mark the ceiling hard.' );
	}

	/**
	 * The Imunify360 refusal is the same deterministic host-rejection
	 * class as NOMAINWP and must also mark the ceiling hard.
	 */
	public function test_imunify_shrink_sets_hard_ceiling() {
		$plan = $this->staged_plan( 1000 );

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'ERROR:Connection Failed. We suspect that Imunify360, a security layer added by your host, is causing this problem.',
				'retryable'      => true,
			),
		);

		$cursor = array( 'chunk_bytes' => 786432 );
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 786432, $cursor['ceiling_bytes'] );
		$this->assertFalse( $cursor['ceiling_soft'], 'An Imunify360 refusal must mark the ceiling hard.' );
	}

	/**
	 * A failure above an already-known tighter ceiling must not weaken it,
	 * and recovery goes back to the last size that worked.
	 */
	public function test_shrink_never_weakens_tighter_ceiling() {
		$plan = $this->staged_plan( 1000 );

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'HTTPERROR - connection reset',
				'retryable'      => true,
			),
		);

		$cursor = array(
			'chunk_bytes'   => 917504,
			'ceiling_bytes' => 524288,
			'ceiling_soft'  => false,
			'good_bytes'    => 393216,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 524288, $cursor['ceiling_bytes'], 'A larger failed size must not overwrite a tighter ceiling.' );
		$this->assertFalse( $cursor['ceiling_soft'], 'The tighter ceiling keeps its own classification.' );
		$this->assertSame( 393216, $cursor['chunk_bytes'], 'Recovery must ride the last known-good size.' );
	}

	/**
	 * After a failed probe, the transfer reverts to the last known-good
	 * size instead of halving away the convergence progress.
	 */
	public function test_shrink_reverts_to_last_known_good() {
		$plan = $this->staged_plan( 1000 );

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'NOMAINWP - https://example.com/wp-admin/admin-ajax.php',
				'retryable'      => true,
			),
		);

		$cursor = array(
			'chunk_bytes' => 589824,
			'good_bytes'  => 393216,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 589824, $cursor['ceiling_bytes'] );
		$this->assertSame( 393216, $cursor['good_bytes'], 'good_bytes survives as the revert target and the retry size.' );
	}

	/**
	 * When the known-good size itself starts failing, the shrink must
	 * halve - a revert to the same failing size would resend it forever,
	 * resurrecting the exact starvation bug this machinery fixes.
	 */
	public function test_shrink_at_good_size_halves_not_noop() {
		$plan = $this->staged_plan( 1000 );

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'NOMAINWP - https://example.com/wp-admin/admin-ajax.php',
				'retryable'      => true,
			),
		);

		$cursor = array(
			'chunk_bytes' => 393216,
			'good_bytes'  => 393216,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 393216, $cursor['ceiling_bytes'] );
		$this->assertSame( 262144, $cursor['chunk_bytes'], 'The retry must ride the halved size, not resend the failing one.' );
	}

	/**
	 * A transport that fails every send still escalates: from a converged
	 * good-size state it walks down to the floor and surfaces the
	 * FLOOR_FAILS_MAX retryable error. Revert-to-good can never ride a
	 * dead transport forever.
	 */
	public function test_dead_transport_from_converged_state_escalates() {
		$plan  = $this->staged_plan( 1000 );
		$fault = array(
			'result'         => 'shrink',
			'bytes_received' => 0,
			'error'          => 'HTTPERROR - timed out',
			'retryable'      => true,
		);

		$this->driver->chunk_script = array( $fault, $fault, $fault, $fault );

		$cursor = array(
			'chunk_bytes'   => 458752,
			'good_bytes'    => 458752,
			'ceiling_bytes' => 524288,
			'ceiling_soft'  => true,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertFalse( $result['done'] );
		$this->assertNotNull( $result['error'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertCount( 4, $this->driver->sent, 'One non-floor halve plus three floor retries reach escalation.' );
		$this->assertSame( 458752, $cursor['ceiling_bytes'], 'The equal-size failure tightens the ceiling to the failed size.' );
	}

	/**
	 * With a ceiling known, a clean streak bisects toward it instead of
	 * blindly doubling into the known-bad size - the production
	 * starvation pattern this sprint fixes.
	 */
	public function test_ceiling_bisects_instead_of_doubling() {
		$plan = $this->staged_plan( 32 * 393216 + 589824 );

		$cursor = array(
			'chunk_bytes'   => 393216,
			'ceiling_bytes' => 786432,
			'ceiling_soft'  => false,
			'good_bytes'    => 393216,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 33, $this->driver->sent );
		$this->assertSame( 589824, $this->driver->sent[32]['bytes'], 'The streak must probe the midpoint, never the known-bad ceiling.' );
		$this->assertSame( 589824, $cursor['chunk_bytes'] );
	}

	/**
	 * Once the gap to the ceiling is inside the convergence step, streaks
	 * stop growing the chunk and start counting toward a heal reprobe.
	 */
	public function test_ceiling_convergence_stops_growth_and_counts() {
		$plan = $this->staged_plan( 33 * 300000 );

		$cursor = array(
			'chunk_bytes'   => 300000,
			'ceiling_bytes' => 310000,
			'ceiling_soft'  => false,
			'good_bytes'    => 300000,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 300000, $this->driver->sent[32]['bytes'], 'A converged transfer must hold its size.' );
		$this->assertSame( 1, $cursor['ceiling_streak_count'], 'The converged streak must count toward a heal reprobe.' );
		$this->assertSame( 310000, $cursor['ceiling_bytes'] );
	}

	/**
	 * A soft ceiling reprobes quickly: the second converged streak sends
	 * one probe at the ceiling, and its success clears the cap entirely.
	 */
	public function test_soft_ceiling_reprobe_success_clears_ceiling() {
		$plan = $this->staged_plan( 32 * 300000 + 310000 );

		$cursor = array(
			'chunk_bytes'          => 300000,
			'ceiling_bytes'        => 310000,
			'ceiling_soft'         => true,
			'ceiling_streak_count' => 1,
			'good_bytes'           => 300000,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 310000, $this->driver->sent[32]['bytes'], 'The heal probe must ride the ceiling size.' );
		$this->assertSame( 0, $cursor['ceiling_bytes'], 'A successful probe at the ceiling clears it.' );
		$this->assertFalse( $cursor['ceiling_soft'] );
		$this->assertSame( 0, $cursor['ceiling_streak_count'] );
		$this->assertSame( 310000, $cursor['good_bytes'] );
	}

	/**
	 * A hard ceiling waits far longer between reprobes than a soft one: a
	 * count that would already fire the soft probe stays converged.
	 */
	public function test_hard_ceiling_waits_longer_before_reprobe() {
		$plan = $this->staged_plan( 33 * 300000 );

		$cursor = array(
			'chunk_bytes'          => 300000,
			'ceiling_bytes'        => 310000,
			'ceiling_soft'         => false,
			'ceiling_streak_count' => 1,
			'good_bytes'           => 300000,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 300000, $this->driver->sent[32]['bytes'], 'A hard ceiling must not reprobe at the soft cadence.' );
		$this->assertSame( 310000, $cursor['ceiling_bytes'] );
		$this->assertSame( 2, $cursor['ceiling_streak_count'] );
	}

	/**
	 * A failed heal probe reconfirms the ceiling, reclassifies it from
	 * this failure's error, resets the reprobe clock, and reverts to the
	 * known-good size.
	 */
	public function test_reprobe_failure_reconfirms_ceiling() {
		$plan = $this->staged_plan( 600000 );

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'NOMAINWP - https://example.com/wp-admin/admin-ajax.php',
				'retryable'      => true,
			),
		);

		$cursor = array(
			'chunk_bytes'          => 310000,
			'ceiling_bytes'        => 310000,
			'ceiling_soft'         => true,
			'ceiling_streak_count' => 0,
			'good_bytes'           => 300000,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 310000, $cursor['ceiling_bytes'], 'The failed probe reconfirms the same ceiling.' );
		$this->assertFalse( $cursor['ceiling_soft'], 'A NOMAINWP reconfirmation flips the ceiling hard.' );
		$this->assertSame( 0, $cursor['ceiling_streak_count'] );
		$this->assertSame( 300000, $this->driver->sent[1]['bytes'], 'The retry must revert to the known-good size.' );
	}

	/**
	 * A successful heal probe at the ceiling remembers the size it
	 * cleared, so a boundary that flaps right back can be recognized.
	 */
	public function test_reprobe_success_remembers_cleared_size() {
		$plan = $this->staged_plan( 32 * 300000 + 310000 );

		$cursor = array(
			'chunk_bytes'          => 300000,
			'ceiling_bytes'        => 310000,
			'ceiling_soft'         => true,
			'ceiling_streak_count' => 1,
			'good_bytes'           => 300000,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $cursor['ceiling_bytes'], 'A successful probe at the ceiling clears it.' );
		$this->assertSame( 310000, $cursor['ceiling_cleared_bytes'], 'The cleared size must be remembered for flap detection.' );
	}

	/**
	 * A flap at the exact size a heal probe just cleared steps down one
	 * converge step instead of halving - the boundary is known to sit
	 * right there, so a full bisection re-climb would rediscover what
	 * the cursor already learned (the job-14 production cycle).
	 */
	public function test_flap_at_cleared_size_steps_down_instead_of_halving() {
		$plan = $this->staged_plan( 737280 );

		$this->driver->chunk_script = array(
			array(
				'result'         => 'shrink',
				'bytes_received' => 0,
				'error'          => 'NOMAINWP - https://example.com/wp-admin/admin-ajax.php',
				'retryable'      => true,
			),
		);

		$cursor = array(
			'chunk_bytes'           => 737280,
			'good_bytes'            => 737280,
			'ceiling_cleared_bytes' => 737280,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 720896, $this->driver->sent[1]['bytes'], 'The retry must ride one converge step below the flapped size, not half of it.' );
		$this->assertSame( 737280, $cursor['ceiling_bytes'], 'The flapped size is re-taught as the ceiling.' );
		$this->assertFalse( $cursor['ceiling_soft'], 'A NOMAINWP flap marks the re-taught ceiling hard.' );
		$this->assertSame( 0, $cursor['ceiling_cleared_bytes'], 'The step-down consumes the cleared-size memory.' );
	}

	/**
	 * The step-down spends exactly one probe: when the size one converge
	 * step below the flapped boundary also fails, the halve safety takes
	 * over unchanged - nothing is proven at that level anymore.
	 */
	public function test_second_failure_after_flap_step_down_halves() {
		$plan  = $this->staged_plan( 737280 );
		$fault = array(
			'result'         => 'shrink',
			'bytes_received' => 0,
			'error'          => 'NOMAINWP - https://example.com/wp-admin/admin-ajax.php',
			'retryable'      => true,
		);

		$this->driver->chunk_script = array( $fault, $fault );

		$cursor = array(
			'chunk_bytes'           => 737280,
			'good_bytes'            => 737280,
			'ceiling_cleared_bytes' => 737280,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 737280, $this->driver->sent[0]['bytes'] );
		$this->assertSame( 720896, $this->driver->sent[1]['bytes'], 'The flap earns exactly one step-down probe.' );
		$this->assertSame( 360448, $this->driver->sent[2]['bytes'], 'The failed step-down probe halves - the safety is intact.' );
		$this->assertSame( 720896, $cursor['ceiling_bytes'], 'The second failure tightens the ceiling to its own size.' );
	}

	/**
	 * A checkpoint written before the ceiling fields existed backfills
	 * them on resume with no warnings and sane values.
	 */
	public function test_legacy_cursor_backfills_ceiling_fields() {
		$plan = $this->staged_plan( 900 );

		$cursor = array(
			'artifact_index' => 0,
			'offset'         => 0,
			'chunk_bytes'    => 300,
			'floor_fails'    => 0,
			'ok_streak'      => 0,
		);
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $cursor['ceiling_bytes'] );
		$this->assertFalse( $cursor['ceiling_soft'] );
		$this->assertSame( 0, $cursor['ceiling_streak_count'] );
		$this->assertSame( 0, $cursor['ceiling_cleared_bytes'] );
		$this->assertSame( 300, $cursor['good_bytes'], 'good_bytes must track the size that worked.' );
	}

	/**
	 * Adopt-only artifacts are skipped: nothing crosses the wire.
	 */
	public function test_adopt_only_skipped() {
		$plan = array(
			array(
				'role'       => 'full',
				'file'       => 'rollback.zip',
				'size'       => 10,
				'sha256'     => '',
				'adopt_only' => true,
			),
		);

		$cursor = array();
		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );

		$this->assertTrue( $result['done'] );
		$this->assertEmpty( $this->driver->sent );
	}
}
