<?php
/**
 * Restore decryption tests.
 *
 * An encrypted snapshot uploaded through the real upload path is downloaded,
 * decrypted, and verified through the real restore staging path, proving the
 * seal/open round-trip end to end for both whole and chunked artifacts. A
 * chain that mixes an encrypted snapshot with a plaintext one decrypts each
 * artifact from its own snapshot, and decryption keeps working after the
 * toggle is turned off. A snapshot whose key is gone surfaces a staging error
 * rather than restoring garbage.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Archive_Cipher;
use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Local;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Restore driver serving remote objects from a pinned base path.
 */
class Decrypt_Test_Driver extends MainWP_Backup_Restore_Transfer_Driver {

	/**
	 * Base path the stub provider serves objects from.
	 *
	 * @var string
	 */
	public $remote_base = '';

	/**
	 * Serve remote objects from the pinned base via the real local provider.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return \MainWP\Dashboard\MainWP_Backup_Storage_Provider|null
	 */
	protected function provider_for_snapshot( int $snapshot_id ): ?\MainWP\Dashboard\MainWP_Backup_Storage_Provider {
		if ( '' === $this->remote_base ) {
			return null;
		}
		return new MainWP_Backup_Storage_Local( array( 'base_path' => $this->remote_base ) );
	}
}

/**
 * Class MainWP_Backup_Restore_Decryption_Test
 */
class MainWP_Backup_Restore_Decryption_Test extends \WP_UnitTestCase {

	/**
	 * Scratch dir.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Driver under test.
	 *
	 * @var Decrypt_Test_Driver
	 */
	private $driver;

