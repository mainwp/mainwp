<?php
/**
 * Client status changes propagate to the saved site set.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Clients;
use MainWP\Dashboard\MainWP_Client;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Post_Handler;

/**
 * Class Test_MWP_2109_Client_Suspend_Sites
 */
class Test_MWP_2109_Client_Suspend_Sites extends \WP_UnitTestCase {

	use Site_Fixture;

	/** Sign in as a Dashboard administrator. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST    = array();
		$_REQUEST = array();
	}

	/** Clear request state before the next test. */
	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Both status transitions must propagate independently.
	 *
	 * @return array
	 */
	public static function status_changes(): array {
		return array(
			'suspend'   => array( 0, 1 ),
			'unsuspend' => array( 1, 0 ),
		);
	}

	/**
	 * Edit Client updates every saved site, including newly selected sites.
	 *
	 * @dataProvider status_changes
	 * @param int $old_status Stored status.
	 * @param int $new_status Posted status.
	 */
	public function test_edit_client_status_change_updates_sites( $old_status, $new_status ): void {
		$client   = $this->create_client( $old_status );
		$site_ids = $this->create_client_sites( $client, $old_status );
		$site_ids[] = $this->create_test_site( array( 'suspended' => $old_status ) );
		$unrelated = $this->create_test_site( array( 'suspended' => $old_status ) );

		$this->save_client( $client->client_id, $site_ids, array( 'client.suspended' => $new_status ) );

		$this->assert_client_status( $client->client_id, $new_status );
		$this->assert_site_statuses( $site_ids, array( $new_status, $new_status, $new_status ) );
		$this->assert_site_statuses( array( $unrelated ), array( $old_status ) );
	}

	/** An unchanged client status preserves individual site suspensions. */
	public function test_edit_client_unchanged_status_leaves_sites_untouched(): void {
		$client   = $this->create_client( 0 );
		$site_ids = $this->create_client_sites( $client, 0 );
		MainWP_DB::instance()->update_website_values( $site_ids[0], array( 'suspended' => 1 ) );

		$this->save_client( $client->client_id, $site_ids, array( 'client.suspended' => 0 ) );

		$this->assert_client_status( $client->client_id, 0 );
		$this->assert_site_statuses( $site_ids, array( 1, 0 ) );
	}

	/** A save without the status field cannot change site suspensions. */
	public function test_edit_client_without_status_leaves_sites_untouched(): void {
		$client   = $this->create_client( 1 );
		$site_ids = $this->create_client_sites( $client, 0 );
		MainWP_DB::instance()->update_website_values( $site_ids[0], array( 'suspended' => 1 ) );

		$this->save_client( $client->client_id, $site_ids );

		$this->assert_client_status( $client->client_id, 1 );
		$this->assert_site_statuses( $site_ids, array( 1, 0 ) );
	}

	/** A new suspended client propagates its status after sites are assigned. */
	public function test_add_suspended_client_updates_selected_sites(): void {
		$site_ids = array( $this->create_test_site(), $this->create_test_site() );

		$client_id = $this->save_client( 0, $site_ids, array( 'client.suspended' => 1 ) );

		$this->assert_client_status( $client_id, 1 );
		$this->assert_site_statuses( $site_ids, array( 1, 1 ) );
	}

	/** Leaving Suspended for Lead or Lost reactivates the sites. */
	public function test_edit_client_suspended_to_lead_unsuspends_sites(): void {
		$client   = $this->create_client( 1 );
		$site_ids = $this->create_client_sites( $client, 1 );

		$this->save_client( $client->client_id, $site_ids, array( 'client.suspended' => 2 ) );

		$this->assert_client_status( $client->client_id, 2 );
		$this->assert_site_statuses( $site_ids, array( 0, 0 ) );
	}

	/** Moving between non-suspended statuses never writes Lead/Lost into the sites. */
	public function test_edit_client_active_to_lost_leaves_sites_untouched(): void {
		$client   = $this->create_client( 0 );
		$site_ids = $this->create_client_sites( $client, 0 );
		MainWP_DB::instance()->update_website_values( $site_ids[0], array( 'suspended' => 1 ) );

		$this->save_client( $client->client_id, $site_ids, array( 'client.suspended' => 3 ) );

		$this->assert_client_status( $client->client_id, 3 );
		$this->assert_site_statuses( $site_ids, array( 1, 0 ) );
	}

	/** REST unsuspend of a Lead client is not a change out of Suspended. */
	public function test_rest_unsuspend_lead_client_leaves_sites_untouched(): void {
		$client   = $this->create_client( 2 );
		$site_ids = $this->create_client_sites( $client, 0 );
		MainWP_DB::instance()->update_website_values( $site_ids[0], array( 'suspended' => 1 ) );

		$response = $this->rest_status_request( $client->client_id, 0 );

		$this->assertSame( 200, $response->get_status() );
		$this->assert_client_status( $client->client_id, 0 );
		$this->assert_site_statuses( $site_ids, array( 1, 0 ) );
	}

