<?php
/**
 * Test-restore (Level-5 verified restore) driver tests.
 *
 * The driver reuses the real resolve -> stage pipeline to land decrypted,
 * hash-verified artifacts, then applies them into a throwaway sandbox - a
 * scratch database and a temp directory that never touch the live site. A
 * good snapshot passes its assertions and records a pass on the snapshot row;
 * an encrypted snapshot proves the staged-decrypt path end to end; a corrupt
 * dump records a fail without disturbing live data; and the sandbox is always
 * torn down, on pass and on fail alike.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Local;
use MainWP\Dashboard\MainWP_Backup_TestRestore_Driver;
use MainWP\Dashboard\MainWP_Backup_TestRestore_Sandbox;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Restore transfer driver that serves remote objects from a pinned base path,
 * mirroring the seam the decryption tests use.
 */
class TestRestore_Remote_Transfer_Driver extends MainWP_Backup_Restore_Transfer_Driver {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

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
 * Transfer driver whose staging never finishes within budget: it returns
 * the neutral not-done outcome (done=false, error=null) the real driver
 * produces at a mid-flight deadline or a pending chunk reassembly, so the
 * caller's handling of an unfinished-but-not-failed staging can be exercised.
 */
class TestRestore_Incomplete_Staging_Driver extends MainWP_Backup_Restore_Transfer_Driver {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Never finish staging, and report no error (a budget miss, not a failure).
	 *
	 * @param int   $job_id   Staging job id.
	 * @param array $plan     Plan entries (by reference).
	 * @param float $deadline Slice deadline.
	 *
	 * @return array
	 */
	public function stage_artifacts( int $job_id, array &$plan, float $deadline ): array {
		return array(
			'done'  => false,
			'error' => null,
		);
	}
}

/**
 * Test-restore driver with the transfer driver pinned, so an encrypted
 * snapshot can be staged from a local stand-in for cloud storage.
 */
class Testable_TestRestore_Driver extends MainWP_Backup_TestRestore_Driver {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double above.

	/**
	 * Pinned transfer driver, or null for the real one.
	 *
	 * @var MainWP_Backup_Restore_Transfer_Driver|null
	 */
	public $driver = null;

	/**
	 * Pinned driver seam.
	 *
	 * @return MainWP_Backup_Restore_Transfer_Driver
	 */
	protected function transfer_driver(): MainWP_Backup_Restore_Transfer_Driver {
		return null !== $this->driver ? $this->driver : parent::transfer_driver();
	}
}

/**
 * Class MainWP_Backup_TestRestore_Driver_Test
 */
class MainWP_Backup_TestRestore_Driver_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Scratch dir for fixtures.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Test site id.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Recorded snapshot ids to clean up.
	 *
	 * @var int[]
	 */
	private $snapshot_ids = array();

	/**
	 * Set up site and scratch dir.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->site_id = $this->create_test_site();
		$this->dir     = sys_get_temp_dir() . '/mainwp-testrestore-' . uniqid();
		mkdir( $this->dir );
	}

	/**
	 * Drop recorded snapshots, clean scratch, clear encryption state.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		if ( ! empty( $this->snapshot_ids ) ) {
			$ids = implode( ',', array_map( 'intval', $this->snapshot_ids ) );
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . " WHERE id IN ( {$ids} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		}
		$this->rrmdir( $this->dir );

		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );
		delete_option( 'mainwp_testrestore_canary' );

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
		$enc->set_recovery_passphrase( 'test restore passphrase' );
		return $enc;
	}

	/**
	 * Whether a database exists on the server.
	 *
	 * @param string $name Database name.
	 *
	 * @return bool
	 */
	private function db_exists( $name ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW DATABASES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- server-level existence probe.
		return null !== $found;
	}

