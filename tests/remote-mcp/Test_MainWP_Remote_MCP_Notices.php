<?php
/**
 * Remote MCP new-connection notice and expiry reminder tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Notices;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Notices
 */
class Test_MainWP_Remote_MCP_Notices extends \WP_UnitTestCase {

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

	/**
	 * Notices under test, hooked in.
	 *
	 * @var Remote_MCP_Notices
	 */
	private $notices;

	public function set_up() {
		$this->saved_server = $_SERVER;
		$this->fixtures_set_up();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->notices          = new Remote_MCP_Notices( $this->panel_services() );
		$this->notices->register_hooks();
		$this->known->entries[ self::CLIENT_ID ] = array( 'label' => 'Claude' );
	}

	public function tear_down() {
		$_SERVER = $this->saved_server;
		parent::tear_down();
	}

	public function test_new_grant_sends_notice_with_revoke_link() {
		$owner      = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'owner@example.org',
			)
		);
		$connection = $this->connect( $owner );

		do_action( Remote_MCP_Events::GRANT_ISSUED, $connection['grant_id'], $owner, self::CLIENT_ID, 'bundled', 'code_exchange' );

		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'owner@example.org', $mails[0]['to'][0][0], 'only the connection owner is mailed' );
		$this->assertStringContainsString( 'Claude', $mails[0]['subject'] );
		$this->assertStringContainsString( 'Claude', $mails[0]['body'] );
		$this->assertStringContainsString( '198.51.100.7', $mails[0]['body'] );
		$this->assertStringContainsString( get_userdata( $owner )->user_login, $mails[0]['body'] );
		$link = $this->notices->revoke_link( $connection['grant_id'] );
		$this->assertStringContainsString( 'page=mainwp-mcp&tab=direct', $link );
		$this->assertStringContainsString( 'revoke=' . $connection['grant_id'], $link );
		$this->assertStringContainsString( $link, $mails[0]['body'] );
		$this->assertStringNotContainsString( $connection['access_token'], $mails[0]['body'] );

		// Full administrators see it in the panel.
		$html = $this->admin()->render();
		$this->assertStringContainsString( 'New connection', $html );
		$this->assertStringContainsString( '198.51.100.7', $html );

		// An action for a grant that doesn't exist sends nothing.
		do_action( Remote_MCP_Events::GRANT_ISSUED, 999999, $owner, self::CLIENT_ID, 'bundled', 'code_exchange' );
		$this->assertCount( 1, $this->mails() );
	}

	public function test_connection_token_mint_sends_new_connection_notice() {
		$result = $this->post(
			'mint_token',
			'',
			array(
				'label'  => 'AnythingLLM',
				'access' => 'read',
				'policy' => 'standard',
				'expiry' => '90',
			)
		);
		$this->assertTrue( $result['ok'], $result['code'] );

		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( get_userdata( $this->user_id )->user_email, $mails[0]['to'][0][0] );
		$this->assertStringContainsString( 'The connection token AnythingLLM was created on your MainWP Dashboard as ' . get_userdata( $this->user_id )->user_login . ' on ', $mails[0]['body'] );
		$this->assertStringContainsString( 'revoke=' . $result['grant_id'], $mails[0]['body'] );
		$this->assertStringNotContainsString( $result['secret'], $mails[0]['body'], 'the token itself is never mailed' );
	}

	public function test_connection_token_expiry_reminder_sent_seven_days_before() {
		$soon  = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Expiring soon', time() + 6 * DAY_IN_SECONDS );
		$later = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Plenty of time', time() + 30 * DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $soon['outcome'] );
		$this->assertSame( OAuth_Store::OK, $later['outcome'] );
		$this->assertNotFalse( has_action( Remote_MCP_Settings::PURGE_HOOK, array( $this->notices, 'send_expiry_reminders' ) ), 'runs from the daily event' );

		$this->assertSame( 1, $this->notices->send_expiry_reminders() );
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'Expiring soon', $mails[0]['body'] );
		$this->assertStringNotContainsString( 'Plenty of time', $mails[0]['body'] );
		$this->assertSame( get_userdata( $this->user_id )->user_email, $mails[0]['to'][0][0] );

		// The next daily run doesn't send it again.
		$this->assertSame( 0, $this->notices->send_expiry_reminders() );
		$this->assertCount( 1, $this->mails() );

		// A revoked token gets no reminder.
		$gone = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Revoked one', time() + 2 * DAY_IN_SECONDS );
		$this->store->revoke_grant( $gone['grant_id'], 'manual' );
		$this->assertSame( 0, $this->notices->send_expiry_reminders() );
	}

	public function test_registered_and_webapp_names_escaped_in_panel_and_notice() {
		$registered = $this->store->create_client(
			'registered',
			array(
				'redirect_uris' => array( 'http://127.0.0.1/callback' ),
				'client_name'   => "<img src=x onerror=alert(1)>\r\nBcc: victim@example.org",
			)
		);
		$webapp     = $this->store->create_client(
			'webapp',
			array(
				'redirect_uris' => array( 'https://chat.example.com/cb' ),
				'client_name'   => '<script>alert(2)</script>',
			)
		);
		$local      = $this->connect_write( $this->user_id, 'standard', array( 'client_id' => $registered['client_id'] ) );
		$hosted     = $this->connect_write( $this->user_id, 'standard', array( 'client_id' => $webapp['client_id'] ) );

		$html = $this->admin()->render();
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<script>alert(2)', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(2)&lt;/script&gt;', $html );
		$this->assertStringContainsString( 'name not checked', $html );

		add_filter(
			'wp_mail_content_type',
			function () {
				return 'text/html';
			}
		);
		do_action( Remote_MCP_Events::GRANT_ISSUED, $local['grant_id'], $this->user_id, $registered['client_id'], 'registered', 'code_exchange' );
		do_action( Remote_MCP_Events::GRANT_ISSUED, $hosted['grant_id'], $this->user_id, $webapp['client_id'], 'webapp', 'code_exchange' );
		$mails = $this->mails();
		$this->assertCount( 2, $mails );
		foreach ( $mails as $mail ) {
			$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $mail['subject'], 'no header injection through the subject' );
			$this->assertStringNotContainsString( '<img', $mail['body'] );
			$this->assertStringNotContainsString( '<script', $mail['body'] );
		}
		$this->assertCount( 1, $mails[0]['to'], 'the name adds no recipient' );
		$this->assertStringContainsString( 'name not checked', $mails[0]['body'] );
	}
	public function test_plain_mail_preserves_quotes_apostrophes_and_ampersands() {
		$name                                    = "\"AnythingLLM\" & owner's app";
		$this->known->entries[ self::CLIENT_ID ] = array( 'label' => $name );
		$connection                              = $this->connect();
		$this->notices->send_new_connection_notice( $connection['grant_id'], 'new' );
		$mail = $this->mails()[0];
		$this->assertSame( 'New AI app connected to MainWP: ' . $name, $mail['subject'] );
		$this->assertStringContainsString( $name, $mail['body'] );
		$this->assertStringNotContainsString( '&quot;', $mail['body'] );
	}

	public function test_plain_mail_strips_newlines_from_app_names() {
		$this->known->entries[ self::CLIENT_ID ] = array( 'label' => "A\r\nBcc: injected@example.org\tApp" );
		$connection                              = $this->connect();
		$this->notices->send_new_connection_notice( $connection['grant_id'], 'new' );
		$mail = $this->mails()[0];
		$this->assertSame( 'New AI app connected to MainWP: A Bcc: injected@example.org App', $mail['subject'] );
		$this->assertStringContainsString( 'A Bcc: injected@example.org App', $mail['body'] );
		$this->assertCount( 1, $mail['to'] );
	}
}
