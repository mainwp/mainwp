<?php
/**
 * MainWP Backup Storage SFTP Provider Tests
 *
 * Verifies the SFTP provider through an injected connection factory and
 * an in-memory fake connection: keys resolve under the base path with
 * traversal rejected, uploads create parent directories and publish via
 * tmp-then-rename, existence checks distinguish files from directories,
 * deletes are idempotent, listing walks the tree recursively without
 * pagination (matching the Local provider), the connection test proves
 * write access with a probe object, and connection failures surface as
 * RuntimeException with the auth status so the upload driver can park
 * the destination as credentials-invalid.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_Provider;
use MainWP\Dashboard\MainWP_Backup_Storage_SFTP;

/**
 * An in-memory SFTP connection covering the phpseclib3 surface the
 * provider uses.
 */
class Fake_SFTP_Connection { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Files by absolute remote path.
	 *
	 * @var array<string,string>
	 */
	public $files = array();

	/**
	 * Directories by absolute remote path.
	 *
	 * @var array<string,bool>
	 */
	public $dirs = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $dirs Pre-existing directories.
	 */
	public function __construct( array $dirs = array( '/backups' ) ) {
		foreach ( $dirs as $dir ) {
			$this->dirs[ rtrim( $dir, '/' ) ] = true;
		}
	}

	/**
	 * Write a file.
	 *
	 * @param string $remote Remote path.
	 * @param string $data   File bytes.
	 *
	 * @return bool
	 */
	public function put( $remote, $data ) {
		$this->files[ $remote ] = $data;
		return true;
	}

	/**
	 * Read a file out to a local path.
	 *
	 * @param string $remote Remote path.
	 * @param string $local  Local target path.
	 *
	 * @return bool
	 */
	public function get( $remote, $local ) {
		if ( ! isset( $this->files[ $remote ] ) ) {
			return false;
		}
		return false !== file_put_contents( $local, $this->files[ $remote ] );
	}

	/**
	 * Stat a path.
	 *
	 * @param string $remote Remote path.
	 *
	 * @return array|false
	 */
	public function stat( $remote ) {
		if ( isset( $this->files[ $remote ] ) ) {
			return array(
				'type' => 1,
				'size' => strlen( $this->files[ $remote ] ),
			);
		}
		if ( isset( $this->dirs[ rtrim( $remote, '/' ) ] ) ) {
			return array(
				'type' => 2,
				'size' => 0,
			);
		}
		return false;
	}

	/**
	 * Delete a path.
	 *
	 * @param string $remote    Remote path.
	 * @param bool   $recursive Whether to recurse.
	 *
	 * @return bool
	 */
	public function delete( $remote, $recursive = true ) {
		unset( $recursive );
		if ( isset( $this->files[ $remote ] ) ) {
			unset( $this->files[ $remote ] );
			return true;
		}
		return false;
	}

	/**
	 * Rename a path; fails when the target exists, per the SFTP
	 * protocol.
	 *
	 * @param string $from Source path.
	 * @param string $to   Target path.
	 *
	 * @return bool
	 */
	public function rename( $from, $to ) {
		if ( ! isset( $this->files[ $from ] ) || isset( $this->files[ $to ] ) ) {
			return false;
		}
		$this->files[ $to ] = $this->files[ $from ];
		unset( $this->files[ $from ] );
		return true;
	}

	/**
	 * Create a directory.
	 *
	 * @param string $dir       Directory path.
	 * @param int    $mode      Permissions.
	 * @param bool   $recursive Create parents.
	 *
	 * @return bool
	 */
	public function mkdir( $dir, $mode = -1, $recursive = false ) {
		unset( $mode );
		$dir = rtrim( $dir, '/' );
		if ( $recursive ) {
			$parts = explode( '/', ltrim( $dir, '/' ) );
			$path  = '';
			foreach ( $parts as $part ) {
				$path                .= '/' . $part;
				$this->dirs[ $path ] = true;
			}
			return true;
		}
		$this->dirs[ $dir ] = true;
		return true;
	}

	/**
	 * Whether a path exists.
	 *
	 * @param string $remote Remote path.
	 *
	 * @return bool
	 */
	public function file_exists( $remote ) {
		return isset( $this->files[ $remote ] ) || isset( $this->dirs[ rtrim( $remote, '/' ) ] );
	}

	/**
	 * Whether a path is a directory.
	 *
	 * @param string $remote Remote path.
	 *
	 * @return bool
	 */
	public function is_dir( $remote ) {
		return isset( $this->dirs[ rtrim( $remote, '/' ) ] );
	}

