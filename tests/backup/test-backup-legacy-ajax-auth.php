<?php
/**
 * MainWP Legacy Backup AJAX Authorization Tests
 *
 * The legacy backup file endpoints (download, delete, size checks) act on a
 * caller-supplied site id and local path. These tests pin the per-site
 * authorization the newer restore endpoints already enforce, and the path
 * bounds that keep a caller-supplied local path inside the dashboard backup
 * tree.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Legacy_Ajax_Auth_Test
 */
class MainWP_Backup_Legacy_Ajax_Auth_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Captured child calls: list of action names.
	 *
	 * @var string[]
	 */
	private $child_calls = array();

	/**
	 * Per-site backup directories created as a side effect of
	 * get_mainwp_specific_dir() during a test, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

	/**
	 * Reset request state and remove any per-site dir the containment
	 * checks created (get_mainwp_specific_dir mkdir's on every call).
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_POST['security'], $_POST['site_id'], $_POST['siteId'], $_POST['type'], $_POST['url'], $_POST['local'], $_POST['file'], $_REQUEST['security'] );

		foreach ( $this->fixture_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( trailingslashit( $dir ) . '*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
			}
		}
		$this->fixture_dirs = array();

		parent::tearDown();
	}

	/**
	 * The per-site backup directory for a site, tracked for cleanup.
	 *
	 * @param int $site_id Site id.
	 *
	 * @return string Per-site dir with trailing slash.
	 */
	private function site_backup_dir( $site_id ) {
		$dir                  = MainWP_System_Utility::get_mainwp_specific_dir( (string) $site_id );
		$this->fixture_dirs[] = untrailingslashit( $dir );
		return $dir;
	}

	/**
	 * Deny the per-site capability for one site id via the capability
	 * filter, simulating a Team Control site restriction.
	 *
	 * @param int $site_id Site id to deny.
	 *
	 * @return callable The filter, for later removal.
	 */
	private function deny_site( $site_id ) {
		$deny = function ( $allowed, $cap_type, $cap ) use ( $site_id ) {
			if ( 'site' === $cap_type && (int) $cap === (int) $site_id ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		return $deny;
	}

	/**
	 * Record every child call so a denied request can prove none happened.
	 *
	 * @return void
	 */
	private function capture_child_calls() {
		$this->child_calls = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) {
				unset( $website );
				$this->child_calls[] = $what;
				return array( 'ok' => 1 );
			},
			10,
			3
		);
	}

	/**
	 * Invoke a handler AJAX endpoint with the ajax-termination pattern and
	 * return the decoded JSON payload.
	 *
	 * @param string $action Nonce action / endpoint name.
	 * @param string $method Handler method to call.
	 * @param array  $post   POST fields.
	 *
	 * @return array|null Decoded response payload.
	 */
	private function call_endpoint( $action, $method, array $post ) {
		$nonce                = wp_create_nonce( $action );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		$throw = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		add_filter( 'wp_die_handler', $throw );

		$raw = '';
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->{$method}();
		} catch ( \RuntimeException $e ) {
			// The endpoint terminated the request as designed.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_handler', $throw );
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return json_decode( $raw, true );
	}

	/**
	 * A backup-capable user restricted from a site cannot download its
	 * backup files, and no directory is created for the requested target.
	 *
	 * @return void
	 */
	public function test_download_file_rejects_site_restricted_user() {
		$this->skip_when_capabilities_bypassed();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$deny    = $this->deny_site( $site_id );

		$dirs   = MainWP_System_Utility::get_mainwp_dir();
		$target = $dirs[0] . 'denied-user-test/backup.zip';

		$response = $this->call_endpoint(
			'mainwp_backup_download_file',
			'ajax_mainwp_backup_download_file',
			array(
				'site_id' => $site_id,
				'type'    => 'full',
				'url'     => 'http://127.0.0.1:1/never-fetched.zip',
				'local'   => $target,
			)
		);
		remove_filter( 'mainwp_currentusercan', $deny, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response, 'The denied request must produce an error payload.' );
		$this->assertDirectoryDoesNotExist( dirname( $target ), 'A denied request must not create the download directory.' );
	}

	/**
	 * A backup-capable user restricted from a site cannot delete its
	 * child-side backup files: the request errors before any child call.
	 *
	 * @return void
	 */
	public function test_delete_file_rejects_site_restricted_user_without_child_call() {
		$this->skip_when_capabilities_bypassed();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$this->capture_child_calls();
		$deny = $this->deny_site( $site_id );

		$response = $this->call_endpoint(
			'mainwp_backup_delete_file',
			'mainwp_backup_delete_file',
			array(
				'site_id' => $site_id,
				'file'    => 'backup.zip',
			)
		);
		remove_filter( 'mainwp_currentusercan', $deny, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response, 'The denied request must produce an error payload.' );
		$this->assertNotContains( 'delete_backup', $this->child_calls, 'A denied request must never reach the child site.' );
	}

	/**
	 * Even an authorized caller cannot point the download target outside
	 * the dashboard backup tree: the sink throws before creating anything.
	 * (Tested at the sink because the AJAX wrapper terminates with a plain
	 * die() that would kill the test process.)
	 *
	 * @return void
	 */
	public function test_download_file_rejects_local_path_outside_backup_root() {
		$site_id = $this->create_test_site();

		$target = sys_get_temp_dir() . '/mainwp-escape-test/backup.zip';

		$thrown = null;
		try {
			MainWP_Backup_File_Manager::backup_download_file( $site_id, 'full', 'http://127.0.0.1:1/never-fetched.zip', $target );
		} catch ( \Exception $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'An out-of-tree local path must be rejected.' );
		$this->assertStringContainsString( 'Invalid backup file location', $thrown->getMessage() );
		$this->assertDirectoryDoesNotExist( dirname( $target ), 'A rejected request must not create the escaped directory.' );
	}

	/**
	 * An authorized caller cannot point the download target into a sibling
	 * site's backup directory either: the sink is site-scoped, so a
	 * tampered `local` can neither write bytes into nor sweep another
	 * site's archive set. (Tested at the sink because the AJAX wrapper
	 * terminates with a plain die() that would kill the test process.)
	 *
	 * @return void
	 */
	public function test_download_file_rejects_sibling_site_local_path() {
		$site_a = $this->create_test_site();
		$site_b = $this->create_test_site();

		$dir_b    = $this->site_backup_dir( $site_b );
		$existing = $dir_b . 'full-existing-backup.tar.gz';
		file_put_contents( $existing, 'sibling artifact' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$target = $dir_b . 'full-injected.tar.gz';

		$thrown = null;
		try {
			MainWP_Backup_File_Manager::backup_download_file( $site_a, 'full', 'http://127.0.0.1:1/never-fetched.zip', $target );
		} catch ( \Exception $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'A sibling site download target must be rejected.' );
		$this->assertStringContainsString( 'Invalid backup file location', $thrown->getMessage() );
		$this->assertFileExists( $existing, 'The sibling site archive must not be swept.' );
		$this->assertFileDoesNotExist( $target, 'No bytes may land in the sibling site directory.' );
	}

	/**
	 * The path-bound helper accepts the backup tree and rejects substring
	 * lookalikes and traversal escapes.
	 *
	 * @return void
	 */
	public function test_is_within_backup_root_bounds() {
		$dirs = MainWP_System_Utility::get_mainwp_dir();
		$root = $dirs[0];

		$this->assertTrue( MainWP_Backup_File_Manager::is_within_backup_root( $root . 'site/backup.zip' ) );
		$this->assertFalse( MainWP_Backup_File_Manager::is_within_backup_root( '' ) );
		$this->assertFalse( MainWP_Backup_File_Manager::is_within_backup_root( sys_get_temp_dir() . '/evil' . $root . 'backup.zip' ), 'The root appearing later in a hostile path must not qualify.' );
		$this->assertFalse( MainWP_Backup_File_Manager::is_within_backup_root( $root . '../../wp-config.php' ), 'A traversal after a valid root must not escape it.' );
	}

	/**
	 * The size probe refuses paths that escape the backup tree via
	 * traversal, even when the file exists.
	 *
	 * @return void
	 */
	public function test_backup_get_file_size_refuses_traversal_escape() {
		$dirs = MainWP_System_Utility::get_mainwp_dir();
		$root = $dirs[0];

		$upload  = wp_upload_dir();
		$outside = trailingslashit( $upload['basedir'] ) . 'mainwp-escaped-probe.txt';
		file_put_contents( $outside, 'outside' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		try {
			$this->assertSame( 0, MainWP_Backup_File_Manager::backup_get_file_size( $root . '../mainwp-escaped-probe.txt' ), 'A traversal path must never leak a size outside the tree.' );

			$inside = $root . 'size-probe.txt';
			file_put_contents( $inside, '12345' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$this->assertSame( 5, MainWP_Backup_File_Manager::backup_get_file_size( $inside ), 'A file inside the tree must report its size.' );
			unlink( $inside ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		} finally {
			unlink( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * A backup-capable but site-restricted user cannot probe file sizes for
	 * a site they cannot manage: the request errors and leaks no size. This
	 * is the concrete MainWP Team Control scenario - a sub-admin scoped away
	 * from the site by the `mainwp_current_user_can('site', id)` gate.
	 *
	 * @return void
	 */
	public function test_getfilesize_rejects_site_restricted_user() {
		$this->skip_when_capabilities_bypassed();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$deny    = $this->deny_site( $site_id );

		$dir      = $this->site_backup_dir( $site_id );
		$response = $this->call_endpoint(
			'mainwp_backup_getfilesize',
			'mainwp_backup_getfilesize',
			array(
				'site_id' => $site_id,
				'local'   => $dir . 'full-backup.zip',
			)
		);
		remove_filter( 'mainwp_currentusercan', $deny, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response, 'The denied request must produce an error payload.' );
		$this->assertArrayNotHasKey( 'result', $response, 'A denied request must not leak a size.' );
	}

	/**
	 * The per-site containment helper accepts the site's own backup dir and
	 * rejects another site's dir, a traversal escape, and a zero site id.
	 *
	 * @return void
	 */
	public function test_is_within_site_backup_dir_bounds() {
		$site_a = $this->create_test_site();
		$site_b = $this->create_test_site();

		$dir_a = $this->site_backup_dir( $site_a );
		$dir_b = $this->site_backup_dir( $site_b );

		$this->assertTrue( MainWP_Backup_File_Manager::is_within_site_backup_dir( $dir_a . 'full-backup.zip', $site_a ), 'A path in the site own dir must pass.' );
		$this->assertFalse( MainWP_Backup_File_Manager::is_within_site_backup_dir( $dir_b . 'full-backup.zip', $site_a ), 'Another site dir must not pass for this site.' );
		$this->assertFalse( MainWP_Backup_File_Manager::is_within_site_backup_dir( $dir_a . '../' . $site_b . '/full-backup.zip', $site_a ), 'A traversal into a sibling site must not escape.' );
		$this->assertFalse( MainWP_Backup_File_Manager::is_within_site_backup_dir( $dir_a . 'full-backup.zip', 0 ), 'A zero site id must never confine.' );
	}
}
