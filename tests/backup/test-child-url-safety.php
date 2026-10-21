<?php
/**
 * Compromised Children must not select internal icon or cron destinations.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Sync;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;
use MainWP\Dashboard\MainWP_System_Utility;

/** Fixture requests exercise both sync transports without replacing cURL. */
class MainWP_Child_URL_Safety_Test extends MainWP_Backup_Test_Case {

	/** Stored Child fixture.
	 *
	 * @var object
	 */
	private $website;

	/** Files owned by this test.
	 *
	 * @var string[]
	 */
	private $files = array();

	/** Real signatures keep the batch request on its production path. */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
		openssl_pkey_export( $key, $private_key );
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp' ),
				array(
					'userid'     => get_current_user_id(),
					'url'        => static::fixture_url( '/' ),
					'siteurl'    => static::fixture_url( '/' ),
					'name'       => 'Child URL fixture',
					'adminname'  => 'admin',
					'privkey'    => base64_encode( $private_key ),
					'is_staging' => 0,
				)
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->assertNotFalse( $wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'wpid' => $id ) ) );
		$this->website = MainWP_DB::instance()->get_website_by_id( $id );
		add_filter( 'mainwp_fetch_url_site_timeout', array( $this, 'short_timeout' ) );
		delete_option( 'mainwp_cron_last_ping' );
	}

	/** Failed assertions must not leak an icon or a daily cron gate. */
	public function tear_down() {
		remove_filter( 'mainwp_fetch_url_site_timeout', array( $this, 'short_timeout' ) );
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		delete_option( 'mainwp_cron_last_ping' );
		parent::tear_down();
	}

	/**
	 * Bound fixture requests cannot stall the test process.
	 *
	 * @return int Seconds.
	 */
	public function short_timeout() {
		return 5;
	}

	/**
	 * Both transports must preserve the same redirect boundary.
	 *
	 * @return array Transport cases.
	 */
	public static function transports() {
		return array(
			'single' => array( 'single' ),
			'batch'  => array( 'batch' ),
		);
	}

	/**
	 * Parse only actual Child envelopes returned by the fixture.
	 *
	 * @param string $transport Single or batch.
	 * @param string $route Fixture route.
	 * @return mixed Child result, or false on failure.
	 */
	private function sync_request( $transport, $route ) {
		if ( 'single' === $transport ) {
			try {
				return MainWP_Connect::fetch_url_site( $this->website, static::fixture_url( $route ), array() );
			} catch ( MainWP_Exception $error ) {
				return false;
			}
		}
		$sites  = array( $this->website );
		$output = false;
		MainWP_Connect::fetch_urls_authed(
			$sites,
			'sync',
			array(),
			static function ( $body, $site, &$result ) {
				$result = preg_match( '~<mainwp>(.*)</mainwp>~', (string) $body, $matches ) ? json_decode( base64_decode( $matches[1] ), true ) : false;
			},
			$output,
			ltrim( $route, '/' )
		);
		return $output;
	}

	/**
	 * The old cURL defaults allow FTP, so an accepted TCP connection exposes the regression.
	 *
	 * @dataProvider transports
	 * @param string $transport Single or batch.
	 */
	public function test_sync_refuses_ftp_redirect_without_contact( $transport ) {
		$ready         = $this->temp_download_path();
		$marker        = $this->temp_download_path();
		$this->files[] = $ready;
		$this->files[] = $marker;
		$process       = proc_open(
			// nosemgrep: AIK_exec-use -- test harness; the argv array bypasses the shell and every element is a test constant or fixture value.
			array( PHP_BINARY, __DIR__ . '/fixtures/ftp-reject.php', $ready, $marker ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', '/dev/null', 'w' ),
				2 => array( 'file', '/dev/null', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		try {
			$deadline = microtime( true ) + 3;
			while ( ! file_exists( $ready ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
				clearstatcache( true, $ready );
			}
			$this->assertFileExists( $ready, 'The FTP fixture did not start.' );
			$port = (string) substr( (string) strrchr( (string) file_get_contents( $ready ), ':' ), 1 );
			$this->assertMatchesRegularExpression( '/^\d+$/', $port, 'The FTP fixture did not publish its port.' );
			$result = $this->sync_request( $transport, '/sync-ftp?port=' . $port );
			$this->assertFileDoesNotExist( $marker, 'A non-HTTP redirect reached the FTP listener.' );
			$this->assertFalse( $result );
		} finally {
			proc_terminate( $process );
			proc_close( $process );
		}
	}

	/**
	 * Three redirects retain the Child response.
	 *
	 * @dataProvider transports
	 * @param string $transport Single or batch.
	 */
	public function test_sync_three_redirects_complete( $transport ) {
		$this->assertSame( array( 'child_url_fixture' => 'ok' ), $this->sync_request( $transport, '/sync-chain?hops=3' ) );
	}

	/**
	 * A fourth redirect exhausts the allowed budget.
	 *
	 * @dataProvider transports
	 * @param string $transport Single or batch.
	 */
	public function test_sync_fourth_redirect_is_refused( $transport ) {
		$this->assertFalse( $this->sync_request( $transport, '/sync-chain?hops=4' ) );
	}

	/**
	 * A permanent same-host move still returns a valid Child envelope.
	 *
	 * @dataProvider transports
	 * @param string $transport Single or batch.
	 */
	public function test_sync_same_host_301_completes( $transport ) {
		$this->assertSame( array( 'child_url_fixture' => 'ok' ), $this->sync_request( $transport, '/sync-301' ) );
	}

	/**
	 * Injected answers separate address policy from DNS availability.
	 *
	 * @dataProvider icon_rules
	 * @param string $site Stored site URL.
	 * @param string $icon Reported icon URL.
	 * @param array  $addresses DNS answers.
	 * @param mixed  $expected Resolve pins or false.
	 */
	public function test_icon_destination_rule( $site, $icon, $addresses, $expected ) {
		$this->assertSame( $expected, MainWP_Sync::child_icon_resolve( $site, $icon, $addresses ) );
	}

	/**
	 * Other hosts need globally routable IPv4 addresses to exclude internal read targets.
	 *
	 * @return array URL and address cases.
	 */
	public static function icon_rules() {
		$site = 'https://child.example/';
		return array(
			'same host'          => array( $site, 'http://child.example/icon.png', array( '10.0.0.5' ), array() ),
			'www alias'          => array( $site, 'https://WWW.CHILD.EXAMPLE/icon.png', array(), array() ),
			'same explicit port' => array( 'http://child.example:8080/', 'http://child.example:8080/icon.png', array(), array() ),
			'absent icon port'   => array( 'http://child.example:8080/', 'http://child.example/icon.png', array(), array() ),
			'other private port' => array( $site, 'https://child.example:8443/icon.png', array( '10.0.0.5' ), false ),
			'other public port'  => array( $site, 'https://child.example:8443/icon.png', array( '8.8.8.8' ), array( 'child.example:8443:8.8.8.8' ) ),
			'private'            => array( $site, 'https://cdn.example/icon.png', array( '10.0.0.5' ), false ),
			'loopback'           => array( $site, 'https://cdn.example/icon.png', array( '127.0.0.1' ), false ),
			'link local'         => array( $site, 'https://cdn.example/icon.png', array( '169.254.169.254' ), false ),
			'CGNAT'              => array( $site, 'https://cdn.example/icon.png', array( '100.64.0.1' ), false ),
			'CGNAT metadata'     => array( $site, 'http://100.100.100.200/icon.png', array(), false ),
			'below CGNAT'        => array( $site, 'https://cdn.example/icon.png', array( '100.63.255.255' ), array( 'cdn.example:443:100.63.255.255' ) ),
			'documentation'      => array( $site, 'https://cdn.example/icon.png', array( '192.0.2.1' ), false ),
			'reserved'           => array( $site, 'https://cdn.example/icon.png', array( '240.0.0.1' ), false ),
			'public'             => array( $site, 'https://cdn.example/icon.png', array( '8.8.8.8', '1.1.1.1' ), array( 'cdn.example:443:8.8.8.8' ) ),
			'mixed answers'      => array( $site, 'https://cdn.example/icon.png', array( '8.8.8.8', '10.0.0.5' ), false ),
			'public HTTP'        => array( $site, 'http://cdn.example/icon.png', array( '8.8.8.8' ), array( 'cdn.example:80:8.8.8.8' ) ),
			'IPv4 loopback'      => array( $site, 'http://127.0.0.1/icon.png', array( '8.8.8.8' ), false ),
			'IPv4 metadata'      => array( $site, 'http://169.254.169.254/icon.png', array(), false ),
			'IPv4 public'        => array( $site, 'http://8.8.8.8/icon.png', array(), array() ),
			'IPv6 loopback'      => array( $site, 'http://[::1]/icon.png', array(), false ),
			'IPv6 public'        => array( $site, 'https://[2606:4700:4700::1111]/icon.png', array(), false ),
			'NAT64'              => array( $site, 'http://[64:ff9b::a00:5]/icon.png', array(), false ),
			'6to4'               => array( $site, 'http://[2002:7f00:1::1]/icon.png', array(), false ),
			'local Child'        => array( 'http://127.0.0.1/', 'http://127.0.0.1/icon.png', array(), array() ),
			'FTP'                => array( $site, 'ftp://child.example/icon.png', array( '8.8.8.8' ), false ),
			'userinfo'           => array( $site, 'https://user:password@child.example/icon.png', array(), false ),
			'empty userinfo'     => array( $site, 'https://@child.example/icon.png', array(), false ),
			'unresolvable'       => array( $site, 'https://cdn.example/icon.png', array(), false ),
			'no host'            => array( $site, '/icon.png', array(), false ),
			'unicode host'       => array( $site, 'http://bücher.example/icon.png', array( '8.8.8.8' ), false ),
			'punycode host'      => array( $site, 'http://xn--bcher-kva.example/icon.png', array( '8.8.8.8' ), array( 'xn--bcher-kva.example:80:8.8.8.8' ) ),
			'leading hyphen'     => array( $site, 'http://-rb.example/icon.png', array( '8.8.8.8' ), false ),
			'trailing hyphen'    => array( $site, 'http://rb-.example/icon.png', array( '8.8.8.8' ), false ),
			'underscore host'    => array( $site, 'http://a_b.example/icon.png', array( '8.8.8.8' ), false ),
			'trailing dot'       => array( $site, 'http://cdn.example./icon.png', array( '8.8.8.8' ), array( 'cdn.example.:80:8.8.8.8' ) ),
			'decimal literal'    => array( $site, 'http://2130706433/icon.png', array( '127.0.0.1' ), false ),
			'NUL same host'      => array( $site, "https://child.example/icon.png\0", array(), false ),
			'NUL other host'     => array( $site, "https://cdn.example\0/icon.png", array( '8.8.8.8' ), false ),
			'space'              => array( $site, 'https://child.example/my icon.png', array(), false ),
			'tab'                => array( $site, "https://child.example/\ticon.png", array(), false ),
			'DEL'                => array( $site, "https://child.example/\x7ficon.png", array(), false ),
			'empty'              => array( $site, '', array(), false ),
			'non-string answer'  => array( $site, 'https://cdn.example/icon.png', array( 8 ), false ),
		);
	}

	/**
	 * Malformed Child values must not abort the update-check loop.
	 *
	 * @dataProvider invalid_icon_values
	 * @param mixed $icon Child-reported icon value.
	 */
	public function test_icon_non_string_is_refused( $icon ) {
		$this->assertSame( array( 'error' => 'Icon URL is not allowed.' ), MainWP_Sync::download_child_icon( $this->website, $icon ) );
	}

	/**
	 * JSON values can bypass the expected URL shape.
	 *
	 * @return array Invalid icon values.
	 */
	public static function invalid_icon_values() {
		return array(
			'array'            => array( array( 1 ) ),
			'integer'          => array( 123 ),
			'NUL in same host' => array( "https://child.example/icon.png\0" ),
			'NUL in host'      => array( "https://cdn.example\0/icon.png" ),
		);
	}

	/** The optional visit redirects need the same boundary as sync requests. */
	public function test_try_visit_redirect_options() {
		$method = new \ReflectionMethod( MainWP_Connect::class, 'try_visit' );
		$lines  = file( $method->getFileName() );
		$source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
		$this->assertStringContainsString( 'curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );', $source );
		$this->assertStringContainsString( 'curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );', $source );
		$this->assertStringContainsString( 'curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );', $source );
	}

	/** A refused loopback target must never receive even a blind read. */
	public function test_icon_loopback_refused_before_fetch_or_write() {
		$this->website->url = 'https://child.example/';
		$marker             = 'mainwp-redirect-' . bin2hex( random_bytes( 8 ) );
		$marker_file        = sys_get_temp_dir() . '/' . $marker;
		$this->files[]      = $marker_file;
		$dirs               = MainWP_System_Utility::get_mainwp_dir( 'icons', true );
		$icon_file          = $dirs[0] . 'favi-' . $this->website->id . '-redirect-target.png';
		$this->files[]      = $icon_file;
		$result             = MainWP_Sync::download_child_icon( $this->website, static::fixture_url( '/redirect-target.png?marker=' . $marker ) );
		$this->assertFileDoesNotExist( $marker_file );
		$this->assertFileDoesNotExist( $icon_file );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( '', MainWP_DB::instance()->get_website_option( $this->website->id, 'favi_icon', '' ) );
	}

	/** Intranet Children retain the existing icon and option behavior. */
	public function test_icon_same_host_is_written_and_option_saved() {
		$dirs          = MainWP_System_Utility::get_mainwp_dir( 'icons', true );
		$name          = 'favi-' . $this->website->id . '-icon.png';
		$this->files[] = $dirs[0] . $name;
		$this->assertSame( array( 'result' => 'success' ), MainWP_Sync::download_child_icon( $this->website, static::fixture_url( '/child-icon.png' ) ) );
		$this->assertSame( static::fixture_content( 64 ), file_get_contents( $dirs[0] . $name ) );
		$this->assertSame( $name, MainWP_DB::instance()->get_website_option( $this->website->id, 'favi_icon', '' ) );
	}

	/** A Child-chosen file name must not reach the public icons dir, where favi.php.png could run as PHP. */
	public function test_icon_file_name_comes_from_the_role_not_the_child() {
		$dirs          = MainWP_System_Utility::get_mainwp_dir( 'icons', true );
		$name          = 'favi-' . $this->website->id . '-icon.png';
		$this->files[] = $dirs[0] . $name;
		$this->files[] = $dirs[0] . 'favi-' . $this->website->id . '-favi.php.png';
		$this->assertSame( array( 'result' => 'success' ), MainWP_Sync::download_child_icon( $this->website, static::fixture_url( '/favi.php.png?v=1#x.php' ) ) );
		$this->assertFileExists( $dirs[0] . $name );
		$this->assertFileDoesNotExist( $dirs[0] . 'favi-' . $this->website->id . '-favi.php.png' );
		$this->assertSame( array(), glob( $dirs[0] . 'favi-' . $this->website->id . '-*.php*' ) );
		$this->assertSame( $name, MainWP_DB::instance()->get_website_option( $this->website->id, 'favi_icon', '' ) );
	}

	/** A file a Child named before the role naming must not stay in the public icons dir. */
	public function test_icon_download_removes_earlier_names_for_the_site() {
		$dirs    = MainWP_System_Utility::get_mainwp_dir( 'icons', true );
		$id      = (int) $this->website->id;
		$planted = array( $dirs[0] . 'favi-' . $id . '-favi.php.png', $dirs[0] . 'favi-' . $id . '-old.ico' );
		$control = $dirs[0] . 'favi-' . $id . '1-icon.png';
		foreach ( array_merge( $planted, array( $control ) ) as $path ) {
			file_put_contents( $path, 'planted' );
			$this->files[] = $path;
		}
		$this->files[] = $dirs[0] . 'favi-' . $id . '-icon.png';
		MainWP_DB::instance()->update_website_option( $this->website, 'favi_icon', 'favi-' . $id . '-favi.php.png' );
		$this->assertSame( array( 'result' => 'success' ), MainWP_Sync::download_child_icon( $this->website, static::fixture_url( '/child-icon.png' ) ) );
		$this->assertSame( static::fixture_content( 64 ), file_get_contents( $dirs[0] . 'favi-' . $id . '-icon.png' ) );
		foreach ( $planted as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
		$this->assertSame( array( $dirs[0] . 'favi-' . $id . '-icon.png' ), glob( $dirs[0] . 'favi-' . $id . '-*' ) );
		$this->assertFileExists( $control, 'Another site\'s icon was removed.' );
	}

	/** The fetch must use its checked address and stop before a redirect target. */
	public function test_icon_fetch_pins_dns_and_does_not_follow_redirects() {
		$base    = 'http://icon-pin.invalid:' . static::$server_port;
		$resolve = array( 'icon-pin.invalid:' . static::$server_port . ':127.0.0.1' );
		$this->assertSame( static::fixture_content( 64 ), MainWP_Connect::get_file_content( $base . '/child-icon.png', $resolve ) );
		$marker        = 'mainwp-redirect-' . bin2hex( random_bytes( 8 ) );
		$marker_file   = sys_get_temp_dir() . '/' . $marker;
		$this->files[] = $marker_file;
		$this->assertFalse( MainWP_Connect::get_file_content( $base . '/redirect-other-host?marker=' . $marker, $resolve ) );
		$this->assertFileDoesNotExist( $marker_file );
	}

	/**
	 * Subdirectory installs retain their cron path without trusting a different host.
	 *
	 * @dataProvider cron_urls
	 * @param string $siteurl Child-reported WordPress location.
	 * @param string $expected Requested cron URL.
	 */
	public function test_cron_ping_uses_stored_host( $siteurl, $expected ) {
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp' ),
				array(
					'url'     => 'https://child.example/',
					'siteurl' => $siteurl,
				),
				array( 'id' => $this->website->id )
			)
		);
		$urls    = array();
		$capture = static function ( $pre, $args, $url ) use ( &$urls ) {
			$urls[] = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'headers'  => array(),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );
		try {
			MainWP_System_Cron_Jobs::instance()->cron_ping_childs();
		} finally {
			remove_filter( 'pre_http_request', $capture, 10 );
		}
		$this->assertSame( array( $expected ), $urls );
	}

	/**
	 * Host aliases and subdirectories are legitimate WordPress locations.
	 *
	 * @return array Cron destinations.
	 */
	public static function cron_urls() {
		return array(
			'same host'  => array( 'https://child.example/wordpress', 'https://child.example/wordpress/wp-cron.php' ),
			'www alias'  => array( 'https://www.child.example/wordpress/', 'https://www.child.example/wordpress/wp-cron.php' ),
			'cross host' => array( 'http://169.254.169.254/secrets', 'https://child.example/wp-cron.php' ),
		);
	}
}