	/**
	 * List a directory's direct children.
	 *
	 * @param string $dir Directory path.
	 *
	 * @return array|false
	 */
	public function rawlist( $dir ) {
		$dir = rtrim( $dir, '/' );
		if ( ! isset( $this->dirs[ $dir ] ) ) {
			return false;
		}

		$entries = array(
			'.'  => array( 'type' => 2 ),
			'..' => array( 'type' => 2 ),
		);

		foreach ( array_keys( $this->dirs ) as $candidate ) {
			if ( $candidate !== $dir && dirname( $candidate ) === $dir ) {
				$entries[ basename( $candidate ) ] = array(
					'type' => 2,
					'size' => 0,
				);
			}
		}
		foreach ( $this->files as $path => $contents ) {
			if ( dirname( $path ) === $dir ) {
				$entries[ basename( $path ) ] = array(
					'type' => 1,
					'size' => strlen( $contents ),
				);
			}
		}

		return $entries;
	}
}

/**
 * Class MainWP_Backup_Storage_SFTP_Test
 */
class MainWP_Backup_Storage_SFTP_Test extends \WP_UnitTestCase { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double above.

	/**
	 * Build a provider around a fake connection.
	 *
	 * @param Fake_SFTP_Connection $connection Fake connection.
	 * @param array                $overrides  Setting overrides.
	 *
	 * @return MainWP_Backup_Storage_SFTP
	 */
	private function make_provider( Fake_SFTP_Connection $connection, array $overrides = array() ) {
		$settings = array_merge(
			array(
				'host'      => 'sftp.example.com',
				'port'      => 22,
				'username'  => 'backup-user',
				'password'  => 'backup-pass',
				'base_path' => '/backups',
			),
			$overrides
		);

		return new MainWP_Backup_Storage_SFTP(
			$settings,
			function () use ( $connection ) {
				return $connection;
			}
		);
	}

	/**
	 * The provider satisfies the storage provider interface.
	 *
	 * @return void
	 */
	public function test_implements_storage_provider_interface() {
		$provider = $this->make_provider( new Fake_SFTP_Connection() );
		$this->assertInstanceOf( MainWP_Backup_Storage_Provider::class, $provider );
	}

	/**
	 * Uploads create missing parent directories and land the bytes at
	 * the resolved path with no leftover tmp file.
	 *
	 * @return void
	 */
	public function test_put_object_creates_parents_and_publishes() {
		$connection = new Fake_SFTP_Connection();
		$provider   = $this->make_provider( $connection );

		$this->assertTrue( $provider->put_object( '4/42/chunks/abc.bin', 'chunk-bytes' ) );

		$this->assertSame( 'chunk-bytes', $connection->files['/backups/4/42/chunks/abc.bin'] );
		$this->assertTrue( $connection->is_dir( '/backups/4/42/chunks' ) );
		$this->assertCount( 1, $connection->files, 'No tmp file may remain after publish.' );
	}

	/**
	 * Overwriting an existing object replaces it.
	 *
	 * @return void
	 */
	public function test_put_object_overwrites_existing() {
		$connection = new Fake_SFTP_Connection();
		$provider   = $this->make_provider( $connection );

		$provider->put_object( 'k.bin', 'old' );
		$provider->put_object( 'k.bin', 'new' );

		$this->assertSame( 'new', $connection->files['/backups/k.bin'] );
		$this->assertCount( 1, $connection->files );
	}

	/**
	 * A failed write throws instead of silently succeeding.
	 *
	 * @return void
	 */
	public function test_put_object_failure_throws() {
		$failing = new class() extends Fake_SFTP_Connection {
			/**
			 * Fail every write.
			 *
			 * @param string $remote Remote path.
			 * @param string $data   File bytes.
			 *
			 * @return bool
			 */
			public function put( $remote, $data ) {
				unset( $remote, $data );
				return false;
			}
		};
		$provider = $this->make_provider( $failing );

		$this->expectException( \RuntimeException::class );
		$provider->put_object( 'k.bin', 'x' );
	}

	/**
	 * Keys with traversal segments are rejected.
	 *
	 * @return void
	 */
	public function test_traversal_keys_throw() {
		$provider = $this->make_provider( new Fake_SFTP_Connection() );

		$this->expectException( \RuntimeException::class );
		$provider->put_object( '../../etc/passwd', 'x' );
	}

	/**
	 * HEAD reports a file's size, absence as false, and a directory as
	 * absence.
	 *
	 * @return void
	 */
	public function test_head_object_present_absent_and_directory() {
		$connection = new Fake_SFTP_Connection();
		$provider   = $this->make_provider( $connection );

		$provider->put_object( 'k.bin', 'four' );

		$this->assertSame( array( 'size' => 4 ), $provider->head_object( 'k.bin' ) );
		$this->assertFalse( $provider->head_object( 'missing.bin' ) );

		$connection->mkdir( '/backups/folder' );
		$this->assertFalse( $provider->head_object( 'folder' ) );
	}

