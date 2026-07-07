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
