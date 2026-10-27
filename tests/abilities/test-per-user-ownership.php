<?php
/**
 * Ownership boundaries through real abilities and authenticated REST routes.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Abilities_Cron;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Exception;

/** Tests per-user ownership without connecting to Child sites. */
class Test_Per_User_Ownership extends MainWP_Abilities_Test_Case {
	/**
	 * Saved globals and authentication state.
	 *
	 * @var array
	 */
	private $saved = array();
	/**
	 * Registered authentication handler.
	 *
	 * @var object
	 */
	private $auth;
	/**
	 * Key owner.
	 *
	 * @var int
	 */
	private $owner;
	/**
	 * Fixture keys.
	 *
	 * @var array
	 */
	private $keys;
	/**
	 * Owned and foreign site IDs.
	 *
	 * @var array
	 */
	private $sites;
	/**
	 * Owned and foreign tag IDs.
	 *
	 * @var array
	 */
	private $tags;
	/**
	 * Exact fixture callbacks.
	 *
	 * @var array
	 */
	private $filters = array();
	/**
	 * Whether ownership is disabled.
	 *
	 * @var bool
	 */
	private $single = false;
	/**
	 * Configured site grants.
	 *
	 * @var mixed
	 */
	private $site_grants = 'all';
	/**
	 * Configured tag grants.
	 *
	 * @var mixed
	 */
	private $group_grants = 'all';
	/**
	 * Intercepted transport targets.
	 *
	 * @var array
	 */
	private $transport = array();
	/**
	 * Blocked HTTP calls.
	 *
	 * @var int
	 */
	private $http = 0;

