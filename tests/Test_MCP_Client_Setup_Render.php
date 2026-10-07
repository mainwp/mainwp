<?php
/**
 * AI client setup on the Application Passwords page.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Rest_Api_Page;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MCP_Client_Setup_Render
 */
class Test_MCP_Client_Setup_Render extends \WP_UnitTestCase {

	/**
	 * A login WordPress would never create, written straight to the table, so
	 * the attribute escaping is what keeps it from becoming markup.
	 *
	 * @var string
	 */
	const HOSTILE_LOGIN = 'ad"min\'><script>x</script>';

	/**
	 * Set the current user to an administrator with a hostile login.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$wpdb->update( $wpdb->users, array( 'user_login' => self::HOSTILE_LOGIN ), array( 'ID' => $user_id ) );
		clean_user_cache( $user_id );
		wp_set_current_user( $user_id );
	}

	/**
	 * Parse rendered HTML and return the setup containers.
	 *
	 * @param string $html Rendered HTML.
	 * @return array{0: \DOMXPath, 1: \DOMNodeList}
	 */
	private function setup_containers( $html ) {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $doc );
		return array( $xpath, $xpath->query( '//div[contains(concat(" ", @class, " "), " mainwp-ai-client-setup ")]' ) );
	}

	/**
	 * The success modal carries the Dashboard URL and the creating user's login for the snippets.
	 */
	public function test_success_modal_renders_setup_with_url_and_username(): void {
		ob_start();
		MainWP_Rest_Api_Page::success_application_password_modal();
		$html = ob_get_clean();

		list( $xpath, $containers ) = $this->setup_containers( $html );
		$this->assertSame( 1, $containers->length );
		$container = $containers->item( 0 );
		$this->assertSame( 'modal', $container->getAttribute( 'data-setup-context' ) );
		$this->assertSame( untrailingslashit( home_url() ), $container->getAttribute( 'data-dashboard-url' ) );
		$this->assertSame( self::HOSTILE_LOGIN, $container->getAttribute( 'data-username' ) );
		$this->assertSame( 0, $xpath->query( '//script' )->length, 'The login must stay inside its attribute.' );
		$this->assertSame( __( 'MainWP Application Password', 'mainwp' ), $container->getAttribute( 'data-vscode-password-prompt' ), 'The VS Code prompt text comes from PHP so it can be translated.' );

		$snippets = array();
		foreach ( $xpath->query( './/code[@data-snippet]', $container ) as $code ) {
			$snippets[] = $code->getAttribute( 'data-snippet' );
			$this->assertSame( '', $code->textContent, 'Snippets are filled client-side from the create response.' );
		}
		$this->assertSame( array( 'claude-code', 'cursor', 'vscode' ), $snippets );
		$this->assertSame( 3, $xpath->query( './/button[contains(@class, "mainwp-ai-client-setup-copy")]', $container )->length );
	}

	/**
	 * The onboarding notice renders the same setup under its own tab names.
	 */
	public function test_welcome_notice_renders_setup_with_distinct_tabs(): void {
		$method = new \ReflectionMethod( MainWP_Rest_Api_Page::class, 'render_application_passwords_messages' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( null, array() );
		$html = ob_get_clean();

		list( $xpath, $containers ) = $this->setup_containers( $html );
		$this->assertSame( 1, $containers->length );
		$container = $containers->item( 0 );
		$this->assertSame( 'notice', $container->getAttribute( 'data-setup-context' ) );
		$this->assertSame( self::HOSTILE_LOGIN, $container->getAttribute( 'data-username' ) );

		$tabs = array();
		foreach ( $xpath->query( './/a[@data-tab]', $container ) as $item ) {
			$tabs[] = $item->getAttribute( 'data-tab' );
		}
		$this->assertSame( array( 'mainwp-ai-setup-notice-claude-code', 'mainwp-ai-setup-notice-cursor', 'mainwp-ai-setup-notice-vscode' ), $tabs );
	}
}
