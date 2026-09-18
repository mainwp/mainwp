<?php
/**
 * MainWP Download Lock Tests
 *
 * Tests for the download lock files that replace the fragile
 * file-modification-time check for in-progress downloads.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_File_Manager;

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct file IO in tests.

/**
 * Class MainWP_Download_Lock_Test
 */
class MainWP_Download_Lock_Test extends MainWP_Abilities_Test_Case {

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
		foreach ( glob( $this->dir . '*' ) ?: array() as $left_over ) {
			unlink( $left_over );
		}
		rmdir( $this->dir );
		parent::tear_down();
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
	 * A corrupt lock file counts as stale.
	 *
	 * @return void
	 */
	public function test_corrupt_lock_is_replaced() {
		$file = $this->dir . 'backup.zip';
		file_put_contents( $file . '.downloading', 'not json at all' );

		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $file, 42, 7 ) );
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
}
