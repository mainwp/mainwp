<?php
/**
 * Child archive cleanup requires a backup right, site access and a bare name.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Handler;
use MainWP\Dashboard\Backup_Delete_Gate_Post_Handler;

/** AJAX termination must escape the handler's Exception catch block. */
class Backup_Delete_Gate_Response_Stop extends \Error {}

/** Refused cleanup must never dispatch a deletion to the Child. */
class MainWP_Backup_Delete_File_Gate_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Test-owned callbacks.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Requests observed by the HTTP mock.
	 *
	 * @var array Requests observed by the HTTP mock.
	 */
	private $requests = array();

	/** Raw exits would terminate PHPUnit before it could inspect the request. */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$code   = '';
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/class/class-mainwp-post-backup-handler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local source to capture legacy exits.
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				$code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && 'MainWP_Post_Backup_Handler' === $token[1] ? 'Backup_Delete_Gate_Post_Handler' : $token[1] );
			} else {
				$code .= $token;
			}
		}
		$code = str_replace( '\\wp_die;', '\\wp_die();', $code );
		// nosemgrep: AIK_eval-use -- Evaluates this repo's own handler source with exits rewritten; no request or user input reaches it.
		eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture legacy exits while retaining the source gate and Child dispatch.
	}

	/** The Child test hook avoids real cURL traffic and exposes dispatch inputs. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->filter( 'mainwp_currentusercan', '__return_true' );
		$this->filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array(
					'url'  => $url,
					'body' => $args['body'],
					'dts'  => get_option( 'mainwp_ajaxposts' )['mainwp_backup_delete_file'] ?? null,
				);
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'result' => true ) ),
					'cookies'  => array(),
				);
			}
		);
		$this->filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $pre, $website, $what, $params ) {
				// Production uses cURL, so the test hook bridges dispatch to the WordPress HTTP observer.
				$response = wp_remote_post( $website->url, array( 'body' => array_merge( array( 'function' => $what ), $params ) ) );
				return json_decode( wp_remote_retrieve_body( $response ), true );
			}
		);
	}

	/** Keep callbacks from other suites and request globals intact. */
	public function tearDown(): void {
		foreach ( $this->filters as $hook => $callback ) {
			remove_filter( $hook, $callback, 999 );
		}
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Independent backup and site rights.
	 *
	 * @return array Independent backup and site rights.
	 */
	public static function rights(): array {
		return array(
			'no backup right' => array( false, false, true ),
			'out of scope'    => array( true, true, false ),
			'task only'       => array( false, true, true ),
			'on demand only'  => array( true, false, true ),
		);
	}

	/**
	 * The native branch requires execute_backups and access to the site.
	 *
	 * @dataProvider rights
	 * @param bool $execute On-demand right.
	 * @param bool $task Task-run right.
	 * @param bool $scope Site right.
	 */
	public function test_delete_rights( bool $execute, bool $task, bool $scope ): void {
		$site = $this->create_test_site();
		$this->filter(
			'mainwp_currentusercan',
			static function ( $can, $type, $cap ) use ( $execute, $task, $scope, $site ) {
				if ( 'dashboard' === $type && 'execute_backups' === $cap ) {
					return $execute;
				}
				if ( 'dashboard' === $type && 'run_backup_tasks' === $cap ) {
					return $task;
				}
				return 'site' === $type && $site === (int) $cap ? $scope : $can;
			}
		);
		$name     = 'backup-K7Q2M9XA-1760000000.zip';
		$response = $this->dispatch( $site, $name );
		if ( $execute && $scope ) {
			$this->assertSame( array( 'result' => true ), $response );
			$this->assert_request( $name );
		} else {
			$this->assertCount( 0, $this->requests );
			$this->assertNotEmpty( $response['error'] );
		}
	}

	/**
	 * Paths and non-archive names.
	 *
	 * @return array Paths and non-archive names.
	 */
	public static function bad_names(): array {
		return array(
			'empty'             => array( '' ),
			'traversal'         => array( '../x.zip' ),
			'embedded dot pair' => array( 'backup..zip' ),
			'path'              => array( 'folder/x.zip' ),
			'absolute path'     => array( '/tmp/x.zip' ),
			'backslash'         => array( 'folder\\x.zip' ),
			'NUL'               => array( "x\0.zip" ),
			'disguised PHP'     => array( 'x.zip.php' ),
			'PHP file'          => array( 'wp-config.php' ),
			'unanchored suffix' => array( "x.zip\n" ),
			'PID control file'  => array( 'backup-x.zip.pid' ),
			'done control file' => array( 'x.done' ),
			'index file'        => array( 'index.php' ),
			'htaccess'          => array( '.htaccess' ),
			'raw database'      => array( 'dbBackup-x.sql' ),
			'no extension'      => array( 'backup-x' ),
			'array'             => array( array( 'x.zip' ) ),
			// The Child sanitizes the name before deleting, so each of these would reach it as a different file.
			'tag hides suffix'  => array( 'backup-123.pid<!--x>.zip' ),
			'unclosed tag'      => array( 'backup-123.pid<x.zip' ),
			'percent octet'     => array( 'backup%2d1.zip' ),
			'leading space'     => array( ' x.zip' ),
			'double space'      => array( 'x  y.zip' ),
			'tab'               => array( "x\t.zip" ),
			'invalid UTF-8'     => array( "x\xff.zip" ),
		);
	}

	/**
	 * Invalid names must never dispatch a Child deletion from AJAX.
	 *
	 * @dataProvider bad_names
	 * @param mixed $name Invalid archive name.
	 */
	public function test_ajax_refuses_bad_name_before_child( $name ): void {
		$response = $this->dispatch( $this->create_test_site(), $name );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'Invalid backup archive name.', $response['error']['message'] );
	}

	/**
	 * Direct callers must not bypass archive-name validation.
	 *
	 * @dataProvider bad_names
	 * @param mixed $name Invalid archive name.
	 */
	public function test_executor_delete_refuses_bad_name_before_child( $name ): void {
		$site = $this->create_test_site();
		try {
			MainWP_Backup_Executor::backup_delete_file( $site, $name );
			$this->fail( 'Expected an invalid archive name to throw.' );
		} catch ( \MainWP\Dashboard\MainWP_Exception $exception ) {
			$this->assertSame( 'Invalid backup archive name.', $exception->getMessage() );
		}
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * The deprecated entry point must retain the executor's refusal.
	 *
	 * @dataProvider bad_names
	 * @param mixed $name Invalid archive name.
	 */
	public function test_deprecated_delete_refuses_bad_name_before_child( $name ): void {
		$site = $this->create_test_site();
		$this->setExpectedDeprecated( 'MainWP\Dashboard\MainWP_Backup_Handler::backup_delete_file' );
		try {
			MainWP_Backup_Handler::backup_delete_file( $site, $name );
			$this->fail( 'Expected an invalid archive name to throw.' );
		} catch ( \MainWP\Dashboard\MainWP_Exception $exception ) {
			$this->assertSame( 'Invalid backup archive name.', $exception->getMessage() );
		}
		$this->assertCount( 0, $this->requests );
	}

	/** A missing site must not dispatch even when the archive name is valid. */
	public function test_ajax_refuses_missing_site_before_child(): void {
		$response = $this->dispatch( 0, 'backup-K7Q2M9XA-1760000000.zip' );
		$this->assertSame( 'Site ID not found. Please reload the page and try again.', $response['error']['message'] );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Every Child archive form and supported extension.
	 *
	 * @return array Every Child archive form and supported extension.
	 */
	public static function child_names(): array {
		$names = array();
		foreach ( array( 'zip', 'tar', 'tar.gz', 'tar.bz2' ) as $ext ) {
			foreach ( array( 'backup-K7Q2M9XA-1760000000', 'backup-1760000000', 'Café_東京 12-~,:;[x](y).example', 'resumed.archive', 'dbBackup-K7Q2M9XA-1760000000.sql', 'dbBackup-1760000000.sql' ) as $base ) {
				$names[ $base . '.' . $ext ] = array( $base . '.' . $ext );
			}
		}
		return $names;
	}

	/**
	 * Valid Child names must survive cleanup without a prefix restriction.
	 *
	 * @dataProvider child_names
	 * @param string $name Child archive basename.
	 */
	public function test_child_archive_name_reaches_child_unchanged( string $name ): void {
		$response = $this->dispatch( $this->create_test_site(), $name );
		$this->assertSame( array( 'result' => true ), $response );
		$this->assert_request( $name );
	}

	/**
	 * Assert the exact deletion payload observed at Child dispatch.
	 *
	 * @param string $name Expected Child archive name.
	 */
	private function assert_request( string $name ): void {
		$this->assertCount( 1, $this->requests );
		$this->assertSame(
			array(
				'function' => 'delete_backup',
				'del'      => $name,
			),
			$this->requests[0]['body']
		);
	}

	/**
	 * Replace only callbacks owned by this test.
	 *
	 * @param string   $hook Filter.
	 * @param callable $callback Callback.
	 */
	private function filter( string $hook, callable $callback ): void {
		if ( isset( $this->filters[ $hook ] ) ) {
			remove_filter( $hook, $this->filters[ $hook ], 999 );
		}
		$this->filters[ $hook ] = $callback;
		add_filter( $hook, $callback, 999, 5 );
	}

	/**
	 * Capture the source AJAX response without terminating the suite.
	 *
	 * @param int   $site Site ID.
	 * @param mixed $name File name.
	 * @return array JSON response.
	 */
	private function dispatch( int $site, $name ): array {
		$before   = get_option( 'mainwp_ajaxposts' );
		$dts      = wp_generate_uuid4();
		$_POST    = array(
			'security' => wp_create_nonce( 'mainwp_backup_delete_file' ),
			'site_id'  => $site,
			'file'     => wp_slash( $name ),
			'dts'      => $dts,
		);
		$_REQUEST = $_POST;
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
		ob_start();
		$body = '';
		try {
			Backup_Delete_Gate_Post_Handler::instance()->mainwp_backup_delete_file();
		} catch ( Backup_Delete_Gate_Response_Stop $exception ) {
			$body = $exception->getMessage();
		} finally {
			$buffer = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		$response = json_decode( $buffer . $body, true );
		$this->assertIsArray( $response, $buffer . $body );
		if ( ! empty( $response['error'] ) ) {
			$this->assertSame( $before, get_option( 'mainwp_ajaxposts' ), 'Refusal recorded a duplicate-request marker.' );
		} else {
			$this->assertSame( $dts, $this->requests[0]['dts'] ?? null, 'An accepted request must record its marker before Child dispatch.' );
		}
		return $response;
	}

	/**
	 * AJAX termination callback.
	 *
	 * @return callable AJAX termination callback.
	 */
	public function ajax_die_handler(): callable {
		return static function ( $message ) {
			throw new Backup_Delete_Gate_Response_Stop( (string) $message );
		};
	}
}
