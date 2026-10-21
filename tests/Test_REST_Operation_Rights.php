<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit's file loader matches the class name.
/**
 * Regression coverage for Team Control operation rights on REST v2 handlers.
 *
 * Runs without a WordPress installation:
 * vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Test_REST_Operation_Rights.php
 *
 * Each probe loads the real controllers in a fresh subprocess with the REST
 * bypass constant set. Storage, transport and WordPress services are isolated
 * doubles; full WordPress routing and credential authentication need integration
 * testing separately.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use PHPUnit\Framework\TestCase;

/** Test the operation boundaries, including calls made directly by batch handlers. */
class Test_REST_Operation_Rights extends TestCase {

	/**
	 * Run an isolated probe without a shell or the parent process's WP globals.
	 *
	 * @param array $input Probe scenario.
	 * @return array Probe result.
	 */
	private function probe( array $input ): array {
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
	 * Every protected handler refuses its withheld right before doing any work.
	 *
	 * @dataProvider guarded_handlers
	 * @param string $controller Controller class.
	 * @param string $method     Operation handler.
	 * @param string $group      Permission group.
	 * @param string $right      Withheld permission.
	 */
	public function test_denied_handler_stops_before_data_access( $controller, $method, $group, $right ): void {
		$result = $this->probe(
			array(
				'controller' => $controller,
				'method'     => $method,
				'rights'     => array( $group => array( $right => false ) ),
			)
		);
		$this->assertSame( 'mainwp_insufficient_right', $result['error']['code'] );
		$this->assertSame(
			array(
				'status' => 403,
				'right'  => $right,
			),
			$result['error']['data']
		);
		$this->assertNull( $result['boundary'] );
		$this->assertSame( array( array( $group, $right, 42 ) ), $result['checks'] );
	}

	/**
	 * Operation-to-right contracts, independent of route dispatch.
	 *
	 * @return array
	 */
	public static function guarded_handlers(): array {
		return array(
			array( 'MainWP_Rest_Settings_Controller', 'update_general_settings', 'dashboard', 'manage_dashboard_settings' ),
			array( 'MainWP_Rest_Settings_Controller', 'update_api_backup_settings', 'dashboard', 'manage_api_backups' ),
			array( 'MainWP_Rest_Users_Controller', 'get_users', 'dashboard', 'manage_users' ),
			array( 'MainWP_Rest_Users_Controller', 'create_user', 'dashboard', 'manage_users' ),
			array( 'MainWP_Rest_Users_Controller', 'edit_user', 'dashboard', 'manage_users' ),
			array( 'MainWP_Rest_Users_Controller', 'delete_user', 'dashboard', 'manage_users' ),
			array( 'MainWP_Rest_Users_Controller', 'update_admin_password', 'dashboard', 'manage_users' ),
			array( 'MainWP_Rest_Users_Controller', 'import_users', 'dashboard', 'manage_users' ),
			array( 'MainWP_Rest_Posts_Controller', 'list_posts', 'dashboard', 'manage_posts' ),
			array( 'MainWP_Rest_Posts_Controller', 'get_post', 'dashboard', 'manage_posts' ),
			array( 'MainWP_Rest_Posts_Controller', 'update_status_post', 'dashboard', 'manage_posts' ),
			array( 'MainWP_Rest_Posts_Controller', 'edit_post', 'dashboard', 'manage_posts' ),
			array( 'MainWP_Rest_Posts_Controller', 'create_post', 'dashboard', 'manage_posts' ),
			array( 'MainWP_Rest_Posts_Controller', 'delete_post', 'dashboard', 'manage_posts' ),
			array( 'MainWP_Rest_Pages_Controller', 'list_pages', 'dashboard', 'manage_pages' ),
			array( 'MainWP_Rest_Pages_Controller', 'get_page', 'dashboard', 'manage_pages' ),
			array( 'MainWP_Rest_Pages_Controller', 'update_status_page', 'dashboard', 'manage_pages' ),
			array( 'MainWP_Rest_Pages_Controller', 'edit_page', 'dashboard', 'manage_pages' ),
			array( 'MainWP_Rest_Pages_Controller', 'create_page', 'dashboard', 'manage_pages' ),
			array( 'MainWP_Rest_Pages_Controller', 'delete_page', 'dashboard', 'manage_pages' ),
			array( 'MainWP_Rest_Sites_Controller', 'reconnect_item', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'reconnect_items', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'disconnect_site', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'disconnect_sites', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'suspend_item', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'unsuspend_item', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'check_item', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'check_items', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'update_item', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'create_item', 'dashboard', 'add_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'delete_item', 'dashboard', 'delete_sites' ),
			array( 'MainWP_Rest_Sites_Controller', 'activate_plugins_of_site', 'dashboard', 'activate_deactivate_plugins' ),
			array( 'MainWP_Rest_Sites_Controller', 'deactivate_plugins_of_site', 'dashboard', 'activate_deactivate_plugins' ),
			array( 'MainWP_Rest_Sites_Controller', 'delete_plugins_of_site', 'dashboard', 'delete_plugins' ),
			array( 'MainWP_Rest_Sites_Controller', 'activate_themes_of_site', 'dashboard', 'activate_themes' ),
			array( 'MainWP_Rest_Sites_Controller', 'delete_themes_of_site', 'dashboard', 'delete_themes' ),
			array( 'MainWP_Rest_Updates_Controller', 'update_wp_site', 'dashboard', 'update_wordpress' ),
			array( 'MainWP_Rest_Updates_Controller', 'update_plugins_site', 'dashboard', 'update_plugins' ),
			array( 'MainWP_Rest_Updates_Controller', 'update_themes_site', 'dashboard', 'update_themes' ),
			array( 'MainWP_Rest_Updates_Controller', 'update_translations_site', 'dashboard', 'update_translations' ),
			array( 'MainWP_Rest_Updates_Controller', 'ignore_update_plugins_of_site', 'dashboard', 'ignore_unignore_updates' ),
			array( 'MainWP_Rest_Updates_Controller', 'ignore_update_themes_of_site', 'dashboard', 'ignore_unignore_updates' ),
			array( 'MainWP_Rest_Updates_Controller', 'ignore_update_core_of_site', 'dashboard', 'ignore_unignore_updates' ),
			array( 'MainWP_Rest_Clients_Controller', 'create_item', 'dashboard', 'manage_clients' ),
			array( 'MainWP_Rest_Clients_Controller', 'update_item', 'dashboard', 'manage_clients' ),
			array( 'MainWP_Rest_Clients_Controller', 'delete_item', 'dashboard', 'manage_clients' ),
			array( 'MainWP_Rest_Clients_Controller', 'suspend_client', 'dashboard', 'manage_clients' ),
			array( 'MainWP_Rest_Clients_Controller', 'unsuspend_client', 'dashboard', 'manage_clients' ),
			array( 'MainWP_Rest_Clients_Controller', 'create_client_fields', 'dashboard', 'manage_clients' ),
			array( 'MainWP_Rest_Clients_Controller', 'edit_client_fields', 'dashboard', 'manage_clients' ),
			array( 'MainWP_Rest_Clients_Controller', 'delete_client_fields', 'dashboard', 'manage_clients' ),
			array( 'MainWP_Rest_Tags_Controller', 'create_item', 'dashboard', 'manage_groups' ),
			array( 'MainWP_Rest_Tags_Controller', 'update_item', 'dashboard', 'manage_groups' ),
			array( 'MainWP_Rest_Tags_Controller', 'delete_item', 'dashboard', 'manage_groups' ),
			array( 'MainWP_Rest_Monitors_Controller', 'update_individual_monitor_settings', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Monitors_Controller', 'check_monitor', 'dashboard', 'edit_sites' ),
			array( 'MainWP_Rest_Monitors_Controller', 'update_global_monitoring_settings', 'dashboard', 'manage_dashboard_settings' ),
			array( 'MainWP_Rest_API_Keys_Controller', 'create_new_key', 'rest_api', 'create_rest_api_keys' ),
			array( 'MainWP_Rest_API_Keys_Controller', 'edit_key', 'rest_api', 'edit_rest_api_keys' ),
			array( 'MainWP_Rest_API_Keys_Controller', 'delete_key', 'rest_api', 'delete_rest_api_keys' ),
			array( 'MainWP_Rest_Costs_Controller', 'create_item', 'dashboard', 'add_costs' ),
			array( 'MainWP_Rest_Costs_Controller', 'update_item', 'dashboard', 'edit_costs' ),
			array( 'MainWP_Rest_Costs_Controller', 'delete_item', 'dashboard', 'delete_costs' ),
		);
	}

	/** Every update type must be authorized on both all-types update routes. */
	public function test_bulk_updates_require_each_type_right(): void {
		foreach ( array( 'update_all_items_start', 'update_all_items_inidividual_site_start' ) as $method ) {
			foreach ( array( 'update_wordpress', 'update_plugins', 'update_themes', 'update_translations' ) as $right ) {
				$result = $this->probe(
					array(
						'controller' => 'MainWP_Rest_Updates_Controller',
						'method'     => $method,
						'rights'     => array( 'dashboard' => array( $right => false ) ),
					)
				);
				$this->assertSame( 'mainwp_insufficient_right', $result['error']['code'] );
				$this->assertSame( $right, $result['error']['data']['right'] );
				$this->assertNull( $result['boundary'] );
			}
		}
	}

	/** Every mutating batch action must enforce the operation handler's right. */
	public function test_batches_cannot_bypass_handler_rights(): void {
		$actions = array(
			'sites'   => array(
				'create'     => 'add_sites',
				'update'     => 'edit_sites',
				'delete'     => 'delete_sites',
				'reconnect'  => 'edit_sites',
				'disconnect' => 'edit_sites',
				'suspend'    => 'edit_sites',
				'check'      => 'edit_sites',
				'remove'     => 'delete_sites',
			),
			'clients' => array(
				'create' => 'manage_clients',
				'update' => 'manage_clients',
				'delete' => 'manage_clients',
			),
			'tags'    => array(
				'create' => 'manage_groups',
				'update' => 'manage_groups',
				'delete' => 'manage_groups',
			),
			'costs'   => array(
				'create' => 'add_costs',
				'update' => 'edit_costs',
				'delete' => 'delete_costs',
			),
		);
		foreach ( $actions as $group => $operations ) {
			foreach ( $operations as $action => $right ) {
				foreach ( array( 'batch_items', 'global_batch' ) as $method ) {
					$items    = array( $action => array( in_array( $action, array( 'create', 'update' ), true ) ? array( 'id' => 1 ) : 1 ) );
					$result   = $this->probe(
						array(
							'controller'       => 'Rest_Rights_Batch_' . ucfirst( $group ),
							'method'           => $method,
							'params'           => 'global_batch' === $method ? array( $group => $items ) : $items,
							'rights'           => array( 'dashboard' => array( $right => false ) ),
							'route_check'      => true,
							'batch_group'      => $group,
							'nested_namespace' => 'costs' === $group,
							'route'            => '/mainwp/v2/' . $group . '/batch',
						)
					);
					$response = 'global_batch' === $method ? $result['result'][ $group ] : $result['result'];
					$this->assertSame( 'mainwp_insufficient_right', $response[ $action ][0]['error']['code'], $group . '/' . $action );
					$this->assertSame( 403, $response[ $action ][0]['error']['data']['status'] );
					$this->assertNull( $result['boundary'] );
				}
			}
		}
	}

	/** Authorized requests reach the original operation despite an unrelated denied right. */
	public function test_granted_operations_reach_the_original_boundary(): void {
		$plugin   = array(
			'id_domain' => 1,
			'slug'      => 'example/example.php',
		);
		$theme    = array(
			'id_domain' => 1,
			'slug'      => 'example',
		);
		$user     = array(
			'username' => 'example',
			'email'    => 'example@example.test',
			'password' => 'fixture-only',
			'role'     => 'administrator',
			'websites' => array( 1 ),
		);
		$password = array(
			'password' => 'fixture-only',
			'websites' => array( 1 ),
		);
		$cases    = array(
			array( 'MainWP_Rest_Sites_Controller', 'activate_plugins_of_site', $plugin, 'plugin_action' ),
			array( 'MainWP_Rest_Sites_Controller', 'delete_plugins_of_site', $plugin, 'plugin_action' ),
			array( 'MainWP_Rest_Sites_Controller', 'activate_themes_of_site', $theme, 'theme_action' ),
			array( 'MainWP_Rest_Updates_Controller', 'update_translations_site', $plugin, 'upgradetranslation' ),
			array( 'MainWP_Rest_Tags_Controller', 'create_item', array( 'name' => 'Example tag' ), 'tag write' ),
			array( 'Rest_Rights_Users', 'create_user', $user, 'newuser' ),
			array( 'Rest_Rights_Users', 'update_admin_password', $password, 'newadminpassword' ),
			array( 'MainWP_Rest_Monitors_Controller', 'update_global_monitoring_settings', array( 'active' => false ), 'monitor settings write' ),
			array( 'MainWP_Rest_API_Keys_Controller', 'delete_key', array( 'key_identifier' => 88 ), 'key deletion' ),
		);
		foreach ( $cases as list( $controller, $method, $params, $boundary ) ) {
			$result = $this->probe(
				array(
					'controller'  => $controller,
					'method'      => $method,
					'params'      => $params,
					'rights'      => array( 'dashboard' => array( 'manage_clients' => false ) ),
					'route_check' => true,
				)
			);
			$this->assertNull( $result['error'], $method );
			$this->assertSame( $boundary, $result['boundary'], $method );
		}
	}

	/** API Access permits the list for any one of its four existing management rights. */
	public function test_key_list_preserves_any_management_right_access(): void {
		$rights = array_fill_keys( array( 'manage_rest_api_keys', 'create_rest_api_keys', 'edit_rest_api_keys', 'delete_rest_api_keys' ), false );
		foreach ( array_merge( array( '' ), array_keys( $rights ) ) as $grant ) {
			$grants = $rights;
			if ( '' !== $grant ) {
				$grants[ $grant ] = true;
			}
			$result = $this->probe(
				array(
					'controller' => 'Rest_Rights_Keys',
					'method'     => 'list_keys',
					'rights'     => array( 'rest_api' => $grants ),
				)
			);
			if ( '' === $grant ) {
				$this->assertSame( 'mainwp_insufficient_right', $result['error']['code'] );
				$this->assertNull( $result['boundary'] );
			} else {
				$this->assertNull( $result['error'] );
				$this->assertSame( 'key list', $result['boundary'] );
			}
		}
	}

	/** Key scopes must still deny requests with granted operation rights. */
	public function test_key_scopes_remain_enforced(): void {
		foreach ( array( 'read', 'write', 'read_write', 'delete' ) as $scope ) {
			foreach ( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
				$result  = $this->probe(
					array(
						'controller'      => 'MainWP_Rest_Users_Controller',
						'method'          => 'create_user',
						'scope'           => $scope,
						'http_method'     => $method,
						'route_check'     => true,
						'permission_only' => true,
					)
				);
				$allowed = 'GET' === $method ? in_array( $scope, array( 'read', 'read_write' ), true ) : in_array( $scope, 'DELETE' === $method ? array( 'write', 'read_write', 'delete' ) : array( 'write', 'read_write' ), true );
				$this->assertSame( $allowed, true === $result['result'] );
				$this->assertSame( array(), $result['checks'] );
			}
		}
		$result = $this->probe(
			array(
				'controller'      => 'MainWP_Rest_Users_Controller',
				'method'          => 'create_user',
				'route_check'     => true,
				'unauthenticated' => true,
			)
		);
		$this->assertSame( 'mainwp_rest_authentication_error', $result['error']['code'] );
		$this->assertSame( array(), $result['checks'] );
	}

	/** Per-site and all-site restrictions still apply when operation rights are granted. */
	public function test_site_restrictions_remain_enforced(): void {
		$result = $this->probe(
			array(
				'controller' => 'MainWP_Rest_Sites_Controller',
				'method'     => 'activate_plugins_of_site',
				'params'     => array(
					'id_domain' => 2,
					'slug'      => 'example/example.php',
				),
				'sites'      => array( 1 ),
			)
		);
		$this->assertNotNull( $result['error'] );
		$this->assertNull( $result['boundary'] );
		$result = $this->probe(
			array(
				'controller'   => 'MainWP_Rest_API_Keys_Controller',
				'method'       => 'delete_key',
				'params'       => array( 'key_identifier' => 88 ),
				'sites'        => array( 1 ),
				'route_check'  => true,
				'unrestricted' => true,
			)
		);
		$this->assertSame( 'mainwp_restricted_account', $result['error']['code'] );
		$this->assertNull( $result['boundary'] );
	}

	/** An absent current user cannot gain rights through filters that default to true. */
	public function test_userless_rest_cannot_run_privileged_handlers(): void {
		foreach ( array( array( 'MainWP_Rest_Users_Controller', 'create_user' ), array( 'MainWP_Rest_API_Keys_Controller', 'delete_key' ) ) as list( $controller, $method ) ) {
			$result = $this->probe(
				array(
					'controller' => $controller,
					'method'     => $method,
					'user'       => 0,
				)
			);
			$this->assertSame( 'mainwp_insufficient_right', $result['error']['code'] );
			$this->assertSame( 403, $result['error']['data']['status'] );
			$this->assertNull( $result['boundary'] );
		}
	}
}
