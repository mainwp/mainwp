<?php
/**
 * Remote MCP approval page tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\MCP_Approval_Gate;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Approval_Page;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Response;

require_once __DIR__ . '/fixtures/approval-gate-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Approval_Page
 */
class Test_MainWP_MCP_Approval_Page extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures, Approval_Gate_Fixtures {
		Approval_Gate_Fixtures::set_up insteadof Remote_MCP_Store_Fixtures;
		Remote_MCP_Store_Fixtures::set_up as store_set_up;
		Approval_Gate_Fixtures::tear_down as gate_tear_down;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	const DASH = 'mainwp-test/delete-thing-v1';

	/**
	 * Server and cookie state before the test.
	 *
	 * @var array
	 */
	private $saved = array();

	public function tear_down() {
		$_SERVER = $this->saved['server'] ?? $_SERVER;
		$_COOKIE = $this->saved['cookie'] ?? $_COOKIE;
		$this->gate_tear_down();
	}

	/**
	 * A pending dashboard row for a test ability.
	 *
	 * @param array $o `ability`, `entry`, `args`, `ctx`.
	 * @return int Approval id.
	 */
	private function pending( array $o = array() ): int {
		if ( empty( $this->saved ) ) {
			$this->saved            = array(
				'server' => $_SERVER,
				'cookie' => $_COOKIE,
			);
			$_SERVER['HTTPS']       = 'on';
			$_SERVER['SERVER_PORT'] = '443';
		}
		$name = $o['ability'] ?? self::DASH;
		if ( ! wp_has_ability( $name ) ) {
			$this->ability(
				$name,
				array(
					'site_ids' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'secret'   => array( 'type' => 'string' ),
					'confirm'  => array( 'type' => 'boolean' ),
				)
			);
			$this->entry(
				$name,
				'dashboard',
				( $o['entry'] ?? array() ) + array(
					'resolver' => 'sites',
					'adapter'  => 'atomic',
				)
			);
		}
		$ctx = $o['ctx'] ?? $this->ctx();
		return (int) $this->call( $ctx, $name, $o['args'] ?? array( 'site_ids' => array( 3 ) ) )['decision']['approval_id'];
	}

	/**
	 * Log a user in through the logged-in cookie only; no current user is set.
	 *
	 * @param int $user_id User.
	 */
	private function login( int $user_id ): void {
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		wp_set_current_user( 0 );
	}

	/**
	 * Log out.
	 */
	private function logout(): void {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
	}

	/**
	 * GET the page.
	 *
	 * @param int    $id    Approval.
	 * @param string $extra Extra query.
	 * @return Remote_MCP_Response
	 */
	private function get( int $id, string $extra = '' ): Remote_MCP_Response {
		$page = new Remote_MCP_Approval_Page( $this->services );
		return $page->handle( new Remote_MCP_Request( 'route_approve', 'GET', 'approval=' . $id . $extra, '', $_SERVER ) );
	}

	/**
	 * POST a decision.
	 *
	 * @param int    $id       Approval.
	 * @param string $decision `approve`, `deny` or `run`.
	 * @param string $nonce    Nonce.
	 * @return Remote_MCP_Response
	 */
	private function post( int $id, string $decision, string $nonce ): Remote_MCP_Response {
		$page = new Remote_MCP_Approval_Page( $this->services );
		$body = http_build_query(
			array(
				'approval' => $id,
				'decision' => $decision,
				'_wpnonce' => $nonce,
			)
		);
		return $page->handle( new Remote_MCP_Request( 'route_approve', 'POST', 'approval=' . $id, $body, $_SERVER ) );
	}

