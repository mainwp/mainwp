<?php
/**
 * Site queries retain actor scope under REST, cron and WP-CLI.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\Rest_Api_V1;

/**
 * Exercise the DB query paths with each machine flag independently.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_SEC19_Machine_Site_Scope extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Let the WordPress bootstrap define constants in the isolated process.
	 *
	 * @param \PHPUnit\Framework\TestResult|null $result Current result collector.
	 * @return \PHPUnit\Framework\TestResult
	 */
	public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
		$configuration                           = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? null;
		$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = '';
		try {
			return parent::run( $result );
		} finally {
			$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration;
		}
	}

	/** Legacy keys authenticate in their controller without a WordPress owner. */
	public function test_legacy_key_route_keeps_its_site_count() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->create_test_site();
		$this->create_test_site();
		$key    = 'ck_' . bin2hex( random_bytes( 20 ) );
		$secret = 'cs_' . bin2hex( random_bytes( 20 ) );
		update_option(
			'mainwp_rest_api_keys',
			array(
				$key => array(
					'cs'      => wp_hash_password( $secret ),
					'enabled' => 1,
					'perms'   => 'r',
				),
			)
		);
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		Rest_Api_V1::instance()->mainwp_register_routes();
		$request = new \WP_REST_Request( 'GET', '/mainwp/v1/sites/all-sites-count' );
		$request->set_query_params(
			array(
				'consumer_key'    => $key,
				'consumer_secret' => $secret,
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 2, (int) $response->get_data()['count'] );
	}

	/**
	 * Execution flag and grant cases.
	 *
	 * @return array
	 */
	public static function context_cases() {
		$cases = array();
		foreach ( array( 'MAINWP_REST_API_DOING', 'DOING_CRON', 'WP_CLI' ) as $flag ) {
			foreach ( array( 'restricted', 'empty', 'invalid', 'admin', 'userless', 'missing_actor' ) as $scope ) {
				$cases[ $flag . '-' . $scope ] = array( $flag, $scope );
			}
		}
		$cases['REST takes precedence over CLI']  = array( 'rest-cli', 'userless' );
		$cases['REST takes precedence over cron'] = array( 'rest-cron', 'userless' );
		foreach ( array( 'restricted', 'userless', 'missing_actor' ) as $scope ) {
			$cases[ 'legacy REST-' . $scope ] = array( 'legacy-rest', $scope );
		}
		return $cases;
	}

	/**
	 * Queries must not turn restricted grants into all sites.
	 *
	 * @dataProvider context_cases
	 * @param string $flag Execution context.
	 * @param string $scope Grant shape.
	 */
	public function test_machine_site_queries( $flag, $scope ) {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$visible = $this->create_test_site();
		$hidden  = $this->create_test_site();

		if ( 'legacy-rest' === $flag ) {
			define( 'MAINWP_REST_API', true );
			define( 'MAINWP_REST_API_DOING', true );
		} elseif ( 'rest-cli' === $flag || 'rest-cron' === $flag ) {
			define( 'MAINWP_REST_API_DOING', true );
			$other_flag = 'rest-cli' === $flag ? 'WP_CLI' : 'DOING_CRON';
			define( $other_flag, true );
		} else {
			define( $flag, true );
		}
		$grants = array(
			'restricted'    => array( $visible ),
			'empty'         => array(),
			'invalid'       => array( 'bad', 0, -1 ),
			'admin'         => 'all',
			'userless'      => 'all',
			'missing_actor' => 'all',
		);
		$filter = static function () use ( $grants, $scope ) {
			return $grants[ $scope ];
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $filter, 999 );
		if ( 'userless' === $scope ) {
			wp_set_current_user( 0 );
		} elseif ( 'missing_actor' === $scope ) {
			$GLOBALS['current_user']->ID = 999999999;
		}
		try {
			$global   = 'admin' === $scope || ( 'userless' === $scope && in_array( $flag, array( 'DOING_CRON', 'WP_CLI', 'legacy-rest' ), true ) );
			$expected = $global ? array( $visible, $hidden ) : ( 'restricted' === $scope ? array( $visible ) : array() );
			$sites    = MainWP_DB::instance()->get_websites_for_current_user();
			$ids      = array_map( 'intval', wp_list_pluck( $sites, 'id' ) );
			sort( $ids );
			sort( $expected );
			$this->assertSame( $expected, $ids, $flag . ' / ' . $scope . ': list scope' );
			$this->assertSame( $global, (bool) MainWP_DB::instance()->get_website_by_id( $hidden ), 'Hidden single-site lookup' );
			$this->assertSame( '', $GLOBALS['wpdb']->last_error, 'Scope must produce valid SQL' );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $filter, 999 );
			wp_set_current_user( 0 );
		}
	}
}