	/**
	 * Downloads copy the remote bytes to the local target.
	 *
	 * @return void
	 */
	public function test_get_object_downloads_to_file() {
		$connection = new Fake_SFTP_Connection();
		$provider   = $this->make_provider( $connection );
		$target     = get_temp_dir() . 'mainwp-sftp-download-' . uniqid() . '.bin';

		$provider->put_object( 'k.bin', 'remote-bytes' );

		try {
			$this->assertTrue( $provider->get_object( 'k.bin', $target ) );
			$this->assertSame( 'remote-bytes', file_get_contents( $target ) );
		} finally {
			if ( file_exists( $target ) ) {
				unlink( $target );
			}
		}
	}

	/**
	 * Downloading a missing object throws.
	 *
	 * @return void
	 */
	public function test_get_object_missing_throws() {
		$provider = $this->make_provider( new Fake_SFTP_Connection() );

		$this->expectException( \RuntimeException::class );
		$provider->get_object( 'missing.bin', get_temp_dir() . 'mainwp-sftp-' . uniqid() );
	}

	/**
	 * Deletes succeed for present and missing objects alike.
	 *
	 * @return void
	 */
	public function test_delete_object_is_idempotent() {
		$connection = new Fake_SFTP_Connection();
		$provider   = $this->make_provider( $connection );

		$provider->put_object( 'k.bin', 'x' );

		$this->assertTrue( $provider->delete_object( 'k.bin' ) );
		$this->assertSame( array(), $connection->files );
		$this->assertTrue( $provider->delete_object( 'k.bin' ), 'Deleting a missing object must succeed.' );
	}

	/**
	 * Listing walks the tree recursively, filters by prefix, skips
	 * directories, and is never paginated.
	 *
	 * @return void
	 */
	public function test_list_objects_walks_recursively_with_prefix() {
		$connection = new Fake_SFTP_Connection();
		$provider   = $this->make_provider( $connection );

		$provider->put_object( '4/42/chunks/aa.bin', str_repeat( 'a', 100 ) );
		$provider->put_object( '4/42/manifest.json', 'mmmmm' );
		$provider->put_object( '4/43/other.bin', 'zz' );

		$result = $provider->list_objects( '4/42/' );

		$this->assertFalse( $result['truncated'] );
		$this->assertNull( $result['next_token'] );

		$keys = array_column( $result['objects'], 'key' );
		sort( $keys );
		$this->assertSame( array( '4/42/chunks/aa.bin', '4/42/manifest.json' ), $keys );

		$sizes = array_column( $result['objects'], 'size', 'key' );
		$this->assertSame( 100, $sizes['4/42/chunks/aa.bin'] );
	}

	/**
	 * Listing under a base path that does not exist is empty, not an
	 * error.
	 *
	 * @return void
	 */
	public function test_list_objects_missing_base_is_empty() {
		$provider = $this->make_provider( new Fake_SFTP_Connection( array() ) );

		$result = $provider->list_objects( '4/42/' );

		$this->assertSame( array(), $result['objects'] );
		$this->assertNull( $result['next_token'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * The connection test proves write access with a probe object and
	 * cleans it up.
	 *
	 * @return void
	 */
	public function test_connection_test_round_trip() {
		$connection = new Fake_SFTP_Connection();
		$provider   = $this->make_provider( $connection );

		$result = $provider->connection_test();

		$this->assertTrue( $result['ok'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( array(), $connection->files, 'The probe object must be cleaned up.' );
	}

	/**
	 * A missing base path fails the connection test with a clear error.
	 *
	 * @return void
	 */
	public function test_connection_test_reports_missing_base_path() {
		$provider = $this->make_provider( new Fake_SFTP_Connection( array() ) );

		$result = $provider->connection_test();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Base path', $result['error'] );
	}

	/**
	 * A connection factory failure (bad host, bad credentials) surfaces
	 * in the connection test result and throws from data calls with the
	 * auth status preserved.
	 *
	 * @return void
	 */
	public function test_connection_failure_surfaces_with_status() {
		$provider = new MainWP_Backup_Storage_SFTP(
			array(
				'host'      => 'sftp.example.com',
				'username'  => 'u',
				'password'  => 'wrong',
				'base_path' => '/backups',
			),
			function () {
				throw new \RuntimeException( 'SFTP login failed.', 401 );
			}
		);

		$result = $provider->connection_test();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '401', $result['error'] );

		try {
			$provider->put_object( 'k.bin', 'x' );
			$this->fail( 'A connection failure must throw from data calls.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 401, $e->getCode() );
		}
	}
}
