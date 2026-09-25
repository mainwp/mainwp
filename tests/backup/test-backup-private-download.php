<?php
/**
 * Signed downloads from the existing per-site Backups tab.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_System_Handler;
use MainWP\Dashboard\MainWP_System_Utility;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/**
 * Exercise parse_init with real archive paths and download signatures.
 */
class MainWP_Backup_Private_Download_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Isolate private storage and act as an administrator.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Remove fixtures and request-local state.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Replace only the exit-producing streamer, retaining endpoint validation.
	 *
	 * @param array $query Request parameters.
	 * @return string|null Served bytes, or null when nothing was served.
	 */
	private function request_download( array $query ): ?string {
		$streamer = new class() extends MainWP_System_Handler {
			/**
			 * Bytes selected by the endpoint.
			 *
			 * @var string|null
			 */
			public $bytes = null;

			/**
			 * Capture the archive without exiting PHPUnit.
			 *
			 * @param string $file Authorized archive path.
			 * @throws \RuntimeException To stop before parse_init exits.
			 */
			public function upload_file( $file ) {
				$this->bytes = file_get_contents( $file );
				throw new \RuntimeException( 'download captured' );
			}
		};
		$property = new \ReflectionProperty( MainWP_System_Handler::class, 'instance' );
		$original = $property->getValue();
		$get      = $_GET;
		$request  = $_REQUEST;
		try {
			$property->setValue( null, $streamer );
			$_GET     = $query;
			$_REQUEST = $query;
			try {
				MainWP_System::instance()->parse_init();
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'download captured', $error->getMessage() );
			}
			return $streamer->bytes;
		} finally {
			$property->setValue( null, $original );
			$_GET     = $get;
			$_REQUEST = $request;
		}
	}

	/**
	 * Private downloads retain signature validation and reject escaping paths.
	 */
	public function test_private_mwpdl_download_and_refusals() {
		$site_id = $this->create_test_site();
		$dir     = MainWP_Backup_Private_Root::site_dir( $site_id );
		$file    = $dir . 'full-test.tar.gz';
		file_put_contents( $file, 'private archive bytes' );
		$query = array(
			'root'  => 'private',
			'mwpdl' => 'sites/' . $site_id . '/full-test.tar.gz',
			'sig'   => MainWP_System_Utility::get_download_sig( $file ),
		);
		$this->assertSame( 'private archive bytes', $this->request_download( $query ) );
		$this->assertNull( $this->request_download( array_merge( $query, array( 'sig' => 'invalid' ) ) ) );
		$this->assertNull( $this->request_download( array_merge( $query, array( 'mwpdl' => 'sites/' . $site_id . '/../' . $site_id . '/full-test.tar.gz' ) ) ) );
		$outside = wp_tempnam( 'outside-backup' );
		file_put_contents( $outside, 'outside archive bytes' );
		try {
			symlink( $outside, $dir . 'escape.tar.gz' );
			$escape = array_merge(
				$query,
				array(
					'mwpdl' => 'sites/' . $site_id . '/escape.tar.gz',
					'sig'   => MainWP_System_Utility::get_download_sig( $outside ),
				)
			);
			$this->assertNull( $this->request_download( $escape ) );
			$this->assertNull( $this->request_download( array_merge( $escape, array( 'mwpdl' => $outside ) ) ) );
		} finally {
			unlink( $outside );
		}
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		MainWP_Backup_Private_Root::reset();
		$this->assertNull( $this->request_download( $query ) );
	}

	/**
	 * A signed private link is a bearer capability; without a session it must serve nothing.
	 */
	public function test_private_mwpdl_download_requires_login() {
		$site_id = $this->create_test_site();
		$dir     = MainWP_Backup_Private_Root::site_dir( $site_id );
		$file    = $dir . 'full-test.tar.gz';
		file_put_contents( $file, 'private archive bytes' );
		$query = array(
			'root'  => 'private',
			'mwpdl' => 'sites/' . $site_id . '/full-test.tar.gz',
			'sig'   => MainWP_System_Utility::get_download_sig( $file ),
		);
		wp_set_current_user( 0 );
		$this->assertNull( $this->request_download( $query ) );
	}

	/**
	 * Only the site-owned `sites/<id>/...` shape is servable; other private locations are refused.
	 */
	public function test_private_mwpdl_download_refuses_non_sites_path() {
		$dir  = MainWP_Backup_Private_Root::staging_dir( 'restore-staging', 'x' );
		$file = $dir . 'file.tar.gz';
		file_put_contents( $file, 'staging archive bytes' );
		$query = array(
			'root'  => 'private',
			'mwpdl' => 'restore-staging/x/file.tar.gz',
			'sig'   => MainWP_System_Utility::get_download_sig( $file ),
		);
		$this->assertNull( $this->request_download( $query ) );
	}

	/**
	 * Missing and unknown root selectors keep serving the legacy location.
	 */
	public function test_legacy_mwpdl_root_selection_is_unchanged() {
		$site_id = $this->create_test_site();
		$file    = MainWP_System_Utility::get_mainwp_specific_dir( $site_id ) . 'legacy.zip';
		file_put_contents( $file, 'legacy archive bytes' );
		try {
			$query = array(
				'mwpdl' => '0/' . $site_id . '/legacy.zip',
				'sig'   => MainWP_System_Utility::get_download_sig( $file ),
			);
			$this->assertSame( 'legacy archive bytes', $this->request_download( $query ) );
			$this->assertSame( 'legacy archive bytes', $this->request_download( array_merge( $query, array( 'root' => 'unknown' ) ) ) );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * A session alone grants neither backup execution nor site access.
	 */
	public function test_private_download_requires_backup_and_site_rights() {
		$this->skip_when_capabilities_bypassed();
		$site_id = $this->create_test_site();
		$file    = MainWP_Backup_Private_Root::site_dir( $site_id ) . 'full.zip';
		file_put_contents( $file, 'archive' );
		$query = array(
			'root'  => 'private',
			'mwpdl' => 'sites/' . $site_id . '/full.zip',
			'sig'   => MainWP_System_Utility::get_download_sig( $file ),
		);
		foreach ( array( 'dashboard', 'site' ) as $denied ) {
			$filter = static function ( $allowed, $type, $cap ) use ( $denied, $site_id ) {
				return $type === $denied && ( 'execute_backups' === $cap || $site_id === (int) $cap ) ? false : $allowed;
			};
			add_filter( 'mainwp_currentusercan', $filter, 10, 3 );
			$this->assertNull( $this->request_download( $query ), $denied );
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
		$this->assertSame( 'archive', $this->request_download( $query ) );
	}
}