	/**
	 * Set up scratch dirs, the driver, and tiny chunk thresholds.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-decrypt-test-' . uniqid();
		mkdir( $this->dir );
		$this->driver = new Decrypt_Test_Driver();
		add_filter(
			'mainwp_backup_upload_chunk_size',
			static function () {
				return 1024;
			}
		);
		add_filter(
			'mainwp_backup_upload_whole_file_threshold',
			static function () {
				return 2000;
			}
		);
	}

	/**
	 * Clean staging, scratch, and encryption state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->driver->cleanup_staging( 888001 );
		$this->rrmdir( $this->dir );

		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );

		parent::tear_down();
	}

	/**
	 * Recursive cleanup.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
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
	 * Arm encryption: toggle on and set a recovery passphrase (creates the KEK).
	 *
	 * @return MainWP_Backup_Encryption
	 */
	private function arm() {
		$enc = MainWP_Backup_Encryption::instance();
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '1' );
		$enc->set_recovery_passphrase( 'restore decryption passphrase' );
		return $enc;
	}

	/**
	 * Position-unique content so no two chunks dedupe to one object.
	 *
	 * @param string $name Seed.
	 * @param int    $size Byte length.
	 *
	 * @return string
	 */
	private function position_unique( $name, $size ) {
		$content = '';
		$block   = 0;
		$length  = 0;
		while ( $length < $size ) {
			$content .= md5( $name . '-' . $block, true );
			$length  += 16; // Raw md5 appends 16 bytes.
			++$block;
		}
		return substr( $content, 0, $size );
	}

	/**
	 * Record a snapshot over fixtures and return its id.
	 *
	 * @param int   $site_id  Site id.
	 * @param array $archives Archive entries.
	 *
	 * @return int
	 */
	private function record( $site_id, array $archives ) {
		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => $site_id,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => $archives,
				'manifest_path' => '',
			)
		);
	}

	/**
	 * A whole-file uploaded through the real seal path downloads, decrypts, and
	 * verifies through the real restore path; so does a chunked artifact. This
	 * proves the upload and restore agree on the data key and the AAD.
	 *
	 * @return void
	 */
	public function test_encrypted_round_trip_through_upload_and_restore() {
		$this->arm();

		$base    = $this->dir . '/dest';
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'round trip',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$src = $this->dir . '/src';
		mkdir( $src );
		$big   = $this->position_unique( 'big', 3000 );
		$small = 'a small database dump';
		file_put_contents( $src . '/big.tar.gz', $big );
		file_put_contents( $src . '/db.sql.gz', $small );

		$snapshot_id = $this->record(
			4,
			array(
				array(
					'file' => 'big.tar.gz',
					'size' => 3000,
					'role' => 'part',
					'path' => $src . '/big.tar.gz',
				),
				array(
					'file' => 'db.sql.gz',
					'size' => strlen( $small ),
					'role' => 'db',
					'path' => $src . '/db.sql.gz',
				),
			)
		);
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$this->driver->remote_base = $base;

		// Whole-file artifact round-trips to the exact source bytes.
		$whole   = array(
			array(
				'role'            => 'db',
				'file'            => 'db.sql.gz',
				'size'            => strlen( $small ),
				'sha256'          => '',
				'snapshot_id'     => $snapshot_id,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/' . $snapshot_id . '/files/db.sql.gz',
				'expected_sha256' => hash( 'sha256', $small ),
				'adopt_only'      => false,
			),
		);
		$outcome = $this->driver->stage_artifacts( 888001, $whole, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'], (string) $outcome['error'] );
		$this->assertSame( $small, file_get_contents( $whole[0]['staged_path'] ) );
		$this->assertSame( hash( 'sha256', $small ), $whole[0]['sha256'] );

		// Chunked artifact reassembles, decrypts, and verifies its whole hash.
		$chunked  = array(
			array(
				'role'            => 'part',
				'file'            => 'big.tar.gz',
				'size'            => 3000,
				'sha256'          => '',
				'snapshot_id'     => $snapshot_id,
				'source'          => 'remote_chunked',
				'local_path'      => '',
				'remote_key'      => '',
				'expected_sha256' => hash( 'sha256', $big ),
				'adopt_only'      => false,
			),
		);
		$outcome2 = $this->driver->stage_artifacts( 888001, $chunked, microtime( true ) + 60 );
		$this->assertTrue( $outcome2['done'], (string) $outcome2['error'] );
		$this->assertSame( $big, file_get_contents( $chunked[0]['staged_path'] ) );
		$this->assertSame( hash( 'sha256', $big ), $chunked[0]['sha256'] );
	}

	/**
	 * Staging decrypts a whole-file artifact whose plan carries the
	 * chain-namespaced staging name in 'file' and the snapshot's own basename
	 * in 'source_file', as a resolve-built plan does. The upload seals under
	 * the basename, so the open AAD must follow source_file; keying it on the
	 * namespaced 'file' makes AES-GCM authentication fail and a valid backup
	 * read as undecryptable. The earlier whole-file test hand-builds an
	 * unprefixed plan and so cannot catch this.
	 *
	 * @return void
	 */
	public function test_namespaced_whole_file_decrypts_via_source_file_aad() {
		$this->arm();

		$base    = $this->dir . '/dest';
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'namespaced aad',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$src = $this->dir . '/src';
		mkdir( $src );
		$small = 'a small database dump';
		file_put_contents( $src . '/db.sql.gz', $small );

		$snapshot_id = $this->record(
			4,
			array(
				array(
					'file' => 'db.sql.gz',
					'size' => strlen( $small ),
					'role' => 'db',
					'path' => $src . '/db.sql.gz',
				),
			)
		);
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$this->driver->remote_base = $base;

		// A resolve-built plan: 'file' is the chain-namespaced staging name,
		// 'source_file' is the basename the upload sealed under.
		$plan    = array(
			array(
				'role'            => 'db',
				'file'            => 's' . $snapshot_id . '-db.sql.gz',
				'source_file'     => 'db.sql.gz',
				'size'            => strlen( $small ),
				'sha256'          => '',
				'snapshot_id'     => $snapshot_id,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/' . $snapshot_id . '/files/db.sql.gz',
				'expected_sha256' => hash( 'sha256', $small ),
				'adopt_only'      => false,
			),
		);
		$outcome = $this->driver->stage_artifacts( 888001, $plan, microtime( true ) + 60 );

		$this->assertTrue( $outcome['done'], (string) $outcome['error'] );
		$this->assertSame( $small, file_get_contents( $plan[0]['staged_path'] ) );
		$this->assertSame( hash( 'sha256', $small ), $plan[0]['sha256'] );
	}

	/**
	 * A restore plan that mixes an encrypted snapshot with a plaintext one
	 * decrypts each artifact from its own snapshot. The encrypted artifact
	 * still decrypts after the toggle is turned off, since the key survives.
	 *
	 * @return void
	 */
	public function test_mixed_chain_decrypts_per_snapshot() {
		$enc  = $this->arm();
		$base = $this->dir . '/dest';

		// Encrypted snapshot A: seal a whole-file object under its own DEK.
		$a       = $this->record( 4, array() );
		$snap_a  = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $a );
		$dek_a   = $enc->dek_from_meta( $snap_a->encryption, $a, $enc->load_kek() );
		$plain_a = 'encrypted artifact A bytes';
		mkdir( $base . '/4/' . $a . '/files', 0777, true );
		file_put_contents( $base . '/4/' . $a . '/files/a.tar.gz', MainWP_Backup_Archive_Cipher::seal( $plain_a, $dek_a, 'snapshot:' . $a . ':whole:a.tar.gz' ) );

		// Turn encryption off, then record a plaintext snapshot B and write its
		// plaintext object. A must still decrypt: disabling must not lose keys.
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '' );
		$b       = $this->record( 4, array() );
		$plain_b = 'plaintext artifact B bytes';
		mkdir( $base . '/4/' . $b . '/files', 0777, true );
		file_put_contents( $base . '/4/' . $b . '/files/b.tar.gz', $plain_b );

		$this->driver->remote_base = $base;
		$plan                      = array(
			array(
				'role'            => 'part',
				'file'            => 'a.tar.gz',
				'size'            => strlen( $plain_a ),
				'sha256'          => '',
				'snapshot_id'     => $a,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/' . $a . '/files/a.tar.gz',
				'expected_sha256' => hash( 'sha256', $plain_a ),
				'adopt_only'      => false,
			),
			array(
				'role'            => 'db',
				'file'            => 'b.tar.gz',
				'size'            => strlen( $plain_b ),
				'sha256'          => '',
				'snapshot_id'     => $b,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/' . $b . '/files/b.tar.gz',
				'expected_sha256' => hash( 'sha256', $plain_b ),
				'adopt_only'      => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 888001, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'], (string) $outcome['error'] );
		$this->assertSame( $plain_a, file_get_contents( $plan[0]['staged_path'] ) );
		$this->assertSame( $plain_b, file_get_contents( $plan[1]['staged_path'] ) );
	}

	/**
	 * An encrypted snapshot whose key file is gone surfaces a staging error,
	 * not a crash and not restored garbage.
	 *
	 * @return void
	 */
	public function test_missing_key_surfaces_staging_error() {
		$enc  = $this->arm();
		$base = $this->dir . '/dest';

		$a    = $this->record( 4, array() );
		$snap = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $a );
		$dek  = $enc->dek_from_meta( $snap->encryption, $a, $enc->load_kek() );
		mkdir( $base . '/4/' . $a . '/files', 0777, true );
		file_put_contents( $base . '/4/' . $a . '/files/x.tar.gz', MainWP_Backup_Archive_Cipher::seal( 'secret bytes', $dek, 'snapshot:' . $a . ':whole:x.tar.gz' ) );

		// Lose the key after sealing.
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );

		$this->driver->remote_base = $base;
		$plan                      = array(
			array(
				'role'            => 'db',
				'file'            => 'x.tar.gz',
				'size'            => 12,
				'sha256'          => '',
				'snapshot_id'     => $a,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/' . $a . '/files/x.tar.gz',
				'expected_sha256' => '',
				'adopt_only'      => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 888001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertNotNull( $outcome['error'] );
		$this->assertStringContainsString( 'key is unavailable', (string) $outcome['error'] );
	}

	/**
	 * A whole-file object whose sealed bytes were tampered fails the restore
	 * decrypt guard rather than staging corrupt data. Flipping a byte of the
	 * GCM-sealed unit breaks authentication, so open() throws,
	 * maybe_decrypt_file returns false, and staging deletes the file and
	 * surfaces the decrypt error. The seal/open primitive has its own tamper
	 * test; this characterizes the guard across the real stage_from_remote
	 * path. It must pass against current code - a pass here means a tampered
	 * cloud object cannot restore.
	 *
	 * @return void
	 */
	public function test_tampered_whole_file_object_fails_restore() {
		$this->arm();

		$base    = $this->dir . '/dest';
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'tamper whole',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$src = $this->dir . '/src';
		mkdir( $src );
		$small = 'a small database dump';
		file_put_contents( $src . '/db.sql.gz', $small );

		$snapshot_id = $this->record(
			4,
			array(
				array(
					'file' => 'db.sql.gz',
					'size' => strlen( $small ),
					'role' => 'db',
					'path' => $src . '/db.sql.gz',
				),
			)
		);
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		// Prove the stored object is actually sealed before tampering: a
		// tampered *plaintext* object would trip the sha256 integrity guard
		// instead of the decrypt guard and green this test for the wrong
		// reason. Sealed size is plaintext + the fixed GCM overhead.
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertTrue( MainWP_Backup_Encryption::meta_is_encrypted( $snapshot->encryption ) );
		$object = $base . '/4/' . $snapshot_id . '/files/db.sql.gz';
		$this->assertSame( strlen( $small ) + MainWP_Backup_Archive_Cipher::OVERHEAD, filesize( $object ) );

		// Flip the first byte (the GCM nonce); any single-byte change to a
		// sealed unit fails authentication. XOR guarantees it differs.
		$sealed    = file_get_contents( $object );
		$sealed[0] = chr( ord( $sealed[0] ) ^ 0xFF );
		file_put_contents( $object, $sealed );

		$this->driver->remote_base = $base;
		$plan                      = array(
			array(
				'role'            => 'db',
				'file'            => 'db.sql.gz',
				'size'            => strlen( $small ),
				'sha256'          => '',
				'snapshot_id'     => $snapshot_id,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/' . $snapshot_id . '/files/db.sql.gz',
				'expected_sha256' => hash( 'sha256', $small ),
				'adopt_only'      => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 888001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertStringContainsString( 'could not be decrypted', (string) $outcome['error'] );
		// A tampered full-length object fails GCM authentication, which is by
		// design indistinguishable from a wrong key - the message must stay
		// hedged, never confidently blaming the key.
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_KEY, $outcome['error_code'] );
		$this->assertStringContainsString( 'may be wrong', (string) $outcome['error'] );
		// The decrypt guard deletes the staged file rather than leaving
		// corrupt bytes behind.
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 888001 ) . 'db.sql.gz' );
	}

	/**
	 * A sealed object truncated below the cipher's fixed framing overhead
	 * (28 bytes) fails staging with the corruption code, never the
	 * key-hedged one: unlike a full-length tamper or a wrong key, a
	 * truncation is genuinely distinguishable and is never a symptom of a
	 * wrong encryption key.
	 *
	 * @return void
	 */
	public function test_truncated_encrypted_object_reports_corruption_not_key() {
		$this->arm();
		$base = $this->dir . '/dest';

		$a = $this->record( 4, array() );
		mkdir( $base . '/4/' . $a . '/files', 0777, true );
		// Below MainWP_Backup_Archive_Cipher::OVERHEAD (28 bytes).
		file_put_contents( $base . '/4/' . $a . '/files/x.tar.gz', 'short' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$this->driver->remote_base = $base;
		$plan                      = array(
			array(
				'role'            => 'db',
				'file'            => 'x.tar.gz',
				'size'            => 5,
				'sha256'          => '',
				'snapshot_id'     => $a,
				'source'          => 'remote',
				'local_path'      => '',
				'remote_key'      => '4/' . $a . '/files/x.tar.gz',
				'expected_sha256' => '',
				'adopt_only'      => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 888001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_CORRUPT, $outcome['error_code'] );
		$this->assertStringNotContainsString( 'may be wrong', (string) $outcome['error'], 'A truncation is not a key problem and must not hedge on the key.' );
		$this->assertFileDoesNotExist( $this->driver->staging_dir( 888001 ) . 'x.tar.gz' );
	}

	/**
	 * A tampered chunk object fails the chunked restore decrypt guard. Each
	 * sealed chunk is decrypted before its content hash is checked, so
	 * flipping a byte of one chunk breaks authentication first and surfaces
	 * the decrypt error - the reassembled artifact is never staged. This
	 * characterizes the guard across the real stage_from_remote_chunked path
	 * and must pass against current code.
	 *
	 * @return void
	 */
	public function test_tampered_chunk_object_fails_restore() {
		$this->arm();

		$base    = $this->dir . '/dest';
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'tamper chunk',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$src = $this->dir . '/src';
		mkdir( $src );
		// Position-unique so the 1024-byte chunk filter yields several
		// distinct sealed chunks (no two dedupe to one object).
		$big = $this->position_unique( 'big', 3000 );
		file_put_contents( $src . '/big.tar.gz', $big );

		$snapshot_id = $this->record(
			4,
			array(
				array(
					'file' => 'big.tar.gz',
					'size' => 3000,
					'role' => 'part',
					'path' => $src . '/big.tar.gz',
				),
			)
		);
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		// Prove the artifact was both chunked and sealed before tampering.
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertTrue( MainWP_Backup_Encryption::meta_is_encrypted( $snapshot->encryption ) );
		$chunks = glob( $base . '/4/' . $snapshot_id . '/chunks/*.bin' );
		$this->assertGreaterThanOrEqual( 2, count( (array) $chunks ) );

		// Flip the first byte of one sealed chunk; never touch manifest.json.
		$victim    = $chunks[0];
		$sealed    = file_get_contents( $victim );
		$sealed[0] = chr( ord( $sealed[0] ) ^ 0xFF );
		file_put_contents( $victim, $sealed );

		$this->driver->remote_base = $base;
		// No 'chunks' key, so fetch_manifest runs and reads the real manifest.
		$plan = array(
			array(
				'role'            => 'part',
				'file'            => 'big.tar.gz',
				'size'            => 3000,
				'sha256'          => '',
				'snapshot_id'     => $snapshot_id,
				'source'          => 'remote_chunked',
				'local_path'      => '',
				'remote_key'      => '',
				'expected_sha256' => hash( 'sha256', $big ),
				'adopt_only'      => false,
			),
		);

		$outcome = $this->driver->stage_artifacts( 888001, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		$this->assertStringContainsString( 'could not be decrypted', (string) $outcome['error'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_KEY, $outcome['error_code'] );
		$this->assertStringContainsString( 'may be wrong', (string) $outcome['error'] );
	}
}
