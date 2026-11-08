<?php
/**
 * Shared support for the metadata, authorization and consent tests: a store with a
 * hook between the consent row read and its claim, a services registry that hands out that store,
 * client and catalog doubles, and request helpers that drive the real route handlers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\Known_Clients;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Context;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Response;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;
use MainWP\Dashboard\Remote_MCP\Tool_Executor;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase, Generic.Files.OneObjectStructurePerFile.MultipleFound

if ( class_exists( Remote_MCP_Test_Store::class ) ) {

	/**
	 * The real store, with a hook that runs once between the consent POST's row read and its
	 * claim, so a test can change the row in that window.
	 */
	class OAuth_Consent_Test_Store extends Remote_MCP_Test_Store {

		/**
		 * Called once with the row the next time find_request() finds one, before it returns.
		 *
		 * @var callable|null
		 */
		public $on_request_read = null;

		/**
		 * The store's lookup, then the pending hook.
		 *
		 * @param string $request_key Request key.
		 * @return array|null
		 */
		public function find_request( string $request_key ): ?array {
			$row = parent::find_request( $request_key );
			if ( null !== $row && null !== $this->on_request_read ) {
				$callback              = $this->on_request_read;
				$this->on_request_read = null;
				$callback( $row );
			}
			return $row;
		}
	}

	/**
	 * Registry whose store is the test's store.
	 */
	class OAuth_Authorize_Test_Services extends Remote_MCP_Services {

		/**
		 * Store handed to every handler.
		 *
		 * @var OAuth_Store|null
		 */
		public $test_store;

		/**
		 * The test store.
		 *
		 * @return OAuth_Store|null
		 */
		public function store(): ?OAuth_Store {
			return $this->test_store;
		}
	}

	/**
	 * Known_Clients double: exact-id descriptors, every lookup recorded, and a redirect answer the
	 * test controls (allowed by default, so the authorize endpoint's own rules are what a test sees).
	 */
	class OAuth_Known_Clients_Double implements Known_Clients {

		/**
		 * Descriptors by exact client_id.
		 *
		 * @var array
		 */
		public $clients = array();

		/**
		 * Every client_id looked up, in order.
		 *
		 * @var array
		 */
		public $lookups = array();

		/**
		 * What redirect_allowed() answers.
		 *
		 * @var bool
		 */
		public $allow_redirects = true;

		/**
		 * Descriptor with defaults.
		 *
		 * @param array $fields Fields.
		 * @return array
		 */
		public static function descriptor( array $fields ): array {
			return array_merge(
				array(
					'client_id'                   => '',
					'kind'                        => 'bundled',
					'label'                       => '',
					'client_name'                 => null,
					'redirect_rule'               => 'loopback_any_port',
					'redirect_uris'               => array(),
					'token_endpoint_auth_methods' => array( 'none' ),
					'oauth_endpoints'             => true,
					'approval_delivery'           => 'result',
					'enabled'                     => true,
					'step_up'                     => 'result',
				),
				$fields
			);
		}

		/**
		 * Add a client.
		 *
		 * @param array $fields Descriptor fields.
		 */
		public function add( array $fields ): void {
			$descriptor                                = self::descriptor( $fields );
			$this->clients[ $descriptor['client_id'] ] = $descriptor;
		}

		/**
		 * Descriptor for an exact id.
		 *
		 * @param string $client_id Client id.
		 * @return array|null
		 */
		public function lookup( string $client_id ): ?array {
			$this->lookups[] = $client_id;
			foreach ( $this->clients as $id => $descriptor ) {
				if ( (string) $id === $client_id ) {
					return $descriptor;
				}
			}
			return null;
		}

		/**
		 * Controlled answer.
		 *
		 * @param array  $client       Descriptor.
		 * @param string $redirect_uri Redirect.
		 * @return bool
		 */
		public function redirect_allowed( array $client, string $redirect_uri ): bool {
			return $this->allow_redirects;
		}

		/**
		 * Not used here.
		 *
		 * @param string $kind         Kind.
		 * @param string $redirect_uri Redirect.
		 * @return bool
		 */
		public function validate_redirect( string $kind, string $redirect_uri ): bool {
			return false;
		}
	}

	/**
	 * Tool_Executor double that only answers catalog().
	 */
	class OAuth_Catalog_Double implements Tool_Executor {

		/**
		 * Catalog rows.
		 *
		 * @var array
		 */
		public $rows = array();

		/**
		 * Constructor.
		 *
		 * @param array $rows Catalog rows.
		 */
		public function __construct( array $rows = array() ) {
			$this->rows = $rows;
		}

		/**
		 * Unused.
		 *
		 * @param Remote_MCP_Context $context Context.
		 * @return array
		 */
		public function list_tools( Remote_MCP_Context $context ): array {
			return array();
		}

		/**
		 * Rows.
		 *
		 * @return array
		 */
		public function catalog(): array {
			return $this->rows;
		}

		/**
		 * Unused.
		 *
		 * @param Remote_MCP_Context $context   Context.
		 * @param string             $name      Tool.
		 * @param array              $arguments Arguments.
		 * @return array
		 */
		public function call_tool( Remote_MCP_Context $context, string $name, array $arguments ): array {
			return array( 'isError' => true );
		}

		/**
		 * Unused.
		 *
		 * @param Call_Plan $plan     Plan.
		 * @param int       $index    Entry.
		 * @param int       $user_id  User.
		 * @param int       $grant_id Grant.
		 * @return array
		 */
		public function execute_plan_entry( Call_Plan $plan, int $index, int $user_id, int $grant_id ): array {
			return array(
				'ok'     => false,
				'reason' => 'not_available',
				'result' => null,
				'queued' => false,
			);
		}
	}

}

