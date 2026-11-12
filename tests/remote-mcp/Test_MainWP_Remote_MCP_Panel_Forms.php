<?php
/**
 * Every panel action form, by complete payload: what a browser would post from the rendered page,
 * where it lands, and that the handler accepts it.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Revocation;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Admin;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Notices;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Panel_Forms
 */
class Test_MainWP_Remote_MCP_Panel_Forms extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures {
		set_up as fixtures_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Section each action's form returns to; '' for the top of the page.
	 *
	 * @var array
	 */
	const ANCHORS = array(
		'toggle'               => '',
		'dismiss_home_changed' => '',
		'dismiss_notices'      => '',
		'revoke'               => 'connections',
		'disconnect_mine'      => 'connections',
		'disconnect_all'       => 'connections',
		'narrow_scope'         => 'connections',
		'set_policy'           => 'connections',
		'mint_token'           => 'tokens',
		'webapp_add'           => 'apps',
		'webapp_delete'        => 'apps',
		'webapp_reissue'       => 'apps',
		'webapp_switch'        => 'apps',
		'registration_remove'  => 'apps',
		'registration_switch'  => 'apps',
		'static_client_switch' => 'apps',
		'tool_switch'          => 'tools',
		'category_switch'      => 'tools',
		'unaudited_switch'     => 'tools',
		'save_tools'           => 'tools',
		'self_test'            => 'checks',
		'external_check'       => 'checks',
	);

	/**
	 * Fields each action's handler reads beyond action, target and nonce.
	 *
	 * @var array
	 */
	const EXTRA_FIELDS = array(
		'set_policy'           => array( 'policy' ),
		'mint_token'           => array( 'label', 'access', 'policy', 'expiry' ),
		'webapp_add'           => array( 'name', 'redirect_uris' ),
		'webapp_switch'        => array( 'value' ),
		'registration_switch'  => array( 'value' ),
		'static_client_switch' => array( 'value' ),
		'tool_switch'          => array( 'value' ),
		'category_switch'      => array( 'value' ),
		'unaudited_switch'     => array( 'value' ),
		'save_tools'           => array( 'tools', 'categories', 'unaudited', 'tools_settings_complete' ),
		'dismiss_notices'      => array( 'upto' ),
	);

	/**
	 * A second full administrator.
	 *
	 * @var int
	 */
	private $colleague;

	/**
	 * Grants and clients of fixture (a).
	 *
	 * @var array
	 */
	private $made = array();

