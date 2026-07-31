<?php
/**
 * Connection-test handler security boundary tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Site_Handler;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Tests local URL rejection and saved-site authorization without network requests.
 */
class Test_Post_Site_Handler_Connection_Security extends \WP_UnitTestCase {

	/**
	 * Site IDs inserted by this test case.
	 *
	 * @var int[]
	 */
	private $site_ids = array();

	public function setUp(): void {
		parent::setUp();
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		global $wpdb;

		foreach ( $this->site_ids as $site_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ) );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ) );
		}
		$this->site_ids = array();

		parent::tearDown();
	}

	/**
	 * A port cannot become valid merely because another filter also allowlists it.
	 */
	public function test_blocked_port_remains_invalid_when_also_allowed(): void {
		$blocked = static function () {
			return array( 21, 22 );
		};
		$allowed = static function () {
			return array( 22 );
		};
		add_filter( 'mainwp_connect_sites_not_allow_ports', $blocked, 999, 2 );
		add_filter( 'mainwp_connect_sites_allow_ports', $allowed, 999, 2 );

		try {
			$this->assertTrue( $this->invoke_handler_helper( 'is_invalid_connection_test_url', 'https://example.test:22/' ) );
		} finally {
			remove_filter( 'mainwp_connect_sites_not_allow_ports', $blocked, 999 );
			remove_filter( 'mainwp_connect_sites_allow_ports', $allowed, 999 );
		}
	}

	/**
	 * An explicitly allowed, nonstandard, nonblocked port remains supported.
	 */
	public function test_allowed_nonstandard_port_is_valid(): void {
		$blocked = static function () {
			return array( 21, 22 );
		};
		$allowed = static function () {
			return array( 8443 );
		};
		add_filter( 'mainwp_connect_sites_not_allow_ports', $blocked, 999, 2 );
		add_filter( 'mainwp_connect_sites_allow_ports', $allowed, 999, 2 );

		try {
			$this->assertFalse( $this->invoke_handler_helper( 'is_invalid_connection_test_url', 'https://example.test:8443/' ) );
		} finally {
			remove_filter( 'mainwp_connect_sites_not_allow_ports', $blocked, 999 );
			remove_filter( 'mainwp_connect_sites_allow_ports', $allowed, 999 );
		}
	}

	/**
	 * Allowlists cannot erase URL validation failures.
	 */
	public function test_allowed_port_does_not_override_invalid_scheme_or_query(): void {
		$allowed = static function () {
			return array( 8443 );
		};
		add_filter( 'mainwp_connect_sites_allow_ports', $allowed, 999, 2 );

		try {
			$this->assertTrue( $this->invoke_handler_helper( 'is_invalid_connection_test_url', 'ftp://example.test:8443/' ) );
			$this->assertTrue( $this->invoke_handler_helper( 'is_invalid_connection_test_url', 'https://example.test:8443/?=unexpected' ) );
		} finally {
			remove_filter( 'mainwp_connect_sites_allow_ports', $allowed, 999 );
		}
	}

	/**
	 * Unauthorized IDs are rejected before a website object is exposed.
	 */
	public function test_unauthorized_site_is_unavailable(): void {
		$site_id = $this->create_site();
		$deny    = static function ( $allowed, $cap_type, $cap ) use ( $site_id ) {
			if ( 'site' === $cap_type && $site_id === (int) $cap ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 999, 3 );

		try {
			$this->assertFalse( $this->invoke_handler_helper( 'get_connection_test_website', $site_id ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 999 );
		}
	}

	/**
	 * Unknown and unauthorized IDs share the same unavailable result.
	 */
	public function test_missing_site_is_unavailable(): void {
		global $wpdb;

		$missing_id = 1000 + (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}mainwp_wp" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$allow      = static function ( $allowed, $cap_type, $cap ) use ( $missing_id ) {
			if ( 'site' === $cap_type && $missing_id === (int) $cap ) {
				return true;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $allow, 999, 3 );

		try {
			$this->assertFalse( $this->invoke_handler_helper( 'get_connection_test_website', $missing_id ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $allow, 999 );
		}
	}

	/**
	 * An authorized existing site resolves to its saved row.
	 */
	public function test_authorized_site_is_available(): void {
		$site_id = $this->create_site();
		$allow   = static function ( $allowed, $cap_type, $cap ) use ( $site_id ) {
			if ( 'site' === $cap_type && $site_id === (int) $cap ) {
				return true;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $allow, 999, 3 );

		try {
			$website = $this->invoke_handler_helper( 'get_connection_test_website', $site_id );
			$this->assertIsObject( $website );
			$this->assertSame( $site_id, (int) $website->id );
		} finally {
			remove_filter( 'mainwp_currentusercan', $allow, 999 );
		}
	}

	/**
	 * The AJAX sink must enforce Dashboard and site access before reading draft inputs.
	 */
	public function test_handler_checks_access_before_request_inputs(): void {
		$source = file_get_contents( MAINWP_PLUGIN_DIR . 'class/class-mainwp-post-site-handler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$handler          = strpos( $source, 'public function mainwp_testwp()' );
		$dashboard_guard  = strpos( $source, "mainwp_current_user_can( 'dashboard', 'test_connection' )", $handler );
		$site_id_input    = strpos( $source, "\$_POST['siteid']", $dashboard_guard );
		$site_access_call = strpos( $source, 'self::get_connection_test_website( $site_id )', $site_id_input );
		$url_input        = strpos( $source, "\$_POST['url']", $site_access_call );

		$this->assertNotFalse( $handler );
		$this->assertNotFalse( $dashboard_guard );
		$this->assertNotFalse( $site_id_input );
		$this->assertNotFalse( $site_access_call );
		$this->assertNotFalse( $url_input );
		$this->assertTrue( $handler < $dashboard_guard );
		$this->assertTrue( $dashboard_guard < $site_id_input );
		$this->assertTrue( $site_id_input < $site_access_call );
		$this->assertTrue( $site_access_call < $url_input );
	}

	/**
	 * Invoke a private static handler helper.
	 *
	 * @param string $method_name Helper method name.
	 * @param mixed  ...$args     Helper arguments.
	 *
	 * @return mixed Helper result.
	 */
	private function invoke_handler_helper( $method_name, ...$args ) {
		$method = new \ReflectionMethod( MainWP_Post_Site_Handler::class, $method_name );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( null, ...$args );
	}

	/**
	 * Insert a minimal saved site and its required sync row.
	 *
	 * @return int Site ID.
	 */
	private function create_site() {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			array(
				'userid'    => get_current_user_id(),
				'url'       => 'https://connection-security-' . wp_generate_uuid4() . '.example/',
				'name'      => 'Connection Security Test Site',
				'adminname' => 'admin',
				'pubkey'    => 'test-pubkey',
				'privkey'   => 'test-privkey',
			)
		);
		$site_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			array(
				'wpid'        => $site_id,
				'version'     => '5.0.0',
				'sync_errors' => '',
				'dtsSync'     => time(),
			)
		);

		$this->site_ids[] = $site_id;
		return $site_id;
	}
}
