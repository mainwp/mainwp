<?php
/**
 * User imports retain upload intent and target authorization.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\Import_Authorization_Post_Handler;

/** Exit interception keeps the production AJAX response available to assertions. */
class Import_Authorization_Stop extends \Error {}

/** Exercise upload intent and target selection through the production handlers. */
class Test_Users_Import_Authorization extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Loopback server process.
	 *
	 * @var resource|null
	 */
	private static $server;
	/**
	 * Loopback base URL.
	 *
	 * @var string
	 */
	private static $url;
	/**
	 * Fixture request log path.
	 *
	 * @var string
	 */
	private static $log;
	/**
	 * Server diagnostics path.
	 *
	 * @var string
	 */
	private static $server_log;
	/**
	 * Ephemeral signing key for fixture sites.
	 *
	 * @var string
	 */
	private static $key;
	/**
	 * Test-owned permission filters.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Load termination-aware handlers and start the loopback transport.
	 *
	 * @throws \RuntimeException When the loopback server cannot start.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$names = array(
			'MainWP_User'         => 'Import_Authorization_User',
			'MainWP_Post_Handler' => 'Import_Authorization_Post_Handler',
		);
		foreach ( array( 'pages/page-mainwp-user.php', 'class/class-mainwp-post-handler.php' ) as $file ) {
			$code = '';
			foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
				$code .= is_array( $token ) ? ( T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] ) ) : $token;
			}
			eval( '?>' . str_replace( '\\wp_die;', '\\wp_die();', $code ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Intercept termination without replacing authorization, lookup or dispatch.
		}
		$socket  = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
		$address = stream_socket_get_name( $socket, false );
		fclose( $socket );
		self::$url                        = 'http://' . $address;
		self::$log                        = tempnam( sys_get_temp_dir(), 'users-import-requests-' );
		self::$server_log                 = tempnam( sys_get_temp_dir(), 'users-import-server-' );
		$env                              = getenv();
		$env['MAINWP_IMPORT_HTTP_TEST']   = '1';
		$env['MAINWP_IMPORT_REQUEST_LOG'] = self::$log;
		$env['WP_TESTS_CONFIG_FILE_PATH'] = defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ? WP_TESTS_CONFIG_FILE_PATH : getenv( 'WP_TESTS_CONFIG_FILE_PATH' );
		self::$server                     = proc_open(
			array( PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/users-import-http.php' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'file', self::$server_log, 'a' ),
				2 => array( 'file', self::$server_log, 'a' ),
			),
			$pipes,
			dirname( __DIR__ ),
			$env
		);
		fclose( $pipes[0] );
		$ready = false;
		for ( $i = 0; $i < 40; ++$i ) {
			$curl = curl_init( self::$url . '/health' );
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
			$ready = 'ready' === curl_exec( $curl );
			curl_close( $curl );
			if ( $ready ) {
				break;
			}
			usleep( 50000 );
		}
		if ( ! $ready ) {
			throw new \RuntimeException( 'User-import loopback server did not start.' );
		}
		openssl_pkey_export( openssl_pkey_new( array( 'private_key_bits' => 2048 ) ), self::$key );
	}

	/** Stop the transport and remove its temporary files. */
	public static function tearDownAfterClass(): void {
		if ( is_resource( self::$server ) ) {
			proc_terminate( self::$server );
			proc_close( self::$server );
		}
		foreach ( array( self::$log, self::$server_log ) as $file ) {
			if ( $file && file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDownAfterClass();
	}

	/** Use an administrator and clear the fixture request log. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		file_put_contents( self::$log, '' );
	}

	/** Restore permission filters and request state. */
	public function tearDown(): void {
		foreach ( $this->filters as $hook => $callback ) {
			remove_filter( $hook, $callback, 999 );
		}
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Nonce shapes include the valid value emitted by the upload form. */
	public static function upload_nonces(): array {
		return array(
			'missing'    => array( 'missing', false ),
			'wrong'      => array( 'wrong', false ),
			'array'      => array( 'array', false ),
			'valid form' => array( 'valid', true ),
		);
	}

	/**
	 * Real multipart requests retain valid imports and reject missing intent.
	 *
	 * @dataProvider upload_nonces
	 * @param string $nonce_kind Submitted nonce shape.
	 * @param bool   $accepted Whether the upload should produce import rows.
	 */
	public function test_real_multipart_upload_requires_the_form_nonce( $nonce_kind, $accepted ): void {
		$curl = curl_init( self::$url . '/upload' );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		$form = curl_exec( $curl );
		$this->assertSame( 200, curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ), 'Upload form request failed.' );
		$this->assertSame( 1, preg_match( '/name="_wpnonce"[^>]*value="([^"]+)"/', $form, $match ), 'Upload form nonce is missing.' );
		$csv = tempnam( sys_get_temp_dir(), 'users-import-csv-' );
		file_put_contents( $csv, 'fixture,fixture@example.test,First,Last,,fixture-password,0,administrator,http://example.test/,tag' );
		$post = array( 'import_user_file_bulkupload' => new \CURLFile( $csv, 'text/csv', 'users.csv' ) );
		if ( 'missing' !== $nonce_kind ) {
			$post[ 'array' === $nonce_kind ? '_wpnonce[]' : '_wpnonce' ] = 'valid' === $nonce_kind ? $match[1] : 'invalid';
		}
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $post );
		try {
			$html = curl_exec( $curl );
		} finally {
			unlink( $csv );
		}
		$this->assertSame( 200, curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ), 'Upload request failed.' );
		curl_close( $curl );
		$this->assertSame( $accepted, str_contains( $html, 'id="import_user_do_import" value="1"' ), 'Unexpected automatic import state.' );
		$this->assertSame( $accepted, str_contains( $html, 'id="user_import_csv_line_1"' ), 'Unexpected parsed import rows.' );
		if ( ! $accepted ) {
			$this->assertStringContainsString( 'File could not be uploaded. Please try again.', $html );
		}
		$this->assertSame( '', file_get_contents( self::$log ) );
	}

	/** Per-user ownership rejects a foreign target while retaining an owned target. */
	public function test_other_owner_url_is_rejected_and_own_site_still_imports(): void {
		$this->filter( 'mainwp_is_single_user', '__return_false' );
		$own   = $this->site( 'own' );
		$other = $this->site( 'other', self::factory()->user->create() );
		$this->assertSame( 1, count( MainWP_DB::instance()->get_websites_by_url( $other->url ) ) );
		$response = $this->dispatch(
			array(
				'select_by'    => 'site',
				'select_sites' => array( $own->url, $other->url ),
			)
		);
		$this->assert_result( $response, $own, $other );
	}

	/** Both import selection modes must enforce staff site grants. */
	public static function staff_targets(): array {
		return array( array( 'site' ), array( 'group' ) );
	}

	/**
	 * Staff can import only to the sites granted by the permission filter.
	 *
	 * @dataProvider staff_targets
	 * @param string $select_by Site or group selection.
	 */
	public function test_level_ten_staff_site_policy_applies_to_url_and_group_imports( $select_by ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		wp_get_current_user()->add_cap( 'level_10' );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertTrue( MainWP_System_Utility::is_admin() );
		$allowed    = $this->site( 'allowed' );
		$restricted = $this->site( 'restricted' );
		$this->filter(
			'mainwp_currentusercan',
			static function ( $can, $type, $id ) use ( $allowed ) {
				return 'site' === $type ? (int) $id === (int) $allowed->id : $can;
			}
		);
		$post = array(
			'select_by'    => $select_by,
			'select_sites' => array( $allowed->url, $restricted->url ),
		);
		if ( 'group' === $select_by ) {
			global $wpdb;
			$wpdb->insert(
				$wpdb->prefix . 'mainwp_group',
				array(
					'userid' => get_current_user_id(),
					'name'   => 'Import scope',
				)
			);
			$group = $wpdb->insert_id;
			foreach ( array( $allowed, $restricted ) as $site ) {
				$wpdb->insert(
					$wpdb->prefix . 'mainwp_wp_group',
					array(
						'wpid'    => $site->id,
						'groupid' => $group,
					)
				);
			}
			$post['select_groups'] = array( 'Import scope' );
		}
		$this->assert_result( $this->dispatch( $post ), $allowed, $restricted );
	}

	/** Create a connected fixture site with a usable signing key.
	 *
	 * @param string   $name Fixture site name.
	 * @param int|null $owner Dashboard owner, or the current user.
	 * @return object Fixture site.
	 */
	private function site( $name, $owner = null ) {
		$id = $this->create_test_site(
			array(
				'userid'  => $owner ?? get_current_user_id(),
				'name'    => $name,
				'url'     => self::$url . '/child/' . $name . '/',
				'privkey' => base64_encode( self::$key ),
			)
		);
		return MainWP_DB::instance()->get_website_by_id( $id );
	}

	/** Track a test-owned permission filter for cleanup.
	 *
	 * @param string   $hook Filter name.
	 * @param callable $callback Test policy.
	 */
	private function filter( $hook, $callback ): void {
		$this->filters[ $hook ] = $callback;
		add_filter( $hook, $callback, 999, 3 );
	}

	/** Keep AJAX termination inside the test process. */
	public function die_handler(): callable {
		return static function ( $message ) {
			throw new Import_Authorization_Stop( (string) $message );
		};
	}

	/** Submit an import through the production AJAX handler.
	 *
	 * @param array $targets Target selection fields.
	 * @return array Import response.
	 */
	private function dispatch( $targets ): array {
		$_POST    = array_merge(
			array(
				'security'   => wp_create_nonce( 'mainwp_importuser' ),
				'user_login' => 'fixture-user',
				'email'      => 'fixture@example.test',
				'pass1'      => 'fixture-password',
				'role'       => 'administrator',
			),
			$targets
		);
		$_REQUEST = $_POST;
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		ob_start();
		try {
			Import_Authorization_Post_Handler::instance()->mainwp_importuser();
		} catch ( Import_Authorization_Stop $stop ) {
			echo $stop->getMessage();
		} finally {
			$body = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		}
			$response = json_decode( $body, true );
			$this->assertIsArray( $response, $body );
			return $response;
	}

	/** The allowed target succeeds and the rejected target is never dispatched.
	 *
	 * @param array  $response Import response.
	 * @param object $allowed Permitted fixture site.
	 * @param object $rejected Denied fixture site.
	 */
	private function assert_result( $response, $allowed, $rejected ): void {
		$this->assertSame( array( 'New user(s) created: ' . $allowed->name ), $response['ok_list'] );
		$this->assertCount( 1, $response['error_list'] );
		$this->assertStringContainsString( 'insufficient permissions', $response['error_list'][0] );
		$this->assertStringContainsString( $rejected->url, $response['error_list'][0] );
		$this->assertStringContainsString( $rejected->url, $response['failed_logging'] );
		$this->assertStringNotContainsString( 'fixture-password', $response['failed_logging'] );
		$this->assertSame( '/child/' . $allowed->name . '/wp-admin/admin-ajax.php' . "\n", file_get_contents( self::$log ) );
	}
}