	public function set_up() {
		$this->fixtures_set_up();
		$this->colleague     = self::factory()->user->create( array( 'role' => 'administrator' ) );
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
				'ability'       => 'acme/rebuild-v1',
				'category'      => 'acme',
				'standard_tier' => 'dashboard',
				'availability'  => 'not_reviewed',
				'reason'        => 'uncontracted',
			),
		);
	}

	/**
	 * Fixture (a): the connector on, a write grant of the current user, another user's grant, a
	 * connection token, a registered app, a web app, the home-changed flag, unreviewed tools and an
	 * unseen notice.
	 */
	private function full_fixture(): void {
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$this->made['notice_at'] = time() - 60;

		$this->made['mine']   = (int) $this->connect_write( $this->user_id )['grant_id'];
		$this->made['theirs'] = (int) $this->connect_write( $this->colleague )['grant_id'];
		$token                = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Billing bot', time() + DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $token['outcome'] );
		$this->made['token']      = (int) $token['grant_id'];
		$registered               = $this->store->create_client(
			'registered',
			array(
				'redirect_uris' => array( 'http://127.0.0.1/callback' ),
				'client_name'   => 'Desk Helper',
			)
		);
		$webapp                   = $this->store->create_client(
			'webapp',
			array(
				'redirect_uris' => array( 'https://chat.example.com/cb' ),
				'client_name'   => 'Chat',
			)
		);
		$this->made['registered'] = (string) $registered['client_id'];
		$this->made['webapp']     = (string) $webapp['client_id'];
		update_option( OAuth_Revocation::OPTION_HOME_CHANGED, time(), false );
		update_option(
			Remote_MCP_Notices::OPTION_STATE,
			array(
				'notices' => array(
					array(
						'grant_id' => $this->made['theirs'],
						'user_id'  => $this->colleague,
						'app'      => 'Claude',
						'why'      => 'new',
						'ip'       => '198.51.100.7',
						'at'       => $this->made['notice_at'],
					),
				),
			),
			false
		);
	}

	/**
	 * Assert every form's payload: the URL and anchor it returns to, exactly the fields its
	 * handler reads, and a nonce that verifies for its action and target.
	 *
	 * @param array $forms From panel_forms().
	 */
	private function assert_payloads( array $forms ): void {
		$this->assertNotEmpty( $forms );
		foreach ( $forms as $form ) {
			$fields = $form['fields'];
			$action = $fields['mainwp_remote_mcp_action'];
			$this->assertArrayHasKey( $action, Remote_MCP_Admin::ACTIONS, 'a known action' );
			$this->assertArrayHasKey( 'target', $fields, $action );
			$this->assertArrayHasKey( '_wpnonce', $fields, $action );
			$expected = array_merge( array( 'mainwp_remote_mcp_action', 'target', '_wpnonce' ), self::EXTRA_FIELDS[ $action ] ?? array() );
			$this->assertEqualsCanonicalizing( $expected, array_keys( $fields ), $action . ' posts exactly the fields its handler reads' );
			$anchor = self::ANCHORS[ $action ];
			$this->assertSame( Remote_MCP_Admin::panel_url() . ( '' === $anchor ? '' : '#mainwp-mcp-message-zone' ), $form['url'], $action . ' returns to the page feedback' );
			$this->assertNotFalse( wp_verify_nonce( $fields['_wpnonce'], Remote_MCP_Admin::nonce_action( $action, $fields['target'] ) ), $action . ' nonce verifies for ' . $fields['target'] );
		}
	}

	/**
	 * Targets of one action's forms.
	 *
	 * @param array  $forms  Forms.
	 * @param string $action Action.
	 * @return array
	 */
	private function targets( array $forms, string $action ): array {
		return array_map(
			function ( $form ) {
				return $form['fields']['target'];
			},
			$this->forms_of( $forms, $action )
		);
	}

	public function test_full_administrator_gets_every_action_with_a_complete_payload() {
		$this->full_fixture();
		$forms = $this->panel_forms( $this->admin()->render() );
		$this->assert_payloads( $forms );

		$actions = array_unique( array_map( fn( $form ) => $form['fields']['mainwp_remote_mcp_action'], $forms ) );
		$visible = array_diff( array_keys( Remote_MCP_Admin::ACTIONS ), array( 'tool_switch', 'category_switch', 'unaudited_switch' ) );
		$this->assertEqualsCanonicalizing( $visible, array_values( $actions ), 'every current action is reachable from the page' );

		$this->assertSame( array( 'off' ), $this->targets( $forms, 'toggle' ) );
		$this->assertEqualsCanonicalizing( array( (string) $this->made['mine'], (string) $this->made['theirs'], (string) $this->made['token'] ), $this->targets( $forms, 'revoke' ) );
		$this->assertSame( array( (string) $this->made['mine'] ), $this->targets( $forms, 'narrow_scope' ), 'only the owner of a write connection narrows it' );
		$this->assertEqualsCanonicalizing( array( (string) $this->made['mine'], (string) $this->made['token'] ), $this->targets( $forms, 'set_policy' ), 'policy is the owner\'s alone' );
		foreach ( $this->forms_of( $forms, 'set_policy' ) as $form ) {
			$this->assertSame( OAuth_Store::POLICIES, $form['options']['policy'] );
		}
		$this->assertSame( array( $this->made['registered'] ), $this->targets( $forms, 'registration_remove' ) );
		foreach ( array( 'webapp_switch', 'webapp_reissue', 'webapp_delete' ) as $action ) {
			$this->assertSame( array( $this->made['webapp'] ), $this->targets( $forms, $action ), $action );
		}
		$this->assertEqualsCanonicalizing( array( 'mainwp-cursor', 'mainwp-gemini-cli' ), $this->targets( $forms, 'static_client_switch' ) );
		$this->assertCount( 1, $this->forms_of( $forms, 'save_tools' ), 'one form saves all tool settings' );
		$tools = $this->forms_of( $forms, 'save_tools' )[0]['fields'];
		$this->assertSame( array( 'list_sites_v1' => 'on', 'acme_rebuild_v1' => 'on' ), $tools['tools'] );
		$this->assertSame( array( 'mainwp-sites' => 'on' ), $tools['categories'] );
		$this->assertSame( (string) $this->made['notice_at'], $this->forms_of( $forms, 'dismiss_notices' )[0]['fields']['upto'], 'the newest event the page shows' );
	}

	public function test_limited_administrator_gets_own_rows_and_no_site_wide_actions() {
		$limited = $this->limited_admin();
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		update_option( OAuth_Revocation::OPTION_HOME_CHANGED, time(), false );
		$theirs = (int) $this->connect_write( $this->user_id )['grant_id'];
		$own    = (int) $this->connect_write( $limited )['grant_id'];

		wp_set_current_user( $limited );
		$html  = $this->admin()->render();
		$forms = $this->panel_forms( $html );
		$this->assert_payloads( $forms );

		$this->assertSame( array( (string) $own ), $this->targets( $forms, 'revoke' ), 'no revoke for another user\'s row' );
		$this->assertStringNotContainsString( 'data-grant="' . $theirs . '"', $html );
		$this->assertSame( array( (string) $own ), $this->targets( $forms, 'narrow_scope' ) );
		$this->assertSame( array( (string) $own ), $this->targets( $forms, 'set_policy' ) );
		$this->assertSame( array( OAuth_Store::POLICY_STANDARD, OAuth_Store::POLICY_STRICT ), $this->forms_of( $forms, 'set_policy' )[0]['options']['policy'] );
		$this->assertSame( array( OAuth_Store::POLICY_STANDARD, OAuth_Store::POLICY_STRICT ), $this->forms_of( $forms, 'mint_token' )[0]['options']['policy'] );
		foreach ( Remote_MCP_Admin::ACTIONS as $action => $level ) {
			if ( 'full' === $level ) {
				$this->assertSame( array(), $this->forms_of( $forms, $action ), $action . ' is not offered' );
			}
		}
		$this->assertSame( array(), $this->forms_of( $forms, 'dismiss_notices' ), 'notices are for full administrators' );
		foreach ( array( 'disconnect_mine', 'mint_token', 'self_test', 'external_check', 'dismiss_home_changed' ) as $action ) {
			$this->assertCount( 1, $this->forms_of( $forms, $action ), $action );
		}
		$this->assertMatchesRegularExpression( '#<input type="checkbox"[^>]*id="mainwp-rmcp-toggle"[^>]*disabled#', $html, 'the toggle shows its state but is disabled' );
	}

	/**
	 * Fixtures (c), (d) and (e): states where no app can connect.
	 *
	 * @return array
	 */
	public static function inactive_states() {
		return array(
			'connector off' => array( 'off' ),
			'forced off'    => array( 'forced' ),
			'http home'     => array( 'http' ),
		);
	}

	/**
	 * Connections, revoke and disconnect stay while the app picker hides.
	 *
	 * @dataProvider inactive_states
	 *
	 * @param string $state State.
	 */
	public function test_connections_stay_manageable_while_no_app_can_connect( $state ) {
		$mine   = (int) $this->connect_write( $this->user_id )['grant_id'];
		$theirs = (int) $this->connect_write( $this->colleague )['grant_id'];
		$admin  = $this->admin();
		if ( 'forced' === $state ) {
			update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
			add_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		} elseif ( 'http' === $state ) {
			update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
			$admin = new Remote_MCP_Admin(
				new Remote_MCP_Panel_Test_Services(
					array(
						'tool_executor' => $this->catalog,
						'known_clients' => $this->known,
						'enable_check'  => $this->enable_check,
					),
					$this->store,
					new Remote_MCP_Urls( 'http://dash.example.org' )
				)
			);
		}
		$html = $admin->render();
		remove_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$forms = $this->panel_forms( $html );
		$this->assert_payloads( $forms );

		$this->assertEqualsCanonicalizing( array( (string) $mine, (string) $theirs ), $this->targets( $forms, 'revoke' ), $state );
		$this->assertSame( array( (string) $mine ), $this->targets( $forms, 'narrow_scope' ), $state );
		$this->assertSame( array( (string) $mine ), $this->targets( $forms, 'set_policy' ), $state );
		$this->assertCount( 1, $this->forms_of( $forms, 'disconnect_mine' ), $state );
		$this->assertCount( 1, $this->forms_of( $forms, 'disconnect_all' ), $state );
		$this->assertStringNotContainsString( 'id="mainwp-rmcp-app-picker"', $html, 'no app picker while no app can connect' );
		$this->assertStringNotContainsString( 'class="ui segment mainwp-rmcp-app"', $html );
		$this->assertStringContainsString( 'id="connect"', $html, 'the connect block says why instead' );
		if ( 'forced' === $state ) {
			$this->assertMatchesRegularExpression( '#<input type="checkbox"[^>]*id="mainwp-rmcp-toggle"[^>]*disabled#', $html );
		}
		if ( 'off' === $state ) {
			$this->assertSame( array( 'on' ), $this->targets( $forms, 'toggle' ) );
			$this->assertStringContainsString( 'Turn on remote AI connections to connect an app.', $html );
		}
	}

	public function test_parsed_payloads_run_through_the_handler() {
		$this->full_fixture();
		$admin = $this->admin();
		$forms = $this->panel_forms( $admin->render() );
		$pick  = function ( string $action, string $target ) use ( $forms ): array {
			foreach ( $this->forms_of( $forms, $action ) as $form ) {
				if ( $target === $form['fields']['target'] ) {
					return $form['fields'];
				}
			}
			$this->fail( $action . ' form for ' . $target );
		};

		$policy           = $pick( 'set_policy', (string) $this->made['mine'] );
		$policy['policy'] = OAuth_Store::POLICY_STRICT;
		$this->assertTrue( $admin->handle_post( $policy )['ok'] );
		$this->assertSame( 'strict', $this->grant( $this->made['mine'] )['approval_policy'] );

		$this->assertTrue( $admin->handle_post( $pick( 'narrow_scope', (string) $this->made['mine'] ) )['ok'] );
		$this->assertNotContains( OAuth_Store::SCOPE_WRITE, OAuth_Store::scope_tokens( $this->grant( $this->made['mine'] )['scope'] ) );

		$this->assertTrue( $admin->handle_post( $pick( 'revoke', (string) $this->made['theirs'] ) )['ok'] );
		$this->assertSame( 'revoked', $this->grant( $this->made['theirs'] )['status'] );

		$tool                          = $pick( 'save_tools', '' );
		$tool['tools']['list_sites_v1'] = 'off';
		$this->assertTrue( $admin->handle_post( $tool )['ok'] );
		$this->assertFalse( Remote_MCP_Settings::tool_enabled( 'list_sites_v1' ) );

		$this->assertTrue( $admin->handle_post( $pick( 'dismiss_notices', '' ) )['ok'] );
		$this->assertSame( $this->made['notice_at'], (int) get_user_meta( $this->user_id, Remote_MCP_Notices::SEEN_META, true ) );
		$this->assertStringNotContainsString( 'id="mainwp-rmcp-notices"', $admin->render(), 'the seen notice is gone' );

		$this->assertTrue( $admin->handle_post( $pick( 'disconnect_mine', '' ) )['ok'] );
		$this->assertSame( 'revoked', $this->grant( $this->made['token'] )['status'], 'the user\'s token ended with their connections' );
	}
}
