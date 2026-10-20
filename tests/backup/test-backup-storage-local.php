<?php
/**
 * MainWP Backup Storage Local Provider Tests
 *
 * Verifies the Local provider over a configured base path: objects write
 * atomically under nested keys, existence checks report sizes, downloads
 * copy bytes back out, deletes are idempotent, prefix listing walks the
 * tree, and the connection test proves the path is writable.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_Local;

/**
 * Class MainWP_Backup_Storage_Local_Test
 */
class MainWP_Backup_Storage_Local_Test extends \WP_UnitTestCase {

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
		if ( is_link( $dir ) ) {
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * A fixture symlink must not give cleanup access to another directory's files.
	 *
	 * @return void
	 */
	public function test_remove_dir_preserves_symlink_target() {
		$dir    = get_temp_dir() . 'mainwp-cleanup-link-' . uniqid();
		$target = get_temp_dir() . 'mainwp-cleanup-target-' . uniqid();
		mkdir( $dir, 0755, true );
		mkdir( $target, 0755, true );
		$sentinel = $target . '/sentinel.txt';
		$link     = $dir . '/linked-dir';
		file_put_contents( $sentinel, 'keep' );
		try {
			$this->assertTrue( symlink( $target, $link ) );
			$this->remove_dir( $dir );
			$this->assertFileExists( $sentinel );
			$this->assertSame( 'keep', file_get_contents( $sentinel ) );
			$this->assertDirectoryDoesNotExist( $dir );
		} finally {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
			if ( file_exists( $sentinel ) ) {
				unlink( $sentinel );
			}
			rmdir( $target );
		}
	}

	/**
	 * A provider over a fresh temp base path.
	 *
	 * @return MainWP_Backup_Storage_Local
	 */
	private function make_provider() {
		$dir = get_temp_dir() . 'mainwp-storage-local-' . uniqid();
		mkdir( $dir, 0755, true );
		$this->temp_dirs[] = $dir;
		return new MainWP_Backup_Storage_Local( array( 'base_path' => $dir ) );
	}

	/**
	 * Put then head then get round-trips an object under a nested key.
	 *
	 * @return void
	 */
	public function test_put_head_get_round_trip() {
		$provider = $this->make_provider();
		$body     = str_repeat( 'chunk-bytes', 100 );

		$this->assertTrue( $provider->put_object( '4/42/chunks/abc123.bin', $body ) );

		$head = $provider->head_object( '4/42/chunks/abc123.bin' );
		$this->assertIsArray( $head );
		$this->assertSame( strlen( $body ), $head['size'] );

		$target = $this->temp_dirs[0] . DIRECTORY_SEPARATOR . 'downloaded.bin';
		$this->assertTrue( $provider->get_object( '4/42/chunks/abc123.bin', $target ) );
		$this->assertSame( $body, file_get_contents( $target ) );
	}

	/**
	 * Heading a missing object reports absence, not an error.
	 *
	 * @return void
	 */
	public function test_head_missing_object_returns_false() {
		$this->assertFalse( $this->make_provider()->head_object( 'no/such/key.bin' ) );
	}

	/**
	 * Deletes remove the object and are idempotent.
	 *
	 * @return void
	 */
	public function test_delete_is_idempotent() {
		$provider = $this->make_provider();
		$provider->put_object( 'a/b.bin', 'x' );

		$this->assertTrue( $provider->delete_object( 'a/b.bin' ) );
		$this->assertFalse( $provider->head_object( 'a/b.bin' ) );
		$this->assertTrue( $provider->delete_object( 'a/b.bin' ), 'Deleting a missing object must succeed.' );
	}

	/** Shared site folders must survive cleanup of a snapshot's private folders. */
	public function test_delete_removes_empty_snapshot_directories() {
		$provider = $this->make_provider();
		$base     = $this->temp_dirs[0];
		$keys     = array( '4/42/chunks/a.bin', '4/42/chunks/b.bin', '4/42/files/archive.zip', '4/42/manifest.json' );
		foreach ( $keys as $key ) {
			$this->assertTrue( $provider->put_object( $key, 'payload' ) );
		}
		$this->assertTrue( $provider->put_object( '4/43/chunks/sibling.bin', 'keep' ) );
		foreach ( $keys as $key ) {
			$this->assertTrue( $provider->delete_object( $key ) );
		}
		$this->assertDirectoryDoesNotExist( $base . '/4/42' );
		$this->assertDirectoryExists( $base . '/4' );
		$this->assertSame( 'keep', file_get_contents( $base . '/4/43/chunks/sibling.bin' ) );
		$this->assertTrue( $provider->delete_object( '4/43/chunks/sibling.bin' ) );
		$this->assertDirectoryDoesNotExist( $base . '/4/43' );
		$this->assertDirectoryExists( $base . '/4' );
		$this->assertDirectoryExists( $base );
	}

	/** A remaining object must not turn a successful unlink into a retry. */
	public function test_delete_keeps_nonempty_directory_and_succeeds() {
		$provider = $this->make_provider();
		$this->assertTrue( $provider->put_object( '4/42/chunks/a.bin', 'remove' ) );
		$this->assertTrue( $provider->put_object( '4/42/chunks/b.bin', 'keep' ) );
		$this->assertTrue( $provider->delete_object( '4/42/chunks/a.bin' ) );
		$this->assertFileDoesNotExist( $this->temp_dirs[0] . '/4/42/chunks/a.bin' );
		$this->assertDirectoryExists( $this->temp_dirs[0] . '/4/42/chunks' );
		$this->assertSame( 'keep', file_get_contents( $this->temp_dirs[0] . '/4/42/chunks/b.bin' ) );
	}

	/** Root-level connection probes must never retire the destination itself. */
	public function test_delete_root_probe_keeps_base_path() {
		$provider = $this->make_provider();
		$this->assertTrue( $provider->put_object( '.mainwp-probe-test.txt', 'probe' ) );
		$this->assertTrue( $provider->delete_object( '.mainwp-probe-test.txt' ) );
		$this->assertDirectoryExists( $this->temp_dirs[0] );
		$this->assertFileDoesNotExist( $this->temp_dirs[0] . '/.mainwp-probe-test.txt' );
	}

	/**
	 * Prefix listing returns matching keys with sizes.
	 *
	 * @return void
	 */
	public function test_list_objects_filters_by_prefix() {
		$provider = $this->make_provider();
		$provider->put_object( '4/42/chunks/aa.bin', 'one' );
		$provider->put_object( '4/42/chunks/bb.bin', 'three' );
		$provider->put_object( '4/43/chunks/cc.bin', 'other-snapshot' );

		$result = $provider->list_objects( '4/42/' );

		$this->assertFalse( $result['truncated'] );
		$this->assertNull( $result['next_token'] );

		$keys = array_column( $result['objects'], 'key' );
		sort( $keys );
		$this->assertSame( array( '4/42/chunks/aa.bin', '4/42/chunks/bb.bin' ), $keys );

		$sizes = array_column( $result['objects'], 'size', 'key' );
		$this->assertSame( 3, $sizes['4/42/chunks/aa.bin'] );
		$this->assertSame( 5, $sizes['4/42/chunks/bb.bin'] );
	}

	/**
	 * Listing a prefix never descends into unrelated subtrees. The
	 * unrelated tree here is unreadable: a walk that fails to prune would
	 * throw trying to enter it.
	 *
	 * @return void
	 */
	public function test_list_objects_prunes_unrelated_directories() {
		$provider = $this->make_provider();
		$provider->put_object( '4/42/chunks/aa.bin', 'one' );
		$provider->put_object( '9/90/other.bin', 'x' );

		$unrelated = $this->temp_dirs[0] . '/9/90';
		chmod( $unrelated, 0000 );

		try {
			$result = $provider->list_objects( '4/42/' );
		} finally {
			chmod( $unrelated, 0755 );
		}

		$this->assertSame( array( '4/42/chunks/aa.bin' ), array_column( $result['objects'], 'key' ) );
	}

	/**
	 * Object writes are atomic: no tmp leftovers beside final objects.
	 *
	 * @return void
	 */
	public function test_put_leaves_no_tmp_files() {
		$provider = $this->make_provider();
		$provider->put_object( 'x/y/z.bin', 'payload' );

		$tmp = glob( $this->temp_dirs[0] . DIRECTORY_SEPARATOR . 'x' . DIRECTORY_SEPARATOR . 'y' . DIRECTORY_SEPARATOR . '*.tmp*' );
		$this->assertSame( array(), $tmp );
	}

	/**
	 * Keys must not escape the base path.
	 *
	 * @return void
	 */
	public function test_traversal_keys_are_rejected() {
		$provider = $this->make_provider();

		$this->assertFalse( $provider->put_object( '../escape.bin', 'x' ) );
		$this->assertFalse( $provider->head_object( '../../etc/passwd' ) );
		$this->assertFalse( $provider->get_object( 'a/../../escape.bin', $this->temp_dirs[0] . '/out.bin' ) );
	}

	/**
	 * Existing destinations must not expose or modify bytes in a served tree.
	 *
	 * @dataProvider served_base_operations
	 * @param string $boundary Served directory.
	 * @param string $operation Provider operation.
	 * @return void
	 */
	public function test_operations_refuse_served_base_path( string $boundary, string $operation ) {
		$base = untrailingslashit( $boundary ) . '/mainwp-local-refusal-' . uniqid();
		mkdir( $base, 0755, true );
		$this->temp_dirs[] = $base;
		file_put_contents( $base . '/existing.bin', 'keep' );
		$provider = new MainWP_Backup_Storage_Local( array( 'base_path' => $base ) );
		$error    = null;
		try {
			if ( 'put_object' === $operation ) {
				$provider->put_object( 'new.bin', 'blocked' );
			} elseif ( 'get_object' === $operation ) {
				$provider->get_object( 'existing.bin', $base . '/downloaded.bin' );
			} elseif ( 'list_objects' === $operation ) {
				$provider->list_objects();
			} else {
				$provider->$operation( 'existing.bin' );
			}
		} catch ( \RuntimeException $e ) {
			$error = $e->getMessage();
		}
		$this->assertIsString( $error, $operation . ' must report a served-directory refusal.' );
		$this->assertStringContainsString( $base, $error );
		$this->assertStringContainsString( 'outside the WordPress and wp-content folders', $error );
		$this->assertSame( 'keep', file_get_contents( $base . '/existing.bin' ) );
		$this->assertSame( array( '.', '..', 'existing.bin' ), scandir( $base ) );
	}

	/**
	 * Both served trees need the same protection for every object operation.
	 *
	 * @return array
	 */
	public static function served_base_operations() {
		$cases      = array();
		$boundaries = array(
			'wordpress' => ABSPATH,
			'content'   => WP_CONTENT_DIR,
		);
		foreach ( $boundaries as $name => $boundary ) {
			foreach ( array( 'put_object', 'head_object', 'get_object', 'delete_object', 'list_objects' ) as $operation ) {
				$cases[ $name . ':' . $operation ] = array( $boundary, $operation );
			}
		}
		return $cases;
	}

	/**
	 * A writable directory is insufficient when the web server serves it.
	 *
	 * @return void
	 */
	public function test_connection_test_refuses_served_base_path() {
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $boundary ) {
			$base = untrailingslashit( $boundary ) . '/mainwp-local-probe-refusal-' . uniqid();
			mkdir( $base, 0755, true );
			$this->temp_dirs[] = $base;
			$provider          = new MainWP_Backup_Storage_Local( array( 'base_path' => $base ) );
			$result            = $provider->connection_test();
			$this->assertFalse( $result['ok'] );
			$this->assertStringContainsString( $base, $result['error'] );
			$this->assertStringContainsString( 'outside the WordPress and wp-content folders', $result['error'] );
			$this->assertSame( array( '.', '..' ), scandir( $base ) );
		}
	}

