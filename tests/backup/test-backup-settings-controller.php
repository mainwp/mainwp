<?php
/**
 * Backup settings REST tests: the mainwp_enable_native_backups key
 * alias on the general settings routes and the dedicated
 * settings/backups sub-resource mirroring the Backup Settings page.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Updates_Overview;
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
	 * Singleton state must not change authentication in later tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		\MainWP_REST_Authentication::$instance = null;

		$reflection = new \ReflectionClass( \MainWP_Rest_Server::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_primaryBackup' );
		delete_option( 'mainwp_backup_max_concurrent' );
		delete_option( 'mainwp_options_loadFilesBeforeZip' );
		delete_option( 'mainwp_maximumFileDescriptors' );
		delete_option( 'mainwp_on_demand_retention_count' );
		delete_option( 'mainwp_backup_before_upgrade_days' );

		parent::tearDown();
	}

	/**
	 * Controller behavior needs session authority without activating machine authentication.
	 *
	 * @return void
	 */
	protected function authenticate_as_admin(): void {
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
		$auth = $this->getMockBuilder( \MainWP_REST_Authentication::class )->disableOriginalConstructor()->onlyMethods( array( 'is_valid_permissions' ) )->getMock();
		$auth->method( 'is_valid_permissions' )->willReturnCallback(
			static function () {
				return current_user_can( 'manage_options' ) ? true : new \WP_Error( 'mainwp_rest_authentication_error', 'Administrator session required.', array( 'status' => 401 ) );
			}
		);
		\MainWP_REST_Authentication::$instance = $auth;
	}

	/**
	 * Each body source must exercise the controller's real validation and persistence.
	 *
	 * @param string $method HTTP method.
	 * @param string $route REST route.
	 * @param array  $body Body for write requests.
	 * @param string $source Controller body source: json, params, or raw.
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_request( string $method, string $route, array $body = array(), string $source = 'json' ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( ! empty( $body ) ) {
			$request->set_body( (string) wp_json_encode( $body ) );
			if ( 'raw' === $source ) {
				// An already-parsed empty parameter bag forces the controller's raw-body fallback.
				$request->get_json_params();
			} elseif ( 'params' === $source ) {
				$request->set_body_params( $body );
			}
			$request->set_header( 'content-type', 'application/json' );
		}
		return rest_do_request( $request );
	}

	/**
	 * GET must report the window used by the gate, including its fallback.
	 *
	 * @dataProvider general_settings_backup_windows
	 * @param int $stored Stored window.
	 * @param int $expected Effective window.
	 */
	public function test_general_settings_returns_effective_backup_window( int $stored, int $expected ): void {
		update_option( 'mainwp_backup_before_upgrade_days', $stored );
		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/settings/general' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected, $response->get_data()['data']['backup_before_upgrade_days'] );
	}

	/**
	 * Invalid stored values must not escape through the REST representation.
	 *
	 * @return array Stored and effective windows.
	 */
	public static function general_settings_backup_windows(): array {
		return array(
			'zero'  => array( 0, 7 ),
			'valid' => array( 3, 3 ),
		);
	}

	/**
	 * All body sources must store the same window as the Settings form.
	 *
	 * @dataProvider general_edit_backup_windows
	 * @param string $method HTTP method.
	 * @param string $source Controller body source.
	 * @param mixed  $submitted Submitted window.
	 * @param int    $expected Stored window.
	 */
	public function test_general_edit_normalizes_backup_window( string $method, string $source, $submitted, int $expected ): void {
		update_option( 'mainwp_backup_before_upgrade_days', 99 );
		update_option( 'mainwp_enableNativeBackups', 0 );
		update_option( 'mainwp_primaryBackup', '' );
		$response = $this->do_authenticated_request(
			$method,
			'/mainwp/v2/settings/general/edit',
			array(
				'backup_before_upgrade_days'   => $submitted,
				'mainwp_enable_native_backups' => 1,
			),
			$source
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected, (int) get_option( 'mainwp_backup_before_upgrade_days' ) );
		$this->assertSame( $expected, MainWP_Updates_Overview::get_backup_before_upgrade_days() );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ) );
	}

	/**
	 * The raw-body fallback bypasses WordPress argument sanitization.
	 *
	 * @return array Methods, body sources, submitted and stored windows.
	 */
	public static function general_edit_backup_windows(): array {
		$windows = array(
			'valid'           => array( 12, 12 ),
			'zero'            => array( 0, 7 ),
			'negative'        => array( -5, 7 ),
			'negative string' => array( '-5', 7 ),
			'non-numeric'     => array( 'abc', 7 ),
			'leading zero'    => array( '030', 30 ),
			'fraction'        => array( 1.5, 7 ),
			'empty'           => array( '', 7 ),
			'null'            => array( null, 7 ),
			'array'           => array( array( 12 ), 7 ),
		);
		$cases   = array();
		foreach ( array( 'PUT', 'PATCH' ) as $method ) {
			foreach ( array( 'json', 'params', 'raw' ) as $source ) {
				foreach ( $windows as $name => $window ) {
					$cases[ $method . ' ' . $source . ' ' . $name ] = array( $method, $source, $window[0], $window[1] );
				}
			}
		}
		return $cases;
	}

	/**
	 * Form-encoded requests remain outside the route's accepted content type.
	 */
	public function test_general_edit_rejects_form_encoded_backup_window(): void {
		update_option( 'mainwp_backup_before_upgrade_days', 99 );
		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/settings/general/edit' );
		$request->set_header( 'content-type', 'application/x-www-form-urlencoded' );
		$request->set_body( 'backup_before_upgrade_days=0' );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_content_type', $response->get_data()['code'] );
		$this->assertSame( 99, (int) get_option( 'mainwp_backup_before_upgrade_days' ) );
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

	/**
	 * Machine authentication must not bypass a withheld Dashboard settings right.
	 *
	 * @return void
	 */
	public function test_backups_edit_requires_dashboard_settings_right(): void {
		$options = array(
			'mainwp_enableNativeBackups'       => 0,
			'mainwp_enableLegacyBackupFeature' => 0,
			'mainwp_primaryBackup'             => 'old-provider',
			'mainwp_on_demand_retention_count' => 5,
		);
		foreach ( $options as $option => $value ) {
			update_option( $option, $value );
		}
		$deny = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $right ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		$writes = array();
		$track  = static function ( $value, $option ) use ( &$writes ) {
			$writes[] = $option;
			return $value;
		};
		add_filter( 'pre_update_option', $track, 10, 2 );
		try {
			$response = $this->do_authenticated_request(
				'PUT',
				'/mainwp/v2/settings/backups/edit',
				array(
					'mainwp_enable_native_backups'     => 1,
					'mainwp_primary_backup'            => '',
					'mainwp_on_demand_retention_count' => 7,
				)
			);
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny );
			remove_filter( 'pre_update_option', $track );
		}
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mainwp_insufficient_right', $response->get_data()['code'] );
		$this->assertSame( 'Your role does not permit this operation.', $response->get_data()['message'] );
		$this->assertSame(
			array(
				'status' => 403,
				'right'  => 'manage_dashboard_settings',
			),
			$response->get_data()['data']
		);
		$this->assertSame( array(), $writes );
		foreach ( $options as $option => $value ) {
			$this->assertSame( (string) $value, (string) get_option( $option ), $option );
		}
	}

	/**
	 * The effective old flag may still live under its rollback name.
	 *
	 * @return array Flag transition cases.
	 */
	public static function native_transitions(): array {
		return array(
			'enable'              => array( 0, 0, 1, false, '' ),
			'enable with primary' => array( 0, 0, 1, true, '' ),
			'legacy off'          => array( null, 0, 1, true, '' ),
			'already enabled'     => array( 1, 1, 1, true, '' ),
			'legacy on'           => array( null, 1, 1, true, '' ),
			'disable'             => array( 1, 1, 0, false, 'old-provider' ),
		);
	}

	/**
	 * Enabling native backups must override a primary value in the same body.
	 *
	 * @dataProvider native_transitions
	 * @param int|null $old_native Stored native flag, or absent.
	 * @param int      $old_legacy Rollback flag.
	 * @param int      $new_flag Requested native flag.
	 * @param bool     $set_primary Whether the body also sets a primary method.
	 * @param string   $expected_primary Expected resulting primary method.
	 * @return void
	 */
	public function test_backups_edit_native_transition( $old_native, int $old_legacy, int $new_flag, bool $set_primary, string $expected_primary ): void {
		if ( null === $old_native ) {
			delete_option( 'mainwp_enableNativeBackups' );
		} else {
			update_option( 'mainwp_enableNativeBackups', $old_native );
		}
		update_option( 'mainwp_enableLegacyBackupFeature', $old_legacy );
		update_option( 'mainwp_primaryBackup', 'old-provider' );
		$body = array( 'mainwp_enable_native_backups' => $new_flag );
		if ( $set_primary ) {
			$body['mainwp_primary_backup'] = '';
		}
		$primary_updates = array();
		$track           = static function ( $value ) use ( &$primary_updates ) {
			$primary_updates[] = $value;
			return $value;
		};
		add_filter( 'pre_update_option_mainwp_primaryBackup', $track );
		try {
			$response = $this->do_authenticated_request( 'PUT', '/mainwp/v2/settings/backups/edit', $body );
		} finally {
			remove_filter( 'pre_update_option_mainwp_primaryBackup', $track );
		}
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $new_flag, (int) get_option( 'mainwp_enableNativeBackups' ) );
		$this->assertSame( $new_flag, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
		$this->assertSame( $expected_primary, get_option( 'mainwp_primaryBackup' ) );
		$expected_updates = (int) $set_primary + (int) ( $new_flag && ! ( null === $old_native ? $old_legacy : $old_native ) );
		$this->assertSame( array_fill( 0, $expected_updates, '' ), $primary_updates );
		$this->assertSame( 1, $response->get_data()['success'] );
	}
	/**
	 * Turning native backups on clears a stored API-backups primary, as the ability does,
	 * while a body that asks for both stays refused and general/edit keeps its rule.
	 *
	 * @return void
	 */
	public function test_backups_edit_enables_native_over_stored_api_backups_primary(): void {
		update_option( 'mainwp_enableNativeBackups', 0 );
		update_option( 'mainwp_enableLegacyBackupFeature', 0 );
		update_option( 'mainwp_primaryBackup', 'module-api-backups' );

		$both = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/backups/edit',
			array(
				'mainwp_enable_native_backups' => 1,
				'mainwp_primary_backup'        => 'module-api-backups',
			)
		);
		$this->assertSame( 400, $both->get_status() );
		$this->assertSame( 'module-api-backups', get_option( 'mainwp_primaryBackup' ) );

		$general = $this->do_authenticated_request( 'PUT', '/mainwp/v2/settings/general/edit', array( 'mainwp_enable_native_backups' => 1 ) );
		$this->assertSame( 400, $general->get_status() );
		$this->assertSame( 0, (int) get_option( 'mainwp_enableNativeBackups' ) );

		$response = $this->do_authenticated_request( 'PUT', '/mainwp/v2/settings/backups/edit', array( 'mainwp_enable_native_backups' => 1 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
		$this->assertSame( '', get_option( 'mainwp_primaryBackup' ) );
	}

	/**
	 * The backups route judges the native flag and primary method its write stores:
	 * read from the body (not the query string), coerced as the sanitizers coerce
	 * them, null included. Stored states: off_api = native off with an API-backups
	 * primary, on_empty = native on with no primary, on_api = a contradictory stored
	 * pair the route must leave alone unless the request touches it.
	 *
	 * @return array Stored state, body, query, expected status, native flag and primary.
	 */
	public static function native_flag_and_primary_matrix(): array {
		$api = 'module-api-backups';
		return array(
			'string false'                   => array( 'off_api', array( 'mainwp_enable_native_backups' => 'false' ), array(), 200, 0, $api ),
			'legacy key string false'        => array( 'off_api', array( 'mainwp_enable_legacy_backup_feature' => 'false' ), array(), 200, 0, $api ),
			'string zero'                    => array( 'off_api', array( 'mainwp_enable_native_backups' => '0' ), array(), 200, 0, $api ),
			'json false'                     => array( 'off_api', array( 'mainwp_enable_native_backups' => false ), array(), 200, 0, $api ),
			'string one'                     => array( 'off_api', array( 'mainwp_enable_native_backups' => '1' ), array(), 200, 1, '' ),
			'json true'                      => array( 'off_api', array( 'mainwp_enable_native_backups' => true ), array(), 200, 1, '' ),
			'null legacy with alias on'      => array(
				'off_api',
				array(
					'mainwp_enable_legacy_backup_feature' => null,
					'mainwp_enable_native_backups'        => 1,
				),
				array(),
				400,
				0,
				$api,
			),
			'on with api primary'            => array(
				'off_api',
				array(
					'mainwp_enable_native_backups' => 1,
					'mainwp_primary_backup'        => $api,
				),
				array(),
				400,
				0,
				$api,
			),
			'on with empty primary'          => array(
				'off_api',
				array(
					'mainwp_enable_native_backups' => 1,
					'mainwp_primary_backup'        => '',
				),
				array(),
				200,
				1,
				'',
			),
			'on with null primary'           => array(
				'off_api',
				array(
					'mainwp_enable_native_backups' => 1,
					'mainwp_primary_backup'        => null,
				),
				array(),
				200,
				1,
				'',
			),
			'null primary alone'             => array( 'off_api', array( 'mainwp_primary_backup' => null ), array(), 400, 0, $api ),
			'flag only in query'             => array( 'off_api', array( 'mainwp_on_demand_retention_count' => 5 ), array( 'mainwp_enable_native_backups' => 1 ), 200, 0, $api ),
			'null legacy with alias on, on'  => array(
				'on_empty',
				array(
					'mainwp_enable_legacy_backup_feature' => null,
					'mainwp_enable_native_backups'        => 1,
				),
				array(),
				400,
				1,
				'',
			),
			'null flag while on'             => array( 'on_empty', array( 'mainwp_enable_native_backups' => null ), array(), 400, 1, '' ),
			'api primary, off only in query' => array( 'on_empty', array( 'mainwp_primary_backup' => $api ), array( 'mainwp_enable_native_backups' => 0 ), 400, 1, '' ),
			'off with api primary'           => array(
				'on_empty',
				array(
					'mainwp_enable_native_backups' => 0,
					'mainwp_primary_backup'        => $api,
				),
				array(),
				200,
				0,
				$api,
			),
			'already on'                     => array( 'on_empty', array( 'mainwp_enable_native_backups' => 1 ), array(), 200, 1, '' ),
			'untouched contradictory state'  => array( 'on_api', array( 'mainwp_on_demand_retention_count' => 5 ), array(), 200, 1, $api ),
			'on over contradictory state'    => array( 'on_api', array( 'mainwp_enable_native_backups' => 1 ), array(), 400, 1, $api ),
		);
	}

	/**
	 * Each row ends in the state its sanitized body names, or is refused with nothing written.
	 *
	 * @dataProvider native_flag_and_primary_matrix
	 * @param string $state Stored state key.
	 * @param array  $body Request body.
	 * @param array  $query Query string parameters.
	 * @param int    $status Expected HTTP status.
	 * @param int    $native Expected native flag.
	 * @param string $primary Expected primary method.
	 * @return void
	 */
	public function test_backups_edit_native_flag_and_primary_matrix( string $state, array $body, array $query, int $status, int $native, string $primary ): void {
		$on = 'off_api' === $state ? 0 : 1;
		update_option( 'mainwp_enableNativeBackups', $on );
		update_option( 'mainwp_enableLegacyBackupFeature', $on );
		update_option( 'mainwp_primaryBackup', 'on_empty' === $state ? '' : 'module-api-backups' );

		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/settings/backups/edit' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$request->set_query_params( $query );
		$response = rest_do_request( $request );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $native, (int) get_option( 'mainwp_enableNativeBackups' ) );
		$this->assertSame( $native, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
		$this->assertSame( $primary, get_option( 'mainwp_primaryBackup' ) );
	}
}
