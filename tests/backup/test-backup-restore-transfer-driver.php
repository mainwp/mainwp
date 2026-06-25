<?php
/**
 * Restore transfer driver tests: staging from local and remote
 * sources, chunked transfer with realignment and adaptive sizing.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

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