	/** Prepare two owners and the authentication instance registered on WordPress. */
	public function setUp(): void {
		parent::setUp();
		$this->saved = array(
			'server' => $_SERVER,
			'get'    => $_GET,
			'post'   => $_POST,
			'query'  => $GLOBALS['wp']->query_vars,
			'rest'   => $GLOBALS['wp_rest_server'] ?? null,
			'auth'   => \MainWP_REST_Authentication::$instance,
		);
		foreach ( $GLOBALS['wp_filter']['determine_current_user']->callbacks[15] as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && $function[0] instanceof \MainWP_REST_Authentication && 'authenticate' === $function[1] ) {
				$this->auth = $function[0];
				break;
			}
		}
		$this->assertInstanceOf( \MainWP_REST_Authentication::class, $this->auth );
		\MainWP_REST_Authentication::$instance = $this->auth;
		foreach ( array( 'user', 'error', 'auth_method' ) as $name ) {
			$property = new \ReflectionProperty( $this->auth, $name );
			$property->setAccessible( true );
			$this->saved['auth_state'][ $name ] = $property->getValue( $this->auth );
		}
		$this->owner = $this->set_current_user_as_admin();
		$other       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->keys  = array(
			'read_write' => $this->create_rest_api_key( $this->owner ),
			'read'       => $this->create_rest_api_key( $this->owner, 'read' ),
		);
		$this->sites = array( $this->create_test_site(), $this->create_test_site( array( 'userid' => $other ) ) );
		$this->tags  = array( $this->create_test_tag() );
		wp_set_current_user( $other );
		$this->tags[] = $this->create_test_tag();
		wp_set_current_user( $this->owner );
		$this->assign_site_to_tag( $this->sites[0], $this->tags[0] );
		$this->assign_site_to_tag( $this->sites[1], $this->tags[1] );
		$this->filter(
			'mainwp_is_single_user',
			function () {
				return $this->single;
			}
		);
		$this->filter(
			'mainwp_currentuserallowedaccesssites',
			function () {
				return $this->site_grants;
			}
		);
		$this->filter(
			'mainwp_currentuserallowedaccessgroups',
			function () {
				return $this->group_grants;
			}
		);
		$this->filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $site ) {
				$this->transport[] = (int) $site->id;
				return new \WP_Error( 'fixture_transport', 'Child transport intercepted.' );
			},
			2
		);
		$this->filter(
			'mainwp_pre_fetch_authed_data',
			function ( $pre, $params, $what, $site ) {
				$this->transport[] = (int) $site->id;
				throw new MainWP_Exception( 'Fixture transport intercepted.' );
			},
			4
		);
		$this->filter(
			'pre_http_request',
			function () {
				++$this->http;
				return new \WP_Error( 'fixture_http', 'HTTP intercepted.' );
			}
		);
		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		foreach ( array( new \MainWP_Rest_Sites_Controller(), new \MainWP_Rest_Tags_Controller(), new \MainWP_Rest_Users_Controller(), new \MainWP_Rest_Settings_Controller(), new \MainWP_Rest_Updates_Controller() ) as $controller ) {
			$controller->register_routes();
		}
	}

	/** Restore private authentication state, globals and fixture filters. */
	public function tearDown(): void {
		foreach ( $this->filters as $hook => $callback ) {
			remove_filter( $hook, $callback, 99 );
		}
		foreach ( MainWP_DB::instance()->remove_rest_api_keys_by_user( $this->owner ) as $row ) {
			\MainWP_REST_Authentication::delete_api_key_cache( $row->consumer_key );
		}
		foreach ( $this->saved['auth_state'] as $name => $value ) {
			$this->auth_property( $name, $value );
		}
		\MainWP_REST_Authentication::$instance = $this->saved['auth'];
		$_SERVER                               = $this->saved['server'];
		$_GET                                  = $this->saved['get'];
		$_POST                                 = $this->saved['post'];
		$GLOBALS['wp']->query_vars             = $this->saved['query'];
		$GLOBALS['wp_rest_server']             = $this->saved['rest'];
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Install a fixture filter.
	 *
	 * @param string   $hook Hook name.
	 * @param callable $callback Callback.
	 * @param int      $args Argument count.
	 */
	private function filter( $hook, $callback, $args = 1 ): void {
		$this->filters[ $hook ] = $callback;
		add_filter( $hook, $callback, 99, $args );
	}

	/**
	 * Set authentication state between requests.
	 *
	 * @param string $name Property name.
	 * @param mixed  $value Property value.
	 */
	private function auth_property( $name, $value ): void {
		$property = new \ReflectionProperty( $this->auth, $name );
		$property->setAccessible( true );
		$property->setValue( $this->auth, $value );
	}

	/**
	 * Authenticate a fixture key using the registered handler.
	 *
	 * @param string $method HTTP method.
	 * @param string $route Route suffix.
	 * @param string $key Key permission level.
	 */
	private function authenticate( $method, $route, $key = 'read_write' ): void {
		foreach ( array(
			'user'        => null,
			'error'       => null,
			'auth_method' => '',
		) as $name => $value ) {
			$this->auth_property( $name, $value );
		}
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$_SERVER['HTTPS']                        = 'on';
		$_SERVER['REQUEST_METHOD']               = $method;
		$_SERVER['REQUEST_URI']                  = '/wp-json/mainwp/v2/' . $route;
		$_SERVER['PHP_AUTH_USER']                = $this->keys[ $key ]['consumer_key'];
		$_SERVER['PHP_AUTH_PW']                  = $this->keys[ $key ]['consumer_secret'];
		$GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/' . $route;
		wp_set_current_user( 0 );
		$owner = $this->auth->authenticate( false );
		$this->assertSame( $this->owner, (int) $owner, 'Fixture key must authenticate its owner.' );
		wp_set_current_user( $owner );
	}

	/**
	 * Dispatch an actual registered REST route.
	 *
	 * @param string $method HTTP method.
	 * @param string $route Route suffix.
	 * @param array  $body JSON body.
	 * @param string $key Key permission level.
	 * @return \WP_REST_Response
	 */
	private function request( $method, $route, $body = array(), $key = 'read_write' ) {
		$this->authenticate( $method, $route, $key );
		$request = new \WP_REST_Request( $method, '/mainwp/v2/' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( (object) $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Site references accepted by abilities.
	 *
	 * @param int $index Fixture index.
	 * @return array
	 */
	private function references( $index ) {
		$site = MainWP_DB::instance()->get_website_by_id( $this->sites[ $index ] );
		return array( (int) $site->id, $site->url, wp_parse_url( $site->url, PHP_URL_HOST ) );
	}

	/** Foreign IDs, URLs and domains have missing-target parity for reads and deletes. */
	public function test_site_abilities_and_inventory_routes(): void {
		foreach ( array( 'read_write', 'read' ) as $key ) {
			foreach ( array( 0, 1 ) as $index ) {
				foreach ( array( $this->references( $index )[0], $this->references( $index )[2] ) as $reference ) {
					$route    = 'sites/' . rawurlencode( (string) $reference ) . '/plugins';
					$response = $this->request( 'GET', $route, array(), $key );
					$this->assertSame( 0 === $index ? 200 : 404, $response->get_status(), 'Inventory must respect ownership for ' . $key );
				}
			}
		}
	}

	/** Deletes resolve IDs, URLs and domains within ownership. */
	public function test_site_ability_targets(): void {
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->authenticate( 'DELETE', 'sites/' . $this->sites[1] . '/remove' );
		foreach ( $this->references( 1 ) as $reference ) {
			foreach ( array( 'mainwp/delete-site-v1', 'mainwp/get-site-plugins-v1' ) as $ability ) {
				$input = array( 'site_id_or_domain' => $reference );
				if ( 'mainwp/delete-site-v1' === $ability ) {
					$input['confirm'] = true;
				}
				$result = $this->execute_ability( $ability, $input );
				$this->assertWPError( $result );
				$this->assertSame( 'mainwp/get-site-plugins-v1' === $ability ? 'ability_invalid_permissions' : 'mainwp_site_not_found', $result->get_error_code() );
				$missing = $this->execute_ability( $ability, array_merge( $input, array( 'site_id_or_domain' => 99999999 ) ) );
				$this->assertSame( $missing->get_error_code(), $result->get_error_code() );
				$this->assertSame( $missing->get_error_data(), $result->get_error_data() );
				$scope_error = MainWP_Abilities_Util::check_site_access( MainWP_DB::instance()->get_website_by_id( $this->sites[1] ), $input );
				$this->assertSame( 'mainwp_site_not_found', $scope_error->get_error_code() );
				$this->assertSame( 404, $scope_error->get_error_data()['status'] );
				$this->assertNotEmpty( MainWP_DB::instance()->get_website_by_id( $this->sites[1] ) );
			}
		}
		$own = $this->execute_ability(
			'mainwp/delete-site-v1',
			array(
				'site_id_or_domain' => $this->sites[0],
				'confirm'           => true,
			)
		);
		$this->assertNotWPError( $own );
		$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $this->sites[0] ) );
		$this->assertSame( array(), $this->transport );
	}

	/** Password selections reach transport only for owned sites. */
	public function test_password_explicit_targets(): void {
		$route   = 'users/update-admin-password';
		$foreign = $this->request(
			'PUT',
			$route,
			array(
				'websites' => (string) $this->sites[1],
				'password' => 'Fixture-only-password',
			)
		);
		$missing = $this->request(
			'PUT',
			$route,
			array(
				'websites' => '99999999',
				'password' => 'Fixture-only-password',
			)
		);
		$this->assertSame( $missing->get_status(), $foreign->get_status() );
		$this->assertSame( str_replace( '99999999', (string) $this->sites[1], wp_json_encode( $missing->get_data() ) ), wp_json_encode( $foreign->get_data() ) );
		$this->assertSame( array(), $this->transport );
	}

	/** Bulk password selections exclude foreign targets before transport. */
	public function test_password_bulk_targets(): void {
		$route = 'users/update-admin-password';
		$this->request(
			'PUT',
			$route,
			array(
				'websites' => implode( ',', array( $this->sites[1], $this->sites[0] ) ),
				'password' => 'Fixture-only-password',
			)
		);
		$this->assertSame( array(), $this->transport, 'A mixed explicit list fails validation before transport.' );
		$this->request(
			'PUT',
			$route,
			array(
				'websites' => (string) $this->sites[0],
				'password' => 'Fixture-only-password',
			)
		);
		$this->assertSame( array( $this->sites[0] ), $this->transport );
		$this->transport = array();
		$this->assign_site_to_tag( $this->sites[1], $this->tags[0] );
		$group_name = MainWP_DB_Common::instance()->get_group_by_id( $this->tags[0] )->name;
		$this->request(
			'PUT',
			$route,
			array(
				'groups'   => $group_name,
				'password' => 'Fixture-only-password',
			)
		);
		$this->assertSame( array( $this->sites[0] ), $this->transport );
		$this->transport = array();
		$foreign_members = $this->create_test_tag();
		$this->assign_site_to_tag( $this->sites[1], $foreign_members );
		$response = $this->request(
			'PUT',
			$route,
			array(
				'groups'   => MainWP_DB_Common::instance()->get_group_by_id( $foreign_members )->name,
				'password' => 'Fixture-only-password',
			)
		);
		$this->assertSame( 'no_website_found', $response->get_data()['code'] );
		$this->assertSame( array(), $this->transport );
		$this->assertSame( 0, $this->http );
	}

	/**
	 * Snapshot tag rows and memberships.
	 *
	 * @return array
	 */
	private function tag_state() {
		global $wpdb;
		return array(
			$wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_group ORDER BY id", ARRAY_A ),
			$wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_group ORDER BY groupid, wpid", ARRAY_A ),
		);
	}

	/**
	 * Foreign tag operations must leave rows and memberships intact.
	 *
	 * @dataProvider tag_routes
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param bool   $create Whether the ID is supplied in the body.
	 */
	public function test_tag_rest_missing_parity( $method, $suffix, $create ): void {
		$before  = $this->tag_state();
		$foreign = $this->request(
			$method,
			'tags/' . ( $create ? 'add' : $this->tags[1] . $suffix ),
			array(
				'id'   => $this->tags[1],
				'name' => 'Test Tag Changed',
			)
		);
		$this->assertSame( $before, $this->tag_state() );
		$missing = $this->request(
			$method,
			'tags/' . ( $create ? 'add' : '99999999' . $suffix ),
			array(
				'id'   => 99999999,
				'name' => 'Test Tag Changed',
			)
		);
		$this->assertSame( 404, $foreign->get_status() );
		$this->assertSame( $missing->get_data(), $foreign->get_data() );
		$own = $this->request(
			$method,
			'tags/' . ( $create ? 'add' : $this->tags[0] . $suffix ),
			array(
				'id'   => $this->tags[0],
				'name' => 'Test Tag Changed',
			)
		);
		$this->assertSame( 200, $own->get_status() );
		if ( 'GET' !== $method ) {
			$this->assertSame( 1, $own->get_data()['success'] );
		}
	}

	/**
	 * Registered tag routes.
	 *
	 * @return array
	 */
	public static function tag_routes(): array {
		return array(
			array( 'GET', '', false ),
			array( 'PUT', '/edit', false ),
			array( 'DELETE', '/remove', false ),
			array( 'POST', '', true ),
		);
	}

	/** Batch create, update and delete reuse the ownership resolver. */
	public function test_tag_batch_and_lists(): void {
		$before   = $this->tag_state();
		$response = $this->request(
			'POST',
			'tags/batch',
			array(
				'create' => array(
					array(
						'id'   => $this->tags[1],
						'name' => 'Test Tag Batch',
					),
				),
				'update' => array(
					array(
						'id'   => $this->tags[1],
						'name' => 'Test Tag Batch',
					),
				),
				'delete' => array( $this->tags[1] ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		foreach ( array( 'create', 'update', 'delete' ) as $operation ) {
			$this->assertArrayHasKey( 'error', $response->get_data()[ $operation ][0] );
			$this->assertSame( 'mainwp_rest_invalid_tag_id', $response->get_data()[ $operation ][0]['error']['code'] );
		}
		$this->assertSame( $before, $this->tag_state() );
		$list = $this->request( 'GET', 'tags' );
		$this->assertSame( 200, $list->get_status() );
		$data = $list->get_data();
		$this->assertContains( $this->tags[0], array_map( 'intval', array_column( $data['data'], 'id' ) ) );
		$this->assertNotContains( $this->tags[1], array_map( 'intval', array_column( $data['data'], 'id' ) ) );
		$missing = $this->request(
			'POST',
			'tags/batch',
			array(
				'create' => array(
					array(
						'id'   => 99999999,
						'name' => 'Test Tag Batch',
					),
				),
				'update' => array(
					array(
						'id'   => 99999999,
						'name' => 'Test Tag Batch',
					),
				),
				'delete' => array( 99999999 ),
			)
		);
		foreach ( array( 'create', 'update', 'delete' ) as $operation ) {
			$this->assertSame( $missing->get_data()[ $operation ][0]['error'], $response->get_data()[ $operation ][0]['error'] );
		}
		$own = $this->request(
			'POST',
			'tags/batch',
			array(
				'create' => array(
					array(
						'id'   => $this->tags[0],
						'name' => 'Test Tag Batch Own',
					),
				),
				'update' => array(
					array(
						'id'   => $this->tags[0],
						'name' => 'Test Tag Batch Updated',
					),
				),
				'delete' => array( $this->tags[0] ),
			)
		);
		$this->assertSame( 200, $own->get_status() );
		foreach ( array( 'create', 'update', 'delete' ) as $operation ) {
			$this->assertSame( 1, $own->get_data()[ $operation ][0]['success'] );
		}
		$this->assertEmpty( MainWP_DB_Common::instance()->get_group_by_id( $this->tags[0] ) );
	}

	/** Tag ability execution covers mutation, deletion, list and member scope. */
	public function test_tag_abilities_and_members(): void {
		$this->authenticate( 'PUT', 'tags/' . $this->tags[1] . '/edit' );
		$before = $this->tag_state();
		foreach ( array( 'mainwp/delete-tag-v1', 'mainwp/update-tag-v1' ) as $ability ) {
			$input   = 'mainwp/update-tag-v1' === $ability ? array( 'name' => 'Test Tag Ability' ) : array( 'confirm' => true );
			$result  = $this->execute_ability( $ability, array_merge( $input, array( 'tag_id' => $this->tags[1] ) ) );
			$missing = $this->execute_ability( $ability, array_merge( $input, array( 'tag_id' => 99999999 ) ) );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_tag_not_found', $result->get_error_code() );
			$this->assertSame( $missing->get_error_code(), $result->get_error_code() );
			$this->assertSame( str_replace( '99999999', (string) $this->tags[1], $missing->get_error_message() ), $result->get_error_message() );
			$this->assertSame( $missing->get_error_data(), $result->get_error_data() );
			$this->assertSame( $before, $this->tag_state() );
		}
		$list = $this->execute_ability( 'mainwp/list-tags-v1' );
		$this->assertNotWPError( $list );
		$this->assertContains( $this->tags[0], array_map( 'intval', array_column( $list['items'], 'id' ) ) );
		$this->assertNotContains( $this->tags[1], array_map( 'intval', array_column( $list['items'], 'id' ) ) );
		$empty_tag = $this->create_test_tag();
		$deleted   = $this->execute_ability(
			'mainwp/delete-tag-v1',
			array(
				'tag_id'  => $empty_tag,
				'confirm' => true,
			)
		);
		$this->assertNotWPError( $deleted );
		$this->assertEmpty( MainWP_DB_Common::instance()->get_group_by_id( $empty_tag ) );
		$this->assign_site_to_tag( $this->sites[1], $this->tags[0] );
		$members = $this->execute_ability( 'mainwp/get-tag-sites-v1', array( 'tag_id' => $this->tags[0] ) );
		$this->assertNotWPError( $members );
		$this->assertSame( array( $this->sites[0] ), array_map( 'intval', array_column( $members['items'], 'id' ) ) );
		$own = $this->execute_ability(
			'mainwp/update-tag-v1',
			array(
				'tag_id' => $this->tags[0],
				'name'   => 'Test Tag Own Updated',
			)
		);
		$this->assertNotWPError( $own );
		$this->assertSame( 'Test Tag Own Updated', MainWP_DB_Common::instance()->get_group_by_id( $this->tags[0] )->name );
	}

	/** Configured grants restrict owned rows without widening ownership. */
	public function test_team_control_and_single_user_scope(): void {
		$this->request( 'GET', 'tags' );
		$this->site_grants  = array( $this->sites[1] );
		$this->group_grants = array( $this->tags[1] );
		$this->assertSame( array(), MainWP_Abilities_Util::get_allowed_site_ids() );
		$this->assertSame( array(), MainWP_Abilities_Util::get_allowed_group_ids() );
		$this->assertSame( 404, $this->request( 'GET', 'sites/' . $this->sites[0] . '/plugins' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', 'tags/' . $this->tags[0] )->get_status() );
		$this->site_grants  = $this->sites;
		$this->group_grants = $this->tags;
		$this->assertSame( array( $this->sites[0] ), MainWP_Abilities_Util::get_allowed_site_ids() );
		$this->assertSame( array( $this->tags[0] ), MainWP_Abilities_Util::get_allowed_group_ids() );
		$this->assertSame( 200, $this->request( 'GET', 'sites/' . $this->sites[0] . '/plugins' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', 'sites/' . $this->sites[1] . '/plugins' )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', 'tags/' . $this->tags[0] )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', 'tags/' . $this->tags[1] )->get_status() );
		$this->single = true;
		$this->assertSame( $this->sites, MainWP_Abilities_Util::get_allowed_site_ids() );
		$this->assertSame( $this->tags, MainWP_Abilities_Util::get_allowed_group_ids() );
		$response = $this->request( 'GET', 'sites/' . $this->sites[1] . '/plugins' );
		$this->assertSame( 200, $response->get_status() );
		$response = $this->request( 'PUT', 'tags/' . $this->tags[1] . '/edit', array( 'name' => 'Test Tag Single User' ) );
		$this->assertSame( 1, $response->get_data()['success'] );
		$deleted = $this->execute_ability(
			'mainwp/delete-tag-v1',
			array(
				'tag_id'  => $this->tags[1],
				'confirm' => true,
			)
		);
		$this->assertNotWPError( $deleted );
		$deleted = $this->execute_ability(
			'mainwp/delete-site-v1',
			array(
				'site_id_or_domain' => $this->sites[1],
				'confirm'           => true,
			)
		);
		$this->assertNotWPError( $deleted );
	}

	/** Ownership never grants ability permission to a demoted key or queued owner. */
	public function test_demoted_owner_and_queue_scope(): void {
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$user = new \WP_User( $this->owner );
		$user->set_role( 'subscriber' );
		$this->keys['read_write'] = $this->create_rest_api_key( $this->owner );
		$response                 = $this->request( 'GET', 'sites/' . $this->sites[0] . '/plugins' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ability_invalid_permissions', $response->get_data()['code'] );
		$this->authenticate( 'GET', 'sites/' . $this->sites[0] . '/plugins' );
		$result = $this->execute_ability( 'mainwp/get-site-plugins-v1', array( 'site_id_or_domain' => $this->sites[0] ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$cron    = MainWP_Abilities_Cron::instance();
		$restore = new \ReflectionMethod( $cron, 'restore_job_owner' );
		$restore->setAccessible( true );
		$this->assertFalse( $restore->invoke( $cron, array( 'user_id' => $this->owner ) ) );
		$this->site_grants = $this->sites;
		$this->assertSame( 200, $this->request( 'GET', 'sites/' . $this->sites[0] . '/plugins' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', 'sites/' . $this->sites[1] . '/plugins' )->get_status() );
		$this->assertTrue( $restore->invoke( $cron, array( 'user_id' => $this->owner ) ) );
		$this->site_grants = 'all';
		$user->set_role( 'administrator' );
		wp_set_current_user( $this->owner );
		$scope = new \ReflectionMethod( $cron, 'get_job_allowed_site_ids' );
		$scope->setAccessible( true );
		$this->assertSame( array( $this->sites[0] ), $scope->invoke( $cron, array( 'user_id' => $this->owner ) ) );
	}

	/** All-site background jobs capture only owned targets. */
	public function test_all_site_job_targeting(): void {
		$response = $this->request( 'POST', 'settings/tools/destroy-sessions' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['success'] );
		$id  = $data['destroy_id'];
		$job = get_transient( 'mainwp_tool_destroy_job_' . $id );
		try {
			$this->assertSame( array( $this->sites[0] ), array_map( 'intval', $job['websites'] ) );
			$this->assertSame( 1, $job['total'] );
			$this->assertSame( array(), $this->transport );
		} finally {
			delete_transient( 'mainwp_tool_destroy_job_' . $id );
			wp_clear_scheduled_hook( 'mainwp_cron_destroy_session_batch', array( $id ) );
		}
	}

	/** REST tag reads retain key permissions and hide foreign member IDs. */
	public function test_rest_tag_member_output_and_non_admin_read(): void {
		$this->assign_site_to_tag( $this->sites[1], $this->tags[0] );
		$response = $this->request( 'GET', 'tags/' . $this->tags[0], array(), 'read' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $this->sites[0] ), wp_parse_id_list( $response->get_data()['data']['sites_ids'] ) );
		$user = new \WP_User( $this->owner );
		$user->set_role( 'subscriber' );
		$this->keys['read'] = $this->create_rest_api_key( $this->owner, 'read' );
		$response           = $this->request( 'GET', 'tags/' . $this->tags[0], array(), 'read' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->tags[0], $response->get_data()['data']['id'] );
		$response = $this->request( 'GET', 'tags/' . $this->tags[1], array(), 'read' );
		$this->assertSame( 404, $response->get_status() );
	}

	/** Owned-site operation denials retain the existing forbidden response. */
	public function test_owned_site_denial_keeps_403(): void {
		$ability  = wp_get_ability( 'mainwp/list-ignored-updates-v1' );
		$property = new \ReflectionProperty( $ability, 'permission_callback' );
		$property->setAccessible( true );
		$original = $property->getValue( $ability );
		try {
			$property->setValue( $ability, '__return_false' );
			$response = $this->request( 'GET', 'updates/' . $this->sites[0] . '/ignored' );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'ability_invalid_permissions', $response->get_data()['code'] );
			$this->assertSame( 404, $this->request( 'GET', 'updates/' . $this->sites[1] . '/ignored' )->get_status() );
		} finally {
			$property->setValue( $ability, $original );
		}
	}

	/** Another owner's job retains its existing forbidden response. */
	public function test_foreign_job_denial_keeps_403(): void {
		$this->authenticate( 'GET', 'tags' );
		$job_id = 'sync_ownership_fixture';
		set_transient( 'mainwp_sync_job_' . $job_id, array( 'user_id' => $this->owner + 1000 ), DAY_IN_SECONDS );
		try {
			$result = $this->execute_ability( 'mainwp/get-batch-job-status-v1', array( 'job_id' => $job_id ) );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_permission_denied', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		} finally {
			delete_transient( 'mainwp_sync_job_' . $job_id );
		}
	}

	/** Global routes use configured grants rather than the effective owned subset. */
	public function test_global_route_and_userless_contexts(): void {
		$response = $this->request( 'PUT', 'settings/general/edit', array( 'backup_before_upgrade' => 1 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) get_option( 'mainwp_backup_before_upgrade' ) );
		$this->site_grants = array( $this->sites[0] );
		$response          = $this->request( 'PUT', 'settings/general/edit', array( 'backup_before_upgrade' => 0 ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mainwp_restricted_account', $response->get_data()['code'] );
		$property = new \ReflectionProperty( MainWP_Abilities_Util::class, 'machine_context_override' );
		$property->setAccessible( true );
		$original = $property->getValue();
		try {
			wp_set_current_user( 0 );
			$property->setValue( null, true );
			$this->assertSame( 'all', MainWP_Abilities_Util::get_scoped_site_ids_or_all() );
			$this->assertSame( 'all', MainWP_Abilities_Util::get_scoped_group_ids_or_all() );
			$this->assertSame( 'all', MainWP_Abilities_Util::get_scoped_site_ids_or_all( false ) );
			$this->assertSame( 'all', MainWP_Abilities_Util::get_scoped_group_ids_or_all( false ) );
			$property->setValue( null, 'rest' );
			$this->assertSame( array(), MainWP_Abilities_Util::get_scoped_site_ids_or_all() );
			$this->assertSame( array(), MainWP_Abilities_Util::get_scoped_group_ids_or_all() );
			$this->assertSame( array(), MainWP_Abilities_Util::get_scoped_site_ids_or_all( false ) );
			$this->assertSame( array(), MainWP_Abilities_Util::get_scoped_group_ids_or_all( false ) );
			$guard = ( new \MainWP_Rest_Settings_Controller() )->get_unrestricted_permissions_check( new \WP_REST_Request( 'PUT', '/mainwp/v2/settings/general/edit' ) );
			$this->assertWPError( $guard );
			$this->assertSame( 'mainwp_restricted_account', $guard->get_error_code() );
		} finally {
			$property->setValue( null, $original );
		}
	}
}
