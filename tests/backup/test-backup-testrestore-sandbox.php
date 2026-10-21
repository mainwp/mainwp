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

namespace MainWP\Dashboard\Tests; // phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- The production-namespace filesystem stub belongs with its tests.

require_once __DIR__ . '/trait-backup-private-root-fixture.php'; // phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- the filesystem stub must share the production namespace.

use MainWP\Dashboard\MainWP_Backup_TestRestore_Sandbox;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;

/**
 * Class MainWP_Backup_TestRestore_Sandbox_Test
 */
class MainWP_Backup_TestRestore_Sandbox_Test extends \WP_UnitTestCase { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- the switchable stub belongs with its regression tests.

	use Backup_Cancel_Transport_Guard;

	use Backup_Private_Root_Fixture;

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
		$this->set_up_private_root();
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
		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * Sandbox cleanup removes a symlink found in the extracted tree without following it,
	 * so a link in a restored archive cannot reach files outside the sandbox.
	 */
	public function test_remove_dir_unlinks_symlinks_without_following_them() {
		$root    = trailingslashit( get_temp_dir() ) . 'mainwp-sbx-link-' . wp_generate_password( 8, false );
		$outside = trailingslashit( get_temp_dir() ) . 'mainwp-sbx-outside-' . wp_generate_password( 8, false );
		$this->assertTrue( wp_mkdir_p( $root . '/nested' ) );
		$this->assertTrue( wp_mkdir_p( $outside ) );
		$this->assertSame( 4, file_put_contents( $outside . '/keep.txt', 'keep' ) );
		$this->assertTrue( symlink( $outside, $root . '/nested/link' ) );
		$this->assertTrue( symlink( $outside, $root . '-rootlink' ) );

		$sandbox = ( new \ReflectionClass( MainWP_Backup_TestRestore_Sandbox::class ) )->newInstanceWithoutConstructor();
		$method  = new \ReflectionMethod( $sandbox, 'remove_dir' );
		$method->setAccessible( true );
		try {
			$method->invoke( $sandbox, trailingslashit( $root ) );
			$method->invoke( $sandbox, trailingslashit( $root . '-rootlink' ) );

			$this->assertFileExists( $outside . '/keep.txt', 'A link inside the tree must not be followed.' );
			$this->assertFalse( is_link( $root . '/nested/link' ) );
			$this->assertDirectoryDoesNotExist( $root );
			$this->assertFalse( is_link( $root . '-rootlink' ), 'A linked root is unlinked, not walked.' );
		} finally {
			foreach ( array( $root . '/nested/link', $root . '-rootlink' ) as $link ) {
				if ( is_link( $link ) ) {
					unlink( $link );
				}
			}
			if ( file_exists( $outside . '/keep.txt' ) ) {
				unlink( $outside . '/keep.txt' );
			}
			if ( is_dir( $outside ) ) {
				rmdir( $outside );
			}
		}
	}

	/**
	 * Recursive cleanup.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	private function rrmdir( $dir ) {
		if ( is_link( $dir ) ) {
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
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
	 * Raw headers preserve the old writer's trailing prefix slash.
	 *
	 * @param string $name   Header name.
	 * @param string $body   Member bytes.
	 * @param string $prefix Ustar prefix.
	 * @param string $type   Tar member type.
	 * @param string $target Link target.
	 * @return string Header and padded payload.
	 */
	private function raw_tar_member( $name, $body = '', $prefix = '', $type = '0', $target = '' ) {
		$header = str_repeat( "\0", 512 );
		foreach ( array(
			0   => str_pad( $name, 100, "\0" ),
			100 => "0000644\0",
			108 => "0000000\0",
			116 => "0000000\0",
			124 => sprintf( "%011o\0", strlen( $body ) ),
			136 => "00000000000\0",
			148 => '        ',
			156 => $type,
			157 => str_pad( $target, 100, "\0" ),
			257 => "ustar\0",
			263 => '00',
			345 => str_pad( $prefix, 155, "\0" ),
		) as $offset => $value ) {
			$header = substr_replace( $header, $value, $offset, strlen( $value ) );
		}
		$header = substr_replace( $header, sprintf( "%06o\0 ", array_sum( unpack( 'C*', $header ) ) ), 148, 8 );
		return $header . str_pad( $body, (int) ceil( strlen( $body ) / 512 ) * 512, "\0" );
	}

	/**
	 * Recompute the checksum so a numeric fault reaches the size parser.
	 *
	 * @param string $bytes  Header and payload.
	 * @param int    $offset Field offset.
	 * @param string $value  Replacement bytes.
	 * @return string Patched tar member.
	 */
	private function patch_raw_tar_header( $bytes, $offset, $value ) {
		$bytes = substr_replace( $bytes, $value, $offset, strlen( $value ) );
		$bytes = substr_replace( $bytes, '        ', 148, 8 );
		return substr_replace( $bytes, sprintf( "%06o\0 ", array_sum( unpack( 'C*', substr( $bytes, 0, 512 ) ) ) ), 148, 8 );
	}

	/**
	 * Each fixture gets its own path because PharData caches archive manifests.
	 *
	 * @param string $bytes Raw tar members.
	 * @param bool   $gzip  Whether to compress the fixture.
	 * @return string Archive path.
	 */
	private function raw_tar( $bytes, $gzip = false ) {
		$path   = $this->dir . '/' . uniqid( 'headers-' ) . '.tar' . ( $gzip ? '.gz' : '' );
		$bytes .= str_repeat( "\0", 1024 );
		$bytes  = $gzip ? gzencode( $bytes ) : $bytes;
		$this->assertSame( strlen( $bytes ), file_put_contents( $path, $bytes ) );
		return $path;
	}

