<?php
/**
 * Private native backup storage and consumer regression tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_TestRestore_Sandbox;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Exception;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/**
 * Exercise actual filesystem refusal and both generations of recorded paths.
 */
class MainWP_Backup_Private_Root_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Isolate both roots.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		add_filter(
			'upload_dir',
			function ( $dirs ) {
				$dirs['basedir'] = $this->private_root . '-legacy';
				return $dirs;
			}
		);
	}

	/**
	 * Remove fixtures and cached resolution.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->remove_private_fixture( $this->private_root . '-legacy' );
		$this->remove_private_fixture( $this->private_root . '-filtered' );
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * The filter sees the default, and a successful root is cached.
	 */
	public function test_default_filter_and_cache() {
		remove_filter( 'mainwp_backup_private_root', array( $this, 'filter_private_root' ) );
		$seen = null;
		add_filter(
			'mainwp_backup_private_root',
			function ( $value ) use ( &$seen ) {
				$seen = $value;
				return $this->private_root;
			}
		);
		$root = MainWP_Backup_Private_Root::resolve();
		$this->assertSame( dirname( rtrim( ABSPATH, '/\\' ) ) . '/mainwp-private/' . basename( rtrim( ABSPATH, '/\\' ) ) . '/backups', $seen );
		$this->assertSame( $this->private_root . '/', $root );
		$this->assertDirectoryDoesNotExist( $root );
		$default = $seen;
		MainWP_Backup_Private_Root::reset();
		$this->assertSame( $root, MainWP_Backup_Private_Root::resolve() );
		$this->assertSame( $default, $seen );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		$this->assertSame( $root, MainWP_Backup_Private_Root::resolve() );
		MainWP_Backup_Private_Root::reset();
		$this->assertWPError( MainWP_Backup_Private_Root::resolve() );
	}

	/**
	 * Reject public roots before creating anything.
	 */
	public function test_public_and_invalid_roots_are_refused() {
		foreach ( array( ABSPATH, ABSPATH . 'private-test', WP_CONTENT_DIR, WP_CONTENT_DIR . '/private-test', '', false, "bad\0path", 'relative/backups' ) as $path ) {
			$filter = static function () use ( $path ) {
				return $path;
			};
			add_filter( 'mainwp_backup_private_root', $filter, 99 );
			MainWP_Backup_Private_Root::reset();
			$error = MainWP_Backup_Private_Root::resolve();
			$this->assertWPError( $error );
			$this->assertSame( 'mainwp_backup_private_root_unavailable', $error->get_error_code() );
			$this->assertStringContainsString( 'unavailable:', $error->get_error_message() );
			remove_filter( 'mainwp_backup_private_root', $filter, 99 );
		}
	}

	/**
	 * A symlink, including a public ancestor alias, cannot hide served storage.
	 */
	public function test_symlink_and_public_alias_are_refused() {
		mkdir( $this->private_root );
		symlink( ABSPATH, $this->private_root . '/link' );
		foreach ( array( '/link', '/link/private-test' ) as $suffix ) {
			$filter = function () use ( $suffix ) {
				return $this->private_root . $suffix;
			};
			add_filter( 'mainwp_backup_private_root', $filter, 99 );
			MainWP_Backup_Private_Root::reset();
			$this->assertWPError( MainWP_Backup_Private_Root::resolve() );
			remove_filter( 'mainwp_backup_private_root', $filter, 99 );
		}
	}

	/**
	 * Writability must be demonstrated before chmod can mask the failure.
	 */
	public function test_unwritable_root_is_refused() {
		mkdir( $this->private_root, 0500 );
		try {
			$error = MainWP_Backup_Private_Root::site_dir( 1 );
			$this->assertWPError( $error );
			$this->assertStringContainsString( 'write probe', $error->get_error_message() );
			$this->assertSame( array( '.', '..' ), scandir( $this->private_root ) );
		} finally {
			chmod( $this->private_root, 0700 );
		}
	}

	/**
	 * IDs, staging names, real paths and missing-file parents stay confined.
	 */
	public function test_site_and_staging_confinement() {
		$one    = MainWP_Backup_Private_Root::site_dir( 1 );
		$twelve = MainWP_Backup_Private_Root::site_dir( 12 );
		$this->assertSame( $this->private_root . '/sites/1/', $one );
		$this->assertSame( 0700, fileperms( $one ) & 0777 );
		$this->assertWPError( MainWP_Backup_Private_Root::site_dir( 0 ) );
		$this->assertWPError( MainWP_Backup_Private_Root::site_dir( -1 ) );
		$legacy = MainWP_Backup_Private_Root::legacy_site_dir( 1 );
		$this->assertDirectoryDoesNotExist( $legacy );
		wp_mkdir_p( $legacy );
		foreach ( array( $one, $legacy ) as $dir ) {
			file_put_contents( $dir . 'archive.zip', 'backup' );
			$this->assertTrue( MainWP_Backup_Private_Root::is_within_site_roots( $dir . 'archive.zip', 1 ) );
			$this->assertTrue( MainWP_Backup_Private_Root::is_within_site_roots( $dir . 'missing.zip', 1 ) );
			$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $dir . 'archive.zip', 12 ) );
		}
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $twelve . 'missing.zip', 1 ) );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $one . '../12/missing.zip', 1 ) );
		symlink( $twelve, $one . 'escape' );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $one . 'escape/missing.zip', 1 ) );
		foreach ( array( '', '..', 'a..b', '../job', 'a/b', 'a\\b' ) as $name ) {
			$this->assertWPError( MainWP_Backup_Private_Root::staging_dir( 'restore-staging', $name ) );
		}
		$this->assertWPError( MainWP_Backup_Private_Root::staging_dir( 'unexpected', 'job' ) );
		$this->assertSame( $this->private_root . '/restore-staging/a-b/', MainWP_Backup_Private_Root::staging_dir( 'restore-staging', 'a b' ) );
	}

	/**
	 * Both staging drivers use private storage and refuse a bad root.
	 */
	public function test_staging_consumers_and_sandbox_refusal() {
		$restore = new MainWP_Backup_Restore_Transfer_Driver();
		$this->assertSame( $this->private_root . '/restore-staging/42/', $restore->staging_dir( 42 ) );
		$upload = new \ReflectionMethod( MainWP_Backup_Upload_Driver::class, 'staging_dir' );
		$job    = (object) array(
			'snapshot_id'    => 7,
			'destination_id' => 8,
		);
		$this->assertSame( $this->private_root . '/upload-staging/7-8', $upload->invoke( MainWP_Backup_Upload_Driver::instance(), $job ) );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		MainWP_Backup_Private_Root::reset();
		$plan    = array( array( 'source' => 'remote' ) );
		$outcome = $restore->stage_artifacts( 42, $plan, microtime( true ) + 10 );
		$this->assertStringContainsString( 'Private backup directory', $outcome['error'] );
		$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $outcome['error_code'] );
		try {
			$upload->invoke( MainWP_Backup_Upload_Driver::instance(), $job );
			$this->fail( 'Upload must refuse unavailable private staging.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 10001, $error->getCode() );
			$this->assertStringContainsString( 'Private backup directory', $error->getMessage() );
		}
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		try {
			$sandbox->bring_up();
			$this->fail( 'Sandbox must refuse unavailable private staging.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $error->get_message_error_code() );
			$this->assertStringContainsString( 'Private backup directory', $error->getMessage() );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Retention sweeps both roots while preserving another site's files.
	 */
	public function test_retention_purge_and_remove_both_roots() {
		$roots  = array( MainWP_Backup_Private_Root::site_dir( 1 ), MainWP_Backup_Private_Root::legacy_site_dir( 1 ) );
		$purge  = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'purge_orphaned_snapshot_dirs' );
		$remove = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'remove_local_dir' );
		foreach ( $roots as $root ) {
			wp_mkdir_p( $root . 'snapshot-orphan' );
			file_put_contents( $root . 'snapshot-orphan/archive.zip', 'orphan' );
			touch( $root . 'snapshot-orphan/archive.zip', time() - 100 );
			touch( $root . 'snapshot-orphan', time() - 100 );
		}
		$result = array(
			'orphan_dirs_removed' => 0,
			'errors'              => array(),
		);
		$args   = array( 1, 10, &$result );
		$purge->invokeArgs( MainWP_Backup_Retention_Executor::instance(), $args );
		$this->assertSame( 2, $result['orphan_dirs_removed'] );
		foreach ( $roots as $root ) {
			$this->assertDirectoryDoesNotExist( $root . 'snapshot-orphan' );
			wp_mkdir_p( $root . 'snapshot-recorded' );
			$path = $root . 'snapshot-recorded/archive.zip';
			file_put_contents( $path, 'recorded' );
			$snapshot = (object) array(
				'id'            => 999999,
				'site_id'       => 1,
				'archives'      => array( array( 'path' => $path ) ),
				'manifest_path' => '',
			);
			$errors   = array();
			$args     = array( $snapshot, &$errors );
			$this->assertTrue( $remove->invokeArgs( MainWP_Backup_Retention_Executor::instance(), $args ) );
			$this->assertSame( array(), $errors );
			$this->assertDirectoryDoesNotExist( dirname( $path ) );
		}
	}

	/**
	 * Recent-backup scans select the newest archive across both roots.
	 */
	public function test_recent_backup_uses_both_roots() {
		$private = MainWP_Backup_Private_Root::site_dir( 1 );
		$legacy  = MainWP_Backup_Private_Root::legacy_site_dir( 1 );
		wp_mkdir_p( $legacy );
		file_put_contents( $private . 'full-old.zip', 'old' );
		touch( $private . 'full-old.zip', time() - 10 * DAY_IN_SECONDS );
		$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( 1 ) );
		file_put_contents( $legacy . 'full-new.zip', 'new' );
		$this->assertTrue( MainWP_Backup_Executor::has_recent_backup( 1 ) );
		unlink( $legacy . 'full-new.zip' );
		file_put_contents( $private . 'full-new.zip', 'new' );
		$this->assertTrue( MainWP_Backup_Executor::has_recent_backup( 1 ) );
	}

	/**
	 * A confinement check must not populate a fresh site's backup directory.
	 */
	public function test_confinement_does_not_create_site_directory() {
		$path = MainWP_Backup_Private_Root::site_path( 7654321 );
		$this->assertSame( $this->private_root . '/sites/7654321/', $path );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $path . 'missing.zip', 7654321 ) );
		$this->assertDirectoryDoesNotExist( $path );
		$this->assertDirectoryDoesNotExist( $this->private_root . '/sites' );
		$this->assertDirectoryDoesNotExist( MainWP_Backup_Private_Root::legacy_site_dir( 7654321 ) );
		$other = MainWP_Backup_Private_Root::site_dir( 7654322 );
		symlink( $other, untrailingslashit( $path ) );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_site_roots( $path . 'missing.zip', 7654321 ) );
		$this->assertWPError( MainWP_Backup_Private_Root::site_path( 0 ) );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		MainWP_Backup_Private_Root::reset();
		$this->assertWPError( MainWP_Backup_Private_Root::site_path( 7654321 ) );
	}

	/**
	 * A shared override directory must retain its traversal and contents.
	 */
	public function test_existing_override_keeps_mode_and_no_protection_files() {
		mkdir( $this->private_root );
		chmod( $this->private_root, 0755 );
		$this->assertSame( $this->private_root . '/sites/1/', MainWP_Backup_Private_Root::site_dir( 1 ) );
		$this->assertSame( 0755, fileperms( $this->private_root ) & 0777 );
		$this->assertSame( array( '.', '..', 'sites' ), scandir( $this->private_root ) );
	}

	/**
	 * Legacy files remain readable but cannot be new download destinations.
	 */
	public function test_legacy_write_refused_and_private_size_reported() {
		$private = MainWP_Backup_Private_Root::site_dir( 1 );
		$legacy  = MainWP_Backup_Private_Root::legacy_site_dir( 1 );
		wp_mkdir_p( $legacy );
		file_put_contents( $private . 'full.zip', 'private bytes' );
		$this->assertSame( 13, \MainWP\Dashboard\MainWP_Backup_File_Manager::backup_get_file_size( $private . 'full.zip' ) );
		$this->assertTrue( MainWP_Backup_Private_Root::is_within_site_roots( $legacy . 'full.zip', 1 ) );
		$this->assertFalse( MainWP_Backup_Private_Root::is_within_private_site_dir( $legacy . 'full.zip', 1 ) );
		$this->expectException( MainWP_Exception::class );
		\MainWP\Dashboard\MainWP_Backup_File_Manager::backup_download_file( 1, 'full', 'remote', $legacy . 'full.zip' );
	}

	/**
	 * Incomplete legacy checkpoints take the fresh private destination branch.
	 */
	public function test_incomplete_legacy_checkpoint_is_discarded() {
		$private = MainWP_Backup_Private_Root::site_dir( 1 ) . 'full.zip';
		$legacy  = MainWP_Backup_Private_Root::legacy_site_dir( 1 );
		wp_mkdir_p( $legacy );
		$path   = $legacy . 'full.zip';
		$choose = new \ReflectionMethod( MainWP_Backup_Executor::class, 'resumable_download_path' );
		file_put_contents( $path, 'partial' );
		$this->assertSame( $path, $choose->invoke( null, $path, 1, 1 ) );
		$this->assertSame( '', $choose->invoke( null, $path, 0, 1 ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( $private, $choose->invoke( null, $private, 0, 1 ) );
	}

	/**
	 * Environment checks need only private storage to be writable.
	 */
	public function test_environment_checks_private_root() {
		$upload = wp_upload_dir( null, false );
		wp_mkdir_p( $upload['basedir'] . '/mainwp' );
		file_put_contents( $upload['basedir'] . '/mainwp/backup', 'blocked legacy directory' );
		\MainWP\Dashboard\MainWP_Backup_Monitor::validate_backup_environment();
		$this->assertSame( 'blocked legacy directory', file_get_contents( $upload['basedir'] . '/mainwp/backup' ) );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		MainWP_Backup_Private_Root::reset();
		try {
			\MainWP\Dashboard\MainWP_Backup_Monitor::validate_backup_environment();
			$this->fail( 'Unavailable private storage must fail validation.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $error->get_message_error_code() );
		}
		update_option( 'mainwp_enableNativeBackups', 1 );
		try {
			MainWP_Backup_Executor::trigger_backup( $this->create_test_site() );
			$this->fail( 'Storage must be resolved before a child task can start.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $error->get_message_error_code() );
		}
	}

	/**
	 * Historical site paths honor MainWP's upload filter without creating them.
	 */
	public function test_filtered_legacy_path_and_read_only_scan() {
		add_filter(
			'mainwp_get_wp_upload_dir',
			function ( $dirs, $subdir, $direct ) {
				$this->assertNull( $subdir );
				$this->assertFalse( $direct );
				$dirs['basedir'] = $this->private_root . '-filtered';
				return $dirs;
			},
			10,
			3
		);
		$this->assertSame( $this->private_root . '-filtered/mainwp/0/1/', MainWP_Backup_Private_Root::legacy_site_dir( 1 ) );
		$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( 1 ) );
		$this->assertDirectoryDoesNotExist( $this->private_root . '/sites/1' );
		$this->assertDirectoryDoesNotExist( $this->private_root . '-filtered' );
	}

	/**
	 * Selection precedence and filesystem refusals have literal outcomes.
	 */
	public function test_root_selection_matrix() {
		$rows = array(
			'WordPress directory named mainwp-private gets a sibling container' => array( 'container', null, true ),
			'sibling installs resolve to different roots' => array( 'siblings', null, true ),
			'default accepted'                            => array( 'default', null, true ),
			'constant wins over default'                  => array( 'constant', null, true ),
			'filter wins over constant'                   => array( 'filter', null, true ),
			'inside ABSPATH refused'                      => array( 'path', ABSPATH . 'private-test', false ),
			'inside WP_CONTENT_DIR refused'               => array( 'path', WP_CONTENT_DIR . '/private-test', false ),
			'symlink refused'                             => array( 'symlink', $this->private_root . '/link', false ),
			'unwritable refused'                          => array( 'unwritable', $this->private_root . '/readonly', false ),
			'relative refused'                            => array( 'path', 'relative/backups', false ),
			"'..' refused"                                => array( 'path', $this->private_root . '/../backups', false ),
		);
		mkdir( $this->private_root );
		symlink( $this->private_root, $this->private_root . '/link' );
		mkdir( $this->private_root . '/readonly', 0500 );
		foreach ( $rows as $label => $row ) {
			list( $selection, $path, $accepted ) = $row;
			if ( in_array( $selection, array( 'default', 'constant', 'filter', 'siblings', 'container' ), true ) ) {
				// Constants need separate processes; none of these resolutions writes to disk.
				$resolve = static function ( string $abspath ) use ( $selection ) {
					$source = dirname( __DIR__, 2 ) . '/class/backup/class-mainwp-backup-private-root.php';
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- wp_json_encode() escapes slashes, which a PHP string literal keeps.
					$script = 'define("ABSPATH", ' . var_export( $abspath, true ) . '); define("WP_CONTENT_DIR", "/public/content");'
						. ( in_array( $selection, array( 'default', 'siblings', 'container' ), true ) ? '' : 'define("MAINWP_BACKUP_PRIVATE_DIR", "/constant/backups");' )
						. 'function wp_normalize_path($p) { return $p; } function untrailingslashit($p) { return rtrim($p, "/"); }'
						. 'function trailingslashit($p) { return rtrim($p, "/") . "/"; } function path_is_absolute($p) { return $p[0] === "/"; }'
						. 'function apply_filters($tag, $value) { return ' . ( 'filter' === $selection ? '"/filtered/backups"' : '$value' ) . '; }'
						. 'function __($value, $domain) { return $value; } class WP_Error {}'
						. 'require ' . var_export( $source, true ) . '; echo MainWP\\Dashboard\\MainWP_Backup_Private_Root::resolve();'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote the source path for the isolated constant test.
					return shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );
				};
				if ( 'container' === $selection ) {
					$this->assertSame( '/srv/mainwp-private-dashboard/mainwp-private/backups/', $resolve( '/srv/mainwp-private/' ), $label );
					$this->assertSame( '/srv/mainwp-private/site/backups/', $resolve( '/srv/site/' ), $label );
					continue;
				}
				if ( 'siblings' === $selection ) {
					$first  = $resolve( '/sites/a/' );
					$second = $resolve( '/sites/b/' );
					$this->assertSame( '/sites/mainwp-private/a/backups/', $first, $label );
					$this->assertSame( '/sites/mainwp-private/b/backups/', $second, $label );
					$this->assertNotSame( $first, $second, $label );
					continue;
				}
				$expected = array(
					'default'  => '/public/mainwp-private/wp/backups/',
					'constant' => '/constant/backups/',
					'filter'   => '/filtered/backups/',
				);
				$this->assertSame( $expected[ $selection ], $resolve( '/public/wp/' ), $label );
				continue;
			}
			$filter = static function () use ( $path ) {
				return $path;
			};
			add_filter( 'mainwp_backup_private_root', $filter, 99 );
			MainWP_Backup_Private_Root::reset();
			try {
				$result = 'unwritable' === $selection ? MainWP_Backup_Private_Root::site_dir( 1 ) : MainWP_Backup_Private_Root::resolve();
				$this->assertSame( $accepted, ! is_wp_error( $result ), $label );
				$this->assertSame( 'mainwp_backup_private_root_unavailable', $result->get_error_code(), $label );
			} finally {
				remove_filter( 'mainwp_backup_private_root', $filter, 99 );
			}
		}
	}

	/**
	 * Readers never need a write probe; only writers create and protect storage.
	 */
	public function test_resolve_configuration_outcomes() {
		$rows = array( 'writable root', 'root read-only', 'root missing' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
		wp_mkdir_p( MainWP_Backup_Private_Root::legacy_site_dir( $site_id ) );
		foreach ( $rows as $label ) {
			$this->remove_private_fixture( $this->private_root );
			MainWP_Backup_Private_Root::reset();
			$site = $this->private_root . '/sites/' . $site_id . '/';
			$file = $site . 'snapshot-recorded/archive.zip';
			if ( 'root missing' !== $label ) {
				wp_mkdir_p( dirname( $file ) );
				file_put_contents( $file, 'recorded' );
				file_put_contents( $site . 'full-backup.zip', 'listable' );
				touch( $site . 'full-backup.zip', 1700000000 );
				wp_mkdir_p( $site . 'snapshot-orphan' );
				file_put_contents( $site . 'snapshot-orphan/archive.zip', 'orphan' );
				touch( $site . 'snapshot-orphan/archive.zip', time() - 100 );
				touch( $site . 'snapshot-orphan', time() - 100 );
				foreach ( array( '/restore-staging/7', '/upload-staging/7-8' ) as $staging ) {
					wp_mkdir_p( $this->private_root . $staging );
					file_put_contents( $this->private_root . $staging . '/archive.zip', 'staged' );
				}
				chmod( $this->private_root, 'root read-only' === $label ? 0500 : 0700 );
			}
			try {
				$this->assertSame( $this->private_root . '/', MainWP_Backup_Private_Root::resolve(), $label );
				$this->assertSame( $site, MainWP_Backup_Private_Root::site_path( $site_id ), $label );
				$exists = 'root missing' !== $label;
				$this->assertSame( $exists, MainWP_Backup_Private_Root::is_within_site_roots( $file, $site_id ), $label );
				$this->assertSame( $exists, MainWP_Backup_Private_Root::is_within_private_site_dir( $file, $site_id ), $label );
				$this->assertSame( $exists, \MainWP\Dashboard\MainWP_Backup_File_Manager::is_within_site_backup_dir( $file, $site_id ), $label );
				// The private mwpdl branch confines the file to resolve()'s root.
				$this->assertSame( $exists, \MainWP\Dashboard\MainWP_Backup_File_Manager::path_is_confined_to( $file, MainWP_Backup_Private_Root::resolve() ), $label );
				ob_start();
				\MainWP\Dashboard\MainWP_Manage_Sites::show_backups( $website );
				$html   = ob_get_clean();
				$table  = new \MainWP\Dashboard\MainWP_Manage_Sites_List_Table();
				$column = $table->column_backup( (array) $website );
				if ( $exists ) {
					$this->assertStringContainsString( 'full-backup.zip', $html, $label );
					$this->assertStringNotContainsString( 'No full backup has been taken yet', $html, $label );
					$this->assertSame( \MainWP\Dashboard\MainWP_Utility::format_timestamp( \MainWP\Dashboard\MainWP_Utility::get_timestamp( 1700000000 ) ) . '<br />', $column, $label );
				} else {
					$this->assertStringContainsString( 'No full backup has been taken yet', $html, $label );
					$this->assertStringContainsString( 'Never', $column, $label );
				}
				ob_start();
				\MainWP\Dashboard\MainWP_Backup_Page::render_backup_environment_notices();
				$notice = ob_get_clean();
				$this->assertStringNotContainsString( 'Private backup directory', $notice, $label );
				if ( $exists ) {
					$remove   = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'remove_local_dir' );
					$snapshot = (object) array(
						'id'            => 999999,
						'site_id'       => $site_id,
						'archives'      => array( array( 'path' => $file ) ),
						'manifest_path' => '',
					);
					$errors   = array();
					$args     = array( $snapshot, &$errors );
					$this->assertTrue( $remove->invokeArgs( MainWP_Backup_Retention_Executor::instance(), $args ), $label );
					$this->assertSame( array(), $errors, $label );
					$this->assertDirectoryDoesNotExist( dirname( $file ), $label );
				}
				$purge  = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'purge_orphaned_snapshot_dirs' );
				$result = array(
					'orphan_dirs_removed' => 0,
					'errors'              => array(),
				);
				$args   = array( $site_id, 10, &$result );
				$purge->invokeArgs( MainWP_Backup_Retention_Executor::instance(), $args );
				$this->assertSame( $exists ? 1 : 0, $result['orphan_dirs_removed'], $label );
				$this->assertSame( array(), $result['errors'], $label );
				$this->assertDirectoryDoesNotExist( $site . 'snapshot-orphan', $label );
				if ( $exists ) {
					$this->assertFileExists( $site . 'full-backup.zip', $label );
				}
				$restore = new MainWP_Backup_Restore_Transfer_Driver();
				$restore->cleanup_staging( 7 );
				$cleanup = new \ReflectionMethod( MainWP_Backup_Upload_Driver::class, 'cleanup_staging' );
				$job     = (object) array(
					'snapshot_id'    => 7,
					'destination_id' => 8,
				);
				$cleanup->invoke( MainWP_Backup_Upload_Driver::instance(), $job );
				$this->assertDirectoryDoesNotExist( $this->private_root . '/restore-staging/7', $label );
				$this->assertDirectoryDoesNotExist( $this->private_root . '/upload-staging/7-8', $label );
				$this->assertSame( $exists, is_dir( $this->private_root ), $label );
				$site_result    = MainWP_Backup_Private_Root::site_dir( $site_id );
				$staging_result = MainWP_Backup_Private_Root::staging_dir( 'restore-staging', 'job' );
				if ( 'root read-only' === $label ) {
					foreach ( array( $site_result, $staging_result ) as $error ) {
						$this->assertWPError( $error, $label );
						$this->assertSame( 'mainwp_backup_private_root_unavailable', $error->get_error_code(), $label );
					}
					$this->assertSame( 0500, fileperms( $this->private_root ) & 0777, $label );
					$this->assertSame( $site, MainWP_Backup_Private_Root::site_path( $site_id ), $label );
				} else {
					$this->assertSame( $site, $site_result, $label );
					$this->assertSame( $this->private_root . '/restore-staging/job/', $staging_result, $label );
					$this->assertDirectoryExists( $site_result, $label );
					$this->assertDirectoryExists( $staging_result, $label );
					if ( 'root missing' === $label ) {
						$this->assertSame( 0700, fileperms( $this->private_root ) & 0777, $label );
						$this->assertSame( 'deny from all', file_get_contents( $this->private_root . '/.htaccess' ), $label );
						$this->assertFileExists( $this->private_root . '/index.php', $label );
					}
				}
			} finally {
				if ( is_dir( $this->private_root ) ) {
					chmod( $this->private_root, 0700 );
				}
			}
		}
	}
}
