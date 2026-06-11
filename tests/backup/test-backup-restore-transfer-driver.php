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
	 * Serve remote objects from the pinned base path.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return object|null Destination stand-in.
	 */
	protected function find_destination_for_snapshot( int $snapshot_id ): ?object {
		if ( '' === $this->remote_base ) {
			return null;
		}
		return (object) array(
			'id'       => 1,
			'provider' => 'local',
			'settings' => array( 'base_path' => $this->remote_base ),
		);
	}

	/**
	 * Stage via the local provider directly (bypasses destination
	 * decryption).
	 *
	 * @param string $staging Staging dir.
	 * @param array  $entry   Plan entry (by reference).
	 *
	 * @return string|null Error or null.
	 */
	protected function stage_from_remote( string $staging, array &$entry ): ?string {
		if ( '' === $this->remote_base ) {
			return 'No storage destination holds a copy of this backup.';
		}
		$provider = new MainWP_Backup_Storage_Local( array( 'base_path' => $this->remote_base ) );
		$final    = $staging . $entry['file'];
		if ( ! $provider->get_object( $entry['remote_key'], $final ) ) {
			return 'Downloading the backup file from cloud storage failed: ' . $entry['file'];
		}
		$entry['staged_path'] = $final;
		$entry['sha256']      = (string) hash_file( 'sha256', $final );
		return null;
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
	 * A chunked-remote artifact refuses staging with a clear error
	 * (reassembly is its own step).
	 */
	public function test_stage_remote_chunked_refused() {
		$plan = array(
			array(
				'role'        => 'full',
				'file'        => 'big.zip',
				'size'        => 1,
				'sha256'      => '',
				'snapshot_id' => 4,
				'source'      => 'remote_chunked',
				'local_path'  => '',
				'remote_key'  => '',
				'adopt_only'  => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertNotNull( $outcome['error'] );
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
