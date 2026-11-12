<?php
/**
 * The panel's layout: the status strip, the app picker, the connections table, pending approvals,
 * and one-time secrets and check results inside the section that produced them.
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
 * Class Test_MainWP_Remote_MCP_Panel_Sections
 */
class Test_MainWP_Remote_MCP_Panel_Sections extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures {
		set_up as fixtures_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	const TEST_ABILITY = 'mainwp-panel-test/sync-v1';

	/**
	 * The apps the picker lists, in order: popular ones first, then the rest alphabetically.
	 *
	 * @var array
	 */
	const PICKER_ORDER = array( 'Claude', 'ChatGPT', 'Claude Code', 'Cursor', 'VS Code', 'Codex CLI', 'Gemini CLI', 'Zed', '5ire', 'AnythingLLM', 'Cherry Studio', 'Claude Desktop', 'Cline', 'Continue', 'Goose', 'Jan', 'LM Studio', 'mcp-remote', 'Msty', 'Open WebUI', 'Qwen Code', 'Roo Code', 'Another app' );

	/**
	 * Apps whose configuration carries a connection token.
	 *
	 * @var array
	 */
	const TOKEN_APPS = array( 'AnythingLLM', '5ire', 'Msty', 'Roo Code' );

	public function set_up() {
		$this->fixtures_set_up();
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
	}

