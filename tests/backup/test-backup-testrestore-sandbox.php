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
			$this->assertFileExists( untrailingslashit( $sandbox->temp_dir() ) . '-phar/' . substr( sha1( basename( $long ) ), 0, 12 ) . '.tar', 'A 50-byte tail needs a short alias.' );
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
			$this->assertFileExists( untrailingslashit( $sandbox->temp_dir() ) . '-phar/' . substr( sha1( basename( $path ) ), 0, 12 ) . '.tar' );
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
