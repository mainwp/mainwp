<?php
/**
 * Missing and inaccessible sites must have the same REST refusal.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Exercise the registered routes without process-wide authentication constants.
 */
class Test_Single_Site_Not_Found_REST extends MainWP_Abilities_Test_Case {

	/**
	 * Server for this request fixture.
	 *
	 * @var \WP_REST_Server
	 */
	private $server;

	/**
	 * Previous REST server.
	 *
	 * @var mixed
	 */
	private $previous_server;

	/**
	 * Existing site owned by the fixture administrator.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Filters installed by this fixture.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Child requests recorded before communication.
	 *
	 * @var array
	 */
	private $child_requests = array();

	/** Set up a real registered REST route and local site. */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$this->site_id = $this->create_test_site();
		global $wp_rest_server;
		$this->previous_server = $wp_rest_server;
		$wp_rest_server        = new \WP_REST_Server();
		$this->server          = $wp_rest_server;
		do_action( 'rest_api_init' );
		$this->add_test_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $site, $action ) {
				$this->child_requests[] = array( (int) $site->id, $action );
				return new \WP_Error( 'unexpected_child_request', 'A refused request must not contact a child site.' );
			},
			3
		);
	}

	/** Restore only the server and filters changed by this fixture. */
	public function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], 10 );
		}
		global $wp_rest_server;
		$wp_rest_server = $this->previous_server;
		parent::tearDown();
	}

	/**
	 * Keep shared hooks intact after each case.
	 *
	 * @param string   $hook Hook name.
	 * @param callable $callback Callback.
	 * @param int      $arguments Accepted arguments.
	 */
	private function add_test_filter( $hook, $callback, $arguments = 1 ) {
		$this->filters[] = array( $hook, $callback );
		add_filter( $hook, $callback, 10, $arguments );
	}

	/**
	 * Every registration using the single-site permission factory, plus retired actions.
	 *
	 * @return array
	 */
	public static function single_site_abilities() {
		$cases = array();
		foreach ( array( 'get-site', 'get-site-plugins', 'get-site-themes', 'get-abandoned-plugins', 'get-abandoned-themes', 'get-site-security', 'get-site-changes', 'get-site-client', 'get-site-costs', 'get-site-updates', 'get-site-knowledge' ) as $name ) {
			$cases[ $name ] = array( $name, 'GET', array() );
		}
		foreach ( array( 'core', 'plugins', 'themes', 'translations' ) as $type ) {
			$name           = 'update-site-' . $type;
			$cases[ $name ] = array( $name, 'POST', array( 'dry_run' => true ) );
		}
		$cases['set-ignored-updates']   = array(
			'set-ignored-updates',
			'POST',
			array(
				'action' => 'ignore',
				'type'   => 'plugin',
				'slug'   => 'sample/plugin.php',
			),
		);
		$cases['unignore-site-updates'] = array(
			'unignore-site-updates',
			'DELETE',
			array(
				'type'    => 'plugin',
				'slugs'   => array( 'sample/plugin.php' ),
				'dry_run' => true,
			),
		);
		foreach ( array( 'core', 'plugins', 'themes' ) as $type ) {
			$name                       = 'ignore-site-' . $type;
			$extra                      = 'core' === $type ? array() : array( 'slugs' => array( 'plugins' === $type ? 'sample/plugin.php' : 'sample-theme' ) );
			$cases[ $name ]             = array( $name, 'POST', $extra + array( 'action' => 'add' ) );
			$cases[ $name . '-remove' ] = array( $name, 'POST', $extra + array( 'action' => 'remove' ) );
		}
		$cases['set-ignored-updates-unignore'] = array(
			'set-ignored-updates',
			'POST',
			array(
				'action' => 'unignore',
				'type'   => 'plugin',
				'slug'   => 'sample/plugin.php',
			),
		);
		return $cases;
	}

	/**
	 * Mutations must retain the operation-right gate.
	 *
	 * @return array
	 */
	public static function mutating_abilities() {
		return array_filter(
			self::single_site_abilities(),
			static function ( $ability_case ) {
				return 'GET' !== $ability_case[1];
			}
		);
	}

	/**
	 * Reads whose execute-stage guard must preserve scoped refusals.
	 *
	 * @return array
	 */
	public static function execute_access_reads() {
		return array_intersect_key(
			self::single_site_abilities(),
			array_fill_keys( array( 'get-site', 'get-site-plugins', 'get-site-themes', 'get-site-knowledge' ), true )
		);
	}

	/**
	 * Dispatch with the documented method and JSON transport.
	 *
	 * @param string $name Ability slug.
	 * @param string $method HTTP method.
	 * @param array  $input Valid input.
	 * @return \WP_REST_Response
	 */
	private function request( $name, $method, array $input ) {
		if ( 'get-site-knowledge' === $name ) {
			$input['site_id'] = $input['site_id_or_domain'];
			unset( $input['site_id_or_domain'] );
		}
		$ability = wp_get_ability( 'mainwp/' . $name . '-v1' );
		$this->assertNotNull( $ability );
		$valid = rest_validate_value_from_schema( $input, $ability->get_input_schema() );
		$this->assertTrue( $valid, is_wp_error( $valid ) ? $valid->get_error_message() : '' );
		$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
		if ( 'GET' === $method ) {
			$request->set_query_params( array( 'input' => $input ) );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Capture site rows, holds, scheduled work and persisted job records.
	 *
	 * @return array
	 */
	private function state() {
		global $wpdb;
		return array(
			'sites'   => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp ORDER BY id", ARRAY_A ),
			'options' => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_options ORDER BY wpid, name", ARRAY_A ),
			'jobs'    => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%mainwp%job%' ORDER BY option_name", ARRAY_A ),
			'cron'    => get_option( 'cron' ),
		);
	}

	/**
	 * Assert the complete refusal without depending on core's status rewriting.
	 *
	 * @param \WP_REST_Response $response REST response.
	 * @param int               $identifier Requested site ID.
	 */
	private function assert_missing( $response, $identifier ) {
		$this->assertSame( 404, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'mainwp_site_not_found', $response->get_data()['code'] );
		$this->assertSame( sprintf( 'No site found with ID %d.', $identifier ), $response->get_data()['message'] );
	}

	/**
	 * A missing site must reach execution with its original 404.
	 *
	 * @dataProvider single_site_abilities
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_missing_site_is_404( $name, $method, $extra ) {
		$before   = $this->state();
		$response = $this->request( $name, $method, array( 'site_id_or_domain' => 999999999 ) + $extra );
		$this->assertSame( $before, $this->state(), 'Missing site requests must not write local state.' );
		$this->assertSame( array(), $this->child_requests );
		$this->assert_missing( $response, 999999999 );
	}

	/**
	 * Scoped denials must match the same identifier after the site is removed.
	 *
	 * @dataProvider single_site_abilities
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_scoped_site_is_identical_404_without_side_effects( $name, $method, $extra ) {
		$allowed_site = $this->create_test_site();
		$this->add_test_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $allowed_site ) {
				return array( $allowed_site );
			}
		);
		$knowledge_reads = 0;
		$this->add_test_filter(
			'query',
			static function ( $sql ) use ( &$knowledge_reads ) {
				if ( preg_match( '/^\s*SELECT\b/i', $sql ) && false !== strpos( $sql, 'mainwp_knowledge' ) ) {
					++$knowledge_reads;
				}
				return $sql;
			}
		);
		if ( 'unignore-site-updates' === $name ) {
			$this->set_site_ignored_plugins(
				$this->site_id,
				array(
					'sample/plugin.php' => array(
						'Name'             => 'Stored Hold',
						'ignored_versions' => array( 'all_versions' ),
					),
				)
			);
		}
		if ( isset( $extra['dry_run'] ) ) {
			$extra['dry_run'] = false;
			$extra['confirm'] = true;
		}
		$input    = array( 'site_id_or_domain' => $this->site_id ) + $extra;
		$before   = $this->state();
		$response = $this->request( $name, $method, $input );
		$this->assertSame( $before, $this->state(), 'Scoped site requests must not write local state.' );
		$this->assertSame( array(), $this->child_requests );
		$this->assertSame( 0, $knowledge_reads, 'Scoped refusals must not read knowledge records.' );
		$this->assert_missing( $response, $this->site_id );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $this->site_id ), array( '%d' ) );
		$missing = $this->request( $name, $method, $input );
		$this->assert_missing( $missing, $this->site_id );
		$this->assertSame( $response->get_data(), $missing->get_data() );
		$this->assertSame( array(), $this->child_requests );
	}

	/**
	 * A resolvable scoped site refused by its access filter must reach the execute guard.
	 *
	 * @dataProvider execute_access_reads
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_scoped_filter_denial_is_404_after_site_resolution( $name, $method, $extra ) {
		$site_id = $this->site_id;
		$this->add_test_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_id ) {
				return array( $site_id );
			}
		);
		$this->assertNotWPError( \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( $site_id ) );
		$this->add_test_filter( 'mainwp_check_site_access', '__return_false', 3 );
		$before   = $this->state();
		$response = $this->request( $name, $method, array( 'site_id_or_domain' => $site_id ) + $extra );
		$this->assertSame( $before, $this->state() );
		$this->assertSame( array(), $this->child_requests );
		$this->assert_missing( $response, $site_id );
	}

	/**
	 * Existing authorized sites retain their response and dry-run controls.
	 *
	 * @dataProvider single_site_abilities
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_authorized_existing_site_control( $name, $method, $extra ) {
		$site_id = $this->site_id;
		$this->add_test_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_id ) {
				return array( $site_id );
			}
		);
		$response = $this->request( $name, $method, array( 'site_id_or_domain' => $this->site_id ) + $extra );
		if ( isset( $extra['action'] ) && in_array( $extra['action'], array( 'unignore', 'remove' ), true ) ) {
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'mainwp_unignore_moved', $response->get_data()['code'] );
			$this->assertSame( 'mainwp/unignore-site-updates-v1', $response->get_data()['data']['replacement'] );
		} else {
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			if ( isset( $extra['dry_run'] ) ) {
				$this->assertTrue( $response->get_data()['dry_run'] );
			}
		}
		$this->assertSame( array(), $this->child_requests );
	}

	/**
	 * Missing operation rights still deny an existing authorized site.
	 *
	 * @dataProvider mutating_abilities
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_missing_operation_right_is_403( $name, $method, $extra ) {
		$this->add_test_filter( 'mainwp_currentusercan', '__return_false', 3 );
		$before   = $this->state();
		$response = $this->request( $name, $method, array( 'site_id_or_domain' => $this->site_id ) + $extra );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mainwp_insufficient_right', $response->get_data()['code'] );
		$this->assertSame( $before, $this->state() );
		$this->assertSame( array(), $this->child_requests );
	}

	/**
	 * Target deferral must not allow a missing target to skip operation rights.
	 *
	 * @dataProvider mutating_abilities
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_missing_target_without_operation_right_is_403( $name, $method, $extra ) {
		$this->add_test_filter( 'mainwp_currentusercan', '__return_false', 3 );
		$response = $this->request( $name, $method, array( 'site_id_or_domain' => 999999999 ) + $extra );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mainwp_insufficient_right', $response->get_data()['code'] );
		$this->assertSame( array(), $this->child_requests );
	}

	/** Knowledge read rights must remain distinct from operation rights. */
	public function test_knowledge_right_denial_control() {
		$this->add_test_filter( 'mainwp_currentusercan', '__return_false', 3 );
		$reads = 0;
		$this->add_test_filter(
			'query',
			static function ( $sql ) use ( &$reads ) {
				if ( preg_match( '/^\s*SELECT\b/i', $sql ) && false !== strpos( $sql, 'mainwp_knowledge' ) ) {
					++$reads;
				}
				return $sql;
			}
		);
		$response = $this->request( 'get-site-knowledge', 'GET', array( 'site_id_or_domain' => $this->site_id ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mainwp_access_denied', $response->get_data()['code'] );
		$this->assertSame( 0, $reads, 'Denied summaries must not read knowledge records.' );
	}

	/**
	 * Unscoped filter denials retain their access-denied 403.
	 *
	 * @dataProvider single_site_abilities
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_unscoped_access_denial_control( $name, $method, $extra ) {
		$this->add_test_filter( 'mainwp_check_site_access', '__return_false', 3 );
		$response = $this->request( $name, $method, array( 'site_id_or_domain' => $this->site_id ) + $extra );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mainwp_access_denied', $response->get_data()['code'] );
		$this->assertSame( array(), $this->child_requests );
	}

	/**
	 * Unauthenticated requests retain their authentication refusal.
	 *
	 * @dataProvider single_site_abilities
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_unauthenticated_control( $name, $method, $extra ) {
		wp_set_current_user( 0 );
		$response = $this->request( $name, $method, array( 'site_id_or_domain' => 999999999 ) + $extra );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( 'mainwp_permission_denied', $response->get_data()['code'] );
		$this->assertSame( array(), $this->child_requests );
	}

	/**
	 * Logged-in users without REST permission retain their 403.
	 *
	 * @dataProvider single_site_abilities
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_no_rest_permission_control( $name, $method, $extra ) {
		$this->set_current_user_as_subscriber();
		$response = $this->request( $name, $method, array( 'site_id_or_domain' => 999999999 ) + $extra );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mainwp_permission_denied', $response->get_data()['code'] );
		$this->assertSame( array(), $this->child_requests );
	}

	/**
	 * Direct PHP execution returns the site-not-found WP_Error.
	 *
	 * @dataProvider single_site_abilities
	 * @param string $name Slug.
	 * @param string $method Unused REST method.
	 * @param array  $extra Valid operation fields.
	 */
	public function test_direct_php_missing_site_is_404( $name, $method, $extra ) {
		$key    = 'get-site-knowledge' === $name ? 'site_id' : 'site_id_or_domain';
		$result = wp_get_ability( 'mainwp/' . $name . '-v1' )->execute( array( $key => 999999999 ) + $extra );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( 'No site found with ID 999999999.', $result->get_error_message() );
	}

	/** Native backups already return the documented missing-site 404. */
	public function test_native_backup_missing_site_control() {
		$response = $this->request( 'list-backup-tasks', 'GET', array( 'site_id_or_domain' => 999999999 ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'mainwp_site_not_found', $response->get_data()['code'] );
		$this->assertSame( 'The site was not found.', $response->get_data()['message'] );
		$this->assertSame( array(), $this->child_requests );
	}
}
