<?php
/**
 * MainWP Download Lock Tests
 *
 * Tests for the download lock files that replace the fragile
 * file-modification-time check for in-progress downloads.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard;

/**
 * Hosts without hard links must exercise exclusive publication too.
 *
 * @param string $target Source pathname.
 * @param string $link Destination pathname.
 * @return bool
 */
function link( $target, $link ) {
	return Tests\MainWP_Download_Lock_Test::$disable_hard_links ? false : \link( $target, $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- delegates to the real filesystem unless fallback coverage is requested.
}

namespace MainWP\Dashboard\Tests; // phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- the filesystem or clock stub must share the production namespace.

use MainWP\Dashboard\MainWP_Backup_File_Manager;

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct file IO in tests.

/**
 * Class MainWP_Download_Lock_Test
 */
class MainWP_Download_Lock_Test extends MainWP_Abilities_Test_Case { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- the switchable stub belongs with its regression tests.

	/**
	 * Force hosts with hard links to cover the fallback.
	 *
	 * @var bool
	 */
	public static $disable_hard_links = false;

	/**
	 * Working directory for lock files.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Create a scratch directory.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = get_temp_dir() . 'mainwp-lock-tests-' . uniqid() . '/';
		mkdir( $this->dir, 0777, true );
	}

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	public function tear_down() {
		self::$disable_hard_links = false;
		foreach ( glob( $this->dir . '*' ) ?: array() as $left_over ) {
			unlink( $left_over );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Unsupported hard links must still permit exactly one owner and release.
	 */
	public function test_fallback_publication_acquires_exclusively_and_releases() {
		self::$disable_hard_links = true;
		$file                     = $this->dir . 'fallback.zip';
		$lock                     = $file . '.downloading';
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		$data = json_decode( file_get_contents( $lock ), true );
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data['token'] );
		$this->assertSame( 42, $data['site_id'] );
		$this->assertSame( 7, $data['task_id'] );
		$this->assertFalse( MainWP_Backup_File_Manager::acquire_download_lock( $file, 43, 8 ) );
		$this->assertSame( $data, json_decode( file_get_contents( $lock ), true ) );
		MainWP_Backup_File_Manager::release_download_lock( $file );
		$this->assertFileDoesNotExist( $lock );
	}

	/**
	 * A fallback writer without the reclaim mutex cannot safely publish an empty pathname.
	 */
	public function test_fallback_refuses_publication_while_mutex_held() {
		self::$disable_hard_links = true;
		$file                     = $this->dir . 'fallback-contended.zip';
		$lock                     = $file . '.downloading';
		$mutex                    = fopen( $lock . '.reclaim', 'c' );
		$this->assertTrue( flock( $mutex, LOCK_EX | LOCK_NB ) );
		try {
			$this->assertFalse( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
			$this->assertFileDoesNotExist( $lock );
		} finally {
			flock( $mutex, LOCK_UN );
			fclose( $mutex );
		}
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		MainWP_Backup_File_Manager::release_download_lock( $file );
	}

	/**
	 * An owner releasing after the existence check must not cause false contention.
	 */
	public function test_vanished_lock_retries_exclusive_acquisition() {
		$file = $this->dir . 'vanished.zip';
		$lock = $file . '.downloading';
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		$released = false;
		$release  = function ( $path ) use ( $file, $lock, &$released ) {
			if ( ! $released && str_starts_with( $path, $lock . '.' ) && str_ends_with( $path, '.tmp' ) ) {
				$released = true;
				MainWP_Backup_File_Manager::release_download_lock( $file );
			}
			return $path;
		};
		add_filter( 'wp_delete_file', $release );
		try {
			$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 43, 8 ) );
			$this->assertTrue( $released );
			$this->assertSame( 43, json_decode( file_get_contents( $lock ), true )['site_id'] );
		} finally {
			remove_filter( 'wp_delete_file', $release );
			MainWP_Backup_File_Manager::release_download_lock( $file );
		}
	}

	/**
	 * Reclaiming a stale lock and observing a vanished replacement each need a retry.
	 */
	public function test_acquire_retries_after_stale_reclaim_and_vanished_lock() {
		self::$disable_hard_links = true;
		$file                     = $this->dir . 'two-retries.zip';
		$lock                     = $file . '.downloading';
		file_put_contents( $lock, wp_json_encode( array( 'started' => time() - 2 * HOUR_IN_SECONDS ) ) );
		$attempts = 0;
		$vanished = false;
		$replace  = function ( $password ) use ( $lock, &$attempts ) {
			++$attempts;
			if ( 2 === $attempts ) {
				$this->assertFileDoesNotExist( $lock, 'Attempt one must reclaim the stale lock.' );
				file_put_contents(
					$lock,
					wp_json_encode(
						array(
							'started' => time(),
							'token'   => 'racing-owner',
						)
					)
				);
			}
			return $password;
		};
		$release  = function ( $path ) use ( $lock, &$attempts, &$vanished ) {
			if ( 2 === $attempts && ! $vanished && str_starts_with( $path, $lock . '.' ) && str_ends_with( $path, '.tmp' ) ) {
				$this->assertFileExists( $lock, 'The replacement must block attempt two before it vanishes.' );
				unlink( $lock );
				$vanished = true;
			}
			return $path;
		};
		add_filter( 'random_password', $replace );
		add_filter( 'wp_delete_file', $release );
		try {
			$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
			$this->assertTrue( $vanished );
			$this->assertSame( 3, $attempts );
			$data = json_decode( file_get_contents( $lock ), true );
			$this->assertSame( 42, $data['site_id'] );
			$this->assertSame( 7, $data['task_id'] );
			$this->assertNotEmpty( $data['token'] );
		} finally {
			remove_filter( 'random_password', $replace );
			remove_filter( 'wp_delete_file', $release );
			self::$disable_hard_links = false;
			MainWP_Backup_File_Manager::release_download_lock( $file );
		}
	}

	/**
	 * Acquiring a lock creates the lock file with its metadata.
	 *
	 * @return void
	 */
	public function test_acquire_creates_lock_file_with_metadata() {
		$file = $this->dir . 'backup.zip';

		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );

		$lock = $file . '.downloading';
		$this->assertFileExists( $lock );
		$data = json_decode( file_get_contents( $lock ), true );
		$this->assertSame( 42, $data['site_id'] );
		$this->assertSame( 7, $data['task_id'] );
		$this->assertGreaterThan( 0, $data['started'] );
	}

	/**
	 * Transfer heartbeats renew our sidecar but cannot overwrite a new owner.
	 */
	public function test_download_progress_refreshes_only_owned_lock() {
		$file    = $this->dir . 'heartbeat.zip';
		$lock    = $file . '.downloading';
		$site_id = $this->create_test_site();
		$website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
		$key     = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $pem );
		$website->privkey                = base64_encode( $pem );
		$website->download_token_support = '0';
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, $site_id, 7 ) );
		$filter = function () use ( $file, $lock ) {
			$data            = json_decode( file_get_contents( $lock ), true );
			$data['started'] = time() - 120;
			unlink( $lock );
			do_action( 'mainwp_download_to_file_progress', $file, 50 );
			file_put_contents( $lock, wp_json_encode( $data ) );
			do_action( 'mainwp_download_to_file_progress', $file, 100 );
			$renewed = json_decode( file_get_contents( $lock ), true );
			$this->assertGreaterThan( $data['started'], $renewed['started'] );
			$this->assertSame( $data['token'], $renewed['token'] );
			$data['token'] = 'replacement-owner';
			file_put_contents( $lock, wp_json_encode( $data ) );
			do_action( 'mainwp_download_to_file_progress', $file, 200 );
			$this->assertSame( $data, json_decode( file_get_contents( $lock ), true ) );
			file_put_contents( $file, 'downloaded bytes' );
			return true;
		};
		$before = has_action( 'mainwp_download_to_file_progress' );
		add_filter( 'mainwp_download_to_file_pre', $filter );
		try {
			MainWP_Backup_File_Manager::download_backup_artifact( $website, 'heartbeat.zip', $file, false, null, null );
			$this->assertSame( $before, has_action( 'mainwp_download_to_file_progress' ) );
		} finally {
			remove_filter( 'mainwp_download_to_file_pre', $filter );
			MainWP_Backup_File_Manager::release_download_lock( $file );
		}
		$this->assertFileExists( $lock, 'Releasing the old token must preserve the replacement.' );
	}

	/**
	 * A failed temporary write must leave the owned sidecar intact.
	 */
	public function test_failed_refresh_write_preserves_sidecar() {
		$file = $this->dir . 'unwritable-refresh.zip';
		$lock = $file . '.downloading';
		$temp = $lock . '.tmp';
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		$data            = json_decode( file_get_contents( $lock ), true );
		$data['started'] = time() - 120;
		$original        = wp_json_encode( $data );
		file_put_contents( $lock, $original );
		file_put_contents( $temp, 'blocked' );
		chmod( $temp, 0400 );
		try {
			if ( is_writable( $temp ) ) {
				$this->markTestSkipped( 'The test process bypasses file permissions.' );
			}
			$refresh = new \ReflectionMethod( MainWP_Backup_File_Manager::class, 'refresh_download_lock' );
			$this->assertFalse( $refresh->invoke( null, $file ) );
			$this->assertSame( $original, file_get_contents( $lock ) );
			$this->assertFileDoesNotExist( $temp );
		} finally {
			if ( file_exists( $temp ) ) {
				chmod( $temp, 0600 );
				unlink( $temp );
			}
			MainWP_Backup_File_Manager::release_download_lock( $file );
		}
	}

	/**
	 * A live lock blocks a second acquisition.
	 *
	 * @return void
	 */
	public function test_live_lock_blocks_second_acquire() {
		$file = $this->dir . 'backup.zip';

		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		$this->assertFalse( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
	}

	/**
	 * A stale lock (older than the TTL) is replaced.
	 *
	 * @return void
	 */
	public function test_stale_lock_is_replaced() {
		$file = $this->dir . 'backup.zip';
		$lock = $file . '.downloading';
		file_put_contents(
			$lock,
			wp_json_encode(
				array(
					'started' => time() - 2 * HOUR_IN_SECONDS,
					'site_id' => 42,
					'task_id' => 7,
				)
			)
		);

		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );

		$data = json_decode( file_get_contents( $lock ), true );
		$this->assertGreaterThan( time() - 60, $data['started'], 'The stale lock must be replaced with a fresh one.' );
	}

	/**
	 * A corrupt lock must age past the publication grace period before replacement.
	 *
	 * @return void
	 */
	public function test_corrupt_lock_is_replaced() {
		$file = $this->dir . 'backup.zip';
		file_put_contents( $file . '.downloading', 'not json at all' );
		touch( $file . '.downloading', time() - 61 );

		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
	}

	/**
	 * A contender must allow an exclusive creator time to finish publication.
	 */
	public function test_empty_lock_requires_publication_grace_before_reclaim() {
		$file = $this->dir . 'empty.zip';
		$lock = $file . '.downloading';
		file_put_contents( $lock, '' );
		$this->assertFalse( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		$this->assertFalse( $this->reclaim( $lock ) );
		$this->assertSame( '', file_get_contents( $lock ) );

		touch( $lock, time() - 61 );
		$this->assertTrue( $this->reclaim( $lock ) );
		$this->assertFileDoesNotExist( $lock );
		file_put_contents( $lock, '' );
		touch( $lock, time() - 61 );
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		$this->assertSame( 42, json_decode( file_get_contents( $lock ), true )['site_id'] );
	}

	/**
	 * Crashed publication sidecars must not accumulate or cost a live writer its file.
	 */
	public function test_cleanup_sidecars_preserves_live_writers_and_mutexes() {
		$lock = $this->dir . 'sidecar.zip.downloading';
		foreach ( array( '.tmp', '.' . wp_generate_uuid4() . '.tmp' ) as $suffix ) {
			$temp = $lock . $suffix;
			file_put_contents( $temp, '' );
			MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
			$this->assertFileExists( $temp );
			touch( $temp, time() - 61 );
			$mutex = fopen( $lock . '.reclaim', 'c' );
			flock( $mutex, LOCK_EX );
			try {
				MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
				$this->assertFileExists( $temp );
			} finally {
				flock( $mutex, LOCK_UN );
				fclose( $mutex );
			}
			MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
			$this->assertFileDoesNotExist( $temp );
			file_put_contents( $temp, wp_json_encode( array( 'started' => time() ) ) );
			MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
			$this->assertFileExists( $temp );
			file_put_contents( $temp, wp_json_encode( array( 'started' => time() - 2 * HOUR_IN_SECONDS ) ) );
			MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
			$this->assertFileDoesNotExist( $temp );
			$this->assertFileExists( $lock . '.reclaim' );
		}
	}

	/**
	 * An orphan mutex can be swept only after its own mtime passes the lock TTL.
	 */
	public function test_cleanup_removes_orphan_mutex_older_than_ttl() {
		$mutex_file = $this->dir . 'orphan.zip.downloading.reclaim';
		file_put_contents( $mutex_file, '' );
		touch( $mutex_file, time() - 2 * HOUR_IN_SECONDS );

		MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );

		$this->assertFileDoesNotExist( $mutex_file );
	}

	/**
	 * Site cleanup reaches stale locks and orphan mutexes in immediate snapshot directories.
	 */
	public function test_cleanup_sweeps_snapshot_download_locks_and_mutexes() {
		$run_dir = $this->dir . 'snapshot-abc/';
		mkdir( $run_dir );
		$lock  = $run_dir . 'stale.zip.downloading';
		$old   = $run_dir . 'old.zip.downloading.reclaim';
		$young = $run_dir . 'young.zip.downloading.reclaim';
		try {
			file_put_contents( $lock, wp_json_encode( array( 'started' => time() - 2 * HOUR_IN_SECONDS ) ) );
			file_put_contents( $old, '' );
			touch( $old, time() - 2 * HOUR_IN_SECONDS );
			file_put_contents( $young, '' );

			MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );

			$this->assertFileDoesNotExist( $lock );
			$this->assertFileDoesNotExist( $old );
			$this->assertFileExists( $young );
		} finally {
			foreach ( glob( $run_dir . '*' ) ?: array() as $left_over ) {
				unlink( $left_over );
			}
			rmdir( $run_dir );
		}
	}

	/**
	 * An old mutex stays while its lock exists and becomes eligible once it is orphaned.
	 */
	public function test_cleanup_keeps_mutex_while_lock_exists() {
		$lock       = $this->dir . 'live.zip.downloading';
		$mutex_file = $lock . '.reclaim';
		file_put_contents( $lock, wp_json_encode( array( 'started' => time() ) ) );
		file_put_contents( $mutex_file, '' );
		touch( $mutex_file, time() - 2 * HOUR_IN_SECONDS );

		MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
		$this->assertFileExists( $lock );
		$this->assertFileExists( $mutex_file );

		unlink( $lock );
		MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
		$this->assertFileDoesNotExist( $mutex_file );
	}

	/**
	 * A young orphan stays until its own mtime passes the lock TTL.
	 */
	public function test_cleanup_keeps_orphan_mutex_younger_than_ttl() {
		$mutex_file = $this->dir . 'young.zip.downloading.reclaim';
		file_put_contents( $mutex_file, '' );
		touch( $mutex_file, time() - 60 );

		MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
		$this->assertFileExists( $mutex_file );

		touch( $mutex_file, time() - 2 * HOUR_IN_SECONDS );
		MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
		$this->assertFileDoesNotExist( $mutex_file );
	}

	/**
	 * An old orphan stays while a separate file handle holds its flock.
	 */
	public function test_cleanup_keeps_orphan_mutex_while_flocked() {
		$mutex_file = $this->dir . 'held.zip.downloading.reclaim';
		file_put_contents( $mutex_file, '' );
		touch( $mutex_file, time() - 2 * HOUR_IN_SECONDS );
		$mutex = fopen( $mutex_file, 'c' );
		$this->assertTrue( flock( $mutex, LOCK_EX | LOCK_NB ) );
		try {
			MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
			$this->assertFileExists( $mutex_file );
		} finally {
			flock( $mutex, LOCK_UN );
			fclose( $mutex );
		}

		MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );
		$this->assertFileDoesNotExist( $mutex_file );
	}

	/**
	 * Releasing removes the lock file.
	 *
	 * @return void
	 */
	public function test_release_removes_lock_file() {
		$file = $this->dir . 'backup.zip';
		MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 );

		MainWP_Backup_File_Manager::release_download_lock( $file );

		$this->assertFileDoesNotExist( $file . '.downloading' );
	}

	/**
	 * A process whose lock was stale-replaced by another must not delete
	 * the replacement on release: the on-disk token no longer matches the
	 * one it wrote, so the release is a no-op and the new owner's lock
	 * survives.
	 *
	 * @return void
	 */
	public function test_release_leaves_foreign_lock_alone() {
		$file = $this->dir . 'backup.zip';
		$lock = $file . '.downloading';

		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );

		// Another process stale-replaced the lock with its own token.
		$foreign = wp_json_encode(
			array(
				'started' => time(),
				'site_id' => 43,
				'task_id' => 8,
				'token'   => 'foreign-owner-token',
			)
		);
		file_put_contents( $lock, $foreign );

		MainWP_Backup_File_Manager::release_download_lock( $file );

		$this->assertFileExists( $lock, 'A release must not delete a lock another process now owns.' );
		$data = json_decode( file_get_contents( $lock ), true );
		$this->assertSame( 'foreign-owner-token', $data['token'], 'The foreign lock must survive untouched.' );

		unlink( $lock );
	}

	/**
	 * A reclaimer that exclusive-created its replacement lock but has not
	 * yet written its JSON token leaves the file briefly empty. A stale
	 * prior owner releasing in that window must not delete the reclaimer's
	 * fresh lock: an empty or unparseable on-disk token is not this
	 * process's token, so the release is a no-op.
	 *
	 * @return void
	 */
	public function test_release_leaves_untokened_replacement_lock_alone() {
		$file = $this->dir . 'backup.zip';
		$lock = $file . '.downloading';

		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );

		// A concurrent reclaimer exclusive-created the replacement lock but
		// has not written its token yet: the file is empty on disk.
		file_put_contents( $lock, '' );

		MainWP_Backup_File_Manager::release_download_lock( $file );

		$this->assertFileExists( $lock, 'A release must not delete a reclaimer\'s fresh, not-yet-tokened lock.' );

		unlink( $lock );
	}

	/**
	 * The acquire is an exclusive create: a lock file appearing between
	 * the staleness check and the write is never silently overwritten. A
	 * pre-existing live lock without a token (pre-upgrade format) still
	 * refuses.
	 *
	 * @return void
	 */
	public function test_acquire_refuses_tokenless_live_lock() {
		$file = $this->dir . 'backup.zip';
		file_put_contents(
			$file . '.downloading',
			wp_json_encode(
				array(
					'started' => time(),
					'site_id' => 1,
					'task_id' => 1,
				)
			)
		);

		$this->assertFalse( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
	}

	/**
	 * Invoke the private reclaim helper.
	 *
	 * @param string $lock_file Lock path.
	 *
	 * @return bool Reclaim outcome.
	 */
	private function reclaim( $lock_file ) {
		$method = new \ReflectionMethod( MainWP_Backup_File_Manager::class, 'reclaim_stale_lock' );
		$method->setAccessible( true );
		return $method->invoke( null, $lock_file );
	}

	/**
	 * The Codex-flagged invalidation: a contender that validated the OLD
	 * stale lock must not be able to delete the FRESH lock a concurrent
	 * winner created in the meantime. All deletes go through the reclaim,
	 * which re-reads under the mutex - a fresh lock refuses the reclaim
	 * and survives.
	 *
	 * @return void
	 */
	public function test_reclaim_refuses_fresh_lock_after_stale_observation() {
		$lock = $this->dir . 'backup.zip.downloading';

		// The contender observed this stale lock and decided to reclaim...
		file_put_contents(
			$lock,
			wp_json_encode(
				array(
					'started' => time() - 2 * HOUR_IN_SECONDS,
					'site_id' => 1,
					'task_id' => 1,
				)
			)
		);
		// ...but before its delete lands, a concurrent winner reclaimed and
		// wrote a fresh lock with its own token.
		file_put_contents(
			$lock,
			wp_json_encode(
				array(
					'started' => time(),
					'site_id' => 2,
					'task_id' => 2,
					'token'   => 'winner-token',
				)
			)
		);

		$this->assertFalse( $this->reclaim( $lock ), 'A reclaim decided against stale state must refuse once the lock is fresh again.' );
		$this->assertFileExists( $lock, 'The winner\'s fresh lock must survive the losing contender.' );
		$data = json_decode( file_get_contents( $lock ), true );
		$this->assertSame( 'winner-token', $data['token'] );
	}

	/**
	 * A still-stale lock reclaims: the file is removed and the caller may
	 * retry the exclusive create.
	 *
	 * @return void
	 */
	public function test_reclaim_removes_still_stale_lock() {
		$lock = $this->dir . 'backup.zip.downloading';
		file_put_contents(
			$lock,
			wp_json_encode(
				array(
					'started' => time() - 2 * HOUR_IN_SECONDS,
					'site_id' => 1,
					'task_id' => 1,
				)
			)
		);

		$this->assertTrue( $this->reclaim( $lock ) );
		$this->assertFileDoesNotExist( $lock );
	}

	/**
	 * While another process holds the reclaim mutex, a contender backs off
	 * instead of racing the takeover.
	 *
	 * @return void
	 */
	public function test_reclaim_backs_off_while_mutex_held() {
		$lock = $this->dir . 'backup.zip.downloading';
		file_put_contents(
			$lock,
			wp_json_encode(
				array(
					'started' => time() - 2 * HOUR_IN_SECONDS,
					'site_id' => 1,
					'task_id' => 1,
				)
			)
		);

		// Model the concurrent reclaimer holding the per-path mutex.
		$mutex = fopen( $lock . '.reclaim', 'c' );
		flock( $mutex, LOCK_EX );

		try {
			$this->assertFalse( $this->reclaim( $lock ), 'A held mutex must refuse the takeover.' );
			$this->assertFileExists( $lock, 'The lock must be untouched while another process reclaims.' );
		} finally {
			flock( $mutex, LOCK_UN );
			fclose( $mutex );
		}
	}

	/**
	 * Startup cleanup removes only stale lock files.
	 *
	 * @return void
	 */
	public function test_cleanup_removes_only_stale_locks() {
		$stale = $this->dir . 'old.zip.downloading';
		$fresh = $this->dir . 'new.zip.downloading';
		file_put_contents(
			$stale,
			wp_json_encode(
				array(
					'started' => time() - 2 * HOUR_IN_SECONDS,
					'site_id' => 1,
					'task_id' => 1,
				)
			)
		);
		file_put_contents(
			$fresh,
			wp_json_encode(
				array(
					'started' => time(),
					'site_id' => 2,
					'task_id' => 2,
				)
			)
		);

		MainWP_Backup_File_Manager::cleanup_stale_download_locks( $this->dir );

		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists( $fresh );
	}
	/**
	 * Hard links expose complete payloads; fallback empty windows rely on the reclaim mutex.
	 */
	public function test_link_publication_exposes_only_complete_payloads() {
		self::$disable_hard_links = false;
		$file                     = $this->dir . 'publication.zip';
		$lock                     = $file . '.downloading';
		$observations             = 0;
		$observe                  = function ( $value ) use ( $lock, &$observations ) {
			if ( file_exists( $lock ) ) {
				$data = json_decode( file_get_contents( $lock ), true );
				$this->assertIsArray( $data, 'A visible lock must already contain complete JSON.' );
				$this->assertNotEmpty( $data['token'] ?? '', 'A visible lock must identify its owner.' );
				++$observations;
			}
			return $value;
		};
		add_filter( 'random_password', $observe );
		add_filter( 'wp_delete_file', $observe );
		try {
			$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
			$this->assertFalse( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
			$this->assertGreaterThan( 0, $observations );
			$this->assertSame( array(), glob( $lock . '.*.tmp' ) );
		} finally {
			remove_filter( 'random_password', $observe );
			remove_filter( 'wp_delete_file', $observe );
			MainWP_Backup_File_Manager::release_download_lock( $file );
		}
	}

	/**
	 * A replacement owner must survive, and an owned delete must hold the reclaim mutex.
	 */
	public function test_release_serializes_deletion_and_preserves_replacement() {
		$file = $this->dir . 'serialized.zip';
		$lock = $file . '.downloading';
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		$checked = false;
		$observe = function ( $path ) use ( $lock, &$checked ) {
			if ( $lock === $path ) {
				$mutex = fopen( $lock . '.reclaim', 'c' );
				try {
					$this->assertFalse( flock( $mutex, LOCK_EX | LOCK_NB ), 'Release must exclude a concurrent reclaimer until deletion finishes.' );
					$checked = true;
				} finally {
					fclose( $mutex );
				}
			}
			return $path;
		};
		add_filter( 'wp_delete_file', $observe );
		try {
			MainWP_Backup_File_Manager::release_download_lock( $file );
			$this->assertTrue( $checked );
		} finally {
			remove_filter( 'wp_delete_file', $observe );
		}
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
		$replacement = wp_json_encode(
			array(
				'started' => time(),
				'token'   => 'replacement',
			)
		);
		file_put_contents( $lock, $replacement );
		MainWP_Backup_File_Manager::release_download_lock( $file );
		$this->assertSame( $replacement, file_get_contents( $lock ) );
	}
}
