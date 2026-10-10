<?php
/**
 * Restore transfer driver tests: staging from local and remote
 * sources, chunked transfer with realignment and adaptive sizing.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_Backup_Storage_Local;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;

/**
 * Model cloud transport failures after a partial download.
 */
class Restore_Throwing_Storage_Local extends MainWP_Backup_Storage_Local {

	/**
	 * Object whose download fails.
	 *
	 * @var string
	 */
	public $throw_key = '';

	/**
	 * Partial downloads that must be removed before failover completes.
	 *
	 * @var string[]
	 */
	public $failed_paths = array();

	/**
	 * Leave partial bytes as a transport failure can.
	 *
	 * @param string $key        Object key.
	 * @param string $local_path Target file path.
	 * @return bool Download result.
	 * @throws \RuntimeException When the selected download fails.
	 */
	public function get_object( string $key, string $local_path ): bool {
		if ( $key === $this->throw_key ) {
			file_put_contents( $local_path, 'partial download' );
			$this->failed_paths[] = $local_path;
			throw new \RuntimeException( 'Transport failed with private provider details.', 503 );
		}
		return parent::get_object( $key, $local_path );
	}
}

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
	 * Legacy plans need the same transport failures as candidate plans.
	 *
	 * @var \MainWP\Dashboard\MainWP_Backup_Storage_Provider|null
	 */
	public $snapshot_provider = null;

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
	 * Per-destination base paths the candidate failover resolves against.
	 *
	 * @var array<int,string>
	 */
	public $destination_bases = array();

	/**
	 * Provider overrides for transport failures Local cannot produce.
	 *
	 * @var array<int,\MainWP\Dashboard\MainWP_Backup_Storage_Provider>
	 */
	public $destination_providers = array();

	/**
	 * Destination ids the failover loop tried, in order.
	 *
	 * @var int[]
	 */
	public $resolved_destinations = array();

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
		if ( null !== $this->snapshot_provider ) {
			return $this->snapshot_provider;
		}
		if ( '' === $this->remote_base ) {
			return null;
		}
		return new MainWP_Backup_Storage_Local( array( 'base_path' => $this->remote_base ) );
	}

	/**
	 * Serve each candidate destination from its own pinned base path.
	 *
	 * @param int $destination_id Destination id.
	 *
	 * @return \MainWP\Dashboard\MainWP_Backup_Storage_Provider|null Provider.
	 */
	protected function provider_for_destination( int $destination_id ): ?\MainWP\Dashboard\MainWP_Backup_Storage_Provider {
		$this->resolved_destinations[] = $destination_id;
		if ( isset( $this->destination_providers[ $destination_id ] ) ) {
			return $this->destination_providers[ $destination_id ];
		}
		if ( ! isset( $this->destination_bases[ $destination_id ] ) ) {
			return null;
		}
		return new MainWP_Backup_Storage_Local( array( 'base_path' => $this->destination_bases[ $destination_id ] ) );
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
class MainWP_Backup_Restore_Transfer_Driver_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;

	use Backup_Private_Root_Fixture;

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
	 * Snapshot rows created by the integration test, cleaned in tear_down.
	 *
	 * @var int[]
	 */
	private $snapshot_ids = array();

	/**
	 * Build scratch dirs.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_private_root();
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
		if ( ! empty( $this->snapshot_ids ) ) {
			global $wpdb;
			$ids = implode( ',', array_map( 'intval', $this->snapshot_ids ) );
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . " WHERE id IN ( {$ids} )" ); // phpcs:ignore WordPress.DB -- test cleanup.
			foreach ( $this->snapshot_ids as $snapshot_id ) {
				MainWP_Backup_Upload_Job_Store::instance()->purge_upload_jobs_for_snapshot( $snapshot_id );
			}
			$this->snapshot_ids = array();
		}
		$this->tear_down_private_root();
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
	 * Restored plaintext stays behind deny files after a host recreates uploads.
	 */
	public function test_staging_protection_survives_recreation_and_transfer_resume() {
		$remote = $this->dir . '/remote';
		mkdir( $remote . '/4/8/files', 0755, true );
		file_put_contents( $remote . '/4/8/files/db.tar.gz', 'remote-bytes' );
		$this->driver->remote_base = $remote;
		$root                      = $this->private_root . '/restore-staging';
		$upload                    = function ( $dirs ) {
			$dirs['basedir'] = $this->dir;
			return $dirs;
		};
		$filtered                  = function ( $dirs ) {
			$dirs['basedir'] = $this->dir . '/filtered';
			return $dirs;
		};
		add_filter( 'upload_dir', $upload );
		add_filter( 'mainwp_get_wp_upload_dir', $filtered );
		try {
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
			for ( $pass = 0; $pass < 3; ++$pass ) {
				if ( 1 === $pass ) {
					$this->rrmdir( $root );
					$this->assertDirectoryDoesNotExist( $root );
				} elseif ( 2 === $pass ) {
					unlink( $root . '/.htaccess' );
					unlink( $root . '/index.php' );
				}
				$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
				$this->assertTrue( $outcome['done'] );
				$this->assertSame( $root . '/777001/db.tar.gz', $plan[0]['staged_path'] );
				$this->assertSame( 'remote-bytes', file_get_contents( $plan[0]['staged_path'] ) );
				if ( 2 === $pass ) {
					$this->assertFileDoesNotExist( $root . '/.htaccess' );
					$this->assertFileDoesNotExist( $root . '/index.php' );
					continue;
				}
				$this->assertFileExists( $root . '/.htaccess' );
				$this->assertSame( 'deny from all', file_get_contents( $root . '/.htaccess' ) );
				$this->assertFileExists( $root . '/index.php' );
				$this->assertSame( '', file_get_contents( $root . '/index.php' ) );
				$this->assertDirectoryDoesNotExist( $this->dir . '/filtered/mainwp/restore-staging' );
			}
			$cursor  = array();
			$outcome = $this->driver->transfer_artifacts( (object) array( 'id' => 4 ), 'task', $plan, $cursor, microtime( true ) + 60 );
			$this->assertTrue( $outcome['done'] );
			$this->assertNotEmpty( $this->driver->sent );
			$this->assertFileDoesNotExist( $root . '/.htaccess' );
			$this->assertFileDoesNotExist( $root . '/index.php' );
			$this->driver->cleanup_staging( 777001 );
			$this->assertDirectoryDoesNotExist( $root . '/777001' );
			$this->assertFileDoesNotExist( $root . '/.htaccess' );
			$this->assertFileDoesNotExist( $root . '/index.php' );
		} finally {
			remove_filter( 'upload_dir', $upload );
			remove_filter( 'mainwp_get_wp_upload_dir', $filtered );
		}
	}

	/**
	 * A resumed transfer reads staged bytes without requiring a writable root.
	 */
	public function test_transfer_resume_reads_staged_file_under_read_only_root() {
		$staging = $this->driver->staging_dir( 777001 );
		$this->assertIsString( $staging );
		$path = $staging . 'db.tar.gz';
		$this->assertSame( 12, file_put_contents( $path, 'remote-bytes' ) );
		$plan = array(
			array(
				'role'        => 'db',
				'file'        => 'db.tar.gz',
				'size'        => 12,
				'sha256'      => hash_file( 'sha256', $path ),
				'source'      => 'remote',
				'staged_path' => $path,
				'adopt_only'  => false,
			),
		);
		$this->assertTrue( chmod( $this->private_root, 0500 ) );
		try {
			clearstatcache( true, $this->private_root );
			if ( is_writable( $this->private_root ) ) {
				$this->markTestSkipped( 'The process can write read-only directories (for example, as root).' );
			}
			$cursor  = array();
			$outcome = $this->driver->transfer_artifacts( (object) array( 'id' => 4 ), 'task', $plan, $cursor, microtime( true ) + 60, null, 777001 );
			$this->assertNotSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $outcome['error_code'] ?? null );
			$this->assertTrue( $outcome['done'] );
			$this->assertNull( $outcome['error'] );
			$this->assertCount( 1, $this->driver->sent );
			$this->assertSame( 12, $this->driver->sent[0]['bytes'] );
		} finally {
			chmod( $this->private_root, 0700 );
		}
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
		$this->driver->remote_base     = $remote;
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
		$this->assertFalse( ! empty( $outcome['retryable'] ) );
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 777001 ) . 'db.tar.gz' );
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 777001 ) . 'db.tar.gz.downloading' );
	}

	/**
	 * Build a chunked remote layout: manifest plus content-addressed
	 * pieces under {site 0}/{snapshot 44}/.
	 *
	 * @param string $content    Artifact content split into two chunks.
	 * @param bool   $corrupt    Whether to corrupt the second chunk's bytes.
	 * @param string $algo       Chunk content-address algorithm.
	 * @param bool   $namespaced Build the plan entry the way the catalog
	 *                           does: chain-namespaced 'file' plus the raw
	 *                           'source_file' the manifest records.
	 *
	 * @return array Plan entry for the chunked artifact.
	 */
	private function make_chunked_remote( $content, $corrupt = false, $algo = 'sha256', $namespaced = false ) {
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

		$entry = array(
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
		if ( $namespaced ) {
			$entry['file']        = 's44-big.zip';
			$entry['source_file'] = 'big.zip';
		}
		return $entry;
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
	 * A production-shaped plan entry - chain-namespaced 'file' with the
	 * raw archive name in 'source_file' - still finds its artifact in the
	 * manifest, which records the snapshot's own archive names.
	 */
	public function test_stage_remote_chunked_matches_manifest_by_source_file() {
		$content = str_repeat( 'chunky-data-', 50 );
		$plan    = array( $this->make_chunked_remote( $content, false, 'sha256', true ) );

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( $content, file_get_contents( $plan[0]['staged_path'] ) );
		$this->assertStringEndsWith( 's44-big.zip', $plan[0]['staged_path'] );
	}

	/**
	 * A fresh chunked reassembly (no bytes on disk yet) refuses before
	 * downloading any chunk when free space is less than the artifact's
	 * remaining size - no assembly file is left behind.
	 */
	public function test_stage_remote_chunked_refuses_when_disk_full_at_start() {
		$content                       = str_repeat( 'chunky-data-', 50 );
		$plan                          = array( $this->make_chunked_remote( $content ) );
		$this->driver->fake_free_bytes = 5; // Less than strlen( $content ).

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_DISK_FULL, $outcome['error_code'] );
		$this->assertFalse( ! empty( $outcome['retryable'] ) );
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
		$plan[0]['chunks']       = $manifest['artifacts'][0]['chunks'];
		$plan[0]['total_size']   = strlen( $content );
		$plan[0]['chunk_prefix'] = '0/44/';
		$plan[0]['reassembly']   = array(
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
	 * Full remote-chunked path from real rows: a snapshot row plus its
	 * completed upload job resolve through the catalog into a namespaced
	 * plan, and that plan stages against the manifest the upload wrote.
	 * Guards the manifest-name/plan-name contract the hand-built unit
	 * fixtures cannot see.
	 */
	public function test_upload_catalog_stage_chunked_roundtrip() {
		global $wpdb;
		$this->create_test_site( array( 'id' => 9901 ) );
		$content = str_repeat( 'integration-bytes-', 40 );

		// A complete snapshot whose only artifact is gone locally.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'task_id'       => 991,
				'site_id'       => 9901,
				'type'          => 'full',
				'parent_id'     => null,
				'created'       => time(),
				'archives'      => wp_json_encode( array( array( 'file' => 'big.zip', 'role' => 'full', 'path' => $this->dir . '/gone/big.zip', 'size' => strlen( $content ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				'manifest_path' => '',
				'status'        => 'complete',
			)
		);
		$snapshot_id          = (int) $wpdb->insert_id;
		$this->snapshot_ids[] = $snapshot_id;

		// A completed chunked upload recorded the artifact under its raw name.
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( 7 ) );
		$jobs = $store->get_jobs_for_snapshot( $snapshot_id );
		$this->assertCount( 1, $jobs );
		$job_id = (int) $jobs[0]->id;
		$store->save_checkpoint( $job_id, array( 'artifacts' => array( array( 'file' => 'big.zip', 'mode' => 'chunked', 'sha256' => hash( 'sha256', $content ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
		$store->set_status( $job_id, MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE );

		// The catalog resolves the chain into a chain-namespaced plan.
		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id, 9901 );
		$this->assertTrue( $resolved['ok'], (string) $resolved['error'] );
		$plan = $resolved['plan'];
		$this->assertSame( 's' . $snapshot_id . '-big.zip', $plan[0]['file'] );
		$this->assertSame( 'big.zip', $plan[0]['source_file'] );
		$this->assertSame( 'remote_chunked', $plan[0]['source'] );

		// The remote layout the upload produced: chunks + manifest under
		// {site}/{snapshot}/, named by the snapshot's own archive names.
		$remote = $this->dir . '/remote-int';
		$prefix = $remote . '/9901/' . $snapshot_id;
		mkdir( $prefix . '/chunks', 0777, true );
		$half   = (int) ( strlen( $content ) / 2 );
		$chunks = array();
		$offset = 0;
		foreach ( array( substr( $content, 0, $half ), substr( $content, $half ) ) as $piece ) {
			$hash     = hash( 'sha256', $piece );
			$chunks[] = array( 'hash' => $hash, 'algo' => 'sha256', 'offset' => $offset, 'length' => strlen( $piece ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			file_put_contents( $prefix . '/chunks/' . $hash . '.bin', $piece ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$offset += strlen( $piece );
		}
		file_put_contents( $prefix . '/manifest.json', wp_json_encode( array( 'format' => 1, 'artifacts' => array( array( 'file' => 'big.zip', 'size' => strlen( $content ), 'chunks' => $chunks ) ) ) ) ); // phpcs:ignore
		$this->driver->remote_base = $remote;

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'], (string) ( $outcome['error'] ?? '' ) );
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
	 * Refusing another chunk preserves acknowledged progress without a failure.
	 */
	public function test_transfer_stops_before_chunk_when_callback_refuses() {
		$staged = $this->dir . '/payload.zip';
		file_put_contents( $staged, str_repeat( 'A', 700 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$plan   = array(
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
		$checks = 0;
		$result = $this->driver->transfer_artifacts(
			(object) array( 'id' => 1 ),
			'task-1-x',
			$plan,
			$cursor,
			microtime( true ) + 60,
			function () use ( &$checks ): bool {
				return ++$checks < 2;
			}
		);

		$this->assertSame(
			array(
				'done'      => false,
				'error'     => null,
				'retryable' => false,
			),
			$result
		);
		$this->assertSame( 2, $checks );
		$this->assertCount( 1, $this->driver->sent );
		$this->assertSame( 300, $cursor['offset'] );
		$this->assertSame( 0, $cursor['artifact_index'] );
		$this->assertSame( array( 0 ), array_column( $this->driver->sent, 'offset' ) );

		$result = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task-1-x', $plan, $cursor, microtime( true ) + 60 );
		$this->assertTrue( $result['done'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( array( 0, 300, 600 ), array_column( $this->driver->sent, 'offset' ) );
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
	 * Empty tombstones still need a final chunk so the child creates the file.
	 */
	public function test_zero_byte_artifact_completes_with_zero_byte_ack() {
		$staged = $this->dir . '/tombstones.list';
		file_put_contents( $staged, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$plan = array(
			array(
				'role'        => 'tombstones',
				'file'        => 'tombstones.list',
				'size'        => 0,
				'sha256'      => hash( 'sha256', '' ),
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

		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( 1, $cursor['artifact_index'] );
		$this->assertSame( 0, $cursor['offset'] );
		$this->assertSame(
			array(
				array(
					'file'   => 'tombstones.list',
					'offset' => 0,
					'bytes'  => 0,
					'last'   => true,
				),
			),
			$this->driver->sent
		);
	}

	/**
	 * A staged artifact that is missing reads back as size 0, which must not
	 * pass for an empty artifact: the child would create an empty file and the
	 * failure would resurface later as a hash mismatch.
	 */
	public function test_missing_staged_artifact_is_not_an_empty_artifact() {
		$plan = array(
			array(
				'role'        => 'tombstones',
				'file'        => 'tombstones.list',
				'size'        => 0,
				'sha256'      => hash( 'sha256', '' ),
				'staged_path' => $this->dir . '/never-staged.list',
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
		$this->assertStringContainsString( 'staged artifact is missing', $result['error'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( 0, $cursor['artifact_index'] );
		$this->assertSame( array(), $this->driver->sent, 'A missing staged artifact must not reach the child at all.' );
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

	/**
	 * A failover candidate record.
	 *
	 * @param int    $dest_id Destination id.
	 * @param bool   $chunked Chunked mode.
	 * @param string $sha     Backup-time sha256.
	 *
	 * @return array Candidate.
	 */
	private function candidate( $dest_id, $chunked = false, $sha = '' ) {
		return array(
			'key'            => '4/8/files/db.tar.gz',
			'chunked'        => $chunked,
			'destination_id' => $dest_id,
			'sha256'         => $sha,
		);
	}

	/**
	 * A remote plan entry carrying failover candidates.
	 *
	 * @param array  $candidates Candidates in order.
	 * @param string $source     Primary source classification.
	 *
	 * @return array Plan entry.
	 */
	private function remote_entry( array $candidates, $source = 'remote' ) {
		return array(
			'role'              => 'db',
			'file'              => 'db.tar.gz',
			'source_file'       => 'db.tar.gz',
			'size'              => 12,
			'sha256'            => '',
			'expected_sha256'   => isset( $candidates[0]['sha256'] ) ? $candidates[0]['sha256'] : '',
			'snapshot_id'       => 8,
			'source'            => $source,
			'local_path'        => '',
			'remote_key'        => '4/8/files/db.tar.gz',
			'remote_candidates' => $candidates,
			'adopt_only'        => false,
		);
	}

	/**
	 * Write a whole-file object under a destination base.
	 *
	 * @param string $base  Destination base dir.
	 * @param string $bytes Object content.
	 */
	private function put_object( $base, $bytes ) {
		if ( ! is_dir( $base . '/4/8/files' ) ) {
			mkdir( $base . '/4/8/files', 0777, true );
		}
		file_put_contents( $base . '/4/8/files/db.tar.gz', $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
	}

	/**
	 * The object missing at the primary destination stages from the second
	 * completed replica instead of failing the restore.
	 */
	public function test_stage_fails_over_when_primary_object_missing() {
		$base_a = $this->dir . '/dest-a';
		$base_b = $this->dir . '/dest-b';
		mkdir( $base_a );
		$this->put_object( $base_b, 'replica-two-bytes' );

		$this->driver->destination_bases = array(
			21 => $base_a,
			22 => $base_b,
		);

		$plan = array(
			$this->remote_entry(
				array(
					$this->candidate( 21, false, hash( 'sha256', 'replica-two-bytes' ) ),
					$this->candidate( 22, false, hash( 'sha256', 'replica-two-bytes' ) ),
				)
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( hash( 'sha256', 'replica-two-bytes' ), $plan[0]['sha256'] );
		$this->assertSame( array( 21, 22 ), $this->driver->resolved_destinations );
	}

	/**
	 * Exhausting replicas must retain transport retryability without provider details.
	 *
	 * @dataProvider failed_staging_sources
	 * @param string $failure Download that fails.
	 * @param bool   $legacy Whether the plan predates candidates.
	 * @param bool   $throws Whether the provider throws.
	 * @param bool   $second Whether a missing second replica is tried.
	 */
	public function test_failed_staging_classifies_provider_exceptions( string $failure, bool $legacy, bool $throws, bool $second ) {
		$base = $this->dir . '/remote';
		if ( 'whole' === $failure ) {
			mkdir( $base );
			$entry = $this->remote_entry( array( $this->candidate( 21, false, '' ) ) );
			$key   = $entry['remote_key'];
		} else {
			$content = 'chunked-replica-bytes';
			$entry   = $this->make_chunked_remote( $content );

			$entry['remote_candidates'] = array( $this->candidate( 21, true, '' ) );

			$key = 'manifest' === $failure ? '0/44/manifest.json' : '0/44/chunks/' . hash( 'sha256', substr( $content, 0, (int) ( strlen( $content ) / 2 ) ) ) . '.bin';
		}
		$provider            = new Restore_Throwing_Storage_Local( array( 'base_path' => $base ) );
		$provider->throw_key = $throws ? $key : '';

		$this->driver->destination_providers[21] = $provider;
		if ( $legacy ) {
			unset( $entry['remote_candidates'] );
			$this->driver->snapshot_provider = $provider;
		}
		if ( $second ) {
			$entry['remote_candidates'][]        = $this->candidate( 22, false, '' );
			$this->driver->destination_bases[22] = $base;
		}
		$plan    = array( $entry );
		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertNotNull( $outcome['error'] );
		$this->assertSame( $throws, ! empty( $outcome['retryable'] ), 'Provider exceptions must remain retryable after replicas are exhausted.' );
		$this->assertArrayNotHasKey( 'candidate_index', $plan[0] );
		$this->assertArrayNotHasKey( 'retryable', $plan[0] );
		$this->assertStringNotContainsString( 'private provider details', wp_json_encode( array( $outcome, $plan ) ) );
		$this->assertSame( $legacy ? array() : ( $second ? array( 21, 22 ) : array( 21 ) ), $this->driver->resolved_destinations );
		foreach ( $provider->failed_paths as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
	}

	/**
	 * Manifest and chunk failures also occur in plans saved before failover existed.
	 *
	 * @return array
	 */
	public static function failed_staging_sources() {
		return array(
			'single whole throw'    => array( 'whole', false, true, false ),
			'throw then false'      => array( 'whole', false, true, true ),
			'false only'            => array( 'whole', false, false, false ),
			'single manifest throw' => array( 'manifest', false, true, false ),
			'single chunk throw'    => array( 'chunk', false, true, false ),
			'legacy whole throw'    => array( 'whole', true, true, false ),
			'legacy manifest throw' => array( 'manifest', true, true, false ),
			'legacy chunk throw'    => array( 'chunk', true, true, false ),
		);
	}

	/**
	 * Every exit of the failover loop leaves the entry in one documented
	 * state: the outcome's retryability, the candidate index and carried
	 * history, and the chunked resume state. Rows M1-M21 are the input
	 * matrix in the D65 findings section.
	 *
	 * @dataProvider failover_exit_matrix
	 * @param string $source     Primary classification; picks the legacy fallback routine.
	 * @param array  $candidates Destination id => behaviour, in candidate order.
	 * @param string $fallback   Snapshot-level fallback: chunked or nocopy.
	 * @param array  $ticks      Each tick: mutations, deadline (past|open), expected result.
	 */
	public function test_failover_exit_matrix( string $source, array $candidates, string $fallback, array $ticks ) {
		$content = str_repeat( 'slice-data-', 20 );
		$entry   = $this->make_chunked_remote( $content );
		$remote  = $this->dir . '/remote';
		$empty   = $this->dir . '/empty';
		$whole   = $this->dir . '/whole';
		mkdir( $empty );
		$this->put_object( $whole, $content );

		$entry['source']            = $source;
		$entry['remote_key']        = '4/8/files/db.tar.gz';
		$entry['remote_candidates'] = array();
		$bases                      = array(
			'whole'   => $whole,
			'chunked' => $remote,
			'nocopy'  => $empty,
		);
		foreach ( $candidates as $dest_id => $behaviour ) {
			$entry['remote_candidates'][] = $this->candidate( $dest_id, in_array( $behaviour, array( 'chunked', 'throw_manifest' ), true ), '' );
			if ( 'throw' === $behaviour || 'throw_manifest' === $behaviour ) {
				$provider            = new Restore_Throwing_Storage_Local( array( 'base_path' => 'throw' === $behaviour ? $empty : $remote ) );
				$provider->throw_key = 'throw' === $behaviour ? '4/8/files/db.tar.gz' : '0/44/manifest.json';

				$this->driver->destination_providers[ $dest_id ] = $provider;
			} elseif ( 'gone' !== $behaviour ) {
				$this->driver->destination_bases[ $dest_id ] = $bases[ $behaviour ];
			}
		}
		if ( 'nocopy' === $fallback ) {
			$this->driver->snapshot_provider = new MainWP_Backup_Storage_Local( array( 'base_path' => $empty ) );
		}

		$plan     = array( $entry );
		$assembly = $this->driver->staging_dir( 777001 ) . 'big.zip.assembling';
		foreach ( $ticks as $tick => $spec ) {
			list( $mutations, $deadline, $expect ) = $spec;
			foreach ( $mutations as $mutation ) {
				if ( 0 === strpos( $mutation, 'gone:' ) ) {
					$gone = (int) substr( $mutation, 5 );
					unset( $this->driver->destination_bases[ $gone ], $this->driver->destination_providers[ $gone ] );
				} elseif ( 0 === strpos( $mutation, 'serve:' ) ) {
					// A destination comes back holding a whole file or no copy.
					list( , $back, $mode ) = explode( ':', $mutation );
					unset( $this->driver->destination_providers[ (int) $back ] );
					$this->driver->destination_bases[ (int) $back ] = $bases[ $mode ];
				} elseif ( 'lose_chunks' === $mutation ) {
					foreach ( (array) glob( $remote . '/0/44/chunks/*.bin' ) as $chunk ) {
						unlink( $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
					}
				} elseif ( 'disk_full' === $mutation ) {
					$this->driver->fake_free_bytes = 1;
				} elseif ( 'partial_assembly' === $mutation ) {
					// The previous tick appended the first chunk before yielding.
					$length = (int) $plan[0]['chunks'][0]['length'];
					file_put_contents( $assembly, substr( $content, 0, $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
					$plan[0]['reassembly'] = array(
						'next'  => 1,
						'bytes' => $length,
					);
				}
			}

			$outcome = $this->driver->stage_artifacts( 777001, $plan, 'past' === $deadline ? microtime( true ) - 1 : microtime( true ) + 60 );
			$label   = 'tick ' . ( $tick + 1 );
			$state   = $outcome['done'] ? 'staged' : ( null === $outcome['error'] ? 'pending' : 'error' );
			$code    = isset( $outcome['error_code'] ) ? $outcome['error_code'] : null;

			$this->assertSame( $expect['state'], $state, $label );
			$this->assertSame( $expect['retryable'], ! empty( $outcome['retryable'] ), $label . ' retryable' );
			$this->assertSame( $expect['disk_full'], MainWP_Backup_Error_Codes::ERR_RESTORE_DISK_FULL === $code, $label . ' disk full' );
			$this->assertSame( $expect['index'], $plan[0]['candidate_index'] ?? null, $label . ' candidate_index' );
			$this->assertSame( $expect['history'], array_key_exists( 'candidate_retryable', $plan[0] ), $label . ' candidate_retryable' );
			foreach ( array( 'reassembly', 'chunks', 'chunk_prefix' ) as $key ) {
				$this->assertSame( $expect['resume'], isset( $plan[0][ $key ] ), $label . ' ' . $key );
			}
			if ( ! $expect['resume'] ) {
				$this->assertFileDoesNotExist( $assembly, $label . ' assembly file' );
			}
		}
	}

	/**
	 * The failover exit matrix (D65 findings section, rows M1-M21).
	 *
	 * @return array
	 */
	public static function failover_exit_matrix() {
		$expect = static function ( $state, $retryable, $index, $history, $resume, $disk_full = false ) {
			return array(
				'state'     => $state,
				'retryable' => $retryable,
				'index'     => $index,
				'history'   => $history,
				'resume'    => $resume,
				'disk_full' => $disk_full,
			);
		};

		$pending_after_throw = array( array(), 'past', $expect( 'pending', false, 1, true, true ) );
		$fallback_yields     = array( array( 'gone:22' ), 'past', $expect( 'pending', false, 2, true, true ) );

		return array(
			'M1 first replica stages'                     => array( 'remote', array( 21 => 'whole' ), 'chunked', array( array( array(), 'open', $expect( 'staged', false, null, false, false ) ) ) ),
			'M2 chunked replica yields'                   => array( 'remote', array( 21 => 'chunked' ), 'chunked', array( array( array(), 'past', $expect( 'pending', false, 0, false, true ) ) ) ),
			'M3 throw then a chunked replica yields'      => array(
				'remote',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw ),
			),
			'M4 resumed replica stages'                   => array(
				'remote',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw, array( array(), 'open', $expect( 'staged', false, null, false, false ) ) ),
			),
			'M5 resumed replica fails after a throw'      => array(
				'remote',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw, array( array( 'lose_chunks' ), 'open', $expect( 'error', true, null, false, false ) ) ),
			),
			'M6 resumed replica fails after no copy'      => array(
				'remote',
				array(
					21 => 'nocopy',
					22 => 'chunked',
				),
				'chunked',
				array( array( array(), 'past', $expect( 'pending', false, 1, false, true ) ), array( array( 'lose_chunks' ), 'open', $expect( 'error', false, null, false, false ) ) ),
			),
			'M7 every replica fails, none threw'          => array(
				'remote',
				array(
					21 => 'nocopy',
					22 => 'nocopy',
				),
				'chunked',
				array( array( array(), 'open', $expect( 'error', false, null, false, false ) ) ),
			),
			'M8 throw then no copy'                       => array(
				'remote',
				array(
					21 => 'throw',
					22 => 'nocopy',
				),
				'chunked',
				array( array( array(), 'open', $expect( 'error', true, null, false, false ) ) ),
			),
			'M9 throw then disk full'                     => array(
				'remote',
				array(
					21 => 'throw_manifest',
					22 => 'chunked',
				),
				'chunked',
				array( array( array( 'disk_full' ), 'open', $expect( 'error', false, null, false, false, true ) ) ),
			),
			'M10 skip between tried replicas'             => array(
				'remote',
				array(
					21 => 'nocopy',
					22 => 'gone',
					23 => 'whole',
				),
				'chunked',
				array( array( array(), 'open', $expect( 'staged', false, null, false, false ) ) ),
			),
			'M11 skip after a throw'                      => array(
				'remote',
				array(
					21 => 'throw',
					22 => 'gone',
				),
				'chunked',
				array( array( array(), 'open', $expect( 'error', true, null, false, false ) ) ),
			),
			'M12 skip before a chunked replica yields'    => array(
				'remote',
				array(
					21 => 'gone',
					22 => 'chunked',
				),
				'chunked',
				array( array( array(), 'past', $expect( 'pending', false, 1, false, true ) ) ),
			),
			'M13 skipped resume drops its cursor'         => array(
				'remote',
				array(
					21 => 'chunked',
					22 => 'whole',
				),
				'chunked',
				array( array( array(), 'past', $expect( 'pending', false, 0, false, true ) ), array( array( 'partial_assembly', 'gone:21' ), 'open', $expect( 'staged', false, null, false, false ) ) ),
			),
			'M14 fallback errors with history'            => array(
				'remote',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'nocopy',
				array( $pending_after_throw, array( array( 'gone:22' ), 'open', $expect( 'error', true, null, false, false ) ) ),
			),
			'M15 fallback errors without history'         => array( 'remote', array( 21 => 'gone' ), 'nocopy', array( array( array(), 'open', $expect( 'error', false, null, false, false ) ) ) ),
			'M16 fallback yields then fails, history'     => array(
				'remote_chunked',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw, $fallback_yields, array( array( 'gone:21', 'lose_chunks' ), 'open', $expect( 'error', true, null, false, false ) ) ),
			),
			'M17 fallback yields then stages'             => array(
				'remote_chunked',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw, $fallback_yields, array( array( 'gone:21' ), 'open', $expect( 'staged', false, null, false, false ) ) ),
			),
			'M18 fallback disk full'                      => array(
				'remote_chunked',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw, array( array( 'gone:22', 'disk_full' ), 'open', $expect( 'error', false, null, false, false, true ) ) ),
			),
			'M19 fallback fails, an earlier replica is back' => array(
				'remote_chunked',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw, $fallback_yields, array( array( 'partial_assembly', 'lose_chunks', 'serve:21:whole' ), 'open', $expect( 'staged', false, null, false, false ) ) ),
			),
			'M20 fallback keeps its claim over a replica' => array(
				'remote_chunked',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw, $fallback_yields, array( array( 'serve:21:nocopy' ), 'open', $expect( 'staged', false, null, false, false ) ) ),
			),
			'M21 fallback and the replica back both fail' => array(
				'remote_chunked',
				array(
					21 => 'throw',
					22 => 'chunked',
				),
				'chunked',
				array( $pending_after_throw, $fallback_yields, array( array( 'lose_chunks', 'serve:21:nocopy' ), 'open', $expect( 'error', true, null, false, false ) ) ),
			),
		);
	}

	/**
	 * Cloud transport exceptions must not prevent a healthy replica restoring.
	 */
	public function test_stage_fails_over_when_primary_download_throws() {
		$base_a = $this->dir . '/dest-a';
		$base_b = $this->dir . '/dest-b';
		mkdir( $base_a );
		$this->put_object( $base_b, 'replica-two-bytes' );
		$provider            = new Restore_Throwing_Storage_Local( array( 'base_path' => $base_a ) );
		$provider->throw_key = '4/8/files/db.tar.gz';

		$this->driver->destination_providers[21] = $provider;
		$this->driver->destination_bases[22]     = $base_b;

		$sha  = hash( 'sha256', 'replica-two-bytes' );
		$plan = array(
			$this->remote_entry( array( $this->candidate( 21, false, $sha ), $this->candidate( 22, false, $sha ) ) ),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertNull( $outcome['error'] );
		$this->assertSame( 'replica-two-bytes', file_get_contents( $plan[0]['staged_path'] ) );
		$this->assertSame( $sha, $plan[0]['sha256'] );
		$this->assertSame( array( 21, 22 ), $this->driver->resolved_destinations );
		$this->assertCount( 1, $provider->failed_paths );
		$this->assertFileDoesNotExist( $provider->failed_paths[0] );
	}

	/**
	 * A manifest transport failure must allow the next chunked replica.
	 */
	public function test_stage_fails_over_when_primary_manifest_download_throws() {
		$content             = 'chunked-replica-bytes';
		$entry               = $this->make_chunked_remote( $content, false, 'sha256', true );
		$base                = $this->dir . '/remote';
		$provider            = new Restore_Throwing_Storage_Local( array( 'base_path' => $base ) );
		$provider->throw_key = '0/44/manifest.json';

		$this->driver->destination_providers[21] = $provider;
		$this->driver->destination_bases[22]     = $base;

		$sha  = hash( 'sha256', $content );
		$plan = array(
			array_merge(
				$this->remote_entry( array( $this->candidate( 21, true, $sha ), $this->candidate( 22, true, $sha ) ), 'remote_chunked' ),
				$entry
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertNull( $outcome['error'] );
		$this->assertSame( $content, file_get_contents( $plan[0]['staged_path'] ) );
		$this->assertSame( $sha, $plan[0]['sha256'] );
		$this->assertSame( array( 21, 22 ), $this->driver->resolved_destinations );
		$this->assertCount( 1, $provider->failed_paths );
		$this->assertFileDoesNotExist( $provider->failed_paths[0] );
	}

	/**
	 * A chunk transport failure must discard partial bytes before failover.
	 */
	public function test_stage_fails_over_when_primary_chunk_download_throws() {
		$content             = 'chunked-replica-bytes';
		$entry               = $this->make_chunked_remote( $content, false, 'sha256', true );
		$base                = $this->dir . '/remote';
		$provider            = new Restore_Throwing_Storage_Local( array( 'base_path' => $base ) );
		$chunk_hash          = hash( 'sha256', substr( $content, 0, (int) ( strlen( $content ) / 2 ) ) );
		$provider->throw_key = '0/44/chunks/' . $chunk_hash . '.bin';

		$this->driver->destination_providers[21] = $provider;
		$this->driver->destination_bases[22]     = $base;

		$sha  = hash( 'sha256', $content );
		$plan = array(
			array_merge(
				$this->remote_entry( array( $this->candidate( 21, true, $sha ), $this->candidate( 22, true, $sha ) ), 'remote_chunked' ),
				$entry
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertNull( $outcome['error'] );
		$this->assertSame( $content, file_get_contents( $plan[0]['staged_path'] ) );
		$this->assertSame( $sha, $plan[0]['sha256'] );
		$this->assertSame( array( 21, 22 ), $this->driver->resolved_destinations );
		$this->assertCount( 1, $provider->failed_paths );
		$this->assertFileDoesNotExist( $provider->failed_paths[0] );
	}

	/**
	 * With every replica healthy the first candidate serves the download:
	 * failover is deterministic, not a scan.
	 */
	public function test_stage_prefers_first_healthy_destination() {
		$base_a = $this->dir . '/dest-a';
		$base_b = $this->dir . '/dest-b';
		$this->put_object( $base_a, 'replica-one' );
		$this->put_object( $base_b, 'replica-two' );

		$this->driver->destination_bases = array(
			21 => $base_a,
			22 => $base_b,
		);

		$plan = array(
			$this->remote_entry(
				array(
					$this->candidate( 21, false, hash( 'sha256', 'replica-one' ) ),
					$this->candidate( 22, false, hash( 'sha256', 'replica-two' ) ),
				)
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( hash( 'sha256', 'replica-one' ), $plan[0]['sha256'] );
		$this->assertSame( array( 21 ), $this->driver->resolved_destinations );
	}

	/**
	 * A corrupt object at the primary (integrity hash mismatch) fails over
	 * to the healthy replica instead of failing the restore.
	 */
	public function test_stage_fails_over_on_corrupt_primary_object() {
		$base_a = $this->dir . '/dest-a';
		$base_b = $this->dir . '/dest-b';
		$this->put_object( $base_a, 'evil-bytes!!' );
		$this->put_object( $base_b, 'good-bytes!!' );

		$this->driver->destination_bases = array(
			21 => $base_a,
			22 => $base_b,
		);

		$good_sha = hash( 'sha256', 'good-bytes!!' );
		$plan     = array(
			$this->remote_entry(
				array(
					$this->candidate( 21, false, $good_sha ),
					$this->candidate( 22, false, $good_sha ),
				)
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( $good_sha, $plan[0]['sha256'] );
		$this->assertSame( array( 21, 22 ), $this->driver->resolved_destinations );
	}

	/**
	 * A chunked primary whose manifest is gone fails over to a whole-file
	 * replica at another destination; the candidate's own mode drives the
	 * staging routine, not the entry's primary classification.
	 */
	public function test_chunked_candidate_fails_over_to_whole_replica() {
		$base_a = $this->dir . '/dest-a';
		$base_b = $this->dir . '/dest-b';
		mkdir( $base_a );
		$this->put_object( $base_b, 'whole-replica' );

		$this->driver->destination_bases = array(
			21 => $base_a,
			22 => $base_b,
		);

		$plan = array(
			$this->remote_entry(
				array(
					$this->candidate( 21, true, '' ),
					$this->candidate( 22, false, hash( 'sha256', 'whole-replica' ) ),
				),
				'remote_chunked'
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( hash( 'sha256', 'whole-replica' ), $plan[0]['sha256'] );
		$this->assertSame( array( 21, 22 ), $this->driver->resolved_destinations );
	}

	/**
	 * Disk exhaustion is host-side: no other destination can fix it, so the
	 * failover loop stops instead of retrying every replica into the same
	 * full disk.
	 */
	public function test_disk_full_stops_failover() {
		$base_a = $this->dir . '/dest-a';
		$base_b = $this->dir . '/dest-b';
		$this->put_object( $base_a, 'replica-one' );
		$this->put_object( $base_b, 'replica-two' );

		$this->driver->destination_bases = array(
			21 => $base_a,
			22 => $base_b,
		);
		$this->driver->fake_free_bytes   = 1;

		$plan = array(
			$this->remote_entry(
				array(
					$this->candidate( 21, false, hash( 'sha256', 'replica-one' ) ),
					$this->candidate( 22, false, hash( 'sha256', 'replica-two' ) ),
				)
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_DISK_FULL, $outcome['error_code'] );
		$this->assertFalse( ! empty( $outcome['retryable'] ) );
		$this->assertSame( array( 21 ), $this->driver->resolved_destinations );
	}

	/**
	 * Every candidate failing leaves the last error as the outcome, the
	 * same hard failure a single destination produced before failover.
	 */
	public function test_all_candidates_failing_reports_error() {
		$base_a = $this->dir . '/dest-a';
		$base_b = $this->dir . '/dest-b';
		mkdir( $base_a );
		mkdir( $base_b );

		$this->driver->destination_bases = array(
			21 => $base_a,
			22 => $base_b,
		);

		$plan = array(
			$this->remote_entry(
				array(
					$this->candidate( 21, false, '' ),
					$this->candidate( 22, false, '' ),
				)
			),
		);

		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertNotNull( $outcome['error'] );
		$this->assertSame( array( 21, 22 ), $this->driver->resolved_destinations );
	}

	/**
	 * Local legacy artifacts remain restorable when private storage is unavailable.
	 */
	public function test_local_legacy_plan_never_resolves_private_staging() {
		$legacy = \MainWP\Dashboard\MainWP_Backup_Private_Root::legacy_site_dir( 777001 );
		wp_mkdir_p( $legacy );
		$file = $legacy . 'local-test.zip';
		file_put_contents( $file, 'local bytes' );
		$plan   = array(
			array(
				'source'     => 'local',
				'local_path' => $file,
				'file'       => 'legacy.zip',
			),
		);
		$calls  = 0;
		$filter = static function () use ( &$calls ) {
			++$calls;
			return false;
		};
		add_filter( 'mainwp_backup_private_root', $filter, 99 );
		\MainWP\Dashboard\MainWP_Backup_Private_Root::reset();
		$outcome = $this->driver->stage_artifacts( 777001, $plan, microtime( true ) + 60 );
		$this->assertSame(
			array(
				'done'  => true,
				'error' => null,
			),
			$outcome
		);
		$this->assertSame( 0, $calls );
		$this->assertSame( $file, $plan[0]['staged_path'] );
		$this->assertSame( hash( 'sha256', 'local bytes' ), $plan[0]['sha256'] );
		wp_delete_file( $file );
	}

	/**
	 * Old remote checkpoints must stage again before transferring any bytes.
	 */
	public function test_legacy_staging_is_retried_for_both_remote_sources() {
		$filter = function ( $dirs ) {
			$dirs['basedir'] = $this->dir . '/filtered';
			return $dirs;
		};
		add_filter( 'mainwp_get_wp_upload_dir', $filter );
		$upload = apply_filters( 'mainwp_get_wp_upload_dir', wp_upload_dir( null, false ), null, false );
		$legacy = trailingslashit( $upload['basedir'] ) . 'mainwp/restore-staging/777001/';
		wp_mkdir_p( $legacy );
		try {
			foreach ( array( 'remote', 'remote_chunked' ) as $source ) {
				file_put_contents( $legacy . 'archive.zip', 'legacy' );
				$plan    = array(
					array(
						'source'      => $source,
						'staged_path' => $legacy . 'archive.zip',
						'sha256'      => hash( 'sha256', 'legacy' ),
					),
				);
				$cursor  = array( 'offset' => 3 );
				$outcome = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task', $plan, $cursor, microtime( true ) + 60, null, 777001 );
				$this->assertFalse( $outcome['done'] );
				$this->assertNull( $outcome['error'] );
				$this->assertTrue( $outcome['retryable'] );
				$this->assertTrue( $outcome['restage'] );
				$this->assertSame( '', $outcome['plan'][0]['staged_path'] );
				$this->assertSame( '', $outcome['plan'][0]['sha256'] );
				$this->assertEmpty( $this->driver->sent );
				$this->assertFileDoesNotExist( $legacy . 'archive.zip' );
			}
			$outside = $this->dir . '/outside.zip';
			file_put_contents( $outside, 'keep' );
			$plan[0]['staged_path'] = $outside;
			$outcome                = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task', $plan, $cursor, microtime( true ) + 60, null, 777001 );
			$this->assertTrue( $outcome['restage'] );
			$this->assertSame( 'keep', file_get_contents( $outside ) );
			add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
			\MainWP\Dashboard\MainWP_Backup_Private_Root::reset();
			$outcome = $this->driver->transfer_artifacts( (object) array( 'id' => 1 ), 'task', $plan, $cursor, microtime( true ) + 60, null, 777001 );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $outcome['error_code'] );
			$this->driver->cleanup_staging( 777001 );
			$this->assertDirectoryDoesNotExist( $legacy );
		} finally {
			remove_filter( 'mainwp_get_wp_upload_dir', $filter );
			$this->rrmdir( $legacy );
		}
	}
}

/**
 * Provider double answering head_object from a script.
 */
class Head_Scripted_Provider {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Scripted answer: false (confirmed missing), 'throw' (transient
	 * error), anything else reads as present.
	 *
	 * @var mixed
	 */
	private $answer;

	/**
	 * Pin the answer.
	 *
	 * @param mixed $answer Scripted answer.
	 */
	public function __construct( $answer ) {
		$this->answer = $answer;
	}

	/**
	 * Scripted HEAD.
	 *
	 * @param string $key Object key.
	 *
	 * @return mixed Scripted result.
	 *
	 * @throws \RuntimeException When scripted to.
	 */
	public function head_object( string $key ) {
		unset( $key );
		if ( 'throw' === $this->answer ) {
			throw new \RuntimeException( 'transient head failure' );
		}
		return $this->answer;
	}
}

/**
 * Driver with the presignable candidate enumeration scripted.
 */
class Preflight_Candidates_Driver extends MainWP_Backup_Restore_Transfer_Driver {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Scripted uploads.
	 *
	 * @var array
	 */
	public $uploads = array();

	/**
	 * Scripted enumeration.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return array Uploads.
	 */
	protected function presignable_uploads_for_snapshot( int $snapshot_id ): array {
		unset( $snapshot_id );
		return $this->uploads;
	}

	/**
	 * Fixed prefix; no snapshot row needed.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return string Prefix.
	 */
	protected function snapshot_prefix( int $snapshot_id ): string {
		return '4/' . $snapshot_id . '/';
	}
}

/**
 * Class MainWP_Backup_Restore_Preflight_Failover_Test
 */
class MainWP_Backup_Restore_Preflight_Failover_Test extends \WP_UnitTestCase {

	use Backup_Cancel_Transport_Guard;

 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- doubles above.

	/**
	 * A remote plan the preflight checks.
	 *
	 * @return array Plan.
	 */
	private function remote_plan() {
		return array(
			array(
				'role'        => 'db',
				'file'        => 's8-db.tar.gz',
				'source_file' => 'db.tar.gz',
				'snapshot_id' => 8,
				'source'      => 'remote',
				'adopt_only'  => false,
			),
		);
	}

	/**
	 * An artifact confirmed missing at the first destination but present at
	 * a second replica must not block the restore.
	 */
	public function test_preflight_passes_when_replica_present_elsewhere() {
		$driver          = new Preflight_Candidates_Driver();
		$driver->uploads = array(
			array(
				'provider'  => new Head_Scripted_Provider( false ),
				'artifacts' => array(),
			),
			array(
				'provider'  => new Head_Scripted_Provider( array( 'size' => 12 ) ),
				'artifacts' => array(),
			),
		);

		$this->assertTrue( $driver->remote_artifacts_present( $this->remote_plan() ) );
	}

	/**
	 * Only an artifact confirmed missing at EVERY presignable destination
	 * blocks the restore.
	 */
	public function test_preflight_blocks_when_all_replicas_confirmed_missing() {
		$driver          = new Preflight_Candidates_Driver();
		$driver->uploads = array(
			array(
				'provider'  => new Head_Scripted_Provider( false ),
				'artifacts' => array(),
			),
			array(
				'provider'  => new Head_Scripted_Provider( false ),
				'artifacts' => array(),
			),
		);

		$this->assertFalse( $driver->remote_artifacts_present( $this->remote_plan() ) );
	}

	/**
	 * A transient HEAD error at any replica defers to staging rather than
	 * false-blocking: absence was not confirmed everywhere.
	 */
	public function test_preflight_transient_error_never_blocks() {
		$driver          = new Preflight_Candidates_Driver();
		$driver->uploads = array(
			array(
				'provider'  => new Head_Scripted_Provider( false ),
				'artifacts' => array(),
			),
			array(
				'provider'  => new Head_Scripted_Provider( 'throw' ),
				'artifacts' => array(),
			),
		);

		$this->assertTrue( $driver->remote_artifacts_present( $this->remote_plan() ) );
	}
}
