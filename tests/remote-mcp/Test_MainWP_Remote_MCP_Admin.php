<?php
/**
 * Remote MCP panel tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Admin;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Admin
 */
class Test_MainWP_Remote_MCP_Admin extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures {
		set_up as fixtures_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * $_SERVER before the test.
	 *
	 * @var array
	 */
	private $saved_server;

	public function set_up() {
		$this->saved_server = $_SERVER;
		$this->fixtures_set_up();
	}

	public function tear_down() {
		$_SERVER = $this->saved_server;
		parent::tear_down();
	}

	/**
	 * A pending Dashboard-tier approval on a grant.
	 *
	 * @param int $grant_id Grant.
	 * @return int Approval id.
	 */
	private function pending_approval( int $grant_id ): int {
		$approval = $this->store->create_approval(
			array(
				'grant_id'    => $grant_id,
				'ability'     => 'mainwp/delete-site-v1',
				'tier'        => 'dashboard',
				'args_digest' => str_repeat( 'a', 64 ),
				'summary'     => 'Delete a site',
			)
		);
		$this->assertSame( OAuth_Store::OK, $approval['outcome'] );
		return (int) $approval['approval_id'];
	}

	/**
	 * Whether rendered HTML lists a connection row.
	 *
	 * @param string $html     HTML.
	 * @param int    $grant_id Grant.
	 * @return bool
	 */
	private function lists_grant( string $html, int $grant_id ): bool {
		return false !== strpos( $html, 'data-grant="' . $grant_id . '"' );
	}

	public function test_off_by_default_and_carries_no_beta_label() {
		delete_option( Remote_MCP_Settings::OPTION_ENABLED );
		$this->assertFalse( Remote_MCP_Settings::is_active() );

		$html = $this->admin()->render();
		$this->assertStringContainsString( 'value="toggle"', $html );
		$this->assertStringContainsString( 'name="target" value="on"', $html, 'the switch offers "on" while off' );
		$this->assertDoesNotMatchRegularExpression( '/\bbeta\b/i', $html );

		$view = $this->admin()->direct_view( null );
		$this->assertIsArray( $view );
		$this->assertIsCallable( $view['render'] );
	}

	public static function site_wide_actions() {
		return array(
			'toggle' => array( 'toggle', 'off' ),
			'registration' => array( 'registration_switch', '' ),
			'static client' => array( 'static_client_switch', 'mainwp-cursor' ),
			'tool' => array( 'tool_switch', 'list_sites_v1' ),
			'category' => array( 'category_switch', 'mainwp-sites' ),
			'unaudited' => array( 'unaudited_switch', '' ),
		);
	}

	private function site_wide_catalog() {
		$this->catalog->rows = array(
			array( 'tool_name' => 'list_sites_v1', 'ability' => 'mainwp/list-sites-v1', 'category' => 'mainwp-sites', 'standard_tier' => 'read', 'availability' => 'available', 'reason' => null ),
			array( 'tool_name' => 'acme_rebuild_v1', 'ability' => 'acme/rebuild-v1', 'category' => 'acme', 'standard_tier' => 'dashboard', 'availability' => 'not_reviewed', 'reason' => 'uncontracted' ),
		);
	}

