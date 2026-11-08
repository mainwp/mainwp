<?php
/**
 * The remote connector's panel as the "Connect directly" view of the MainWP MCP page.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_AI;
use MainWP\Dashboard\MainWP_MCP;
use MainWP\Dashboard\Remote_MCP\OAuth_Known_Clients;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Admin;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Notices;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Requirements;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_MCP_Page
 */
class Test_MainWP_Remote_MCP_MCP_Page extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures {
		set_up as fixtures_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Request and admin-page globals before the test.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Panel the page renders, over the test store.
	 *
	 * @var Remote_MCP_Admin
	 */
	private $panel;

	public function set_up() {
		$this->saved = array(
			'get'    => $_GET,
			'post'   => $_POST,
			'server' => $_SERVER,
			'screen' => $GLOBALS['current_screen'] ?? null,
			'plugin' => $GLOBALS['plugin_page'] ?? null,
		);
		$this->fixtures_set_up();
		$this->panel               = $this->admin();
		$GLOBALS['current_screen'] = \WP_Screen::get( 'mainwp_page_mainwp-mcp' );
		$GLOBALS['plugin_page']    = 'mainwp-mcp';
		$_GET['page']              = 'mainwp-mcp';
		// The booted module offers its own panel; the test's runs over the test store.
		add_filter( 'mainwp_mcp_direct_view', array( $this, 'offer_test_panel' ), 20 );
	}

	public function tear_down() {
		$_GET                      = $this->saved['get'];
		$_POST                     = $this->saved['post'];
		$_SERVER                   = $this->saved['server'];
		$GLOBALS['current_screen'] = $this->saved['screen'];
		$GLOBALS['plugin_page']    = $this->saved['plugin'];
		parent::tear_down();
	}

	/**
	 * Filter callback: the test panel's direct view.
	 *
	 * @param mixed $view Earlier view.
	 * @return mixed
	 */
	public function offer_test_panel( $view ) {
		return $this->panel->direct_view( $view );
	}

