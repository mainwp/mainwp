<?php
/**
 * Activation ability previews and legacy execution.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Covers preview safety and optional confirmation.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_Activation_Previews extends MainWP_Abilities_Test_Case {

	/**
	 * Activation operations.
	 *
	 * @return array
	 */
	public function operations() {
		return array(
			'activate plugins'   => array( 'activate-site-plugins', 'plugins', array( 'sample/sample.php' ), 'plugin_action', 'activate', 'plugin' ),
			'deactivate plugins' => array( 'deactivate-site-plugins', 'plugins', array( 'sample/sample.php' ), 'plugin_action', 'deactivate', 'plugin' ),
			'activate theme'     => array( 'activate-site-theme', 'theme', 'sample', 'theme_action', 'activate', 'theme' ),
		);
	}

	/**
	 * The MCP gate must discover optional confirmation and preview support.
	 *
	 * @dataProvider operations
	 * @param string $operation Ability operation.
	 * @return void
	 */
	public function test_gate_metadata( $operation ) {
		$this->skip_if_no_abilities_api();
		$ability = wp_get_ability( 'mainwp/' . $operation . '-v1' );
		$schema  = $ability->get_input_schema();
		$this->assertTrue( $ability->get_meta()['annotations']['destructive'] );
		$this->assertFalse( $ability->get_meta()['annotations']['idempotent'] );
		foreach ( array( 'dry_run', 'confirm' ) as $key ) {
			$this->assertSame( 'boolean', $schema['properties'][ $key ]['type'] );
			$this->assertFalse( $schema['properties'][ $key ]['default'] );
			$this->assertNotContains( $key, $schema['required'] );
		}
		$this->assertStringContainsString( 'Without dry_run', $ability->get_description() );
		$preview = $ability->get_output_schema()['properties']['would_affect']['items']['properties'];
		$this->assertSame( array( 'boolean', 'null' ), $preview['active']['type'] );
		$this->assertSame( array( 'boolean', 'null' ), $preview['will_change']['type'] );
	}

	/**
	 * Previews describe the synced state without any Child request.
	 *
	 * @dataProvider operations
	 * @param string $operation Ability operation.
	 * @param string $key Input key.
	 * @param mixed  $target Requested target.
	 * @return void
	 */
	public function test_preview_does_not_call_child( $operation, $key, $target ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$slug    = is_array( $target ) ? $target[0] : $target;
		$site_id = $this->create_test_site(
			array(
				'plugins' => wp_json_encode(
					array(
						array(
							'slug'   => $slug,
							'Name'   => 'Sample',
							'active' => true,
						),
					)
				),
				'themes'  => wp_json_encode(
					array(
						$slug => array(
							'Name'   => 'Sample',
							'active' => false,
						),
						'old' => array(
							'Name'   => 'Old Theme',
							'active' => true,
						),
					)
				),
			)
		);
		$calls   = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function () use ( &$calls ) {
				++$calls;
				return array( 'success' => true );
			}
		);
		$result = $this->execute_ability(
			'mainwp/' . $operation . '-v1',
			array(
				'site_id_or_domain' => $site_id,
				$key                => $target,
				'dry_run'           => true,
			)
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 0, $calls, 'A preview must not contact Child.' );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['count'] );
		$item = $result['would_affect'][0];
		$this->assertSame( $slug, $item['slug'] );
		$this->assertSame( 'Sample', $item['name'] );
		$this->assertSame( 'theme' !== $key, $item['active'] );
		$this->assertSame( 'activate-site-plugins' !== $operation, $item['will_change'] );
		$this->assertNotEmpty( $result['warnings'] );
		if ( 'theme' === $key ) {
			$this->assertSame( 'old', $result['current_theme']['slug'] );
			$this->assertTrue( $result['current_theme']['active'] );
		}
	}

	/**
	 * Missing inventory must not be reported as an inactive target.
	 *
	 * @dataProvider operations
	 * @param string $operation Ability operation.
	 * @param string $key Input key.
	 * @param mixed  $target Requested target.
	 * @return void
	 */
	public function test_preview_preserves_unknown_state( $operation, $key, $target ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site(
			array(
				'plugins' => 'invalid-json',
				'themes'  => '',
			)
		);
		$this->mock_child_site_response( $site_id, array( 'success' => true ) );
		$result = $this->execute_ability(
			'mainwp/' . $operation . '-v1',
			array(
				'site_id_or_domain' => $site_id,
				$key                => $target,
				'dry_run'           => true,
			)
		);
		$this->assertNotWPError( $result );
		$this->assertNull( $result['would_affect'][0]['active'] );
		$this->assertNull( $result['would_affect'][0]['will_change'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	/**
	 * Optional confirmation must preserve the existing mutation contract.
	 *
	 * @dataProvider operations
	 * @param string $operation Ability operation.
	 * @param string $key Input key.
	 * @param mixed  $target Requested target.
	 * @param string $command Child command.
	 * @param string $action Child action.
	 * @param string $parameter Child parameter.
	 * @return void
	 */
	public function test_legacy_execution( $operation, $key, $target, $command, $action, $parameter ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$calls   = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $site, $what, $params ) use ( &$calls ) {
				$calls[] = array( $what, $params );
				return array( 'success' => true );
			},
			10,
			4
		);
		foreach ( array( array(), array( 'confirm' => false ), array( 'confirm' => true ) ) as $flags ) {
			$result = $this->execute_ability(
				'mainwp/' . $operation . '-v1',
				array_merge(
					array(
						'site_id_or_domain' => $site_id,
						$key                => $target,
					),
					$flags
				)
			);
			$this->assertNotWPError( $result );
			$this->assertArrayHasKey( 'deactivate' === $action ? 'deactivated' : 'activated', $result );
			$this->assertArrayNotHasKey( 'dry_run', $result );
		}
		$this->assertCount( 3, $calls );
		foreach ( $calls as $call ) {
			$this->assertSame(
				array(
					$command,
					array(
						'action'   => $action,
						$parameter => is_array( $target ) ? $target[0] : $target,
					),
				),
				$call
			);
		}
	}

	/**
	 * Existing REST clients must keep using POST without confirmation.
	 *
	 * @dataProvider operations
	 * @param string $operation Ability operation.
	 * @param string $key Input key.
	 * @param mixed  $target Requested target.
	 * @return void
	 */
	public function test_legacy_rest_post_without_confirm( $operation, $key, $target ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$calls   = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function () use ( &$calls ) {
				++$calls;
				return array( 'success' => true );
			}
		);
		$request = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/mainwp/' . $operation . '-v1/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'input' => array(
						'site_id_or_domain' => $site_id,
						$key                => $target,
					),
				)
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Contradictory flags must fail before any mutation.
	 *
	 * @dataProvider operations
	 * @param string $operation Ability operation.
	 * @param string $key Input key.
	 * @param mixed  $target Requested target.
	 * @return void
	 */
	public function test_conflicting_flags( $operation, $key, $target ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$calls = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function () use ( &$calls ) {
				++$calls;
				return array( 'success' => true );
			}
		);
		$result = $this->execute_ability(
			'mainwp/' . $operation . '-v1',
			array(
				'site_id_or_domain' => $this->create_test_site(),
				$key                => $target,
				'dry_run'           => true,
				'confirm'           => true,
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
		$this->assertSame( 0, $calls );
	}
}
