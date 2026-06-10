<?php
/**
 * MainWP Backup File Validation Tests
 *
 * Tests for downloaded-backup validation: size tolerance and archive
 * header (magic byte) checks, including rejection of error-page content
 * saved with a backup extension.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Exception;

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct file IO in tests.

/**
 * Class MainWP_Backup_File_Validation_Test
 */
class MainWP_Backup_File_Validation_Test extends \WP_UnitTestCase {

	/**
	 * Files to clean up.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Clean up scratch files.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();
		parent::tear_down();
	}

	/**
	 * Write a scratch file with the given name suffix and content.
	 *
	 * @param string $suffix  File suffix including extension.
	 * @param string $content File content.
	 *
	 * @return string Path.
	 */
	private function scratch_file( $suffix, $content ) {
		$path          = get_temp_dir() . 'mainwp-validate-' . uniqid() . $suffix;
		$this->files[] = $path;
		file_put_contents( $path, $content );
		return $path;
	}

	/**
	 * A real ZIP archive passes validation.
	 *
	 * @return void
	 */
	public function test_valid_zip_passes() {
		$path          = get_temp_dir() . 'mainwp-validate-' . uniqid() . '.zip';
		$this->files[] = $path;
		$zip           = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( 'readme.txt', 'backup content' );
		$zip->close();

		MainWP_Backup_File_Manager::validate_backup_file( $path, filesize( $path ) );
		$this->assertTrue( true, 'No exception thrown for a valid ZIP.' );
	}

	/**
	 * GZIP content passes the header check.
	 *
	 * @return void
	 */
	public function test_valid_gzip_passes() {
		$path = $this->scratch_file( '.tar.gz', gzencode( 'tar bytes here' ) );

		MainWP_Backup_File_Manager::validate_backup_file( $path, filesize( $path ) );
		$this->assertTrue( true, 'No exception thrown for valid GZIP.' );
	}

	/**
	 * BZ2 content passes the header check.
	 *
	 * @return void
	 */
	public function test_valid_bz2_passes() {
		$path = $this->scratch_file( '.tar.bz2', 'BZh91AY&SY' . str_repeat( 'x', 64 ) );

		MainWP_Backup_File_Manager::validate_backup_file( $path, filesize( $path ) );
		$this->assertTrue( true, 'No exception thrown for valid BZ2 header.' );
	}

	/**
	 * An HTML error page saved with a ZIP extension is rejected.
	 *
	 * @return void
	 */
	public function test_html_error_page_with_zip_extension_fails() {
		$path = $this->scratch_file( '.zip', '<html><body>Fatal error: out of memory</body></html>' );

		try {
			MainWP_Backup_File_Manager::validate_backup_file( $path, filesize( $path ) );
			$this->fail( 'Expected header validation to reject HTML content.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_header_invalid', $e->get_message_error_code() );
		}
	}

	/**
	 * A plain TAR archive with a ustar header passes.
	 *
	 * @return void
	 */
	public function test_valid_tar_passes() {
		$header = str_pad( 'somefile.txt', 257, "\0" ) . "ustar\0" . str_repeat( "\0", 249 );
		$path   = $this->scratch_file( '.tar', $header . str_repeat( "\0", 512 ) );

		MainWP_Backup_File_Manager::validate_backup_file( $path, filesize( $path ) );
		$this->assertTrue( true, 'No exception thrown for valid TAR header.' );
	}

	/**
	 * A garbage TAR file is rejected.
	 *
	 * @return void
	 */
	public function test_invalid_tar_fails() {
		$path = $this->scratch_file( '.tar', str_repeat( 'A', 1024 ) );

		try {
			MainWP_Backup_File_Manager::validate_backup_file( $path, filesize( $path ) );
			$this->fail( 'Expected header validation to reject a garbage TAR.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_header_invalid', $e->get_message_error_code() );
		}
	}

	/**
	 * A size within the one percent tolerance passes.
	 *
	 * @return void
	 */
	public function test_size_within_tolerance_passes() {
		$path = $this->scratch_file( '.tar.gz', gzencode( str_repeat( 'data', 1000 ) ) );

		MainWP_Backup_File_Manager::validate_backup_file( $path, (int) floor( filesize( $path ) * 1.005 ) );
		$this->assertTrue( true, 'No exception for a size within tolerance.' );
	}

	/**
	 * A size outside the tolerance is rejected as too small.
	 *
	 * @return void
	 */
	public function test_size_outside_tolerance_fails() {
		$path = $this->scratch_file( '.tar.gz', gzencode( 'tiny' ) );

		try {
			MainWP_Backup_File_Manager::validate_backup_file( $path, filesize( $path ) * 10 );
			$this->fail( 'Expected size validation to reject the short file.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_file_too_small', $e->get_message_error_code() );
		}
	}

	/**
	 * A missing or empty file is rejected as corrupt.
	 *
	 * @return void
	 */
	public function test_missing_or_empty_file_fails() {
		try {
			MainWP_Backup_File_Manager::validate_backup_file( get_temp_dir() . 'does-not-exist.zip', 100 );
			$this->fail( 'Expected validation to reject a missing file.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_file_corrupt', $e->get_message_error_code() );
		}

		$empty = $this->scratch_file( '.zip', '' );
		try {
			MainWP_Backup_File_Manager::validate_backup_file( $empty, 100 );
			$this->fail( 'Expected validation to reject an empty file.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_file_corrupt', $e->get_message_error_code() );
		}
	}

	/**
	 * Unknown size (false) skips the size check but still verifies the header.
	 *
	 * @return void
	 */
	public function test_unknown_size_still_checks_header() {
		$path = $this->scratch_file( '.tar.gz', gzencode( 'content' ) );
		MainWP_Backup_File_Manager::validate_backup_file( $path, false );
		$this->assertTrue( true, 'No exception for valid header without size.' );

		$bad = $this->scratch_file( '.zip', '<html>error</html>' );
		try {
			MainWP_Backup_File_Manager::validate_backup_file( $bad, false );
			$this->fail( 'Expected header rejection even without an expected size.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_header_invalid', $e->get_message_error_code() );
		}
	}
}
