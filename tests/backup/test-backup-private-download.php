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
		chmod( $this->private_root, 0500 );
		MainWP_Backup_Private_Root::reset();
		try {
			$this->assertSame( 'private archive bytes', $this->request_download( $query ) );
		} finally {
			chmod( $this->private_root, 0700 );
		}
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
			wp_set_current_user( 0 );
			$this->assertSame( 'legacy archive bytes', $this->request_download( $query ) );
			$this->assertSame( 'legacy archive bytes', $this->request_download( array_merge( $query, array( 'root' => 'unknown' ) ) ) );
		} finally {
			unlink( $file );
		}
	}


	/**
	 * Literal authorization outcomes exercise the real endpoint and its ordering.
	 */
	public function test_private_download_gate_matrix() {
		$this->skip_when_capabilities_bypassed();
		$rows = array(
			// Role, denied right, multi-user, request shape, expected bytes, expected resolver calls.
			'cron'                       => array( 'administrator', '', false, 'cron', null, 0 ),
			'existing other site denied' => array( 'administrator', 'other-site', false, 'existing-other', null, 0 ),
			'anonymous'                  => array( '', '', false, 'own', null, 0 ),
			'subscriber'                 => array( 'subscriber', '', false, 'own', null, 0 ),
			'editor'                     => array( 'editor', '', false, 'own', null, 0 ),
			'administrator'              => array( 'administrator', '', false, 'own', 'archive', 1 ),
			'backup right denied'        => array( 'administrator', 'dashboard', false, 'own', null, 0 ),
			'site right denied'          => array( 'administrator', 'site', false, 'own', null, 0 ),
			'other owner'                => array( 'administrator', '', true, 'own', null, 0 ),
			'leading zero'               => array( 'administrator', '', false, 'sites/05/full.zip', null, 0 ),
			'noncanonical integer'       => array( 'administrator', '', false, 'sites/12abc/full.zip', null, 0 ),
			'non-site path'              => array( 'administrator', '', false, 'backups/full.zip', null, 0 ),
			'parent traversal'           => array( 'administrator', '', false, 'traversal', null, 1 ),
			'other site path'            => array( 'administrator', '', false, 'other', null, 1 ),
		);
		foreach ( $rows as $label => $row ) {
			list( $role, $denied, $multi, $shape, $expected, $expected_calls ) = $row;
			$this->remove_private_fixture( $this->private_root );
			MainWP_Backup_Private_Root::reset();
			$user = '' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) );
			wp_set_current_user( $user );
			$owner    = $multi ? self::factory()->user->create() : $user;
			$site_id  = $this->create_test_site( array( 'userid' => $owner ) );
			$other_id = $this->create_test_site( array( 'userid' => $user ) );
			$file     = $this->private_root . '/sites/' . ( 'existing-other' === $shape ? $other_id : $site_id ) . '/full.zip';
			$sig      = 'unused';
			if ( '' !== $role ) {
				wp_mkdir_p( dirname( $file ) );
				file_put_contents( $file, 'archive' );
				$this->assertFileExists( $file, $label );
				$sig = MainWP_System_Utility::get_download_sig( $file );
			}
			$paths  = array(
				'cron'           => 'sites/' . $site_id . '/full.zip',
				'existing-other' => 'sites/' . $other_id . '/full.zip',
				'own'            => 'sites/' . $site_id . '/full.zip',
				'other'          => 'sites/' . $other_id . '/full.zip',
				'traversal'      => 'sites/' . $site_id . '/../' . $site_id . '/full.zip',
			);
			$calls  = 0;
			$count  = static function ( $root ) use ( &$calls ) {
				++$calls;
				return $root;
			};
			$rights = static function ( $allowed, $type, $cap ) use ( $denied, $site_id ) {
				if ( 'other-site' === $denied && 'site' === $type ) {
					return (int) $cap === $site_id;
				}
				return $type === $denied ? false : $allowed;
			};
			$mode   = static function () use ( $multi ) {
				return ! $multi;
			};
			add_filter( 'mainwp_backup_private_root', $count, 99 );
			add_filter( 'mainwp_currentusercan', $rights, 10, 3 );
			add_filter( 'mainwp_is_single_user', $mode );
			if ( 'cron' === $shape ) {
				add_filter( 'wp_doing_cron', '__return_true' );
			}
			try {
				$this->assertSame(
					$expected,
					$this->request_download(
						array(
							'root'  => 'private',
							'mwpdl' => $paths[ $shape ] ?? $shape,
							'sig'   => $sig,
						)
					),
					$label
				);
				$this->assertSame( $expected_calls, $calls, $label );
				if ( '' === $role ) {
					$this->assertDirectoryDoesNotExist( $this->private_root );
				}
			} finally {
				remove_filter( 'mainwp_backup_private_root', $count, 99 );
				remove_filter( 'mainwp_currentusercan', $rights, 10 );
				remove_filter( 'mainwp_is_single_user', $mode );
				remove_filter( 'wp_doing_cron', '__return_true' );
			}
		}
	}
}
