<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit's file loader matches the class name.
/**
 * Cost Tracker settings use their own Team Control right.
 *
 * Runs production handlers with isolated WordPress/storage doubles, without
 * a WordPress installation. See fixtures/cost-settings-rights.inc.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use PHPUnit\Framework\TestCase;

/** Cover browser and REST writes while preserving Chrome's existing GET probe. */
class Test_Cost_Tracker_Settings_Rights extends TestCase {

	/**
	 * Run a fresh process for one request.
	 *
	 * @param array $input Probe configuration.
	 * @return array Probe result.
	 */
	private function probe( array $input ): array {
		$input  += array( 'cost_settings_mode' => 'rest' );
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

	/**
	 * Settings currency, product types and payment methods share one right.
	 *
	 * @return array
	 */
	public static function writers(): array {
		return array(
			array( 'edit_cost_tracker_settings', 'PUT', array( 'currency' => 'EUR' ) ),
			array( 'add_cost_tracker_product_type', 'POST', array( 'title' => 'New product' ) ),
			array(
				'update_cost_tracker_product_type',
				'PATCH',
				array(
					'slug'  => 'held-product',
					'title' => 'Updated product',
				),
			),
			array( 'delete_cost_tracker_product_type', 'DELETE', array( 'slug' => 'held-product' ) ),
			array( 'add_cost_tracker_payment_method', 'POST', array( 'title' => 'New payment' ) ),
			array(
				'update_cost_tracker_payment_method',
				'PATCH',
				array(
					'slug'  => 'held-payment',
					'title' => 'Updated payment',
				),
			),
			array( 'delete_cost_tracker_payment_method', 'DELETE', array( 'slug' => 'held-payment' ) ),
		);
	}

	/**
	 * The Cost Tracker right is independent from general settings permission.
	 *
	 * @dataProvider writers
	 * @param string $method REST handler.
	 * @param string $http   HTTP method.
	 * @param array  $params Valid request parameters.
	 */
	public function test_rest_writes_require_cost_settings_right( $method, $http, $params ): void {
		foreach ( array( false, true ) as $allowed ) {
			$result = $this->probe(
				array(
					'method'      => $method,
					'http_method' => $http,
					'params'      => $params,
					'rights'      => array(
						'dashboard' => array(
							'manage_costs_settings'     => $allowed,
							'manage_dashboard_settings' => ! $allowed,
						),
					),
				)
			);
			$this->assertSame( array( array( 'dashboard', 'manage_costs_settings', 42 ) ), $result['checks'] );
			if ( $allowed ) {
				$this->assertNull( $result['error'] );
				$this->assertSame( 1, $result['result']['success'] );
				$this->assertSame( 1, $result['writes'] );
				$this->assertNotSame( $result['before'], $result['options'] );
			} else {
				$this->assertSame( 'mainwp_insufficient_right', $result['error']['code'] );
				$this->assertSame(
					array(
						'status' => 403,
						'right'  => 'manage_costs_settings',
					),
					$result['error']['data']
				);
				$this->assertSame( 0, $result['writes'] );
				$this->assertSame( $result['before'], $result['options'] );
			}
		}
	}

	/**
	 * Existing authentication and global site/tag scope checks stay enforced.
	 *
	 * @dataProvider writers
	 * @param string $method REST handler.
	 * @param string $http   HTTP method.
	 * @param array  $params Valid request parameters.
	 */
	public function test_existing_rest_guards_remain_enforced( $method, $http, $params ): void {
		$cases = array(
			array( array( 'scope' => 'read' ), 'mainwp_rest_authentication_error' ),
			array( array( 'unauthenticated' => true ), 'mainwp_rest_authentication_error' ),
			array( array( 'sites' => array( 1 ) ), 'mainwp_restricted_account' ),
			array( array( 'groups' => array() ), 'mainwp_restricted_account' ),
		);
		foreach ( $cases as list( $extra, $code ) ) {
			$result = $this->probe(
				array_merge(
					array(
						'method'      => $method,
						'http_method' => $http,
						'params'      => $params,
					),
					$extra
				)
			);
			$this->assertSame( $code, $result['error']['code'] );
			$this->assertSame( array(), $result['checks'] );
			$this->assertSame( 0, $result['writes'] );
			$this->assertSame( $result['before'], $result['options'] );
		}
	}

	/** Browser rendering and form submission use the same canonical right. */
	public function test_browser_settings_use_cost_settings_right(): void {
		foreach ( array( 'screen', 'post' ) as $mode ) {
			foreach ( array( false, true ) as $allowed ) {
				$result = $this->probe(
					array(
						'cost_settings_mode' => $mode,
						'cost_browser'       => true,
						'rights'             => array(
							'dashboard' => array(
								'manage_costs_settings' => $allowed,
								'manage_cost_tracker'   => ! $allowed,
								'manage_dashboard_settings' => ! $allowed,
							),
						),
					)
				);
				$this->assertSame( ! $allowed, $result['denied'] );
				$this->assertSame( array( array( 'dashboard', 'manage_costs_settings', 42 ) ), $result['checks'] );
				$this->assertSame( $allowed ? ( 'screen' === $mode ? 'render settings' : 'redirect' ) : null, $result['boundary'] );
				$this->assertSame( $allowed && 'post' === $mode ? 1 : 0, $result['writes'] );
				if ( ! $allowed ) {
					$this->assertSame( $result['before'], $result['options'] );
				}
			}
		}
	}

	/** A granted permission never substitutes for the existing form nonce. */
	public function test_form_still_requires_valid_nonce(): void {
		foreach ( array( array( 'nonce' => 'invalid' ), array( 'missing_nonce' => true ) ) as $extra ) {
			$result = $this->probe(
				array_merge(
					array(
						'cost_settings_mode' => 'post',
						'cost_browser'       => true,
					),
					$extra
				)
			);
			$this->assertSame( 0, $result['writes'] );
			$this->assertSame( array(), $result['checks'] );
			$this->assertSame( $result['before'], $result['options'] );
		}
	}

	/** The default grant used without Team Control still permits normal access. */
	public function test_default_grants_preserve_existing_access(): void {
		$result = $this->probe(
			array(
				'method' => 'edit_cost_tracker_settings',
				'params' => array( 'currency' => 'EUR' ),
			)
		);
		$this->assertNull( $result['error'] );
		$this->assertSame( 1, $result['writes'] );
		$this->assertSame( 'EUR', $result['options']['currency'] );
	}

	/** The GET permission change is deferred until Chrome stops using this probe. */
	public function test_existing_get_probe_still_works_without_settings_right(): void {
		$result = $this->probe(
			array(
				'method'      => 'get_cost_tracker_settings',
				'http_method' => 'GET',
				'scope'       => 'read',
				'rights'      => array(
					'dashboard' => array(
						'manage_costs_settings'     => false,
						'manage_dashboard_settings' => false,
					),
				),
			)
		);
		$this->assertNull( $result['error'] );
		$this->assertSame( 1, $result['result']['success'] );
		$this->assertSame( 'USD', $result['result']['data']['currency'] );
		$this->assertSame( array(), $result['checks'] );
		$this->assertSame( 0, $result['writes'] );
	}
}
