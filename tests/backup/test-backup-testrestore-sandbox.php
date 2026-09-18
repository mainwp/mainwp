<?php
/**
 * MainWP Backup Test-Restore Sandbox Tests
 *
 * extract_zip() and extract_tar() run against untrusted, attacker-controlled
 * backup archives, so a defense-in-depth check runs before either extractor
 * is invoked: every entry name is rejected if it carries a parent-directory
 * traversal segment, an absolute path, or a stream wrapper. PHP's own
 * ZipArchive/PharData extractors already neutralise these on 7.4+ (confirmed
 * below for both), so this is belt-and-braces at the name level, not the
 * only thing standing between an archive and the filesystem.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_TestRestore_Sandbox;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class MainWP_Backup_TestRestore_Sandbox_Test
 */
class MainWP_Backup_TestRestore_Sandbox_Test extends \WP_UnitTestCase {

	/**
	 * Scratch dir for archive fixtures.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Set up the fixture scratch dir.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-tr-sandbox-' . uniqid();
		mkdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
	}

	/**
	 * Remove the fixture scratch dir.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->rrmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Recursive cleanup.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
	}

	/**
	 * Build a zip carrying a path-traversal entry alongside a clean one.
	 * ZipArchive::addFromString happily stores a raw '../' name; the sandbox
	 * must catch it before extraction, not rely on ZipArchive to refuse it.
	 *
	 * @return string Absolute path to the zip.
	 */
	private function malicious_zip() {
		$path = $this->dir . '/malicious.zip';
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( '../evil.txt', 'malicious-bytes' );
		$zip->addFromString( 'good.txt', 'good-bytes' );
		$zip->close();
		return $path;
	}

	/**
	 * Build a zip with only a clean entry.
	 *
	 * @return string Absolute path to the zip.
	 */
	private function clean_zip() {
		$path = $this->dir . '/clean.zip';
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( 'good.txt', 'good-bytes' );
		$zip->close();
		return $path;
	}

	/**
	 * Build an uncompressed tar with only a clean entry. Named '.tar' (not
	 * '.tar.gz') so MainWP_Backup_File_Manager::get_real_extension() routes
	 * it to extract_tar() rather than the lone-gzip-file branch.
	 *
	 * @return string Absolute path to the tar.
	 */
	private function clean_tar() {
		$path = $this->dir . '/clean.tar';
		$phar = new \PharData( $path );
		$phar->addFromString( 'good.txt', 'good-bytes' );
		unset( $phar );
		return $path;
	}