	/**
	 * REST POSTs propagate suspension and activation through the registered routes.
	 *
	 * @dataProvider status_changes
	 * @param int $old_status Stored status.
	 * @param int $new_status Requested status.
	 */
	public function test_rest_status_change_updates_sites( $old_status, $new_status ): void {
		$client   = $this->create_client( $old_status );
		$site_ids = $this->create_client_sites( $client, $old_status );

		$response = $this->rest_status_request( $client->client_id, $new_status );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['success'] );
		$this->assert_client_status( $client->client_id, $new_status );
		$this->assert_site_statuses( $site_ids, array( $new_status, $new_status ) );
	}

	/**
	 * Repeated REST requests preserve individual site statuses.
	 *
	 * @dataProvider status_changes
	 * @param int $unused Opposite status, unused.
	 * @param int $status Stored and requested status.
	 */
	public function test_rest_unchanged_status_leaves_sites_untouched( $unused, $status ): void {
		$client   = $this->create_client( $status );
		$site_ids = $this->create_client_sites( $client, 0 );
		MainWP_DB::instance()->update_website_values( $site_ids[0], array( 'suspended' => 1 ) );

		$response = $this->rest_status_request( $client->client_id, $status );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['success'] );
		$this->assert_site_statuses( $site_ids, array( 1, 0 ) );
	}

	/** The suspend ability updates the client's sites after a successful save. */
	public function test_suspend_ability_updates_sites(): void {
		$client   = $this->create_client( 0 );
		$site_ids = $this->create_client_sites( $client, 0 );

		$result = MainWP_Abilities_Clients::execute_suspend_client( array( 'client_id_or_email' => $client->client_id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['suspended'] );
		$this->assert_client_status( $client->client_id, 1 );
		$this->assert_site_statuses( $site_ids, array( 1, 1 ) );
	}

	/** Repeating the ability leaves a manually unsuspended site alone. */
	public function test_suspend_ability_unchanged_status_leaves_sites_untouched(): void {
		$client   = $this->create_client( 1 );
		$site_ids = $this->create_client_sites( $client, 1 );
		MainWP_DB::instance()->update_website_values( $site_ids[0], array( 'suspended' => 0 ) );

		$result = MainWP_Abilities_Clients::execute_suspend_client( array( 'client_id_or_email' => $client->client_id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['suspended'] );
		$this->assert_site_statuses( $site_ids, array( 0, 1 ) );
	}

	/** CSV site URLs are assigned before a new client's suspension propagates. */
	public function test_csv_import_new_suspended_client_updates_sites(): void {
		$site_ids = array( $this->create_test_site(), $this->create_test_site() );
		$email    = 'import-' . wp_generate_uuid4() . '@example.com';

		$response = $this->import_client( $email, $site_ids );

		$this->assertTrue( $response['success'] );
		$this->assert_client_status( $response['data']['client']['client_id'], 1 );
		$this->assert_site_statuses( $site_ids, array( 1, 1 ) );
	}

	/** An import that updates an existing active client propagates suspension. */
	public function test_csv_import_existing_client_status_change_updates_sites(): void {
		$client   = $this->create_client( 0 );
		$site_ids = $this->create_client_sites( $client, 0 );
		$scope    = static function () use ( $site_ids ) {
			return $site_ids;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		try {
			$response = $this->import_client( $client->client_email, $site_ids );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}

		$this->assertTrue( $response['success'] );
		$this->assertSame( (int) $client->client_id, (int) $response['data']['client']['client_id'] );
		$this->assert_client_status( $client->client_id, 1 );
		$this->assert_site_statuses( $site_ids, array( 1, 1 ) );
	}

	/** Reimporting an already suspended client preserves individual site statuses. */
	public function test_csv_import_existing_unchanged_status_leaves_sites_untouched(): void {
		$client   = $this->create_client( 1 );
		$site_ids = $this->create_client_sites( $client, 0 );
		$scope    = static function () use ( $site_ids ) {
			return $site_ids;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		try {
			$response = $this->import_client( $client->client_email, $site_ids );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}

		$this->assertTrue( $response['success'] );
		$this->assertSame( (int) $client->client_id, (int) $response['data']['client']['client_id'] );
		$this->assert_site_statuses( $site_ids, array( 0, 0 ) );
	}

	/**
	 * Create a client with an explicit status.
	 *
	 * @param int $status Suspended flag.
	 * @return object
	 */
	private function create_client( $status ) {
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => 'Client ' . wp_generate_uuid4(),
				'client_email' => wp_generate_uuid4() . '@example.com',
				'created'      => time(),
				'suspended'    => $status,
			),
			true
		);
		$this->assertIsObject( $client );
		return $client;
	}

	/**
	 * Create two sites owned by a client.
	 *
	 * @param object $client Client fixture.
	 * @param int    $status Initial site status.
	 * @return int[]
	 */
	private function create_client_sites( $client, $status ): array {
		$args = array( 'client_id' => $client->client_id, 'suspended' => $status );
		return array( $this->create_test_site( $args ), $this->create_test_site( $args ) );
	}

	/**
	 * Submit the Edit/Add Client form handler.
	 *
	 * @param int   $client_id Client ID, or zero for a new client.
	 * @param int[] $site_ids Selected sites.
	 * @param array $fields Default client fields.
	 * @return int Saved client ID.
	 */
	private function save_client( $client_id, array $site_ids, array $fields = array() ): int {
		$_POST = array(
			'nonce_client_id' => wp_create_nonce( 'editing-client-' . $client_id ),
			'selected_sites'  => $site_ids,
			'client_fields'   => array(
				'client_id'     => $client_id,
				'default_field' => array_merge( array( 'client.name' => 'Saved client' ), $fields ),
			),
		);
		ob_start();
		try {
			MainWP_Client::add_client();
		} finally {
			$body = ob_get_clean();
		}
		$response = json_decode( $body, true );
		$this->assertIsArray( $response, $body );
		$this->assertSame( 'yes', $response['success'] );
		return (int) $response['client_id'];
	}

	/**
	 * Dispatch the real REST route using the existing authenticated-user fixture.
	 *
	 * @param int $client_id Client ID.
	 * @param int $status Requested status.
	 * @return \WP_REST_Response
	 */
	private function rest_status_request( $client_id, $status ) {
		$server = $GLOBALS['wp_rest_server'] ?? null;
		$auth   = \MainWP_REST_Authentication::get_instance();
		$user   = new \ReflectionProperty( $auth, 'user' );
		$user->setAccessible( true );
		$original_user = $user->getValue( $auth );
		$priority      = has_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ) );
		// Match Test_REST_Settings_Tool_Timestamps: avoid the permanent REST bypass constant.
		if ( false !== $priority ) {
			remove_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ), $priority );
		}
		$user->setValue( $auth, (object) array( 'permissions' => 'read_write', 'user_id' => get_current_user_id(), 'key_id' => 0 ) );
		try {
			$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
			do_action( 'rest_api_init' );
			( new \MainWP_Rest_Clients_Controller() )->register_routes();
			$route = '/mainwp/v2/clients/' . $client_id . ( $status ? '/suspend' : '/unsuspend' );
			return rest_do_request( new \WP_REST_Request( 'POST', $route ) );
		} finally {
			$user->setValue( $auth, $original_user );
			if ( false !== $priority ) {
				add_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ), $priority, 3 );
			}
			$GLOBALS['wp_rest_server'] = $server;
		}
	}

	/**
	 * Submit one parsed CSV row to the import handler.
	 *
	 * @param string $email Client email.
	 * @param int[]  $site_ids Site URLs to include.
	 * @return array JSON response.
	 */
	private function import_client( $email, array $site_ids ): array {
		$_POST = array(
			'security'  => wp_create_nonce( 'mainwp_clients_import_client' ),
			'name'      => 'Imported client',
			'email'     => $email,
			'suspended' => 1,
			'urls'      => array_map(
				static function ( $site_id ) {
					return MainWP_DB::instance()->get_website_by_id( $site_id )->url;
				},
				$site_ids
			),
		);
		$_REQUEST = $_POST;
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
		ob_start();
		try {
			MainWP_Post_Handler::instance()->mainwp_clients_import_client();
		} catch ( \WPDieException $exception ) {
			$this->assertSame( '', $exception->getMessage() );
		} finally {
			$body = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		$response = json_decode( $body, true );
		$this->assertIsArray( $response, $body );
		return $response;
	}

	/**
	 * Capture wp_send_json() without ending PHPUnit.
	 *
	 * @return callable
	 */
	public function ajax_die_handler(): callable {
		return static function ( $message ) {
			throw new \WPDieException( (string) $message );
		};
	}

	/**
	 * Assert the stored client flag.
	 *
	 * @param int $client_id Client ID.
	 * @param int $status Expected flag.
	 */
	private function assert_client_status( $client_id, $status ): void {
		$client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
		$this->assertIsObject( $client );
		$this->assertSame( $status, (int) $client->suspended );
	}

	/**
	 * Read site flags from storage after the operation.
	 *
	 * @param int[] $site_ids Site IDs.
	 * @param int[] $expected Expected flags in site order.
	 */
	private function assert_site_statuses( array $site_ids, array $expected ): void {
		$actual = array();
		foreach ( $site_ids as $site_id ) {
			$site = MainWP_DB::instance()->get_website_by_id( $site_id );
			$this->assertIsObject( $site );
			$actual[] = (int) $site->suspended;
		}
		$this->assertSame( $expected, $actual, 'Site suspension flags must match in saved site order.' );
	}
}
