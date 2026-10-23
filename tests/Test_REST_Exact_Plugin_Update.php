<?php
/**
 * Refusal and forwarding tests for the version-bound update route.
 *
 * @package MainWP\Dashboard\Tests
 */

use MainWP\Dashboard\Tests\MainWP_Abilities_Test_Case;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Connect;

class Test_REST_Exact_Plugin_Update extends MainWP_Abilities_Test_Case {
	private $site;
	private $calls = array();
	private $response;
	private $controller;
	private $scope;
	private $stats = array(
		'plugin_updates' => array(),
		'version'        => '6.2.1',
	);

	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$id               = $this->create_test_site(
			array(
				'plugin_upgrades' => wp_json_encode( array( 'fixture/fixture.php' => array( 'update' => array( 'new_version' => '1.1' ) ) ) ),
			)
		);
		$this->site       = MainWP_DB::instance()->get_website_by_id( $id );
		$this->response   = array(
			'success'         => 1,
			'contract'        => 'mainwp.plugin-update-exact.v1',
			'slug'            => 'fixture/fixture.php',
			'current_version' => '1.0',
			'target_version'  => '1.1',
		);
		$this->controller = new class( $this->site ) extends MainWP_Rest_Updates_Controller {
			private $site;
			public function __construct( $site ) {
				$this->site = $site;
			}
			public function get_request_item( $request ) {
				return $this->site;
			}
		};
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child' ), 10, 4 );
	}

	public function tearDown(): void {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child' ), 10 );
		if ( $this->scope ) {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $this->scope );
		}
		$property = new ReflectionProperty( MainWP_REST_Authentication::class, 'user' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( MainWP_REST_Authentication::get_instance(), null );
		parent::tearDown();
	}

	public function child( $reply, $site, $command, $params ) {
		$this->calls[] = array(
			'site'    => $site->id,
			'command' => $command,
			'params'  => $params,
		);
		return 'stats' === $command ? $this->stats : $this->response;
	}

	private function request( $changes = array() ) {
		$request = new WP_REST_Request( 'POST', '/mainwp/v2/updates/' . $this->site->id . '/update/plugins/exact' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array_merge(
					array(
						'slug'            => 'fixture/fixture.php',
						'current_version' => '1.0',
						'target_version'  => '1.1',
					),
					$changes
				)
			)
		);
		$request['id_domain'] = (string) $this->site->id;
		return $request;
	}

	private function assert_refusal( $result, $code, $status ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	/** @dataProvider invalid_bindings */
	public function test_invalid_bindings_never_dispatch( $change ) {
		$this->assert_refusal( $this->controller->update_plugin_site_exact( $this->request( $change ) ), 'mainwp_exact_invalid_request', 400 );
		$this->assertSame( array(), $this->calls );
	}

	public function invalid_bindings() {
		return array(
			array( array( 'slug' => array( 'fixture/fixture.php' ) ) ),
			array( array( 'slug' => 'fixture/fixture.php,other/other.php' ) ),
			array( array( 'current_version' => null ) ),
			array( array( 'target_version' => '' ) ),
			array( array( 'target_version' => '1.0' ) ),
		);
	}

	public function test_ignored_plugin_never_dispatches() {
		$this->site->ignored_plugins = wp_json_encode( array( 'fixture/fixture.php' => 'Fixture' ) );
		$this->assert_refusal( $this->controller->update_plugin_site_exact( $this->request() ), 'mainwp_exact_update_unavailable', 409 );
		$this->assertSame( array(), $this->calls );
	}

	public function test_site_ignore_never_dispatches() {
		$this->site->is_ignorePluginUpdates = 1;
		$this->assert_refusal( $this->controller->update_plugin_site_exact( $this->request() ), 'mainwp_exact_site_unavailable', 409 );
		$this->assertSame( array(), $this->calls );
	}

	public function test_suspended_and_disconnected_sites_never_dispatch() {
		$this->site->suspended = '1';
		$this->assert_refusal( $this->controller->update_plugin_site_exact( $this->request() ), 'mainwp_exact_site_unavailable', 409 );
		$this->site->suspended   = '0';
		$this->site->sync_errors = 'Disconnected';
		$this->assert_refusal( $this->controller->update_plugin_site_exact( $this->request() ), 'mainwp_exact_site_unavailable', 409 );
		$this->assertSame( array(), $this->calls );
	}

	/** @dataProvider unconfirmed_responses */
	public function test_unconfirmed_child_never_succeeds_or_falls_back( $response ) {
		$this->response = $response;
		$this->assert_refusal( $this->controller->update_plugin_site_exact( $this->request() ), 'mainwp_exact_update_unconfirmed', 502 );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'upgradepluginexact', $this->calls[0]['command'] );
	}

	public function unconfirmed_responses() {
		return array(
			array( array( 'success' => 1 ) ),
			array(
				array(
					'success' => 0,
					'message' => 'Refused',
				),
			),
			array(
				array(
					'contract'        => 'mainwp.plugin-update-exact.v1',
					'success'         => 1,
					'slug'            => 'other/other.php',
					'current_version' => '1.0',
					'target_version'  => '1.1',
				),
			),
			array(
				array(
					'contract'        => 'mainwp.plugin-update-exact.v1',
					'success'         => 1,
					'slug'            => 'fixture/fixture.php',
					'current_version' => '1.0',
					'target_version'  => '1.2',
				),
			),
		);
	}

	public function test_exact_fields_and_one_site_are_forwarded_to_distinct_command() {
		$result = $this->controller->update_plugin_site_exact( $this->request() );
		$this->assertNotWPError( $result );
		$this->assertSame( $this->response, $result->get_data() );
		$this->assertSame(
			array(
				'site'    => $this->site->id,
				'command' => 'upgradepluginexact',
				'params'  => array(
					'slug'            => 'fixture/fixture.php',
					'current_version' => '1.0',
					'target_version'  => '1.1',
				),
			),
			$this->calls[0]
		);
		$this->assertSame( array( 'upgradepluginexact', 'stats' ), array_column( $this->calls, 'command' ) );
	}

	public function test_route_reuses_api_key_permissions() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		add_action( 'rest_api_init', array( new MainWP_Rest_Updates_Controller(), 'register_routes' ) );
		do_action( 'rest_api_init' );
		$routes   = $wp_rest_server->get_routes();
		$route    = $routes['/mainwp/v2/updates/(?P<id_domain>[A-Za-z0-9-\.]+)/update/plugins/exact'];
		$callback = $route[0]['permission_callback'];
		$request  = $this->request();
		$property = new ReflectionProperty( MainWP_REST_Authentication::class, 'user' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( MainWP_REST_Authentication::get_instance(), null );
		$this->assertWPError( call_user_func( $callback, $request ) );
		$property->setValue( MainWP_REST_Authentication::get_instance(), (object) array( 'permissions' => 'read' ) );
		$this->assertWPError( call_user_func( $callback, $request ) );
		$property->setValue( MainWP_REST_Authentication::get_instance(), (object) array( 'permissions' => 'write' ) );
		$this->assertTrue( call_user_func( $callback, $request ) );
		$wp_rest_server = null;
	}

	public function test_explicit_scope_guard_refuses_before_child_dispatch() {
		$this->scope = static function () {
			return array();
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $this->scope );
		$result = $this->controller->update_plugin_site_exact( $this->request() );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_exact_site_unavailable', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->calls );
	}

	public function test_success_without_sync_refreshes_pending_plugins_and_preserves_receipt() {
		$remaining                   = array( 'other/other.php' => array( 'update' => array( 'new_version' => '2.0' ) ) );
		$this->site->plugin_upgrades = wp_json_encode( array_merge( json_decode( $this->site->plugin_upgrades, true ), $remaining ) );
		MainWP_DB::instance()->update_website_values( $this->site->id, array( 'plugin_upgrades' => $this->site->plugin_upgrades ) );
		$this->stats['plugin_updates'] = $remaining;
		$result                        = $this->controller->update_plugin_site_exact( $this->request() );
		$this->assertNotWPError( $result );
		$this->assertSame( $this->response, $result->get_data() );
		$fresh = MainWP_DB::instance()->get_website_by_id( $this->site->id );
		$this->assertSame( $remaining, json_decode( $fresh->plugin_upgrades, true ) );
		$this->assertSame( array( 'upgradepluginexact', 'stats' ), array_column( $this->calls, 'command' ) );
	}

	public function test_refresh_without_plugin_inventory_never_confirms_stale_state() {
		$this->stats = array( 'version' => '6.2.1' );
		$result      = $this->controller->update_plugin_site_exact( $this->request() );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_exact_sync_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_failed_refresh_never_reports_complete_success_or_retries_update() {
		$this->stats = array( 'error' => 'Fixture refresh failed' );
		$result      = $this->controller->update_plugin_site_exact( $this->request() );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_exact_sync_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertSame( array( 'upgradepluginexact', 'stats' ), array_column( $this->calls, 'command' ) );
	}

	/** @dataProvider transport_selections */
	public function test_exact_command_uses_standard_update_transport_lifecycle( $selection, $expected_slug ) {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child' ), 10 );
		$events = array();
		$before = static function ( $site, $type, $list ) use ( &$events ) {
			$events[] = array( 'before', $type, $list );
		};
		$after  = static function ( $site, $type, $list ) use ( &$events ) {
			$events[] = array( 'after', $type, $list );
		};
		add_action( 'mainwp_website_before_updated', $before, 10, 3 );
		add_action( 'mainwp_website_updated', $after, 10, 3 );
		try {
			Test_MainWP_Exact_Transport::$response = $this->response;
			$result                                = Test_MainWP_Exact_Transport::fetch_url_authed(
				$this->site,
				'upgradepluginexact',
				$selection
			);
			$this->assertSame( $this->response, $result );
			$this->assertTrue( Test_MainWP_Exact_Transport::$others['upgrade'] );
			$this->assertFalse( Test_MainWP_Exact_Transport::$retry );
			$this->assertSame( array( array( 'before', 'plugin', $expected_slug ), array( 'after', 'plugin', $expected_slug ) ), $events );
		} finally {
			remove_action( 'mainwp_website_before_updated', $before, 10 );
			remove_action( 'mainwp_website_updated', $after, 10 );
		}
	}

	public function transport_selections() {
		return array(
			array(
				array(
					'slug'            => 'fixture/fixture.php',
					'current_version' => '1.0',
					'target_version'  => '1.1',
				),
				'fixture/fixture.php',
			),
			array( array(), '' ),
			array( array( 'slug' => array( 'fixture/fixture.php' ) ), '' ),
		);
	}

	public function test_real_site_lookup_refuses_foreign_site_scope() {
		$id          = $this->create_test_site();
		$this->scope = static function () {
			return array();
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $this->scope );
		$request              = $this->request();
		$request['id_domain'] = (string) $id;
		$result               = ( new MainWP_Rest_Updates_Controller() )->update_plugin_site_exact( $request );
		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->calls );
	}
}


/** Run the actual transport lifecycle with only signing and network I/O replaced. */
class Test_MainWP_Exact_Transport extends MainWP_Connect {
	public static $response;
	public static $others;
	public static $retry;
	public static function get_post_data_authed( &$website, $what, $params = null, $others = array() ) {
		return $params;
	}
	public static function fetch_url( &$website, $url, $postdata, $checkConstraints = false, $verifyCertificate = null, $pRetryFailed = true, $http_user = null, $http_pass = null, $sslVersion = 0, $others = array(), &$output = array() ) {
		self::$others = $others;
		self::$retry  = $pRetryFailed;
		return self::$response;
	}
}
