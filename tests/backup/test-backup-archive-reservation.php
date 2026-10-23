<?php
/**
 * Local archive reservations preserve existing and claimed paths.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Exception;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/** Exercise real exclusive reservations and persisted path claims. */
class MainWP_Backup_Archive_Reservation_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/** Isolate the filesystem. */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
	}

	/** Remove owned fixtures. */
	public function tearDown(): void {
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Compound extensions stay intact when a name is already occupied.
	 *
	 * @dataProvider n1_extensions
	 * @param string $extension Archive extension.
	 */
	public function test_n1_reservation_preserves_extension( $extension ) {
		$site = $this->create_test_site();
		$dir  = MainWP_Backup_Private_Root::site_dir( $site );
		$path = $dir . 'full-x-October-13-2026-8-17-pm' . $extension;
		file_put_contents( $path, 'original' );
		$reserved = MainWP_Backup_File_Manager::reserve_local_archive_path( $path, $site );
		$this->assertSame( $dir . 'full-x-October-13-2026-8-17-pm-2' . $extension, $reserved );
		$this->assertSame( 'original', file_get_contents( $path ) );
		$this->assertFileExists( $reserved . '.reserved' );
		$this->assertFileDoesNotExist( $reserved );
		$this->assertSame( $dir . 'full-x-October-13-2026-8-17-pm-3' . $extension, MainWP_Backup_File_Manager::reserve_local_archive_path( $path, $site ) );
	}

	/**
	 * Supported extensions.
	 *
	 * @return array Supported extensions.
	 */
	public static function n1_extensions() {
		return array_map( static fn( $ext ) => array( $ext ), array( '.tar.gz', '.sql.gz', '.sql.tar.gz', '.zip', '.tar', '.tar.bz2', '.sql' ) );
	}

	/** Unknown-size transport cleanup must leave the name reserved. */
	public function test_n1_unknown_size_cleanup_preserves_reservation() {
		$site  = $this->create_test_site();
		$path  = MainWP_Backup_Private_Root::site_dir( $site ) . 'full.zip';
		$first = MainWP_Backup_File_Manager::reserve_local_archive_path( $path, $site );
		if ( file_exists( $first ) ) {
			unlink( $first );
		}
		$second = MainWP_Backup_File_Manager::reserve_local_archive_path( $path, $site );
		$this->assertNotSame( $first, $second );
		$this->assertFileExists( $first . '.reserved' );
		$this->assertFileDoesNotExist( $first );
		$this->assertFileExists( $second . '.reserved' );
		$this->assertFileDoesNotExist( $second );
	}

	/** A reservation sidecar holds its name without an archive. */
	public function test_n1_reservation_skips_reserved_sidecar() {
		$site = $this->create_test_site();
		$dir  = MainWP_Backup_Private_Root::site_dir( $site );
		$path = $dir . 'full.zip';
		file_put_contents( $path . '.reserved', '' );
		$reserved = MainWP_Backup_File_Manager::reserve_local_archive_path( $path, $site );
		$this->assertSame( $dir . 'full-2.zip', $reserved );
		$this->assertFileExists( $path . '.reserved' );
		$this->assertFileExists( $reserved . '.reserved' );
		$this->assertFileDoesNotExist( $reserved );
	}

	/** The legacy sweep must leave a slot for bytes that have not landed. */
	public function test_n1_legacy_sweep_preserves_older_archive() {
		$site = $this->create_test_site();
		$dir  = MainWP_Backup_Private_Root::site_dir( $site );
		$old  = $dir . 'full-old.zip';
		file_put_contents( $old, 'older full archive' );
		touch( $old, time() - HOUR_IN_SECONDS );
		update_option( 'mainwp_backupsOnServer', 2 );
		try {
			$reserved = MainWP_Backup_File_Manager::reserve_local_archive_path( $dir . 'full-new.zip', $site );
			MainWP_Backup_File_Manager::sweep_backup_download_files( $site, 'full', $reserved, false );
			$this->assertFileExists( $old );
			$this->assertSame( 'older full archive', file_get_contents( $old ) );
			$this->assertFileExists( $reserved . '.reserved' );
			$this->assertFileDoesNotExist( $reserved );
		} finally {
			delete_option( 'mainwp_backupsOnServer' );
		}
	}

	/** A reservation cannot count as a recent backup. */
	public function test_n1_reservation_is_not_a_recent_archive() {
		$site = $this->create_test_site();
		MainWP_Backup_File_Manager::reserve_local_archive_path( MainWP_Backup_Private_Root::site_dir( $site ) . 'full.zip', $site );
		$this->assertSame( -1, MainWP_Backup_Executor::latest_backup_archive_mtime( $site ) );
	}

	/**
	 * Cleanup reclaims day-old reservations while preserving fresh and unrelated files.
	 *
	 * @dataProvider n1_reservation_directories
	 * @param string $subdir Reservation directory relative to the site.
	 */
	public function test_n1_cleanup_reclaims_only_old_reservations( $subdir ) {
		$site = $this->create_test_site();
		$dir  = MainWP_Backup_Private_Root::site_dir( $site );
		$run  = $dir . $subdir;
		wp_mkdir_p( $run );
		$old   = $run . 'old.zip.reserved';
		$fresh = $run . 'fresh.zip.reserved';
		$other = $run . 'old.zip';
		file_put_contents( $old, '' );
		file_put_contents( $fresh, '' );
		file_put_contents( $other, 'keep' );
		touch( $old, time() - 2 * DAY_IN_SECONDS );
		touch( $fresh, time() - DAY_IN_SECONDS + 60 );
		touch( $other, time() - 2 * DAY_IN_SECONDS );
		MainWP_Backup_File_Manager::cleanup_stale_download_locks( $dir );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $fresh );
		$this->assertSame( 'keep', file_get_contents( $other ) );
	}

	/**
	 * Both directories covered by the lock sweep.
	 *
	 * @return array Reservation directories.
	 */
	public static function n1_reservation_directories() {
		return array(
			'site'     => array( '' ),
			'snapshot' => array( 'snapshot-old/' ),
		);
	}

	/** A lost ad-hoc handle CAS releases only the reservation, even if archive bytes arrive. */
	public function test_adhoc_handle_cas_loss_releases_reservation_only() {
		$site = $this->create_test_site();
		$dir  = MainWP_Backup_Private_Root::site_dir( $site );
		$db   = MainWP_DB::instance();
		$db->update_website_option(
			$site,
			MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id'   => 'reservation-race',
					'owner'     => '',
					'driven_at' => time(),
				)
			)
		);
		$child = static function ( $result, $website, $what ) {
			return 'backup_task_run' === $what ? array(
				'phase' => 'completed',
				'file'  => 'remote.zip',
				'size'  => 10,
				'type'  => 'full',
			) : $result;
		};
		$path  = '';
		$lose  = static function ( $query ) use ( $db, $dir, &$path ) {
			$reservations = glob( $dir . '*.reserved' );
			if ( str_starts_with( $query, 'UPDATE ' . $db->get_table_name( 'wp_options' ) . ' SET value' ) && str_contains( $query, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) && ! empty( $reservations ) ) {
				$path = substr( $reservations[0], 0, -strlen( '.reserved' ) );
				file_put_contents( $path, 'Archive bytes owned by another request' );
				return $query . ' AND 1 = 0';
			}
			return $query;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 10, 3 );
		add_filter( 'query', $lose );
		try {
			try {
				MainWP_Backup_Executor::get_backup_task_status( $site, 'reservation-race', 1 );
				$this->fail( 'A lost handle CAS must refuse the download.' );
			} catch ( MainWP_Exception $error ) {
				$this->assertSame( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, $error->get_message_error_code() );
			}
			$this->assertNotSame( '', $path, 'The fixture must lose the CAS after reserving a path.' );
			$this->assertSame( 'Archive bytes owned by another request', file_get_contents( $path ) );
			$this->assertFileDoesNotExist( $path . '.reserved' );
		} finally {
			remove_filter( 'query', $lose );
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 10 );
			$db->remove_website_option( $site, array( MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		}
	}

	/** Sidecars and database claims reserve names even without an archive. */
	public function test_n1_reservation_skips_sidecars_and_claims() {
		$site = $this->create_test_site();
		$dir  = MainWP_Backup_Private_Root::site_dir( $site );
		file_put_contents( $dir . 'full.zip.downloading', 'active lock' );
		file_put_contents( $dir . 'full-2.zip.downloading.reclaim', 'mutex' );
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => $site,
				'type'          => 'full',
				'archives'      => array(
					array(
						'role' => 'full',
						'file' => 'full-3.zip',
						'path' => $dir . 'full-3.zip',
						'size' => 10,
					),
				),
				'manifest_path' => '',
			)
		);
		global $wpdb;
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'wp_backup' ),
			array(
				'name'      => 'Open reservation test',
				'last_run'  => time(),
				'completed' => 0,
			)
		);
		$task = (int) $wpdb->insert_id;
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task, $site, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task,
			$site,
			array(
				'downloadedFULL' => $dir . 'full-4.zip',
				'dtsFetched'     => time(),
			)
		);
		$this->assertSame( $dir . 'full-5.zip', MainWP_Backup_File_Manager::reserve_local_archive_path( $dir . 'full.zip', $site ) );
		$this->assertSame( 'mutex', file_get_contents( $dir . 'full-2.zip.downloading.reclaim' ) );
	}

	/** Numbered exhaustion must still allocate a fresh random path. */
	public function test_n1_reservation_uses_random_suffix_after_numbered_limit() {
		$site = $this->create_test_site();
		$dir  = MainWP_Backup_Private_Root::site_dir( $site );
		for ( $i = 1; $i <= 100; ++$i ) {
			file_put_contents( $dir . 'full' . ( 1 === $i ? '' : '-' . $i ) . '.tar.gz', 'keep' );
		}
		$path = MainWP_Backup_File_Manager::reserve_local_archive_path( $dir . 'full.tar.gz', $site );
		$this->assertMatchesRegularExpression( '/full-[a-f0-9-]{36}\.tar\.gz$/', $path );
		$this->assertFileExists( $path . '.reserved' );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 'keep', file_get_contents( $dir . 'full.tar.gz' ) );
	}
	/** Zero means unlimited while the incoming download directory is still prepared. */
	public function test_zero_server_retention_keeps_all_download_archives() {
		$site = $this->create_test_site();
		$dir  = MainWP_Backup_Private_Root::site_dir( $site );
		file_put_contents( $dir . 'old.zip', 'old archive' );
		file_put_contents( $dir . 'older.zip', 'older archive' );
		update_option( 'mainwp_backupsOnServer', 0 );
		MainWP_Backup_File_Manager::sweep_backup_download_files( $site, 'full', $dir . 'incoming.zip', false );
		$this->assertFileExists( $dir . 'old.zip' );
		$this->assertFileExists( $dir . 'older.zip' );
		$this->assertFileExists( $dir . 'index.php' );
	}
}
