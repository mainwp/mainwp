<?php
/**
 * Backup settings REST tests: the mainwp_enable_native_backups key
 * alias on the general settings routes and the dedicated
 * settings/backups sub-resource mirroring the Backup Settings page.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

/**
 * Class MainWP_Backup_Settings_Controller_Test
 */
class MainWP_Backup_Settings_Controller_Test extends \WP_Test_REST_TestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Consumer key for REST API authentication.
	 *
	 * @var string
	 */
	protected $consumer_key;

	/**
	 * Consumer secret for REST API authentication.
	 *
	 * @var string
	 */
	protected $consumer_secret;

	/**
	 * Reset the REST server singleton and boot a fresh server, the same
	 * way the REST integration tests do.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$reflection           = new \ReflectionClass( \MainWP_Rest_Server::class );
		$controllers_property = $reflection->getProperty( 'controllers' );
		$controllers_property->setAccessible( true );
		$controllers_property->setValue( \MainWP_Rest_Server::instance(), array() );

		\MainWP_REST_Authentication::$instance = null;

		if ( ! has_action( 'rest_api_init', array( \MainWP_Rest_Server::instance(), 'register_rest_routes' ) ) ) {
			add_action( 'rest_api_init', array( \MainWP_Rest_Server::instance(), 'register_rest_routes' ), 10 );
		}

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments -- test bootstrap.
		do_action( 'rest_api_init' );

		$this->authenticate_as_admin();
	}

	/**
	 * Tear down: drop the test API key and reset singletons.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;

		\MainWP_REST_Authentication::$instance = null;

		$reflection = new \ReflectionClass( \MainWP_Rest_Server::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_api_keys WHERE description = 'Test API Key'" ); // phpcs:ignore WordPress.DB -- test cleanup.

		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_primaryBackup' );
		delete_option( 'mainwp_backup_max_concurrent' );
		delete_option( 'mainwp_options_loadFilesBeforeZip' );
		delete_option( 'mainwp_maximumFileDescriptors' );
		delete_option( 'mainwp_on_demand_retention_count' );

		parent::tearDown();
	}

	/**
	 * Create an admin and a read_write API key.
	 *
	 * @return void
	 */
	protected function authenticate_as_admin(): void {
		global $wpdb;

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->consumer_key    = 'ck_' . bin2hex( random_bytes( 16 ) );
		$this->consumer_secret = 'cs_' . bin2hex( random_bytes( 16 ) );

		$wpdb->insert( // phpcs:ignore WordPress.DB -- test fixture.
			$wpdb->prefix . 'mainwp_api_keys',
			array(
				'user_id'         => $this->admin_user_id,
				'description'     => 'Test API Key',
				'permissions'     => 'read_write',
				'consumer_key'    => mainwp_api_hash( $this->consumer_key ),
				'consumer_secret' => $this->consumer_secret,
				'truncated_key'   => substr( $this->consumer_key, -7 ),
				'enabled'         => 1,
				'last_access'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Make an authenticated REST request; PUT/PATCH bodies go as JSON.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param array  $body   JSON body for write requests.
	 *
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_request( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$original_get    = $_GET;
		$original_server = $_SERVER;

		$_GET['consumer_key']    = $this->consumer_key;
		$_GET['consumer_secret'] = $this->consumer_secret;
		$_SERVER['HTTPS']        = 'on';
		$_SERVER['REQUEST_URI']  = '/wp-json' . $route;

		\MainWP_REST_Authentication::$instance = null;
		$auth                                  = \MainWP_REST_Authentication::get_instance();
		$auth->authenticate( 0 );

		$request = new WP_REST_Request( $method, $route );
		if ( ! empty( $body ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		$response = rest_do_request( $request );

		$_GET    = $original_get;
		$_SERVER = $original_server;

		return $response;
	}

	/**
	 * GET settings/general emits the native-backups key under both the
	 * canonical name and the legacy alias, with the same value.
	 *
	 * @return void
	 */
	public function test_general_settings_emit_native_backups_alias() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/settings/general' );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data()['data'];
		$this->assertArrayHasKey( 'mainwp_enable_native_backups', $data );
		$this->assertArrayHasKey( 'mainwp_enable_legacy_backup_feature', $data );
		$this->assertSame( $data['mainwp_enable_legacy_backup_feature'], $data['mainwp_enable_native_backups'] );
		$this->assertSame( 1, (int) $data['mainwp_enable_native_backups'] );
	}

	/**
	 * PUT general/edit with only the canonical key writes the option.
	 *
	 * @return void
	 */
	public function test_general_edit_accepts_native_backups_key() {
		update_option( 'mainwp_enableNativeBackups', 0 );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/general/edit',
			array( 'mainwp_enable_native_backups' => 1 )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ) );
	}

	/**
	 * Both keys present and agreeing succeed.
	 *
	 * @return void
	 */
	public function test_general_edit_agreeing_keys_accepted() {
		update_option( 'mainwp_enableNativeBackups', 0 );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/general/edit',
			array(
				'mainwp_enable_native_backups'        => 1,
				'mainwp_enable_legacy_backup_feature' => 1,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ) );
	}

	/**
	 * Both keys present and disagreeing are refused before anything is
	 * written.
	 *
	 * @return void
	 */
	public function test_general_edit_conflicting_keys_rejected() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/general/edit',
			array(
				'mainwp_enable_native_backups'        => 1,
				'mainwp_enable_legacy_backup_feature' => 0,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ), 'A conflicting request must not change the option.' );
	}

	/**
	 * GET settings/backups returns every Backup Settings page key.
	 *
	 * @return void
	 */
	public function test_backups_settings_route_returns_full_key_set() {
		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/settings/backups' );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data()['data'];
		$keys = array(
			'mainwp_enable_native_backups',
			'mainwp_enable_legacy_backup_feature',
			'mainwp_primary_backup',
			'mainwp_backups_on_server',
			'mainwp_backup_on_external_sources',
			'mainwp_archive_format',
			'mainwp_notification_on_backup_fail',
			'mainwp_notification_on_backup_start',
			'mainwp_chunked_backup_tasks',
			'mainwp_load_files_before_zip',
			'mainwp_maximum_file_descriptors_auto',
			'mainwp_maximum_file_descriptors',
			'mainwp_backup_max_concurrent',
			'mainwp_on_demand_retention_count',
		);
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $data, "settings/backups must expose $key" );
		}
	}

	/**
	 * GET settings/backups exposes the on-demand retention count, defaulting
	 * to 5 when the option has never been saved.
	 *
	 * @return void
	 */
	public function test_backups_settings_route_exposes_on_demand_retention_default() {
		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/settings/backups' );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data()['data'];
		$this->assertArrayHasKey( 'mainwp_on_demand_retention_count', $data );
		$this->assertSame( 5, (int) $data['mainwp_on_demand_retention_count'] );
	}

	/**
	 * PUT settings/backups/edit persists the on-demand retention count.
	 *
	 * @return void
	 */
	public function test_backups_edit_persists_on_demand_retention_count() {
		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/backups/edit',
			array( 'mainwp_on_demand_retention_count' => 7 )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7, (int) get_option( 'mainwp_on_demand_retention_count' ) );
	}

	/**
	 * The on-demand retention count is bound to 1-100; out-of-range values
	 * are refused before anything is written.
	 *
	 * @return void
	 */
	public function test_backups_edit_rejects_out_of_range_on_demand_retention() {
		update_option( 'mainwp_on_demand_retention_count', 5 );

		foreach ( array( 0, 101 ) as $bad ) {
			$response = $this->do_authenticated_request(
				'PUT',
				'/mainwp/v2/settings/backups/edit',
				array( 'mainwp_on_demand_retention_count' => $bad )
			);

			$this->assertSame( 400, $response->get_status(), "Value {$bad} must be rejected." );
			$this->assertSame( 5, (int) get_option( 'mainwp_on_demand_retention_count' ), 'A rejected request must not change the option.' );
		}
	}

	/**
	 * PUT settings/backups/edit persists the underlying options.
	 *
	 * @return void
	 */
	public function test_backups_edit_persists_options() {
		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/backups/edit',
			array(
				'mainwp_backup_max_concurrent'         => 3,
				'mainwp_load_files_before_zip'         => 0,
				'mainwp_maximum_file_descriptors'      => 200,
				'mainwp_maximum_file_descriptors_auto' => 1,
				'mainwp_archive_format'                => 'zip',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, (int) get_option( 'mainwp_backup_max_concurrent' ) );
		$this->assertSame( 0, (int) get_option( 'mainwp_options_loadFilesBeforeZip' ) );
		$this->assertSame( 200, (int) get_option( 'mainwp_maximumFileDescriptors' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_maximumFileDescriptorsAuto' ) );
		$this->assertSame( 'zip', get_option( 'mainwp_archiveFormat' ) );
	}

	/**
	 * The native-backups alias works on the backups sub-resource too.
	 *
	 * @return void
	 */
	public function test_backups_edit_accepts_native_backups_key() {
		update_option( 'mainwp_enableNativeBackups', 0 );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/backups/edit',
			array( 'mainwp_enable_native_backups' => 1 )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ) );
	}

	/**
	 * The primary-backup mutual exclusion guards the backups
	 * sub-resource the same way it guards general/edit.
	 *
	 * @return void
	 */
	public function test_backups_edit_mutual_exclusion_rejected() {
		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/backups/edit',
			array(
				'mainwp_primary_backup'        => 'module-api-backups',
				'mainwp_enable_native_backups' => 1,
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}
}
