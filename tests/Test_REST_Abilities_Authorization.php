<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit's file loader matches the class name.
/**
 * Scoped REST v2 owners can use internal abilities without manage_options.
 *
 * Uses isolated subprocesses and the production authorization boundaries.
 * See fixtures/rest-abilities-authorization.inc for the WordPress doubles.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use PHPUnit\Framework\TestCase;

/** Regression coverage for Team Control role migration and internal abilities. */
class Test_REST_Abilities_Authorization extends TestCase {

	/**
	 * Run one isolated authorization probe.
	 *
	 * @param array $input Scenario configuration.
	 * @return array Probe result.
	 */
	private function probe( array $input ): array {
		$input  += array( 'abilities_mode' => 'gate' );
		$process = proc_open(
			array( PHP_BINARY, __DIR__ . '/fixtures/rest-operation-rights.inc', json_encode( $input, JSON_THROW_ON_ERROR ) ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertSame( '', $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}

	/** The reported failures reach their actual registered ability permission callbacks. */
	public function test_rest_handlers_accept_migrated_scoped_roles(): void {
		$cases = array(
			array( 'MainWP_Rest_Sites_Controller', 'get_item', array( 'id_domain' => 1 ), 'GET', 'mainwp/get-site-v1' ),
			array( 'MainWP_Rest_Updates_Controller', 'get_items', array(), 'GET', 'mainwp/list-updates-v1' ),
			array( 'MainWP_Rest_Updates_Controller', 'update_all_items_start', array(), 'POST', 'mainwp/run-updates-v1' ),
		);
		foreach ( $cases as list( $controller, $method, $params, $http, $ability ) ) {
			$result = $this->probe(
				array(
					'abilities_mode' => 'controller',
					'controller'     => $controller,
					'method'         => $method,
					'params'         => $params,
					'http_method'    => $http,
				)
			);
			$this->assertNull( $result['error'] );
			$this->assertSame( 'ability:' . $ability, $result['boundary'] );
			$this->assertFalse( $result['manage_options'], 'No WordPress capability is granted.' );
			$this->assertNull( $result['after_permission'], 'Authorization ends even when execution throws.' );
		}
	}

	/** Every ability currently used internally by REST v2 accepts the scoped owner. */
	public function test_registered_internal_ability_permissions_accept_scoped_roles(): void {
		$abilities = array(
			'mainwp/get-site-v1'             => array( 'site_id_or_domain' => 1 ),
			'mainwp/list-sites-v1'           => array(),
			'mainwp/sync-sites-v1'           => array( 'site_ids' => array( 1 ) ),
			'mainwp/get-site-plugins-v1'     => array( 'site_id_or_domain' => 1 ),
			'mainwp/get-site-themes-v1'      => array( 'site_id_or_domain' => 1 ),
			'mainwp/list-updates-v1'         => array(),
			'mainwp/list-ignored-updates-v1' => array(),
			'mainwp/run-updates-v1'          => array( 'confirm' => true ),
		);
		foreach ( $abilities as $ability => $input ) {
			$result = $this->probe(
				array(
					'abilities_mode' => 'ability',
					'ability'        => $ability,
					'ability_input'  => $input,
					'http_method'    => 'POST',
				)
			);
			$this->assertNull( $result['error'], $ability );
			$this->assertSame( 'ability:' . $ability, $result['boundary'] );
		}
	}

	/** Missing operation permissions and inaccessible targets remain blocked. */
	public function test_internal_abilities_still_require_operation_and_site_access(): void {
		foreach ( array( 'update_wordpress', 'update_plugins', 'update_themes', 'update_translations' ) as $right ) {
			$result = $this->probe(
				array(
					'abilities_mode' => 'ability',
					'ability'        => 'mainwp/run-updates-v1',
					'http_method'    => 'POST',
					'rights'         => array( 'dashboard' => array( $right => false ) ),
				)
			);
			$this->assertSame( 'mainwp_insufficient_right', $result['error']['code'] );
			$this->assertSame( $right, $result['error']['data']['right'] );
			$this->assertNull( $result['boundary'] );
		}
		$result = $this->probe(
			array(
				'abilities_mode' => 'ability_execute',
				'ability'        => 'mainwp/get-site-v1',
				'ability_input'  => array( 'site_id_or_domain' => 2 ),
				'sites'          => array( 1 ),
			)
		);
		$this->assertSame( 'mainwp_site_not_found', $result['error']['code'] );
		$this->assertSame( 404, $result['error']['data']['status'] );
		$this->assertNull( $result['boundary'] );
	}

	/**
	 * Internal calls recheck method scope, including administrator-owned keys.
	 *
	 * @dataProvider key_scopes
	 * @param string $scope Key scope.
	 * @param string $http  Request method.
	 * @param bool   $allow Expected result.
	 */
	public function test_internal_ability_calls_require_key_scope( $scope, $http, $allow ): void {
		foreach ( array( false, true ) as $admin ) {
			$result = $this->probe(
				array(
					'scope'          => $scope,
					'http_method'    => $http,
					'manage_options' => $admin,
				)
			);
			if ( $allow ) {
				$this->assertTrue( $result['result'] );
			} else {
				$this->assertSame( 'mainwp_rest_authentication_error', $result['error']['code'] );
			}
		}
	}

	/**
	 * HTTP-method scope contracts.
	 *
	 * @return array
	 */
	public static function key_scopes(): array {
		$cases = array();
		foreach ( array( 'read', 'write', 'read_write', 'delete' ) as $scope ) {
			foreach ( array( 'GET', 'POST', 'PATCH', 'DELETE' ) as $http ) {
				$allow   = 'read_write' === $scope
					|| ( 'GET' === $http ? 'read' === $scope : ( 'DELETE' === $http ? in_array( $scope, array( 'write', 'delete' ), true ) : 'write' === $scope ) );
				$cases[] = array( $scope, $http, $allow );
			}
		}
		return $cases;
	}

	/** Direct Abilities, foreign routes, mismatched owners and subscribers gain no access. */
	public function test_exception_is_limited_to_authenticated_scoped_requests(): void {
		$cases = array(
			array( 'no_frame' => true ),
			array( 'route' => '/wp-abilities/v1/abilities/mainwp/list-sites-v1/run' ),
			array( 'route' => '/mainwp/v1/sites' ),
			array( 'route' => '/mainwp/v20/sites' ),
			array( 'route' => '/mainwp-backwpup/v1/sites' ),
			array( 'owner' => 99 ),
			array( 'user' => 0 ),
			array( 'unauthenticated' => true ),
			array( 'sites' => 'all' ),
			array( 'sites' => array() ),
		);
		foreach ( $cases as $case ) {
			$result = $this->probe( $case );
			$this->assertSame( 'mainwp_permission_denied', $result['error']['code'], json_encode( $case ) );
			$this->assertNull( $result['boundary'] );
			$this->assertNull( $result['after_permission'] );
		}
		// Existing administrator key access and session access retain their gates.
		foreach ( array( array( 'sites' => 'all' ), array( 'no_frame' => true ), array( 'unauthenticated' => true ) ) as $case ) {
			$result = $this->probe( array_merge( array( 'manage_options' => true ), $case ) );
			$this->assertTrue( $result['result'] );
		}
	}

	/** Nested non-v2 requests cannot borrow the outer authorization. */
	public function test_nested_request_does_not_inherit_authorization(): void {
		foreach ( array( '/wp-abilities/v1/abilities/mainwp/list-sites-v1/run', '/wp/v2/users', '/mainwp/v1/sites' ) as $route ) {
			$result = $this->probe(
				array(
					'abilities_mode' => 'nested',
					'nested_route'   => $route,
				)
			);
			$this->assertSame(
				array(
					true,
					array(
						'code' => 'mainwp_permission_denied',
						'data' => array( 'status' => 403 ),
					),
					true,
				),
				$result['states']
			);
			$this->assertNull( $result['after_permission'] );
		}
	}

	/** Queued updates recheck the owner and grants, and restore the original cron user. */
	public function test_queued_updates_recheck_scoped_owner_permissions(): void {
		$result = $this->probe(
			array(
				'abilities_mode' => 'cron',
				'user'           => 0,
			)
		);
		$this->assertSame( 'upgrade', $result['boundary'] );
		$this->assertSame( 0, $result['current_user'] );
		$this->assertSame( array( array( 'dashboard', 'update_wordpress', 42 ) ), $result['checks'] );
		foreach ( array(
			array( 'sites' => 'all' ),
			array( 'sites' => array() ),
			array( 'owner_deleted' => true ),
			array( 'sites' => array( 2 ) ),
			array( 'rights' => array( 'dashboard' => array( 'update_wordpress' => false ) ) ),
		) as $case ) {
			$result = $this->probe(
				array_merge(
					array(
						'abilities_mode' => 'cron',
						'user'           => 0,
					),
					$case
				)
			);
			$this->assertNull( $result['boundary'], json_encode( $case ) );
			$this->assertSame( 'access_revoked', $result['result']['errors'][0]['code'] );
			$this->assertSame( 0, $result['current_user'] );
		}
		$result = $this->probe(
			array(
				'abilities_mode' => 'cron',
				'user'           => 0,
				'manage_options' => true,
				'sites'          => 'all',
			)
		);
		$this->assertSame( 'upgrade', $result['boundary'] );
		$this->assertSame( 0, $result['current_user'] );
	}
}
