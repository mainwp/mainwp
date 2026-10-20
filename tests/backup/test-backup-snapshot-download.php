<?php
/**
 * Snapshot downloads bind current authorization to a single local archive.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Download;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_DB;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/** Exercise persisted tokens, real site rights, and the returning streamer. */
class MainWP_Backup_Snapshot_Download_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Owning site.
	 *
	 * @var int
	 */
	private $site_id;
	/**
	 * Other site.
	 *
	 * @var int
	 */
	private $other_site_id;
	/**
	 * Snapshot identity.
	 *
	 * @var int
	 */
	private $snapshot_id;
	/**
	 * Recorded archive.
	 *
	 * @var string
	 */
	private $path;
	/**
	 * Transients to remove.
	 *
	 * @var string[]
	 */
	private $keys = array();

	/**
	 * Issued links must recheck the recorded size before serving bytes.
	 *
	 * @dataProvider n1_recorded_sizes
	 * @param mixed $size Invalid recorded size, or null to change disk bytes.
	 */
	public function test_n1_download_size_refusal_at_issue_and_redemption( $size ) {
		list( $query, $key ) = $this->link();
		if ( null === $size ) {
			file_put_contents( $this->path, 'replacement bytes' );
		} else {
			global $wpdb;
			$archive = array(
				'file' => 'snapshot.zip',
				'path' => $this->path,
				'role' => 'full',
			);
			if ( 'missing' !== $size ) {
				$archive['size'] = $size;
			}
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'archives' => wp_json_encode( array( $archive ) ) ), array( 'id' => $this->snapshot_id ) );
		}
		foreach ( array( MainWP_Backup_Download::create_download_url( $this->snapshot_id ), MainWP_Backup_Download::redeem_download( $query ) ) as $result ) {
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_backup_download_size', $result->get_error_code() );
		}
		$this->assertNotFalse( get_site_transient( $key ) );
	}

	/**
	 * Mismatch cases.
	 *
	 * @return array Mismatch cases.
	 */
	public static function n1_recorded_sizes() {
		return array(
			'disk changed'   => array( null ),
			'missing'        => array( 'missing' ),
			'numeric string' => array( '168000' ),
			'fraction'       => array( 168000.5 ),
		);
	}

	/** Set up isolated files and auto-assigned database identities. */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$this->set_up_private_root();
		$this->site_id       = $this->create_test_site();
		$this->other_site_id = $this->create_test_site();
		$dir                 = MainWP_Backup_Private_Root::site_path( $this->site_id );
		mkdir( $dir, 0700, true );
		$this->path = $dir . 'snapshot.zip';
		file_put_contents( $this->path, str_repeat( "archive\0bytes\xff", 12000 ) );
		global $wpdb;
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'task_id'       => 0,
				'site_id'       => $this->site_id,
				'type'          => 'full',
				'created'       => time(),
				'archives'      => wp_json_encode(
					array(
						array(
							'file' => 'snapshot.zip',
							'path' => $this->path,
							'role' => 'full',
							'size' => filesize( $this->path ),
						),
					)
				),
				'manifest_path' => '',
				'status'        => 'complete',
			)
		);
		$this->snapshot_id = (int) $wpdb->insert_id;
	}

	/** Remove only test-owned tokens, rows, and files. */
	public function tearDown(): void {
		foreach ( $this->keys as $key ) {
			delete_site_transient( $key );
		}
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'id' => $this->snapshot_id ) );
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Create a real link and retain its transient for cleanup.
	 *
	 * @return array Query and transient key.
	 */
	private function link(): array {
		$url = MainWP_Backup_Download::create_download_url( $this->snapshot_id );
		$this->assertIsString( $url );
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$key          = 'mainwp_backup_snapshot_download_' . hash( 'sha256', $query['token'] );
		$this->keys[] = $key;
		$this->assertSame( 'mainwp_backup_snapshot_download', $query['action'] );
		$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9]{32}$/D', $query['token'] );
		$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'], 'mainwp_backup_snapshot_download' ) );
		$record = get_site_transient( $key );
		$this->assertSame( get_current_user_id(), $record['user_id'] );
		$this->assertSame( $this->site_id, $record['site_id'] );
		$this->assertSame( $this->snapshot_id, $record['snapshot_id'] );
		$this->assertSame( 'snapshot.zip', $record['file'] );
		$this->assertGreaterThan( time() + 590, $record['expires'] );
		$this->assertLessThanOrEqual( time() + 600, $record['expires'] );
		return array( $query, $key );
	}

	/**
	 * Both issue and redemption must reject missing user, right, or site scope.
	 *
	 * @dataProvider denied_access
	 * @param string $state Permission change.
	 */
	public function test_download_refuses_access_at_issue_and_redemption( string $state ) {
		list( $query, $key ) = $this->link();
		$deny_right          = static function ( $can, $type, $right ) use ( $state ) {
			return 'no execute_backups' === $state && 'dashboard' === $type && 'execute_backups' === $right ? false : $can;
		};
		$deny_site           = function ( $can, $site_id ) use ( $state ) {
			return 'access revoked' === $state && $this->site_id === (int) $site_id ? false : $can;
		};
		$scope               = function ( $allowed ) use ( $state ) {
			return 'restricted other site' === $state ? array( $this->other_site_id ) : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny_right, 10, 3 );
		add_filter( 'mainwp_check_site_access', $deny_site, 10, 2 );
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		if ( 'logged out' === $state ) {
			wp_set_current_user( 0 );
		} elseif ( 'other user' === $state ) {
			$this->set_current_user_as_admin();
			$query['_wpnonce'] = wp_create_nonce( 'mainwp_backup_snapshot_download' );
		}
		try {
			$this->assertWPError( MainWP_Backup_Download::redeem_download( $query ) );
			if ( 'other user' !== $state ) {
				$this->assertWPError( MainWP_Backup_Download::create_download_url( $this->snapshot_id ) );
			}
			$this->assertFileExists( $this->path );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny_right, 10 );
			remove_filter( 'mainwp_check_site_access', $deny_site, 10 );
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}
	}

	/**
	 * Permission refusal cases.
	 *
	 * @return array
	 */
	public static function denied_access(): array {
		return array(
			'logged out'            => array( 'logged out' ),
			'no execute_backups'    => array( 'no execute_backups' ),
			'restricted other site' => array( 'restricted other site' ),
			'access revoked'        => array( 'access revoked' ),
			'other user'            => array( 'other user' ),
		);
	}

	/**
	 * Token lifetime, nonce, and every bound identity must match.
	 *
	 * @dataProvider invalid_links
	 * @param string $state Link mutation.
	 */
	public function test_download_refuses_invalid_link( string $state ) {
		list( $query, $key ) = $this->link();
		if ( 'bad nonce' === $state ) {
			$query['_wpnonce'] = 'invalid';
		} elseif ( 'expired' === $state ) {
			$record            = get_site_transient( $key );
			$record['expires'] = time();
			set_site_transient( $key, $record, 600 );
		} elseif ( 'snapshot' === $state ) {
			++$query['snapshot_id'];
		} elseif ( 'site' === $state ) {
			$query['site_id'] = $this->other_site_id;
		} elseif ( 'artifact' === $state ) {
			$query['file'] = 'different.zip';
		} elseif ( 'array token' === $state ) {
			$query['token'] = array( $query['token'] );
		}
		$this->assertWPError( MainWP_Backup_Download::redeem_download( $query ) );
		$this->assertFileExists( $this->path );
	}

	/**
	 * Invalid request cases.
	 *
	 * @return array
	 */
	public static function invalid_links(): array {
		return array(
			'bad nonce'        => array( 'bad nonce' ),
			'expired'          => array( 'expired' ),
			'another snapshot' => array( 'snapshot' ),
			'another site'     => array( 'site' ),
			'another artifact' => array( 'artifact' ),
			'array token'      => array( 'array token' ),
		);
	}

	/** A redeemed token is gone before the streamer can open the file. */
	public function test_download_refuses_second_use() {
		list( $query, $key ) = $this->link();
		$this->assertSame( $this->path, MainWP_Backup_Download::redeem_download( $query ) );
		$this->assertFalse( get_site_transient( $key ) );
		$this->assertWPError( MainWP_Backup_Download::redeem_download( $query ) );
		$this->assertFileExists( $this->path );
	}

	/**
	 * Recorded files cannot escape the snapshot's own site at either check.
	 *
	 * @dataProvider unsafe_archives
	 * @param string $state Archive mutation.
	 */
	public function test_download_refuses_unavailable_or_unconfined_archive( string $state ) {
		list( $query ) = $this->link();
		global $wpdb;
		$updates = array();
		if ( 'incremental' === $state ) {
			$updates['type'] = 'incremental';
		} elseif ( 'remote only' === $state ) {
			unlink( $this->path );
		} else {
			$other = MainWP_Backup_Private_Root::site_path( $this->other_site_id );
			mkdir( $other, 0700, true );
			file_put_contents( $other . 'snapshot.zip', 'other site bytes' );
			if ( 'symlink' === $state ) {
				unlink( $this->path );
				symlink( $other . 'snapshot.zip', $this->path );
			} else {
				$updates['archives'] = wp_json_encode(
					array(
						array(
							'file' => 'snapshot.zip',
							'path' => $other . 'snapshot.zip',
							'role' => 'full',
						),
					)
				);
			}
		}
		if ( $updates ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), $updates, array( 'id' => $this->snapshot_id ) );
		}
		$this->assertWPError( MainWP_Backup_Download::redeem_download( $query ) );
		$this->assertWPError( MainWP_Backup_Download::create_download_url( $this->snapshot_id ) );
	}

	/**
	 * File refusal cases.
	 *
	 * @return array
	 */
	public static function unsafe_archives(): array {
		return array(
			'another site folder'  => array( 'other site' ),
			'symlink outside site' => array( 'symlink' ),
			'incremental'          => array( 'incremental' ),
			'remote only'          => array( 'remote only' ),
		);
	}

	/**
	 * Run the real stream with isolated PHP header and connection spies.
	 *
	 * @param string $mode complete, abort (after the first chunk), read-error (second read fails) or first-read-error.
	 * @return array Stream result and captured bytes.
	 */
	private function stream( string $mode ): array {
		$command = array( PHP_BINARY, __DIR__ . '/fixtures/snapshot-download-stream.php', $this->path, $mode );
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$output  = stream_get_contents( $pipes[1] );
		$error   = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}

	/** Full, database-only, and files-only downloads retain the recorded bytes. */
	public function test_recorded_archive_streams_byte_for_byte_and_returns() {
		foreach ( array( 'full', 'db', 'files' ) as $type ) {
			global $wpdb;
			$archives = array(
				array(
					'file' => 'snapshot.zip',
					'path' => $this->path,
					'role' => 'db' === $type ? 'db' : 'full',
					'size' => filesize( $this->path ),
				),
			);
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'type'     => $type,
					'archives' => wp_json_encode( $archives ),
				),
				array( 'id' => $this->snapshot_id )
			);
			list( $query, $key ) = $this->link();
			$this->assertSame( $this->path, MainWP_Backup_Download::redeem_download( $query ) );
			$this->assertFalse( get_site_transient( $key ) );
			$result = $this->stream( 'complete' );
			$this->assertTrue( $result['completed'] );
			$this->assertSame( file_get_contents( $this->path ), base64_decode( $result['bytes'] ) );
			$this->assertContains( 'Content-Length: ' . filesize( $this->path ), $result['headers'] );
			$this->assertSame( array( true, false ), $result['ignore_abort'] );
			$this->assertFileExists( $this->path );
		}
	}

	/** An abort after a chunk returns false and leaves the archive available. */
	public function test_client_abort_mid_stream_returns_without_fatal_error() {
		$result = $this->stream( 'abort' );
		$this->assertFalse( $result['completed'] );
		$bytes = base64_decode( $result['bytes'] );
		$this->assertNotEmpty( $bytes );
		$this->assertLessThan( filesize( $this->path ), strlen( $bytes ) );
		$this->assertSame( substr( file_get_contents( $this->path ), 0, strlen( $bytes ) ), $bytes );
		$this->assertSame( array( true, false ), $result['ignore_abort'] );
		$this->assertFileExists( $this->path );
	}

	/** A multi-gigabyte archive must not hit max_execution_time mid-stream. */
	public function test_stream_lifts_the_execution_limit() {
		$result = $this->stream( 'complete' );
		$this->assertTrue( $result['completed'] );
		$this->assertSame( array( 0 ), $result['time_limits'] );
	}

	/** Once bytes are out, a read failure ends the body short instead of appending an error page. */
	public function test_read_failure_after_output_ends_short_without_error() {
		$result = $this->stream( 'read-error' );
		$this->assertFalse( $result['completed'] );
		$bytes = base64_decode( $result['bytes'] );
		$this->assertSame( 64 * 1024, strlen( $bytes ) );
		$this->assertSame( substr( file_get_contents( $this->path ), 0, 64 * 1024 ), $bytes );
		$this->assertContains( 'Content-Length: ' . filesize( $this->path ), $result['headers'] );
		$this->assertSame( array( true, false ), $result['ignore_abort'] );
	}

	/** A failed first read sends no archive headers, so the error page is not saved as the archive. */
	public function test_read_failure_before_output_sends_no_archive_headers() {
		$result = $this->stream( 'first-read-error' );
		$this->assertSame( 'error', $result['completed'] );
		$this->assertSame( '', base64_decode( $result['bytes'] ) );
		$this->assertSame( array(), $result['headers'] );
		$this->assertFileExists( $this->path );
	}

	/** Mutex files stay on disk while the accordion still exposes archives. */
	public function test_accordion_hides_reclaim_files_without_deleting() {
		update_option( 'mainwp_enableNativeBackups', 1 );
		$mutex = $this->path . '.downloading.reclaim';
		file_put_contents( $mutex, 'mutex' );
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		ob_start();
		MainWP_Manage_Sites::show_backups( $website );
		$output = ob_get_clean();
		$this->assertStringNotContainsString( '.downloading.reclaim', $output );
		$this->assertStringContainsString( 'snapshot.zip', $output );
		$this->assertStringContainsString( 'mwpdl=', $output );
		$this->assertFileExists( $mutex );
	}
}