	/**
	 * A safe base must not permit object I/O through a served directory alias.
	 *
	 * @dataProvider symlink_object_operations
	 * @param string $operation Provider operation.
	 */
	public function test_operations_refuse_served_symlink_inside_base( string $operation ) {
		$provider = $this->make_provider();
		$target   = ABSPATH . 'mainwp-local-object-refusal-' . uniqid();
		mkdir( $target . '/42/files', 0755, true );
		$this->temp_dirs[] = $target;
		file_put_contents( $target . '/42/files/existing.zip', 'keep' );
		$link = $this->temp_dirs[0] . '/4';
		if ( ! @symlink( $target, $link ) ) {
			$this->markTestSkipped( 'This platform cannot create the directory symlink.' );
		}
		$error = null;
		try {
			$this->assertTrue( $provider->put_object( '5/42/files/normal.zip', 'normal' ) );
			$this->assertSame( array( 'size' => 6 ), $provider->head_object( '5/42/files/normal.zip' ) );
			$this->assertTrue( $provider->get_object( '5/42/files/normal.zip', $this->temp_dirs[0] . '/normal-download.zip' ) );
			$this->assertSame( 'normal', file_get_contents( $this->temp_dirs[0] . '/normal-download.zip' ) );
			$this->assertTrue( $provider->delete_object( '5/42/files/normal.zip' ) );
			try {
				if ( 'put_object' === $operation ) {
					$provider->put_object( '4/42/new/files/x.zip', 'blocked' );
				} elseif ( 'get_object' === $operation ) {
					$provider->get_object( '4/42/files/existing.zip', $this->temp_dirs[0] . '/blocked-download.zip' );
				} else {
					$provider->$operation( '4/42/files/existing.zip' );
				}
			} catch ( \RuntimeException $e ) {
				$error = $e->getMessage();
			}
		} finally {
			unlink( $link );
		}
		$this->assertIsString( $error, $operation . ' must refuse an object inside a served tree.' );
		$this->assertStringContainsString( 'outside the WordPress and wp-content folders', $error );
		$this->assertSame( 'keep', file_get_contents( $target . '/42/files/existing.zip' ) );
		$this->assertDirectoryDoesNotExist( $target . '/42/new' );
		$this->assertFileDoesNotExist( $this->temp_dirs[0] . '/blocked-download.zip' );
	}

