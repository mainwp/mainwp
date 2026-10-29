<?php
/**
 * MainWP Backup Sweeper Exemption Tests
 *
 * The legacy backups-on-server sweeper prunes the flat per-site download
 * dir before every legacy-format download. Files recorded by complete
 * backup snapshots belong to snapshot retention, so the sweeper must
 * neither count nor delete them.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Sweeper_Exemption_Test
 */
class MainWP_Backup_Sweeper_Exemption_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Isolate native writes.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
	}


	/**
	 * Created fixture dirs for cleanup.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

	/**
	 * Site ids whose snapshot rows need cleanup.
	 *
	 * @var int[]
	 */
	private $snapshot_site_ids = array();

	/**
	 * Clean fixture rows, files, and options.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		foreach ( $this->snapshot_site_ids as $site_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
		$this->snapshot_site_ids = array();

		foreach ( $this->fixture_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				// Reused site directories can contain artifacts owned by other tests.
				if ( 0 === strpos( basename( $dir ), 'test-sweep-' ) ) {
					array_map( 'unlink', array_filter( glob( $dir . '/*' ) ?: array(), 'is_file' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
				}
				if ( ! glob( $dir . '/*' ) ) {
					rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
				}
			}
		}
		$this->fixture_dirs = array();

		delete_option( 'mainwp_backupsOnServer' );

		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Create a token-capable site with a real RSA private key.
	 *
	 * @return object Website.
	 */
	private function make_site() {
		global $wpdb;

		$site_id = $this->create_test_site();

		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $privkey_pem );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'privkey' => base64_encode( $privkey_pem ) ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions -- test fixture.

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'download_token_support', '1' );
		return MainWP_DB::instance()->get_website_by_id( $site_id );
	}

	/**
	 * Create the flat download dir the sweep runs over, inside the site's
	 * own backup directory - the download sink confines its target there.
	 *
	 * @param int $site_id Site the dir belongs to.
	 *
	 * @return string Dir with trailing slash.
	 */
	private function make_flat_dir( $site_id ) {
		$site_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( (string) $site_id );
		$dir      = $site_dir . 'test-sweep-' . uniqid();
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;
		$this->fixture_dirs[] = untrailingslashit( $site_dir );
		return trailingslashit( $dir );
	}

	/**
	 * Seed an archive file with a fixed mtime.
	 *
	 * @param string $dir   Flat dir (trailing slash).
	 * @param string $name  File name.
	 * @param int    $mtime Modification time.
	 *
	 * @return string Absolute path.
	 */
	private function seed_archive( $dir, $name, $mtime ) {
		$path = $dir . $name;
		file_put_contents( $path, "PK\x03\x04" . str_repeat( 'z', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		touch( $path, $mtime );
		return $path;
	}

	/**
	 * Record a complete snapshot row whose archives list carries a path.
	 *
	 * @param int    $site_id Site id.
	 * @param string $path    Local artifact path the snapshot records.
	 *
	 * @return int Snapshot id.
	 */
	private function record_snapshot_over( $site_id, $path ) {
		$this->snapshot_site_ids[] = (int) $site_id;
		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 990400,
				'site_id'       => (int) $site_id,
				'type'          => 'full',
				'archives'      => array(
					array(
						'file' => basename( $path ),
						'size' => 36,
						'role' => 'full',
						'path' => $path,
					),
				),
				'manifest_path' => '',
			)
		);
	}

	/**
	 * Mock the token callable and the transfer layer so the download that
	 * follows the sweep succeeds.
	 *
	 * @return void
	 */
	private function mock_successful_download() {
		$content = "\x1f\x8b\x08" . str_repeat( 'z', 64 );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $content ) {
				unset( $website, $params );
				if ( 'backup_download_token' !== $what ) {
					return $result;
				}
				return array(
					'token' => str_repeat( 'a', 32 ),
					'hash'  => hash( 'sha256', $content ),
					'size'  => strlen( $content ),
				);
			},
			10,
			4
		);
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture write.
				return true;
			},
			10,
			3
		);
	}

	/**
	 * A snapshot-recorded archive in the flat dir survives the sweep while
	 * an unrecorded one is deleted.
	 *
	 * @return void
	 */
	public function test_sweeper_skips_snapshot_recorded_file_in_backup_download_file() {
		$website = $this->make_site();
		$dir     = $this->make_flat_dir( (int) $website->id );

		update_option( 'mainwp_backupsOnServer', 1 );

		$protected   = $this->seed_archive( $dir, 'protected.zip', time() - 600 );
		$unprotected = $this->seed_archive( $dir, 'unprotected.zip', time() - 300 );
		$this->record_snapshot_over( (int) $website->id, $protected );

		$this->mock_successful_download();

		MainWP_Backup_File_Manager::backup_download_file( (int) $website->id, 'full', 'backup-remote.tar.gz', $dir . 'new-backup.tar.gz' );

		$this->assertFileExists( $protected, 'A snapshot-recorded archive must survive the legacy sweep.' );
		$this->assertFileDoesNotExist( $unprotected, 'An unrecorded archive past the retention count must be deleted.' );
	}

	/**
	 * A snapshot recorded after the scan protects its file without using a retention slot.
	 */
	public function test_sweeper_rechecks_snapshot_protection_before_delete() {
		$website = $this->make_site();
		$site_id = (int) $website->id;
		$dir     = $this->make_flat_dir( $site_id );
		update_option( 'mainwp_backupsOnServer', 2 );

		$old       = $this->seed_archive( $dir, 'old.zip', time() - 900 );
		$protected = $this->seed_archive( $dir, 'newly-protected.zip', time() - 600 );
		$newest    = $this->seed_archive( $dir, 'newest.zip', time() - 300 );
		$this->mock_successful_download();

		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$reads          = 0;
		$record_on_read = function ( $query ) use ( $snapshot_table, $site_id, $protected, &$reads ) {
			if ( str_contains( $query, 'SELECT archives, manifest_path FROM ' . $snapshot_table ) ) {
				++$reads;
				if ( 2 === $reads ) {
					$this->record_snapshot_over( $site_id, $protected );
				}
			}
			return $query;
		};
		add_filter( 'query', $record_on_read );
		try {
			MainWP_Backup_File_Manager::backup_download_file( $site_id, 'full', 'backup-remote.tar.gz', $dir . 'new-backup.tar.gz' );
		} finally {
			remove_filter( 'query', $record_on_read );
		}

		$this->assertFileExists( $protected, 'The newly snapshot-protected archive must survive the sweep.' );
		$this->assertSame( 2, $reads, 'The protection set must be read again after the scan.' );
		$this->assertFileExists( $newest, 'The newly protected archive must not consume a retention slot.' );
		$this->assertFileDoesNotExist( $old );
	}

	/**
	 * Open-run paths and live locks protect unfinished downloads from legacy retention.
	 *
	 * @return void
	 */
	public function test_sweeper_preserves_open_run_and_live_downloads() {
		$website = $this->make_site();
		$site_id = (int) $website->id;
		$dir     = $this->make_flat_dir( $site_id );
		$db      = MainWP_DB_Backup::instance();
		$task    = $db->add_backup_task( $website->userid, 'Open run', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );

		$open   = $this->seed_archive( $dir, 'open.zip', time() - 600 );
		$locked = $this->seed_archive( $dir, 'locked.zip', time() - 500 );
		$stale  = $this->seed_archive( $dir, 'stale.zip', time() - 400 );
		file_put_contents( $stale . '.downloading', 'invalid lock' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		touch( $stale . '.downloading', time() - 600 );
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $locked, $site_id, (int) $task->id ) );
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'  => time() - 100,
				'completed' => 0,
			)
		);
		$db->add_backup_task_progress( $task->id, $site_id, array() );
		$db->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'     => time() - 50,
				'downloadedFULL' => $open,
			)
		);
		update_option( 'mainwp_backupsOnServer', 1 );
		$this->mock_successful_download();

		try {
			MainWP_Backup_File_Manager::backup_download_file( $site_id, 'full', 'backup-remote.tar.gz', $dir . 'new-backup.tar.gz' );
			$this->assertFileExists( $open, 'An open run download must survive the legacy sweep.' );
			$this->assertFileExists( $locked, 'A live download lock must protect its archive.' );
			$this->assertFileDoesNotExist( $stale, 'An unprotected stale archive must be removed.' );
			$this->assertFileDoesNotExist( $stale . '.downloading', 'The stale download lock must be reclaimed before the sweep.' );
		} finally {
			MainWP_Backup_File_Manager::release_download_lock( $locked );
			$db->remove_backup_task( $task->id );
		}
	}

	/**
	 * Protected files do not consume retention slots: with a limit of 2,
	 * the newest unprotected file keeps its slot even though a protected
	 * file is newer.
	 *
	 * @return void
	 */
	public function test_sweeper_exempt_file_does_not_count_toward_max_backups() {
		$website = $this->make_site();
		$dir     = $this->make_flat_dir( (int) $website->id );

		update_option( 'mainwp_backupsOnServer', 2 );

		$unprotected_old = $this->seed_archive( $dir, 'unprotected-old.zip', time() - 900 );
		$unprotected_new = $this->seed_archive( $dir, 'unprotected-new.zip', time() - 600 );
		$protected       = $this->seed_archive( $dir, 'protected.zip', time() - 300 );
		$this->record_snapshot_over( (int) $website->id, $protected );

		$this->mock_successful_download();

		MainWP_Backup_File_Manager::backup_download_file( (int) $website->id, 'full', 'backup-remote.tar.gz', $dir . 'new-backup.tar.gz' );

		$this->assertFileExists( $protected );
		$this->assertFileExists( $unprotected_new, 'A protected file must not consume the retention slot of the newest unprotected file.' );
		$this->assertFileDoesNotExist( $unprotected_old );
	}
}
