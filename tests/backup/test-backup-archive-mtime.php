<?php
/**
 * The directory scan must remain independent of the freshness threshold.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Private_Root;

/** Class MainWP_Backup_Archive_Mtime_Test. */
class MainWP_Backup_Archive_Mtime_Test extends MainWP_Abilities_Test_Case {
	/**
	 * SQL archives must not advance the legacy freshness signal.
	 *
	 * @return void
	 */
	public function test_scan_preserves_recent_backup_decision() {
		$this->assertTrue( method_exists( MainWP_Backup_Executor::class, 'latest_backup_archive_mtime' ), 'The pure archive mtime scan must exist.' );
		$site_id = $this->create_test_site();
		$dir     = MainWP_Backup_Private_Root::legacy_site_dir( $site_id );
		wp_mkdir_p( $dir );
		$archive = $dir . 'mtime-fixture.zip';
		$sql     = $dir . 'mtime-fixture.sql.gz';
		try {
			$this->assertSame( -1, MainWP_Backup_Executor::latest_backup_archive_mtime( $site_id ) );
			file_put_contents( $archive, 'archive' );
			file_put_contents( $sql, 'sql' );
			touch( $archive, time() - 2 * DAY_IN_SECONDS );
			touch( $sql, time() );
			clearstatcache();
			$this->assertSame( (int) filemtime( $archive ), MainWP_Backup_Executor::latest_backup_archive_mtime( $site_id ) );
			$this->assertTrue( MainWP_Backup_Executor::has_recent_backup( $site_id, 3 ) );
			$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( $site_id, 1 ) );
		} finally {
			unlink( $archive );
			unlink( $sql );
			rmdir( $dir );
		}
	}
}