	public function test_switching_off_a_static_client_works_before_the_connector_is_installed() {
		delete_option( Remote_MCP_Settings::OPTION_DB_VERSION );
		$this->assertFalse( Remote_MCP_Install::is_installed() );

		$result = $this->post( 'static_client_switch', 'mainwp-cursor', array( 'value' => 'off' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( Remote_MCP_Settings::static_client_enabled( 'mainwp-cursor' ) );
	}

	public function test_switching_off_a_static_client_ends_its_connections() {
		$this->assertTrue( $this->post( 'toggle', 'on' )['ok'] );
		Remote_MCP_Settings::set_static_client_enabled( 'mainwp-cursor', true );
		$cursor = $this->connect_write(
			$this->user_id,
			'standard',
			array(
				'client_id'   => 'mainwp-cursor',
				'client_kind' => 'static',
			)
		);
		$other  = $this->connect_write( $this->user_id );

		$this->assertTrue( $this->post( 'static_client_switch', 'mainwp-cursor', array( 'value' => 'off' ) )['ok'] );
		$this->assertTrue( $this->post( 'static_client_switch', 'mainwp-cursor', array( 'value' => 'on' ) )['ok'] );

		$this->assertSame( 'revoked', $this->store->get_grant( (int) $cursor['grant_id'] )['status'], 'switching it back on must not bring old tokens back' );
		$this->assertSame( 'live', $this->store->get_grant( (int) $other['grant_id'] )['status'] );
	}

	/** @dataProvider site_wide_actions */
	public function test_site_wide_actions_require_full_administrator( $action, $target ) {
		$this->site_wide_catalog();
		$this->assertTrue( $this->post( 'toggle', 'on' )['ok'] );
		Remote_MCP_Settings::set_registration_enabled( true );
		Remote_MCP_Settings::set_static_client_enabled( 'mainwp-cursor', true );
		Remote_MCP_Settings::set_tool_enabled( 'list_sites_v1', true );
		Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', true );
		Remote_MCP_Settings::set_unaudited_extension_tools_enabled( true );
		$grant = $this->connect_write( $this->user_id );
		$options = array( Remote_MCP_Settings::OPTION_ENABLED, Remote_MCP_Settings::OPTION_REGISTRATION_ENABLED, Remote_MCP_Settings::OPTION_STATIC_CLIENTS, Remote_MCP_Settings::OPTION_TOOL_SWITCHES, Remote_MCP_Settings::OPTION_UNAUDITED_TOOLS_ENABLED );
		$before = array_map( 'get_option', $options );
		wp_set_current_user( $this->limited_admin() );
		$result = $this->post( $action, $target, array( 'value' => 'off' ) );
		$this->assertSame( 'forbidden', $result['code'] );
		$this->assertSame( $before, array_map( 'get_option', $options ) );
		$this->assertSame( 'live', $this->grant( $grant['grant_id'] )['status'] );

		wp_set_current_user( $this->user_id );
		$this->assertTrue( $this->post( $action, $target, array( 'value' => 'off' ) )['ok'] );
		$this->assertNotSame( $before, array_map( 'get_option', $options ) );
	}

	public function test_limited_panel_shows_site_wide_states_without_action_forms() {
		$this->site_wide_catalog();
		$limited = $this->limited_admin();
		foreach ( array( false, true ) as $on ) {
			update_option( Remote_MCP_Settings::OPTION_ENABLED, $on ? '1' : '0' );
			Remote_MCP_Settings::set_registration_enabled( $on );
			Remote_MCP_Settings::set_static_client_enabled( 'mainwp-cursor', $on );
			Remote_MCP_Settings::set_tool_enabled( 'list_sites_v1', $on );
			Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', $on );
			Remote_MCP_Settings::set_unaudited_extension_tools_enabled( $on );
			wp_set_current_user( $limited );
			$html = $this->admin()->render();
			foreach ( self::site_wide_actions() as list( $action ) ) {
				$this->assertStringNotContainsString( 'value="' . $action . '"', $html );
			}
			$this->assertStringContainsString( $on ? 'On. AI apps' : 'Off. No AI app', $html );
			$this->assertMatchesRegularExpression( '/Let apps on your own computer connect<\/td>\s*<td[^>]*>\s*<span class="ui mini basic label">' . ( $on ? 'On' : 'Off' ) . '<\/span>\s*<\/td>/', $html );
			$this->assertMatchesRegularExpression( '/<code>list_sites_v1<\/code><\/td>\s*<td[^>]*>\s*<span class="ui mini basic label">' . ( $on ? 'On' : 'Off' ) . '<\/span>\s*<\/td>/', $html );
			foreach ( array( 'self_test', 'external_check' ) as $action ) {
				$this->assertStringContainsString( 'value="' . $action . '"', $html );
			}
			wp_set_current_user( $this->user_id );
			$html = $this->admin()->render();
			foreach ( self::site_wide_actions() as list( $action ) ) {
				$this->assertStringContainsString( 'value="' . $action . '"', $html );
			}
		}
	}

	public function test_toggle_requires_nonce_and_manage_options() {
		$bad = $this->post( 'toggle', 'on', array(), 'not-a-nonce' );
		$this->assertSame( 'bad_nonce', $bad['code'] );
		$this->assertFalse( Remote_MCP_Settings::enabled() );

		// A nonce for another action or target doesn't carry over.
		$other = $this->post( 'toggle', 'on', array(), wp_create_nonce( Remote_MCP_Admin::nonce_action( 'toggle', 'off' ) ) );
		$this->assertSame( 'bad_nonce', $other['code'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'forbidden', $this->post( 'toggle', 'on' )['code'] );
		$this->assertFalse( Remote_MCP_Settings::enabled() );

		wp_set_current_user( $this->user_id );
		$this->enable_check->reasons = array( 'discovery_failed' );
		$blocked                     = $this->post( 'toggle', 'on' );
		$this->assertFalse( $blocked['ok'] );
		$this->assertSame( 'blocked', $blocked['code'] );
		$this->assertContains( 'discovery_failed', $blocked['reasons'] );
		$this->assertFalse( Remote_MCP_Settings::enabled() );

		$this->enable_check->reasons = array();
		$this->assertTrue( $this->post( 'toggle', 'on' )['ok'] );
		$this->assertTrue( Remote_MCP_Settings::enabled() );

		$this->assertTrue( $this->post( 'toggle', 'off' )['ok'] );
		$this->assertFalse( Remote_MCP_Settings::enabled() );
	}

	public function test_revocation_actions_work_on_an_http_home_and_loosening_does_not() {
		$grant    = $this->connect_write( $this->user_id );
		$services = new class( array( 'tool_executor' => $this->catalog, 'known_clients' => $this->known, 'enable_check' => $this->enable_check ), $this->store ) extends Remote_MCP_Panel_Test_Services {
			public function store(): ?OAuth_Store {
				return null;
			}
		};
		$admin    = new Remote_MCP_Admin( $services );
		// While the home can't serve MCP, connections can still be ended; nothing new is issued.
		$this->assertTrue( $this->post( 'revoke', (string) $grant['grant_id'], array(), null, $admin )['ok'] );
		$this->assertSame( 'revoked', $this->grant( $grant['grant_id'] )['status'] );
		$this->assertSame( 'insecure_home', $this->post( 'mint_token', '', array(), null, $admin )['code'] );
		$narrow = $this->connect_write( $this->user_id );
		$this->assertTrue( $this->post( 'narrow_scope', (string) $narrow['grant_id'], array(), null, $admin )['ok'], 'narrowing ends write access on HTTP too' );
	}

	public function test_revoke_requires_nonce_and_ownership_or_admin() {
		$owner     = $this->connect_write( $this->user_id );
		$limited   = $this->limited_admin();
		$theirs    = $this->connect_write( $limited );
		$colleague = $this->connect_write( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A limited user can't revoke someone else's connection, whatever the nonce.
		wp_set_current_user( $limited );
		$this->assertSame( 'forbidden', $this->post( 'revoke', (string) $owner['grant_id'] )['code'] );
		$this->assertSame( 'live', $this->grant( $owner['grant_id'] )['status'] );

		// Their own needs a valid nonce.
		$this->assertSame( 'bad_nonce', $this->post( 'revoke', (string) $theirs['grant_id'], array(), 'x' )['code'] );
		$this->assertSame( 'live', $this->grant( $theirs['grant_id'] )['status'] );
		$this->assertTrue( $this->post( 'revoke', (string) $theirs['grant_id'] )['ok'] );
		$this->assertSame( 'revoked', $this->grant( $theirs['grant_id'] )['status'] );

		// A full administrator revokes anyone's.
		wp_set_current_user( $this->user_id );
		$this->assertTrue( $this->post( 'revoke', (string) $colleague['grant_id'] )['ok'] );
		$this->assertSame( 'revoked', $this->grant( $colleague['grant_id'] )['status'] );
		$this->assertTrue( $this->post( 'revoke', (string) $owner['grant_id'] )['ok'] );

		// A user without manage_options revokes nothing, not even with a nonce.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertSame( 'forbidden', $this->post( 'revoke', '999' )['code'] );
	}

	public function test_connected_apps_list_shows_no_token_material() {
		$connection = $this->connect_write( $this->user_id );
		$token      = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'LM Studio', time() + DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $token['outcome'] );

		$html = $this->admin()->render();
		$this->assertTrue( $this->lists_grant( $html, $connection['grant_id'] ) );
		$this->assertTrue( $this->lists_grant( $html, $token['grant_id'] ) );
		$this->assertStringContainsString( 'LM Studio', $html );
		$this->assertDoesNotMatchRegularExpression( '/mw(pat|prt|pac|pct|pap|pws)_[A-Za-z0-9_-]{10}/', $html );
		foreach ( array( $connection['access_token'], $connection['refresh_token'], $token['token'] ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $html );
		}
		foreach ( $this->rows( 'tokens' ) as $row ) {
			$this->assertStringNotContainsString( $row['token_hash'], $html );
		}
	}

	public function test_connected_apps_list_shows_own_connections_unless_full_administrator() {
		$limited = $this->limited_admin();
		$mine    = $this->connect_write( $this->user_id );
		$theirs  = $this->connect_write( $limited );

		wp_set_current_user( $limited );
		$html = $this->admin()->render();
		$this->assertTrue( $this->lists_grant( $html, $theirs['grant_id'] ) );
		$this->assertFalse( $this->lists_grant( $html, $mine['grant_id'] ), 'a limited user sees only their own' );

		wp_set_current_user( $this->user_id );
		$html = $this->admin()->render();
		$this->assertTrue( $this->lists_grant( $html, $mine['grant_id'] ) );
		$this->assertTrue( $this->lists_grant( $html, $theirs['grant_id'] ), 'a full administrator sees everyone' );
	}

	public function test_scope_narrowing_in_panel_takes_effect_next_request() {
		$connection = $this->connect_write( $this->user_id );
		$before     = $this->store->check_access_token( $connection['access_token'] );
		$this->assertContains( 'mainwp:write', OAuth_Store::scope_tokens( $before['scope'] ) );

		$result = $this->post( 'narrow_scope', (string) $connection['grant_id'] );

		$this->assertTrue( $result['ok'], $result['code'] );
		$after = $this->store->check_access_token( $connection['access_token'] );
		$this->assertSame( OAuth_Store::OK, $after['outcome'] );
		$this->assertNotContains( 'mainwp:write', OAuth_Store::scope_tokens( $after['scope'] ), 'the same token loses write' );
		$this->assertContains( 'mainwp:read', OAuth_Store::scope_tokens( $after['scope'] ) );
		$this->assertSame( (int) $before['policy_version'] + 1, (int) $after['policy_version'] );

		// Narrowing a view-only connection again changes nothing.
		$this->assertFalse( $this->post( 'narrow_scope', (string) $connection['grant_id'] )['ok'] );
	}

	public function test_pending_approvals_list_shows_only_own_rows() {
		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$mine   = $this->pending_approval( $this->connect_write( $this->user_id )['grant_id'] );
		$theirs = $this->pending_approval( $this->connect_write( $other )['grant_id'] );
		$urls   = $this->panel_services()->urls();

		$html = $this->admin()->render();
		$this->assertStringContainsString( esc_url( $urls->approval_url( $mine ) ), $html );
		$this->assertStringNotContainsString( esc_url( $urls->approval_url( $theirs ) ), $html, 'not even for a full administrator' );

		wp_set_current_user( $other );
		$html = $this->admin()->render();
		$this->assertStringContainsString( esc_url( $urls->approval_url( $theirs ) ), $html );
		$this->assertStringNotContainsString( esc_url( $urls->approval_url( $mine ) ), $html );
	}

	public function test_no_request_after_consent_shows_host_help() {
		global $wpdb;
		$quiet = $this->connect_write( $this->user_id );
		$used  = $this->connect_write( $this->user_id );
		$fresh = $this->connect_write( $this->user_id );
		$table = Remote_MCP_Install::table( 'grants' );
		$then  = time() - 11 * MINUTE_IN_SECONDS;
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET created_at = %d, last_used_at = %d WHERE id IN (%d, %d)", $then, $then, $quiet['grant_id'], $used['grant_id'] ) );
		$this->clock->now = time();
		$this->assertTrue( $this->store->record_grant_use( $used['grant_id'], '203.0.113.9' ) );
		$this->known->entries[ self::CLIENT_ID ] = array( 'label' => 'Claude' );

		$_SERVER['HTTP_CF_RAY'] = '8c1f0d1e2a3b4c5d-AMS';
		$html                   = $this->admin()->render();

		$help = $this->admin()->no_request_help();
		$this->assertCount( 1, $help, 'only the connection past 10 minutes with no request' );
		$this->assertSame( $quiet['grant_id'], $help[0]['grant_id'] );
		$this->assertStringContainsString( esc_html( "Claude hasn't reached this Dashboard yet" ), $html );
		$this->assertStringContainsString( 'cloudflare', strtolower( $help[0]['help_url'] ) );
		$this->assertStringContainsString( esc_url( $help[0]['help_url'] ), $html );
		$this->assertSame( 1, substr_count( $html, esc_html( "hasn't reached this Dashboard yet" ) ) );
		$this->assertTrue( $this->lists_grant( $html, $fresh['grant_id'] ) );
	}

	public function test_policy_and_scope_changes_audited_owner_only_and_loosening_sends_notice() {
		$connection                              = $this->connect_write( $this->user_id, 'strict' );
		$this->known->entries[ self::CLIENT_ID ] = array( 'label' => 'Claude' );
		$grant_id                                = (string) $connection['grant_id'];

		// Strict to Standard loosens: audited and the owner is told.
		$this->assertTrue( $this->post( 'set_policy', $grant_id, array( 'policy' => 'standard' ) )['ok'] );
		$this->assertSame( 'standard', $this->grant( $connection['grant_id'] )['approval_policy'] );
		$audit = $this->audits_of( 'policy_changed' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'strict', $audit[0]['policy_before'] );
		$this->assertSame( 'standard', $audit[0]['policy_after'] );
		$this->assertSame( $connection['grant_id'], $audit[0]['grant_id'] );
		$this->assertCount( 1, $this->mails(), 'loosening sends the notice' );
		$this->assertSame( get_userdata( $this->user_id )->user_email, $this->mails()[0]['to'][0][0] );

		// Standard to Strict tightens: audited, no notice.
		$this->assertTrue( $this->post( 'set_policy', $grant_id, array( 'policy' => 'strict' ) )['ok'] );
		$this->assertCount( 2, $this->audits_of( 'policy_changed' ) );
		$this->assertCount( 1, $this->mails() );

		// Anything to Hands-off loosens.
		$this->assertTrue( $this->post( 'set_policy', $grant_id, array( 'policy' => 'hands_off' ) )['ok'] );
		$this->assertCount( 2, $this->mails() );

		// An unknown policy changes nothing.
		$this->assertSame( 'invalid', $this->post( 'set_policy', $grant_id, array( 'policy' => 'yolo' ) )['code'] );
		$this->assertSame( 'hands_off', $this->grant( $connection['grant_id'] )['approval_policy'] );

		// Narrowing is audited with the scopes before and after.
		$this->assertTrue( $this->post( 'narrow_scope', $grant_id )['ok'] );
		$narrowed = $this->audits_of( 'scope_narrowed' );
		$this->assertCount( 1, $narrowed );
		$this->assertSame( 'mainwp:read mainwp:write offline_access', $narrowed[0]['scope_before'] );
		$this->assertSame( 'mainwp:read offline_access', $narrowed[0]['scope_after'] );

		// Another user can do neither.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 'not_owner', $this->post( 'set_policy', $grant_id, array( 'policy' => 'standard' ) )['code'] );
		$this->assertSame( 'hands_off', $this->grant( $connection['grant_id'] )['approval_policy'] );
	}

	public function test_panel_actions_fire_audit_events() {
		$this->known->valid_redirects = array( 'https://chat.example.com/oauth/callback' );
		$connection                   = $this->connect_write( $this->user_id, 'strict' );

		$added = $this->post(
			'webapp_add',
			'',
			array(
				'name'          => 'Open WebUI',
				'redirect_uris' => "https://chat.example.com/oauth/callback\n",
			)
		);
		$this->assertTrue( $added['ok'], $added['code'] );
		$client_id = $added['client_id'];
		$this->assertTrue( $this->post( 'webapp_switch', $client_id, array( 'value' => 'off' ) )['ok'] );
		$this->assertTrue( $this->post( 'webapp_reissue', $client_id )['ok'] );
		$this->assertTrue( $this->post( 'webapp_delete', $client_id )['ok'] );
		$this->assertTrue(
			$this->post(
				'mint_token',
				'',
				array(
					'label'  => 'Msty',
					'access' => 'read',
					'policy' => 'standard',
					'expiry' => '30',
				)
			)['ok']
		);
		$this->assertTrue( $this->post( 'set_policy', (string) $connection['grant_id'], array( 'policy' => 'standard' ) )['ok'] );
		$this->assertTrue( $this->post( 'narrow_scope', (string) $connection['grant_id'] )['ok'] );
		$this->assertTrue( $this->post( 'revoke', (string) $connection['grant_id'] )['ok'] );

		$events = array_column( $this->audits, 0 );
		foreach ( array( 'client_added', 'client_changed', 'client_secret_reissued', 'client_deleted', 'connection_token_minted', 'policy_changed', 'scope_narrowed', 'grant_revoked' ) as $event ) {
			$this->assertContains( $event, $events, $event );
			foreach ( $this->audits_of( $event ) as $fields ) {
				$this->assertSame( $this->user_id, $fields['user_id'], $event . ' names the acting user' );
				$this->assertArrayNotHasKey( 'name', $fields );
			}
		}
		$policy = $this->audits_of( 'policy_changed' )[0];
		$this->assertSame( array( 'strict', 'standard' ), array( $policy['policy_before'], $policy['policy_after'] ) );
		$scope = $this->audits_of( 'scope_narrowed' )[0];
		$this->assertSame( array( 'mainwp:read mainwp:write offline_access', 'mainwp:read offline_access' ), array( $scope['scope_before'], $scope['scope_after'] ) );
		$this->assertSame( 'webapp', $this->audits_of( 'client_added' )[0]['client_kind'] );
		$this->assertStringNotContainsString( 'Open WebUI', wp_json_encode( $this->audits ), 'names never reach the log' );

		// Other full administrators see each web app change in the panel; limited users don't.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = $this->admin()->render();
		foreach ( array( 'Web app added: Open WebUI', 'Web app switched on or off: Open WebUI', 'Web app secret reissued: Open WebUI', 'Web app deleted: Open WebUI' ) as $notice ) {
			$this->assertStringContainsString( $notice, $html );
		}
		wp_set_current_user( $this->limited_admin() );
		$this->assertStringNotContainsString( 'Web app added', $this->admin()->render() );
	}

	public function test_policy_or_scope_edit_posted_by_another_user_is_refused() {
		$connection = $this->connect_write( $this->user_id, 'strict' );
		$grant_id   = (string) $connection['grant_id'];
		$before     = $this->grant( $connection['grant_id'] );

		$full    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$limited = $this->limited_admin();
		foreach ( array(
			'full administrator' => $full,
			'limited user'       => $limited,
		) as $who => $user ) {
			wp_set_current_user( $user );
			$policy = $this->post( 'set_policy', $grant_id, array( 'policy' => 'standard' ) );
			$scope  = $this->post( 'narrow_scope', $grant_id );
			$this->assertSame( 'not_owner', $policy['code'], $who );
			$this->assertSame( 'not_owner', $scope['code'], $who );
		}

		$after = $this->grant( $connection['grant_id'] );
		$this->assertSame( $before['approval_policy'], $after['approval_policy'] );
		$this->assertSame( $before['scope'], $after['scope'] );
		$this->assertSame( $before['policy_version'], $after['policy_version'] );

		// Each refusal is counted, with no policy or scope change recorded.
		$refused = array_filter(
			array_merge( $this->audits_of( 'policy_changed' ), $this->audits_of( 'scope_narrowed' ) ),
			function ( $fields ) {
				return 'refused' === ( $fields['outcome'] ?? '' ) && 'not_owner' === ( $fields['reason'] ?? '' );
			}
		);
		$this->assertCount( 4, $refused );
		foreach ( $refused as $fields ) {
			$this->assertArrayNotHasKey( 'policy_after', $fields );
			$this->assertArrayNotHasKey( 'scope_after', $fields );
		}
	}

	public function test_registration_removal_by_limited_user_is_refused() {
		$client = $this->store->create_client(
			'registered',
			array(
				'redirect_uris' => array( 'http://127.0.0.1/callback' ),
				'client_name'   => 'Cline',
			)
		);
		$this->assertSame( OAuth_Store::OK, $client['outcome'] );
		$limited    = $this->limited_admin();
		$connection = $this->connect_write( $this->user_id, 'standard', array( 'client_id' => $client['client_id'] ) );

		wp_set_current_user( $limited );
		$this->assertSame( 'forbidden', $this->post( 'registration_remove', $client['client_id'] )['code'] );
		$this->assertNotNull( $this->store->get_client( $client['client_id'] ) );
		$this->assertSame( 'live', $this->grant( $connection['grant_id'] )['status'] );
		$this->assertStringNotContainsString( 'value="registration_remove"', $this->admin()->render(), 'no remove action offered' );

		wp_set_current_user( $this->user_id );
		$this->assertTrue( $this->post( 'registration_remove', $client['client_id'] )['ok'] );
		$this->assertNull( $this->store->get_client( $client['client_id'] ) );
		$this->assertSame( 'revoked', $this->grant( $connection['grant_id'] )['status'], 'every user loses the shared app' );

		// The action never removes a web app.
		$webapp = $this->store->create_client(
			'webapp',
			array(
				'redirect_uris' => array( 'https://chat.example.com/cb' ),
				'client_name'   => 'Chat',
			)
		);
		$this->assertSame( 'invalid', $this->post( 'registration_remove', $webapp['client_id'] )['code'] );
		$this->assertNotNull( $this->store->get_client( $webapp['client_id'] ) );
	}

	public function test_tool_switch_panel_and_lists_render_from_catalog_rows() {
		$this->catalog->rows = array(
			array(
				'tool_name'     => 'list_sites_v1',
				'ability'       => 'mainwp/list-sites-v1',
				'category'      => 'mainwp-sites',
				'standard_tier' => 'read',
				'availability'  => 'available',
				'reason'        => null,
			),
			array(
				'tool_name'     => 'acme_rebuild_v1',
				'ability'       => 'mainwp/acme-rebuild-v1',
				'category'      => 'acme',
				'standard_tier' => 'dashboard',
				'availability'  => 'not_reviewed',
				'reason'        => 'uncontracted',
			),
			array(
				'tool_name'     => 'updraft_backup_v1',
				'ability'       => 'mainwp/updraft-backup-v1',
				'category'      => 'updraftplus',
				'standard_tier' => null,
				'availability'  => 'not_available_yet',
				'reason'        => 'inert',
			),
			array(
				'tool_name'     => 'broken_thing_v1',
				'ability'       => 'mainwp/broken-thing-v1',
				'category'      => 'broken',
				'standard_tier' => null,
				'availability'  => 'not_available',
				'reason'        => 'invalid_annotations',
			),
		);
		$admin               = $this->admin();
		$lists               = $admin->tool_lists();
		$this->assertSame( array( 'list_sites_v1' ), array_column( $lists['available']['mainwp-sites'], 'tool_name' ) );
		$this->assertSame( array( 'acme_rebuild_v1' ), array_column( $lists['not_reviewed'], 'tool_name' ) );
		$this->assertSame( array( 'updraft_backup_v1' ), array_column( $lists['not_available_yet'], 'tool_name' ) );
		$this->assertSame( array( 'broken_thing_v1' ), array_column( $lists['not_available'], 'tool_name' ) );

		$html = $admin->render();
		$this->assertStringContainsString( 'Not reviewed by MainWP', $html );
		$this->assertStringContainsString( esc_html( "isn't cancelled when a connection is revoked" ), $html );
		$this->assertStringContainsString( 'Not available yet', $html );
		$this->assertStringContainsString( 'Not available to AI apps', $html );
		$this->assertStringContainsString( 'name="target" value="list_sites_v1"', $html );
		$this->assertStringContainsString( 'name="target" value="acme_rebuild_v1"', $html );
		$this->assertStringNotContainsString( 'name="target" value="updraft_backup_v1"', $html, 'no switch for an inert tool' );
		$this->assertStringNotContainsString( 'name="target" value="broken_thing_v1"', $html );

		$this->assertTrue( $this->post( 'tool_switch', 'list_sites_v1', array( 'value' => 'off' ) )['ok'] );
		$this->assertFalse( Remote_MCP_Settings::tool_enabled( 'list_sites_v1' ) );
		$this->assertTrue( $this->post( 'category_switch', 'mainwp-sites', array( 'value' => 'off' ) )['ok'] );
		$this->assertFalse( Remote_MCP_Settings::category_enabled( 'mainwp-sites' ) );
		$this->assertTrue( $this->post( 'unaudited_switch', '', array( 'value' => 'off' ) )['ok'] );
		$this->assertFalse( Remote_MCP_Settings::unaudited_extension_tools_enabled() );

		// Names the catalog doesn't list are never stored.
		$this->assertSame( 'invalid', $this->post( 'tool_switch', 'made_up_tool', array( 'value' => 'off' ) )['code'] );
		$this->assertSame( 'invalid', $this->post( 'category_switch', 'made-up', array( 'value' => 'off' ) )['code'] );
		$stored = get_option( Remote_MCP_Settings::OPTION_TOOL_SWITCHES );
		$this->assertArrayNotHasKey( 'made_up_tool', $stored['tools'] );
		$this->assertArrayNotHasKey( 'made-up', $stored['categories'] );
	}

	public function test_not_reviewed_panel_copy_names_the_hands_off_confirmation() {
		// Under Hands-off the gate drops an unreviewed write to a chat confirmation instead of the
		// Dashboard page (Test_MainWP_MCP_Extension_Contracts pins that), so the panel says both.
		$this->catalog->rows = array(
			array(
				'tool_name'     => 'acme_rebuild_v1',
				'ability'       => 'mainwp/acme-rebuild-v1',
				'category'      => 'acme',
				'standard_tier' => 'dashboard',
				'availability'  => 'not_reviewed',
				'reason'        => 'uncontracted',
			),
		);
		$html = html_entity_decode( $this->admin()->render(), ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( "These extension tools make changes MainWP hasn't reviewed, so each call needs your approval on the Dashboard, or a confirmation in the chat under Hands-off.", $html );
	}
	public function test_first_use_panel_runs_no_module_table_queries() {
		delete_option( Remote_MCP_Settings::OPTION_DB_VERSION );
		$queries = array();
		$count   = function ( $sql ) use ( &$queries ) {
			if ( preg_match( '/mainwp_(?:oauth|remote_mcp)_(?:requests|codes|grants|generations|tokens|approvals|jobs|clients)/', $sql ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		try {
			$html = $this->admin()->render();
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertSame( array(), $queries, 'First-use panel must not query uninstalled module tables.' );
		$this->assertStringContainsString( 'No app has registered.', $html );
		$this->assertStringNotContainsString( 'mwpws_', $html );
	}

	public function test_first_use_store_actions_refuse_with_install_notice() {
		delete_option( Remote_MCP_Settings::OPTION_DB_VERSION );
		foreach ( array( 'mint_token', 'revoke', 'disconnect_mine', 'disconnect_all', 'narrow_scope', 'set_policy', 'webapp_add', 'webapp_delete', 'webapp_reissue', 'webapp_switch', 'registration_remove' ) as $action ) {
			$result = $this->post(
				$action,
				'',
				array(
					'label'  => 'App',
					'access' => 'read',
					'policy' => 'standard',
					'expiry' => '90',
				)
			);
			$this->assertFalse( $result['ok'], $action );
			$this->assertSame( 'not_installed', $result['code'], $action );
			$this->assertStringContainsString( 'Turn on remote AI connections', $result['message'], $action );
		}
	}

	public function test_documented_app_connect_snippet_fields_render() {
		$html = html_entity_decode( $this->admin()->render(), ENT_QUOTES, 'UTF-8' );
		$apps = array(
			'Claude'         => 'URL',
			'Claude Desktop' => 'URL',
			'Claude Code'    => 'claude mcp add',
			'ChatGPT'        => 'URL',
			'VS Code'        => 'servers',
			'Codex CLI'      => 'mcp_servers.mainwp',
			'Zed'            => 'context_servers',
			'Goose'          => 'uri',
			'Cursor'         => 'mainwp-cursor',
			'Gemini CLI'     => 'mainwp-gemini-cli',
			'Qwen Code'      => 'httpUrl',
			'Cline'          => 'streamableHttp',
			'Jan'            => 'url',
			'Continue'       => 'mcpServers',
			'Cherry Studio'  => 'URL',
			'mcp-remote'     => 'npx',
			'LM Studio'      => 'mcpServers',
			'Open WebUI'     => 'Client ID',
			'AnythingLLM'    => 'streamable',
			'5ire'           => 'Authorization',
			'Msty'           => 'Authorization',
			'Roo Code'       => 'streamable-http',
		);
		foreach ( $apps as $app => $field ) {
			$found = preg_match( '#<h4[^>]*>' . preg_quote( $app, '#' ) . '</h4>\s*<pre>(.*?)</pre>#s', $html, $snippet );
			$this->assertSame( 1, $found, $app . ' connect snippet' );
			$this->assertStringContainsString( self::RESOURCE, $snippet[1], $app . ' MCP URL' );
			$this->assertStringContainsString( $field, $snippet[1], $app . ' config field' );
		}
	}

	public function test_panel_categories_share_core_and_extension_order() {
		foreach ( array( 'mainwp-matomo', 'mainwp-backups', 'mainwp-updates', 'mainwp-sites', 'acme-alpha' ) as $category ) {
			$this->catalog->rows[] = array( 'tool_name' => $category, 'ability' => 'mainwp/test-v1', 'category' => $category, 'standard_tier' => 'read', 'availability' => 'available', 'reason' => null );
		}
		$this->assertSame( array( 'mainwp-sites', 'mainwp-updates', 'mainwp-backups', 'acme-alpha', 'mainwp-matomo' ), array_keys( $this->admin()->tool_lists()['available'] ) );
	}

}
