<?php
/**
 * Remote MCP revocation trigger tests.
 *
 * The user and home triggers run through real WordPress hooks, registered by a provider over the
 * test store. The fixtures detach the boot provider to avoid duplicate lifecycle hooks.
 * Panel triggers go through the panel's action handler.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Revocation;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

require_once __DIR__ . '/fixtures/panel-test-support.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Revocation
 */
class Test_MainWP_OAuth_Revocation extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures {
		set_up as fixtures_set_up;
	}

	/**
	 * Provider under test, hooked in.
	 *
	 * @var OAuth_Revocation
	 */
	private $revocation;

	public function set_up() {
		$this->fixtures_set_up();
		$this->assertInstanceOf( OAuth_Revocation::class, Remote_MCP::instance()->services()->get( 'hooks_revocation' ), 'the implementing class fills the slot' );
		$services         = $this->panel_services();
		$this->revocation = new OAuth_Revocation( $services );
		$this->revocation->register_hooks( $services );
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Assert a grant was revoked for a reason and its access token no longer works.
	 *
	 * @param array  $connection Exchange result.
	 * @param string $reason     Revoke reason.
	 */
	private function assert_revoked( array $connection, string $reason ): void {
		$grant = $this->grant( $connection['grant_id'] );
		$this->assertSame( 'revoked', $grant['status'], $reason );
		$this->assertSame( $reason, $grant['revoke_reason'] );
		$this->assertFalse( $this->access_ok( $connection['access_token'] ), $reason . ': token refused' );
	}

	/**
	 * The provider under test.
	 *
	 * @return OAuth_Revocation
	 */
	private function booted(): OAuth_Revocation {
		return $this->revocation;
	}

	public function test_user_deleted_revokes_grants() {
		$doomed = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$theirs = $this->connect( $doomed );
		$mine   = $this->connect();

		wp_delete_user( $doomed );

		$this->assert_revoked( $theirs, 'user_deleted' );
		$this->assertTrue( $this->access_ok( $mine['access_token'] ), 'another user is untouched' );
	}

	public function test_removed_from_blog_revokes_grants() {
		$connection = $this->connect();
		do_action( 'remove_user_from_blog', $this->user_id, get_current_blog_id(), 0 );
		$this->assert_revoked( $connection, 'removed_from_blog' );
	}

	public function test_super_admin_revoked_revokes_grants() {
		$connection = $this->connect();
		do_action( 'revoked_super_admin', $this->user_id );
		$this->assert_revoked( $connection, 'super_admin_revoked' );
	}

	public function test_wp_set_password_revokes_grants() {
		$connection = $this->connect();
		wp_set_password( 'a-brand-new-password', $this->user_id );
		$this->assert_revoked( $connection, 'password_changed' );
	}

	public function test_profile_password_change_revokes_grants() {
		global $wpdb;
		$connection = $this->connect();

		// A profile edit that keeps the password changes nothing.
		wp_update_user(
			array(
				'ID'           => $this->user_id,
				'display_name' => 'Renamed',
			)
		);
		$this->assertSame( 'live', $this->grant( $connection['grant_id'] )['status'], 'no password change' );

		// profile_update with a new stored hash: a password change made without wp_set_password().
		$old = get_userdata( $this->user_id );
		$wpdb->update( $wpdb->users, array( 'user_pass' => wp_hash_password( 'changed-elsewhere' ) ), array( 'ID' => $this->user_id ) );
		clean_user_cache( $this->user_id );
		do_action( 'profile_update', $this->user_id, $old, array() );

		$this->assert_revoked( $connection, 'password_changed' );
	}

	public function test_losing_manage_options_revokes_grants() {
		$connection = $this->connect();
		$user       = get_userdata( $this->user_id );

		// Adding a role keeps manage_options, so nothing is revoked.
		$user->add_role( 'editor' );
		$this->assertSame( 'live', $this->grant( $connection['grant_id'] )['status'], 'still an administrator' );

		$user->set_role( 'editor' );
		$this->assert_revoked( $connection, 'cap_lost' );

		// remove_role() alone dropping the last manage_options role counts too.
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$again = $this->connect( $other );
		get_userdata( $other )->remove_role( 'administrator' );
		$this->assert_revoked( $again, 'cap_lost' );
	}

	public function test_manual_revoke_takes_effect_on_next_request() {
		$first  = $this->connect();
		$second = $this->connect();

		$result = $this->post( 'revoke', (string) $first['grant_id'] );

		$this->assertTrue( $result['ok'], $result['code'] );
		$this->assert_revoked( $first, 'manual' );
		$this->assertTrue( $this->access_ok( $second['access_token'] ), 'only the named connection ends' );
	}

	public function test_disabling_connector_revokes_all_and_rotates_secret() {
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$mine    = $this->connect();
		$other   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$theirs  = $this->connect( $other );
		$version = $this->hasher->secret_version();

		$result = $this->post( 'toggle', 'off' );

		$this->assertTrue( $result['ok'], $result['code'] );
		$this->assertFalse( Remote_MCP_Settings::enabled() );
		$this->assert_revoked( $mine, 'disabled' );
		$this->assertSame( 'revoked', $this->grant( $theirs['grant_id'] )['status'] );
		$this->assertGreaterThan( $version, ( new \MainWP\Dashboard\Remote_MCP\OAuth_Token_Hasher() )->secret_version(), 'secret rotated' );
	}

	public function test_home_change_revokes_grants_and_flags_reconnect() {
		update_option( Remote_MCP_Settings::OPTION_BOUND_HOME, 'https://dash.example.org', false );
		$connection = $this->connect();
		$this->assertNull( OAuth_Revocation::home_changed_at() );

		update_option( 'home', 'https://moved.example.org' );

		$this->assert_revoked( $connection, 'home_changed' );
		$this->assertNotNull( OAuth_Revocation::home_changed_at(), 'reconnect flag set' );
		$this->assertSame( 'https://moved.example.org', get_option( Remote_MCP_Settings::OPTION_BOUND_HOME ), 'bound to the new home' );
		$this->assertStringContainsString( 'Your site address changed. Connected AI apps need to reconnect.', $this->admin()->render() );

		// A home set through WP_HOME fires no option hook; the per-request compare catches it.
		update_option( Remote_MCP_Settings::OPTION_BOUND_HOME, 'https://old.example.org', false );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$again = $this->connect();
		$this->booted()->check_bound_home();
		$this->assert_revoked( $again, 'home_changed' );

		// A matching home leaves connections alone.
		$kept = $this->connect();
		$this->booted()->check_bound_home();
		$this->assertSame( 'live', $this->grant( $kept['grant_id'] )['status'] );
	}

	public function test_each_user_trigger_bumps_user_generation() {
		$triggers = array(
			'deleted_user'          => function ( int $user ) {
				wp_delete_user( $user );
			},
			'remove_user_from_blog' => function ( int $user ) {
				do_action( 'remove_user_from_blog', $user, get_current_blog_id(), 0 );
			},
			'revoked_super_admin'   => function ( int $user ) {
				do_action( 'revoked_super_admin', $user );
			},
			'wp_set_password'       => function ( int $user ) {
				wp_set_password( 'another-password', $user );
			},
			'profile_update'        => function ( int $user ) {
				global $wpdb;
				$old = get_userdata( $user );
				$wpdb->update( $wpdb->users, array( 'user_pass' => wp_hash_password( 'x' ) ), array( 'ID' => $user ) );
				clean_user_cache( $user );
				do_action( 'profile_update', $user, $old, array() );
			},
			'set_user_role'         => function ( int $user ) {
				get_userdata( $user )->set_role( 'author' );
			},
			'disconnect_mine'       => function ( int $user ) {
				wp_set_current_user( $user );
				$this->assertTrue( $this->post( 'disconnect_mine' )['ok'] );
			},
		);
		foreach ( $triggers as $name => $trigger ) {
			$user   = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$before = $this->generation( $user );
			$trigger( $user );
			$this->assertSame( $before + 1, $this->generation( $user ), $name );
		}
		$this->assertSame( 0, $this->generation( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ), 'creating a user bumps nothing' );
	}

	public function test_disable_domain_change_and_rotation_bump_site_generation() {
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$start = $this->generation( 0 );

		$this->assertTrue( $this->post( 'toggle', 'off' )['ok'] );
		$this->assertSame( $start + 1, $this->generation( 0 ), 'disable' );

		update_option( Remote_MCP_Settings::OPTION_BOUND_HOME, 'https://dash.example.org', false );
		update_option( 'home', 'https://moved.example.org' );
		$this->assertSame( $start + 2, $this->generation( 0 ), 'domain change' );

		update_option( 'home', 'https://dash.example.org' );
		$this->assertSame( $start + 3, $this->generation( 0 ), 'and back again' );

		$connection = $this->connect();
		$this->assertTrue( $this->post( 'disconnect_all' )['ok'] );
		$this->assertSame( $start + 4, $this->generation( 0 ), 'secret rotation' );
		$this->assert_revoked( $connection, 'secret_rotated' );
		$this->assertSame( OAuth_Store::OK, $this->connect()['outcome'], 'new connections work afterwards' );
	}
	public function test_http_wp_home_password_reset_revokes_after_https_restored() {
		$connection = $this->connect();
		$before     = $this->generation( $this->user_id );
		$home       = function () {
			return 'http://dash.example.org';
		};
		add_filter( 'pre_option_home', $home );
		try {
			$services = new Remote_MCP_Services();
			$this->assertNull( $services->store() );
			$provider = new OAuth_Revocation( $services );
			$provider->register_hooks( $services );
			remove_action( 'wp_set_password', array( $this->revocation, 'on_set_password' ) );
			wp_set_password( 'reset-over-http', $this->user_id );
		} finally {
			remove_filter( 'pre_option_home', $home );
		}
		$this->assertSame( 'https://dash.example.org', get_option( 'home' ) );
		$this->assertSame( $before + 1, $this->generation( $this->user_id ) );
		$this->assert_revoked( $connection, 'password_changed' );
	}

	public function test_http_home_and_user_removal_invalidate_installed_connections() {
		$connection = $this->connect();
		$services   = new Remote_MCP_Services( array(), new Remote_MCP_Urls( 'http://dash.example.org' ) );
		$provider   = new OAuth_Revocation( $services );
		$before     = $this->generation( $this->user_id );
		$provider->on_removed_from_blog( $this->user_id );
		$this->assertSame( $before + 1, $this->generation( $this->user_id ) );
		$this->assert_revoked( $connection, 'removed_from_blog' );
		$connection = $this->connect();
		$site       = $this->generation( 0 );
		update_option( Remote_MCP_Settings::OPTION_BOUND_HOME, 'https://dash.example.org', false );
		$provider->on_home_updated( 'https://dash.example.org', 'http://dash.example.org' );
		$this->assertSame( $site + 1, $this->generation( 0 ) );
		$this->assert_revoked( $connection, 'home_changed' );
		// An HTTP home has no canonical base. It gets bound as unservable, so the per-request check
		// revokes once for it instead of on every request.
		$this->assertNotSame( '', (string) get_option( Remote_MCP_Settings::OPTION_BOUND_HOME ) );
		update_option( 'home', 'http://dash.example.org' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$provider->check_bound_home();
		$once = $this->generation( 0 );
		$provider->check_bound_home();
		$provider->check_bound_home();
		$this->assertSame( $once, $this->generation( 0 ), 'an unchanged HTTP home is not revoked again on every request' );
	}

	private function assert_user_invalidation_retries( string $failure ): void {
		$store                = new Remote_MCP_Panel_Test_Failing_Store( $this->hasher, self::RESOURCE, $this->clock );
		$services             = $this->panel_services();
		$services->test_store = $store;
		$provider             = new OAuth_Revocation( $services );
		$connection           = $this->connect();
		$before               = $this->generation( $this->user_id );
		$store->$failure      = true;
		$provider->on_set_password( '', $this->user_id );
		$this->assertStringContainsString( 'Could not end every affected AI connection', $this->admin()->render() );
		$store->$failure = false;
		// A later password hook in the same request must not be suppressed after failure.
		$provider->on_set_password( '', $this->user_id );
		$this->assertGreaterThan( $before, $this->generation( $this->user_id ) );
		$this->assert_revoked( $connection, 'password_changed' );
		$this->assertStringNotContainsString( 'Could not end every affected AI connection', $this->admin()->render() );

		$connection      = $this->connect();
		$provider        = new OAuth_Revocation( $services );
		$store->$failure = true;
		$provider->on_deleted_user( $this->user_id );
		$store->$failure = false;
		// init on the next request retries even if the lifecycle hook never fires again.
		$next = new OAuth_Revocation( $services );
		$next->register_hooks( $services );
		do_action( 'init' );
		$this->assert_revoked( $connection, 'user_deleted' );
		$this->assertStringNotContainsString( 'Could not end every affected AI connection', $this->admin()->render() );
	}

	public function test_failed_user_generation_bump_remains_unhandled_and_retries() {
		$this->assert_user_invalidation_retries( 'fail_bump' );
	}

	public function test_failed_user_revoke_remains_unhandled_and_retries() {
		$this->assert_user_invalidation_retries( 'fail_revoke' );
	}

	private function assert_home_invalidation_retries( string $failure ): void {
		$store                = new Remote_MCP_Panel_Test_Failing_Store( $this->hasher, self::RESOURCE, $this->clock );
		$services             = $this->panel_services();
		$services->test_store = $store;
		$provider             = new OAuth_Revocation( $services );
		$connection           = $this->connect();
		update_option( Remote_MCP_Settings::OPTION_BOUND_HOME, 'https://old.example.org', false );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$store->$failure = true;
		$provider->check_bound_home();
		$this->assertSame( 'https://old.example.org', get_option( Remote_MCP_Settings::OPTION_BOUND_HOME ) );
		$this->assertNull( OAuth_Revocation::home_changed_at() );
		$this->assertStringContainsString( 'Could not end every affected AI connection', $this->admin()->render() );
		$store->$failure = false;
		$next            = new OAuth_Revocation( $services );
		$next->register_hooks( $services );
		do_action( 'init' );
		$this->assert_revoked( $connection, 'home_changed' );
		$this->assertSame( 'https://dash.example.org', get_option( Remote_MCP_Settings::OPTION_BOUND_HOME ) );
		$this->assertStringNotContainsString( 'Could not end every affected AI connection', $this->admin()->render() );
	}

	public function test_failed_home_generation_bump_does_not_rebind_and_retries() {
		$this->assert_home_invalidation_retries( 'fail_bump' );
	}

	public function test_failed_home_revoke_does_not_rebind_and_retries() {
		$this->assert_home_invalidation_retries( 'fail_revoke' );
	}

	public function test_deactivation_ends_connections() {
		$connection = $this->connect();
		$generation = $this->generation( 0 );
		$version = $this->hasher->secret_version();
		$remote = new Remote_MCP( $this->panel_services() );
		$remote->register_hooks();
		do_action( 'deactivate_' . plugin_basename( MAINWP_PLUGIN_FILE ), false );
		$this->assert_revoked( $connection, 'disabled' );
		$this->assertGreaterThan( $generation, $this->generation( 0 ) );
		$this->assertGreaterThan( $version, ( new \MainWP\Dashboard\Remote_MCP\OAuth_Token_Hasher() )->secret_version() );
		$this->assertFalse( Remote_MCP_Settings::enabled() );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$this->assertFalse( $this->access_ok( $connection['access_token'] ) );
	}

}