	/**
	 * Render the MainWP MCP page.
	 *
	 * @param string|null $tab Requested tab, or null for none.
	 * @return string
	 */
	private function render_mcp( ?string $tab ): string {
		if ( null === $tab ) {
			unset( $_GET['tab'] );
		} else {
			$_GET['tab'] = $tab;
		}
		ob_start();
		try {
			MainWP_MCP::render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Post one panel action to the page's direct view, as a browser would.
	 *
	 * @param string      $action Action.
	 * @param string      $target Target.
	 * @param array       $fields Extra fields.
	 * @param string|null $nonce  Nonce, or null for a valid one.
	 * @return string Page HTML.
	 */
	private function submit( string $action, string $target = '', array $fields = array(), ?string $nonce = null ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge(
			$fields,
			array(
				'mainwp_remote_mcp_action' => $action,
				'target'                   => $target,
				'_wpnonce'                 => null === $nonce ? wp_create_nonce( Remote_MCP_Admin::nonce_action( $action, $target ) ) : $nonce,
			)
		);
		try {
			return $this->render_mcp( 'direct' );
		} finally {
			$_POST                     = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}
	}

	/**
	 * Use a panel whose Dashboard address is the given home.
	 *
	 * @param string $home Home URL.
	 */
	private function panel_on( string $home ): void {
		$this->panel = new Remote_MCP_Admin(
			new Remote_MCP_Panel_Test_Services(
				array(
					'tool_executor' => $this->catalog,
					'known_clients' => $this->known,
					'enable_check'  => $this->enable_check,
				),
				$this->store,
				new Remote_MCP_Urls( $home )
			)
		);
	}

	public function test_the_api_access_subpage_is_gone() {
		$slugs = array_column( (array) apply_filters( 'mainwp_getsubpages_restapi', array() ), 'slug' );
		$this->assertNotContains( 'RemoteMCP', $slugs, 'API Access no longer lists the panel' );
		$this->assertFalse( method_exists( Remote_MCP_Admin::class, 'add_subpage' ) );
		$this->assertSame( admin_url( 'admin.php?page=mainwp-mcp&tab=direct' ), Remote_MCP_Admin::panel_url() );
	}

	public function test_direct_view_renders_the_panel_for_an_administrator() {
		$html = $this->render_mcp( 'direct' );
		$this->assertStringContainsString( 'id="mainwp-mcp-tabular-menu"', $html );
		$this->assertStringContainsString( 'class="item active" data-tab="direct"', $html );
		$this->assertStringContainsString( esc_url( 'admin.php?page=mainwp-mcp&tab=local' ), $html );
		$this->assertStringContainsString( 'id="mainwp-remote-mcp-panel"', $html );
		$this->assertStringContainsString( 'value="toggle"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( Remote_MCP_Admin::panel_url() ) . '"', $html, 'forms post back to the direct view' );
		$this->assertStringNotContainsString( 'id="mainwp-mcp-steps"', $html, 'the local wizard is its own view' );
		$this->assertStringContainsString( 'Nothing to install.', $html );
		foreach ( OAuth_Known_Clients::REGISTRY as $entry ) {
			$this->assertStringContainsString( esc_html( $entry['label'] ), $html, 'the known apps come from the registry' );
		}
		foreach ( array_keys( OAuth_Known_Clients::STATIC_CLIENTS ) as $client_id ) {
			$this->assertStringContainsString( esc_html( Remote_MCP_Admin::STATIC_CLIENTS[ $client_id ] ), $html );
		}

		$local = $this->render_mcp( 'local' );
		$this->assertStringContainsString( 'class="item active" data-tab="local"', $local );
		$this->assertStringContainsString( 'id="mainwp-mcp-steps"', $local );
		$this->assertStringNotContainsString( 'id="mainwp-remote-mcp-panel"', $local );
		$this->assertStringNotContainsString( 'mainwp_remote_mcp_action', $local );
	}

	public function test_direct_view_refuses_a_user_without_the_panel_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$html = $this->render_mcp( 'direct' );
		$this->assertStringNotContainsString( 'mainwp_remote_mcp_action', $html );
		$this->assertStringNotContainsString( 'id="mainwp-remote-mcp-panel"', $html );

		// Behind the page's own check, the panel still refuses on its own.
		$view = apply_filters( 'mainwp_mcp_direct_view', null );
		$this->assertIsArray( $view );
		ob_start();
		call_user_func( $view['render'] );
		$out = (string) ob_get_clean();
		$this->assertStringContainsString( 'You need the manage_options capability to manage AI apps.', $out );
		$this->assertStringNotContainsString( 'mainwp_remote_mcp_action', $out );

		$this->submit( 'toggle', 'on' );
		$this->assertFalse( Remote_MCP_Settings::enabled(), 'a valid nonce from a user without the capability changes nothing' );
	}

	public function test_panel_actions_work_from_the_new_url() {
		$html = $this->submit( 'toggle', 'on' );
		$this->assertTrue( Remote_MCP_Settings::enabled() );
		$this->assertStringContainsString( 'Remote AI connections are on.', $html );

		$html = $this->submit( 'toggle', 'off', array(), 'not-a-nonce' );
		$this->assertTrue( Remote_MCP_Settings::enabled(), 'a bad nonce changes nothing' );
		$this->assertStringContainsString( 'This form expired. Reload the page and try again.', $html );

		$grant = $this->connect_write( $this->user_id );
		$this->submit( 'set_policy', (string) $grant['grant_id'], array( 'policy' => 'strict' ) );
		$this->assertSame( 'strict', $this->grant( $grant['grant_id'] )['approval_policy'] );

		$this->submit( 'revoke', (string) $grant['grant_id'] );
		$this->assertSame( 'revoked', $this->grant( $grant['grant_id'] )['status'] );

		$this->submit( 'toggle', 'off' );
		$this->assertFalse( Remote_MCP_Settings::enabled() );

		add_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$html = $this->submit( 'toggle', 'on' );
		$this->assertFalse( Remote_MCP_Settings::enabled(), 'the kill switch holds from the new page' );
		$this->assertStringContainsString( esc_html( Remote_MCP_Admin::forced_off_message( 'filter' ) ), $html );
		remove_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
	}

	public function test_default_view_is_direct_when_enabled_or_on_https_and_local_otherwise() {
		$this->assertStringContainsString( 'class="item active" data-tab="direct"', $this->render_mcp( null ), 'HTTPS, connector off' );
		$this->assertStringContainsString( 'class="item active" data-tab="direct"', $this->render_mcp( 'unknown' ), 'an unknown tab falls back to the default' );

		$this->panel_on( 'http://dash.example.org' );
		$this->assertStringContainsString( 'class="item active" data-tab="local"', $this->render_mcp( null ), 'HTTP, connector off' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$this->assertStringContainsString( 'class="item active" data-tab="direct"', $this->render_mcp( null ), 'HTTP, connector on' );
		$this->assertStringContainsString( 'class="item active" data-tab="local"', $this->render_mcp( 'local' ), 'the URL wins over the default' );

		// Where the connector can't run, the local server is the default.
		$platform    = Remote_MCP_Requirements::evaluate( '7.4.33', '7.0', false );
		$this->panel = new class( $this->panel_services(), $platform ) extends Remote_MCP_Admin {
			private $platform;
			public function __construct( $services, array $platform ) {
				parent::__construct( $services );
				$this->platform = $platform;
			}
			protected function requirements(): array {
				return $this->platform;
			}
		};
		$this->assertStringContainsString( 'class="item active" data-tab="local"', $this->render_mcp( null ) );
		$this->assertStringContainsString( 'Remote AI connections need PHP 8.1 or newer', $this->render_mcp( 'direct' ) );
	}

	public function test_without_a_direct_view_the_page_is_the_local_wizard() {
		add_filter( 'mainwp_mcp_direct_view', '__return_null', 30 );
		foreach ( array( null, 'direct' ) as $tab ) {
			$html = $this->render_mcp( $tab );
			$this->assertStringNotContainsString( 'id="mainwp-mcp-tabular-menu"', $html );
			$this->assertStringNotContainsString( 'mainwp_remote_mcp_action', $html );
			$this->assertStringContainsString( 'id="mainwp-mcp-steps"', $html );
		}
		remove_filter( 'mainwp_mcp_direct_view', '__return_null', 30 );
	}

	public function test_local_view_header_matches_whether_a_direct_view_exists() {
		$requirements = 'Needs Node.js 20.19 or later and an Application Password. Works with private Dashboards and any MCP-compatible tool, including Claude, Cursor, OpenAI Codex, and VS Code Copilot.';

		$html = $this->render_mcp( 'local' );
		$this->assertStringContainsString( 'Run the local server on your computer', $html );
		$this->assertStringNotContainsString( 'Manage MainWP with your AI assistant', $html );
		$this->assertStringContainsString( $requirements, $html );
		$this->assertStringContainsString( 'View MainWP MCP on GitHub.', $html );
		$this->assertStringContainsString( 'Dashboard reachable over public HTTPS?', $html );
		$this->assertStringContainsString( 'Connect directly instead, with nothing to install.', $html );
		$this->assertStringNotContainsString( 'The local server runs on your computer', $html );

		add_filter( 'mainwp_mcp_direct_view', '__return_null', 30 );
		$html = $this->render_mcp( null );
		remove_filter( 'mainwp_mcp_direct_view', '__return_null', 30 );
		$this->assertStringContainsString( 'Manage MainWP with your AI assistant', $html );
		$this->assertStringNotContainsString( 'Run the local server on your computer', $html );
		$this->assertStringContainsString( $requirements, $html );
		$this->assertStringNotContainsString( 'Dashboard reachable over public HTTPS?', $html, 'no link to a view that isn\'t there' );
	}

	/**
	 * Store notices for the panel, newest first.
	 *
	 * @param array $notices Notices.
	 */
	private function seed_notices( array $notices ): void {
		update_option( Remote_MCP_Notices::OPTION_STATE, array( 'notices' => $notices ), false );
	}

	/**
	 * One notice as the panel stores it.
	 *
	 * @param int    $grant_id Grant id, 0 for a web app event.
	 * @param string $app      App name.
	 * @param string $why      Why it was recorded.
	 * @return array
	 */
	private function notice( int $grant_id, string $app, string $why = 'new' ): array {
		return array(
			'grant_id' => $grant_id,
			'user_id'  => $this->user_id,
			'app'      => $app,
			'why'      => $why,
			'ip'       => '198.51.100.7',
			'at'       => time(),
		);
	}

	public function test_loosened_policy_notice_is_not_shown_as_a_new_connection() {
		$grant = $this->connect_write( $this->user_id );
		$this->seed_notices( array( $this->notice( (int) $grant['grant_id'], 'Loosened app', 'loosened' ) ) );
		$html = $this->panel->render();
		$this->assertStringContainsString( 'Approval policy loosened: Loosened app, on ', $html );
		$this->assertStringNotContainsString( 'New connection: Loosened app', $html );
	}

	public function test_new_connection_notices_render_as_one_message_with_a_list() {
		$first  = $this->connect_write( $this->user_id );
		$second = $this->connect_write( $this->user_id );
		$this->seed_notices(
			array(
				$this->notice( 0, 'Zapier', 'client_added' ),
				$this->notice( (int) $second['grant_id'], 'Second app' ),
				$this->notice( (int) $first['grant_id'], 'First app' ),
			)
		);

		$html = $this->panel->render();
		$this->assertSame( 1, substr_count( $html, 'class="ui blue message"' ), 'one message for every notice' );
		$this->assertSame( 1, substr_count( $html, '<ul class="list">' ) );
		$this->assertSame( 3, substr_count( $html, '<li>' ) );
		$this->assertSame( 1, substr_count( $html, 'Review connections' ) );
		$this->assertMatchesRegularExpression( '#</ul>\s*<a href="\#connections">Review connections</a>\s*</div>#', $html, 'one link, after the list' );
		$this->assertStringContainsString( 'Web app added: Zapier, on ', $html );
		$this->assertStringContainsString( 'New connection: Second app on ', $html );
		$this->assertStringContainsString( 'id="connections"', $html, 'the link has a target' );

		$this->seed_notices( array() );
		$this->assertStringNotContainsString( 'class="ui blue message"', $this->panel->render(), 'no empty message' );
	}

	public function test_a_notice_for_a_revoked_connection_says_so() {
		$live    = $this->connect_write( $this->user_id );
		$revoked = $this->connect_write( $this->user_id );
		$this->assertTrue( $this->store->revoke_grant( (int) $revoked['grant_id'], 'user' ) );
		$this->seed_notices(
			array(
				$this->notice( (int) $revoked['grant_id'], 'Revoked app' ),
				$this->notice( (int) $live['grant_id'], 'Live app' ),
				$this->notice( 0, 'Zapier', 'client_deleted' ),
			)
		);

		$html = $this->panel->render();
		$this->assertSame( 3, preg_match_all( '#<li>(.*?)</li>#s', $html, $items ) );
		$this->assertStringContainsString( 'Revoked app', $items[1][0] );
		$this->assertStringContainsString( '. (no longer connected)', $items[1][0] );
		$this->assertStringContainsString( 'Live app', $items[1][1] );
		$this->assertStringNotContainsString( '(no longer connected)', $items[1][1] );
		$this->assertStringNotContainsString( '(no longer connected)', $items[1][2], 'a web app event has no connection' );
	}

	public function test_disconnect_buttons_and_hands_off_note_need_a_connection() {
		$hands_off = 'Hands-off lets the app make changes';

		$html = $this->panel->render();
		$this->assertStringContainsString( 'No AI app is connected.', $html );
		$this->assertStringNotContainsString( 'value="disconnect_mine"', $html );
		$this->assertStringNotContainsString( 'value="disconnect_all"', $html );
		$this->assertStringNotContainsString( $hands_off, $html );

		$this->connect_write( $this->user_id );
		$html = $this->panel->render();
		$this->assertStringContainsString( 'value="disconnect_mine"', $html );
		$this->assertStringContainsString( 'value="disconnect_all"', $html );
		$this->assertStringContainsString( $hands_off, $html );
	}

	public function test_token_form_selects_use_the_dropdown_class() {
		$html = $this->panel->render();
		foreach ( array( 'access', 'policy', 'expiry' ) as $name ) {
			$this->assertStringContainsString( '<select id="mainwp-rmcp-token-' . $name . '" name="' . $name . '" class="ui mini dropdown">', $html );
		}
	}

	public function test_ai_page_card_links_to_the_mcp_page_and_names_both_ways() {
		ob_start();
		MainWP_AI::render();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '#<a href="[^"]*admin\.php\?page=mainwp-mcp">MainWP MCP</a>#', $html );
		$this->assertStringContainsString( 'Connect AI apps to your Dashboard directly, with nothing to install, or run the local MCP server for private Dashboards and any MCP-compatible tool.', $html );

		add_filter( 'mainwp_mcp_direct_view', '__return_null', 30 );
		ob_start();
		MainWP_AI::render();
		$html = (string) ob_get_clean();
		remove_filter( 'mainwp_mcp_direct_view', '__return_null', 30 );
		$this->assertStringNotContainsString( 'Dashboard directly', $html, 'no claim the Dashboard can\'t keep' );
		$this->assertStringContainsString( 'Connect Claude, Cursor, OpenAI Codex, VS Code Copilot, and other MCP-compatible AI tools to your Dashboard.', $html );
	}
}
