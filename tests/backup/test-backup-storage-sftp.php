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
class Fake_SFTP_Connection {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

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
				$path               .= '/' . $part;
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
class MainWP_Backup_Storage_SFTP_Test extends \WP_UnitTestCase {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double above.

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
		$failing  = new class() extends Fake_SFTP_Connection {
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
	 * Listing a prefix never descends into unrelated subtrees: retention
	 * over one snapshot must not scan every other site's backup history on
	 * the destination.
	 *
	 * @return void
	 */
	public function test_list_objects_prunes_unrelated_directories() {
		$connection = new class() extends Fake_SFTP_Connection {
			/**
			 * Directories rawlist() was asked for.
			 *
			 * @var string[]
			 */
			public $rawlist_calls = array();

			/**
			 * Record then delegate.
			 *
			 * @param string $dir Directory path.
			 *
			 * @return array|false
			 */
			public function rawlist( $dir ) {
				$this->rawlist_calls[] = rtrim( $dir, '/' );
				return parent::rawlist( $dir );
			}
		};
		$provider   = $this->make_provider( $connection );

		$provider->put_object( '4/42/chunks/aa.bin', 'aaa' );
		$provider->put_object( '4/43/other.bin', 'zz' );
		$provider->put_object( '5/50/far.bin', 'ff' );

		$connection->rawlist_calls = array();
		$result                    = $provider->list_objects( '4/42/' );

		$this->assertSame( array( '4/42/chunks/aa.bin' ), array_column( $result['objects'], 'key' ) );
		$this->assertNotContains( '/backups/4/43', $connection->rawlist_calls, 'An unrelated snapshot subtree must not be listed.' );
		$this->assertNotContains( '/backups/5', $connection->rawlist_calls, 'An unrelated site subtree must not be listed.' );
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

	/**
	 * A host that resolves to a link-local / cloud-metadata address is blocked
	 * before the phpseclib connection opens. A private/LAN SFTP host is a
	 * supported self-hosted target and is not blocked.
	 *
	 * @return void
	 */
	public function test_default_connection_blocked_when_host_is_link_local() {
		$provider = new MainWP_Backup_Storage_SFTP( array( 'host' => '169.254.169.254' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'link-local or cloud-metadata' );
		$provider->default_connection();
	}

	/**
	 * A fake exposing only the host-key surface verify_host_key() reads.
	 *
	 * @param string $key Server public host key string (algo + base64 blob).
	 *
	 * @return object
	 */
	private function host_key_connection( $key ) {
		return new class( $key ) { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.
			/**
			 * Key string.
			 *
			 * @var string
			 */
			private $key;

			/**
			 * Constructor.
			 *
			 * @param string $key Key string.
			 */
			public function __construct( $key ) {
				$this->key = $key;
			}

			/**
			 * The server's public host key.
			 *
			 * @return string
			 */
			public function getServerPublicHostKey() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- phpseclib3 surface.
				return $this->key;
			}
		};
	}

	/**
	 * A deterministic fake host key (the base64 blob only needs to decode).
	 *
	 * @param string $seed Vary to model a different server key.
	 *
	 * @return string
	 */
	private function fake_host_key( $seed = 'a' ) {
		return 'ssh-ed25519 ' . base64_encode( 'fake-host-key-blob-' . $seed );
	}

	/**
	 * A manually pinned fingerprint that matches the server's key passes
	 * verification.
	 *
	 * @return void
	 */
	public function test_manual_fingerprint_pin_accepts_matching_key() {
		$key      = $this->fake_host_key();
		$expected = MainWP_Backup_Storage_SFTP::fingerprint_host_key( $key );

		$provider = new MainWP_Backup_Storage_SFTP(
			array(
				'host'             => 'sftp.example.com',
				'host_fingerprint' => $expected,
			)
		);

		$provider->verify_host_key( $this->host_key_connection( $key ) );
		$this->assertTrue( true, 'A matching manual pin must not throw.' );
	}

	/**
	 * A manually pinned fingerprint refuses a different server key, naming
	 * both fingerprints so the admin can compare, and parks the destination
	 * (401) instead of retrying into a possible interception.
	 *
	 * @return void
	 */
	public function test_manual_fingerprint_pin_refuses_mismatched_key() {
		$pinned = MainWP_Backup_Storage_SFTP::fingerprint_host_key( $this->fake_host_key( 'expected' ) );

		$provider = new MainWP_Backup_Storage_SFTP(
			array(
				'host'             => 'sftp.example.com',
				'host_fingerprint' => $pinned,
			)
		);

		try {
			$provider->verify_host_key( $this->host_key_connection( $this->fake_host_key( 'attacker' ) ) );
			$this->fail( 'A mismatched host key must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 401, $e->getCode(), 'A host-key mismatch parks the destination instead of retrying.' );
			$this->assertStringContainsString( $pinned, $e->getMessage(), 'The pinned fingerprint must be named.' );
			$this->assertStringContainsString( MainWP_Backup_Storage_SFTP::fingerprint_host_key( $this->fake_host_key( 'attacker' ) ), $e->getMessage(), 'The offered fingerprint must be named.' );
		}
	}

	/**
	 * Trust-on-first-use: the first successful verification stores the
	 * server's fingerprint for the destination, and a later connect from a
	 * different key is refused.
	 *
	 * @return void
	 */
	public function test_first_connect_stores_fingerprint_and_refuses_change() {
		delete_option( 'mainwp_backup_sftp_hostkeys' );
		$key = $this->fake_host_key( 'original' );

		$provider = new MainWP_Backup_Storage_SFTP(
			array(
				'host'           => 'sftp.example.com',
				'destination_id' => 77,
			)
		);

		try {
			$provider->verify_host_key( $this->host_key_connection( $key ) );

			$stored = get_option( 'mainwp_backup_sftp_hostkeys' );
			$this->assertSame( MainWP_Backup_Storage_SFTP::fingerprint_host_key( $key ), isset( $stored[77] ) ? $stored[77] : null, 'The first connect must store the fingerprint.' );

			$this->expectException( \RuntimeException::class );
			$provider->verify_host_key( $this->host_key_connection( $this->fake_host_key( 'rotated' ) ) );
		} finally {
			delete_option( 'mainwp_backup_sftp_hostkeys' );
		}
	}

	/**
	 * Without a destination id (a bare connection test before the row
	 * exists) nothing persists - TOFU only pins saved destinations.
	 *
	 * @return void
	 */
	public function test_no_destination_id_skips_tofu_persistence() {
		delete_option( 'mainwp_backup_sftp_hostkeys' );

		$provider = new MainWP_Backup_Storage_SFTP( array( 'host' => 'sftp.example.com' ) );
		$provider->verify_host_key( $this->host_key_connection( $this->fake_host_key() ) );

		$this->assertFalse( get_option( 'mainwp_backup_sftp_hostkeys' ), 'A connection without a saved destination must not persist a pin.' );
	}

	/**
	 * The fingerprint hashes the decoded key blob (OpenSSH's SHA256 form,
	 * comparable with ssh-keygen -lf output), not the textual key string.
	 *
	 * @return void
	 */
	public function test_fingerprint_hashes_decoded_blob_in_openssh_form() {
		$blob = 'fake-host-key-blob-a';
		$key  = 'ssh-ed25519 ' . base64_encode( $blob );

		$this->assertSame(
			'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ),
			MainWP_Backup_Storage_SFTP::fingerprint_host_key( $key ),
			'The fingerprint must be the OpenSSH SHA256 form of the decoded blob.'
		);
	}

	/**
	 * F4: a connection test for an edit whose target server is unchanged
	 * enforces the existing pin (its destination id), while a new
	 * destination or one repointed at a different host/port does not (id 0),
	 * so a legitimate host change can still re-learn via TOFU.
	 *
	 * @return void
	 */
	public function test_pin_enforcement_id_tracks_server_identity() {
		$stored = array(
			'host'     => 'sftp.example.com',
			'port'     => '22',
			'username' => 'u',
		);

		$this->assertSame( 0, \MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id( 0, $stored, array() ), 'A new destination enforces no pin.' );
		$this->assertSame( 7, \MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id( 7, $stored, $stored ), 'An edit against the same server enforces its pin.' );
		$this->assertSame( 7, \MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id( 7, $stored, array() ), 'Unreadable stored settings must still enforce the existing destination pin.' );
		$this->assertSame(
			0,
			\MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id(
				7,
				array(
					'host' => 'other.example.com',
					'port' => '22',
				),
				$stored
			),
			'Repointing at a different host re-learns via TOFU.'
		);
		$this->assertSame(
			0,
			\MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id(
				7,
				array(
					'host' => 'sftp.example.com',
					'port' => '2222',
				),
				$stored
			),
			'A changed port re-learns via TOFU.'
		);
	}

	/**
	 * Provider changes discard stale trust; unreadable SFTP recovery keeps it.
	 *
	 * @dataProvider sftp_pin_decisions
	 * @param int    $id                Destination id.
	 * @param string $stored_provider   Stored provider.
	 * @param string $new_provider      Candidate provider.
	 * @param array  $new_settings      Candidate settings.
	 * @param array  $existing_settings Stored settings.
	 * @param array  $expected          Expected test id, reset, and notice.
	 * @return void
	 */
	public function test_sftp_pin_decision( int $id, string $stored_provider, string $new_provider, array $new_settings, array $existing_settings, array $expected ) {
		$this->assertSame(
			$expected,
			\MainWP\Dashboard\MainWP_Backup_Storage_Handler::sftp_pin_decision( $id, $stored_provider, $new_provider, $new_settings, $existing_settings )
		);
	}

	/**
	 * Each row states the expected trust behavior independently of the decision.
	 *
	 * @return array
	 */
	public static function sftp_pin_decisions() {
		$stored = array(
			'host' => 'sftp.example.com',
			'port' => '22',
		);
		$new    = array(
			'host' => 'new.example.com',
			'port' => '22',
		);
		$local  = array( 'base_path' => '/backups' );

		return array(
			'new destination'          => array(
				0,
				'sftp',
				'local',
				$local,
				$stored,
				array(
					'test_id' => 0,
					'reset'   => false,
					'notice'  => false,
				),
			),
			'sftp same server'         => array(
				7,
				'sftp',
				'sftp',
				$stored,
				$stored,
				array(
					'test_id' => 7,
					'reset'   => false,
					'notice'  => false,
				),
			),
			'sftp changed server'      => array(
				7,
				'sftp',
				'sftp',
				$new,
				$stored,
				array(
					'test_id' => 0,
					'reset'   => true,
					'notice'  => true,
				),
			),
			'sftp unreadable'          => array(
				7,
				'sftp',
				'sftp',
				$new,
				array(),
				array(
					'test_id' => 7,
					'reset'   => false,
					'notice'  => false,
				),
			),
			'leaving sftp readable'    => array(
				7,
				'sftp',
				'local',
				$local,
				$stored,
				array(
					'test_id' => 0,
					'reset'   => true,
					'notice'  => false,
				),
			),
			'leaving sftp unreadable'  => array(
				7,
				'sftp',
				'local',
				$local,
				array(),
				array(
					'test_id' => 0,
					'reset'   => true,
					'notice'  => false,
				),
			),
			'entering sftp readable'   => array(
				7,
				'local',
				'sftp',
				$new,
				$local,
				array(
					'test_id' => 0,
					'reset'   => true,
					'notice'  => false,
				),
			),
			'entering sftp unreadable' => array(
				7,
				'local',
				'sftp',
				$new,
				array(),
				array(
					'test_id' => 0,
					'reset'   => true,
					'notice'  => false,
				),
			),
			'non-sftp readable'        => array(
				7,
				'local',
				's3',
				array(),
				$local,
				array(
					'test_id' => 0,
					'reset'   => false,
					'notice'  => false,
				),
			),
			'non-sftp unreadable'      => array(
				7,
				'local',
				's3',
				array(),
				array(),
				array(
					'test_id' => 0,
					'reset'   => false,
					'notice'  => false,
				),
			),
		);
	}

	/**
	 * A cosmetic-only host/port difference targets the identical real server
	 * (the provider (int)-casts the port and resolves the host case- and
	 * trailing-dot-insensitively), so it must STILL enforce the pin - a
	 * formatting quirk cannot be used to skip the host-key check.
	 *
	 * @return void
	 */
	public function test_pin_enforcement_id_ignores_cosmetic_host_port_differences() {
		$stored = array(
			'host' => 'sftp.example.com',
			'port' => '22',
		);

		$this->assertSame(
			7,
			\MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id(
				7,
				array(
					'host' => 'sftp.example.com',
					'port' => '022',
				),
				$stored
			),
			'A zero-padded port is the same port.'
		);
		$this->assertSame(
			7,
			\MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id(
				7,
				array(
					'host' => 'sftp.example.com',
					'port' => ' 22',
				),
				$stored
			),
			'A space-padded port is the same port.'
		);
		$this->assertSame(
			7,
			\MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id(
				7,
				array(
					'host' => 'SFTP.Example.com',
					'port' => '22',
				),
				$stored
			),
			'A case-different host is the same host.'
		);
		$this->assertSame(
			7,
			\MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id(
				7,
				array(
					'host' => 'sftp.example.com.',
					'port' => '22',
				),
				$stored
			),
			'A trailing-dot host is the same host.'
		);
		$this->assertSame( 7, \MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id( 7, array( 'host' => 'sftp.example.com' ), $stored ), 'An omitted port defaults to 22 - still the same server.' );
		// The provider derives its port with ! empty(), so "0" also dials 22.
		$this->assertSame(
			7,
			\MainWP\Dashboard\MainWP_Backup_Storage_Handler::pin_enforcement_id(
				7,
				array(
					'host' => 'sftp.example.com',
					'port' => '0',
				),
				$stored
			),
			'A "0" port dials 22 like the provider - still the same server.'
		);
	}

	/**
	 * F5: when the host-key pin cannot be recorded on first use, the
	 * handshake is refused rather than proceeding unverified (which would let
	 * the next connect trust whatever key it saw).
	 *
	 * @return void
	 */
	public function test_first_connect_refuses_when_pin_cannot_persist() {
		delete_option( 'mainwp_backup_sftp_hostkeys' );

		// A CAS write that never lands, modeling a dead or read-only DB.
		$provider = new class( array(
			'host'           => 'sftp.example.com',
			'destination_id' => 91,
		) ) extends MainWP_Backup_Storage_SFTP { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.
			/**
			 * Refuse every swap.
			 *
			 * @param string|null $expected_raw Expected raw value.
			 * @param string      $next_raw     Next raw value.
			 *
			 * @return bool Always false.
			 */
			protected function hostkeys_cas_write( ?string $expected_raw, string $next_raw ): bool {
				unset( $expected_raw, $next_raw );
				return false;
			}
		};

		try {
			$this->expectException( \RuntimeException::class );
			$provider->verify_host_key( $this->host_key_connection( $this->fake_host_key() ) );
		} finally {
			delete_option( 'mainwp_backup_sftp_hostkeys' );
		}
	}

	/**
	 * Write the host-key option row the way a concurrent PHP process would:
	 * straight into the DB, invisible to this request's options cache.
	 *
	 * @param array $pins Destination id => fingerprint map.
	 *
	 * @return void
	 */
	private function foreign_process_writes_hostkeys( array $pins ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' ) ON DUPLICATE KEY UPDATE option_value = VALUES( option_value )", 'mainwp_backup_sftp_hostkeys', maybe_serialize( $pins ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- models a foreign process's write.
	}

	/**
	 * Read the stored pins the same way, past the options cache.
	 *
	 * @return array Destination id => fingerprint map.
	 */
	private function read_hostkeys_from_db() {
		global $wpdb;
		$raw    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mainwp_backup_sftp_hostkeys' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- assertion must see the real row.
		$stored = maybe_unserialize( (string) $raw );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Two simultaneous first uses of the SAME destination cannot both win
	 * trust: the loser must defer to the pin the concurrent connection
	 * recorded and refuse its own differing key, instead of last-write-wins
	 * deciding which server to trust.
	 *
	 * @return void
	 */
	public function test_first_use_defers_to_concurrent_pin_for_same_destination() {
		delete_option( 'mainwp_backup_sftp_hostkeys' );
		// Prime this request's options cache with "no pins" - the stale
		// state the racing connection acts on.
		get_option( 'mainwp_backup_sftp_hostkeys' );

		$winner = MainWP_Backup_Storage_SFTP::fingerprint_host_key( $this->fake_host_key( 'winner' ) );
		$this->foreign_process_writes_hostkeys( array( 77 => $winner ) );

		$provider = new MainWP_Backup_Storage_SFTP(
			array(
				'host'           => 'sftp.example.com',
				'destination_id' => 77,
			)
		);

		try {
			try {
				$provider->verify_host_key( $this->host_key_connection( $this->fake_host_key( 'loser' ) ) );
				$this->fail( 'The losing first use must defer to the concurrent pin and refuse its own key.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 401, $e->getCode(), 'Deferring to the concurrent pin parks the destination.' );
			}

			$stored = $this->read_hostkeys_from_db();
			$this->assertSame( $winner, isset( $stored[77] ) ? $stored[77] : null, 'The concurrent pin must survive the race.' );
		} finally {
			delete_option( 'mainwp_backup_sftp_hostkeys' );
		}
	}

	/**
	 * A first use must not clobber a pin a concurrent process recorded for a
	 * DIFFERENT destination: the stale options cache hides that write, so
	 * only the CAS's direct read can preserve it.
	 *
	 * @return void
	 */
	public function test_first_use_preserves_concurrent_pin_for_other_destination() {
		delete_option( 'mainwp_backup_sftp_hostkeys' );
		get_option( 'mainwp_backup_sftp_hostkeys' );

		$other = MainWP_Backup_Storage_SFTP::fingerprint_host_key( $this->fake_host_key( 'other-destination' ) );
		$this->foreign_process_writes_hostkeys( array( 88 => $other ) );

		$key      = $this->fake_host_key( 'original' );
		$provider = new MainWP_Backup_Storage_SFTP(
			array(
				'host'           => 'sftp.example.com',
				'destination_id' => 77,
			)
		);

		try {
			$provider->verify_host_key( $this->host_key_connection( $key ) );

			$stored = $this->read_hostkeys_from_db();
			$this->assertSame( MainWP_Backup_Storage_SFTP::fingerprint_host_key( $key ), isset( $stored[77] ) ? $stored[77] : null, 'The first use must record its own pin.' );
			$this->assertSame( $other, isset( $stored[88] ) ? $stored[88] : null, 'The concurrent pin for the other destination must survive.' );
		} finally {
			delete_option( 'mainwp_backup_sftp_hostkeys' );
		}
	}

	/**
	 * Forgetting one destination's pin must not clobber a pin a concurrent
	 * process recorded for a DIFFERENT destination between the removal's read
	 * and its write. The stale options cache hides that write, so a plain
	 * read-modify-write would rewrite the whole option from the stale view and
	 * drop the concurrent pin - returning that destination to trust-on-first-
	 * use. Only routing removal through the same CAS the pin writes use keeps
	 * it.
	 *
	 * @return void
	 */
	public function test_forget_host_key_preserves_concurrent_pin_for_other_destination() {
		delete_option( 'mainwp_backup_sftp_hostkeys' );

		$keep = MainWP_Backup_Storage_SFTP::fingerprint_host_key( $this->fake_host_key( 'keep' ) );
		$drop = MainWP_Backup_Storage_SFTP::fingerprint_host_key( $this->fake_host_key( 'drop' ) );
		$this->foreign_process_writes_hostkeys(
			array(
				10 => $drop,
				20 => $keep,
			)
		);

		// Prime this request's options cache with the two-pin view - the stale
		// state a read-modify-write removal would act on.
		get_option( 'mainwp_backup_sftp_hostkeys' );

		// A concurrent process records a third destination's pin after the
		// cache primed; only a direct read sees it.
		$concurrent = MainWP_Backup_Storage_SFTP::fingerprint_host_key( $this->fake_host_key( 'concurrent' ) );
		$this->foreign_process_writes_hostkeys(
			array(
				10 => $drop,
				20 => $keep,
				30 => $concurrent,
			)
		);

		try {
			MainWP_Backup_Storage_SFTP::forget_host_key( 10 );

			$stored = $this->read_hostkeys_from_db();
			$this->assertArrayNotHasKey( 10, $stored, 'The forgotten destination pin must be gone.' );
			$this->assertSame( $keep, isset( $stored[20] ) ? $stored[20] : null, 'An untouched pin must survive the removal.' );
			$this->assertSame( $concurrent, isset( $stored[30] ) ? $stored[30] : null, 'A pin recorded concurrently after the stale cache read must survive the removal.' );
		} finally {
			delete_option( 'mainwp_backup_sftp_hostkeys' );
		}
	}
}
