<?php
/**
 * Remote MCP web app panel tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Admin;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Web_Apps
 */
class Test_MainWP_Remote_MCP_Web_Apps extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	const CALLBACK = 'https://chat.example.com/oauth/oidc/callback';

	/**
	 * Add a web app through the panel.
	 *
	 * @param array $fields Form fields.
	 * @return array Result.
	 */
	private function add( array $fields = array() ): array {
		return $this->post(
			'webapp_add',
			'',
			array_merge(
				array(
					'name'          => 'Open WebUI',
					'redirect_uris' => self::CALLBACK,
				),
				$fields
			)
		);
	}

	/**
	 * A web app row and a live connection through it.
	 *
	 * @return array `client_id`, `secret` and the connection.
	 */
	private function webapp_with_connection(): array {
		$this->known->valid_redirects = array( self::CALLBACK );
		$added                        = $this->add();
		$this->assertTrue( $added['ok'], $added['code'] );
		return array(
			'client_id'  => $added['client_id'],
			'secret'     => $added['secret'],
			'connection' => $this->connect_write( $this->user_id, 'standard', array( 'client_id' => $added['client_id'] ) ),
		);
	}

	public function test_webapp_add_requires_full_admin() {
		$this->known->valid_redirects = array( self::CALLBACK );

		wp_set_current_user( $this->limited_admin() );
		$this->assertSame( 'forbidden', $this->add()['code'] );
		$this->assertStringNotContainsString( 'value="webapp_add"', $this->admin()->render(), 'the form is for full administrators' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'forbidden', $this->add()['code'] );
		$this->assertSame( array(), $this->store->clients_by_kind( 'webapp' ) );

		wp_set_current_user( $this->user_id );
		$added = $this->add();
		$this->assertTrue( $added['ok'], $added['code'] );
		$this->assertMatchesRegularExpression( '/^mwpws_[A-Za-z0-9_-]{43}$/', $added['secret'] );
		$this->assertTrue( $this->store->verify_client_secret( $added['client_id'], $added['secret'] ) );
		$this->assertSame( 1, substr_count( $this->admin()->render( $added ), $added['secret'] ), 'the secret shows once' );
		$this->assertStringNotContainsString( $added['secret'], $this->admin()->render() );
		$this->assertStringContainsString( 'value="webapp_add"', $this->admin()->render() );
		$rows = $this->store->clients_by_kind( 'webapp' );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $this->user_id, (string) $rows[0]['created_by'] );
	}

	public function test_webapp_delete_revokes_its_grants() {
		$app   = $this->webapp_with_connection();
		$other = $this->connect_write( $this->user_id );

		wp_set_current_user( $this->limited_admin() );
		$this->assertSame( 'forbidden', $this->post( 'webapp_delete', $app['client_id'] )['code'] );
		$this->assertNotNull( $this->store->get_client( $app['client_id'] ) );

		wp_set_current_user( $this->user_id );
		$this->assertTrue( $this->post( 'webapp_delete', $app['client_id'] )['ok'] );
		$this->assertNull( $this->store->get_client( $app['client_id'] ) );
		$this->assertSame( 'revoked', $this->grant( $app['connection']['grant_id'] )['status'] );
		$this->assertFalse( $this->access_ok( $app['connection']['access_token'] ) );
		$this->assertSame( 'live', $this->grant( $other['grant_id'] )['status'], 'other apps keep their connections' );

		// A registered app is not a web app.
		$registered = $this->store->create_client( 'registered', array( 'redirect_uris' => array( 'http://127.0.0.1/cb' ) ) );
		$this->assertSame( 'invalid', $this->post( 'webapp_delete', $registered['client_id'] )['code'] );
	}

	public function test_webapp_reissue_secret_replaces_secret_and_revokes_grants() {
		$app = $this->webapp_with_connection();

		wp_set_current_user( $this->limited_admin() );
		$this->assertSame( 'forbidden', $this->post( 'webapp_reissue', $app['client_id'] )['code'] );
		$this->assertTrue( $this->store->verify_client_secret( $app['client_id'], $app['secret'] ) );

		wp_set_current_user( $this->user_id );
		$result = $this->post( 'webapp_reissue', $app['client_id'] );
		$this->assertTrue( $result['ok'], $result['code'] );
		$this->assertNotSame( $app['secret'], $result['secret'] );
		$this->assertFalse( $this->store->verify_client_secret( $app['client_id'], $app['secret'] ), 'old secret dead' );
		$this->assertTrue( $this->store->verify_client_secret( $app['client_id'], $result['secret'] ) );
		$this->assertSame( 'revoked', $this->grant( $app['connection']['grant_id'] )['status'] );
		$this->assertSame( 1, substr_count( $this->admin()->render( $result ), $result['secret'] ) );
		$this->assertNotNull( $this->store->get_client( $app['client_id'] ), 'the app stays' );
	}

	public function test_webapp_add_form_uses_known_clients_validator() {
		$html = $this->admin()->render();
		$this->assertStringContainsString( 'value="webapp_add"', $html );

		// Until the client lookup accepts a URI, nothing is stored.
		$refused = $this->add();
		$this->assertSame( 'invalid_redirect', $refused['code'] );
		$this->assertSame( array( array( 'webapp', self::CALLBACK ) ), $this->known->validate_calls );
		$this->assertSame( array(), $this->store->clients_by_kind( 'webapp' ) );

		// Every URI goes through the one validator; one bad entry refuses the whole app.
		$this->known->valid_redirects = array( self::CALLBACK );
		$this->known->validate_calls  = array();
		$mixed                        = $this->add( array( 'redirect_uris' => self::CALLBACK . "\nhttps://evil.example/cb" ) );
		$this->assertSame( 'invalid_redirect', $mixed['code'] );
		$this->assertSame( array( array( 'webapp', self::CALLBACK ), array( 'webapp', 'https://evil.example/cb' ) ), $this->known->validate_calls );
		$this->assertSame( array(), $this->store->clients_by_kind( 'webapp' ) );

		$this->assertSame( 'invalid', $this->add( array( 'redirect_uris' => '' ) )['code'], 'at least one URI' );
		$this->assertSame( 'invalid', $this->add( array( 'redirect_uris' => implode( "\n", array_fill( 0, 4, self::CALLBACK ) ) ) )['code'], 'at most three' );
		$this->assertSame( 'invalid', $this->add( array( 'name' => '' ) )['code'], 'a name is required' );
		$this->assertSame( 'invalid', $this->add( array( 'name' => str_repeat( 'n', 65 ) ) )['code'], 'at most 64 characters' );

		$added = $this->add( array( 'redirect_uris' => '  ' . self::CALLBACK . "  \r\n" ) );
		$this->assertTrue( $added['ok'], $added['code'] );
		$this->assertSame( array( self::CALLBACK ), $this->store->get_client( $added['client_id'] )['redirect_uris'], 'stored byte-exact' );
	}
}