	public function tear_down() {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::TEST_ABILITY ) ) {
			wp_unregister_ability( self::TEST_ABILITY );
		}
		parent::tear_down();
	}

	/**
	 * The outer HTML of the single element an XPath query finds, entities decoded.
	 *
	 * @param string $html  HTML.
	 * @param string $query XPath query.
	 * @return string
	 */
	private function element( string $html, string $query ): string {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$nodes = ( new \DOMXPath( $dom ) )->query( $query );
		$this->assertSame( 1, $nodes->length, $query );
		return html_entity_decode( $dom->saveHTML( $nodes->item( 0 ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * One app's segment in the picker.
	 *
	 * @param string $html Panel HTML.
	 * @param string $app  App label.
	 * @return string Segment HTML.
	 */
	private function app_segment( string $html, string $app ): string {
		return $this->element( $html, '//div[@data-app="' . sanitize_title( $app ) . '"]' );
	}

	/**
	 * A pending approval with a stored summary.
	 *
	 * @param int    $grant_id Grant.
	 * @param string $ability  Ability.
	 * @param string $summary  Summary column.
	 * @return int
	 */
	private function approval( int $grant_id, string $ability, string $summary ): int {
		$approval = $this->store->create_approval(
			array(
				'grant_id'    => $grant_id,
				'ability'     => $ability,
				'tier'        => 'dashboard',
				'args_digest' => str_repeat( 'b', 64 ),
				'summary'     => $summary,
			)
		);
		$this->assertSame( OAuth_Store::OK, $approval['outcome'] );
		return (int) $approval['approval_id'];
	}

	public function test_pending_approvals_render_as_sentences_never_json() {
		\WP_Abilities_Registry::get_instance()->register(
			self::TEST_ABILITY,
			array(
				'label'               => 'Sync Test Sites',
				'description'         => 'Test ability.',
				'category'            => 'mainwp-sites',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
			)
		);
		$this->known->entries[ self::CLIENT_ID ] = array( 'label' => 'Claude Code' );
		$grant                                   = (int) $this->connect_write( $this->user_id )['grant_id'];
		$sites                                   = array(
			array(
				'id'   => 1,
				'name' => 'Sunny Side Bakery',
				'url'  => 'https://bakery.example.org/',
			),
			array(
				'id'   => 2,
				'name' => 'Second Site',
				'url'  => 'https://second.example.org/',
			),
		);
		$this->approval(
			$grant,
			self::TEST_ABILITY,
			wp_json_encode(
				array(
					'ability'    => self::TEST_ABILITY,
					'sites'      => $sites,
					'site_count' => 60,
				)
			)
		);
		$this->approval(
			$grant,
			self::TEST_ABILITY,
			wp_json_encode(
				array(
					'ability'    => self::TEST_ABILITY,
					'sites'      => array( $sites[0] ),
					'site_count' => 1,
				)
			)
		);
		$this->approval(
			$grant,
			'mainwp/not-registered-v1',
			wp_json_encode(
				array(
					'ability'    => 'mainwp/not-registered-v1',
					'sites'      => array(),
					'site_count' => 0,
				)
			)
		);
		$this->approval( $grant, self::TEST_ABILITY, '{"ability":' );
		$other = (int) $this->connect_write( $this->user_id )['grant_id'];
		$gone  = $this->approval( $other, self::TEST_ABILITY, wp_json_encode( array( 'sites' => array() ) ) );
		global $wpdb;
		$wpdb->delete( Remote_MCP_Install::table( 'grants' ), array( 'id' => $other ) );

		$html     = $this->admin()->render();
		$approval = $this->panel_section( $html, 'approvals' );
		$this->assertStringNotContainsString( '{&quot;', $html );
		$this->assertStringNotContainsString( '{"ability"', $html );
		$this->assertStringContainsString( 'class="ui yellow message" id="approvals"', $html );
		$this->assertStringContainsString( '5 actions are waiting for your approval', $approval );
		$this->assertStringContainsString( 'Sync Test Sites on Sunny Side Bakery and 59 more sites. Asked by Claude Code, ', $approval, 'site_count drives the rest' );
		$this->assertStringContainsString( 'Sync Test Sites on Sunny Side Bakery. Asked by Claude Code, ', $approval );
		$this->assertStringContainsString( 'mainwp/not-registered-v1. Asked by Claude Code, ', $approval, 'an unknown ability shows its name' );
		$this->assertStringContainsString( 'An action. Asked by Claude Code, ', $approval, 'an undecodable summary' );
		$this->assertStringContainsString( 'Sync Test Sites. Asked by An AI app, ', $approval, 'a missing grant' );
		$this->assertSame( 5, substr_count( $approval, '>Review</a>' ) );
		$this->assertStringContainsString( esc_url( $this->panel_services()->urls()->approval_url( $gone ) ), $approval );
		$this->assertMatchesRegularExpression( '#id="approvals".*id="connections"#s', $html, 'pending approvals appear before the connections section' );
	}

	public function test_no_pending_approvals_render_nothing() {
		$this->connect_write( $this->user_id );
		$html = $this->admin()->render();
		$this->assertStringNotContainsString( 'id="approvals"', $html );
		$this->assertStringNotContainsString( 'waiting for your approval', $html );
	}

	public function test_app_picker_lists_every_app_with_its_steps() {
		Remote_MCP_Settings::set_registration_enabled( true );
		$html = $this->admin()->render();
		$this->assertSame( 1, preg_match( '#<select id="mainwp-rmcp-app-picker"[^>]*>(.*?)</select>#s', $html, $select ) );
		preg_match_all( '#<option value="([^"]*)"( selected)?>([^<]*)</option>#', $select[1], $options );
		$this->assertSame( self::PICKER_ORDER, $options[3] );
		$this->assertSame( array_map( 'sanitize_title', self::PICKER_ORDER ), $options[1] );
		$this->assertSame( ' selected', $options[2][0], 'Claude is the default' );
		$this->assertSame( count( self::PICKER_ORDER ), substr_count( $html, 'class="ui segment mainwp-rmcp-app"' ) );

		foreach ( self::PICKER_ORDER as $app ) {
			$segment = $this->app_segment( $html, $app );
			$this->assertStringContainsString( self::RESOURCE, $segment, $app . ' MCP address' );
			$hidden = str_contains( substr( $segment, 0, strpos( $segment, '>' ) ), 'hidden' );
			$this->assertSame( 'Claude' !== $app, $hidden, $app . ': only the default shows' );
			preg_match_all( '#<div class="item">\s*<div class="image"><span class="ui circular label">(\d)</span></div>\s*<div class="content">#', $segment, $steps );
			$this->assertSame( array( '1', '2', '3' ), $steps[1], $app . ': each number sits in the cell beside its step' );
			if ( in_array( $app, self::TOKEN_APPS, true ) ) {
				$this->assertStringContainsString( 'Create a connection token under <a href="#tokens">Connection tokens</a>.', $segment, $app . ' token steps' );
				$this->assertStringContainsString( 'Bearer mwpct_...', $segment, $app . ' placeholder block' );
				$this->assertStringNotContainsString( 'Approve the request that opens in your browser.', $segment, $app );
				$last = array(
					'AnythingLLM' => 'Add it to anythingllm_mcp_servers.json',
					'Msty'        => 'In Msty, go to Toolbox > Tools > Add New Tool',
				);
				$this->assertStringContainsString( $last[ $app ] ?? 'Paste it into the MCP settings in ' . $app . '.', $segment, $app . ': where the block goes' );
			} else {
				$this->assertStringContainsString( 'Approve the request that opens in your browser. The app then shows under Connected apps.', $segment, $app . ' sign-in steps' );
			}
		}
		foreach ( array( 'Claude', 'Claude Desktop', 'ChatGPT' ) as $app ) {
			$segment = $this->app_segment( $html, $app );
			$this->assertStringContainsString( 'Copy this address', $segment );
			$this->assertMatchesRegularExpression( '#<pre id="[^"]+">' . preg_quote( self::RESOURCE, '#' ) . '</pre>#', $segment, $app . ': the address alone' );
		}
		$this->assertStringContainsString( 'Add it to the app as a remote MCP server and sign in when it asks.', $this->app_segment( $html, 'Another app' ) );
		$jan = $this->app_segment( $html, 'Jan' );
		$this->assertStringContainsString( "Name: MainWP\nTransport: HTTP\nURL: " . self::RESOURCE, $jan, 'Jan takes a form, not JSON' );
		$this->assertStringContainsString( 'then choose Sign in and use your Dashboard account.', $jan );
		$this->assertStringContainsString( 'Authentication: OAuth 2.1 (Static)', $this->app_segment( $html, 'Open WebUI' ), 'the option as Open WebUI names it' );

		Remote_MCP_Settings::set_registration_enabled( false );
		$another = $this->app_segment( $this->admin()->render(), 'Another app' );
		$this->assertStringContainsString( 'Turn on <strong>Let other apps connect</strong> under <a href="#apps">Which apps may connect</a> first.', $another );
		$this->assertStringNotContainsString( 'Approve the request that opens in your browser.', $another );
	}

	public function test_connections_table_columns_status_and_unknown_apps() {
		$this->clock->now = time() - HOUR_IN_SECONDS;
		$active           = (int) $this->connect_write( $this->user_id )['grant_id'];
		$unknown          = (int) $this->connect_write(
			$this->user_id,
			'standard',
			array(
				'client_id'   => 'https://apps.example.net/mcp-client.json',
				'client_host' => 'apps.example.net',
			)
		)['grant_id'];
		$failing          = (int) $this->connect_write( $this->user_id )['grant_id'];
		$client           = $this->store->create_client(
			'registered',
			array(
				'redirect_uris' => array( 'http://127.0.0.1/callback' ),
				'client_name'   => 'Desk Helper',
			)
		);
		$registered       = (int) $this->connect_write( $this->user_id, 'standard', array( 'client_id' => $client['client_id'] ) )['grant_id'];
		$this->clock->now = time();
		$this->assertTrue( $this->store->record_grant_use( $active, '203.0.113.9' ) );
		$this->assertTrue( $this->store->record_grant_use( $failing, '203.0.113.10' ) );
		$this->assertTrue( $this->store->record_grant_failure( $failing, 'invalid_token' ) );
		$this->known->entries[ self::CLIENT_ID ] = array( 'label' => 'Claude' );
		$_GET['revoke']                          = (string) $active;

		$html = $this->admin()->render();
		unset( $_GET['revoke'] );
		$section = $this->panel_section( $html, 'connections' );
		$this->assertStringContainsString( 'Connected apps (4)', $section );
		$this->assertMatchesRegularExpression( '#<div style="overflow-x:auto;?">\s*<table class="ui unstackable table" id="mainwp-rmcp-connections">#', $section, 'scrolls sideways on a phone' );
		$this->assertStringNotContainsString( 'single line', $section, 'cells wrap, so the table fits a desktop container' );
		preg_match_all( '#<th[^>]*>(.*?)</th>#s', $section, $headers );
		$this->assertSame( array( 'App', 'User', 'Access', 'Approval policy', 'Last used', 'Status', '' ), array_map( fn( $th ) => trim( wp_strip_all_tags( $th ) ), $headers[1] ) );
		$this->assertStringNotContainsString( 'Save policy', $section, 'the policy saves on change' );
		$this->assertStringContainsString( 'mainwp-rmcp-submit-on-change', $section );

		$row = function ( int $grant_id ) use ( $section ): string {
			$this->assertSame( 1, preg_match( '#<tr data-grant="' . $grant_id . '"[^>]*>(.*?)</tr>#s', $section, $match ), 'row ' . $grant_id );
			return $match[0];
		};
		$this->assertStringContainsString( 'class="warning"', $row( $active ), 'the email link highlights its row' );
		$this->assertStringContainsString( '>Active</span>', $row( $active ) );
		$this->assertStringContainsString( '203.0.113.9', $row( $active ), 'the last address is in the tooltip' );
		$this->assertStringContainsString( '>Failing</span>', $row( $failing ) );
		$this->assertStringContainsString( 'invalid_token', $row( $failing ) );
		$this->assertStringContainsString( '>No requests yet</span>', $row( $unknown ) );
		$this->assertStringContainsString( 'Not yet', $row( $unknown ) );
		$this->assertStringContainsString( '<span style="overflow-wrap:anywhere;">apps.example.net</span>', $row( $unknown ), 'a long host may break' );
		$this->assertMatchesRegularExpression( '#<small class="ui grey text">connected [^<]*</small>\s*<span class="ui mini label"[^>]*>not checked by MainWP</span>#', $row( $unknown ), 'the chip sits on the muted line, not beside the name' );
		$this->assertStringNotContainsString( 'not checked by MainWP', $row( $active ), 'a registry app' );
		$this->assertStringContainsString( 'Desk Helper', $row( $registered ) );
		$this->assertMatchesRegularExpression( '#<small class="ui grey text">connected [^<]*</small>\s*<span class="ui mini label"[^>]*>name from the app</span>#', $row( $registered ) );
		$this->assertStringContainsString( esc_attr( "This app named itself when it registered. MainWP hasn't checked the name." ), $row( $registered ) );
		$this->assertStringContainsString( 'View and change', $row( $active ) );
		$this->assertStringContainsString( '>View only</button>', $row( $active ), 'the owner can narrow a write connection' );
		$this->assertStringContainsString( '>Revoke</button>', $row( $active ) );
		foreach ( array( $active, $unknown, $failing, $registered ) as $grant_id ) {
			$this->assertMatchesRegularExpression( '#<td class="right aligned collapsing" style="white-space:nowrap;">.*>Revoke</button>#s', $row( $grant_id ), 'View only and Revoke stay on one line' );
		}

		$this->assertMatchesRegularExpression( '#<div class="ui mini basic dropdown button not-auto-init" id="mainwp-rmcp-disconnect"#', $section, 'neutral in every theme; the confirm dialogs carry the danger' );
		$this->assertStringNotContainsString( 'red dropdown', $section );
		$this->assertStringContainsString( 'Disconnect…', $section );
		$this->assertStringContainsString( 'My connections', $section );
		$this->assertStringContainsString( 'Everyone&#039;s connections', $section );
	}

	public function test_approval_policy_help_matches_the_tier_rules() {
		$this->connect_write( $this->user_id );
		$section = html_entity_decode( $this->panel_section( $this->admin()->render(), 'connections' ), ENT_QUOTES, 'UTF-8' );
		$this->assertSame( 1, preg_match( '#<th data-tooltip="([^"]*)"[^>]*>\s*Approval policy#', $section, $tooltip ) );
		foreach ( array(
			'Standard: ordinary changes run right away',
			'the riskiest wait for your approval on this Dashboard',
			'Strict: every change waits for your approval on this Dashboard',
			'Hands-off:',
			'confirmed in the chat',
			'adding sites',
			'restoring or deleting backups',
			'changing backup retention',
			'a site\'s URL or SSL settings',
			'replacing the custom dashboard snippet',
			'always wait for your approval here',
			'Instructions hidden in a site\'s content',
		) as $fact ) {
			$this->assertStringContainsString( $fact, $tooltip[1] );
		}
		$this->assertStringNotContainsString( 'never', $tooltip[1] );
	}

	public function test_status_strip_toggle_confirms_before_turning_off() {
		$html  = $this->admin()->render();
		$strip = substr( $html, 0, strpos( $html, 'id="connect"' ) );
		$this->assertSame( 1, preg_match( '#<form[^>]*id="mainwp-rmcp-toggle-form"[^>]*>(.*?)</form>#s', $strip, $form ) );
		$this->assertStringContainsString( 'data-confirm="#mainwp-rmcp-confirm-toggle-off"', $form[0] );
		$this->assertMatchesRegularExpression( '#<div class="ui toggle checkbox[^"]*">\s*<input type="checkbox" id="mainwp-rmcp-toggle" checked>\s*<label for="mainwp-rmcp-toggle">Apps you approve can reach this Dashboard over the internet\.</label>#', $form[1] );
		$this->assertStringContainsString( 'Apps you approve can reach this Dashboard over the internet.', $strip );
		$this->assertStringNotContainsString( 'Turn off and end every connection', $html );
		$this->assertStringNotContainsString( 'value="self_test"', $strip, 'the checks are not in the strip' );
		$modal = $this->element( $html, '//div[@id="mainwp-rmcp-confirm-toggle-off"]' );
		$this->assertStringContainsString( 'class="ui mini modal mainwp-rmcp-confirm"', $modal );
		$this->assertStringContainsString( 'This ends every AI connection on this Dashboard. Apps will have to be approved again.', $modal );
		$this->assertMatchesRegularExpression( '#class="ui red approve button">Turn off<#', $modal );
		$this->assertMatchesRegularExpression( '#class="ui cancel button">Cancel<#', $modal );
		foreach ( array(
			'mine' => 'This ends every AI app connection you made on this Dashboard.',
			'all'  => 'This ends every AI connection on this Dashboard, for every user.',
		) as $which => $text ) {
			$this->connect_write( $this->user_id );
			$this->assertStringContainsString( $text, $this->element( $this->admin()->render(), '//div[@id="mainwp-rmcp-confirm-disconnect-' . $which . '"]' ) );
		}

		delete_option( Remote_MCP_Settings::OPTION_ENABLED );
		$html = $this->admin()->render();
		$this->assertSame( 1, preg_match( '#<form[^>]*id="mainwp-rmcp-toggle-form"[^>]*>#', $html, $open ) );
		$this->assertStringNotContainsString( 'data-confirm', $open[0], 'turning on submits directly' );
		$this->assertMatchesRegularExpression( '#<input type="checkbox" id="mainwp-rmcp-toggle">#', $html );
		$this->assertStringContainsString( 'Off. No AI app can reach this Dashboard over the internet.', $html );
	}

	public function test_check_results_render_in_the_checks_section() {
		$result = array(
			'action'  => 'self_test',
			'ok'      => true,
			'code'    => 'ok',
			'message' => '',
			'lines'   => array(
				array(
					'status'  => 'pass',
					'message' => 'The activity log is on, so every AI app call is recorded.',
				),
			),
		);
		$html   = $this->admin()->render( $result );
		$checks = $this->panel_section( $html, 'checks' );
		$this->assertStringContainsString( 'value="self_test"', $checks );
		$this->assertStringContainsString( 'value="external_check"', $checks );
		$this->assertStringContainsString( esc_html( "The internet check sends only this Dashboard's address to MainWP's checker. Nothing is stored." ), $checks );
		$this->assertStringContainsString( 'Checks run at ', $checks );
		$this->assertStringContainsString( esc_html( $result['lines'][0]['message'] ), $checks );
		$this->assertSame( 1, substr_count( $html, esc_html( $result['lines'][0]['message'] ) ), 'only in the section' );
	}

	public function test_new_token_renders_once_in_a_named_modal() {
		$result = $this->post(
			'mint_token',
			'',
			array(
				'label'  => 'Billing bot',
				'access' => 'read',
				'policy' => 'standard',
				'expiry' => '30',
			)
		);
		$this->assertTrue( $result['ok'], $result['code'] );
		$html   = $this->admin()->render( $result );
		$tokens = $this->element( $html, '//div[@id="mainwp-rmcp-token-modal"]' );
		$this->assertStringContainsString( 'id="mainwp-rmcp-token-modal-title">Billing bot</div>', $tokens );
		$this->assertStringContainsString( 'data-return-url="' . Remote_MCP_Admin::panel_url() . '#tokens"', $tokens );
		$this->assertStringNotContainsString( $result['secret'], $this->panel_section( $html, 'tokens' ), 'the secret is in the modal, not the token form' );
		$this->assertStringContainsString( '>' . $result['secret'] . '</pre>', $tokens, 'the token alone' );
		$this->assertStringContainsString( 'Authorization: Bearer ' . $result['secret'], $tokens );
		$before = substr( $html, 0, strpos( $html, 'id="tokens"' ) );
		$this->assertStringNotContainsString( $result['secret'], $before, 'nowhere above its section' );
		$this->assertStringContainsString( 'Copy the token now.', $before, 'the one-line result stays at the top' );

		$this->assertMatchesRegularExpression( '#<pre id="mainwp-rmcp-secret-header">Authorization: Bearer ' . preg_quote( $result['secret'], '#' ) . '</pre>\s*</div>\s*<button[^>]*data-clipboard-target="\#mainwp-rmcp-secret-header"#', $tokens, 'the header line has its own copy button' );
		$this->assertStringContainsString( 'Paste this header into your app, or pick the app below for a ready-made block.', $tokens );
		$this->assertSame( 1, preg_match( '#<select id="mainwp-rmcp-token-picker"[^>]*data-rmcp-picker="mainwp-rmcp-token-app"[^>]*>(.*?)</select>#s', $tokens, $select ) );
		preg_match_all( '#<option value="([^"]*)"[^>]*>([^<]*)</option>#', $select[1], $options );
		$apps = array( 'AnythingLLM', 'Continue', 'Jan', 'LM Studio', '5ire', 'Msty', 'Roo Code' );
		$this->assertEqualsCanonicalizing( array_merge( array( 'Pick your app' ), $apps ), $options[2], 'only the apps that take a token' );
		$this->assertSame( '', $options[1][0], 'no app is picked at first' );
		foreach ( $apps as $app ) {
			$block = $this->element( $html, '//div[@class="mainwp-rmcp-token-app"][@data-app="' . sanitize_title( $app ) . '"]' );
			$this->assertStringContainsString( ' hidden', substr( $block, 0, strpos( $block, '>' ) ), $app . ' stays hidden until picked' );
			$this->assertStringContainsString( 'Bearer ' . $result['secret'], $block, $app );
		}
		$continue = $this->element( $html, '//div[@data-app="continue"][@class="mainwp-rmcp-token-app"]' );
		$this->assertStringContainsString( "    requestOptions:\n      headers:\n        Authorization: Bearer " . $result['secret'], $continue );
		$this->assertStringNotContainsString( 'id="mainwp-rmcp-token-modal"', $this->admin()->render(), 'a fresh GET has no secret modal' );
	}

	public function test_view_only_requires_a_confirmation_that_explains_the_permanent_change() {
		$this->connect_write( $this->user_id );
		$html = $this->admin()->render();
		$form = $this->element( $html, '//form[.//input[@name="mainwp_remote_mcp_action"][@value="narrow_scope"]]' );
		$this->assertStringContainsString( 'data-confirm="#mainwp-rmcp-confirm-narrow_scope"', $form );
		$modal = $this->element( $html, '//div[@id="mainwp-rmcp-confirm-narrow_scope"]' );
		$this->assertStringContainsString( 'You cannot switch it back to View and change.', $modal );
		$this->assertStringContainsString( 'create or approve a new connection', $modal );
		$this->assertStringContainsString( 'ui cancel button', $modal );
	}

	public function test_advanced_tabs_show_summaries_and_select_the_tab_for_an_action() {
		Remote_MCP_Settings::set_registration_enabled( false );
		$this->catalog->rows = array(
			array(
				'tool_name'    => 'list_sites_v1',
				'ability'      => 'mainwp/list-sites-v1',
				'category'     => 'mainwp-sites',
				'availability' => 'available',
			),
			array(
				'tool_name'    => 'acme_rebuild_v1',
				'ability'      => 'acme/rebuild-v1',
				'category'     => 'acme',
				'availability' => 'not_reviewed',
			),
		);
		$html                = $this->admin()->render();
		$this->assertStringContainsString( 'class="ui basic accordion mainwp-blank-accordion mainwp-rmcp-section" id="mainwp-rmcp-advanced-accordion"', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $this->element( $html, '//h3[@id="mainwp-rmcp-advanced-title"]' ) );
		$this->assertStringContainsString( '<div class="ui secondary pointing fluid menu" id="mainwp-rmcp-advanced-menu" role="tablist"', $html );
		$summaries = array(
			'tokens' => array( 'Connection Tokens', "For apps that can't sign in. You have 0 tokens." ),
			'apps'   => array( 'Allowed Apps', 'Cursor on, Gemini CLI on, other apps off' ),
			'tools'  => array( 'Allowed Tools', '2 of 2 on. 1 not reviewed by MainWP.' ),
			'checks' => array( 'Connection Checks', 'Run the self-test or check from the internet.' ),
		);
		foreach ( $summaries as $id => list( $title, $summary ) ) {
			$tab     = $this->element( $html, '//a[@id="mainwp-rmcp-tab-' . $id . '"]' );
			$section = $this->element( $html, '//div[@id="' . $id . '"][@role="tabpanel"]' );
			$this->assertStringContainsString( 'aria-selected="' . ( 'tokens' === $id ? 'true' : 'false' ) . '"', $tab, $id );
			$this->assertStringContainsString( 'aria-controls="' . $id . '"', $tab );
			$this->assertStringContainsString( $title, $tab );
			$this->assertStringContainsString( $summary, $section );
		}
		$this->assertMatchesRegularExpression( '#id="tokens".*id="apps".*id="tools".*id="checks"#s', $html );

		$opened = array(
			'tools'  => array( 'action' => 'save_tools' ),
			'apps'   => array( 'action' => 'static_client_switch' ),
			'tokens' => array( 'action' => 'mint_token' ),
			'checks' => array(
				'action'  => 'toggle',
				'reasons' => array( 'The activity log is off.' ),
			),
		);
		foreach ( $opened as $id => $result ) {
			$html = $this->admin()->render(
				$result + array(
					'ok'      => false,
					'message' => 'x',
				)
			);
			$this->assertSame( 1, substr_count( $html, 'class="ui tab active"' ), $id );
			$this->assertStringStartsWith( '<div class="ui tab active" id="' . $id . '"', $this->element( $html, '//div[@id="' . $id . '"]' ), $id );
			$this->assertStringContainsString( 'aria-selected="true"', $this->element( $html, '//a[@id="mainwp-rmcp-tab-' . $id . '"]' ), $id );
			$this->assertStringContainsString( 'aria-expanded="true"', $this->element( $html, '//h3[@id="mainwp-rmcp-advanced-title"]' ), $id );
		}
		$this->assertStringContainsString(
			'Just ran. 1 check failed.',
			$this->admin()->render(
				array(
					'action'  => 'self_test',
					'ok'      => true,
					'message' => '',
					'lines'   => array(
						array(
							'status'  => 'fail',
							'message' => 'Nope.',
						),
					),
				)
			)
		);
	}

	public function test_tools_show_labels_counts_and_switches() {
		\WP_Abilities_Registry::get_instance()->register(
			self::TEST_ABILITY,
			array(
				'label'               => 'Sync Test Sites',
				'description'         => 'Test ability.',
				'category'            => 'mainwp-sites',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
			)
		);
		$this->catalog->rows = array(
			array(
				'tool_name'    => 'sync_test_sites_v1',
				'ability'      => self::TEST_ABILITY,
				'category'     => 'mainwp-sites',
				'availability' => 'available',
			),
			array(
				'tool_name'    => 'list_widgets_v1',
				'ability'      => 'acme/list-widgets-v1',
				'category'     => 'mainwp-sites',
				'availability' => 'available',
			),
			array(
				'tool_name'    => 'get_remote_job_status_v1',
				'ability'      => null,
				'category'     => 'mainwp-remote-mcp',
				'availability' => 'available',
			),
			array(
				'tool_name'    => 'vault_v1',
				'ability'      => 'acme/vault-v1',
				'category'     => 'acme',
				'availability' => 'not_available',
			),
		);
		Remote_MCP_Settings::set_tool_enabled( 'list_widgets_v1', false );
		$tools = $this->panel_section( $this->admin()->render(), 'tools' );

		$this->assertStringContainsString( '<div class="ui styled fluid accordion" id="mainwp-rmcp-tool-categories">', $tools );
		$this->assertStringContainsString( '2 tools, 1 off', $tools );
		foreach ( array( 'Sync Test Sites', 'List widgets', 'Get remote job status', 'Never available to AI apps', 'vault_v1' ) as $label ) {
			$this->assertStringContainsString( $label, $tools );
		}
		$this->assertStringNotContainsString( '<table', $tools );
		$forms = $this->panel_forms( $tools );
		$this->assertCount( 1, $forms );
		$this->assertSame( 'save_tools', $forms[0]['fields']['mainwp_remote_mcp_action'] );
		$this->assertSame( 'off', $forms[0]['fields']['tools']['list_widgets_v1'] );
		$this->assertSame( 'on', $forms[0]['fields']['tools']['sync_test_sites_v1'] );
		$this->assertSame( array( 'mainwp-sites' => 'on', 'mainwp-remote-mcp' => 'on' ), $forms[0]['fields']['categories'] );
		$this->assertStringContainsString( '>Save Settings</button>', $tools );
		$this->assertStringNotContainsString( 'mainwp-rmcp-switch', $tools, 'no control submits on change' );

	}

	public function test_which_apps_may_connect_groups_and_web_app_row() {
		$webapp = $this->store->create_client(
			'webapp',
			array(
				'redirect_uris' => array( 'https://chat.example.com/cb' ),
				'client_name'   => 'Chat',
			)
		);
		$apps   = $this->panel_section( $this->admin()->render(), 'apps' );
		$this->assertMatchesRegularExpression( '#Known apps</h4>\s*<div class="ui form">.*Cursor.*Gemini CLI.*Let other apps connect#s', $apps );
		$this->assertMatchesRegularExpression( '#Let other apps connect.*Apps that registered themselves.*Web apps you added#s', $apps );
		$this->assertStringNotContainsString( '<table', $apps );
		$row = $this->element( $apps, '//div[@id="mainwp-rmcp-webapps"]/div' );
		$this->assertStringContainsString( 'class="six wide column">Chat</label>', $row );
		$this->assertStringContainsString( $webapp['client_id'], $row );
		$this->assertStringContainsString( 'https://chat.example.com/cb', $row );
		$this->assertMatchesRegularExpression( '#mainwp-rmcp-app-actions.*webapp_switch.*>New secret<.*>Delete<#s', $row );

	}

	public function test_web_app_secret_renders_inside_which_apps_may_connect() {
		$this->known->valid_redirects = array( 'https://chat.example.com/oauth/callback' );
		$added                        = $this->post(
			'webapp_add',
			'',
			array(
				'name'          => 'Open WebUI',
				'redirect_uris' => 'https://chat.example.com/oauth/callback',
			)
		);
		$this->assertTrue( $added['ok'], $added['code'] );
		$reissued = $this->post( 'webapp_reissue', $added['client_id'] );
		$this->assertTrue( $reissued['ok'], $reissued['code'] );
		foreach ( array(
			'added'    => $added,
			'reissued' => $reissued,
		) as $label => $result ) {
			$html = $this->admin()->render( $result );
			$apps = $this->panel_section( $html, 'apps' );
			$this->assertStringContainsString( $result['secret'], $apps, $label );
			$this->assertStringContainsString( $result['client_id'], $apps, $label );
			$this->assertStringNotContainsString( $result['secret'], substr( $html, 0, strpos( $html, 'id="apps"' ) ), $label );
			$this->assertSame( 1, substr_count( $html, $result['secret'] ), $label . ': shown once' );
		}
	}

	public function test_every_code_block_scrolls_and_has_a_copy_button() {
		$result = $this->post(
			'mint_token',
			'',
			array(
				'label'  => 'Billing bot',
				'access' => 'read',
				'policy' => 'standard',
				'expiry' => '30',
			)
		);
		$html   = $this->admin()->render( $result );
		$this->assertGreaterThan( 20, preg_match_all( '#<pre\b[^>]*>#', $html ) );
		preg_match_all( '#<div style="overflow-x:auto;?">\s*<pre id="([^"]+)">.*?</pre>\s*</div>\s*<button type="button" class="ui mini basic button mainwp-code-copy-button" data-clipboard-target="\#([^"]+)"#s', $html, $blocks );
		$this->assertSame( preg_match_all( '#<pre\b[^>]*>#', $html ), count( $blocks[1] ), 'every block is wrapped, with a copy button' );
		$this->assertSame( $blocks[1], $blocks[2], 'each button copies its own block' );
		$this->assertSame( count( $blocks[1] ), count( array_unique( $blocks[1] ) ), 'ids are unique' );
	}
}