	/**
	 * A zip archive whose entry name attempts to escape the sandbox temp
	 * dir via a '..' segment is refused before extraction: nothing is
	 * written anywhere, not even the archive's other, clean entry.
	 *
	 * @return void
	 */
	public function test_zip_traversal_entry_is_refused_and_extracts_nothing() {
		$path    = $this->malicious_zip();
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();

		try {
			$threw = false;
			try {
				$sandbox->extract_files( $path );
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $e->get_message_error_code(), 'A traversal entry must report the apply error code.' );
			}
			$this->assertTrue( $threw, 'A zip carrying a traversal entry must be refused.' );

			$this->assertSame( 0, $sandbox->temp_file_count(), 'Nothing extracts once a traversal entry is found, including the archive\'s clean member.' );

			$escaped = dirname( untrailingslashit( $sandbox->temp_dir() ) ) . '/evil.txt';
			$this->assertFileDoesNotExist( $escaped, 'The traversal entry must never land outside the sandbox temp dir.' );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * A clean zip still extracts normally: the traversal guard does not
	 * disturb a well-formed archive.
	 *
	 * @return void
	 */
	public function test_clean_zip_still_extracts() {
		$path    = $this->clean_zip();
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();

		try {
			$sandbox->extract_files( $path );
			$this->assertSame( 1, $sandbox->temp_file_count() );
			$this->assertSame( hash( 'sha256', 'good-bytes' ), $sandbox->sentinel_sha256( 'good.txt' ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * A clean tar still extracts normally through extract_tar()'s new
	 * per-entry precheck loop.
	 *
	 * @return void
	 */
	public function test_clean_tar_still_extracts() {
		$path    = $this->clean_tar();
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();

		try {
			$sandbox->extract_files( $path );
			$this->assertSame( 1, $sandbox->temp_file_count() );
			$this->assertSame( hash( 'sha256', 'good-bytes' ), $sandbox->sentinel_sha256( 'good.txt' ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * A host that cannot create an alias must not discredit the backup.
	 *
	 * @return void
	 */
	public function test_tar_gz_alias_directory_failure_is_unsupported() {
		$short = $this->clean_tar();
		$phar  = new \PharData( $short );
		$phar->compress( \Phar::GZ );
		unset( $phar );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		$alias_dir = untrailingslashit( $sandbox->temp_dir() ) . '-phar';

		try {
			$this->assertSame( 7, file_put_contents( $alias_dir, 'blocked' ) );
			$threw = false;
			try {
				$sandbox->extract_files( $short . '.gz' );
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
			}
			$this->assertTrue( $threw, 'An unavailable alias directory must be reported as unsupported.' );
			$this->assertSame( 0, $sandbox->temp_file_count() );
		} finally {
			wp_delete_file( $alias_dir );
			$sandbox->tear_down();
		}
	}

	/**
	 * Corrupt compressed bytes must discredit the backup, not the host.
	 *
	 * @return void
	 */
	public function test_corrupt_tar_gz_is_apply_error() {
		$raw = gzencode( file_get_contents( $this->clean_tar() ) );
		$this->assertNotFalse( $raw );
		$middle         = intdiv( strlen( $raw ), 2 );
		$raw[ $middle ] = chr( ord( $raw[ $middle ] ) ^ 255 );
		$path           = $this->dir . '/corrupt.tar.gz';
		$this->assertSame( strlen( $raw ), file_put_contents( $path, $raw ) );
		$in = gzopen( $path, 'r' );
		$this->assertNotFalse( $in );
		try {
			do {
				$chunk = @gzread( $in, 262144 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corruption intentionally triggers a zlib warning.
			} while ( false !== $chunk && '' !== $chunk );
			$this->assertFalse( $chunk, 'The fixture must fail decompression before tar parsing.' );
		} finally {
			gzclose( $in );
		}

		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$threw = false;
			try {
				@$sandbox->extract_files( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corruption intentionally triggers a zlib warning.
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $e->get_message_error_code() );
			}
			$this->assertTrue( $threw, 'Corrupt gzip bytes must report an apply error.' );
			$this->assertSame( 0, $sandbox->temp_file_count() );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Parent protection must cover extracted files and sibling aliases.
	 *
	 * @return void
	 */
	public function test_bring_up_protects_test_restore_parent() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		try {
			$sandbox->bring_up();
			$parent = $this->dir . '/mainwp/test-restore';
			$this->assertFileExists( $parent . '/.htaccess' );
			$this->assertSame( 'deny from all', file_get_contents( $parent . '/.htaccess' ) );
			$this->assertFileExists( $parent . '/index.php' );
			$this->assertSame( '', file_get_contents( $parent . '/index.php' ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Early alias callers need protection even when alias directory creation fails.
	 *
	 * @return void
	 */
	public function test_phar_readable_path_protects_parent_before_alias_directory() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$path      = $this->dir . '/s23-full-bogdan3.mystagingwebsite.com-September-17-2026-23-55-00.tar';
		$parent    = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$alias_dir = untrailingslashit( $sandbox->temp_dir() ) . '-phar';
		$method    = new \ReflectionMethod( $sandbox, 'phar_readable_path' );
		$method->setAccessible( true );
		$this->assertTrue( rename( $this->clean_tar(), $path ) );
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$this->assertSame( 7, file_put_contents( $alias_dir, 'blocked' ) );

		try {
			$this->assertFileDoesNotExist( $parent . '/.htaccess' );
			$threw = false;
			try {
				$method->invoke( $sandbox, $path );
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
				$this->assertSame( 'The test-restore sandbox could not create its archive directory.', $e->getMessage() );
			}
			$this->assertTrue( $threw, 'The blocked alias directory must fail after parent protection.' );
			$this->assertDirectoryDoesNotExist( $alias_dir );
			$this->assertSame( 'deny from all', file_get_contents( $parent . '/.htaccess' ) );
			$this->assertSame( '', file_get_contents( $parent . '/index.php' ) );
			$this->assertDirectoryDoesNotExist( $sandbox->temp_dir() );

			$this->assertTrue( unlink( $alias_dir ) );
			$deny_rule = "# Existing protection\nDeNy FrOm AlL\n";
			$this->assertSame( strlen( $deny_rule ), file_put_contents( $parent . '/.htaccess', $deny_rule ) );
			$this->assertSame( 8, file_put_contents( $parent . '/index.php', 'retained' ) );
			$this->assertTrue( touch( $parent . '/.htaccess', 1000000000 ) );
			$this->assertFileExists( $method->invoke( $sandbox, $path ) );
			$this->assertSame( $deny_rule, file_get_contents( $parent . '/.htaccess' ) );
			clearstatcache( true, $parent . '/.htaccess' );
			$this->assertSame( 1000000000, filemtime( $parent . '/.htaccess' ), 'A valid deny rule must not be rewritten.' );
			$this->assertSame( 'retained', file_get_contents( $parent . '/index.php' ) );
		} finally {
			if ( is_file( $alias_dir ) ) {
				wp_delete_file( $alias_dir );
			}
			$sandbox->tear_down();
		}
	}

	/**
	 * A permissive parent must not expose restored backup data.
	 *
	 * @return void
	 */
	public function test_bring_up_repairs_permissive_parent_htaccess() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$this->assertSame( 14, file_put_contents( $parent . '/.htaccess', 'allow from all' ) );
		try {
			$sandbox->bring_up();
			$this->assertStringContainsString( 'deny from all', strtolower( file_get_contents( $parent . '/.htaccess' ) ) );
			$this->assertFileExists( $parent . '/index.php' );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Failed protection repair must disqualify the host rather than expose backups.
	 *
	 * @return void
	 */
	public function test_bring_up_uncorrectable_parent_htaccess_is_unsupported() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root can overwrite read-only protection files.' );
		}
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$this->assertSame( 14, file_put_contents( $htaccess, 'allow from all' ) );
		try {
			$this->assertTrue( chmod( $htaccess, 0444 ) );
			clearstatcache( true, $htaccess );
			$this->assertFalse( is_writable( $htaccess ), 'The fixture must prevent deny-rule repair.' );
			$this->assertTrue( is_readable( $htaccess ), 'The fixture must remain readable.' );
			$threw = false;
			try {
				$sandbox->bring_up();
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
				$this->assertSame( 'The test-restore sandbox could not protect its working directory.', $e->getMessage() );
				$this->assertStringContainsString( 'file_put_contents(' . $htaccess . '):', $e->get_message_extra( false ) );
			}
			$this->assertTrue( $threw, 'An uncorrectable deny rule must be reported as unsupported.' );
			$this->assertSame( 'allow from all', file_get_contents( $htaccess ) );
			$this->assertDirectoryDoesNotExist( $sandbox->temp_dir() );
		} finally {
			chmod( $htaccess, 0644 );
			$sandbox->tear_down();
		}
	}

	/**
	 * Refusing a symlink must preserve the target bytes.
	 *
	 * @return void
	 */
	public function test_bring_up_refuses_symlinked_parent_htaccess() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$target = $this->dir . '/credential.php';
		$bytes  = '<?php $credential = "retained";';
		$this->assertSame( strlen( $bytes ), file_put_contents( $target, $bytes ) );
		$this->assertTrue( symlink( $target, $htaccess ) );
		try {
			$threw = false;
			try {
				$sandbox->bring_up();
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
				$this->assertStringContainsString( $htaccess . ' is not a regular file', $e->get_message_extra( false ) );
			}
			$this->assertTrue( $threw, 'A non-regular protection path must be refused.' );
			$this->assertSame( $bytes, file_get_contents( $target ) );
			$this->assertTrue( is_link( $htaccess ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Refusing a hard link must preserve the target bytes.
	 *
	 * @return void
	 */
	public function test_bring_up_refuses_hardlinked_parent_htaccess() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$target = $this->dir . '/credential.php';
		$bytes  = '<?php $credential = "retained";';
		$this->assertSame( strlen( $bytes ), file_put_contents( $target, $bytes ) );
		try {
			if ( ! @link( $target, $htaccess ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- skip filesystems that cannot create hard links.
				$this->markTestSkipped( 'The filesystem cannot create hard links.' );
			}
			$threw = false;
			try {
				$sandbox->bring_up();
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
				$this->assertStringContainsString( $htaccess . ' is not a regular file', $e->get_message_extra( false ) );
			}
			$this->assertTrue( $threw, 'A hard-linked protection path must be refused.' );
			$this->assertSame( $bytes, file_get_contents( $target ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * A symlink to an existing deny rule must remain unchanged and allow bring-up.
	 *
	 * @return void
	 */
	public function test_bring_up_preserves_symlinked_parent_htaccess_with_deny_rule() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$target = $this->dir . '/deny-rule';
		$bytes  = "Deny from all\n# Retain this directive.\nOptions -Indexes\n";
		$this->assertSame( strlen( $bytes ), file_put_contents( $target, $bytes ) );
		$this->assertTrue( symlink( $target, $htaccess ) );
		try {
			$sandbox->bring_up();
			$this->assertDirectoryExists( $sandbox->temp_dir() );
			$this->assertTrue( is_link( $htaccess ) );
			$this->assertSame( $bytes, file_get_contents( $target ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * A device symlink must be refused promptly without reading its endless stream.
	 *
	 * @return void
	 */
	public function test_protect_parent_refuses_symlink_to_dev_zero_promptly() {
		if ( ! is_readable( '/dev/zero' ) ) {
			$this->markTestSkipped( '/dev/zero is not readable on this runner.' );
		}
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$this->assertTrue( symlink( '/dev/zero', $htaccess ) );
		$method = new \ReflectionMethod( $sandbox, 'protect_parent' );
		$method->setAccessible( true );
		try {
			$threw   = false;
			$started = microtime( true );
			try {
				$method->invoke( $sandbox );
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
				$this->assertStringContainsString( $htaccess . ' is not a regular file', $e->get_message_extra( false ) );
			}
			$this->assertLessThan( 1.0, microtime( true ) - $started, 'Device refusal must return within one second.' );
			$this->assertTrue( $threw, 'A protection symlink to /dev/zero must be refused.' );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * A directory must fail the regular-file guard explicitly.
	 *
	 * @return void
	 */
	public function test_bring_up_refuses_directory_parent_htaccess() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$this->assertTrue( wp_mkdir_p( $htaccess ) );
		try {
			$threw = false;
			try {
				$sandbox->bring_up();
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
				$this->assertStringContainsString( $htaccess . ' is not a regular file', $e->get_message_extra( false ) );
			}
			$this->assertTrue( $threw, 'A non-regular protection path must be refused.' );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * PHP read permissions do not establish whether the web server denies access.
	 *
	 * @return void
	 */
	public function test_bring_up_preserves_unreadable_parent_htaccess() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$this->assertSame( 13, file_put_contents( $htaccess, 'deny from all' ) );
		$this->assertTrue( touch( $htaccess, 1000000000 ) );
		try {
			$this->assertTrue( chmod( $htaccess, 0000 ) );
			clearstatcache( true, $htaccess );
			if ( is_readable( $htaccess ) ) {
				$this->markTestSkipped( 'The process can read chmod 000 protection files.' );
			}
			$sandbox->bring_up();
			$this->assertDirectoryExists( $sandbox->temp_dir() );
			$this->assertFileExists( $parent . '/index.php' );
			$this->assertTrue( chmod( $htaccess, 0644 ) );
			clearstatcache( true, $htaccess );
			$this->assertSame( 'deny from all', file_get_contents( $htaccess ) );
			$this->assertSame( 1000000000, filemtime( $htaccess ), 'Unreadable protection must not be rewritten.' );
		} finally {
			chmod( $htaccess, 0644 );
			$sandbox->tear_down();
		}
	}

	/**
	 * Protection refusal must happen before the extraction root is created.
	 *
	 * @return void
	 */
	public function test_bring_up_protects_parent_before_extraction_root() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new class() extends MainWP_Backup_TestRestore_Sandbox {
				/**
				 * Whether the extraction root existed before cleanup.
				 *
				 * @var bool|null
				 */
				public $root_before_cleanup = null;

				/**
				 * Observe the root before normal teardown can remove it.
				 *
				 * @return void
				 */
				public function tear_down(): void {
					$this->root_before_cleanup = is_dir( $this->temp_dir() );
					parent::tear_down();
				}
			};
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$this->assertTrue( wp_mkdir_p( $htaccess ) );
		try {
			$threw = false;
			try {
				$sandbox->bring_up();
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
				$this->assertStringContainsString( $htaccess . ' is not a regular file', $e->get_message_extra( false ) );
			}
			$this->assertTrue( $threw, 'A non-regular protection path must be refused.' );
			$this->assertFalse( $sandbox->root_before_cleanup, 'The extraction root must not exist when protection refuses.' );
			$this->assertDirectoryDoesNotExist( $sandbox->temp_dir() );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * The best-effort index write must not follow a dangling symlink.
	 *
	 * @return void
	 */
	public function test_bring_up_preserves_dangling_index_symlink() {
		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$target = $this->dir . '/missing-index.php';
		$this->assertTrue( symlink( $target, $parent . '/index.php' ) );
		try {
			$sandbox->bring_up();
			$this->assertFileDoesNotExist( $target );
			$this->assertTrue( is_link( $parent . '/index.php' ) );
			$this->assertSame( 'deny from all', file_get_contents( $htaccess ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Dotted dashboard names work on both sides of Phar's filename limit.
	 *
	 * @return void
	 */
	public function test_tar_with_long_dotted_name_extracts() {
		$path    = $this->dir . '/s23-full-bogdan3.mystagingwebsite.com-September-17-2026-23-55.tar';
		$long    = substr( $path, 0, -4 ) . 'x.tar';
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$this->assertTrue( rename( $this->clean_tar(), $path ) );
		$sandbox->bring_up();

		try {
			$sandbox->extract_files( $path );
			$this->assertSame( 1, $sandbox->temp_file_count() );
			$this->assertSame( hash( 'sha256', 'good-bytes' ), $sandbox->sentinel_sha256( 'good.txt' ) );
			$this->assertDirectoryDoesNotExist( untrailingslashit( $sandbox->temp_dir() ) . '-phar', 'The requested plain-tar name has a 49-byte tail and needs no alias.' );

			$this->assertTrue( rename( $path, $long ) );
			$this->assertTrue( unlink( $sandbox->temp_dir() . 'good.txt' ) );
			$sandbox->extract_files( $long );
			$this->assertSame( 1, $sandbox->temp_file_count() );
			$this->assertSame( hash( 'sha256', 'good-bytes' ), $sandbox->sentinel_sha256( 'good.txt' ) );
			$this->assertFileExists( untrailingslashit( $sandbox->temp_dir() ) . '-phar/' . substr( sha1( wp_normalize_path( realpath( $long ) ) ), 0, 12 ) . '.tar', 'A 50-byte tail needs a short alias.' );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Compressed tar SQL members must return their bytes, not an empty stream.
	 *
	 * @return void
	 */
	public function test_tar_gz_with_long_dotted_name_reads_sql_member() {
		$short = $this->dir . '/dump.tar';
		$path  = $this->dir . '/s23-full-bogdan3.mystagingwebsite.com-September-17-2026-23-55.tar.gz';
		$sql   = "CREATE TABLE restored (id int);\nINSERT INTO restored VALUES (1);\n";
		$phar  = new \PharData( $short );
		$phar->addFromString( 'database.sql', $sql );
		$phar->compress( \Phar::GZ );
		unset( $phar );
		$this->assertTrue( rename( $short . '.gz', $path ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$method  = new \ReflectionMethod( $sandbox, 'read_sql_dump' );
		$method->setAccessible( true );

		try {
			$this->assertSame( strlen( $sql ), $sandbox->decompressed_sql_dump_bytes( $path, 1048576 ), 'The size guard must measure the SQL member through the alias.' );
			$this->assertSame( $sql, $method->invoke( $sandbox, $path ) );
			$this->assertSame( 0, $sandbox->temp_file_count(), 'Reading a SQL member must not count the alias as an extracted file.' );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * An alias must not make an empty archive pass the driver's file count.
	 *
	 * @return void
	 */
	public function test_empty_long_named_tar_extracts_nothing() {
		$short = $this->clean_tar();
		$path  = $this->dir . '/s23-full-bogdan3.mystagingwebsite.com-September-17-2026-23-55-00.tar';
		$phar  = new \PharData( $short );
		$phar->delete( 'good.txt' );
		unset( $phar );
		$this->assertTrue( rename( $short, $path ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();

		try {
			$sandbox->extract_files( $path );
			$this->assertSame( 0, $sandbox->temp_file_count() );
			$this->assertFileExists( untrailingslashit( $sandbox->temp_dir() ) . '-phar/' . substr( sha1( wp_normalize_path( realpath( $path ) ) ), 0, 12 ) . '.tar' );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Tear-down must remove aliases as well as the extraction directory.
	 *
	 * @return void
	 */
	public function test_alias_dir_removed_on_tear_down() {
		$path = $this->dir . '/s23-full-bogdan3.mystagingwebsite.com-September-17-2026-23-55-00.tar';
		$this->assertTrue( rename( $this->clean_tar(), $path ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		$alias_dir = untrailingslashit( $sandbox->temp_dir() ) . '-phar';

		try {
			$sandbox->extract_files( $path );
			$this->assertDirectoryExists( $alias_dir );
		} finally {
			$sandbox->tear_down();
		}
		$this->assertDirectoryDoesNotExist( $alias_dir );
		$this->assertDirectoryDoesNotExist( $sandbox->temp_dir() );
		$this->assertFileExists( $path, 'Tear-down must preserve the staged archive.' );
		$sandbox->tear_down();
	}

	/**
	 * extract_tar()'s per-entry loop cannot be exercised end-to-end with a
	 * live traversal fixture: PharData::addFromString('../evil.txt', ...)
	 * does not throw, but the resulting archive's entries never surface
	 * through RecursiveIteratorIterator (confirmed by manual probe - both a
	 * plain foreach and a fresh RecursiveIteratorIterator enumerate zero
	 * entries once a '..' name is present, though PharData::extractTo()
	 * still processes the archive and silently normalises the entry to the
	 * container root rather than escaping it). That leaves PharData's own
	 * extractTo() as the only thing a live fixture could prove, which is the
	 * exact behaviour this defense-in-depth check does not depend on. So the
	 * shared name-validator is unit-tested directly instead, via reflection,
	 * covering the traversal, absolute-path, and stream-wrapper cases the
	 * zip test above cannot (all three route through the same
	 * has_unsafe_entry_name() the zip path already proves end-to-end).
	 *
	 * @return void
	 */
	public function test_unsafe_entry_name_validator_rejects_traversal_absolute_and_wrapper_names() {
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$method  = new \ReflectionMethod( $sandbox, 'has_unsafe_entry_name' );
		$method->setAccessible( true );

		$unsafe = array(
			'../evil.txt',
			'sub/../../evil.txt',
			'/etc/passwd',
			'C:/Windows/evil.txt',
			'phar://some/other/archive.tar',
		);
		foreach ( $unsafe as $name ) {
			$this->assertTrue( $method->invoke( $sandbox, $name ), "Expected '{$name}' to be rejected." );
		}

		$safe = array(
			'good.txt',
			'nested/good.txt',
			'',
		);
		foreach ( $safe as $name ) {
			$this->assertFalse( $method->invoke( $sandbox, $name ), "Expected '{$name}' to be accepted." );
		}
	}
}
