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
class MainWP_Download_Lock_Test extends \WP_UnitTestCase {

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
