<?php
/**
 * Knowledge page access and navigation tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Knowledge;
use MainWP\Dashboard\MainWP_Knowledge_UI;
use MainWP\Dashboard\MainWP_Menu;

class Test_Abilities_Knowledge_Page extends MainWP_Abilities_Test_Case {

	private $filters = array();
	private $previous_globals = array();
	private $previous_get;

	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$this->previous_get = $_GET;
		foreach ( array( 'submenu', 'mainwp_leftmenu', 'current_screen', '_registered_pages', '_parent_pages', '_wp_submenu_nopriv' ) as $key ) {
			$this->previous_globals[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		$GLOBALS['current_screen'] = \WP_Screen::get( 'mainwp_page_Knowledge' );
		$GLOBALS['submenu'] = array();
		$GLOBALS['mainwp_leftmenu'] = array();
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
				return 'Knowledge' === $item['slug'];
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

	private function assert_menu(): void {
		$this->register_menu();
		$menus = $GLOBALS['submenu']['mainwp_tab'];
		$this->assertContains( 'Knowledge', array_column( $menus, 2 ) );
		$entry = $menus[ array_search( 'Knowledge', array_column( $menus, 2 ), true ) ];
		$this->assertSame( 'manage_options', $entry[1] );
		$this->assertContains( 'Knowledge', array_column( $GLOBALS['mainwp_leftmenu']['leftbar'], 1 ) );
		$this->assertNotFalse( has_action( get_plugin_page_hookname( 'Knowledge', 'mainwp_tab' ), array( MainWP_Knowledge::class, 'render' ) ) );
	}

	public function test_knowledge_menu_and_page_admit_unrestricted_users(): void {
		$this->assert_menu();
		$this->assertStringContainsString( 'id="mainwp-knowledge-review-table"', $this->render() );
		$agency = $this->render( 'agency' );
		$this->assertStringContainsString( 'data-scope-type="agency" data-scope-id="0" data-can-write="1"', $agency );
		$this->assertStringContainsString( 'id="mainwp-knowledge-required"', $agency );
		$this->assertStringContainsString( 'Client and site records cannot override a required record. Only a Dashboard user can edit or delete it.', $agency );
		$this->assertStringContainsString( 'Agency records reach the AI agents working on every site in this Dashboard.', $agency );
	}

	public function test_knowledge_menu_and_page_admit_site_restricted_users_read_only(): void {
		$this->restrict_sites();
		$this->assert_menu();
		$this->assertStringContainsString( 'id="mainwp-knowledge-review-table"', $this->render() );
		$agency = $this->render( 'agency' );
		$this->assertStringContainsString( 'data-can-write="0"', $agency );
		$this->assertStringNotContainsString( 'id="mainwp-knowledge-required"', $agency );
		$this->assertStringNotContainsString( 'mainwp-knowledge-add', $agency );
		$this->assertStringContainsString( 'Only users with client management rights and access to every site can change agency records.', $agency );
	}

	public function test_knowledge_all_sites_without_manage_clients_has_read_only_agency_and_no_required(): void {
		$this->withhold_clients();
		$this->assert_menu();
		$this->assertStringContainsString( 'id="mainwp-knowledge-review-table"', $this->render() );
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
		MainWP_Knowledge::init_menu();
		$this->assertNotContains( 'Knowledge', array_column( $GLOBALS['submenu']['mainwp_tab'] ?? array(), 2 ) );
		$this->assertNotContains( 'Knowledge', array_column( $GLOBALS['mainwp_leftmenu']['leftbar'] ?? array(), 1 ) );
		foreach ( array( 'review', 'agency' ) as $tab ) {
			$html = $this->render( $tab );
			$this->assertStringContainsString( 'You do not have permission to access this knowledge.', $html );
			$this->assertStringNotContainsString( 'mainwp-knowledge-panel', $html );
			$this->assertStringNotContainsString( 'mainwp-knowledge-review-table', $html );
		}
		foreach ( array( 'render_left_menu', 'render_mobile_menu' ) as $method ) {
			ob_start();
			MainWP_Menu::$method();
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'page=Knowledge', $html, $method );
		}
	}

	public function test_knowledge_menu_and_page_refuse_missing_base_capability(): void {
		$this->set_current_user_as_subscriber();
		$this->register_menu();
		$this->assertNotContains( 'Knowledge', array_column( $GLOBALS['mainwp_leftmenu']['leftbar'] ?? array(), 1 ) );
		foreach ( array( 'review', 'agency' ) as $tab ) {
			$html = $this->render( $tab );
			$this->assertStringContainsString( 'You do not have permission to access this knowledge.', $html );
			$this->assertStringNotContainsString( 'mainwp-knowledge-panel', $html );
			$this->assertStringNotContainsString( 'mainwp-knowledge-review-table', $html );
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
				$this->assertMatchesRegularExpression( '/href="[^"]*admin.php\?page=Knowledge"[^>]*>.*?Knowledge/s', $html, $method );
			}
		}
	}

	public function test_knowledge_review_is_default_and_has_filters_and_a_panel_free_modal(): void {
		$html = $this->render( 'unknown' );
		$this->assertStringContainsString( 'id="mainwp-knowledge-review-table"', $html );
		$this->assertStringNotContainsString( 'mainwp-knowledge-panel', $html );
		$this->assertSame( 1, substr_count( $html, 'id="mainwp-knowledge-modal"' ) );
		foreach ( array( 'Status', 'Unverified', 'Verified', 'All', 'Type', 'All types', 'Level', 'All levels', 'Open', 'Nothing to review. Records saved by AI agents appear here until a person reviews them.', 'No records match these filters.' ) as $copy ) {
			$this->assertStringContainsString( $copy, $html );
		}
		$this->assertStringContainsString( 'admin.php?page=Knowledge', $html );
		$this->assertStringContainsString( esc_url( 'admin.php?page=Knowledge&tab=agency' ), $html );
		$this->assertMatchesRegularExpression( '/<option value="unverified"\s+selected/', $html );
	}
}
