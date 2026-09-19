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

use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Sweeper_Exemption_Test
 */
class MainWP_Backup_Sweeper_Exemption_Test extends MainWP_Abilities_Test_Case {

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
				// The shared uploads tree carries snapshot-* directories from earlier runs, and site ids are reused; unlink() on one is a fatal.
				array_map( 'unlink', array_filter( glob( $dir . '/*' ) ?: array(), 'is_file' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
				if ( ! glob( $dir . '/*' ) ) {
					rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
				}
			}
		}
		$this->fixture_dirs = array();

		delete_option( 'mainwp_backupsOnServer' );

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
		$site_dir = MainWP_System_Utility::get_mainwp_specific_dir( (string) $site_id );
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
