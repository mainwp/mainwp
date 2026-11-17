<?php
/**
 * REST user protection follows the authenticated Child login.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/** Exercises the real controller with offline Child responses. */
class Test_MWP_2283_REST_Users_Connected_Admin extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Fixture site ID.
	 *
	 * @var int
	 */
	private $site;
	/**
	 * Dashboard owner ID.
	 *
	 * @var int
	 */
	private $owner;
	/**
	 * Child login returned by lookup.
	 *
	 * @var string|null
	 */
	private $login = 'editor2';
	/**
	 * Lookup response mode.
	 *
	 * @var string
	 */
	private $lookup = 'SUCCESS';
	/**
	 * Captured Child mutations.
	 *
	 * @var array
	 */
	private $mutations = array();

	/** Fallback users-list response.
	 *
	 * @var mixed
	 */
	private $fallback = array();

	/** Number of fallback requests.
	 *
	 * @var int
	 */
	private $fallback_calls = 0;

	/** Seeds an owned site and installs transport stubs. */
	public function setUp(): void {
		parent::setUp();
		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
		$this->site = $this->create_test_site( array( 'adminname' => 'connadmin' ) );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), PHP_INT_MAX, 4 );
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
	}

	/** Removes transport stubs. */
	public function tearDown(): void {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), PHP_INT_MAX );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		parent::tearDown();
	}

	/** Refuses WordPress HTTP requests. */
	public function block_http() {
		return new \WP_Error( 'fixture_offline', 'HTTP disabled in user tests.' );
	}

	/**
	 * Returns the authenticated lookup or records a mutation.
	 *
	 * @param mixed  $pre Previous response.
	 * @param object $website Selected site.
	 * @param string $what Child command.
	 * @param array  $params Command arguments.
	 * @throws \MainWP\Dashboard\MainWP_Exception For the lookup exception fixture.
	 */
	public function child_response( $pre, $website, $what, $params ) {
		if ( 'search_users' === $what ) {
			++$this->fallback_calls;
			$this->assertSame(
				array(
					'search'         => '*',
					'search_columns' => 'user_login',
				),
				$params
			);
			if ( 'throw' === $this->fallback ) {
				throw new \MainWP\Dashboard\MainWP_Exception( 'fixture_fallback_failure' );
			}
			return $this->fallback;
		}
		$this->assertSame( 'user_action', $what );
		if ( 'edit' === $params['action'] ) {
			if ( 'throw' === $this->lookup ) {
				throw new \MainWP\Dashboard\MainWP_Exception( 'fixture_lookup_failure' );
			}
			return array(
				'status'    => $this->lookup,
				'user_data' => array( 'public_display' => array( 'display_username' => $this->login ) ),
			);
		}
		$this->mutations[] = $params;
		return array( 'status' => 'SUCCESS' );
	}

	/**
	 * Calls the real REST handler with a JSON request.
	 *
	 * @param string $action REST action.
	 * @param int    $id Child user ID.
	 * @param array  $body JSON body.
	 */
	private function request( $action, $id, $body = array(
		'first_name' => 'John',
		'last_name'  => 'Doe',
	) ) {
		$request = new \WP_REST_Request( 'delete' === $action ? 'DELETE' : 'PUT', '/mainwp/v2/users/' . $this->site . '/' . $id . '/' . $action );
		$request->set_param( 'id_domain', $this->site );
		$request->set_param( 'user_id', $id );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		$controller = new \MainWP_Rest_Users_Controller();
		return 'delete' === $action ? $controller->delete_user( $request ) : $controller->edit_user( $request );
	}

	/** Profile edits survive an owner-ID collision. */
	public function test_collision_profile_edit_reaches_child(): void {
		$this->assertNotWPError( $this->request( 'edit', $this->owner ) );
		$this->assertSame( 'donotupdate', $this->mutations[0]['extra']['role'] );
	}

	/** Role edits for other users survive an owner-ID collision. */
	public function test_collision_role_edit_reaches_child(): void {
		$this->assertNotWPError( $this->request( 'edit', $this->owner, array( 'role' => 'editor' ) ) );
		$this->assertSame( 'editor', $this->mutations[0]['extra']['role'] );
	}

	/** Deletion survives an owner-ID collision. */
	public function test_collision_delete_reaches_child(): void {
		$this->assertNotWPError( $this->request( 'delete', $this->owner ) );
		$this->assertSame( 'delete', $this->mutations[0]['action'] );
	}

	/** Other users remain editable and deletable. */
	public function test_noncollision_control(): void {
		$this->assertNotWPError( $this->request( 'edit', $this->owner + 100 ) );
		$this->assertNotWPError( $this->request( 'delete', $this->owner + 100 ) );
		$this->assertCount( 2, $this->mutations );
	}

	/** Connection protection is independent of the owner ID. */
	public function test_connected_admin_is_protected_with_either_id(): void {
		$this->login = 'connadmin';
		foreach ( array( $this->owner, $this->owner + 100 ) as $id ) {
			$delete = $this->request( 'delete', $id );
			$this->assertWPError( $delete );
			$this->assertSame( 'user_protected', $delete->get_error_code() );
			$this->assertSame( 403, $delete->get_error_data()['status'] );
			$edit = $this->request( 'edit', $id, array( 'role' => 'editor' ) );
			$this->assertWPError( $edit );
			$this->assertSame( 'user_update_error', $edit->get_error_code() );
			$this->assertSame( 403, $edit->get_error_data()['status'] );
		}
		$this->assertSame( array(), $this->mutations );
	}

	/** Profile edits and the no-change sentinel remain allowed. */
	public function test_connected_admin_profile_and_sentinel_are_allowed(): void {
		$this->login = 'connadmin';
		$this->assertNotWPError( $this->request( 'edit', $this->owner ) );
		$this->assertNotWPError( $this->request( 'edit', $this->owner + 100, array( 'role' => 'donotupdate' ) ) );
		$this->assertCount( 2, $this->mutations );
	}

	/** Unknown logins refuse destructive operations only. */
	public function test_missing_login_blocks_only_delete_and_role(): void {
		$this->login = null;
		foreach ( array( 'delete', 'edit' ) as $action ) {
			$result = $this->request( $action, $this->owner + 100, array( 'role' => 'editor' ) );
			$this->assertWPError( $result );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
		$this->assertSame( array(), $this->mutations );
		$this->assertNotWPError( $this->request( 'edit', $this->owner ) );
	}

	/** Lookup failures retain their existing error contracts. */
	public function test_missing_user_and_child_exception_keep_errors(): void {
		$this->lookup = 'FAILED';
		$result       = $this->request( 'delete', $this->owner );
		$this->assertSame( 'user_not_found', $result->get_error_code() );
		$this->assertSame( 'User not found.', $result->get_error_message() );
		$this->lookup = 'throw';
		$this->assertSame( 'get_user_error', $this->request( 'edit', $this->owner )->get_error_code() );
		$this->assertSame( array(), $this->mutations );
	}

	/** Withheld Team Control rights stop both mutations. */
	public function test_manage_users_right_is_required(): void {
		add_filter( 'mainwp_currentusercan', '__return_false' );
		foreach ( array( 'edit', 'delete' ) as $action ) {
			$result = $this->request( $action, $this->owner );
			$this->assertSame( 'mainwp_insufficient_right', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
		remove_filter( 'mainwp_currentusercan', '__return_false' );
		$this->assertSame( array(), $this->mutations );
	}

	/** A present login avoids the fallback, even when the request spoofs it. */
	public function test_present_login_never_fetches_users_list(): void {
		$this->assertNotWPError(
			$this->request(
				'edit',
				$this->owner,
				array(
					'role'     => 'editor',
					'username' => 'connadmin',
				)
			)
		);
		$this->assertNotWPError( $this->request( 'delete', $this->owner ) );
		$this->assertSame( 0, $this->fallback_calls );
	}

	/** Deduplicated Child display choices resolve through the users list by ID. */
	public function test_missing_login_uses_matching_fallback_user(): void {
		$this->login    = null;
		$this->fallback = array(
			array(
				'id'    => $this->owner + 100,
				'login' => 'connadmin',
			),
			array(
				'id'    => (string) $this->owner,
				'login' => 'editor2',
			),
		);
		$this->assertNotWPError( $this->request( 'edit', $this->owner, array( 'role' => 'editor' ) ) );
		$this->assertNotWPError( $this->request( 'delete', $this->owner ) );
		$this->assertCount( 2, $this->mutations );
		$this->assertSame( 2, $this->fallback_calls );
	}

	/** A fallback login protects the connection regardless of the owner ID. */
	public function test_fallback_connected_admin_stays_protected(): void {
		$this->login    = null;
		$this->fallback = array(
			array(
				'id'    => $this->owner + 100,
				'login' => 'connadmin',
			),
		);
		foreach ( array( 'edit', 'delete' ) as $action ) {
			$result = $this->request(
				$action,
				$this->owner + 100,
				array(
					'role'     => 'editor',
					'username' => 'editor2',
				)
			);
			$this->assertWPError( $result );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
		$this->assertSame( array(), $this->mutations );
	}

	/** Connection login capitalization cannot bypass either protection guard. */
	public function test_connected_login_case_differences_remain_protected(): void {
		global $wpdb;
		$this->assertNotFalse( $wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'adminname' => 'ConnAdmin' ), array( 'id' => $this->site ) ) );
		foreach ( array( 'connadmin', null ) as $login ) {
			$this->login    = $login;
			$this->fallback = array(
				array(
					'id'    => $this->owner + 100,
					'login' => 'connadmin',
				),
			);
			foreach ( array( 'edit', 'delete' ) as $action ) {
				$result = $this->request( $action, $this->owner + 100, array( 'role' => 'editor' ) );
				$this->assertWPError( $result );
				$this->assertSame( 403, $result->get_error_data()['status'] );
			}
		}
		$this->assertSame( array(), $this->mutations );
		$this->assertNotWPError( $this->request( 'edit', $this->owner + 100 ) );
		$this->assertCount( 1, $this->mutations );
	}

	/** Failed, malformed and nonmatching fallback responses cannot permit mutations. */
	public function test_unresolved_fallback_fails_closed(): void {
		$this->login = null;
		foreach ( array(
			'throw',
			null,
			array( 'error' => 'Unavailable' ),
			array(
				array(
					'id'    => $this->owner + 100,
					'login' => 'editor2',
				),
			),
			array(
				array(
					'id'    => $this->owner . 'oops',
					'login' => 'editor2',
				),
			),
			array(
				array(
					'id'    => $this->owner,
					'login' => array(),
				),
			),
		) as $fallback ) {
			$this->fallback = $fallback;
			foreach ( array( 'edit', 'delete' ) as $action ) {
				$result = $this->request( $action, $this->owner, array( 'role' => 'editor' ) );
				$this->assertWPError( $result );
				$this->assertSame( 403, $result->get_error_data()['status'] );
			}
		}
		$this->assertSame( array(), $this->mutations );
		$before = $this->fallback_calls;
		$this->assertNotWPError( $this->request( 'edit', $this->owner ) );
		$this->assertSame( $before, $this->fallback_calls );
	}
}
