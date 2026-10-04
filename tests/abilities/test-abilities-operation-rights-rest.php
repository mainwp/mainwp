<?php
/**
 * A restricted administrator must not mutate sites through the native abilities
 * REST route when Team Control withholds the required right.
 *
 * These tests leave MAINWP_REST_API_DOING undefined because constants cannot be
 * reset between tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Abilities_Operation_Rights_Rest extends MainWP_Abilities_Test_Case {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Whether abilities have been initialized for this test class.
	 *
	 * @var bool
	 */
	private static $abilities_initialized = false;

	/**
	 * The exact right filter callback added by this test, for targeted removal.
	 *
	 * @var callable|null
	 */
	private $right_filter = null;

	/**
	 * The remote-call mock filter, for targeted removal.
	 *
	 * @var callable|null
	 */
	private $remote_mock = null;

	public function setUp(): void {
		parent::setUp();

		// Ensure abilities are registered BEFORE creating the REST server:
		// the Abilities API controller needs them when routes are built.
		if ( ! self::$abilities_initialized && function_exists( 'wp_get_ability' ) ) {
			if ( ! wp_get_ability( 'mainwp/delete-site-v1' ) ) {
				\MainWP\Dashboard\MainWP_Abilities::init();
				do_action( 'wp_abilities_api_categories_init' );
				do_action( 'wp_abilities_api_init' );
			}
			self::$abilities_initialized = true;
		}

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		if ( null !== $this->right_filter ) {
			remove_filter( 'mainwp_currentusercan', $this->right_filter, 10 );
			$this->right_filter = null;
		}
		if ( null !== $this->remote_mock ) {
			remove_filter( 'mainwp_fetch_url_authed_pre', $this->remote_mock, 10 );
			$this->remote_mock = null;
		}
		parent::tearDown();
	}

	/**
	 * Withhold a specific dashboard right from the current user.
	 *
	 * @param string $withheld_right The right slug to deny.
	 */
	private function withhold_right( $withheld_right ) {
		$this->right_filter = static function ( $allowed, $cap_type, $cap ) use ( $withheld_right ) {
			if ( 'dashboard' === $cap_type && $cap === $withheld_right ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $this->right_filter, 10, 3 );
	}

	/**
	 * Answer every child-site request successfully so allowed-path abilities
	 * can complete without a real child.
	 */
	private function mock_remote_success() {
		$this->remote_mock = static function ( $result, $website, $what, $params ) {
			return array( 'sync' => array() );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $this->remote_mock, 10, 4 );
	}

	/**
	 * Dispatch a POST to an ability's run route with a JSON input body.
	 *
	 * @param string $ability_name Ability id.
	 * @param array  $input        Ability input.
	 * @return \WP_REST_Response
	 */
	private function run_ability( string $ability_name, array $input ) {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $ability_name . '/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Whether a site row still exists.
	 *
	 * @param int $site_id Site ID.
	 * @return bool
	 */
	private function site_row_exists( int $site_id ): bool {
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		return ! empty( $website );
	}

	/**
	 * A manage_options user whose Team Control role withholds delete_sites is
	 * denied at the REST boundary and the site row is untouched.
	 */
	public function test_withheld_right_denies_native_run_route() {
		$this->set_current_user_as_admin();
		$this->withhold_right( 'delete_sites' );

		$site_id = $this->create_test_site( array( 'name' => 'Rights REST Denied Site' ) );

		$response = $this->run_ability(
			'mainwp/delete-site-v1',
			array(
				'site_id_or_domain' => (string) $site_id,
				'confirm'           => true,
			)
		);

		$this->assertSame( 403, $response->get_status(), 'The run route must deny the withheld right with 403.' );
		$data = $response->get_data();
		$this->assertSame( 'mainwp_insufficient_right', $data['code'] ?? '', 'The denial must carry the rights error code.' );
		$this->assertTrue( $this->site_row_exists( $site_id ), 'The denied call must leave the site row untouched.' );
	}

	/**
	 * The same user holding the right runs the ability successfully.
	 */
	public function test_held_right_allows_native_run_route() {
		$this->set_current_user_as_admin();
		$this->withhold_right( 'manage_clients' ); // An unrelated right.
		$this->mock_remote_success();

		$site_id = $this->create_test_site( array( 'name' => 'Rights REST Allowed Site' ) );

		$response = $this->run_ability(
			'mainwp/delete-site-v1',
			array(
				'site_id_or_domain' => (string) $site_id,
				'confirm'           => true,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'A held right must not block the run route.' );
		$this->assertFalse( $this->site_row_exists( $site_id ), 'The allowed call must delete the site.' );
	}

	/**
	 * The update family derives per-type rights at the REST boundary: a
	 * themes-only request survives a withheld update_plugins, a plugins
	 * request does not.
	 */
	public function test_update_family_per_type_rights_at_rest_boundary() {
		$this->set_current_user_as_admin();
		$this->withhold_right( 'update_plugins' );
		$this->mock_remote_success();

		$site_id = $this->create_test_site( array( 'name' => 'Rights REST Update Site' ) );
		$this->create_test_site_sync( $site_id );

		$denied = $this->run_ability(
			'mainwp/run-updates-v1',
			array(
				'site_ids_or_domains' => array( (string) $site_id ),
				'types'               => array( 'plugins' ),
			)
		);
		$this->assertSame( 403, $denied->get_status(), 'A plugins update must require update_plugins.' );
		$data = $denied->get_data();
		$this->assertSame( 'mainwp_insufficient_right', $data['code'] ?? '' );

		$allowed = $this->run_ability(
			'mainwp/run-updates-v1',
			array(
				'confirm'             => true,
				'site_ids_or_domains' => array( (string) $site_id ),
				'types'               => array( 'themes' ),
			)
		);
		$this->assertSame( 200, $allowed->get_status(), 'A themes-only update must not require update_plugins.' );
	}

	/**
	 * Every ability in the rights map has its registered permission callback
	 * wired through the operation-right check: a mis-wired or forgotten
	 * registration surfaces here rather than shipping an unenforced mutation.
	 *
	 * Uses check_permissions() directly (the same check execute() and the REST
	 * layer run) so no per-ability input fixtures are needed beyond a resolvable
	 * site for the site-scoped callbacks.
	 */
	public function test_every_mapped_ability_permission_callback_enforces_its_right() {
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( array( 'name' => 'Rights Map Wiring Site' ) );

		foreach ( \MainWP\Dashboard\MainWP_Abilities_Util::ABILITY_OPERATION_RIGHTS as $ability_name => $right ) {
			$ability = wp_get_ability( $ability_name );
			$this->assertNotNull( $ability, $ability_name . ' must be registered.' );

			$this->withhold_right( $right );
			$input  = array( 'site_id_or_domain' => (string) $site_id );
			$result = $ability->check_permissions( $input );

			$this->assertWPError( $result, $ability_name . ' must deny when ' . $right . ' is withheld.' );
			$this->assertSame( 'mainwp_insufficient_right', $result->get_error_code(), $ability_name . ' must deny via the rights check.' );

			remove_filter( 'mainwp_currentusercan', $this->right_filter, 10 );
			$this->right_filter = null;
		}

		// The update family enforces per-type derived rights the same way.
		foreach ( array( 'mainwp/run-updates-v1', 'mainwp/update-all-v1' ) as $ability_name ) {
			$ability = wp_get_ability( $ability_name );
			$this->assertNotNull( $ability, $ability_name . ' must be registered.' );

			$this->withhold_right( 'update_translations' );
			$result = $ability->check_permissions( array( 'types' => array( 'translations' ) ) );

			$this->assertWPError( $result, $ability_name . ' must derive per-type rights.' );
			$this->assertSame( 'mainwp_insufficient_right', $result->get_error_code() );

			remove_filter( 'mainwp_currentusercan', $this->right_filter, 10 );
			$this->right_filter = null;
		}
	}
}
