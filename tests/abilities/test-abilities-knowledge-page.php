<?php
/**
 * Knowledge page access and navigation tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_AI;
use MainWP\Dashboard\MainWP_Control;
use MainWP\Dashboard\MainWP_Knowledge;
use MainWP\Dashboard\MainWP_Knowledge_UI;
use MainWP\Dashboard\MainWP_MCP;
use MainWP\Dashboard\MainWP_Menu;

class Test_Abilities_Knowledge_Page extends MainWP_Abilities_Test_Case {

	private $filters = array();
	private $previous_globals = array();
	private $previous_get;

	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$this->previous_get = $_GET;
		foreach ( array( 'submenu', 'mainwp_leftmenu', 'mainwp_sub_leftmenu', '_mainwp_menu_active_slugs', 'plugin_page', 'current_screen', '_registered_pages', '_parent_pages', '_wp_submenu_nopriv' ) as $key ) {
			$this->previous_globals[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		$GLOBALS['current_screen'] = \WP_Screen::get( 'mainwp_page_Knowledge' );
		$GLOBALS['submenu'] = array();
		$GLOBALS['mainwp_leftmenu'] = array();
		$GLOBALS['mainwp_sub_leftmenu'] = array();
		$GLOBALS['_mainwp_menu_active_slugs'] = array();
		$GLOBALS['plugin_page'] = 'Knowledge';
		$_GET['page'] = 'Knowledge';
	}

	public function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], 10 );
		}
		remove_action( 'admin_footer', array( MainWP_Knowledge_UI::class, 'render_modal' ) );
		foreach ( $this->previous_globals as $key => $value ) {
			$GLOBALS[ $key ] = $value;
		}
		$_GET = $this->previous_get;
		parent::tearDown();
	}

	private function filter( string $hook, callable $callback, int $args = 1 ): void {
		add_filter( $hook, $callback, 10, $args );
		$this->filters[] = array( $hook, $callback );
	}

	private function restrict_sites(): void {
		$this->filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
	}

	private function withhold_clients(): void {
		$this->filter( 'mainwp_currentusercan', static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'manage_clients' === $right ? false : $allowed;
		}, 3 );
	}

	private function register_menu(): void {
		// Isolating the new entry avoids unrelated page setup and keeps the production registration path under test.
		$this->filter( 'mainwp_init_primary_menu_items', static function ( $items ) {
			return array_values( array_filter( $items, static function ( $item ) {
				return in_array( $item['slug'], array( 'ai', 'Knowledge', 'mainwp-mcp', 'mainwp-control' ), true );
			} ) );
		} );
		MainWP_Menu::init_mainwp_menus();
	}

	private function render( string $tab = 'review' ): string {
		$_GET['tab'] = $tab;
		ob_start();
		try {
			MainWP_Knowledge::render();
			if ( has_action( 'admin_footer', array( MainWP_Knowledge_UI::class, 'render_modal' ) ) ) {
				MainWP_Knowledge_UI::render_modal();
			}
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	private function render_ai(): string {
		ob_start();
		try {
			MainWP_AI::render();
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	private function render_mcp(): string {
		ob_start();
		try {
			MainWP_MCP::render();
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	private function render_control(): string {
		ob_start();
		try {
			MainWP_Control::render();
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	private function assert_menu(): void {
		$this->register_menu();
		$menus = $GLOBALS['submenu']['mainwp_tab'];
		$this->assertContains( 'ai', array_column( $menus, 2 ) );
		$this->assertContains( 'Knowledge', array_column( $menus, 2 ) );
		$this->assertContains( 'mainwp-mcp', array_column( $menus, 2 ) );
		$this->assertContains( 'mainwp-control', array_column( $menus, 2 ) );
		$ai_entry = $menus[ array_search( 'ai', array_column( $menus, 2 ), true ) ];
		$this->assertSame( 'manage_options', $ai_entry[1] );
		$entry = $menus[ array_search( 'Knowledge', array_column( $menus, 2 ), true ) ];
		$this->assertSame( 'manage_options', $entry[1] );
		$this->assertContains( 'ai', array_column( $GLOBALS['mainwp_leftmenu']['leftbar'], 1 ) );
		$this->assertSame( array( 'Knowledge', 'MainWP MCP', 'MainWP Control' ), array_column( $GLOBALS['mainwp_sub_leftmenu']['ai'], 0 ) );
		$this->assertSame( array( 'Knowledge', 'mainwp-mcp', 'mainwp-control' ), array_column( $GLOBALS['mainwp_sub_leftmenu']['ai'], 4 ) );
		$this->assertNotFalse( has_action( get_plugin_page_hookname( 'ai', 'mainwp_tab' ), array( MainWP_AI::class, 'render' ) ) );
		$this->assertNotFalse( has_action( get_plugin_page_hookname( 'Knowledge', 'mainwp_tab' ), array( MainWP_Knowledge::class, 'render' ) ) );
		$this->assertNotFalse( has_action( get_plugin_page_hookname( 'mainwp-mcp', 'mainwp_tab' ), array( MainWP_MCP::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'load-' . get_plugin_page_hookname( 'mainwp-mcp', 'mainwp_tab' ), array( MainWP_MCP::class, 'enqueue_scripts' ) ) );
		$this->assertNotFalse( has_action( get_plugin_page_hookname( 'mainwp-control', 'mainwp_tab' ), array( MainWP_Control::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'load-' . get_plugin_page_hookname( 'mainwp-control', 'mainwp_tab' ), array( MainWP_Control::class, 'enqueue_scripts' ) ) );
	}

	public function test_ai_feature_pages_render(): void {
		$this->assert_menu();
		$html = $this->render_ai();
		$this->assertStringContainsString( 'id="mainwp-ai-page" class="ui padded segment"', $html );
		$this->assertStringNotContainsString( 'AI tools for your MainWP workflows', $html );
		$this->assertStringContainsString( 'id="mainwp-ai-feature-cards" class="ui four stackable cards"', $html );
		$this->assertSame( 3, substr_count( $html, '<div class="ui fluid card">' ) );
		$this->assertSame( 3, substr_count( $html, '<div class="extra content">' ) );
		$this->assertSame( 3, substr_count( $html, 'class="ui mini basic grey right floated button"' ) );
		$this->assertSame( 3, substr_count( $html, 'Read More' ) );
		$this->assertSame( 3, substr_count( $html, 'Documentation' ) );
		$this->assertStringContainsString( 'Review collected Knowledge records or add and manage Agency knowledge for connected AI tools.', $html );
		$this->assertStringNotContainsString( 'client, and site context', $html );
		foreach ( array( 'Knowledge', 'MainWP MCP', 'MainWP Control', ':book:', ':brain:', ':computer:' ) as $copy ) {
			$this->assertStringContainsString( $copy, $html );
		}
		foreach ( array( 'https://docs.mainwp.com/', 'https://docs.mainwp.com/mcp-server/quickstart', 'https://docs.mainwp.com/api-reference/mainwp-control' ) as $documentation_url ) {
			$this->assertStringContainsString( $documentation_url, $html );
		}
		foreach ( array( 'admin.php?page=Knowledge', 'admin.php?page=mainwp-mcp', 'admin.php?page=mainwp-control' ) as $href ) {
			$this->assertStringContainsString( $href, $html );
		}
		$this->assertStringNotContainsString( 'mainwp-knowledge-page', $html );

		$mcp = $this->render_mcp();
		$this->assertStringContainsString( 'id="mainwp-mcp-page" class="ui padded segment"', $mcp );
		foreach ( array( 'Manage MainWP with your AI assistant', 'Credentials', 'Connect', 'Test', 'Finish', 'Create Application Password', 'List all my sites', 'You are ready to use MainWP MCP', 'What you can do', 'Built-in safeguards' ) as $copy ) {
			$this->assertStringContainsString( $copy, $mcp );
		}
		$this->assertStringNotContainsString( 'Get connected in four steps', $mcp );
		$this->assertSame( 1, substr_count( $mcp, 'class="ui section divider mainwp-mcp-section-divider"' ) );
		$this->assertStringContainsString( 'id="mainwp-mcp-steps" class="ui circular ordered stackable fluid steps"', $mcp );
		$this->assertSame( 10, substr_count( $mcp, 'data-step-index=' ) );
		$this->assertSame( 4, substr_count( $mcp, 'data-step-panel=' ) );
		$this->assertStringContainsString( 'class="active step" role="tab"', $mcp );
		$this->assertSame( 4, substr_count( $mcp, 'class="ui divider mainwp-mcp-step-navigation-divider"' ) );
		$this->assertSame( 4, substr_count( $mcp, 'class="mainwp-mcp-step-navigation-actions"' ) );
		$this->assertSame( 3, substr_count( $mcp, 'class="mainwp-mcp-step-navigation"' ) );
		$this->assertSame( 3, substr_count( $mcp, 'class="mainwp-mcp-step-navigation mainwp-mcp-next-step"' ) );
		$this->assertSame( 6, substr_count( $mcp, '<span class="ui grey text">' ) );
		$this->assertSame( 3, substr_count( $mcp, 'class="ui grey chevron left icon"' ) );
		$this->assertSame( 3, substr_count( $mcp, 'class="ui grey chevron right icon"' ) );
		$this->assertSame( 3, substr_count( $mcp, 'Previous Step' ) );
		$this->assertSame( 3, substr_count( $mcp, 'Next Step' ) );
		$this->assertStringContainsString( 'id="mainwp-mcp-copy-config"', $mcp );
		$this->assertStringContainsString( 'data-clipboard-target="#mainwp-mcp-config"', $mcp );
		$this->assertStringContainsString( 'Copy to clipboard', $mcp );
		$this->assertStringContainsString( '<textarea id="mainwp-mcp-config" class="mainwp-copyable-code mainwp-mcp-config-editor"', $mcp );
		$this->assertStringContainsString( '<div class="mainwp-mcp-step-action">', $mcp );
		$this->assertStringContainsString( 'class="ui small green button mainwp-code-copy-button mainwp-mcp-copy-config-button"', $mcp );
		$this->assertStringNotContainsString( '<div class="ui secondary segment">', $mcp );
		$this->assertStringNotContainsString( '<pre id="mainwp-mcp-config"', $mcp );
		$this->assertSame( 4, substr_count( $mcp, 'class="mainwp-mcp-step-intro"' ) );
		$this->assertStringContainsString( 'Learn more about Application Passwords.', $mcp );
		$this->assertStringContainsString( 'Find instructions for your AI tool.', $mcp );
		$this->assertStringNotContainsString( 'Credential guide', $mcp );
		$this->assertStringNotContainsString( 'Your Dashboard stays in control', $mcp );
		$this->assertStringNotContainsString( 'MainWP MCP lets your AI assistant work with sites', $mcp );
		$this->assertStringNotContainsString( 'class="microchip icon"', $mcp );
		$this->assertStringNotContainsString( '<div class="ui two column stackable grid">', $mcp );
		$this->assertSame( 1, substr_count( $mcp, 'Try ready-made prompts' ) );
		$this->assertSame( 1, substr_count( $mcp, 'https://docs.mainwp.com/mcp-server/prompt-cookbook' ) );
		$this->assertMatchesRegularExpression( '/mainwp-mcp-step-panel-3[\s\S]*Try ready-made prompts[\s\S]*mainwp-mcp-step-panel-4/', $mcp );
		$this->assertSame( 1, substr_count( $mcp, 'https://docs.mainwp.com/mcp-server/quickstart' ) );
		$this->assertStringContainsString( 'Follow the full setup guide.', $mcp );
		$this->assertStringContainsString( 'View MainWP MCP on GitHub.', $mcp );
		$this->assertStringNotContainsString( 'Follow the full quickstart', $mcp );
		$this->assertStringNotContainsString( 'external alternate icon', $mcp );
		foreach ( array( '"command": "npx"', '"@mainwp/mcp"', '"MAINWP_URL"', '"MAINWP_USER"', '"MAINWP_APP_PASSWORD"' ) as $config ) {
			$this->assertStringContainsString( $config, $mcp );
		}
		$this->assertStringContainsString( 'id="rest-application-passwords-settings" class="mainwp-inline-application-password-creator"', $mcp );
		$this->assertStringContainsString( 'data-default-name="MainWP MCP"', $mcp );
		$this->assertStringContainsString( 'data-password-target="#mainwp-mcp-config"', $mcp );
		$this->assertStringContainsString( 'id="mainwp-create-application-password-modal"', $mcp );
		$this->assertStringContainsString( 'id="mainwp-application-password-success-modal"', $mcp );
		$this->assertStringNotContainsString( 'admin.php?page=ApplicationPasswords', $mcp );
		$this->assertStringContainsString( 'https://docs.mainwp.com/mcp-server/quickstart', $mcp );
		$this->assertStringContainsString( 'https://github.com/mainwp/mainwp-mcp', $mcp );
		$this->assertStringNotContainsString( 'git clone', $mcp );

		$control = $this->render_control();
		$this->assertStringContainsString( 'id="mainwp-control-page" class="ui padded segment"', $control );
		foreach ( array( 'Automate MainWP from the command line', 'Credentials', 'Install', 'Test', 'Finish', 'Create Application Password', 'Discover abilities and run your first command', 'You are ready to use MainWP Control', 'Why use MainWP Control', 'Built-in safeguards' ) as $copy ) {
			$this->assertStringContainsString( $copy, $control );
		}
		$this->assertStringNotContainsString( 'Get started in three steps', $control );
		$this->assertStringNotContainsString( 'Built for automation', $control );
		$this->assertStringNotContainsString( 'class="terminal icon"', $control );
		$this->assertStringContainsString( 'id="mainwp-control-steps" class="ui circular ordered stackable fluid steps"', $control );
		$this->assertSame( 10, substr_count( $control, 'data-step-index=' ) );
		$this->assertSame( 4, substr_count( $control, 'data-step-panel=' ) );
		$this->assertStringContainsString( 'class="active step" role="tab"', $control );
		$this->assertSame( 4, substr_count( $control, 'class="mainwp-control-step-intro"' ) );
		$this->assertSame( 4, substr_count( $control, 'class="ui divider mainwp-control-step-navigation-divider"' ) );
		$this->assertSame( 4, substr_count( $control, 'class="mainwp-control-step-navigation-actions"' ) );
		$this->assertSame( 3, substr_count( $control, 'class="mainwp-control-step-navigation"' ) );
		$this->assertSame( 3, substr_count( $control, 'class="mainwp-control-step-navigation mainwp-control-next-step"' ) );
		$this->assertSame( 6, substr_count( $control, '<span class="ui grey text">' ) );
		$this->assertSame( 3, substr_count( $control, 'class="ui grey chevron left icon"' ) );
		$this->assertSame( 3, substr_count( $control, 'class="ui grey chevron right icon"' ) );
		$this->assertSame( 3, substr_count( $control, 'Previous Step' ) );
		$this->assertSame( 3, substr_count( $control, 'Next Step' ) );
		$this->assertStringContainsString( 'npm install -g @mainwp/control', $control );
		$this->assertStringContainsString( 'mainwpcontrol login', $control );
		$this->assertStringContainsString( 'mainwpcontrol abilities run list-sites-v1 --json', $control );
		$this->assertStringContainsString( '<textarea id="mainwp-control-install-commands" class="mainwp-copyable-code mainwp-control-code-editor"', $control );
		$this->assertStringContainsString( '<textarea id="mainwp-control-first-commands" class="mainwp-copyable-code mainwp-control-code-editor"', $control );
		$this->assertSame( 2, substr_count( $control, 'class="ui small green button mainwp-code-copy-button"' ) );
		$this->assertStringNotContainsString( '<pre id="mainwp-control-', $control );
		$this->assertStringNotContainsString( '<div class="ui two column stackable grid">', $control );
		$this->assertStringContainsString( 'View MainWP Control on GitHub.', $control );
		$this->assertStringContainsString( 'https://docs.mainwp.com/api-reference/mainwp-control', $control );
		$this->assertStringContainsString( 'https://mainwp.com/mainwp-tools/mainwp-control/', $control );
		$this->assertStringContainsString( 'https://github.com/mainwp/mainwp-control', $control );
		$this->assertStringContainsString( 'id="rest-application-passwords-settings" class="mainwp-inline-application-password-creator"', $control );
		$this->assertStringContainsString( 'data-default-name="MainWP Control"', $control );
		$this->assertStringContainsString( 'data-password-target=""', $control );
		$this->assertStringContainsString( 'id="mainwp-create-application-password-modal"', $control );
		$this->assertStringContainsString( 'id="mainwp-application-password-success-modal"', $control );
		$this->assertStringNotContainsString( 'admin.php?page=ApplicationPasswords', $control );
	}

	public function test_knowledge_menu_and_page_admit_unrestricted_users(): void {
		$this->assert_menu();
		$review = $this->render();
		$this->assertStringContainsString( 'id="mainwp-knowledge-review"', $review );
		$this->assertStringContainsString( 'id="mainwp-knowledge-page" class="ui padded segment"', $review );
		$this->assertStringContainsString( 'class="ui labeled icon inverted menu mainwp-sub-submenu" id="mainwp-knowledge-tabular-menu"', $review );
		$this->assertStringContainsString( 'class="item active" data-tab="review"', $review );
		$this->assertStringContainsString( 'class="check circle icon"', $review );
		$this->assertStringNotContainsString( 'id="mainwp-page-navigation-wrapper"', $review );
		$agency = $this->render( 'agency' );
		$this->assertStringContainsString( 'id="mainwp-knowledge-page" class="ui padded segment"', $agency );
		$this->assertStringContainsString( 'class="item active" data-tab="agency"', $agency );
		$this->assertStringContainsString( 'class="building icon"', $agency );
		$this->assertStringContainsString( 'data-scope-type="agency" data-scope-id="0" data-can-write="1"', $agency );
		$this->assertStringContainsString( 'id="mainwp-knowledge-required"', $agency );
		$this->assertStringContainsString( 'Records at a more specific level cannot override a required record. Only a Dashboard user can edit or delete it.', $agency );
		$this->assertStringContainsString( 'class="ui blue message" id="mainwp-knowledge-required-help" style="display:none"', $agency );
		$this->assertLessThan( strpos( $agency, 'id="mainwp-knowledge-type"' ), strpos( $agency, 'id="mainwp-knowledge-required-help"' ) );
		$this->assertStringContainsString( 'Agency records reach the AI agents working on every site in this Dashboard.', $agency );
		$this->assertStringContainsString( '<div class="ui blue message">', $agency );
		$this->assertStringContainsString( 'class="close icon mainwp-notice-dismiss" notice-id="mainwp-knowledge-agency-info-message"', $agency );
		$this->assertDoesNotMatchRegularExpression( '/<h2[^>]*>\s*Agency knowledge/s', $agency );
		$this->assertStringNotContainsString( 'Only users with client management rights and access to every site can change agency records.', $agency );
	}

	public function test_knowledge_menu_and_page_admit_site_restricted_users_read_only(): void {
		$this->restrict_sites();
		$this->assert_menu();
		$this->assertStringContainsString( 'id="mainwp-knowledge-review"', $this->render() );
		$agency = $this->render( 'agency' );
		$this->assertStringContainsString( 'data-can-write="0"', $agency );
		$this->assertStringNotContainsString( 'id="mainwp-knowledge-required"', $agency );
		$this->assertStringNotContainsString( 'mainwp-knowledge-add', $agency );
		$this->assertStringContainsString( 'Only users with client management rights and access to every site can change agency records.', $agency );
	}

	public function test_knowledge_all_sites_without_manage_clients_has_read_only_agency_and_no_required(): void {
		$this->withhold_clients();
		$this->assert_menu();
		$this->assertStringContainsString( 'id="mainwp-knowledge-review"', $this->render() );
		$agency = $this->render( 'agency' );
		$this->assertStringContainsString( 'data-can-write="0"', $agency );
		$this->assertStringNotContainsString( 'id="mainwp-knowledge-required"', $agency );
		$this->assertStringNotContainsString( 'mainwp-knowledge-add', $agency );
	}

	public function test_knowledge_menu_and_page_refuse_non_mainwp_admin_with_the_base_capability(): void {
		$id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user = get_userdata( $id );
		$user->add_cap( 'manage_options' );
		wp_set_current_user( $id );
		$this->register_menu();
		MainWP_AI::init_menu();
		MainWP_Knowledge::init_menu();
		MainWP_MCP::init_menu();
		MainWP_Control::init_menu();
		$this->assertNotContains( 'ai', array_column( $GLOBALS['submenu']['mainwp_tab'] ?? array(), 2 ) );
		$this->assertNotContains( 'Knowledge', array_column( $GLOBALS['submenu']['mainwp_tab'] ?? array(), 2 ) );
		$this->assertNotContains( 'mainwp-mcp', array_column( $GLOBALS['submenu']['mainwp_tab'] ?? array(), 2 ) );
		$this->assertNotContains( 'mainwp-control', array_column( $GLOBALS['submenu']['mainwp_tab'] ?? array(), 2 ) );
		$this->assertNotContains( 'ai', array_column( $GLOBALS['mainwp_leftmenu']['leftbar'] ?? array(), 1 ) );
		$this->assertStringContainsString( 'You do not have permission to access this page.', $this->render_ai() );
		$this->assertStringContainsString( 'You do not have permission to access this page.', $this->render_mcp() );
		$this->assertStringContainsString( 'You do not have permission to access this page.', $this->render_control() );
		foreach ( array( 'review', 'agency' ) as $tab ) {
			$html = $this->render( $tab );
			$this->assertStringContainsString( 'You do not have permission to access this knowledge.', $html );
			$this->assertStringNotContainsString( 'mainwp-knowledge-panel', $html );
			$this->assertStringNotContainsString( 'id="mainwp-knowledge-review"', $html );
		}
		foreach ( array( 'render_left_menu', 'render_mobile_menu' ) as $method ) {
			ob_start();
			MainWP_Menu::$method();
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'page=Knowledge', $html, $method );
			$this->assertStringNotContainsString( 'page=ai', $html, $method );
			$this->assertStringNotContainsString( 'page=mainwp-mcp', $html, $method );
			$this->assertStringNotContainsString( 'page=mainwp-control', $html, $method );
		}
	}

	public function test_knowledge_menu_and_page_refuse_missing_base_capability(): void {
		$this->set_current_user_as_subscriber();
		$this->register_menu();
		$this->assertNotContains( 'ai', array_column( $GLOBALS['mainwp_leftmenu']['leftbar'] ?? array(), 1 ) );
		$this->assertStringContainsString( 'You do not have permission to access this page.', $this->render_ai() );
		$this->assertStringContainsString( 'You do not have permission to access this page.', $this->render_mcp() );
		$this->assertStringContainsString( 'You do not have permission to access this page.', $this->render_control() );
		foreach ( array( 'review', 'agency' ) as $tab ) {
			$html = $this->render( $tab );
			$this->assertStringContainsString( 'You do not have permission to access this knowledge.', $html );
			$this->assertStringNotContainsString( 'mainwp-knowledge-panel', $html );
			$this->assertStringNotContainsString( 'id="mainwp-knowledge-review"', $html );
		}
	}

	public function test_knowledge_navigation_renders_for_restricted_and_unrestricted_users(): void {
		$this->register_menu();
		foreach ( array( false, true ) as $restricted ) {
			if ( $restricted ) {
				$this->restrict_sites();
				$this->withhold_clients();
			}
			foreach ( array( 'render_left_menu', 'render_mobile_menu' ) as $method ) {
				ob_start();
				MainWP_Menu::$method();
				$html = ob_get_clean();
				$this->assertMatchesRegularExpression( '/href="[^"]*admin.php\?page=ai"[^>]*>\s*<i class="microchip icon"><\/i>\s*(?:<span class="ui small text">)?AI(?:<\/span>)?\s*<\/a>/', $html, $method );
				$this->assertMatchesRegularExpression( '/href="[^"]*admin.php\?page=Knowledge"[^>]*>\s*Knowledge\s*<\/a>/', $html, $method );
				$this->assertMatchesRegularExpression( '/href="[^"]*admin.php\?page=mainwp-mcp"[^>]*>\s*MainWP MCP\s*<\/a>/', $html, $method );
				$this->assertMatchesRegularExpression( '/href="[^"]*admin.php\?page=mainwp-control"[^>]*>\s*MainWP Control\s*<\/a>/', $html, $method );
				if ( 'render_left_menu' === $method ) {
					$this->assertMatchesRegularExpression( '/class="title with-sub active" href="javascript:void\(0\)">AI\s*<i class="dropdown icon"><\/i>/', $html );
				}
				$this->assertStringNotContainsString( 'page=Knowledge&#038;tab=review', $html, $method );
				$this->assertStringNotContainsString( 'page=Knowledge&#038;tab=agency', $html, $method );
			}
		}
	}

	public function test_knowledge_review_is_default_and_has_filters_and_a_panel_free_modal(): void {
		$html = $this->render( 'unknown' );
		$this->assertStringContainsString( 'id="mainwp-knowledge-review"', $html );
		$this->assertStringNotContainsString( 'mainwp-knowledge-review-table', $html );
		$this->assertStringNotContainsString( 'mainwp-knowledge-panel', $html );
		$this->assertSame( 1, substr_count( $html, 'id="mainwp-knowledge-modal"' ) );
		foreach ( array( 'Status', 'Unverified', 'Verified', 'All', 'Level', 'All levels', 'Nothing to review yet', 'Knowledge records saved by AI agents will appear here until a person reviews them.', 'No knowledge records match the selected filters.', 'No matching records', 'Context', 'Skills', 'Memory' ) as $copy ) {
			$this->assertStringContainsString( $copy, $html );
		}
		$this->assertStringContainsString( 'class="mainwp-actions-bar" id="mainwp-knowledge-review-filters"', $html );
		$this->assertStringContainsString( 'class="ui blue message"', $html );
		$this->assertStringContainsString( 'class="close icon mainwp-notice-dismiss" notice-id="mainwp-knowledge-review-info-message"', $html );
		$this->assertStringContainsString( 'Check each record, then verify, edit, or delete it.', $html );
		$this->assertStringContainsString( 'class="ui two column row"', $html );
		$this->assertStringContainsString( 'class="left aligned middle aligned column"', $html );
		$this->assertStringContainsString( 'class="right aligned middle aligned column"', $html );
		$this->assertStringNotContainsString( '<label for="mainwp-knowledge-filter-', $html );
		$this->assertStringContainsString( 'id="mainwp-knowledge-filter-status" class="ui mini selection dropdown" aria-label="Status"', $html );
		$this->assertStringContainsString( 'id="mainwp-knowledge-filter-level" class="ui mini selection dropdown" aria-label="Level"', $html );
		$this->assertStringNotContainsString( 'id="mainwp-knowledge-filter-type"', $html );
		$this->assertStringContainsString( 'id="mainwp-knowledge-review" style="padding:0!important;"', $html );
		$this->assertLessThan( strpos( $html, 'mainwp-knowledge-review-info-message' ), strpos( $html, 'id="mainwp-knowledge-review-filters"' ) );
		$this->assertStringContainsString( 'id="mainwp-knowledge-review-empty"', $html );
		$this->assertStringContainsString( 'data-emoji=":bulb:"', $html );
		$this->assertStringContainsString( 'admin.php?page=Knowledge', $html );
		$this->assertStringContainsString( esc_url( 'admin.php?page=Knowledge&tab=agency' ), $html );
		$this->assertMatchesRegularExpression( '/<option value="unverified"\s+selected/', $html );
		$this->assertSame( 1, preg_match_all( '/<option value="all"\s+selected/', $html ) );
		$this->assertStringContainsString( 'mainwp-knowledge-review-sections', $html );
		$this->assertSame( 3, substr_count( $html, 'mainwp-knowledge-review-section" data-type=' ) );
		$this->assertSame( 3, substr_count( $html, 'ui styled fluid accordion mainwp-knowledge-records' ) );
		$this->assertStringNotContainsString( 'mainwp-knowledge-review-pagination', $html );
		$this->assertStringContainsString( 'class="ui segment mainwp-widget mainwp-knowledge-section-box"', $html );
	}
}
