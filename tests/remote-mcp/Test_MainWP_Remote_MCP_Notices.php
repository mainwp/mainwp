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
		$this->assertStringContainsString( 'Claude connected ', $html );
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
		$this->assertStringContainsString( 'name from the app', $html );

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

	/**
	 * Store panel notices, newest first.
	 *
	 * @param array $notices Notices.
	 */
	private function seed( array $notices ): void {
		update_option( Remote_MCP_Notices::OPTION_STATE, array( 'notices' => $notices ), false );
	}

	/**
	 * One stored notice.
	 *
	 * @param string $app      App name.
	 * @param int    $at       When.
	 * @param string $why      Event type.
	 * @param int    $grant_id Grant, 0 for a web app event.
	 * @return array
	 */
	private function event( string $app, int $at, string $why = 'new', int $grant_id = 0 ): array {
		return array(
			'grant_id' => $grant_id,
			'user_id'  => $this->user_id,
			'app'      => $app,
			'why'      => $why,
			'ip'       => '0' === (string) $grant_id ? '' : '198.51.100.7',
			'at'       => $at,
		);
	}

	/**
	 * The rendered notice message, or '' when there is none.
	 *
	 * @return string
	 */
	private function notice_message(): string {
		$html = $this->admin()->render();
		return 1 === preg_match( '#<div class="ui blue message" id="mainwp-rmcp-notices">.*?<a href="\#connections">Review</a>\s*</div>#s', $html, $match ) ? $match[0] : '';
	}

	/**
	 * Post dismiss_notices as the current user.
	 *
	 * @param mixed       $upto  Posted value.
	 * @param string|null $nonce Nonce, or null for a valid one.
	 * @return array
	 */
	private function dismiss( $upto, ?string $nonce = null ): array {
		return $this->post( 'dismiss_notices', '', array( 'upto' => (string) $upto ), $nonce );
	}

	public function test_recent_excludes_events_at_or_before_the_marker() {
		$now = time();
		$this->seed( array( $this->event( 'Newest', $now - 10 ), $this->event( 'Middle', $now - 20 ), $this->event( 'Oldest', $now - 30 ) ) );
		$this->assertSame( array( 'Newest', 'Middle', 'Oldest' ), array_column( $this->notices->recent(), 'app' ) );
		$this->assertSame( array( 'Newest' ), array_column( $this->notices->recent( $now - 20 ), 'app' ), 'an event at the marker is seen' );
		$this->assertSame( array(), $this->notices->recent( $now - 10 ) );
		$this->seed( array( $this->event( 'Expired', $now - Remote_MCP_Notices::NOTICE_TTL - 1 ) ) );
		$this->assertSame( array(), $this->notices->recent(), 'the week limit still applies' );
	}

	public function test_every_event_type_renders_when_newer_than_the_marker() {
		$live    = (int) $this->connect_write( $this->user_id )['grant_id'];
		$revoked = (int) $this->connect_write( $this->user_id )['grant_id'];
		$this->assertTrue( $this->store->revoke_grant( $revoked, 'user' ) );
		$now = time();
		$this->seed(
			array(
				$this->event( 'Zapier', $now - 10, 'client_secret_reissued' ),
				$this->event( 'Zapier', $now - 20, 'client_deleted' ),
				$this->event( 'Zapier', $now - 30, 'client_changed' ),
				$this->event( 'Zapier', $now - 40, 'client_added' ),
				$this->event( 'Connection token "Billing bot"', $now - 50, 'token', $live ),
				$this->event( 'Loosened app', $now - 60, 'loosened', $live ),
				$this->event( 'Revoked app', $now - 70, 'new', $revoked ),
				$this->event( 'Seen app', $now - 80, 'new', $live ),
			)
		);
		update_user_meta( $this->user_id, Remote_MCP_Notices::SEEN_META, $now - 80 );

		$message = $this->notice_message();
		$this->assertStringContainsString( '7 changes since you last checked', $message );
		$this->assertStringNotContainsString( 'Seen app', $message, 'acknowledged events stay hidden' );
		$this->assertSame( 5, substr_count( $message, '<li>' ), 'five lines' );
		$this->assertStringContainsString( 'And 2 more in the activity log.', $message );
		foreach ( array( 'Web app secret reissued: Zapier, ', 'Web app deleted: Zapier, ', 'Web app switched on or off: Zapier, ', 'Web app added: Zapier, ', 'Connection token &quot;Billing bot&quot; created ' ) as $line ) {
			$this->assertStringContainsString( $line, $message );
		}

		// Without the newer web app events, the three connection events fit without a count.
		$this->seed(
			array(
				$this->event( 'Connection token "Billing bot"', $now - 50, 'token', $live ),
				$this->event( 'Loosened app', $now - 60, 'loosened', $live ),
				$this->event( 'Revoked app', $now - 70, 'new', $revoked ),
				$this->event( 'Seen app', $now - 80, 'new', $live ),
			)
		);
		$message = $this->notice_message();
		$this->assertSame( 3, preg_match_all( '#<li>(.*?)</li>#s', $message, $items ) );
		$this->assertStringContainsString( 'Connection token &quot;Billing bot&quot; created ', $items[1][0] );
		$this->assertStringContainsString( ' from 198.51.100.7.', $items[1][0] );
		$this->assertStringContainsString( 'Loosened app now asks you less often (policy changed ', $items[1][1] );
		$this->assertStringContainsString( 'Revoked app connected ', $items[1][2] );
		$this->assertStringContainsString( '. (no longer connected)', $items[1][2], 'a revoked connection stays in the message, marked' );
		$this->assertStringNotContainsString( '(no longer connected)', $items[1][0] );
		$this->assertStringNotContainsString( 'more in the activity log', $message );
		$this->assertStringContainsString( '3 changes since you last checked', $message );
	}

	public function test_dismiss_stores_the_newest_shown_event_and_only_moves_forward() {
		$now = time();
		$this->seed( array( $this->event( 'Shown app', $now - 30 ) ) );
		$this->assertStringContainsString( 'Shown app', $this->notice_message() );

		$this->assertSame( 'bad_nonce', $this->dismiss( $now - 30, 'not-a-nonce' )['code'] );
		$this->assertSame( '', get_user_meta( $this->user_id, Remote_MCP_Notices::SEEN_META, true ), 'a bad nonce stores nothing' );

		$this->assertTrue( $this->dismiss( $now - 30 )['ok'] );
		$this->assertSame( $now - 30, (int) get_user_meta( $this->user_id, Remote_MCP_Notices::SEEN_META, true ) );
		$this->assertSame( '', $this->notice_message() );

		$this->assertTrue( $this->dismiss( $now - 3600 )['ok'] );
		$this->assertSame( $now - 30, (int) get_user_meta( $this->user_id, Remote_MCP_Notices::SEEN_META, true ), 'never backward' );
		$this->assertTrue( $this->dismiss( 'abc' )['ok'] );
		$this->assertSame( $now - 30, (int) get_user_meta( $this->user_id, Remote_MCP_Notices::SEEN_META, true ), 'not a number' );
		$this->assertTrue( $this->dismiss( $now + WEEK_IN_SECONDS )['ok'] );
		$this->assertLessThanOrEqual( time(), (int) get_user_meta( $this->user_id, Remote_MCP_Notices::SEEN_META, true ), 'a future time can\'t hide events to come' );

		// An event recorded after the page loaded stays unacknowledged by a dismissal from that page.
		delete_user_meta( $this->user_id, Remote_MCP_Notices::SEEN_META );
		$this->seed( array( $this->event( 'Arrived later', $now - 5 ), $this->event( 'Shown app', $now - 30 ) ) );
		$this->assertTrue( $this->dismiss( $now - 30 )['ok'] );
		$message = $this->notice_message();
		$this->assertStringContainsString( 'Arrived later', $message );
		$this->assertStringNotContainsString( 'Shown app', $message );
	}

	public function test_one_administrators_dismissal_leaves_anothers_message() {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->seed( array( $this->event( 'Claude', time() - 30 ) ) );
		$this->assertTrue( $this->dismiss( time() - 30 )['ok'] );
		$this->assertSame( '', $this->notice_message() );

		wp_set_current_user( $other );
		$this->assertStringContainsString( 'Claude connected ', $this->notice_message() );
		$this->assertSame( '', get_user_meta( $other, Remote_MCP_Notices::SEEN_META, true ) );
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