	/**
	 * Write a gzipped SQL dump fixture and return its path.
	 *
	 * @param string $name Basename (e.g. db.sql.gz).
	 * @param string $sql  SQL body.
	 *
	 * @return string Absolute path.
	 */
	private function write_db_dump( $name, $sql ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, gzencode( $sql ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		return $path;
	}

	/**
	 * Build a gzipped tar containing a single sentinel file and return its path.
	 *
	 * @param string $name     Basename (e.g. files.tar.gz).
	 * @param string $relative Path inside the archive.
	 * @param string $content  Sentinel content.
	 *
	 * @return string Absolute path to the .tar.gz.
	 */
	private function write_files_archive( $name, $relative, $content ) {
		$tar  = $this->dir . '/' . preg_replace( '/\.gz$/', '', $name );
		$phar = new \PharData( $tar );
		$phar->addFromString( $relative, $content );
		$phar->compress( \Phar::GZ );
		unset( $phar );
		\Phar::unlinkArchive( $tar );
		return $this->dir . '/' . $name;
	}

	/**
	 * A large but highly compressible SQL body: the sentinel plus a run of
	 * identical comment lines. It compresses to a few KB yet decompresses to
	 * about a megabyte, so a cap set between the two proves the guard measures
	 * the decompressed size, not the on-disk footprint.
	 *
	 * @return string
	 */
	private function inflated_sql() {
		return $this->sentinel_sql()
			. str_repeat( "-- filler comment line kept identical so it compresses to almost nothing\n", 15000 );
	}

	/**
	 * Write a bzip2-compressed SQL dump fixture and return its path.
	 *
	 * @param string $name Basename (e.g. db.sql.bz2).
	 * @param string $sql  SQL body.
	 *
	 * @return string Absolute path.
	 */
	private function write_bz2_dump( $name, $sql ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, bzcompress( $sql ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		return $path;
	}

	/**
	 * Write a zip carrying a single db.sql member and return the zip path.
	 *
	 * @param string $name Basename (e.g. db.sql.zip).
	 * @param string $sql  SQL body.
	 *
	 * @return string Absolute path.
	 */
	private function write_zip_dump( $name, $sql ) {
		$path = $this->dir . '/' . $name;
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( 'db.sql', $sql );
		$zip->close();
		return $path;
	}

	/**
	 * Write a gzipped tar carrying a single db.sql member and return its path.
	 *
	 * @param string $name Basename (e.g. db.sql.tar.gz).
	 * @param string $sql  SQL body.
	 *
	 * @return string Absolute path.
	 */
	private function write_tar_dump( $name, $sql ) {
		$tar  = $this->dir . '/' . preg_replace( '/\.gz$/', '', $name );
		$phar = new \PharData( $tar );
		$phar->addFromString( 'db.sql', $sql );
		$phar->compress( \Phar::GZ );
		unset( $phar );
		\Phar::unlinkArchive( $tar );
		return $this->dir . '/' . $name;
	}

	/**
	 * A sentinel SQL body that creates a table with two rows.
	 *
	 * @return string
	 */
	private function sentinel_sql() {
		return "CREATE TABLE wp_tr_sentinel (\n"
			. "  id INT NOT NULL,\n"
			. "  name VARCHAR(50) NOT NULL,\n"
			. "  PRIMARY KEY (id)\n"
			. ");\n"
			. "INSERT INTO wp_tr_sentinel (id, name) VALUES (1, 'alpha'), (2, 'beta');\n";
	}

	/**
	 * Record a snapshot over the given archives and remember it for cleanup.
	 *
	 * @param string $type     Snapshot type.
	 * @param array  $archives Archive entries.
	 *
	 * @return int Snapshot id.
	 */
	private function record( $type, array $archives ) {
		$id                   = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => $this->site_id,
				'type'          => $type,
				'parent_id'     => null,
				'archives'      => $archives,
				'manifest_path' => '',
			)
		);
		$this->snapshot_ids[] = $id;
		return $id;
	}

	/**
	 * A local full snapshot stages from disk, imports its dump into a scratch
	 * database, extracts its files into a temp dir, passes the assertions, and
	 * records a pass with a timestamp on the snapshot row.
	 *
	 * @return void
	 */
	public function test_local_full_snapshot_passes_and_records() {
		$dump    = $this->write_db_dump( 'db.sql.gz', $this->sentinel_sql() );
		$archive = $this->write_files_archive( 'files.tar.gz', 'wp-content/uploads/sentinel.txt', 'sentinel-bytes' );

		$snapshot_id = $this->record(
			'full',
			array(
				array(
					'file' => 'files.tar.gz',
					'size' => filesize( $archive ),
					'role' => 'full',
					'path' => $archive,
				),
				array(
					'file' => 'db.sql.gz',
					'size' => filesize( $dump ),
					'role' => 'db',
					'path' => $dump,
				),
			)
		);

		$result = MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $snapshot_id );

