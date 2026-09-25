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
		$this->assertSame( dirname( rtrim( ABSPATH, '/\\' ) ) . '/mainwp-private-' . get_option( 'mainwp_backup_private_suffix' ) . '/backups', $seen );
		$this->assertSame( $this->private_root . '/', $root );
		$this->assertSame( 0700, fileperms( $root ) & 0777 );
		$this->assertSame( 'deny from all', file_get_contents( $root . '.htaccess' ) );
		$this->assertFileExists( $root . 'index.php' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{16}$/', get_option( 'mainwp_backup_private_suffix' ) );
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
	 * A separate PHP process avoids leaking a constant into other tests.
	 */
	public function test_constant_is_selected_before_filter() {
		$source = dirname( __DIR__, 2 ) . '/class/backup/class-mainwp-backup-private-root.php';
		$script = 'define("ABSPATH", "/public/wp/"); define("WP_CONTENT_DIR", "/public/content"); define("MAINWP_BACKUP_PRIVATE_DIR", "/constant/backups");'
			. 'function wp_upload_dir(...$args) { return ["basedir" => "/public/uploads", "baseurl" => "https://test/uploads"]; }'
			. 'function site_url() { return "https://test/wp"; } function content_url() { return "https://test/content"; }'
			. 'function wp_normalize_path($p) { return $p; } function untrailingslashit($p) { return rtrim($p, "/"); }'
			. 'function wp_parse_url($u, $c) { return parse_url($u, $c); }'
			. 'function apply_filters($tag, $value) { echo $tag . ":" . $value; return false; }'
			. 'function __($value, $domain) { return $value; } class WP_Error { public function __construct(...$args) {} }'
			. 'require ' . var_export( $source, true ) . '; echo MainWP\\Dashboard\\MainWP_Backup_Private_Root::resolve() instanceof WP_Error ? ":filtered" : ":unfiltered";'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote a PHP source path for the isolated process.
		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );
		$this->assertSame( 'mainwp_backup_private_root:/constant/backups:filtered', $output );
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
			$error = MainWP_Backup_Private_Root::resolve();
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
			$this->assertSame( 503, $error->getCode() );
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
		$this->assertSame( $this->private_root . '/', MainWP_Backup_Private_Root::resolve() );
		$this->assertSame( 0755, fileperms( $this->private_root ) & 0777 );
		$this->assertSame( array( '.', '..' ), scandir( $this->private_root ) );
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
	 * Each configured layout has a literal decision, independent of the resolver rule.
	 */
	public function test_root_selection_matrix() {
		$rows   = array(
			// WordPress dir, site URL path, content dir, content URL path, uploads dir, uploads URL path, candidate, default, document root, reason.
			'root install'           => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', '/srv/a/mainwp-private-abcdefghijklmnop/backups', true, '', '' ),
			'subdirectory'           => array( '/var/www/html/wp', 'wp', '/var/www/html/wp/content', 'wp/content', '/var/www/html/uploads', 'uploads', '/var/www/html/mainwp-private-abcdefghijklmnop/backups', true, '', 'directory overlaps a served directory' ),
			'bedrock'                => array( '/srv/b/web/wp', 'wp', '/srv/b/web/app', 'app', '/srv/b/web/app/uploads', 'app/uploads', '/srv/b/web/mainwp-private-abcdefghijklmnop/backups', true, '', 'directory overlaps a served directory' ),
			'underivable default'    => array( '/srv/a/public', 'blog', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', '/srv/a/mainwp-private-abcdefghijklmnop/backups', true, '', "the site's web root could not be determined; set MAINWP_BACKUP_PRIVATE_DIR" ),
			'underivable constant'   => array( '/srv/a/public', 'blog', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', '/srv/private', false, '', '' ),
			'equal document root'    => array( '/var/www/html/wp', 'wp', '/var/www/html/content', 'content', '/var/www/html/uploads', 'uploads', '/var/www/html', false, '', 'directory overlaps a served directory' ),
			'contains WordPress'     => array( '/var/www/html', '', '/var/www/html/content', 'content', '/var/www/html/uploads', 'uploads', '/var/www', false, '', 'directory overlaps a served directory' ),
			'inside content'         => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', '/srv/a/public/content/private', false, '', 'directory overlaps a served directory' ),
			'inside uploads'         => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/uploads', '', '/srv/uploads/private', false, '', 'directory overlaps a served directory' ),
			'unusable document root' => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', '/srv/private', false, '/', '' ),
			'request document root'  => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', '/srv/private', false, '/srv', 'directory overlaps a served directory' ),
			'relative'               => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', 'relative', false, '', 'expected an absolute path' ),
			'NUL'                    => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', "bad\0path", false, '', 'expected a non-empty path without NUL bytes' ),
			'junk filter'            => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', false, false, '', 'expected a non-empty path without NUL bytes' ),
			'symlink'                => array( '/srv/a/public', '', '/srv/a/public/content', 'content', '/srv/a/public/uploads', 'uploads', '/link', false, '', 'directory is a symlink' ),
		);
		$derive = new \ReflectionMethod( MainWP_Backup_Private_Root::class, 'served_boundaries' );
		$decide = new \ReflectionMethod( MainWP_Backup_Private_Root::class, 'refusal_reason' );
		wp_mkdir_p( $this->private_root . '/srv/private' );
		symlink( $this->private_root . '/srv/private', $this->private_root . '/link' );
		foreach ( $rows as $label => $row ) {
			list( $wp, $site_path, $content, $content_path, $uploads, $uploads_path, $candidate, $default, $document, $expected ) = $row;
			foreach ( array( $wp, $content, $uploads ) as $dir ) {
				wp_mkdir_p( $this->private_root . $dir );
			}
			$site_filter    = static function () use ( $site_path ) {
				return 'https://test/' . $site_path;
			};
			$content_filter = static function () use ( $content_path ) {
				return 'https://test/' . $content_path;
			};
			$upload_filter  = function ( $dirs ) use ( $uploads, $uploads_path ) {
				$dirs['basedir'] = $this->private_root . $uploads;
				$dirs['baseurl'] = 'https://test/' . $uploads_path;
				return $dirs;
			};
			add_filter( 'site_url', $site_filter );
			add_filter( 'content_url', $content_filter );
			add_filter( 'upload_dir', $upload_filter, 99 );
			try {
				$upload                           = wp_upload_dir( null, false );
				$document                         = '' !== $document && '/' !== $document ? $this->private_root . $document : $document;
				list( $boundaries, $underivable ) = $derive->invoke( null, array( array( $this->private_root . $wp, site_url() ), array( $this->private_root . $content, content_url() ), array( $upload['basedir'], $upload['baseurl'] ) ), $document );
				$candidate                        = is_string( $candidate ) && str_starts_with( $candidate, '/' ) ? $this->private_root . $candidate : $candidate;
				$this->assertSame( $expected, $decide->invoke( null, $candidate, $boundaries, $default, $underivable ), $label );
			} finally {
				remove_filter( 'site_url', $site_filter );
				remove_filter( 'content_url', $content_filter );
				remove_filter( 'upload_dir', $upload_filter, 99 );
			}
		}
	}

	/**
	 * Real resolution covers accepted overrides and refused configuration defaults.
	 */
	public function test_resolve_configuration_outcomes() {
		$rows = array(
			array( 'override', '/', true ),
			array( 'override', 'parent', false ),
			array( 'default', '', false ),
		);
		foreach ( $rows as $row ) {
			list( $selection, $document, $accepted ) = $row;
			$_SERVER['DOCUMENT_ROOT']                = 'parent' === $document ? dirname( $this->private_root ) : $document;
			$site_filter                             = static function () {
				return 'https://test/unmatched-layout';
			};
			add_filter( 'site_url', $site_filter );
			if ( 'default' === $selection ) {
				remove_filter( 'mainwp_backup_private_root', array( $this, 'filter_private_root' ) );
			}
			MainWP_Backup_Private_Root::reset();
			$result = MainWP_Backup_Private_Root::resolve();
			$this->assertSame( $accepted, ! is_wp_error( $result ) );
			if ( 'default' === $selection ) {
				$this->assertStringContainsString( "the site's web root could not be determined", $result->get_error_message() );
			}
			remove_filter( 'site_url', $site_filter );
			add_filter( 'mainwp_backup_private_root', array( $this, 'filter_private_root' ) );
		}
	}

	/**
	 * A database winner overrides stale cache state and fresh random generations.
	 */
	public function test_suffix_uses_database_winner_and_fails_closed() {
		global $wpdb;
		delete_option( 'mainwp_backup_private_suffix' );
		$this->assertFalse( get_option( 'mainwp_backup_private_suffix' ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'mainwp_backup_private_suffix', 'abcdefghijklmnop' ) ); // phpcs:ignore WordPress.DB -- Simulate a competing writer after this request cached a miss.
		add_filter(
			'random_password',
			static function ( $password, $length ) {
				return 16 === $length ? 'zzzzzzzzzzzzzzzz' : $password;
			},
			10,
			2
		);
		$method = new \ReflectionMethod( MainWP_Backup_Private_Root::class, 'install_suffix' );
		$this->assertSame( 'abcdefghijklmnop', $method->invoke( null ) );
		MainWP_Backup_Private_Root::reset();
		$this->assertSame( 'abcdefghijklmnop', $method->invoke( null ) );
		$this->assertSame( 'abcdefghijklmnop', get_option( 'mainwp_backup_private_suffix' ) );
		update_option( 'mainwp_backup_private_suffix', 'invalid' );
		MainWP_Backup_Private_Root::reset();
		$this->assertWPError( MainWP_Backup_Private_Root::resolve() );
	}
}
