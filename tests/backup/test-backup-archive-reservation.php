<?php
/**
 * Local archive reservations preserve existing and claimed paths.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_DB;

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
		$this->assertFileExists( $reserved );
		$this->assertSame( 0, filesize( $reserved ) );
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
		$this->assertSame( 0, filesize( $path ) );
		$this->assertSame( 'keep', file_get_contents( $dir . 'full.tar.gz' ) );
	}
}