		$this->assertSame( 'pass', $result['state'], 'A good local snapshot must pass: ' . (string) $result['error'] );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertSame( 'pass', (string) $snapshot->test_restore_state, 'The pass result must be recorded on the snapshot.' );
		$this->assertGreaterThan( 0, (int) $snapshot->test_restore_at, 'The test-restore time must be recorded.' );

		$this->assertFalse( $this->db_exists( $result['sandbox']['db_name'] ), 'The scratch database must be dropped.' );
		$this->assertDirectoryDoesNotExist( $result['sandbox']['temp_dir'], 'The sandbox temp dir must be removed.' );
	}

	/**
	 * An encrypted snapshot uploaded through the real seal path test-restores
	 * through the resolve -> stage decrypt pipeline: the sandbox imports the
	 * decrypted dump, proving the AAD round-trip holds at this layer too.
	 *
	 * @return void
	 */
	public function test_encrypted_snapshot_test_restores_via_staged_decrypt() {
		$this->arm();

		$base    = $this->dir . '/dest';
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'test restore enc',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$dump = $this->write_db_dump( 'db.sql.gz', $this->sentinel_sql() );

		$snapshot_id = $this->record(
			'db',
			array(
				array(
					'file' => 'db.sql.gz',
					'size' => filesize( $dump ),
					'role' => 'db',
					'path' => $dump,
				),
			)
		);

		\MainWP\Dashboard\MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		\MainWP\Dashboard\MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		// Drop the local source so resolve picks the remote, staged-decrypt path.
		unlink( $dump );

		$driver                = new Testable_TestRestore_Driver();
		$transfer              = new TestRestore_Remote_Transfer_Driver();
		$transfer->remote_base = $base;
		$driver->driver        = $transfer;

		$result = $driver->verify_snapshot( $snapshot_id );

		$this->assertSame( 'pass', $result['state'], 'An encrypted snapshot must test-restore through the staged-decrypt path: ' . (string) $result['error'] );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertSame( 'pass', (string) $snapshot->test_restore_state, 'The pass result must be recorded.' );

		$this->assertFalse( $this->db_exists( $result['sandbox']['db_name'] ), 'The scratch database must be dropped.' );
	}

	/**
	 * A corrupt dump fails the assertions and records a fail, and the live
	 * database is untouched: a canary option set before the run survives.
	 *
	 * @return void
	 */
	public function test_corrupt_artifact_fails_without_touching_live() {
		update_option( 'mainwp_testrestore_canary', 'intact' );

		$dump = $this->write_db_dump( 'db.sql.gz', "CREATE TABLE oops ( this is not valid sql ;;;\n" );

		$snapshot_id = $this->record(
			'db',
			array(
				array(
					'file' => 'db.sql.gz',
					'size' => filesize( $dump ),
					'role' => 'db',
					'path' => $dump,
				),
			)
		);

		$result = MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $snapshot_id );

		$this->assertSame( 'fail', $result['state'], 'A corrupt dump must fail.' );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertSame( 'fail', (string) $snapshot->test_restore_state, 'The fail result must be recorded.' );

		$this->assertSame( 'intact', get_option( 'mainwp_testrestore_canary' ), 'The live database must be untouched by a failed test-restore.' );
		$this->assertFalse( $this->db_exists( $result['sandbox']['db_name'] ), 'The scratch database must be dropped even on failure.' );
		$this->assertDirectoryDoesNotExist( $result['sandbox']['temp_dir'], 'The sandbox temp dir must be removed even on failure.' );
	}

	/**
	 * A dump whose decompressed size exceeds the configured cap is refused
	 * before it is loaded into memory: the run records unsupported (an
	 * environment limitation, not a bad backup), and the scratch resources
	 * are gone afterward same as any other run.
	 *
	 * @return void
	 */
	public function test_oversized_dump_refuses_and_records_unsupported() {
		$cap = static function () {
			return 10; // Below the tiny sentinel fixture's decompressed size.
		};
		add_filter( 'mainwp_testrestore_max_sql_dump_bytes', $cap );

		$dump        = $this->write_db_dump( 'db.sql.gz', $this->sentinel_sql() );
		$snapshot_id = $this->record(
			'db',
			array(
				array(
					'file' => 'db.sql.gz',
					'size' => filesize( $dump ),
					'role' => 'db',
					'path' => $dump,
				),
			)
		);

		$result = MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $snapshot_id );

		remove_filter( 'mainwp_testrestore_max_sql_dump_bytes', $cap );

		$this->assertSame( 'unsupported', $result['state'], 'A dump over the cap must record unsupported, not fail.' );
		$this->assertSame( MainWP_Backup_TestRestore_Driver::ERR_DUMP_TOO_LARGE, $result['error_code'] );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertSame( 'unsupported', (string) $snapshot->test_restore_state, 'The unsupported result must be recorded on the snapshot.' );

		$this->assertFalse( $this->db_exists( $result['sandbox']['db_name'] ), 'The scratch database must not linger after a refusal.' );
		$this->assertDirectoryDoesNotExist( $result['sandbox']['temp_dir'], 'The sandbox temp dir must not linger after a refusal.' );
	}

	/**
	 * The cap covers every compressed format the sandbox reads, not just gzip.
	 * A bzip2, zip, or tar dump whose compressed footprint sits under the cap
	 * but whose decompressed body sits over it must still be refused before
	 * import_database() buffers it, or the cap is trivially bypassable.
	 *
	 * @dataProvider oversized_compressed_dump_provider
	 *
	 * @param string $writer Fixture writer method name.
	 * @param string $name   Dump basename with its real extension.
	 *
	 * @return void
	 */
	public function test_oversized_compressed_dump_refuses_by_decompressed_size( $writer, $name ) {
		// The window that discriminates decompressed-size measurement from the
		// on-disk footprint: well above the few-KB compressed fixture, well
		// below its ~1MB decompressed body.
		$cap = static function () {
			return 256 * 1024;
		};
		add_filter( 'mainwp_testrestore_max_sql_dump_bytes', $cap );

		$dump = $this->{$writer}( $name, $this->inflated_sql() );
		$this->assertLessThan( 256 * 1024, filesize( $dump ), 'The compressed fixture must sit under the cap, so only the decompressed size can trip it.' );

		$snapshot_id = $this->record(
			'db',
			array(
				array(
					'file' => $name,
					'size' => filesize( $dump ),
					'role' => 'db',
					'path' => $dump,
				),
			)
		);

		$result = MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $snapshot_id );

		remove_filter( 'mainwp_testrestore_max_sql_dump_bytes', $cap );

		$this->assertSame( 'unsupported', $result['state'], $name . ' over the cap must record unsupported, not import.' );
		$this->assertSame( MainWP_Backup_TestRestore_Driver::ERR_DUMP_TOO_LARGE, $result['error_code'] );
		$this->assertFalse( $this->db_exists( $result['sandbox']['db_name'] ), 'The scratch database must not linger after a refusal.' );
	}

	/**
	 * Fixtures for the compressed-format cap: writer method and basename whose
	 * real extension routes it down each of read_sql_dump's decode paths.
	 *
	 * @return array[]
	 */
	public function oversized_compressed_dump_provider() {
		return array(
			'bzip2' => array( 'write_bz2_dump', 'db.sql.bz2' ),
			'zip'   => array( 'write_zip_dump', 'db.sql.zip' ),
			'tar'   => array( 'write_tar_dump', 'db.sql.tar.gz' ),
		);
	}

	/**
	 * The gzip decompressed-size measurement counts the true body size from the
	 * decompressed stream, not the much smaller compressed footprint and not the
	 * 32-bit ISIZE trailer: a few-KB gzip of a ~1MB body must measure the ~1MB,
	 * so the cap sees what import_database() would actually buffer. With the
	 * ceiling out of reach the count runs to the end and equals the whole body.
	 *
	 * @return void
	 */
	public function test_gzip_decompressed_size_reads_true_body_size() {
		$sql  = $this->inflated_sql();
		$path = $this->dir . '/body.sql.gz';
		file_put_contents( $path, gzencode( $sql ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$this->assertLessThan( strlen( $sql ), filesize( $path ), 'The fixture must compress well, so the on-disk size is not the body size.' );

		$size = ( new MainWP_Backup_TestRestore_Sandbox() )->decompressed_sql_dump_bytes( $path, PHP_INT_MAX );

		$this->assertSame( strlen( $sql ), $size, 'Counting the decompressed stream must report the true body size.' );
	}

	/**
	 * A highly compressible gzip whose decompressed body runs past the ceiling
	 * is measured by counting the stream, not by trusting the 32-bit ISIZE
	 * trailer. ISIZE wraps modulo 2^32 for bodies over 4 GB and would read far
	 * too small, so a multi-gigabyte dump compressed to a few megabytes used to
	 * slip under the cap and hit gzdecode() whole. Counting stops the moment the
	 * running total passes the ceiling, so the full body is never buffered: the
	 * measurement reads over the ceiling yet well under the whole decompressed
	 * body, proving the count short-circuited instead of inflating everything.
	 *
	 * @return void
	 */
	public function test_gzip_over_ceiling_counts_stream_without_buffering_body() {
		// A body far larger than one read chunk so an early stop is unambiguous:
		// millions of identical lines compress to a few KB yet inflate to ~14MB.
		$sql  = $this->sentinel_sql() . str_repeat( "-- identical filler line that compresses to almost nothing\n", 240000 );
		$path = $this->dir . '/wrapped.sql.gz';
		file_put_contents( $path, gzencode( $sql ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$ceiling = 256 * 1024;
		$this->assertLessThan( $ceiling, filesize( $path ), 'The compressed fixture must sit under the ceiling, so only the counted body can trip it.' );
		$this->assertGreaterThan( 4 * 1024 * 1024, strlen( $sql ), 'The body must exceed several read chunks so a short-circuited count is provably smaller than the whole body.' );

		$size = ( new MainWP_Backup_TestRestore_Sandbox() )->decompressed_sql_dump_bytes( $path, $ceiling );

		$this->assertGreaterThan( $ceiling, $size, 'A body over the ceiling must read as over it.' );
		$this->assertLessThan( strlen( $sql ), $size, 'The count must stop past the ceiling without buffering the whole decompressed body.' );
	}

	/**
	 * A gzip whose compressed footprint already exceeds the cap is refused on
	 * that alone, without trusting the ISIZE footer: for a multi-GB dump the
	 * 32-bit ISIZE has wrapped and would read too small.
	 *
	 * @return void
	 */
	public function test_gzip_compressed_over_ceiling_reads_as_over_ceiling() {
		$path = $this->dir . '/dense.sql.gz';
		file_put_contents( $path, gzencode( $this->sentinel_sql() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$ceiling = 4; // Below even the compressed footprint.
		$size    = ( new MainWP_Backup_TestRestore_Sandbox() )->decompressed_sql_dump_bytes( $path, $ceiling );

		$this->assertGreaterThan( $ceiling, $size, 'A gzip already larger than the ceiling must read as over it.' );
	}

	/**
	 * A deadline that has already passed by the time staging finishes stops
	 * the run before the sandbox applies the plan: no terminal state is
	 * recorded, so the caller's own backoff (not a mislabeled fail) is what
	 * lands on the snapshot.
	 *
	 * @return void
	 */
	public function test_deadline_expired_after_staging_returns_non_terminal_outcome() {
		$dump        = $this->write_db_dump( 'db.sql.gz', $this->sentinel_sql() );
		$snapshot_id = $this->record(
			'db',
			array(
				array(
					'file' => 'db.sql.gz',
					'size' => filesize( $dump ),
					'role' => 'db',
					'path' => $dump,
				),
			)
		);

		$result = MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $snapshot_id, array( 'deadline' => microtime( true ) - 1 ) );

		$this->assertSame( MainWP_Backup_TestRestore_Driver::ERR_DEADLINE_EXCEEDED, $result['error_code'], 'An already-expired deadline must report the deadline-exceeded error code.' );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertSame( '', (string) $snapshot->test_restore_state, 'A deadline miss must not record a terminal state; the caller stamps its own backoff.' );

		$this->assertFalse( $this->db_exists( $result['sandbox']['db_name'] ), 'The scratch database must not linger after a deadline miss.' );
		$this->assertDirectoryDoesNotExist( $result['sandbox']['temp_dir'], 'The sandbox temp dir must not linger after a deadline miss.' );
	}

	/**
	 * Staging that runs out of budget mid-flight returns a neutral not-done
	 * outcome (done=false, no error), not a failure. The driver must report a
	 * deadline miss the scheduler re-samples later, never a failed test-restore
	 * recorded against a healthy snapshot.
	 *
	 * @return void
	 */
	public function test_incomplete_staging_reports_deadline_not_failure() {
		$dump        = $this->write_db_dump( 'db.sql.gz', $this->sentinel_sql() );
		$snapshot_id = $this->record(
			'db',
			array(
				array(
					'file' => 'db.sql.gz',
					'size' => filesize( $dump ),
					'role' => 'db',
					'path' => $dump,
				),
			)
		);

		$driver         = new Testable_TestRestore_Driver();
		$driver->driver = new TestRestore_Incomplete_Staging_Driver();

		$result = $driver->verify_snapshot( $snapshot_id );

		// The scheduler keys off the deadline-exceeded code (which it treats as
		// unavailable, not failed) and re-samples later; the mislabeled
		// staging-failed code and a recorded fail on a healthy snapshot are the
		// regression this guards against.
		$this->assertSame( MainWP_Backup_TestRestore_Driver::ERR_DEADLINE_EXCEEDED, $result['error_code'], 'Staging that ran out of budget must report the deadline-exceeded code, not a staging failure.' );
		$this->assertNotSame( 'mainwp_testrestore_staging_failed', $result['error_code'], 'A budget miss must not be mislabeled as a staging failure.' );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertSame( '', (string) $snapshot->test_restore_state, 'A staging budget miss must not record a terminal state on a healthy snapshot.' );
	}

	/**
	 * A generous deadline does not regress the happy path: staging and the
	 * sandbox apply both have ample budget and the run still passes.
	 *
	 * @return void
	 */
	public function test_verify_with_generous_deadline_still_passes() {
		$dump        = $this->write_db_dump( 'db.sql.gz', $this->sentinel_sql() );
		$snapshot_id = $this->record(
			'db',
			array(
				array(
					'file' => 'db.sql.gz',
					'size' => filesize( $dump ),
					'role' => 'db',
					'path' => $dump,
				),
			)
		);

		$result = MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $snapshot_id, array( 'deadline' => microtime( true ) + 300 ) );

		$this->assertSame( 'pass', $result['state'], 'A generous deadline must not regress the happy path: ' . (string) $result['error'] );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertSame( 'pass', (string) $snapshot->test_restore_state, 'The pass result must still be recorded with a deadline opt supplied.' );
	}

	/**
	 * The sandbox is fully torn down on both a passing and a failing run: no
	 * scratch database and no temp dir linger either way.
	 *
	 * @return void
	 */
	public function test_sandbox_torn_down_on_pass_and_fail() {
		// Pass run.
		$dump_ok = $this->write_db_dump( 'ok.sql.gz', $this->sentinel_sql() );
		$pass_id = $this->record(
			'db',
			array(
				array(
					'file' => 'ok.sql.gz',
					'size' => filesize( $dump_ok ),
					'role' => 'db',
					'path' => $dump_ok,
				),
			)
		);
		$pass    = MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $pass_id );
		$this->assertSame( 'pass', $pass['state'] );
		$this->assertFalse( $this->db_exists( $pass['sandbox']['db_name'] ) );
		$this->assertDirectoryDoesNotExist( $pass['sandbox']['temp_dir'] );

		// Fail run.
		$dump_bad = $this->write_db_dump( 'bad.sql.gz', "NOT SQL AT ALL ;;;\n" );
		$fail_id  = $this->record(
			'db',
			array(
				array(
					'file' => 'bad.sql.gz',
					'size' => filesize( $dump_bad ),
					'role' => 'db',
					'path' => $dump_bad,
				),
			)
		);
		$fail     = MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $fail_id );
		$this->assertSame( 'fail', $fail['state'] );
		$this->assertFalse( $this->db_exists( $fail['sandbox']['db_name'] ) );
		$this->assertDirectoryDoesNotExist( $fail['sandbox']['temp_dir'] );
	}
}