	/**
	 * The nonce a form on the rendered page carries.
	 *
	 * @param Remote_MCP_Response $response Page.
	 * @param string              $decision Decision.
	 * @return string|null
	 */
	private function form_nonce( Remote_MCP_Response $response, string $decision ): ?string {
		if ( preg_match( '/name="decision" value="' . $decision . '"[^>]*>\s*<input type="hidden" name="_wpnonce" value="([^"]+)"/', $response->body(), $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * A nonce for any row and decision, as the logged-in cookie user.
	 *
	 * @param int    $id       Approval.
	 * @param string $decision Decision.
	 * @return string
	 */
	private function nonce_for( int $id, string $decision ): string {
		wp_set_current_user( (int) wp_validate_auth_cookie( '', 'logged_in' ) );
		$nonce = wp_create_nonce( Remote_MCP_Approval_Page::nonce_action( $id, $decision ) );
		wp_set_current_user( 0 );
		return $nonce;
	}

	/**
	 * Frame and cache headers every page response carries.
	 *
	 * @param Remote_MCP_Response $response Response.
	 */
	private function assert_locked_down( Remote_MCP_Response $response ): void {
		$this->assertSame( 'DENY', $response->header( 'X-Frame-Options' ) );
		$csp = (string) $response->header( 'Content-Security-Policy' );
		$this->assertStringContainsString( "frame-ancestors 'none'", $csp );
		$this->assertStringContainsString( "default-src 'self'", $csp );
		$this->assertStringContainsString( "style-src 'self'", $csp );
		$this->assertStringContainsString( "form-action 'self'", $csp );
		$this->assertStringContainsString( 'no-store', (string) $response->header( 'Cache-Control' ) );
	}

	public function test_approval_page_refuses_other_user() {
		$id    = $this->pending(
			array(
				'args' => array(
					'site_ids' => array( 3 ),
					'secret'   => 'n',
				),
			)
		);
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->logout();
		$redirect = $this->get( $id );
		$this->assertSame( 302, $redirect->status() );
		$this->assertSame( wp_login_url( 'https://dash.example.org/mainwp-oauth/approve?approval=' . $id ), $redirect->header( 'Location' ) );

		$this->login( $other );
		$page = $this->get( $id );
		$this->assertSame( 403, $page->status() );
		$this->assertStringNotContainsString( 'site_ids', $page->body() );
		$this->assertStringNotContainsString( 'name="decision"', $page->body() );
		$this->post( $id, 'approve', $this->nonce_for( $id, 'approve' ) );
		$this->post( $id, 'deny', $this->nonce_for( $id, 'deny' ) );
		$this->assertSame( 'pending', $this->store->get_approval( $id )['status'] );

		$this->login( $this->user_id );
		$this->assertSame( 200, $this->get( $id )->status() );
	}

	public function test_approval_page_nonce_bound_to_row() {
		$a = $this->pending();
		$b = $this->pending( array( 'args' => array( 'site_ids' => array( 4 ) ) ) );
		$c = $this->pending( array( 'args' => array( 'site_ids' => array( 5 ) ) ) );
		$this->login( $this->user_id );

		// A GET never decides, whatever its query says.
		$this->get( $a, '&decision=approve&_wpnonce=' . $this->nonce_for( $a, 'approve' ) );
		$this->assertSame( 'pending', $this->store->get_approval( $a )['status'] );

		$this->post( $a, 'approve', $this->nonce_for( $b, 'approve' ) );
		$this->post( $a, 'approve', $this->nonce_for( $a, 'deny' ) );
		$this->post( $a, 'approve', 'not-a-nonce' );
		$this->assertSame( 'pending', $this->store->get_approval( $a )['status'] );

		$nonce = $this->form_nonce( $this->get( $a ), 'approve' );
		$this->assertNotNull( $nonce, 'the page carries the approve form' );
		$this->post( $a, 'approve', $nonce );
		$this->assertSame( 'approved', $this->store->get_approval( $a )['status'] );

		$this->post( $a, 'run', $this->nonce_for( $b, 'run' ) );
		$this->post( $a, 'run', $this->nonce_for( $a, 'approve' ) );
		$this->assertSame( array(), $this->executor->calls );
		$this->get( $a, '&decision=run&_wpnonce=' . $this->nonce_for( $a, 'run' ) );
		$this->assertSame( array(), $this->executor->calls, 'a GET never runs anything' );
		$run = $this->form_nonce( $this->get( $a ), 'run' );
		$this->assertNotNull( $run, 'Run it here instead appears after Approve' );
		$this->assertStringContainsString( '<dl class="mainwp-approval-arguments">', $this->get( $a )->body(), 'the details stay while Run it here is offered' );
		$this->post( $a, 'run', $run );
		$this->assertCount( 1, $this->executor->calls );

		$this->post( $c, 'deny', $this->nonce_for( $b, 'deny' ) );
		$this->assertSame( 'pending', $this->store->get_approval( $c )['status'] );
		$this->post( $c, 'deny', (string) $this->form_nonce( $this->get( $c ), 'deny' ) );
		$this->assertSame( 'denied', $this->store->get_approval( $c )['status'] );

		// Another user's nonce for the same row doesn't work either.
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->login( $other );
		$theirs = $this->nonce_for( $b, 'approve' );
		$this->login( $this->user_id );
		$this->post( $b, 'approve', $theirs );
		$this->assertSame( 'pending', $this->store->get_approval( $b )['status'] );
	}

	public function test_approval_page_is_frame_denied() {
		$id = $this->pending();
		$this->logout();
		$this->assert_locked_down( $this->get( $id ) );
		$this->login( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assert_locked_down( $this->get( $id ) );
		$this->login( $this->user_id );
		$page = $this->get( $id );
		$this->assert_locked_down( $page );
		$this->assertSame( 200, $page->status() );
		$this->assert_locked_down( $this->post( $id, 'approve', (string) $this->form_nonce( $page, 'approve' ) ) );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	public function test_approval_page_counts_unlisted_sites_in_singular_and_plural() {
		// The page lists 50 sites and counts the rest.
		$one = $this->pending( array( 'args' => array( 'site_ids' => range( 1, 51 ) ) ) );
		$two = $this->pending( array( 'args' => array( 'site_ids' => range( 1, 52 ) ) ) );
		$this->login( $this->user_id );
		$this->assertMatchesRegularExpression( '#<p>\s*and 1 more site\s*</p>#', $this->get( $one )->body() );
		$this->assertMatchesRegularExpression( '#<p>\s*and 2 more sites\s*</p>#', $this->get( $two )->body() );
	}

	public function test_backup_refusals_tell_the_user_why_on_the_page() {
		$notice = new \ReflectionMethod( Remote_MCP_Approval_Page::class, 'notice' );
		$notice->setAccessible( true );
		foreach ( array( 'backup_task_groups', 'backup_task_domains', 'backup_task_update', 'backup_preview_capped' ) as $reason ) {
			$this->assertSame(
				'Nothing ran. ' . MCP_Approval_Gate::refusal_text( $reason ),
				$notice->invoke(
					null,
					array(
						'outcome' => 'refused',
						'reason'  => $reason,
					)
				),
				$reason
			);
		}
	}

	public function test_failed_preview_on_approve_shows_a_known_reason_and_keeps_the_row_pending() {
		$answers = array(
			'mainwp-test/preview-known-v1'   => array( 'mainwp_backup_child_update_required', 'The preview for this action failed, so nothing was run. Update MainWP Child first.' ),
			'mainwp-test/preview-unknown-v1' => array( 'provider_said_no', 'Nothing ran. This approval can no longer be used this way.' ),
		);
		foreach ( $answers as $name => $answer ) {
			$this->ability(
				$name,
				array(
					'site_ids' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'dry_run'  => array( 'type' => 'boolean' ),
					'confirm'  => array( 'type' => 'boolean' ),
				),
				array(
					'result' => function () use ( $answer ) {
						return new \WP_Error( $answer[0], 'Update MainWP Child first.' );
					},
				)
			);
			$this->entry(
				$name,
				'dashboard',
				array(
					'resolver' => 'sites',
					'adapter'  => 'atomic',
				)
			);
			$id = $this->pending( array( 'ability' => $name ) );
			$this->login( $this->user_id );
			$page = $this->post( $id, 'approve', (string) $this->form_nonce( $this->get( $id ), 'approve' ) );
			$this->assertStringContainsString( '<p class="mainwp-approval-notice">' . $answer[1] . '</p>', $page->body(), $name );
			$this->assertSame( 'pending', $this->store->get_approval( $id )['status'], $name );
		}
	}

	public function test_run_from_page_consumes_row_and_retry_returns_result() {
		$ctx  = $this->ctx();
		$args = array( 'site_ids' => array( 3 ) );
		$id   = $this->pending(
			array(
				'ctx'  => $ctx,
				'args' => $args,
			)
		);
		$this->login( $this->user_id );
		$this->post( $id, 'approve', (string) $this->form_nonce( $this->get( $id ), 'approve' ) );
		$frozen   = $this->store->approval_call_plan( $this->store->get_approval( $id ) );
		$resolved = count( $this->resolver->calls );

		$this->post( $id, 'run', (string) $this->form_nonce( $this->get( $id ), 'run' ) );
		$this->assertCount( 1, $this->executor->calls );
		$this->assertTrue( $this->executor->calls[0]['plan']->equals( $frozen ), 'exactly the frozen plan shown' );
		$this->assertSame( $ctx->grant_id, $this->executor->calls[0]['grant_id'], 'the row\'s grant' );
		$this->assertSame( $this->user_id, $this->executor->calls[0]['user_id'] );
		$this->assertSame( $resolved, count( $this->resolver->calls ), 'no target re-resolution for site entries' );
		$row = $this->store->get_approval( $id );
		$this->assertSame( 'consumed', $row['status'] );
		$this->assertNotEmpty( $row['result_summary'] );
		$this->assertNotEmpty( $this->audited( 'run_from_page' ) );

		$retry = $this->call( $ctx, self::DASH, $args );
		$this->assertFalse( $retry['result']['isError'] );
		$this->assertStringContainsString( 'Already run from the Dashboard', $retry['result']['content'][0]['text'] );
		$this->assertCount( 1, $this->executor->calls );

		// A multi-entry plan goes to the job runner still approved.
		$many                        = 'mainwp-test/delete-many-v1';
		$this->adapter->map[ $many ] = function ( $a, $targets ) use ( $many ) {
			$entries = array();
			foreach ( $targets['site_ids'] as $site ) {
				$entries[] = array(
					'ability'    => $many,
					'args'       => array( 'site_ids' => array( $site ) ),
					'target_ids' => array( $site ),
				);
			}
			return Call_Plan::from_entries( $entries );
		};
		$job                         = $this->pending(
			array(
				'ability' => $many,
				'args'    => array( 'site_ids' => array( 3, 4 ) ),
				'ctx'     => $ctx,
				'entry'   => array( 'adapter' => 'chunked_sites' ),
			)
		);
		$this->post( $job, 'approve', (string) $this->form_nonce( $this->get( $job ), 'approve' ) );
		$this->post( $job, 'run', (string) $this->form_nonce( $this->get( $job ), 'run' ) );
		$this->assertCount( 1, $this->jobs->calls );
		$this->assertSame( 'approved', $this->jobs->calls[0]['status'] );
		$this->assertSame( 'consumed', $this->store->get_approval( $job )['status'] );
	}

	public function test_run_from_page_not_offered_for_credential_abilities() {
		$name = 'mainwp-test/replace-ext-credential-v1';
		$this->ability(
			$name,
			array(
				'secret_value' => array( 'type' => 'string' ),
				'label'        => array( 'type' => 'string' ),
			)
		);
		$this->entry(
			$name,
			'dashboard',
			array(
				'source'             => 'audit',
				'credential_bearing' => true,
			)
		);
		$id = $this->pending(
			array(
				'ability' => $name,
				'args'    => array( 'secret_value' => 'PAGE-SECRET' ),
			)
		);
		$this->login( $this->user_id );
		$page = $this->get( $id );
		$this->assertStringNotContainsString( 'PAGE-SECRET', $page->body() );
		$this->post( $id, 'approve', (string) $this->form_nonce( $page, 'approve' ) );
		$this->assertSame( 'approved', $this->store->get_approval( $id )['status'] );
		$after = $this->get( $id );
		$this->assertNull( $this->form_nonce( $after, 'run' ), 'no Run it here instead' );
		$this->assertStringContainsString( 'so it checks them again when ', $after->body() );
		$this->post( $id, 'run', $this->nonce_for( $id, 'run' ) );
		$this->assertSame( array(), $this->ran( $name ) );
		$this->assertSame( 'approved', $this->store->get_approval( $id )['status'] );
	}

	public function test_custom_dashboard_snippet_css_and_js_never_load_on_approval_page() {
		$id = $this->pending();
		foreach ( array( 'wp_head', 'admin_head', 'admin_footer', 'wp_footer', 'admin_print_styles', 'admin_print_scripts' ) as $hook ) {
			add_action(
				$hook,
				function () {
					echo '<style>/* SNIPPET-CSS */</style><script>/* SNIPPET-JS */</script>';
				}
			);
		}
		add_action(
			'admin_enqueue_scripts',
			function () {
				wp_enqueue_script( 'snippet-js', 'https://dash.example.org/snippet-evil.js', array(), '1', false );
				wp_enqueue_style( 'snippet-css', 'https://dash.example.org/snippet-evil.css', array(), '1' );
			}
		);
		wp_enqueue_script( 'snippet-js-2', 'https://dash.example.org/snippet-evil-2.js', array(), '1', false );
		wp_enqueue_style( 'snippet-css-2', 'https://dash.example.org/snippet-evil-2.css', array(), '1' );

		$this->login( $this->user_id );
		$body = $this->get( $id )->body();
		$this->assertStringNotContainsString( 'SNIPPET-', $body );
		$this->assertStringNotContainsString( 'snippet-evil', $body );
		$this->assertStringNotContainsString( '<script', $body );
		$this->assertStringContainsString( 'modules/remote-mcp/assets/remote-mcp-pages.css', $body, 'only the module\'s own stylesheet' );
		$this->assertSame( 1, preg_match_all( '/<link /', $body ) );
	}

	public function test_bearer_api_key_or_basic_auth_never_authenticate_approval_page() {
		$id = $this->pending();
		$this->logout();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer mwpat_' . str_repeat( 'a', 43 );
		$_SERVER['PHP_AUTH_USER']      = 'admin';
		$_SERVER['PHP_AUTH_PW']        = 'app password';
		// As if another plugin authenticated the request before the page ran.
		wp_set_current_user( $this->user_id );
		$nonce = wp_create_nonce( Remote_MCP_Approval_Page::nonce_action( $id, 'approve' ) );

		$page = new Remote_MCP_Approval_Page( $this->services );
		$get  = $page->handle( new Remote_MCP_Request( 'route_approve', 'GET', 'approval=' . $id . '&consumer_key=ck_x&consumer_secret=cs_x', '', $_SERVER ) );
		$this->assertSame( 302, $get->status() );
		$post = $page->handle( new Remote_MCP_Request( 'route_approve', 'POST', 'approval=' . $id . '&consumer_key=ck_x', 'approval=' . $id . '&decision=approve&_wpnonce=' . $nonce, $_SERVER ) );
		$this->assertSame( 302, $post->status() );
		$this->assertSame( 'pending', $this->store->get_approval( $id )['status'] );
		$this->assertSame( array(), $this->executor->calls );
	}

	public function test_approval_page_informs_then_cautions_and_drops_the_caution_after_a_decision() {
		$id = $this->pending( array( 'args' => array( 'site_ids' => array( 3 ) ) ) );
		$this->login( $this->user_id );
		$user = get_userdata( $this->user_id );
		$page = $this->get( $id )->body();

		$this->assertStringContainsString( '<title>Approve an action from Claude</title>', preg_replace( '#<title>\s*(.*?)\s*</title>#s', '<title>$1</title>', $page ) );
		$this->assertStringContainsString( '<h1>Claude wants to run an action</h1>', $page );
		$this->assertMatchesRegularExpression( '#<p class="mainwp-oauth-eyebrow">\s*MainWP Dashboard · dash\.example\.org\s*</p>#u', $page );
		$this->assertMatchesRegularExpression( '#Connected on [^<]+ as ' . preg_quote( esc_html( $user->display_name ), '#' ) . '\.#', $page );
		$this->assertStringContainsString( '<dl class="mainwp-approval-arguments">', $page, 'the arguments while it waits' );
		$this->assertMatchesRegularExpression( '#<p class="mainwp-approval-caution">\s*Only approve if you just asked Claude to do this\.\s*</p>\s*<div class="mainwp-approval-actions">#', $page, 'the caution sits right above the buttons' );
		$this->assertLessThan( strpos( $page, 'mainwp-approval-caution' ), strpos( $page, 'mainwp-approval-arguments' ), 'facts before the caution' );

		$denied = $this->post( $id, 'deny', (string) $this->form_nonce( $this->get( $id ), 'deny' ) )->body();
		$this->assertSame( 1, substr_count( $denied, 'Denied. Nothing was changed.' ), 'the outcome once' );
		$this->assertStringNotContainsString( 'mainwp-approval-caution', $denied );
		$this->assertStringNotContainsString( 'mainwp-approval-arguments', $denied );
		$this->assertStringNotContainsString( 'has been used or has expired', $denied, 'no second status line under the notice' );
		$this->assertStringNotContainsString( 'name="decision"', $denied );
		$this->assertStringContainsString( '<h1>Claude wants to run an action</h1>', $denied, 'the app line and action stay' );
		$this->assertStringContainsString( 'Back to your Dashboard', $denied );
		$this->assertStringContainsString( 'This approval has been used or has expired. Ask Claude to start again if you still want this.', $this->get( $id )->body(), 'a later visit says why nothing is offered' );

		$approved = $this->pending( array( 'args' => array( 'site_ids' => array( 4 ) ) ) );
		$after    = $this->post( $approved, 'approve', (string) $this->form_nonce( $this->get( $approved ), 'approve' ) )->body();
		$this->assertSame( 1, substr_count( $after, 'Go back to Claude and ask it to try again.' ), 'the approved outcome once' );
		$this->assertStringNotContainsString( 'mainwp-approval-caution', $after );
		$this->assertStringContainsString( 'You approved this. Go back to Claude and ask it to try again.', $this->get( $approved )->body() );
	}

	public function test_approval_page_preview_is_collapsed() {
		$id = $this->pending();
		$this->login( $this->user_id );
		$row                = $this->store->get_approval( $id );
		$summary            = json_decode( (string) $row['summary'], true );
		$summary['preview'] = array( 'changes' => 2 );
		global $wpdb;
		$wpdb->update( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::table( 'approvals' ), array( 'summary' => wp_json_encode( $summary ) ), array( 'id' => $id ) );
		$this->assertMatchesRegularExpression( '#<details class="mainwp-approval-details">\s*<summary>Preview</summary>\s*<pre class="mainwp-approval-preview">#', $this->get( $id )->body() );
	}
}