/**
 * Base for the metadata, authorization and consent tests: an enabled connector on
 * https://dash.example.com with pretty permalinks, real module tables, a logged-in administrator,
 * the known-client double holding one client of every kind, and helpers that call the real route
 * handlers through the registry.
 */
abstract class OAuth_Authorize_Test_Case extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures {
		set_up as store_set_up;
	}

	const HOME         = 'https://dash.example.com';
	const RESOURCE     = 'https://dash.example.com/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID    = 'https://claude.ai/oauth/claude-code-client-metadata';
	const REDIRECT     = 'http://127.0.0.1:53682/callback';
	const VERIFIER     = 'verifier-0123456789-0123456789-0123456789-0123456789';
	const CLAUDE_AI    = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const CLAUDE_AI_CB = 'https://claude.ai/api/mcp/auth_callback';
	const CURSOR       = 'mainwp-cursor';
	const GEMINI       = 'mainwp-gemini-cli';
	const REGISTERED   = 'mwplc_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const WEBAPP       = 'mwpwa_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
	const WEBAPP_CB    = 'https://chat.example.net/oauth/callback';

	/**
	 * Known-client double.
	 *
	 * @var OAuth_Known_Clients_Double
	 */
	protected $clients;

	/**
	 * Catalog double.
	 *
	 * @var OAuth_Catalog_Double
	 */
	protected $catalog;

	/**
	 * Registry, rebuilt when the home or permalinks change.
	 *
	 * @var OAuth_Authorize_Test_Services|null
	 */
	protected $services_instance;

	/**
	 * $_SERVER before the test.
	 *
	 * @var array
	 */
	protected $saved_server;

	/**
	 * Audit events fired during the test, as [ event, fields ].
	 *
	 * @var array
	 */
	protected $audits = array();

	/**
	 * Forget an earlier test's page-cache request, so the next handler has to make its own.
	 */
	protected function reset_page_cache_flag() {
		$flag = new \ReflectionProperty( \MainWP\Dashboard\Remote_MCP\OAuth_Metadata::class, 'page_cache_disabled' );
		$flag->setAccessible( true );
		$flag->setValue( null, false );
	}

	/**
	 * Enabled connector, tables, user and doubles.
	 */
	public function set_up() {
		$this->store_set_up();
		$this->saved_server = $_SERVER;
		update_option( 'home', self::HOME );
		update_option( 'siteurl', self::HOME );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		delete_option( Remote_MCP_Settings::OPTION_COUNTERS );
		delete_option( Remote_MCP_Settings::OPTION_REGISTRATION_ENABLED );
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['SERVER_PORT'] = '443';
		$_SERVER['HTTP_HOST']   = 'dash.example.com';

		$this->clients = new OAuth_Known_Clients_Double();
		$this->clients->add(
			array(
				'client_id'         => self::CLIENT_ID,
				'label'             => 'Claude Code',
				'client_name'       => 'Claude Code',
				'redirect_uris'     => array( 'http://localhost/callback', 'http://127.0.0.1/callback' ),
				'approval_delivery' => 'elicitation',
				'step_up'           => 'challenge',
			)
		);
		$this->clients->add(
			array(
				'client_id'     => self::CLAUDE_AI,
				'label'         => 'Claude',
				'client_name'   => 'Claude',
				'redirect_uris' => array( self::CLAUDE_AI_CB ),
			)
		);
		$this->clients->add(
			array(
				'client_id'     => self::CURSOR,
				'kind'          => 'static',
				'label'         => "Cursor, or another program using Cursor's settings",
				'redirect_rule' => 'cursor_exact',
				'redirect_uris' => array( 'http://localhost:8787/callback', 'https://www.cursor.com/agents/mcp/oauth/callback' ),
			)
		);
		$this->clients->add(
			array(
				'client_id'     => self::GEMINI,
				'kind'          => 'static',
				'label'         => "Gemini CLI, or another program on your computer using Gemini CLI's settings",
				'redirect_rule' => 'gemini_pattern',
			)
		);
		$this->clients->add(
			array(
				'client_id'     => self::REGISTERED,
				'kind'          => 'registered',
				'client_name'   => 'Desk Helper',
				'redirect_rule' => 'registered_loopback',
				'redirect_uris' => array( 'http://127.0.0.1/cb' ),
			)
		);
		$this->clients->add(
			array(
				'client_id'                   => self::WEBAPP,
				'kind'                        => 'webapp',
				'client_name'                 => 'Team Chat',
				'redirect_rule'               => 'exact',
				'redirect_uris'               => array( self::WEBAPP_CB ),
				'token_endpoint_auth_methods' => array( 'client_secret_basic', 'client_secret_post' ),
			)
		);
		$this->clients->add(
			array(
				'client_id'       => OAuth_Store::CONNECTION_TOKEN_CLIENT_ID,
				'kind'            => 'token',
				'oauth_endpoints' => false,
			)
		);
		$this->catalog           = new OAuth_Catalog_Double(
			array(
				$this->catalog_row( 'list_sites_v1', 'mainwp-sites', 'read' ),
				$this->catalog_row( 'delete_site_v1', 'mainwp-sites', 'dashboard' ),
				$this->catalog_row( 'list_updates_v1', 'mainwp-updates', 'read' ),
				$this->catalog_row( 'run_updates_v1', 'mainwp-updates', 'confirm' ),
			)
		);
		$this->services_instance = null;
		$this->audits            = array();
		add_action(
			'mainwp_remote_mcp_audit',
			function ( $event, $fields ) {
				$this->audits[] = array( $event, $fields );
			},
			10,
			2
		);
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Restore $_SERVER.
	 */
	public function tear_down() {
		if ( is_array( $this->saved_server ) ) {
			$_SERVER = $this->saved_server;
		}
		parent::tear_down();
	}

	/**
	 * Store with the consent read hook.
	 *
	 * @param string $resource Canonical resource.
	 * @return Remote_MCP_Test_Store
	 */
	protected function new_store( string $resource = self::RESOURCE ): Remote_MCP_Test_Store {
		return new OAuth_Consent_Test_Store( $this->hasher, $resource, $this->clock );
	}

	/**
	 * Catalog row.
	 *
	 * @param string      $tool     Tool name.
	 * @param string      $category Category.
	 * @param string|null $tier     Standard tier.
	 * @param string      $state    Availability.
	 * @return array
	 */
	protected function catalog_row( string $tool, string $category, ?string $tier, string $state = 'available' ): array {
		return array(
			'tool_name'     => $tool,
			'ability'       => 'mainwp/' . str_replace( '_', '-', $tool ),
			'category'      => $category,
			'standard_tier' => $tier,
			'availability'  => $state,
			'reason'        => null,
		);
	}

	/**
	 * The registry for the current options.
	 *
	 * @return OAuth_Authorize_Test_Services
	 */
	protected function services(): OAuth_Authorize_Test_Services {
		if ( null === $this->services_instance ) {
			$this->services_instance             = new OAuth_Authorize_Test_Services(
				array(
					'known_clients' => $this->clients,
					'tool_executor' => $this->catalog,
				),
				new Remote_MCP_Urls()
			);
			$this->services_instance->test_store = $this->store;
		}
		return $this->services_instance;
	}

	/**
	 * Change an option the URLs depend on and rebuild the registry.
	 *
	 * @param string $name  Option.
	 * @param string $value Value.
	 */
	protected function set_site_option( string $name, string $value ): void {
		update_option( $name, $value );
		$this->services_instance = null;
	}

	/**
	 * Valid authorization parameters; a null override removes the parameter.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	protected function auth_params( array $overrides = array() ): array {
		$params = array_merge(
			array(
				'response_type'         => 'code',
				'client_id'             => self::CLIENT_ID,
				'redirect_uri'          => self::REDIRECT,
				'scope'                 => 'mainwp:read mainwp:write offline_access',
				'state'                 => 'client-state-123',
				'code_challenge'        => $this->challenge( self::VERIFIER ),
				'code_challenge_method' => 'S256',
				'resource'              => self::RESOURCE,
			),
			$overrides
		);
		return array_filter(
			$params,
			function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Raw query string, every name and value percent-encoded.
	 *
	 * @param array $params Parameters.
	 * @return string
	 */
	protected function query( array $params ): string {
		$pairs = array();
		foreach ( $params as $name => $value ) {
			$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $pairs );
	}

	/**
	 * A route handler from the registry.
	 *
	 * @param string $slot Slot.
	 * @return \MainWP\Dashboard\Remote_MCP\Route_Handler
	 */
	protected function handler( string $slot = 'route_authorize' ) {
		return $this->services()->get( $slot );
	}

	/**
	 * Authorization GET with a raw query string.
	 *
	 * @param string $query Raw query.
	 * @return Remote_MCP_Response
	 */
	protected function get_raw( string $query ): Remote_MCP_Response {
		return $this->handler()->handle( new Remote_MCP_Request( 'route_authorize', 'GET', $query, '', $_SERVER ) );
	}

	/**
	 * Authorization GET.
	 *
	 * @param array $overrides Parameter overrides.
	 * @return Remote_MCP_Response
	 */
	protected function authorize( array $overrides = array() ): Remote_MCP_Response {
		return $this->get_raw( $this->query( $this->auth_params( $overrides ) ) );
	}

	/**
	 * Consent POST with a raw form body.
	 *
	 * @param string $body Raw body.
	 * @return Remote_MCP_Response
	 */
	protected function post_raw( string $body ): Remote_MCP_Response {
		return $this->handler()->handle( new Remote_MCP_Request( 'route_authorize', 'POST', '', $body, $_SERVER ) );
	}

	/**
	 * Render consent and return what the form carries.
	 *
	 * @param array $overrides Parameter overrides.
	 * @return array `response`, `request_key`, `nonce`, `body`.
	 */
	protected function consent( array $overrides = array() ): array {
		$response = $this->authorize( $overrides );
		$this->assertSame( 200, $response->status(), 'consent rendered: ' . $response->body() );
		$body = $response->body();
		$this->assertSame( 1, preg_match( '/name="request_key" value="([^"]*)"/', $body, $key ), 'request_key field' );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]*)"/', $body, $nonce ), 'nonce field' );
		return array(
			'response'    => $response,
			'request_key' => html_entity_decode( $key[1] ),
			'nonce'       => html_entity_decode( $nonce[1] ),
			'body'        => $body,
		);
	}

	/**
	 * Decide a consent form.
	 *
	 * @param array $consent  From consent().
	 * @param array $fields   Field overrides; null removes.
	 * @return Remote_MCP_Response
	 */
	protected function decide( array $consent, array $fields = array() ): Remote_MCP_Response {
		$fields = array_merge(
			array(
				'request_key' => $consent['request_key'],
				'decision'    => 'approve',
				'access'      => 'write',
				'policy'      => 'standard',
				'_wpnonce'    => $consent['nonce'],
			),
			$fields
		);
		$fields = array_filter(
			$fields,
			function ( $value ) {
				return null !== $value;
			}
		);
		return $this->post_raw( $this->query( $fields ) );
	}

	/**
	 * Assert a local error page: 400, framed-denied, no redirect, carrying the error code.
	 *
	 * @param Remote_MCP_Response $response Response.
	 * @param string              $code     Expected error code.
	 * @param string              $message  Context.
	 */
	protected function assert_error_page( Remote_MCP_Response $response, string $code, string $message = '' ): void {
		$this->assertSame( 400, $response->status(), $message . ' status' );
		$this->assertNull( $response->header( 'Location' ), $message . ' never redirects' );
		$this->assertStringContainsString( 'data-error="' . $code . '"', $response->body(), $message . ' error code' );
		$this->assertSame( 'DENY', $response->header( 'X-Frame-Options' ), $message . ' frame denied' );
	}

	/**
	 * Assert a redirect to the client and return its query parameters.
	 *
	 * @param Remote_MCP_Response $response Response.
	 * @param string              $target   Expected redirect URI the Location starts with.
	 * @return array
	 */
	protected function assert_client_redirect( Remote_MCP_Response $response, string $target ): array {
		$this->assertSame( 302, $response->status(), 'redirect: ' . $response->body() );
		$location = (string) $response->header( 'Location' );
		$this->assertStringStartsWith( $target . ( false === strpos( $target, '?' ) ? '?' : '&' ), $location );
		parse_str( (string) substr( $location, strlen( $target ) + 1 ), $query );
		return $query;
	}

	/**
	 * Rows of the consent requests table.
	 *
	 * @return array
	 */
	protected function requests(): array {
		return $this->rows( 'requests' );
	}

	/**
	 * Code rows.
	 *
	 * @return array
	 */
	protected function codes(): array {
		return $this->rows( 'codes' );
	}

	/**
	 * Audit events by name.
	 *
	 * @return array
	 */
	protected function audit_names(): array {
		return array_map(
			function ( $entry ) {
				return $entry[0];
			},
			$this->audits
		);
	}
}
