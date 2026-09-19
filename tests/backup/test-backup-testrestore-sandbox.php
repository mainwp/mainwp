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

namespace MainWP\Dashboard;

/**
 * Extraction limits need deterministic capacity without filling the host disk.
 *
 * @param string $directory Directory to measure.
 * @return float|false
 */
function disk_free_space( $directory ) {
	$free = Tests\MainWP_Backup_TestRestore_Sandbox_Test::$free_space;
	return null === $free ? \disk_free_space( $directory ) : $free;
}

namespace MainWP\Dashboard\Tests; // phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- the filesystem stub must share the production namespace.

use MainWP\Dashboard\MainWP_Backup_TestRestore_Sandbox;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class MainWP_Backup_TestRestore_Sandbox_Test
 */
class MainWP_Backup_TestRestore_Sandbox_Test extends \WP_UnitTestCase { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- the switchable stub belongs with its regression tests.

	/**
	 * Null preserves the host capacity outside capacity tests.
	 *
	 * @var float|false|null
	 */
	public static $free_space = null;


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
		self::$free_space = null;
		$this->dir        = sys_get_temp_dir() . '/mainwp-tr-sandbox-' . uniqid();
		mkdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
	}

	/**
	 * Remove the fixture scratch dir.
	 *
	 * @return void
	 */
	public function tear_down() {
		self::$free_space = null;
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
	 * ZIP extraction either lands both members or refuses without adding a tree.
	 *
	 * @dataProvider zip_extraction_cases
	 *
	 * @param string      $fixture Archive or destination fault.
	 * @param float|bool  $free    Free bytes, or false when unavailable.
	 * @param string|null $error  Expected error code, or null on success.
	 * @param string|null $detail Expected refusal detail, or null on success.
	 *
	 * @return void
	 */
	public function test_zip_extraction_matrix( $fixture, $free, $error, $detail ) {
		$path = 'unsafe' === $fixture ? $this->malicious_zip() : $this->clean_zip();
		if ( 'unsafe' !== $fixture ) {
			$archive = new \ZipArchive();
			$this->assertTrue( $archive->open( $path ) );
			$this->assertTrue( $archive->addFromString( 'second.txt', 'more-bytes' ) );
			$this->assertTrue( $archive->close() );
		}
		if ( 'open-failure' === $fixture ) {
			$this->assertSame( 9, file_put_contents( $path, 'not a zip' ) );
		}

		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		self::$free_space = $free;

		try {
			if ( 'extract-failure' === $fixture ) {
				// A directory blocks the first file even when the runner can bypass permissions.
				$this->assertTrue( mkdir( $sandbox->temp_dir() . 'good.txt' ) );
			}
			$before = scandir( $sandbox->temp_dir() );
			$this->assertNotFalse( $before );
			$caught = null;
			try {
				if ( 'extract-failure' === $fixture ) {
					// The destination collision intentionally emits an extractor warning.
					@$sandbox->extract_files( $path );
				} else {
					$sandbox->extract_files( $path );
				}
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}

			$this->assertSame( $error, null === $caught ? null : $caught->get_message_error_code() );
			$this->assertSame( $detail, null === $caught ? null : $caught->get_message_extra( false ) );
			if ( null === $error ) {
				$this->assertSame( 2, $sandbox->temp_file_count() );
				$this->assertSame( hash( 'sha256', 'good-bytes' ), $sandbox->sentinel_sha256( 'good.txt' ) );
				$this->assertSame( hash( 'sha256', 'more-bytes' ), $sandbox->sentinel_sha256( 'second.txt' ) );
			} else {
				$this->assertSame( 0, $sandbox->temp_file_count(), 'Refused archives must leave no extracted files.' );
				$this->assertSame( $before, scandir( $sandbox->temp_dir() ), 'Refused archives must add no directories.' );
				if ( 'extract-failure' === $fixture ) {
					$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() . 'good.txt' ) );
				}
			}
			if ( 'unsafe' === $fixture ) {
				$escaped = dirname( untrailingslashit( $sandbox->temp_dir() ) ) . '/evil.txt';
				$this->assertFileDoesNotExist( $escaped );
			}
		} finally {
			self::$free_space = null;
			$sandbox->tear_down();
		}
	}

	/**
	 * Two ten-byte members distinguish total expansion from a per-member limit.
	 *
	 * @return array
	 */
	public static function zip_extraction_cases() {
		$boundary = (float) ( 64 * MB_IN_BYTES + 20 );
		return array(
			'clean archive'         => array( 'clean', $boundary + 1024, null, null ),
			'unsafe entry'          => array( 'unsafe', $boundary + 1024, MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, 'zip traversal entry: ../evil.txt' ),
			'exact boundary'        => array( 'clean', $boundary, null, null ),
			'one byte short'        => array( 'clean', $boundary - 1, MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, 'zip extract free space: clean.zip' ),
			'capacity unavailable'  => array( 'clean', false, null, null ),
			'open returns error'    => array( 'open-failure', $boundary + 1024, MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, 'zip open: clean.zip' ),
			'extract returns false' => array( 'extract-failure', $boundary + 1024, MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, 'zip extract: clean.zip' ),
		);
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
	 * Every member must fit while leaving the reserve available to other uploads.
	 *
	 * @dataProvider extraction_space_cases
	 * @param float|bool $free Free bytes, or false when unavailable.
	 * @param bool       $refused Whether extraction must stop.
	 * @return void
	 */
	public function test_extraction_space_guard( $free, $refused ) {
		$path    = $this->clean_tar();
		$archive = new \PharData( $path );
		$archive->addFromString( 'second.txt', 'more-bytes' );
		unset( $archive );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		self::$free_space = $free;

		try {
			$threw = false;
			try {
				$sandbox->extract_files( $path );
			} catch ( MainWP_Exception $e ) {
				$threw = true;
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $e->get_message_error_code() );
				$this->assertStringContainsString( 'does not have enough free space to extract the archive', $e->getMessage() );
			}
			$this->assertSame( $refused, $threw );
			$this->assertSame( $refused ? 0 : 2, $sandbox->temp_file_count() );
			if ( ! $refused ) {
				$this->assertSame( hash( 'sha256', 'more-bytes' ), $sandbox->sentinel_sha256( 'second.txt' ) );
			}
		} finally {
			self::$free_space = null;
			$sandbox->tear_down();
		}
	}

	/**
	 * Two ten-byte members distinguish total expansion from a per-member limit.
	 *
	 * @return array
	 */
	public static function extraction_space_cases() {
		$boundary = (float) ( 64 * MB_IN_BYTES + 20 );
		return array(
			'tar short'       => array( $boundary - 1, true ),
			'tar boundary'    => array( $boundary, false ),
			'tar unavailable' => array( false, false ),
		);
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
	 * The child replay session accepts zero-date defaults even on strict hosts.
	 *
	 * @return void
	 */
	public function test_restore_session_imports_zero_date_default() {
		$path    = $this->dir . '/zero-date.sql';
		$sql     = "CREATE TABLE t ( d datetime NOT NULL DEFAULT '0000-00-00 00:00:00' );";
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$this->assertSame( strlen( $sql ), file_put_contents( $path, $sql ) );

		try {
			$sandbox->bring_up();
			$property = new \ReflectionProperty( $sandbox, 'mysqli' );
			$property->setAccessible( true );
			$connection = $property->getValue( $sandbox );
			$this->assertInstanceOf( \mysqli::class, $connection ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- the sandbox uses a dedicated mysqli connection.
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli_query -- inspect the dedicated scratch connection, not the live WordPress session.
			$result = $connection->query( 'SELECT @@SESSION.sql_mode, @@SESSION.foreign_key_checks, @@SESSION.unique_checks' );
			$this->assertInstanceOf( \mysqli_result::class, $result );
			$session = $result->fetch_row();
			$result->free();
			$this->assertIsArray( $session );
			$modes = explode( ',', $session[0] );
			sort( $modes );
			$this->assertSame( array( 'ALLOW_INVALID_DATES', 'NO_AUTO_VALUE_ON_ZERO' ), $modes, 'Compare exact modes independently of the server output order.' );
			$this->assertSame( 0, (int) $session[1], 'Foreign key checks must match the child replay session.' );
			$this->assertSame( 0, (int) $session[2], 'Unique checks must match the child replay session.' );

			$sandbox->import_database( $path );
			$this->assertSame( 1, $sandbox->table_count(), 'The zero-date table must import cleanly.' );
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
	 * Each filesystem and directive shape must preserve operator files or produce the specified refusal.
	 *
	 * @dataProvider protect_parent_matrix
	 *
	 * @param string $fixture     File, shape and contents marker.
	 * @param string $outcome     Expected write, accept, skip or refuse.
	 * @param string $reason      Refusal detail substring.
	 * @param string $final_state Expected contents or preservation.
	 *
	 * @return void
	 */
	public function test_protect_parent_matrix( string $fixture, string $outcome, string $reason, string $final_state ): void {
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

		list( $name, $shape, $content ) = explode( ':', $fixture );

		$parent   = dirname( untrailingslashit( $sandbox->temp_dir() ) );
		$htaccess = $parent . '/.htaccess';
		$index    = $parent . '/index.php';
		$file     = 'index' === $name ? $index : $htaccess;
		$target   = 'zero' === $content ? '/dev/zero' : $parent . '/operator-file';
		$linked   = in_array( $shape, array( 'symlink', 'hardlink' ), true );
		$storage  = $linked ? $target : $file;
		$payloads = array(
			'empty'          => '',
			'deny'           => 'deny from all',
			'mixed'          => "# Existing protection\nDeNy FrOm AlL\n",
			'require'        => 'Require all denied',
			'comment'        => "# deny from all\nRequire all granted\n",
			'rule-allow'     => "deny from all\nallow from all\n",
			'permissive'     => 'allow from all',
			'unwritable'     => 'allow from all',
			'unreadable'     => 'deny from all',
			'late'           => str_repeat( "# x\n", 2300 ) . "deny from all\n",
			'large'          => "deny from all\n" . str_repeat( "# x\n", 5000 ),
			'directives'     => "Deny from all\n# Retain this directive.\nOptions -Indexes\n",
			'credential'     => '<?php $credential = "retained";',
			'host-list'      => "Deny from all-granted\n",
			'ifmodule'       => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n",
			'sibling'        => "Require all denied\nRequire all granted\n",
			'multi-host'     => "Deny from all example.com\n",
			// 8179 spaces put byte 8192 immediately after "all", so a read capped at
			// 8192 would see a whole "Deny from all" line where the file has a host list.
			'boundary'       => str_repeat( ' ', 8179 ) . "Deny from all-granted\n",
			'cont-allow'     => "Order Deny,Allow\nAllow from all \\\nDeny from all\n",
			'cont-comment'   => "# disabled for now \\\nDeny from all\n",
			'cont-split'     => "Deny from \\\nall\n",
			'cont-double'    => "Deny from \\\\\nall\n",
			'cont-glued'     => "Deny from all\\\nexample.com\n",
			'cont-word'      => "Deny from al\\\nl\n",
			'cont-ws-crlf'   => "Deny from \\ \t\r\nall\r\n",
			'cont-crlf'      => "Deny from \\\r\nall\r\n",
			'cont-cmt-ws'    => "# disabled for now \\ \nDeny from all\n",
			'cont-dbl-blank' => "# x \\\\\n\nDeny from all\n",
			'cont-allow-dbl' => "Allow from all \\\\\n\nDeny from all\n",
			'cont-eof'       => 'Deny from all \\',
			'cont-eof-bare'  => 'Deny from \\',
			'cont-severed'   => str_repeat( ' ', 8177 ) . "Deny from all \\\nexample.com\n",
			'quoted-dq'      => "Deny from \"all\"\n",
			'quoted-sq'      => "Deny from 'all'\n",
			'quoted-require' => "Require \"all\" denied\n",
			'quoted-name'    => "\"Deny\" 'from' all\n",
			'quoted-partial' => "Deny from al\"l\"\n",
			'quoted-hash'    => "Deny from all \"#\"\n",
			'quoted-glued'   => "Require \"all\"denied\n",
			'crlf-cap'       => str_repeat( ' ', 8179 ) . "Deny from all\r\n# tail\n",
			'lf-cap'         => str_repeat( ' ', 8179 ) . "Deny from all\n# tail\n",
		);

		$bytes    = $payloads[ $content ] ?? '';
		$cleanup  = null;
		$alarm    = false;
		$modified = null;
		$method   = new \ReflectionMethod( $sandbox, 'protect_parent' );
		$method->setAccessible( true );

		try {
			$this->assertTrue( wp_mkdir_p( $parent ) );
			// A pre-existing root proves refusals clean up and accepted protection preserves it.
			$this->assertTrue( wp_mkdir_p( $sandbox->temp_dir() ) );
			if ( 'fifo' === $shape || 'fifo' === $content ) {
				if ( ! function_exists( 'posix_mkfifo' ) || ! function_exists( 'pcntl_async_signals' ) ) {
					$this->markTestSkipped( 'FIFO coverage requires posix_mkfifo and pcntl_async_signals.' );
				}
				$previous_async   = pcntl_async_signals( true );
				$previous_handler = pcntl_signal_get_handler( SIGALRM );
				pcntl_signal(
					SIGALRM,
					static function () {
						throw new \RuntimeException( 'Protection opened a FIFO and exceeded five seconds.' );
					},
					false
				);
				// An accidental FIFO read would otherwise hang the suite.
				pcntl_alarm( 5 );
				$alarm = true;
			}
			if ( 'zero' === $content && ! is_readable( '/dev/zero' ) ) {
				$this->markTestSkipped( '/dev/zero is not readable on this runner.' );
			}

			// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- the fixture contract names the protection parent.
			$builder = function ( $parent, $htaccess, $index ) use ( $shape, $content, $name, $storage, $target, $bytes ) {
				$file = 'index' === $name ? $index : $htaccess;
				if ( 'directory' === $shape || 'directory' === $content ) {
					$this->assertTrue( wp_mkdir_p( $storage ) );
				} elseif ( 'fifo' === $shape || 'fifo' === $content ) {
					$this->assertTrue( posix_mkfifo( $storage, 0600 ) );
				} elseif ( 'missing' !== $shape && ! in_array( $content, array( 'dangling', 'zero' ), true ) ) {
					$length = 'late' === $content ? 9214 : ( 'large' === $content ? 20014 : strlen( $bytes ) );
					$this->assertSame( $length, file_put_contents( $storage, $bytes ) );
				}
				if ( 'symlink' === $shape ) {
					$this->assertTrue( symlink( $target, $file ) );
				} elseif ( 'hardlink' === $shape && ! @link( $target, $file ) ) {
					$this->markTestSkipped( 'The filesystem cannot create hard links.' );
				}
				if ( 'blocked-parent' === $content ) {
					$this->assertSame( 13, file_put_contents( $htaccess, 'deny from all' ) );
				}
				return static function () use ( $parent, $file, $storage, $shape, $content ) {
					if ( 'blocked-parent' === $content ) {
						chmod( $parent, 0755 );
					}
					if ( in_array( $content, array( 'unreadable', 'unwritable' ), true ) ) {
						chmod( $storage, 0644 );
					}
					// rrmdir follows directory symlinks, so remove the fixture link first.
					if ( 'symlink' === $shape && is_link( $file ) ) {
						unlink( $file );
					}
				};
			};
			$cleanup = $builder( $parent, $htaccess, $index );

			if ( file_exists( $file ) && 'zero' !== $content ) {
				$this->assertTrue( touch( $file, 1000000000 ) );
				$modified = 1000000000;
			}
			$link_before = is_link( $file ) ? lstat( $file ) : null;
			if ( in_array( $content, array( 'unreadable', 'unwritable', 'blocked-parent' ), true ) ) {
				$mode_path = 'blocked-parent' === $content ? $parent : $storage;
				$mode      = 'unreadable' === $content ? 0000 : ( 'unwritable' === $content ? 0444 : 0555 );
				$this->assertTrue( chmod( $mode_path, $mode ) );
				clearstatcache();
				if ( 'unreadable' === $content && is_readable( $file ) ) {
					$this->markTestSkipped( 'The process can read chmod 000 protection files.' );
				}
				if ( 'unreadable' !== $content && is_writable( $mode_path ) ) {
					$this->markTestSkipped( 'The process can write read-only protection fixtures.' );
				}
				if ( 'unwritable' === $content ) {
					$this->assertFalse( is_writable( $file ), 'The fixture must prevent deny-rule repair.' );
					$this->assertTrue( is_readable( $file ), 'The fixture must remain readable.' );
				}
			}

			$caught  = null;
			$started = microtime( true );
			try {
				$method->invoke( $sandbox );
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}
			$elapsed = microtime( true ) - $started;
			if ( 'zero' === $content ) {
				// A smoke check on the refusal, not on the cap. The guard reads through a symlink
				// on purpose, which is how the other `a` rows reach ACCEPT, so what keeps this
				// device unopened is is_file() being false for it, not the path being a link.
				// Rows 11 and 25 are what pin the cap. The timer stays because an unbounded
				// device read would kill PHP here, and PHPUnit reports that as a dead runner
				// rather than a red test.
				$this->assertLessThan( 1.0, $elapsed, 'Device refusal must return within one second.' );
			}
			if ( 'refuse' === $outcome ) {
				$this->assertInstanceOf( MainWP_Exception::class, $caught );
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, $caught->get_message_error_code() );
				$this->assertSame( 'The test-restore sandbox could not protect its working directory.', $caught->getMessage() );
				$this->assertStringContainsString( $reason, $caught->get_message_extra( false ) );
				if ( 'unwritable' === $content ) {
					$this->assertStringStartsWith( 'sandbox protection: ' . $parent . ': ', $caught->get_message_extra( false ) );
					$this->assertStringContainsString( 'file_put_contents(' . $htaccess . '):', $caught->get_message_extra( false ) );
				} else {
					$this->assertSame( 'sandbox protection: ' . $file . ' ' . $reason, $caught->get_message_extra( false ) );
				}
				$this->assertDirectoryDoesNotExist( $sandbox->temp_dir() );
				$this->assertFileDoesNotExist( $index );
			} else {
				$this->assertNull( $caught, 'Protection must not refuse this matrix row.' );
				$this->assertDirectoryExists( $sandbox->temp_dir() );
				if ( 'htaccess' === $name ) {
					$this->assertFileExists( $index );
					$this->assertSame( '', file_get_contents( $index ) );
				} else {
					$this->assertSame( 'deny from all', file_get_contents( $htaccess ) );
				}
			}

			if ( in_array( $content, array( 'unreadable', 'unwritable' ), true ) ) {
				$this->assertTrue( chmod( $storage, 0644 ) );
			}
			clearstatcache( true, $file );
			if ( 'deny' === $final_state ) {
				$this->assertSame( 'deny from all', file_get_contents( $file ) );
			} elseif ( 'empty index' === $final_state ) {
				$this->assertFileExists( $index );
				$this->assertSame( '', file_get_contents( $index ) );
			} elseif ( 'index absent' === $final_state ) {
				$this->assertFileDoesNotExist( $index );
			} else {
				$this->assertSame( 'untouched', $final_state );
				if ( null !== $modified ) {
					$this->assertSame( $modified, filemtime( $file ), 'Protection must not rewrite an existing file.' );
				}
				if ( is_file( $storage ) ) {
					$this->assertSame( $bytes, file_get_contents( $storage ), 'Operator bytes must survive.' );
				}
			}
			if ( 'symlink' === $shape ) {
				$this->assertTrue( is_link( $file ) );
				$this->assertSame( $target, readlink( $file ) );
				$this->assertSame( $link_before['mtime'], lstat( $file )['mtime'] );
				if ( 'dangling' === $content ) {
					$this->assertFileDoesNotExist( $target );
				}
			} elseif ( 'hardlink' === $shape ) {
				$this->assertSame( 2, stat( $file )['nlink'] );
				$this->assertSame( fileinode( $target ), fileinode( $file ) );
			}
			if ( 'directory' === $shape || 'directory' === $content ) {
				$this->assertDirectoryExists( $storage );
			} elseif ( 'fifo' === $shape || 'fifo' === $content ) {
				$this->assertSame( 'fifo', filetype( $storage ) );
			}
		} finally {
			if ( $alarm ) {
				pcntl_alarm( 0 );
				pcntl_signal( SIGALRM, $previous_handler );
				pcntl_async_signals( $previous_async );
			}
			if ( null !== $cleanup ) {
				$cleanup();
			}
			$sandbox->tear_down();
		}
	}

	/**
	 * # protect_parent() input matrix (84 cases)
	 *
	 * Path classification, evaluated with lstat first (is_link), then stat:
	 * - MISSING: is_link false, file_exists false.
	 * - FOREIGN: is_link true (any target, including dangling), OR exists and not a regular file
	 *   (directory, FIFO, char/block device), OR a regular file with nlink > 1. Reason strings,
	 *   in this priority: "is a symlink", "has multiple hard links", "is not a regular file".
	 *   The plugin never writes through a FOREIGN protection file. The classification is
	 *   per file, not per directory: a symlinked test-restore parent resolves before
	 *   `.htaccess` is ever looked at, and is not this guard's to catch.
	 * - OWN: exists, is a regular file, nlink == 1, not a symlink.
	 *
	 * Deny-rule match (RULE): applied to a nominal first 8192 bytes, read only when
	 * is_file() is true (a directory, FIFO or device is never opened), regex
	 * `/^[ \t]*(?:(?:deny|"deny"|'deny')[ \t]+(?:from|"from"|'from')[ \t]+(?:all|"all"|'all')(?:[ \t]++[^#\s]++)*+|(?:require|"require"|'require')[ \t]+(?:all|"all"|'all')[ \t]+(?:denied|"denied"|'denied'))[ \t]*\r?$/mi`.
	 * The read takes two bytes past the cap so content ending at byte 8192 retains its
	 * CRLF at bytes 8193-8194 (rows 50-50a); one extra byte would lose that complete line.
	 * The sever trim runs after unfolding: `$` also matches end of subject, so a severed
	 * `Deny from all-granted` must not read as a whole rule (row 12e), nor may a continuation
	 * whose partner lies outside the window (rows 42-42a). The LF neighbour also matches (row 51).
	 * The directive has to be a complete line. Checked against Apache 2.4.67's own parser
	 * (`apachectl -t` over a config file with mpm_prefork, authz_core, access_compat,
	 * authz_host and unixd loaded): `Deny from all` takes further host arguments and still
	 * denies everything, so `Deny from all example.com` matches (row 12d); a `#` anywhere in
	 * the argument list is "No comments are allowed here", a syntax error rather than a
	 * comment, so `Deny from all # why` and `Deny from all foo#bar` do not match; and a first
	 * argument of `all-granted` or `all.example.com` is a hostname restriction that denies
	 * nothing, so neither matches. A commented-out rule (`# deny from all`) does not match.
	 * Apache 2.4's `Require all denied` matches: it is a deny rule and overwriting it
	 * flattened an operator directive. A UTF-8 BOM is not skipped on this platform:
	 * measured on Unix, a BOM before `Order Deny,Allow` reports
	 * `Invalid command '\xef\xbb\xbfOrder'`, so a BOM-prefixed deny is not a deny there and
	 * rejecting it is the right answer rather than a gap. Apache consumes a leading BOM
	 * under WIN32, which was not measured here, so do not read this as platform
	 * independent. On this platform the syntax error should fail the whole `.htaccess` and
	 * leave the directory serving 500s, which is expected from the parse result rather than
	 * observed through a running server. Where the BOM is consumed there is no syntax error
	 * at all and the rule works, so the rejection there is a plain false reject. The one
	 * platform-independent part is the price: a foreign path costs the month either way.
	 *
	 * Continuation: a backslash sitting immediately before LF or CRLF disappears with its line
	 * ending and nothing is inserted, while whitespace before the backslash and on the next
	 * line stays. One space or tab between the backslash and the line ending and the line does
	 * NOT continue, which a bare syntax check cannot tell apart, so it was settled with two
	 * independent discriminators: `# x \` + `ThisIsNotADirective` parses (the bogus directive
	 * was swallowed into the comment) while `# x \` + a space + the same line reports "Invalid
	 * command 'ThisIsNotADirective'"; `ServerTokens \` + `Prod` parses while `ServerTokens \`
	 * + a space + `Prod` reports "ServerTokens takes 1 argument". A tab behaves as the space
	 * does, CRLF continues as LF does (rows 39, 52). The earlier `Order Deny,Allow \` + a space
	 * reading was not a discriminator: that line is already two arguments, so "order takes one
	 * argument" comes out either way.
	 *
	 * Apache re-reads the joined buffer after every append, so a doubled backslash whose partner
	 * line is empty continues twice, which is why the unfold runs to a fixed point. Measured:
	 * `# x \\` + an empty line + `ThisIsNotADirective` parses, the same with one backslash
	 * reports "Invalid command", and a second bogus directive after it still errors, so the
	 * swallow is one line rather than an early abort (rows 54, 55). The loop is bounded by the
	 * read: 4097 backslashes then 4097 newlines, the worst shape that fits, converges in 4098
	 * passes and about a millisecond.
	 *
	 * Other measurements: `Order \\` + `Deny,Allow` reports "unknown order" for `\Deny,Allow`,
	 * not "order takes one argument", so the join inserts nothing; `Deny from all\` +
	 * `example.com` is the host `allexample.com`, while `Deny from al\` + `l` denies all.
	 * `Allow from all \` + `Deny from all` is one permissive Allow; `# disabled for now \`
	 * swallows a following deny into the comment; `Deny from \` + `all` denies all.
	 * At EOF without a newline, the backslash stays literal. The case that runs is
	 * `run-eof.sh c8_tok_bs_eof`, `ServerTokens Prod \` with the backslash as the file's last
	 * byte, which reports "takes one argument". The `Deny` and `Order` forms cannot be the
	 * last line of the oracle's main config, because they need a `<Directory>` block that
	 * would have to close after them; an `.htaccess` is already directory context, so there
	 * they are ordinary last lines, which is what row 40 tests. The same rule gives
	 * `Deny from all \` an extra literal host and leaves `Deny from \` naming only `\`.
	 * Unfolding before trimming drops a severed logical line even if its physical prefix
	 * says `Deny from all \`; this is the deliberate cap of rows 11 and 25.
	 *
	 * Quoting: recognised tokens are separated by spaces/tabs, bare or wholly wrapped in
	 * matching single or double quotes, including directive names. Measured: `"Require" bogus denied`,
	 * `Require "bogus" denied` and `Require 'bogus' denied` all report "Unknown Authz provider: bogus".
	 * `Order "Deny,Allow"` and `Deny "from" all` parse; `Deny "frum" all` reports "must be followed
	 * by 'from'", and `Require all "bogus"` reports "must be 'granted' or 'denied'".
	 * A bare token can contain quotes: `Deny from al"l"` names the host `al"l"`, not `all`.
	 * Host arguments after `all` remain opaque runs excluding `#`: `Deny from all "#"` and
	 * `Deny from all "x#y"` both report "No comments are allowed here", just like bare forms.
	 *
	 * Outcomes: WRITE (write contents, then read back the first 8 KB and require RULE, else refuse),
	 * ACCEPT (rule seen, no write), SKIP (no read, no write), REFUSE (tear_down, then throw
	 * ERR_UNSUPPORTED; the classification refusal reads "sandbox protection: <file> <reason>"
	 * and the failed-repair refusal reads "sandbox protection: <parent>: <write errno>";
	 * either costs CAPABILITY_TTL, one month).
	 *
	 * ## .htaccess
	 *
	 * | # | shape | class | outcome | reason / note |
	 * |---|-------|-------|---------|---------------|
	 * | 1 | missing | MISSING | WRITE | |
	 * | 2 | empty regular file | OWN | WRITE | |
	 * | 3 | exactly `deny from all` | OWN | ACCEPT | mtime untouched |
	 * | 4 | mixed case `DeNy FrOm AlL` after a comment line | OWN | ACCEPT | |
	 * | 5 | `Require all denied` only | OWN | ACCEPT | NEW: was overwritten (Aikido row 2) |
	 * | 6 | only `# deny from all` (commented) above `Require all granted` | OWN | WRITE | NEW: was accepted by substring (Aikido row 1) |
	 * | 7 | `deny from all` then `allow from all` | OWN | ACCEPT | text check is defense in depth, not the only control |
	 * | 8 | `allow from all` (permissive) | OWN | WRITE | existing test |
	 * | 9 | permissive and not writable (chmod 0444, non-root) | OWN | REFUSE | read-back lacks rule; detail carries the write errno; existing test |
	 * | 10 | unreadable (chmod 0000, non-root) | OWN | SKIP | recorded pushback; web server enforces it |
	 * | 11 | rule at offset 9200 of a 9214-byte file (past the 8 KB cap) | OWN | WRITE | cap is deliberate: overwrite is the plugin repairing its own file |
	 * | 12 | rule at offset 0 of a 20014-byte file | OWN | ACCEPT | bounded read still sees it |
	 * | 12a | `Deny from all-granted` (a host list, not a global deny) | OWN | WRITE | the directive must be the whole line |
	 * | 12b | `Require all denied` inside `<IfModule mod_authz_core.c>` | OWN | ACCEPT | no deny if the module is absent; recorded pushback, the text check is defense in depth |
	 * | 12c | `Require all denied` then `Require all granted` | OWN | ACCEPT | 2.4's implicit `<RequireAny>` lets the grant win; same recorded pushback |
	 * | 12d | `Deny from all example.com` (further host arguments) | OWN | ACCEPT | a standalone `all` argument still denies everything |
	 * | 12e | `Deny from all-granted` with byte 8192 landing right after `all` | OWN | WRITE | the read drops the line the cap cut in half |
	 * | 13 | directory | FOREIGN | REFUSE | is not a regular file; no read |
	 * | 14 | FIFO | FOREIGN | REFUSE | is not a regular file; no read (never opened) |
	 * | 15 | symlink to `/dev/zero` | FOREIGN | REFUSE | is a symlink. The guard still reads through a symlink first, which is how the other `a` rows reach ACCEPT, so what keeps the device unopened is `is_file('/dev/zero')` being false, not the link. The sub-second assertion is a smoke check on that, not on the cap; rows 11 and 25 pin the cap and the FIFO rows pin the is_file() gate. |
	 * | 16 | dangling symlink | FOREIGN | REFUSE | is a symlink |
	 * | 17 | symlink to a directory | FOREIGN | REFUSE | is a symlink |
	 * | 18 | symlink to a FIFO | FOREIGN | REFUSE | is a symlink; no read |
	 * | 19 | symlink to regular file holding the rule | FOREIGN | ACCEPT | recorded pushback; link untouched |
	 * | 20 | symlink to regular file with `Require all denied` | FOREIGN | ACCEPT | |
	 * | 21 | symlink to regular file with only a commented-out rule | FOREIGN | REFUSE | NEW: was accepted by substring |
	 * | 22 | symlink to regular permissive file | FOREIGN | REFUSE | is a symlink; target untouched |
	 * | 23 | symlink to regular empty file | FOREIGN | REFUSE | |
	 * | 24 | symlink to unreadable regular file (non-root) | FOREIGN | SKIP | NEW: was refused by accident ((string) false); same reasoning as row 10, and the plugin still never writes |
	 * | 25 | symlink to regular file, rule past the 8 KB cap | FOREIGN | REFUSE | cap is deliberate (recorded) |
	 * | 26 | hard link (nlink 2) to regular file holding the rule | FOREIGN | ACCEPT | snapshot-style backups produce this legitimately |
	 * | 27 | hard link to regular permissive file | FOREIGN | REFUSE | has multiple hard links |
	 * | 28 | hard link to regular empty file | FOREIGN | REFUSE | |
	 * | 29 | hard link to unreadable regular file (non-root) | FOREIGN | SKIP | as row 24 |
	 * | 33 | continued Allow followed by deny | OWN | WRITE | one permissive Allow |
	 * | 33a | symlink to continued Allow followed by deny | FOREIGN | REFUSE | is a symlink; one permissive Allow |
	 * | 34 | continued comment followed by deny | OWN | WRITE | deny swallowed into the comment |
	 * | 34a | symlink to continued comment followed by deny | FOREIGN | REFUSE | is a symlink; deny swallowed into the comment |
	 * | 35 | `Deny from \` + `all` | OWN | ACCEPT | joined directive denies all |
	 * | 35a | symlink to `Deny from \` + `all` | FOREIGN | ACCEPT | joined directive denies all |
	 * | 36 | `Deny from \\` + `all` | OWN | WRITE | host `\all` |
	 * | 36a | symlink to `Deny from \\` + `all` | FOREIGN | REFUSE | is a symlink; host `\all` |
	 * | 37 | `Deny from all\` + `example.com` | OWN | WRITE | host `allexample.com`; no inserted space |
	 * | 38 | `Deny from al\` + `l` | OWN | ACCEPT | joined `all` denies; no inserted space |
	 * | 39 | `Deny from \` then space, tab, CRLF and `all` | OWN | WRITE | a space after the backslash ends the line, so Apache reads the host `\` and then an invalid `all` |
	 * | 39a | symlink to `Deny from \` then space, tab, CRLF and `all` | FOREIGN | REFUSE | is a symlink; not a deny either way |
	 * | 40 | `Deny from all \` at EOF | OWN | ACCEPT | literal extra host; no continuation |
	 * | 41 | `Deny from \` at EOF | OWN | WRITE | only the literal host `\` |
	 * | 42 | continued deny ending at 8192, LF at 8193, partner beyond window | OWN | WRITE | severed logical line is dropped |
	 * | 42a | symlink to continued deny ending at 8192, LF at 8193, partner beyond window | FOREIGN | REFUSE | is a symlink; severed logical line is dropped |
	 * | 43 | `Deny from "all"` | OWN | ACCEPT | dequoted `all` |
	 * | 43a | symlink to `Deny from "all"` | FOREIGN | ACCEPT | dequoted `all` |
	 * | 44 | `Deny from 'all'` | OWN | ACCEPT | dequoted `all` |
	 * | 45 | `Require "all" denied` | OWN | ACCEPT | dequoted provider |
	 * | 45a | symlink to `Require "all" denied` | FOREIGN | ACCEPT | dequoted provider |
	 * | 46 | `"Deny" 'from' all` | OWN | ACCEPT | directive name and `from` dequoted |
	 * | 47 | `Deny from al"l"` | OWN | WRITE | host `al"l"` |
	 * | 47a | symlink to `Deny from al"l"` | FOREIGN | REFUSE | is a symlink; host `al"l"` |
	 * | 48 | `Deny from all "#"` | OWN | WRITE | quoted `#` still causes a syntax error |
	 * | 48a | symlink to `Deny from all "#"` | FOREIGN | REFUSE | is a symlink; quoted `#` still causes a syntax error |
	 * | 49 | `Require "all"denied` | OWN | WRITE | Apache denies; deliberately outside the whitespace-separated grammar |
	 * | 50 | content ends at 8192, CR at 8193, LF at 8194 | OWN | ACCEPT | complete deny; +1 read rejected it |
	 * | 50a | symlink to content ends at 8192, CR at 8193, LF at 8194 | FOREIGN | ACCEPT | complete deny; +1 read rejected it |
	 * | 51 | content ends at 8192, LF at 8193 | OWN | ACCEPT | complete deny; LF neighbour |
	 * | 52 | `Deny from \` + CRLF + `all` | OWN | ACCEPT | CRLF continues where a space does not |
	 * | 53 | `# disabled for now \` then a space, then `Deny from all` | OWN | ACCEPT | no continuation, so the deny is a real one |
	 * | 53a | symlink to the same | FOREIGN | ACCEPT | no continuation, so the deny is a real one |
	 * | 54 | `# x \\` + an empty line + `Deny from all` | OWN | WRITE | the buffer is re-read, so the doubled backslash swallows the deny |
	 * | 54a | symlink to the same | FOREIGN | REFUSE | is a symlink; the deny is swallowed |
	 * | 55 | `Allow from all \\` + an empty line + `Deny from all` | OWN | WRITE | one permissive Allow after two joins |
	 *
	 * ## index.php
	 *
	 * | # | shape | class | outcome |
	 * |---|-------|-------|---------|
	 * | 30 | missing | MISSING | WRITE (best effort: no read-back, no refusal) |
	 * | 31 | any regular file, any contents, readable or not | OWN | SKIP |
	 * | 32 | symlink (incl. dangling), hard link, directory, FIFO | FOREIGN | SKIP |
	 *
	 * Known and deliberate, not built for:
	 * - The 8 KB cap. Measured: a rule at offset 9200 in a 9214-byte file is not accepted, a rule at offset 0 in a 20014-byte file is. 8 KB of directives ahead of a deny line is not a shape anyone writes; the cap is what keeps a fat regular file from the OOM fatal that leaks a scratch database per tick. The cap cutting a line in half is handled rather than accepted, row 12e.
	 * - The is_file()-to-read TOCTOU: swapping a regular file for a FIFO between the check and the read reproduces the round-3 hang. Closing it needs O_NONBLOCK + fstat on a descriptor; an attacker who already has uploads write does not need to win this race. The same swap between the classification block and file_put_contents() has the same two outcomes on the write side, follow a symlink or block opening a FIFO, and is accepted on the same grounds.
	 * - A regular file on a hung mount is not this guard's to handle.
	 * - Quoted tokens followed by text without whitespace. Measured: `Require "all"denied` and `Deny from "all"x` parse as separate words in Apache; both deny, but this grammar deliberately does not match them (row 49).
	 * - Unterminated quotes. Measured: `Order "Deny,Allow` parses because the quote runs to the end of the line; this grammar requires a closing quote.
	 * - A bare CR inside a directive line. Apache treats it as whitespace: measured, `Order Deny,Allow<CR>x` reports "order takes one argument" where `Order Deny,Allowx` reports "unknown order", so `<CR>Deny from all`, `Deny from all<CR>-granted` and `Deny<CR>from<CR>all` all deny in Apache and are rejected here. Refuse-too-much only, never accept-too-much, identical at the review base, and it needs a lone CR in the middle of a line of an otherwise LF file. Recorded under `cr-inside-a-line-is-not-treated-as-whitespace`; it belongs in the next re-derivation of the grammar as its own dimension, not in a character class.
	 *
	 * @return array
	 */
	public static function protect_parent_matrix(): array {
		return array(
			'#1 missing htaccess'              => array( 'htaccess:missing:empty', 'write', '', 'deny' ),
			'#2 own empty'                     => array( 'htaccess:own:empty', 'write', '', 'deny' ),
			'#3 own deny from all'             => array( 'htaccess:own:deny', 'accept', '', 'untouched' ),
			'#4 own mixed case after comment'  => array( 'htaccess:own:mixed', 'accept', '', 'untouched' ),
			'#5 own Require all denied'        => array( 'htaccess:own:require', 'accept', '', 'untouched' ),
			'#6 own commented deny'            => array( 'htaccess:own:comment', 'write', '', 'deny' ),
			'#7 own deny then allow'           => array( 'htaccess:own:rule-allow', 'accept', '', 'untouched' ),
			'#8 own permissive'                => array( 'htaccess:own:permissive', 'write', '', 'deny' ),
			'#9 own unwritable'                => array( 'htaccess:own:unwritable', 'refuse', 'Permission denied', 'untouched' ),
			'#10 own unreadable'               => array( 'htaccess:own:unreadable', 'skip', '', 'untouched' ),
			'#11 own rule past cap'            => array( 'htaccess:own:late', 'write', '', 'deny' ),
			'#12 own large rule first'         => array( 'htaccess:own:large', 'accept', '', 'untouched' ),
			'#12a own deny from host list'     => array( 'htaccess:own:host-list', 'write', '', 'deny' ),
			'#12b own IfModule denied'         => array( 'htaccess:own:ifmodule', 'accept', '', 'untouched' ),
			'#12c own denied then granted'     => array( 'htaccess:own:sibling', 'accept', '', 'untouched' ),
			'#12d own deny with host args'     => array( 'htaccess:own:multi-host', 'accept', '', 'untouched' ),
			'#12e own rule cut at the cap'     => array( 'htaccess:own:boundary', 'write', '', 'deny' ),
			'#13 directory'                    => array( 'htaccess:directory:empty', 'refuse', 'is not a regular file', 'untouched' ),
			'#14 FIFO'                         => array( 'htaccess:fifo:empty', 'refuse', 'is not a regular file', 'untouched' ),
			'#15 symlink to dev zero'          => array( 'htaccess:symlink:zero', 'refuse', 'is a symlink', 'untouched' ),
			'#16 dangling symlink'             => array( 'htaccess:symlink:dangling', 'refuse', 'is a symlink', 'untouched' ),
			'#17 symlink to directory'         => array( 'htaccess:symlink:directory', 'refuse', 'is a symlink', 'untouched' ),
			'#18 symlink to FIFO'              => array( 'htaccess:symlink:fifo', 'refuse', 'is a symlink', 'untouched' ),
			'#19 symlink with deny directives' => array( 'htaccess:symlink:directives', 'accept', '', 'untouched' ),
			'#20 symlink Require all denied'   => array( 'htaccess:symlink:require', 'accept', '', 'untouched' ),
			'#21 symlink commented deny'       => array( 'htaccess:symlink:comment', 'refuse', 'is a symlink', 'untouched' ),
			'#22 symlink permissive'           => array( 'htaccess:symlink:permissive', 'refuse', 'is a symlink', 'untouched' ),
			'#22 symlink credential bytes'     => array( 'htaccess:symlink:credential', 'refuse', 'is a symlink', 'untouched' ),
			'#23 symlink empty'                => array( 'htaccess:symlink:empty', 'refuse', 'is a symlink', 'untouched' ),
			'#24 symlink unreadable'           => array( 'htaccess:symlink:unreadable', 'skip', '', 'untouched' ),
			'#25 symlink rule past cap'        => array( 'htaccess:symlink:late', 'refuse', 'is a symlink', 'untouched' ),
			'#26 hardlink deny'                => array( 'htaccess:hardlink:deny', 'accept', '', 'untouched' ),
			'#27 hardlink permissive'          => array( 'htaccess:hardlink:permissive', 'refuse', 'has multiple hard links', 'untouched' ),
			'#27 hardlink credential bytes'    => array( 'htaccess:hardlink:credential', 'refuse', 'has multiple hard links', 'untouched' ),
			'#28 hardlink empty'               => array( 'htaccess:hardlink:empty', 'refuse', 'has multiple hard links', 'untouched' ),
			'#29 hardlink unreadable'          => array( 'htaccess:hardlink:unreadable', 'skip', '', 'untouched' ),
			'#30 missing index'                => array( 'index:missing:empty', 'write', '', 'empty index' ),
			'#30 best effort index failure'    => array( 'index:missing:blocked-parent', 'write', '', 'index absent' ),
			'#31 own empty index'              => array( 'index:own:empty', 'skip', '', 'untouched' ),
			'#31 own nonempty index'           => array( 'index:own:credential', 'skip', '', 'untouched' ),
			'#31 own unreadable index'         => array( 'index:own:unreadable', 'skip', '', 'untouched' ),
			'#32 index symlink'                => array( 'index:symlink:credential', 'skip', '', 'untouched' ),
			'#32 index dangling symlink'       => array( 'index:symlink:dangling', 'skip', '', 'untouched' ),
			'#32 index directory symlink'      => array( 'index:symlink:directory', 'skip', '', 'untouched' ),
			'#32 index FIFO symlink'           => array( 'index:symlink:fifo', 'skip', '', 'untouched' ),
			'#32 index hardlink'               => array( 'index:hardlink:credential', 'skip', '', 'untouched' ),
			'#32 index directory'              => array( 'index:directory:empty', 'skip', '', 'untouched' ),
			'#32 index FIFO'                   => array( 'index:fifo:empty', 'skip', '', 'untouched' ),
			'#33 own cont-allow'               => array( 'htaccess:own:cont-allow', 'write', '', 'deny' ),
			'#33a symlink cont-allow'          => array( 'htaccess:symlink:cont-allow', 'refuse', 'is a symlink', 'untouched' ),
			'#34 own cont-comment'             => array( 'htaccess:own:cont-comment', 'write', '', 'deny' ),
			'#34a symlink cont-comment'        => array( 'htaccess:symlink:cont-comment', 'refuse', 'is a symlink', 'untouched' ),
			'#35 own cont-split'               => array( 'htaccess:own:cont-split', 'accept', '', 'untouched' ),
			'#35a symlink cont-split'          => array( 'htaccess:symlink:cont-split', 'accept', '', 'untouched' ),
			'#36 own cont-double'              => array( 'htaccess:own:cont-double', 'write', '', 'deny' ),
			'#36a symlink cont-double'         => array( 'htaccess:symlink:cont-double', 'refuse', 'is a symlink', 'untouched' ),
			'#37 own cont-glued'               => array( 'htaccess:own:cont-glued', 'write', '', 'deny' ),
			'#38 own cont-word'                => array( 'htaccess:own:cont-word', 'accept', '', 'untouched' ),
			'#39 own cont-ws-crlf'             => array( 'htaccess:own:cont-ws-crlf', 'write', '', 'deny' ),
			'#39a symlink cont-ws-crlf'        => array( 'htaccess:symlink:cont-ws-crlf', 'refuse', 'is a symlink', 'untouched' ),
			'#52 own cont-crlf'                => array( 'htaccess:own:cont-crlf', 'accept', '', 'untouched' ),
			'#53 own cont-cmt-ws'              => array( 'htaccess:own:cont-cmt-ws', 'accept', '', 'untouched' ),
			'#53a symlink cont-cmt-ws'         => array( 'htaccess:symlink:cont-cmt-ws', 'accept', '', 'untouched' ),
			'#54 own cont-dbl-blank'           => array( 'htaccess:own:cont-dbl-blank', 'write', '', 'deny' ),
			'#54a symlink cont-dbl-blank'      => array( 'htaccess:symlink:cont-dbl-blank', 'refuse', 'is a symlink', 'untouched' ),
			'#55 own cont-allow-dbl'           => array( 'htaccess:own:cont-allow-dbl', 'write', '', 'deny' ),
			'#40 own cont-eof'                 => array( 'htaccess:own:cont-eof', 'accept', '', 'untouched' ),
			'#41 own cont-eof-bare'            => array( 'htaccess:own:cont-eof-bare', 'write', '', 'deny' ),
			'#42 own cont-severed'             => array( 'htaccess:own:cont-severed', 'write', '', 'deny' ),
			'#42a symlink cont-severed'        => array( 'htaccess:symlink:cont-severed', 'refuse', 'is a symlink', 'untouched' ),
			'#43 own quoted-dq'                => array( 'htaccess:own:quoted-dq', 'accept', '', 'untouched' ),
			'#43a symlink quoted-dq'           => array( 'htaccess:symlink:quoted-dq', 'accept', '', 'untouched' ),
			'#44 own quoted-sq'                => array( 'htaccess:own:quoted-sq', 'accept', '', 'untouched' ),
			'#45 own quoted-require'           => array( 'htaccess:own:quoted-require', 'accept', '', 'untouched' ),
			'#45a symlink quoted-require'      => array( 'htaccess:symlink:quoted-require', 'accept', '', 'untouched' ),
			'#46 own quoted-name'              => array( 'htaccess:own:quoted-name', 'accept', '', 'untouched' ),
			'#47 own quoted-partial'           => array( 'htaccess:own:quoted-partial', 'write', '', 'deny' ),
			'#47a symlink quoted-partial'      => array( 'htaccess:symlink:quoted-partial', 'refuse', 'is a symlink', 'untouched' ),
			'#48 own quoted-hash'              => array( 'htaccess:own:quoted-hash', 'write', '', 'deny' ),
			'#48a symlink quoted-hash'         => array( 'htaccess:symlink:quoted-hash', 'refuse', 'is a symlink', 'untouched' ),
			'#49 own quoted-glued'             => array( 'htaccess:own:quoted-glued', 'write', '', 'deny' ),
			'#50 own crlf-cap'                 => array( 'htaccess:own:crlf-cap', 'accept', '', 'untouched' ),
			'#50a symlink crlf-cap'            => array( 'htaccess:symlink:crlf-cap', 'accept', '', 'untouched' ),
			'#51 own lf-cap'                   => array( 'htaccess:own:lf-cap', 'accept', '', 'untouched' ),
		);
	}

	/**
	 * A month-long lockout has to say which shape refused, not just that one did.
	 *
	 * @return void
	 */
	public function test_probe_capability_reason_names_the_refusing_shape() {
		$parent = $this->dir . '/mainwp/test-restore';
		$this->assertTrue( wp_mkdir_p( $parent ) );
		$this->assertSame( 14, file_put_contents( $this->dir . '/operator-file', 'allow from all' ) );
		$this->assertTrue( symlink( $this->dir . '/operator-file', $parent . '/.htaccess' ) );

		$upload_dir = function ( $upload ) {
			$upload['basedir'] = $this->dir;
			return $upload;
		};
		add_filter( 'upload_dir', $upload_dir );
		try {
			$probe = MainWP_Backup_TestRestore_Sandbox::probe_capability();
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
			unlink( $parent . '/.htaccess' );
		}

		$this->assertFalse( $probe['capable'] );
		$this->assertStringContainsString( 'The test-restore sandbox could not protect its working directory.', $probe['reason'] );
		$this->assertStringContainsString( $parent . '/.htaccess is a symlink', $probe['reason'] );
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
	 * Dump syntax must not hide a statement that can reach outside the scratch database.
	 *
	 * @return void
	 */
	public function test_sql_statement_guard_matrix() {
		$scratch = 'mainwp_tr_matrix';
		$cases   = array(
			'plain statements'     => array( 'CREATE TABLE wp_posts (id int); INSERT INTO wp_posts VALUES (1);', array( 'CREATE TABLE wp_posts (id int)', 'INSERT INTO wp_posts VALUES (1)' ), '' ),
			'single quoted value'  => array( "INSERT INTO wp_posts VALUES ('a;b');", array( "INSERT INTO wp_posts VALUES ('a;b')" ), '' ),
			'double quoted value'  => array( 'INSERT INTO wp_posts VALUES ("a;b");', array( 'INSERT INTO wp_posts VALUES ("a;b")' ), '' ),
			'backtick identifier'  => array( 'CREATE TABLE `a;b` (id int);', array( 'CREATE TABLE `a;b` (id int)' ), '' ),
			'escaped quote'        => array( "INSERT INTO wp_posts VALUES ('a\\';b');", array( "INSERT INTO wp_posts VALUES ('a\\';b')" ), '' ),
			'doubled quote'        => array( "INSERT INTO wp_posts VALUES ('a'';b');", array( "INSERT INTO wp_posts VALUES ('a'';b')" ), '' ),
			'escaped double quote' => array( 'INSERT INTO wp_posts VALUES ("a\\";b");', array( 'INSERT INTO wp_posts VALUES ("a\\";b")' ), '' ),
			'doubled double quote' => array( 'INSERT INTO wp_posts VALUES ("a"";b");', array( 'INSERT INTO wp_posts VALUES ("a"";b")' ), '' ),
			'doubled backtick'     => array( 'CREATE TABLE `a``;b` (id int);', array( 'CREATE TABLE `a``;b` (id int)' ), '' ),
			'dash comment'         => array( "-- ignored ; USE other\nSELECT 1;", array( "-- ignored ; USE other\nSELECT 1" ), 'SELECT' ),
			'hash comment'         => array( "# ignored ; USE other\nSELECT 1;", array( "# ignored ; USE other\nSELECT 1" ), 'SELECT' ),
			'block comment'        => array( '/* ignored ; USE other */ SELECT 1;', array( '/* ignored ; USE other */ SELECT 1' ), 'SELECT' ),
			'version conditional'  => array( '/*!40101 SET x=1 */;', array( '/*!40101 SET x=1 */' ), 'SET' ),
			'version value'        => array( "/*!40101 SET x='a;b' */; SELECT 1;", array( "/*!40101 SET x='a;b' */", 'SELECT 1' ), 'SET' ),
			'use database'         => array( 'USE `other`;', array( 'USE `other`' ), 'USE' ),
			'lowercase use'        => array( 'use other;', array( 'use other' ), 'USE' ),
			'comment before use'   => array( '/* prefix */ USE other;', array( '/* prefix */ USE other' ), 'USE' ),
			'conditional use'      => array( '/*!40101 USE other */;', array( '/*!40101 USE other */' ), 'USE' ),
			'split keyword'        => array( 'DROP /* reason */ DATABASE other;', array( 'DROP /* reason */ DATABASE other' ), 'DROP' ),
			'foreign schema'       => array( 'INSERT INTO otherdb.wp_users VALUES (1);', array( 'INSERT INTO otherdb.wp_users VALUES (1)' ), 'INSERT cross-schema' ),
			'quoted foreign'       => array( 'INSERT INTO `otherdb`.`wp_users` VALUES (1);', array( 'INSERT INTO `otherdb`.`wp_users` VALUES (1)' ), 'INSERT cross-schema' ),
			'quoted scratch'       => array( 'INSERT INTO `mainwp_tr_matrix`.`wp_posts` VALUES (1);', array( 'INSERT INTO `mainwp_tr_matrix`.`wp_posts` VALUES (1)' ), '' ),
			'unquoted scratch'     => array( 'INSERT INTO mainwp_tr_matrix.wp_posts VALUES (1);', array( 'INSERT INTO mainwp_tr_matrix.wp_posts VALUES (1)' ), '' ),
			'unqualified table'    => array( 'INSERT INTO `wp_posts` VALUES (1);', array( 'INSERT INTO `wp_posts` VALUES (1)' ), '' ),
			'literal use'          => array( "INSERT INTO wp_posts VALUES ('USE other; INSERT INTO otherdb.wp_users');", array( "INSERT INTO wp_posts VALUES ('USE other; INSERT INTO otherdb.wp_users')" ), '' ),
			'trailing semicolons'  => array( 'SELECT 1;;;', array( 'SELECT 1' ), 'SELECT' ),
			'comments only'        => array( '-- comment;', array(), '' ),
			'foreign from'         => array( 'DELETE FROM otherdb.wp_posts;', array( 'DELETE FROM otherdb.wp_posts' ), 'DELETE' ),
			'foreign update'       => array( 'UPDATE otherdb.wp_posts SET id=1;', array( 'UPDATE otherdb.wp_posts SET id=1' ), 'UPDATE' ),
			'foreign table'        => array( 'DROP TABLE otherdb.wp_posts;', array( 'DROP TABLE otherdb.wp_posts' ), 'DROP cross-schema' ),
			'foreign join'         => array( 'SELECT * FROM wp_posts JOIN otherdb.wp_users;', array( 'SELECT * FROM wp_posts JOIN otherdb.wp_users' ), 'SELECT' ),
		);
		foreach ( array( 'CREATE DATABASE', 'CREATE SCHEMA', 'DROP DATABASE', 'DROP SCHEMA', 'ALTER DATABASE', 'ALTER SCHEMA', 'GRANT', 'REVOKE', 'CREATE USER', 'DROP USER', 'SET PASSWORD', 'LOAD DATA', 'LOAD XML', 'CREATE TRIGGER', 'CREATE PROCEDURE', 'CREATE FUNCTION', 'CREATE EVENT' ) as $keyword ) {
			$cases[ $keyword ] = array( $keyword . ' private_site_data;', array( $keyword . ' private_site_data' ), strtok( $keyword, ' ' ) );
		}
		foreach ( array( 'OUTFILE', 'DUMPFILE' ) as $target ) {
			$sql              = "SELECT 'private_site_data' INTO " . $target . " '/tmp/dump'";
			$cases[ $target ] = array( $sql . ';', array( $sql ), 'SELECT' );
		}

		$refused_sql = array(
			"PREPARE p FROM 'DROP TABLE `other`.`wp_users`'" => 'PREPARE',
			'EXECUTE p'                                    => 'EXECUTE',
			'DEALLOCATE PREPARE p'                         => 'DEALLOCATE',
			'CALL p()'                                     => 'CALL',
			"SET SESSION sql_mode='ANSI_QUOTES'"           => 'SET',
			"SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'"  => 'SET',
			'SET @x = 1'                                   => 'SET',
			'SET NAMES utf8mb4'                            => 'SET',
			'/*!40101 SET NAMES utf8mb4 */'                => 'SET',
			'SET CHARACTER SET utf8mb4'                    => 'SET',
			'SET CHARACTER SET sjis'                       => 'SET',
			"CREATE TABLE `t` (id INT) DATA DIRECTORY='/var/www/html/wp-content/uploads'" => 'DATA DIRECTORY',
			"CREATE TABLE `t` (id INT) INDEX DIRECTORY='/var/www/html'" => 'INDEX DIRECTORY',
			"CREATE TABLE `t` (id INT) ENGINE=FEDERATED CONNECTION='mysql://u:p@host/db/tbl'" => 'CONNECTION',
			"INSERT INTO `t` VALUES (LOAD_FILE('/etc/passwd'))" => 'LOAD_FILE',
			'DROP TABLE IF EXISTS `other`.`t`'             => 'DROP cross-schema',
			'CREATE TABLE IF NOT EXISTS `other`.`t` (id INT)' => 'CREATE cross-schema',
			'RENAME TABLE t TO other.t'                    => 'RENAME',
			'CREATE TABLE a LIKE other.t'                  => 'CREATE cross-schema',
			'TRUNCATE other.t'                             => 'TRUNCATE',
			'TRUNCATE TABLE other.t'                       => 'TRUNCATE',
			'CREATE VIEW other.v AS SELECT 1'              => 'CREATE',
			"ALTER USER CURRENT_USER() IDENTIFIED BY 'p'"  => 'ALTER',
			'RENAME USER a TO b'                           => 'RENAME',
			'DELETE FROM other.t'                          => 'DELETE',
			'SELECT 1'                                     => 'SELECT',
			"WITH x AS (SELECT 1 AS a) SELECT a INTO OUTFILE '/tmp/x' FROM x" => 'WITH',
			'DROP TABLE mainwp_tr_matrix.a, other.b'       => 'DROP cross-schema',
			'UPDATE IGNORE other.t SET a=1'                => 'UPDATE',
			'INSERT other.t VALUES (1)'                    => 'INSERT cross-schema',
			'REPLACE other.t VALUES (1)'                   => 'REPLACE cross-schema',
			'INSERT INTO t (a) SELECT a FROM other.u'      => 'INSERT cross-schema',
			'/*!40000 DROP TABLE IF EXISTS `other`.`x` */' => 'DROP cross-schema',
			'INSERT LOW_PRIORITY IGNORE INTO other.t VALUES (1)' => 'INSERT cross-schema',
			'LOCK TABLES wp_posts WRITE, other.t WRITE'    => 'LOCK cross-schema',
			'ALTER TABLE wp_posts ADD FOREIGN KEY (id) REFERENCES other.t (id)' => 'ALTER cross-schema',
			'INSERT INTO `mainwp_tr_matrix```.`t` VALUES (1)' => 'INSERT cross-schema',
			'INSERT INTO `MAINWP_TR_MATRIX`.`t` VALUES (1)' => 'INSERT cross-schema',
			'INSERT INTO `other`.`123` VALUES (1)'         => 'INSERT cross-schema',
			'INSERT INTO `123`.`t` VALUES (1)'             => 'INSERT cross-schema',
			"INSERT INTO t SELECT 1 INTO OUTFILE '/tmp/x'" => 'SELECT INTO OUTFILE',
			"INSERT INTO t SELECT 1 INTO DUMPFILE '/tmp/x'" => 'SELECT INTO DUMPFILE',
		);
		foreach ( $refused_sql as $sql => $refusal ) {
			$cases[ $sql ] = array( $sql . ';', array( $sql ), $refusal );
		}
		$accepted_sql = array(
			'DROP TABLE IF EXISTS `wp_posts`',
			'DROP TABLE IF EXISTS mainwp_tr_matrix.t',
			'DROP TEMPORARY TABLE IF EXISTS `mainwp_tr_matrix`.`t`',
			'CREATE TEMPORARY TABLE IF NOT EXISTS `wp_posts` (id INT)',
			'CREATE TABLE `wp_posts` (id INT, price DECIMAL(10,2) DEFAULT 0.00)',
			'CREATE TABLE `t` (data INT)',
			'CREATE TABLE `t` (connection INT)',
			"INSERT INTO `t` VALUES ('LOAD_FILE(1)')",
			"INSERT INTO `wp_posts` (`a`) VALUES ('x.y')",
			"INSERT INTO wp_posts VALUES ('USE other; DROP TABLE x')",
			'INSERT wp_posts VALUES (1)',
			'INSERT IGNORE INTO wp_posts VALUES (1)',
			'INSERT LOW_PRIORITY IGNORE wp_posts VALUES (1)',
			'INSERT DELAYED INTO wp_posts VALUES (1)',
			'INSERT HIGH_PRIORITY INTO wp_posts VALUES (1)',
			'REPLACE wp_posts VALUES (1)',
			'REPLACE LOW_PRIORITY INTO wp_posts VALUES (1)',
			'REPLACE DELAYED wp_posts VALUES (1)',
			'LOCK TABLES `wp_posts` WRITE',
			'UNLOCK TABLES',
			'ALTER TABLE `wp_posts` DISABLE KEYS',
			'START TRANSACTION',
			'BEGIN',
			'COMMIT',
			"-- ignored ; USE other\nINSERT INTO wp_posts VALUES (1)",
			"# ignored ; USE other\nINSERT INTO wp_posts VALUES (1)",
			'/* ignored ; USE other */ INSERT INTO wp_posts VALUES (1)',
		);
		foreach ( $accepted_sql as $sql ) {
			$cases[ $sql ] = array( $sql . ';', array( $sql ), '' );
		}
		$cases['allowed trailing semicolons'] = array( 'INSERT INTO wp_posts VALUES (1);;;', array( 'INSERT INTO wp_posts VALUES (1)' ), '' );
		$cases['dash without whitespace']     = array( 'INSERT INTO wp_posts VALUES (1--2); USE other;', array( 'INSERT INTO wp_posts VALUES (1--2)', 'USE other' ), 'USE' );
		$cases['unterminated block comment']  = array( 'INSERT INTO wp_posts VALUES (1); /* USE other;', array( 'INSERT INTO wp_posts VALUES (1)' ), '' );

		$split = new \ReflectionMethod( MainWP_Backup_TestRestore_Sandbox::class, 'split_sql_dump' );
		$guard = new \ReflectionMethod( MainWP_Backup_TestRestore_Sandbox::class, 'guard_sql_statement' );
		$split->setAccessible( true );
		$guard->setAccessible( true );
		foreach ( $cases as $label => $case ) {
			list( $sql, $expected, $refusal ) = $case;
			$statements                       = iterator_to_array( $split->invoke( null, $sql ), false );
			$this->assertSame( $expected, $statements, $label );
			$caught = null;
			try {
				foreach ( $statements as $statement ) {
					$guard->invoke( null, $statement, $scratch );
				}
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}
			if ( '' === $refusal ) {
				$this->assertNull( $caught, $label );
			} else {
				$this->assertInstanceOf( MainWP_Exception::class, $caught, $label );
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code(), $label );
				$this->assertSame( $refusal, $caught->get_message_extra( false ), $label );
				$this->assertSame( 'The backup database dump contains a statement the test restore will not run: ' . $refusal . '.', $caught->getMessage(), $label );
			}
		}
	}

	/**
	 * Dump readers join all SQL members and size guards count their stored bytes.
	 *
	 * @dataProvider sql_dump_archive_cases
	 *
	 * @param string      $format   Archive format.
	 * @param array       $members  Member names and contents in insertion order.
	 * @param string|null $expected Expected SQL, or null for an empty dump.
	 * @param int         $bytes    Total uncompressed dump member bytes.
	 *
	 * @return void
	 */
	public function test_sql_dump_archive_matrix( $format, $members, $expected, $bytes ) {
		$path = $this->dir . '/dbBackup-123.sql_.' . $format;
		if ( 'zip' === $format ) {
			$archive = new \ZipArchive();
			$this->assertTrue( $archive->open( $path, \ZipArchive::CREATE ) );
			foreach ( $members as $name => $body ) {
				$this->assertTrue( $archive->addFromString( $name, $body ) );
			}
			$this->assertTrue( $archive->close() );
		} else {
			$archive = new \PharData( $path );
			foreach ( $members as $name => $body ) {
				$archive->addFromString( $name, $body );
			}
			unset( $archive );
		}

		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$method  = new \ReflectionMethod( $sandbox, 'read_sql_dump' );
		$method->setAccessible( true );
		try {
			$this->assertSame( $bytes, $sandbox->decompressed_sql_dump_bytes( $path, 1048576 ), 'Count every matching member, including guard bytes, and exclude unrelated files.' );
			if ( null === $expected ) {
				$caught = null;
				try {
					$method->invoke( $sandbox, $path );
				} catch ( MainWP_Exception $e ) {
					$caught = $e;
				}
				$this->assertInstanceOf( MainWP_Exception::class, $caught );
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
				$this->assertSame( 'The backup archive did not contain a database dump.', $caught->getMessage() );
				$this->assertSame( 'no .sql in ' . basename( $path ), $caught->get_message_extra( false ) );
			} else {
				$this->assertSame( $expected, $method->invoke( $sandbox, $path ), 'Preserve member order and SQL whitespace, remove each guard, and separate statements.' );
			}
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * The child packages a completion marker alongside guarded per-table files.
	 *
	 * @return array
	 */
	public static function sql_dump_archive_cases() {
		$guard    = '<?php exit(); ?>';
		$first    = "\n\nCREATE TABLE restored (id int);";
		$next     = 'INSERT INTO restored VALUES (1);';
		$split    = array(
			'dbBackup-123.sql'                 => '',
			'dbBackup-123-a_table.sql.php'     => $guard . $first,
			'dbBackup-123-z_table.sql.php'     => $guard . $next,
			'dbBackup-123-ignored.sql.php.tmp' => 'unfinished dump',
			'notes.txt'                        => 'not SQL',
		);
		$bytes    = 2 * strlen( $guard ) + strlen( $first ) + strlen( $next );
		$plain    = array( 'dbBackup-123.sql' => $next );
		$empty    = array( 'dbBackup-123.sql' => '' );
		$multiple = array(
			'first.sql' => $first,
			'next.sql'  => $next,
		);
		$reverse  = array(
			'dbBackup-123.sql'             => '',
			'dbBackup-123-z_table.sql.php' => $guard . $first,
			'dbBackup-123-a_table.sql.php' => $guard . $next,
		);
		return array(
			'split zip and summed size'  => array( 'zip', $split, $first . "\n" . $next, $bytes ),
			'split tar and summed size'  => array( 'tar', $split, $first . "\n" . $next, $bytes ),
			'zip index order'            => array( 'zip', $reverse, $first . "\n" . $next, $bytes ),
			'plain zip unchanged'        => array( 'zip', $plain, $next, strlen( $next ) ),
			'plain tar unchanged'        => array( 'tar', $plain, $next, strlen( $next ) ),
			'empty zip marker'           => array( 'zip', $empty, null, 0 ),
			'empty tar marker'           => array( 'tar', $empty, null, 0 ),
			'guard following newlines'   => array( 'zip', array( 'dbBackup-123-a.sql.php' => $guard . "\r\n\n" . $next ), "\r\n\n" . $next, strlen( $guard . "\r\n\n" . $next ) ),
			'guard only has no SQL'      => array( 'zip', array( 'dbBackup-123-a.sql.php' => $guard ), null, strlen( $guard ) ),
			'multiple plain SQL members' => array( 'zip', $multiple, $first . "\n" . $next, strlen( $first . $next ) ),
		);
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