	/**
	 * Every object operation follows the same resolver before touching bytes.
	 *
	 * @return array
	 */
	public static function symlink_object_operations() {
		return array(
			'put'    => array( 'put_object' ),
			'head'   => array( 'head_object' ),
			'get'    => array( 'get_object' ),
			'delete' => array( 'delete_object' ),
		);
	}

	/**
	 * A missing descendant must retain its existing ancestor's served location.
	 *
	 * @return void
	 */
	public function test_put_refuses_symlinked_served_ancestor() {
		$this->make_provider();
		$link              = $this->temp_dirs[0] . '/wordpress';
		$folder            = 'mainwp-local-alias-refusal-' . uniqid();
		$base              = $link . '/' . $folder . '/nested';
		$this->temp_dirs[] = ABSPATH . $folder;
		$this->assertTrue( symlink( ABSPATH, $link ) );
		$provider = new MainWP_Backup_Storage_Local( array( 'base_path' => $base ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( $base );
		try {
			$provider->put_object( 'blocked.bin', 'blocked' );
		} finally {
			unlink( $link );
		}
	}

	/**
	 * A base path that is a symlink to storage outside WordPress keeps working.
	 *
	 * @return void
	 */
	public function test_put_accepts_symlinked_base_outside_served_trees() {
		$this->make_provider();
		$this->make_provider();
		$link = $this->temp_dirs[0] . '/alias';
		$this->assertTrue( symlink( $this->temp_dirs[1], $link ) );
		$provider = new MainWP_Backup_Storage_Local( array( 'base_path' => $link ) );
		try {
			$this->assertTrue( $provider->put_object( '4/42/files/a.bin', 'outside' ) );
			$this->assertSame( 'outside', file_get_contents( $this->temp_dirs[1] . '/4/42/files/a.bin' ) );
		} finally {
			unlink( $link );
		}
	}

	/**
	 * A base path that is itself a symlink into WordPress is refused.
	 *
	 * @return void
	 */
	public function test_put_refuses_symlinked_base_into_served_tree() {
		$this->make_provider();
		$target = ABSPATH . 'mainwp-local-link-refusal-' . uniqid();
		mkdir( $target, 0755, true );
		$this->temp_dirs[] = $target;
		$link              = $this->temp_dirs[0] . '/served-alias';
		$this->assertTrue( symlink( $target, $link ) );
		$provider = new MainWP_Backup_Storage_Local( array( 'base_path' => $link ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( $link );
		try {
			$provider->put_object( 'blocked.bin', 'blocked' );
		} finally {
			unlink( $link );
			$this->assertSame( array( '.', '..' ), scandir( $target ) );
		}
	}

	/**
	 * Parent segments cannot disguise a served base path.
	 *
	 * @return void
	 */
	public function test_put_refuses_parent_segments_in_base_path() {
		$base = ABSPATH . 'mainwp-local-parent-refusal-' . uniqid();
		mkdir( $base, 0755, true );
		$this->temp_dirs[] = $base;
		$provider          = new MainWP_Backup_Storage_Local( array( 'base_path' => $base . '/../' . basename( $base ) ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'outside the WordPress and wp-content folders' );
		$provider->put_object( 'blocked.bin', 'blocked' );
	}

	/**
	 * The connection test proves the base path is writable.
	 *
	 * @return void
	 */
	public function test_connection_test() {
		$ok = $this->make_provider()->connection_test();
		$this->assertTrue( $ok['ok'] );
		$this->assertNull( $ok['error'] );

		$missing = new MainWP_Backup_Storage_Local( array( 'base_path' => '/nonexistent/path/' . uniqid() ) );
		$bad     = $missing->connection_test();
		$this->assertFalse( $bad['ok'] );
		$this->assertIsString( $bad['error'] );
	}
}
