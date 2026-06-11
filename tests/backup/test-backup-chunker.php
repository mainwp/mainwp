<?php
/**
 * MainWP Backup Chunker Tests
 *
 * Verifies the content-addressed chunk planner: artifacts split into
 * fixed-size chunks, each hashed and staged under its hash with an
 * atomic tmp-then-rename write, so an interrupted upload can resume
 * from verified chunks instead of restarting. The chunk filename is
 * the expected hash, never proof of content, so re-entry must verify
 * before reusing a staged chunk.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Chunker;

/**
 * Class MainWP_Backup_Chunker_Test
 */
class MainWP_Backup_Chunker_Test extends \WP_UnitTestCase {

	/**
	 * Temp dirs created during a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	/**
	 * Remove temp fixtures.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->temp_dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->temp_dirs = array();
		parent::tear_down();
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 *
	 * @return void
	 */
	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Create a temp working dir.
	 *
	 * @return string
	 */
	private function make_temp_dir() {
		$dir = get_temp_dir() . 'mainwp-chunker-test-' . uniqid();
		mkdir( $dir, 0755, true );
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Write a deterministic fixture file of a given size.
	 *
	 * @param string $dir  Directory to write into.
	 * @param int    $size Byte length.
	 *
	 * @return string File path.
	 */
	private function make_fixture_file( $dir, $size ) {
		$path    = $dir . DIRECTORY_SEPARATOR . 'artifact.bin';
		$pattern = '0123456789ABCDEF';
		$content = substr( str_repeat( $pattern, (int) ceil( $size / strlen( $pattern ) ) ), 0, $size );
		file_put_contents( $path, $content );
		return $path;
	}

	/**
	 * An artifact splits into ordered chunks with correct offsets,
	 * lengths, and hashes, and each chunk's bytes are staged under its
	 * hash.
	 *
	 * @return void
	 */
	public function test_plan_artifact_splits_file_into_hashed_chunks() {
		$dir     = $this->make_temp_dir();
		$staging = $dir . DIRECTORY_SEPARATOR . 'staging';
		$path    = $this->make_fixture_file( $dir, 2500 );

		$chunks = MainWP_Backup_Chunker::plan_artifact( $path, $staging, 1000 );

		$this->assertIsArray( $chunks );
		$this->assertCount( 3, $chunks );

		$content = file_get_contents( $path );
		$algo    = MainWP_Backup_Chunker::hash_algo();
		$offset  = 0;
		foreach ( array( 1000, 1000, 500 ) as $i => $expected_length ) {
			$slice = substr( $content, $offset, $expected_length );
			$this->assertSame( $offset, $chunks[ $i ]['offset'], "Chunk {$i} offset." );
			$this->assertSame( $expected_length, $chunks[ $i ]['length'], "Chunk {$i} length." );
			$this->assertSame( $algo, $chunks[ $i ]['algo'], "Chunk {$i} algo." );
			$this->assertSame( hash( $algo, $slice ), $chunks[ $i ]['hash'], "Chunk {$i} hash." );

			$staged = $staging . DIRECTORY_SEPARATOR . $chunks[ $i ]['hash'] . '.bin';
			$this->assertFileExists( $staged, "Chunk {$i} staged file." );
			$this->assertSame( $slice, file_get_contents( $staged ), "Chunk {$i} staged bytes." );

			$offset += $expected_length;
		}
	}

	/**
	 * No .tmp leftovers remain after planning; only final chunk names.
	 *
	 * @return void
	 */
	public function test_plan_artifact_leaves_no_tmp_files() {
		$dir     = $this->make_temp_dir();
		$staging = $dir . DIRECTORY_SEPARATOR . 'staging';
		$path    = $this->make_fixture_file( $dir, 2500 );

		MainWP_Backup_Chunker::plan_artifact( $path, $staging, 1000 );

		$leftovers = glob( $staging . DIRECTORY_SEPARATOR . '*.tmp*' );
		$this->assertSame( array(), $leftovers, 'Atomic rename must leave no tmp files.' );
	}

	/**
	 * A staged chunk left torn (short bytes under the right name) by a
	 * crashed prior pass is rewritten on re-entry.
	 *
	 * @return void
	 */
	public function test_torn_staged_chunk_is_rewritten_on_reentry() {
		$dir     = $this->make_temp_dir();
		$staging = $dir . DIRECTORY_SEPARATOR . 'staging';
		$path    = $this->make_fixture_file( $dir, 2500 );

		$chunks = MainWP_Backup_Chunker::plan_artifact( $path, $staging, 1000 );
		$staged = $staging . DIRECTORY_SEPARATOR . $chunks[0]['hash'] . '.bin';

		// Simulate a torn write: right name, short bytes.
		file_put_contents( $staged, substr( file_get_contents( $staged ), 0, 123 ) );

		$replan = MainWP_Backup_Chunker::plan_artifact( $path, $staging, 1000 );

		$this->assertSame( $chunks, $replan, 'Re-entry must produce the identical plan.' );
		$this->assertSame( 1000, strlen( file_get_contents( $staged ) ), 'Torn chunk must be rewritten with complete bytes.' );
	}

	/**
	 * An intact staged chunk is reused on re-entry, not rewritten.
	 *
	 * @return void
	 */
	public function test_intact_staged_chunk_is_reused_on_reentry() {
		$dir     = $this->make_temp_dir();
		$staging = $dir . DIRECTORY_SEPARATOR . 'staging';
		$path    = $this->make_fixture_file( $dir, 2500 );

		$chunks = MainWP_Backup_Chunker::plan_artifact( $path, $staging, 1000 );
		$staged = $staging . DIRECTORY_SEPARATOR . $chunks[0]['hash'] . '.bin';

		// Backdate the staged chunk; a rewrite would refresh its mtime.
		$past = time() - 3600;
		touch( $staged, $past );
		clearstatcache();

		$replan = MainWP_Backup_Chunker::plan_artifact( $path, $staging, 1000 );

		clearstatcache();
		$this->assertSame( $chunks, $replan );
		$this->assertSame( $past, filemtime( $staged ), 'Intact staged chunk must be reused, not rewritten.' );
	}

	/**
	 * Files under the small-file threshold skip chunking entirely.
	 *
	 * @return void
	 */
	public function test_small_file_threshold_decides_chunking() {
		$dir   = $this->make_temp_dir();
		$small = $dir . DIRECTORY_SEPARATOR . 'small.txt';
		file_put_contents( $small, str_repeat( 'a', 100 ) );

		$threshold = MainWP_Backup_Chunker::small_file_threshold();
		$this->assertSame( 512 * 1024, $threshold );

		$this->assertFalse( MainWP_Backup_Chunker::should_chunk( $small ) );

		$large = $this->make_fixture_file( $dir, $threshold );
		$this->assertTrue( MainWP_Backup_Chunker::should_chunk( $large ) );
	}

	/**
	 * The hash algorithm is feature-detected: xxh128 where available,
	 * sha256 otherwise, and always present in hash_algos().
	 *
	 * @return void
	 */
	public function test_hash_algo_feature_detection() {
		$algo = MainWP_Backup_Chunker::hash_algo();

		$this->assertContains( $algo, array( 'xxh128', 'sha256' ) );
		$this->assertContains( $algo, hash_algos(), 'Selected algorithm must exist on this PHP.' );

		if ( in_array( 'xxh128', hash_algos(), true ) ) {
			$this->assertSame( 'xxh128', $algo, 'xxh128 must be preferred where available.' );
		}
	}

	/**
	 * The default chunk size is 4 MiB.
	 *
	 * @return void
	 */
	public function test_default_chunk_size() {
		$this->assertSame( 4 * 1024 * 1024, MainWP_Backup_Chunker::chunk_size() );
	}

	/**
	 * A missing source file fails closed.
	 *
	 * @return void
	 */
	public function test_missing_file_returns_false() {
		$dir = $this->make_temp_dir();

		$this->assertFalse( MainWP_Backup_Chunker::plan_artifact( $dir . '/nope.bin', $dir . '/staging', 1000 ) );
	}

	/**
	 * Identical content across chunks stages one file per distinct hash.
	 *
	 * @return void
	 */
	public function test_duplicate_chunks_share_one_staged_file() {
		$dir     = $this->make_temp_dir();
		$staging = $dir . DIRECTORY_SEPARATOR . 'staging';
		$path    = $dir . DIRECTORY_SEPARATOR . 'artifact.bin';
		file_put_contents( $path, str_repeat( 'z', 3000 ) );

		$chunks = MainWP_Backup_Chunker::plan_artifact( $path, $staging, 1000 );

		$this->assertCount( 3, $chunks );
		$this->assertSame( $chunks[0]['hash'], $chunks[1]['hash'] );
		$staged = glob( $staging . DIRECTORY_SEPARATOR . '*.bin' );
		$this->assertCount( 1, $staged, 'Identical chunks must dedupe to one staged file.' );
	}
}