	/**
	 * All three tar consumers must reject hidden unsafe names before any member is used.
	 *
	 * @dataProvider hidden_unsafe_tar_cases
	 * @param string $prefix Unsafe prefix.
	 * @param string $type   Member type.
	 * @param string $action Tar consumer.
	 * @return void
	 */
	public function test_tar_headers_refuse_hidden_unsafe_names( $prefix, $type, $action ) {
		if ( '/absolute/' === $prefix ) {
			$prefix = $this->dir . '/absolute/';
		}
		$name    = 'escape-' . uniqid() . '.txt';
		$path    = $this->raw_tar( $this->raw_tar_member( 'good.sql', 'SELECT 1;' ) . $this->raw_tar_member( $name, '0' === $type ? 'hostile' : '', $prefix, $type, 'good.sql' ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		$outside = dirname( untrailingslashit( $sandbox->temp_dir() ) ) . '/' . $name;
		try {
			$caught = $this->tar_refusal( $sandbox, $path, $action );
			$this->assertFileDoesNotExist( $outside );
			$this->assertFileDoesNotExist( $this->dir . '/absolute/' . $name );
			$this->assertInstanceOf( MainWP_Exception::class, $caught, 'Iterator validation passed; extracted files: ' . implode( ', ', scandir( $sandbox->temp_dir() ) ) );
			$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
			$this->assertStringContainsString( 'contains an unsafe file path', $caught->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
		} finally {
			$sandbox->tear_down();
			if ( file_exists( $outside ) ) {
				unlink( $outside );
			}
		}
	}

	/**
	 * The same omission affects file, directory and link names as well as SQL consumers.
	 *
	 * @return array
	 */
	public static function hidden_unsafe_tar_cases() {
		$cases = array();
		foreach ( array(
			'parent'   => '../',
			'segment'  => 'nested/../',
			'absolute' => '/absolute/',
			'wrapper'  => 'phar://other/',
			'drive'    => 'C:/outside/',
		) as $label => $prefix ) {
			$cases[ $label ] = array( $prefix, '0', 'extract' );
		}
		$cases['directory'] = array( '../', '5', 'extract' );
		$cases['SQL read']  = array( '../', '0', 'read' );
		$cases['SQL size']  = array( '../', '0', 'size' );
		return $cases;
	}

	/**
	 * Keep the refusal assertions identical across the extraction and SQL entry points.
	 *
	 * @param object $sandbox Sandbox.
	 * @param string $path    Archive.
	 * @param string $action  Tar consumer.
	 * @return MainWP_Exception|null Refusal, if any.
	 */
	private function tar_refusal( $sandbox, $path, $action ) {
		try {
			if ( 'extract' === $action ) {
				$sandbox->extract_files( $path );
			} elseif ( 'size' === $action ) {
				$sandbox->decompressed_sql_dump_bytes( $path, 1048576 );
			} else {
				$read = new \ReflectionMethod( $sandbox, 'read_sql_dump' );
				$read->setAccessible( true );
				$read->invoke( $sandbox, $path );
			}
		} catch ( MainWP_Exception $e ) {
			return $e;
		}
		return null;
	}

	/**
	 * Refusing an incomplete manifest prevents both skipped SQL and unvalidated extraction.
	 *
	 * @dataProvider hidden_long_tar_cases
	 * @param string $action Tar consumer.
	 * @param bool   $gzip   Compressed fixture.
	 * @param string $type   Member type.
	 * @return void
	 */
	public function test_tar_headers_refuse_beta_long_paths( $action, $gzip, $type ) {
		$prefix  = 'nested/' . str_repeat( 'p', 90 ) . '/';
		$name    = '<dump>.sql';
		$path    = $this->raw_tar( $this->raw_tar_member( $name, '0' === $type ? 'SELECT 2;' : '', $prefix, $type ) . $this->raw_tar_member( 'good.txt', 'good' ), $gzip );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, $action );
			$this->assertInstanceOf( MainWP_Exception::class, $caught, 'Extraction succeeded; long file exists: ' . (int) file_exists( $sandbox->temp_dir() . $prefix . $name ) );
			$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
			$this->assertStringContainsString( 'earlier beta', $caught->getMessage() );
			$this->assertStringContainsString( 'one long path', $caught->getMessage() );
			$this->assertStringContainsString( 'cannot be test-restored', $caught->getMessage() );
			$this->assertStringContainsString( esc_html( $prefix . '/' . $name ), $caught->getMessage() );
			$this->assertStringNotContainsString( '<dump>', $caught->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Include decompression and directory-only manifests.
	 *
	 * @return array
	 */
	public static function hidden_long_tar_cases() {
		return array(
			'file'      => array( 'extract', false, '0' ),
			'directory' => array( 'extract', false, '5' ),
			'SQL read'  => array( 'read', false, '0' ),
			'SQL size'  => array( 'size', false, '0' ),
			'gzip'      => array( 'extract', true, '0' ),
		);
	}

	/**
	 * PharData must not extract headers hidden inside unsupported member payloads.
	 *
	 * @dataProvider unsupported_tar_types
	 * @param string $type Member type.
	 * @param bool   $payload Whether to embed another header.
	 */
	public function test_tar_headers_refuse_unsupported_types( $type, $payload ) {
		$body = $payload ? $this->raw_tar_member( '../smuggled.txt', 'hostile' ) : '';
		$name = $payload ? 'k3' : ( 'K' === $type ? '../k0.txt' : 'device.txt' );
		$path = $this->raw_tar( $this->raw_tar_member( $name, $body, '', $type ) . $this->raw_tar_member( 'safe.txt', 'safe' ) );
		$this->assert_tar_name_refusal( $path, 'could not be read' );
	}

	/**
	 * Unsupported tar encodings.
	 *
	 * @return array
	 */
	public static function unsupported_tar_types() {
		return array(
			'K payload' => array( 'K', true ),
			'empty K'   => array( 'K', false ),
			'device'    => array( '3', false ),
		);
	}

	/**
	 * Pax records are refused outright, so they cannot hide the name PharData actually extracts.
	 *
	 * @dataProvider conflicting_tar_names
	 * @param string $shape Metadata and member combination.
	 * @param string $message Expected refusal.
	 */
	public function test_tar_headers_refuse_conflicting_pax_names( $shape, $message ) {
		$pax  = 'path=' . ( 'different' === $shape ? 'sub/safe.txt' : 'safe.txt' ) . "\n";
		$size = strlen( $pax ) + 3;
		$size = strlen( $pax ) + strlen( (string) $size ) + 1;
		$meta = $this->raw_tar_member( 'PaxHeader', $size . ' ' . $pax, '', 'x' );
		if ( 'long' === $shape ) {
			$bytes = $this->raw_tar_member( '././@LongLink', '../lx-escape.txt', '', 'L' ) . $meta . $this->raw_tar_member( 'safe.txt', 'hostile' );
		} elseif ( 'prefix' === $shape ) {
			$pax   = "17 path=good.txt\n";
			$bytes = $this->raw_tar_member( 'good.txt', 'good' ) . $this->raw_tar_member( 'PaxHeader', $pax, '', 'x' ) . $this->raw_tar_member( 'escape.txt', 'hostile', '..' );
		} else {
			$name  = 'different' === $shape ? 'wp-config.php' : '../evil-pax.txt';
			$bytes = $meta . $this->raw_tar_member( $name, 'hostile' ) . $this->raw_tar_member( 'different' === $shape ? 'sub/safe.txt' : 'safe.txt', 'safe' );
		}
		$this->assert_tar_name_refusal( $this->raw_tar( $bytes ), $message );
	}

	/**
	 * Header and metadata disagreements.
	 *
	 * @return array
	 */
	public static function conflicting_tar_names() {
		return array(
			'unsafe header' => array( 'unsafe', 'could not be read' ),
			'different'     => array( 'different', 'could not be read' ),
			'GNU then pax'  => array( 'long', 'could not be read' ),
			'prefix'        => array( 'prefix', 'could not be read' ),
		);
	}

	/**
	 * PharData joins the prefix field even without ustar magic, so a header without it is refused before any member is used.
	 *
	 * @dataProvider magicless_prefix_cases
	 * @param string $prefix Unsafe prefix.
	 * @param bool   $first  Whether the magic-less header comes first.
	 */
	public function test_tar_headers_refuse_unsafe_prefix_without_magic( $prefix, $first ) {
		if ( '/absolute' === $prefix ) {
			$prefix = $this->dir . '/absolute';
		}
		$hidden = $this->patch_raw_tar_header( $this->raw_tar_member( 'evil.txt', 'hostile', $prefix ), 257, str_repeat( "\0", 6 ) );
		$bytes  = ( $first ? $hidden : $this->raw_tar_member( 'first.txt', 'first' ) . $hidden ) . $this->raw_tar_member( 'evil.txt', 'plain' );
		$this->assert_tar_name_refusal( $this->raw_tar( $bytes ), 'could not be read' );
		$this->assertFileDoesNotExist( $this->dir . '/absolute/evil.txt' );
	}

	/**
	 * Magic-less prefix shapes.
	 *
	 * @return array
	 */
	public static function magicless_prefix_cases() {
		return array(
			'parent, later header'   => array( '..', false ),
			'parent, first header'   => array( '..', true ),
			'absolute, later header' => array( '/absolute', false ),
		);
	}

	/**
	 * Links and directories carry no payload for PharData, so a declared one must not hide headers.
	 *
	 * @dataProvider payload_bearing_entry_types
	 * @param string $type   Member type.
	 * @param string $action Tar consumer.
	 */
	public function test_tar_headers_refuse_link_and_directory_payloads( $type, $action ) {
		$body    = $this->raw_tar_member( '../outside.txt', 'hostile' );
		$bytes   = $this->raw_tar_member( 'first.sql', 'SELECT 1;' ) . $this->raw_tar_member( 'a.txt', $body, '', $type, 'first.sql' ) . $this->raw_tar_member( 'after.txt', 'after' );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $this->raw_tar( $bytes ), $action );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertStringContainsString( 'could not be read', $caught->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Payload-bearing link and directory headers across the tar consumers.
	 *
	 * @return array
	 */
	public static function payload_bearing_entry_types() {
		return array(
			'symlink extract'   => array( '2', 'extract' ),
			'symlink SQL read'  => array( '2', 'read' ),
			'symlink SQL size'  => array( '2', 'size' ),
			'hard link extract' => array( '1', 'extract' ),
			'directory extract' => array( '5', 'extract' ),
		);
	}

	/**
	 * Root headers are listing disagreements, not evidence of a beta writer.
	 *
	 * @dataProvider tar_root_names
	 * @param string $name Root header name.
	 */
	public function test_tar_headers_refuse_unlisted_roots_as_unreadable( $name ) {
		$this->assert_tar_name_refusal( $this->raw_tar( $this->raw_tar_member( $name, '', '', '5' ) . $this->raw_tar_member( 'safe.txt', 'safe' ) ), 'could not be read' );
	}

	/**
	 * Root spellings PharData omits.
	 *
	 * @return array
	 */
	public static function tar_root_names() {
		return array( array( './' ), array( '.' ), array( '' ) );
	}

	/**
	 * Refusal must precede any write into the sandbox.
	 *
	 * @param string $path Archive.
	 * @param string $message Expected refusal.
	 */
	private function assert_tar_name_refusal( $path, $message ) {
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'extract' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
			$this->assertStringContainsString( $message, $caught->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Current long-name metadata must continue to work through all tar entry points.
	 *
	 * @dataProvider supported_tar_name_cases
	 * @param string $type Long-name metadata format.
	 * @return void
	 */
	public function test_tar_headers_accept_current_long_names( $type ) {
		$name    = str_repeat( 'current/', 80 ) . 'restored.sql';
		$payload = $name;
		$sql     = 'SELECT 42;';
		$path    = $this->raw_tar( $this->raw_tar_member( 'nested/', '', '', '5' ) . $this->raw_tar_member( '././@LongLink', $payload, '', $type ) . $this->raw_tar_member( substr( $name, 0, 100 ), $sql ) . $this->raw_tar_member( 'good.txt', 'good' ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$sandbox->extract_files( $path );
			$this->assertSame( $sql, file_get_contents( $sandbox->temp_dir() . $name ) );
			$this->assertSame( 'good', file_get_contents( $sandbox->temp_dir() . 'good.txt' ) );
			$this->assertSame( strlen( $sql ), $sandbox->decompressed_sql_dump_bytes( $path, 1048576 ) );
			$read = new \ReflectionMethod( $sandbox, 'read_sql_dump' );
			$read->setAccessible( true );
			$this->assertSame( $sql, $read->invoke( $sandbox, $path ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * GNU long-name records as the Child writer emits them.
	 *
	 * @return array
	 */
	public static function supported_tar_name_cases() {
		return array(
			'GNU' => array( 'L' ),
		);
	}

	/**
	 * The walk accepts only header shapes PharData reads the same way; every other shape is refused
	 * before a member is used, through each tar consumer.
	 *
	 * @dataProvider tar_header_shape_matrix
	 * @param string $shape    Header shape.
	 * @param string $expected accept, unreadable or unsafe.
	 */
	public function test_tar_header_shape_matrix( $shape, $expected ) {
		$hidden  = $this->raw_tar_member( 'sub//evil.php', 'hostile' );
		$carrier = $this->raw_tar_member( 'carrier.txt', $hidden );
		$size    = array(
			'size base-256'       => "\x80" . str_repeat( "\0", 9 ) . "\x04\x00",
			'size leading NUL'    => "\0" . '00000002000',
			'size space then NUL' => " \0" . '0000002000',
			'size empty'          => str_repeat( "\0", 12 ),
			'size leading spaces' => '    0002000' . "\0",
			'size trailing space' => '00000002000 ',
		);
		$long    = function ( $name ) {
			return $this->raw_tar_member( '././@LongLink', $name, '', 'L' );
		};
		// Directories need the execute bit the Child writer records, or extraction cannot descend.
		$dir   = function ( $name ) {
			return $this->patch_raw_tar_header( $this->raw_tar_member( $name, '', '', '5' ), 100, "0000755\0" );
		};
		$names = self::tar_name_rows();
		if ( 'Windows' === PHP_OS_FAMILY && in_array( $shape, array( 'name dots inside a segment', 'name drive letter only' ), true ) ) {
			$this->markTestSkipped( 'Windows cannot create files named ... or C:notes.txt.' );
		}
		$pairs = array(
			'file then directory'           => $this->raw_tar_member( 'a', 'file' ) . $dir( 'a/' ),
			'directory then file'           => $dir( 'a/' ) . $this->raw_tar_member( 'a', 'file' ),
			'slashless directory then file' => $dir( 'a' ) . $this->raw_tar_member( 'a', 'file' ),
			'file then child'               => $this->raw_tar_member( 'a', 'file' ) . $this->raw_tar_member( 'a/b', 'child' ),
			'directory twice'               => $dir( 'a/' ) . $dir( 'a/' ),
			'file twice'                    => $this->raw_tar_member( 'a', 'first' ) . $this->raw_tar_member( 'a', 'second' ),
		);
		if ( isset( $size[ $shape ] ) ) {
			$bytes = $this->patch_raw_tar_header( $carrier, 124, $size[ $shape ] );
		} elseif ( isset( $names[ $shape ] ) ) {
			$bytes = implode( '', array_map( array( $this, 'raw_tar_member' ), $names[ $shape ][0], $names[ $shape ][0] ) );
		} elseif ( isset( $pairs[ $shape ] ) ) {
			$bytes = $pairs[ $shape ];
		} elseif ( 'Child shape' === $shape ) {
			$deep  = str_repeat( 'wp-content/plugins/', 7 ) . 'deep';
			$bytes = $dir( 'wp-content' ) . $long( $deep ) . $dir( substr( $deep, 0, 100 ) ) . $long( $deep . '/file.txt' ) . $this->raw_tar_member( substr( $deep . '/file.txt', 0, 100 ), 'deep' );
		} elseif ( 'two long names' === $shape ) {
			$bytes = $long( 'a.txt' ) . $long( 'b.txt' ) . $this->raw_tar_member( 'evil.txt', 'hostile', '..' ) . $this->raw_tar_member( 'a.txt', 'a' ) . $this->raw_tar_member( 'b.txt', 'b' );
		} elseif ( 'empty long name' === $shape ) {
			$bytes = $this->raw_tar_member( '././@LongLink', "\0", '', 'L' ) . $this->raw_tar_member( 'a.txt', 'a' );
		} elseif ( 'no magic' === $shape ) {
			$bytes = $this->patch_raw_tar_header( $this->raw_tar_member( 'a.txt', 'a' ), 257, str_repeat( "\0", 6 ) );
		} elseif ( 'long unsafe' === $shape ) {
			$bytes = $long( '../escape.txt' ) . $this->raw_tar_member( 'safe.txt', 'hostile' );
		} else {
			$bytes = $this->raw_tar_member( 'meta', $hidden, '', substr( $shape, 5 ), 'a.txt' );
		}
		$path = $this->raw_tar( $bytes . $this->raw_tar_member( 'safe.sql', 'SELECT 1;' ) );
		foreach ( array( 'extract', 'read', 'size' ) as $action ) {
			$sandbox = new MainWP_Backup_TestRestore_Sandbox();
			$sandbox->bring_up();
			try {
				$caught = $this->tar_refusal( $sandbox, $path, $action );
				if ( 'accept' === $expected ) {
					$this->assertNull( $caught, $action . ': ' . ( $caught ? $caught->getMessage() : '' ) );
					if ( 'extract' === $action && isset( $names[ $shape ] ) ) {
						foreach ( $names[ $shape ][0] as $name ) {
							$this->assertSame( $name, file_get_contents( $sandbox->temp_dir() . $name ) );
						}
					}
					continue;
				}
				$this->assertInstanceOf( MainWP_Exception::class, $caught, $action );
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
				$this->assertStringContainsString( 'unsafe' === $expected ? 'contains an unsafe file path' : 'could not be read', $caught->getMessage(), $action );
				$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
			} finally {
				$sandbox->tear_down();
			}
		}
	}

	/**
	 * Accepted and refused header shapes.
	 *
	 * @return array
	 */
	public static function tar_header_shape_matrix() {
		$rows = array(
			'Child shape'         => array( 'Child shape', 'accept' ),
			'size leading spaces' => array( 'size leading spaces', 'accept' ),
			'size trailing space' => array( 'size trailing space', 'accept' ),
			'size base-256'       => array( 'size base-256', 'unreadable' ),
			'size leading NUL'    => array( 'size leading NUL', 'unreadable' ),
			'size space then NUL' => array( 'size space then NUL', 'unreadable' ),
			'size empty'          => array( 'size empty', 'unreadable' ),
			'two long names'      => array( 'two long names', 'unreadable' ),
			'empty long name'     => array( 'empty long name', 'unreadable' ),
			'no magic'            => array( 'no magic', 'unreadable' ),
			'long unsafe'         => array( 'long unsafe', 'unsafe' ),
		);
		foreach ( array( '1', '2', '7', 'x', 'g', 'K', 'S' ) as $type ) {
			$rows[ 'type ' . $type ] = array( 'type ' . $type, 'unreadable' );
		}
		foreach ( self::tar_name_rows() as $shape => $row ) {
			$rows[ $shape ] = array( $shape, $row[1] );
		}
		// PharData keeps one entry per name, so a file and a directory sharing one lose a member.
		foreach ( array(
			'file then directory'           => 'unreadable',
			'directory then file'           => 'unreadable',
			'slashless directory then file' => 'unreadable',
			'file then child'               => 'unreadable',
			'directory twice'               => 'accept',
			'file twice'                    => 'accept',
		) as $shape => $expected ) {
			$rows[ $shape ] = array( $shape, $expected );
		}
		return $rows;
	}

	/**
	 * Member names the Child's validate_extract_path() accepts or refuses: a `..` segment under
	 * either separator, a leading separator with or without a drive letter, or a stream wrapper is
	 * unsafe; dots inside a segment and a drive letter with no separator are ordinary names.
	 *
	 * @return array<string, array{0: string[], 1: string}> Names per shape and the expected outcome.
	 */
	private static function tar_name_rows() {
		return array(
			'name dots inside a segment'  => array( array( 'jquery..min.js', 'a..b/c.txt', '...' ), 'accept' ),
			'name drive letter only'      => array( array( 'C:notes.txt' ), 'accept' ),
			'name bare dot-dot'           => array( array( '..' ), 'unsafe' ),
			'name trailing dot-dot'       => array( array( 'sub/..' ), 'unsafe' ),
			'name backslash dot-dot'      => array( array( 'sub\\..\\evil.txt' ), 'unsafe' ),
			'name leading backslash'      => array( array( '\\evil.txt' ), 'unsafe' ),
			'name drive and backslash'    => array( array( 'C:\\evil.txt' ), 'unsafe' ),
			'name drive and slash'        => array( array( 'C:/evil.txt' ), 'unsafe' ),
			'name stream wrapper'         => array( array( 'phar://other/a.tar' ), 'unsafe' ),
			'name dot-dot after a dotted' => array( array( 'a..b/../evil.txt' ), 'unsafe' ),
		);
	}

	/**
	 * A sandbox whose header walk runs at scaled limits and whose PharData listing carries extra
	 * directories, standing in for a listing the walk never saw. A given listing replaces PharData's,
	 * which drops long names of 1024 bytes or more and cannot read one that keeps a trailing NUL.
	 *
	 * @param int        $entries    Header count limit.
	 * @param int        $name_bytes Name budget.
	 * @param string[]   $extra      Directories appended to PharData's listing.
	 * @param array|null $listing    Listing pairs (path, is directory) used instead of PharData's.
	 * @return MainWP_Backup_TestRestore_Sandbox
	 */
	private function scaled_sandbox( $entries, $name_bytes, $extra = array(), $listing = null ) {
		return new class( $entries, $name_bytes, $extra, $listing ) extends MainWP_Backup_TestRestore_Sandbox {
			/**
			 * Scaled limits and extra listed directories.
			 *
			 * @var array
			 */
			private $scaled;

			/**
			 * Keep the scaled values.
			 *
			 * @param int        $entries    Header count limit.
			 * @param int        $name_bytes Name budget.
			 * @param string[]   $extra      Extra listed directories.
			 * @param array|null $listing    Replacement listing.
			 */
			public function __construct( $entries, $name_bytes, $extra, $listing ) {
				parent::__construct();
				$this->scaled = compact( 'entries', 'name_bytes', 'extra', 'listing' );
			}

			/**
			 * Scaled walk limits.
			 *
			 * @return array
			 */
			protected function tar_walk_limits(): array {
				return array(
					'entries'    => $this->scaled['entries'],
					'name_bytes' => $this->scaled['name_bytes'],
				);
			}

			/**
			 * PharData's listing plus the extra directories.
			 *
			 * @param \PharData $phar Archive.
			 * @return \Generator
			 */
			protected function tar_listing( \PharData $phar ): \Generator {
				if ( null === $this->scaled['listing'] ) {
					yield from parent::tar_listing( $phar );
				} else {
					yield from $this->scaled['listing'];
				}
				foreach ( $this->scaled['extra'] as $dir ) {
					yield array( $dir, true );
				}
			}
		};
	}

	/**
	 * Run the header walk and listing check alone; names here can be longer than a file system allows.
	 *
	 * @param MainWP_Backup_TestRestore_Sandbox $sandbox Sandbox.
	 * @param string                            $path    Plain tar.
	 * @return MainWP_Exception|null Refusal, if any.
	 */
	private function validated_tar_refusal( $sandbox, $path ) {
		$validate = new \ReflectionMethod( MainWP_Backup_TestRestore_Sandbox::class, 'validated_tar' );
		$validate->setAccessible( true );
		try {
			$validate->invoke( $sandbox, $path );
		} catch ( MainWP_Exception $e ) {
			return $e;
		}
		return null;
	}

	/**
	 * The name budget covers the entry limit at the longest name a ustar header holds without a
	 * long-name record (155-byte prefix, slash, 100-byte name), as on the Child.
	 */
	public function test_tar_name_budget_covers_the_entry_limit_at_the_longest_ustar_name() {
		$limits = new \ReflectionMethod( MainWP_Backup_TestRestore_Sandbox::class, 'tar_walk_limits' );
		$limits->setAccessible( true );
		$this->assertSame(
			array(
				'entries'    => 2000000,
				'name_bytes' => 2000000 * 256,
			),
			$limits->invoke( new MainWP_Backup_TestRestore_Sandbox() )
		);
	}

	/**
	 * The name budget charges every name PharData keeps (a long-name payload as declared, else the
	 * prefix and name up to NUL) and every parent directory a member implies without a header, before
	 * duplicates merge. Scaled rows run at 8 x 256 name bytes, the production ratio, with the listing
	 * PharData would give for the names the walk saw (as the Child's rows do), so the rows test the
	 * walk rather than PharData's own name limits.
	 *
	 * @dataProvider tar_name_budget_matrix
	 * @param array    $members  Members as array( kind, name ): file, dir, long (L then file), long-nul
	 *                           (L with a trailing NUL), raw-long (L payload as given), or cut-payloads.
	 * @param string   $expected accept, unreadable or limit.
	 * @param int|null $entries  Scaled header limit, or null for the production limits.
	 */
	public function test_tar_name_budget_matrix( $members, $expected, $entries ) {
		$path    = $this->dir . '/' . uniqid( 'budget-' ) . '.tar';
		$handle  = fopen( $path, 'wb' );
		$listing = array();
		foreach ( $members as $member ) {
			list( $kind, $name ) = $member;
			if ( 'cut-payloads' === $kind ) {
				// Distinct payloads that all cut to one name at the NUL still cost PharData their full size.
				for ( $i = 0; $i < 1025; ++$i ) {
					fwrite( $handle, $this->raw_tar_member( '././@LongLink', "a\0" . str_pad( (string) $i, 65534, 'z' ), '', 'L' ) . $this->raw_tar_member( 'placeholder', 'x' ) );
				}
			} elseif ( 'dir' === $kind ) {
				fwrite( $handle, $this->raw_tar_member( $name, '', '', '5' ) );
			} elseif ( 'file' === $kind ) {
				fwrite( $handle, $this->raw_tar_member( $name, 'x' ) );
			} else {
				$payload = 'long-nul' === $kind ? $name . "\0" : $name;
				fwrite( $handle, $this->raw_tar_member( '././@LongLink', $payload, '', 'L' ) . $this->raw_tar_member( substr( $name, 0, 100 ), 'x' ) );
				$name = explode( "\0", $name, 2 )[0];
			}
			$listing[ $name ] = 'dir' === $kind;
			// PharData lists the parents of every member itself.
			for ( $cut = strrpos( $name, '/' ); false !== $cut && $cut > 0; $cut = strrpos( $name, '/' ) ) {
				$name = substr( $name, 0, $cut );
				if ( ! isset( $listing[ $name ] ) ) {
					$listing[ $name ] = true;
				}
			}
		}
		fwrite( $handle, str_repeat( "\0", 1024 ) );
		fclose( $handle );
		$pairs = array();
		foreach ( $listing as $name => $dir ) {
			$pairs[] = array( (string) $name, $dir );
		}
		$sandbox = null === $entries ? new MainWP_Backup_TestRestore_Sandbox() : $this->scaled_sandbox( $entries, 8 * 256, array(), $pairs );
		$caught  = $this->validated_tar_refusal( $sandbox, $path );
		if ( 'accept' === $expected ) {
			$this->assertNull( $caught, $caught ? $caught->get_message_extra( false ) : '' );
			return;
		}
		$this->assertInstanceOf( MainWP_Exception::class, $caught );
		$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
		if ( 'limit' === $expected ) {
			$this->assertSame( 'Test restore name size limit exceeded while checking backup archive headers.', $caught->getMessage() );
			$this->assertSame( 'tar name size limit exceeded', $caught->get_message_extra( false ) );
		} else {
			$this->assertSame( 'A backup archive could not be read.', $caught->getMessage() );
		}
	}

	/**
	 * Name budget rows from the shared rule. The Dashboard counts every header against the entry
	 * limit, long-name records included, so rows with long names run with room for their headers.
	 *
	 * @return array
	 */
	public static function tar_name_budget_matrix() {
		$names   = function ( $length, $count, $last = null ) {
			$rows = array();
			for ( $i = 0; $i < $count; ++$i ) {
				$rows[] = array( 'long', str_repeat( chr( 97 + $i ), $i === $count - 1 && null !== $last ? $last : $length ) );
			}
			return $rows;
		};
		$average = array( array( 'dir', 'wp-content' ) );
		for ( $i = 0; $i < 7; ++$i ) {
			$average[] = array( 'file', str_pad( 'wp-content/' . $i . '-', 70, 'p' ) );
		}
		return array(
			// About a million members at 70 bytes drew the old 64 MiB refusal; at the entry limit they fit.
			'entry limit at average path length'   => array( $average, 'accept', 8 ),
			'longest ustar names fill the budget'  => array( $names( 256, 8 ), 'accept', 16 ),
			'one byte over the budget'             => array( $names( 256, 8, 257 ), 'limit', 16 ),
			// Plain headers are charged per header, so a repeated name costs PharData each time.
			'repeated plain name fills the budget' => array( array_fill( 0, 20, array( 'file', str_repeat( 'r', 100 ) ) ), 'accept', 20 ),
			'repeated plain name one header over'  => array( array_fill( 0, 21, array( 'file', str_repeat( 'r', 100 ) ) ), 'limit', 21 ),
			'headed parent is not charged again'   => array( array( array( 'dir', 'dd' ), array( 'long', 'dd/' . str_repeat( 'x', 2043 ) ) ), 'accept', 8 ),
			'implied parent fits exactly'          => array( array( array( 'long', 'dd/' . str_repeat( 'x', 2043 ) ) ), 'accept', 8 ),
			'implied parent one byte over'         => array( array( array( 'long', 'dd/' . str_repeat( 'x', 2044 ) ) ), 'limit', 8 ),
			// Fifty implied parents of one 101-byte name total 2500 bytes: depth costs PharData as much as length.
			'deep implied parents'                 => array( array( array( 'long', str_repeat( 'a/', 50 ) . 'x' ) ), 'limit', 8 ),
			'long name with a trailing NUL'        => array( array( array( 'long-nul', str_repeat( 'g', 200 ) ) ), 'accept', 8 ),
			'long name with bytes after a NUL'     => array( array( array( 'raw-long', "a\0b" ) ), 'unreadable', 8 ),
			'more members than the entry limit'    => array( array_fill( 0, 9, array( 'dir', 'd' ) ), 'unreadable', 8 ),
			// Production limits: refused at its first record, before the payloads add up.
			'1025 payloads cut to one name'        => array( array( array( 'cut-payloads', '' ) ), 'unreadable', null ),
		);
	}

	/**
	 * The walk accepts a long name with one trailing NUL (GNU tar's shape), but PharData keeps the NUL
	 * in the entry name and cannot read the entry, so the test restore still refuses the archive.
	 * The Child writer declares the name without a NUL.
	 */
	public function test_tar_long_name_with_trailing_nul_is_unreadable_by_phardata() {
		$name    = str_repeat( 'g', 200 );
		$path    = $this->raw_tar( $this->raw_tar_member( '././@LongLink', $name . "\0", '', 'L' ) . $this->raw_tar_member( substr( $name, 0, 100 ), 'x' ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'extract' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( 'A backup archive could not be read.', $caught->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * A directory PharData lists without a header passes only when a walked member lies under it.
	 *
	 * @dataProvider tar_listed_directory_cases
	 * @param string $directory Directory added to PharData's listing.
	 * @param bool   $accepted  Whether the archive passes.
	 */
	public function test_tar_listed_directory_must_lead_to_a_walked_member( $directory, $accepted ) {
		$long   = str_repeat( 'long/', 120 ) . 'file.txt';
		$path   = $this->raw_tar(
			$this->raw_tar_member( '././@LongLink', $long, '', 'L' ) . $this->raw_tar_member( 'placeholder.txt', 'long' )
			. $this->raw_tar_member( 'plain.txt', 'x' ) . $this->raw_tar_member( 'folder/', '', '', '5' )
			. str_repeat( "\0", 1024 ) . $this->raw_tar_member( 'after-end/', '', '', '5' )
		);
		$caught = $this->validated_tar_refusal( $this->scaled_sandbox( 2000000, 512000000, array( $directory ) ), $path );
		if ( $accepted ) {
			$this->assertNull( $caught, $caught ? $caught->get_message_extra( false ) : '' );
		} else {
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( 'A backup archive could not be read.', $caught->getMessage() );
		}
	}

	/**
	 * Listed directories without a header.
	 *
	 * @return array
	 */
	public static function tar_listed_directory_cases() {
		return array(
			'implied parent'                     => array( 'long', true ),
			'implied parent with a slash'        => array( 'long/', true ),
			'implied grandparent'                => array( 'long/long', true ),
			'headed directory'                   => array( 'folder', true ),
			'orphan directory'                   => array( 'orphan', false ),
			'orphan under a headed directory'    => array( 'folder/orphan', false ),
			'name prefix that is not a parent'   => array( 'lon', false ),
			'implied parent past the end marker' => array( 'after-end', false ),
		);
	}

	/**
	 * A member size past PharData's 32-bit limit is refused before PharData reads the archive.
	 * The fixture is a sparse file, so it costs no disk space.
	 *
	 * @dataProvider oversized_tar_member_sizes
	 * @param string $octal Size field digits.
	 */
	public function test_tar_headers_refuse_sizes_past_phar_limit( $octal ) {
		$header = $this->patch_raw_tar_header( $this->raw_tar_member( 'a.txt', '' ), 124, $octal . "\0" );
		$hidden = $this->raw_tar_member( 'sub//hidden.php', 'hostile' );
		$path   = $this->dir . '/' . uniqid( 'huge-' ) . '.tar';
		$handle = fopen( $path, 'wb' );
		fwrite( $handle, $header . str_repeat( "\0", 512 ) . $hidden );
		ftruncate( $handle, 512 + octdec( $octal ) + 1536 );
		fclose( $handle );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'size' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertStringContainsString( 'could not be read', $caught->getMessage() );
		} finally {
			$sandbox->tear_down();
			unlink( $path );
		}
	}

	/**
	 * Sizes PharData misreads: one byte past the rounding limit, and 4 GiB.
	 *
	 * @return array
	 */
	public static function oversized_tar_member_sizes() {
		return array(
			'rounding overflow' => array( '37777777001' ),
			'4 GiB'             => array( '40000000000' ),
		);
	}

	/**
	 * Metadata limits and payload boundaries must be checked before extraction.
	 *
	 * @dataProvider malformed_tar_header_cases
	 * @param string $fault Header fault.
	 * @return void
	 */
	public function test_tar_headers_refuse_malformed_members( $fault ) {
		$bytes = $this->raw_tar_member( 'good.txt', 'good' );
		if ( 'checksum' === $fault ) {
			$bytes[0] = 'X';
		} elseif ( 'truncated' === $fault ) {
			$bytes = $this->raw_tar_member( 'large.txt', str_repeat( 'x', 2048 ) );
			$bytes = substr( $bytes, 0, 512 );
		} elseif ( 'long' === $fault || 'pax' === $fault ) {
			$bytes = $this->raw_tar_member( '././@LongLink', str_repeat( 'x', 65537 ), '', 'pax' === $fault ? 'x' : 'L' ) . $bytes;
		} elseif ( 'pax record' === $fault ) {
			$bytes = $this->raw_tar_member( 'PaxHeader', "999999999999999999999 path=a\n", '', 'x' ) . $bytes;
		} elseif ( 'orphan' === $fault ) {
			$bytes = $this->raw_tar_member( '././@LongLink', 'unused.txt', '', 'L' );
		} else {
			$size  = 'octal' === $fault ? "00000000009\0" : ( 'overflow' === $fault ? "\x80\x01" . str_repeat( "\0", 10 ) : str_repeat( "\xff", 12 ) );
			$bytes = $this->patch_raw_tar_header( $bytes, 124, $size );
		}
		$path    = $this->raw_tar( $bytes );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'extract' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
			$this->assertStringContainsString( 'could not be read', $caught->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Corrupt fixtures must exercise distinct parser failures.
	 *
	 * @return array
	 */
	public static function malformed_tar_header_cases() {
		return array(
			'checksum'         => array( 'checksum' ),
			'truncated'        => array( 'truncated' ),
			'oversized L'      => array( 'long' ),
			'oversized x'      => array( 'pax' ),
			'pax record'       => array( 'pax record' ),
			'orphan L'         => array( 'orphan' ),
			'invalid octal'    => array( 'octal' ),
			'base256 overflow' => array( 'overflow' ),
			'base256 negative' => array( 'negative' ),
		);
	}

	/**
	 * Metadata formats with unsafe names.
	 *
	 * @return array
	 */
	public static function unsafe_tar_name_cases() {
		return array( array( 'L' ) );
	}

	/**
	 * The effective metadata name takes precedence over an innocuous short header.
	 *
	 * @dataProvider unsafe_tar_name_cases
	 * @param string $type Metadata format.
	 * @return void
	 */
	public function test_tar_headers_refuse_unsafe_metadata_names( $type ) {
		$name    = '../escape.sql';
		$record  = 'path=' . $name . "\n";
		$length  = strlen( $record ) + 3;
		$payload = 'L' === $type ? $name : $length . ' ' . $record;
		$path    = $this->raw_tar( $this->raw_tar_member( '././@LongLink', $payload, '', $type ) . $this->raw_tar_member( 'safe.sql', 'SELECT 1;' ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'read' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
			$this->assertStringContainsString( 'contains an unsafe file path', $caught->getMessage() );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * A missing-path count must include all members and retain a usable first label.
	 *
	 * @return void
	 */
	public function test_tar_headers_report_missing_count_and_invalid_utf8() {
		$prefix  = str_repeat( 'beta/', 21 );
		$path    = $this->raw_tar( $this->raw_tar_member( "<first>-\xff.txt", 'lost', $prefix ) . $this->raw_tar_member( 'second.txt', 'lost', $prefix ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'extract' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertStringContainsString( '2 long paths', $caught->getMessage() );
			$this->assertStringContainsString( '&lt;first&gt;-', $caught->getMessage() );
			$this->assertStringContainsString( '.txt', $caught->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Duplicate missing headers retain their count but use the normalized key as label.
	 */
	public function test_tar_headers_count_duplicate_prefixed_keys() {
		$member  = $this->raw_tar_member( 'lost.txt', 'lost', './beta' );
		$path    = $this->raw_tar( $member . $member );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'extract' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertStringContainsString( '2 long paths', $caught->getMessage() );
			$this->assertStringContainsString( 'first one is beta/lost.txt.', $caught->getMessage() );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Link members are refused before PharData can materialize them.
	 *
	 * @dataProvider tar_link_materialization_cases
	 * @param string $type Tar link type.
	 * @param bool   $child Whether a member writes beneath the link.
	 */
	public function test_tar_headers_sandbox_link_materialization( $type, $child ) {
		$outside = $this->dir . '/outside';
		$this->assertTrue( mkdir( $outside ) );
		$this->assertSame( 4, file_put_contents( $outside . '/keep.txt', 'keep' ) );
		$path    = $this->raw_tar( $this->raw_tar_member( 'link', '', '', $type, $outside ) . ( $child ? $this->raw_tar_member( 'link/written.txt', 'hostile' ) : '' ) );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'extract' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
			$this->assertStringContainsString( 'could not be read', $caught->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
			$tree = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $sandbox->temp_dir(), \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $tree as $file ) {
				$this->assertFalse( $file->isLink(), $file->getPathname() );
			}
			$this->assertSame( array( '.', '..', 'keep.txt' ), scandir( $outside ) );
			$this->assertSame( 'keep', file_get_contents( $outside . '/keep.txt' ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Hard and symbolic links each need a direct and a write-through probe.
	 *
	 * @return array
	 */
	public static function tar_link_materialization_cases() {
		return array(
			'hard'           => array( '1', false ),
			'hard child'     => array( '1', true ),
			'symbolic'       => array( '2', false ),
			'symbolic child' => array( '2', true ),
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
	 * A destination collision must not let a failed tar extraction pass verification.
	 *
	 * @return void
	 */
	public function test_tar_extraction_exception_surfaces_as_failure() {
		$path    = $this->clean_tar();
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();

		try {
			$this->assertTrue( mkdir( $sandbox->temp_dir() . 'good.txt' ) );
			try {
				// PharData emits a warning before throwing on the destination collision.
				@$sandbox->extract_files( $path );
				$this->fail( 'A failed tar extraction must not pass verification.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $e->get_message_error_code() );
				$this->assertStringContainsString( 'A backup archive could not be extracted.', $e->getMessage() );
				$this->assertStringContainsString( 'tar extract: Extraction from phar', $e->get_message_extra( false ) );
			}
			$this->assertSame( 0, $sandbox->temp_file_count() );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() . 'good.txt' ) );
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
	public function test_tar_gz_alias_directory_failure_reports_private_root() {
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
				$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $e->get_message_error_code() );
			}
			$this->assertTrue( $threw, 'An unavailable alias directory must be reported as a private storage failure.' );
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
			$parent = $this->private_root . '/test-restore';
			$this->assertFileExists( $parent . '/.htaccess' );
			$this->assertSame( 'deny from all', file_get_contents( $parent . '/.htaccess' ) );
			$this->assertFileExists( $parent . '/index.php' );
			$this->assertSame( '', file_get_contents( $parent . '/index.php' ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Alias directory creation must succeed before parent protection runs.
	 *
	 * @return void
	 */
	public function test_phar_readable_path_creates_alias_directory_before_protecting_parent() {
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
				$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $e->get_message_error_code() );
				$this->assertStringContainsString( 'Private backup directory', $e->getMessage() );
				$this->assertStringContainsString( $alias_dir, $e->getMessage() );
			}
			$this->assertTrue( $threw, 'The blocked alias directory must fail before parent protection.' );
			$this->assertDirectoryDoesNotExist( $alias_dir );
			$this->assertFileDoesNotExist( $parent . '/.htaccess' );
			$this->assertFileDoesNotExist( $parent . '/index.php' );
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
		$parent = $this->private_root . '/test-restore';
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
	 * Protection refusal cleans up the newly created extraction root.
	 *
	 * @return void
	 */
	public function test_bring_up_cleans_extraction_root_after_protection_refusal() {
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
			$this->assertTrue( $sandbox->root_before_cleanup, 'The private root writer creates the extraction root before protection.' );
			$this->assertDirectoryDoesNotExist( $sandbox->temp_dir() );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * New sandbox parents are private; unwritable roots retain their storage error.
	 *
	 * @dataProvider bring_up_private_root_cases
	 * @param bool $read_only Whether the root starts read-only.
	 * @return void
	 */
	public function test_bring_up_private_root( bool $read_only ) {
		if ( $read_only ) {
			$this->assertTrue( mkdir( $this->private_root, 0500 ) );
			if ( is_writable( $this->private_root ) ) {
				$this->markTestSkipped( 'The process can write read-only directories (for example, as root).' );
			}
		} else {
			$this->assertDirectoryDoesNotExist( $this->private_root );
		}
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		try {
			$caught = null;
			try {
				$sandbox->bring_up();
			} catch ( MainWP_Exception $e ) {
				if ( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED === $e->get_message_error_code() && str_starts_with( (string) $e->get_message_extra( false ), 'create database: ' ) ) {
					$this->markTestSkipped( 'The database user lacks CREATE DATABASE privilege.' );
				}
				$caught = $e;
			}
			if ( $read_only ) {
				$this->assertInstanceOf( MainWP_Exception::class, $caught );
				$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $caught->get_message_error_code() );
			} else {
				$this->assertNull( $caught );
				foreach ( array( $this->private_root, $this->private_root . '/test-restore' ) as $dir ) {
					clearstatcache( true, $dir );
					$this->assertSame( 0700, fileperms( $dir ) & 0777 );
					$this->assertFileExists( $dir . '/index.php' );
					$this->assertSame( 'deny from all', file_get_contents( $dir . '/.htaccess' ) );
				}
			}
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Root creation and write refusal cases.
	 *
	 * @return array
	 */
	public static function bring_up_private_root_cases(): array {
		return array(
			'missing root'   => array( false ),
			'read-only root' => array( true ),
		);
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
			"'<b>x</b>' INTO wp_posts"                     => "'<B>X</B>'",
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
			"CREATE TABLE `t` (id INT) ENGINE=FEDERATED CONNECTION 'mysql://u:p@host/db/tbl'" => 'CONNECTION',
			'CREATE TABLE `t` (id INT) ENGINE=FEDERATED CONNECTION "mysql://u:p@host/db/tbl"' => 'CONNECTION',
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
			"CREATE TABLE `t` (connection VARCHAR(20) DEFAULT 'x')",
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
				$this->assertSame( 'The backup database dump contains a statement the test restore will not run: ' . esc_html( $refusal ) . '.', $caught->getMessage(), $label );
			}
		}
	}

	/**
	 * One case per row of the executable-comment rule (.mwpdev/reviews/security-166/sql-executable-comment-rule.md).
	 *
	 * @return array
	 */
	public function sql_comment_shape_cases() {
		return array(
			'1 plain comment'                    => array( 'INSERT INTO wp_posts /* otherdb.x */ VALUES (1);', '' ),
			'2 M without bang is plain'          => array( 'INSERT INTO wp_posts /*M otherdb.x */ VALUES (1);', '' ),
			'3 mysql bang hides qualifier'       => array( 'INSERT INTO /*! otherdb.*/wp_users VALUES (1);', 'INSERT cross-schema' ),
			'4 mysql 5-digit hides qualifier'    => array( 'INSERT INTO /*!50001 otherdb.*/wp_users VALUES (1);', 'INSERT cross-schema' ),
			'4 mysql 5-digit benign'             => array( 'CREATE TABLE wp_posts (id int) /*!50100 ENGINE=InnoDB */;', '' ),
			'5 mysql 6-digit hides qualifier'    => array( 'INSERT INTO /*!100100 otherdb.*/wp_users VALUES (1);', 'INSERT cross-schema' ),
			'6 mariadb bang hides qualifier'     => array( 'INSERT INTO /*M! otherdb.*/wp_users VALUES (1);', 'INSERT cross-schema' ),
			'7 mariadb 6-digit hides qualifier'  => array( 'INSERT INTO /*M!100100 otherdb.*/wp_users VALUES (1);', 'INSERT cross-schema' ),
			'7 mariadb 5-digit hides qualifier'  => array( 'INSERT INTO /*M!50001 `otherdb`.*/wp_users VALUES (1);', 'INSERT cross-schema' ),
			'7 mariadb hides statement keyword'  => array( '/*M!100100 DROP DATABASE wordpress */;', 'DROP' ),
			'7 mariadb benign'                   => array( 'CREATE TABLE wp_posts (id int) /*M!100100 ENGINE=InnoDB */;', '' ),
			'8 lowercase mariadb marker'         => array( 'INSERT INTO /*m!100100 otherdb.*/wp_users VALUES (1);', 'INSERT cross-schema' ),
			'9 empty executable'                 => array( 'INSERT INTO wp_posts /*!*/ /*M!*/ VALUES (1);', '' ),
			'10 version glued to body'           => array( 'INSERT INTO /*!500011otherdb.*/wp_users VALUES (1);', 'executable comment version' ),
			'11 short version'                   => array( 'INSERT INTO wp_posts /*!4010 */ VALUES (1);', 'executable comment version' ),
			'11 seven digit version'             => array( 'INSERT INTO wp_posts /*M!1001001 */ VALUES (1);', 'executable comment version' ),
			'12 quote closes inside body'        => array( "INSERT INTO wp_posts VALUES (1) /*M!100100 , ('a;b') */;", '' ),
			'13 quote spans the first closer'    => array( "INSERT INTO wp_posts /*!50001 SELECT '*/' FROM otherdb.wp_users WHERE '' */;", 'unterminated quote' ),
			'14 backtick spans the first closer' => array( 'INSERT INTO /*M!100100 `a*/b` */ wp_posts VALUES (1);', 'unterminated quote' ),
			'15 nested comment in executable'    => array( 'INSERT INTO wp_posts /*!50001 /* note */ */ VALUES (1);', 'comment inside an executable comment' ),
			'16 quoted opener in executable'     => array( "INSERT INTO wp_posts VALUES (1) /*!99999 '/*' */;", 'comment inside an executable comment' ),
			'17 hash comment in executable'      => array( "INSERT INTO /*!50001 # */ '\notherdb.wp_users */ VALUES (1) #'\n;", 'line comment inside an executable comment' ),
			'17 dash comment in executable'      => array( "INSERT INTO /*M!100100 -- */ '\notherdb.wp_users */ VALUES (1) #'\n;", 'line comment inside an executable comment' ),
			'18 nested executable'               => array( 'INSERT INTO wp_posts /*!50001 /*M! otherdb.x */ */ VALUES (1);', 'comment inside an executable comment' ),
			'19 nested plain in plain'           => array( '/* a /* b */ INSERT INTO wp_posts VALUES (1);', '' ),
			'20 optimizer hint'                  => array( "INSERT /*+ SET_VAR(sort_buffer_size='*/') */ INTO otherdb.wp_users VALUES ('');", 'optimizer hint comment' ),
			'21 unterminated plain comment'      => array( 'INSERT INTO wp_posts VALUES (1); /* USE other;', 'unterminated comment' ),
			'22 unterminated executable comment' => array( 'INSERT INTO /*M!100100 otherdb.wp_users VALUES (1);', 'unterminated comment' ),
			'23 unterminated quote'              => array( "INSERT INTO wp_posts VALUES ('a);", 'unterminated quote' ),
		);
	}

	/**
	 * A Child-written dump cannot hide SQL from the guard in a comment the server executes.
	 *
	 * @dataProvider sql_comment_shape_cases
	 *
	 * @param string $sql     Dump bytes.
	 * @param string $refusal Expected refusal label, or '' when the dump is accepted.
	 *
	 * @return void
	 */
	public function test_sql_executable_comment_shape_matrix( $sql, $refusal ) {
		$split = new \ReflectionMethod( MainWP_Backup_TestRestore_Sandbox::class, 'split_sql_dump' );
		$guard = new \ReflectionMethod( MainWP_Backup_TestRestore_Sandbox::class, 'guard_sql_statement' );
		$split->setAccessible( true );
		$guard->setAccessible( true );
		$statements = array();
		$caught     = null;
		try {
			foreach ( $split->invoke( null, $sql ) as $statement ) {
				$statements[] = $statement;
				$guard->invoke( null, $statement, 'mainwp_tr_matrix' );
			}
		} catch ( MainWP_Exception $e ) {
			$caught = $e;
		}
		if ( '' === $refusal ) {
			$this->assertNull( $caught, null === $caught ? '' : $caught->get_message_extra( false ) );
			$this->assertSame( array( rtrim( $sql, ';' ) ), $statements );
			return;
		}
		$this->assertInstanceOf( MainWP_Exception::class, $caught );
		$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
		$this->assertSame( $refusal, $caught->get_message_extra( false ) );
		$this->assertSame( 'The backup database dump contains a statement the test restore will not run: ' . esc_html( $refusal ) . '.', $caught->getMessage() );
	}

	/**
	 * A MariaDB-executable comment naming the live database is refused before the server sees it.
	 *
	 * @return void
	 */
	public function test_import_refuses_mariadb_hidden_cross_schema_write() {
		$path    = $this->dir . '/hidden-qualifier.sql';
		$sql     = "CREATE TABLE wp_posts (id int);\nINSERT INTO /*M!100100 `" . DB_NAME . '`.*/wp_posts VALUES (1);';
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$this->assertSame( strlen( $sql ), file_put_contents( $path, $sql ) );

		try {
			$sandbox->bring_up();
			$caught = null;
			try {
				$sandbox->import_database( $path );
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
			$this->assertSame( 'INSERT cross-schema', $caught->get_message_extra( false ), 'The guard, not a server error, must stop the statement.' );
			$this->assertSame( 1, $sandbox->table_count(), 'Statements before the hostile one still import into the scratch database.' );
			$this->assertFalse( $sandbox->has_table_rows(), 'The hostile insert must not reach the server.' );
		} finally {
			$sandbox->tear_down();
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
	 * The shared validator also covers archive formats without a raw-header fixture.
	 *
	 * @return void
	 */
	public function test_unsafe_entry_name_validator_rejects_traversal_absolute_and_wrapper_names() {
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$method  = new \ReflectionMethod( $sandbox, 'has_unsafe_entry_name' );
		$method->setAccessible( true );

		foreach ( self::entry_name_cases() as $label => $row ) {
			$this->assertSame( $row[1], $method->invoke( $sandbox, $row[0] ), $label );
		}
		$this->assertTrue( $method->invoke( $sandbox, "good.txt\0.php" ), 'NUL byte' );
		$this->assertFalse( $method->invoke( $sandbox, '' ), 'archive root' );
	}

	/**
	 * Zip members follow the same name rule as tar members: refused names leave the sandbox
	 * empty, and accepted names land where they say.
	 *
	 * @dataProvider entry_name_cases
	 * @param string $name   Member name.
	 * @param bool   $unsafe Whether the name is refused.
	 */
	public function test_zip_entry_name_matrix( $name, $unsafe ) {
		if ( ! $unsafe && 'Windows' === PHP_OS_FAMILY && 1 === preg_match( '#:|\.$|\\\\#', $name ) ) {
			$this->markTestSkipped( 'Windows cannot create a file named ' . $name . ' in place.' );
		}
		$path = $this->dir . '/' . uniqid( 'names-' ) . '.zip';
		$zip  = new \ZipArchive();
		$this->assertTrue( $zip->open( $path, \ZipArchive::CREATE ) );
		$this->assertTrue( $zip->addFromString( $name, 'named' ) );
		$this->assertTrue( $zip->addFromString( 'good.txt', 'good-bytes' ) );
		$this->assertTrue( $zip->close() );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = null;
			try {
				$sandbox->extract_files( $path );
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}
			if ( $unsafe ) {
				$this->assertInstanceOf( MainWP_Exception::class, $caught );
				$this->assertSame( 'zip traversal entry: ' . $name, $caught->get_message_extra( false ) );
				$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
			} else {
				$this->assertNull( $caught, $caught ? $caught->get_message_extra( false ) : '' );
				$this->assertSame( 'named', file_get_contents( $sandbox->temp_dir() . $name ) );
			}
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * ZipArchive::extractTo() moves a member whose path has a directory name ending in a dot, so
	 * the zip is refused before anything is written; the tar extractor keeps the path as it is.
	 *
	 * @dataProvider zip_moved_name_cases
	 * @param string $name     Member name the zip extractor would move.
	 * @param bool   $tar_kept Whether PharData extracts the same name in place (it refuses control characters and does not list . segments, and the tar walk reads a backslash as a separator, as the Child's does).
	 */
	public function test_zip_refuses_names_the_extractor_would_move( $name, $tar_kept = true ) {
		if ( 'Windows' === PHP_OS_FAMILY && false !== strpos( $name, '\\' ) ) {
			$this->markTestSkipped( 'A backslash separates segments on Windows, so the name is not moved there.' );
		}
		$path = $this->dir . '/' . uniqid( 'moved-' ) . '.zip';
		$zip  = new \ZipArchive();
		$this->assertTrue( $zip->open( $path, \ZipArchive::CREATE ) );
		$this->assertTrue( $zip->addFromString( 'index.php', 'outer' ) );
		$this->assertTrue( $zip->addFromString( $name, 'inner' ) );
		$this->assertTrue( $zip->close() );
		$sandbox = new MainWP_Backup_TestRestore_Sandbox();
		$sandbox->bring_up();
		try {
			$caught = $this->tar_refusal( $sandbox, $path, 'extract' );
			$this->assertInstanceOf( MainWP_Exception::class, $caught );
			$this->assertSame( MainWP_Backup_TestRestore_Sandbox::ERR_APPLY, $caught->get_message_error_code() );
			$this->assertSame( 'zip entry the extractor would move: ' . $name, $caught->get_message_extra( false ) );
			$this->assertSame( array( '.', '..' ), scandir( $sandbox->temp_dir() ) );
		} finally {
			$sandbox->tear_down();
		}
		if ( ! $tar_kept ) {
			return;
		}

		$tar = $this->raw_tar( $this->raw_tar_member( 'index.php', 'outer' ) . $this->raw_tar_member( $name, 'inner' ) );
		$sandbox->bring_up();
		try {
			$sandbox->extract_files( $tar );
			$this->assertSame( 'inner', file_get_contents( $sandbox->temp_dir() . $name ) );
			$this->assertSame( 'outer', file_get_contents( $sandbox->temp_dir() . 'index.php' ) );
		} finally {
			$sandbox->tear_down();
		}
	}

	/**
	 * Directory names ending in one or two dots, at the top and further down.
	 *
	 * @return array
	 */
	public static function zip_moved_name_cases() {
		return array(
			'two dots'                => array( 'release../index.php' ),
			'one dot'                 => array( 'release./index.php' ),
			'nested two dots'         => array( 'a/b../c/index.php' ),
			'three dots'              => array( '.../index.php' ),
			'newline and dot'         => array( "\n./index.php", false ),
			'dot after a dot segment' => array( 'a/./b./index.php', false ),
			'backslash then dot'      => array( 'a\\./index.php', false ),
		);
	}

	/**
	 * Names on both sides of the Child's validate_extract_path() rule.
	 *
	 * @return array<string, array{0: string, 1: bool}> Name and whether it is unsafe.
	 */
	public static function entry_name_cases() {
		return array(
			'plain'                    => array( 'plain.txt', false ),
			'nested'                   => array( 'nested/good-name.txt', false ),
			'dots inside a name'       => array( 'jquery..min.js', false ),
			'dots inside a directory'  => array( 'a..b/c.txt', false ),
			'three dots'               => array( '...', false ),
			'drive letter only'        => array( 'C:notes.txt', false ),
			'dot segment'              => array( 'assets/./style.css', false ),
			'leading dot segments'     => array( '././index.php', false ),
			'backslash after a dot'    => array( 'a.\\b.txt', false ),
			'parent'                   => array( '../evil.txt', true ),
			'nested parent'            => array( 'sub/../../evil.txt', true ),
			'trailing parent'          => array( 'sub/..', true ),
			'bare parent'              => array( '..', true ),
			'backslash parent'         => array( 'sub\\..\\evil.txt', true ),
			'leading backslash parent' => array( '..\\evil.txt', true ),
			'absolute'                 => array( '/etc/passwd', true ),
			'leading backslash'        => array( '\\evil.txt', true ),
			'drive and slash'          => array( 'C:/Windows/evil.txt', true ),
			'drive and backslash'      => array( 'C:\\Windows\\evil.txt', true ),
			'stream wrapper'           => array( 'phar://some/other/archive.tar', true ),
			'parent after dotted name' => array( 'a..b/../evil.txt', true ),
		);
	}
}
