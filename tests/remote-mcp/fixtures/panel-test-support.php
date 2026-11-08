<?php
/**
 * Shared support for the panel, revocation, notice and self-test tests: services over the test
 * store, doubles for the catalog, the client lookup and the enable check, and request helpers.
 *
 * Lives under fixtures/ because phpunit.xml.dist excludes that directory from collection.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\Enable_Check;
use MainWP\Dashboard\Remote_MCP\Known_Clients;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Admin;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Context;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;
use MainWP\Dashboard\Remote_MCP\Tool_Executor;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing, Generic.CodeAnalysis.UnusedFunctionParameter

require_once dirname( __DIR__ ) . '/class-remote-mcp-test-support.php';

if ( class_exists( Remote_MCP_Services::class ) && ! class_exists( Remote_MCP_Panel_Test_Services::class, false ) ) {

	/**
	 * Services whose store is the test's clock-controlled store.
	 */
	class Remote_MCP_Panel_Test_Services extends Remote_MCP_Services {

		/**
		 * Store handed to every caller.
		 *
		 * @var OAuth_Store|null
		 */
		public $test_store;

		public function __construct( array $overrides, $store, ?Remote_MCP_Urls $urls = null ) {
			parent::__construct( $overrides, $urls );
			$this->test_store = $store;
		}

		public function store(): ?OAuth_Store {
			return $this->test_store;
		}

		public function installed_store(): ?OAuth_Store {
			return Remote_MCP_Install::is_installed() ? $this->test_store : null;
		}
	}

	/**
	 * Store failures at either persistence step of invalidation.
	 */
	class Remote_MCP_Panel_Test_Failing_Store extends Remote_MCP_Test_Store {

		public $fail_bump   = false;
		public $fail_revoke = false;

		public function bump_generation( int $subject_id ): bool {
			return ! $this->fail_bump && parent::bump_generation( $subject_id );
		}

		public function bump_user_generation( int $user_id, string $reason ): ?int {
			if ( $this->fail_revoke ) {
				$this->bump_generation( $user_id );
				return null;
			}
			return parent::bump_user_generation( $user_id, $reason );
		}

		public function bump_site_generation( string $reason ): ?int {
			if ( $this->fail_revoke ) {
				$this->bump_generation( 0 );
				return null;
			}
			return parent::bump_site_generation( $reason );
		}
	}

	/**
	 * Tool executor whose catalog rows the test sets.
	 */
	class Remote_MCP_Panel_Test_Catalog implements Tool_Executor {

		/**
		 * Catalog rows.
		 *
		 * @var array
		 */
		public $rows = array();

		public function list_tools( Remote_MCP_Context $context ): array {
			return array();
		}

		public function catalog(): array {
			return $this->rows;
		}

		public function call_tool( Remote_MCP_Context $context, string $name, array $arguments ): array {
			return array( 'isError' => true );
		}

		public function execute_plan_entry( Call_Plan $plan, int $index, int $user_id, int $grant_id ): array {
			return array(
				'ok'     => false,
				'reason' => 'double',
				'result' => null,
				'queued' => false,
			);
		}
	}

	/**
	 * Client lookup with fixed entries and a recorded add-time redirect check.
	 */
	class Remote_MCP_Panel_Test_Known_Clients implements Known_Clients {

		/**
		 * Descriptors by client id.
		 *
		 * @var array
		 */
		public $entries = array();

		/**
		 * Redirects validate_redirect() accepts.
		 *
		 * @var array
		 */
		public $valid_redirects = array();

		/**
		 * Calls to validate_redirect(), as [ kind, uri ].
		 *
		 * @var array
		 */
		public $validate_calls = array();

		public function lookup( string $client_id ): ?array {
			return $this->entries[ $client_id ] ?? null;
		}

		public function redirect_allowed( array $client, string $redirect_uri ): bool {
			return false;
		}

		public function validate_redirect( string $kind, string $redirect_uri ): bool {
			$this->validate_calls[] = array( $kind, $redirect_uri );
			return in_array( $redirect_uri, $this->valid_redirects, true );
		}
	}

	/**
	 * Enable check with fixed reasons.
	 */
	class Remote_MCP_Panel_Test_Enable_Check implements Enable_Check {

		/**
		 * Reasons to return.
		 *
		 * @var array
		 */
		public $reasons = array();

		public function blocking_reasons(): array {
			return $this->reasons;
		}
	}
}

/**
 * Panel fixtures: an HTTPS root install, the test store behind the services, doubles for the
 * slots outside the panel's own classes, captured audit events and mail.
 */
trait Remote_MCP_Panel_Fixtures {

	use Remote_MCP_Store_Fixtures {
		Remote_MCP_Store_Fixtures::set_up as store_set_up;
	}

	/**
	 * Catalog double.
	 *
	 * @var Remote_MCP_Panel_Test_Catalog
	 */
	protected $catalog;

	/**
	 * Client lookup double.
	 *
	 * @var Remote_MCP_Panel_Test_Known_Clients
	 */
	protected $known;

	/**
	 * Enable check double.
	 *
	 * @var Remote_MCP_Panel_Test_Enable_Check
	 */
	protected $enable_check;

	/**
	 * Audit events fired during the test, as [ event, fields ].
	 *
	 * @var array
	 */
	protected $audits = array();

	/**
	 * Grant-issued actions fired during the test, as argument lists.
	 *
	 * @var array
	 */
	protected $issued = array();

	/**
	 * HTTPS root install with pretty permalinks, the activity log on, and the current user set.
	 */
	public function set_up() {
		$this->store_set_up();
		// The panel and the store must agree on "now": mint expiry and the 10-minute help rule read it.
		$this->clock->now = time();
		update_option( 'home', 'https://dash.example.org' );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( Remote_MCP_Settings::OPTION_LOG_SETTINGS, array( 'enabled' => 1 ) );
		delete_option( Remote_MCP_Settings::OPTION_ENABLED );
		$this->catalog      = new Remote_MCP_Panel_Test_Catalog();
		$this->known        = new Remote_MCP_Panel_Test_Known_Clients();
		$this->enable_check = new Remote_MCP_Panel_Test_Enable_Check();
		$this->audits       = array();
		$this->issued       = array();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) {
				$this->audits[] = array( $event, $fields );
			},
			10,
			2
		);
		add_action(
			Remote_MCP_Events::GRANT_ISSUED,
			function ( ...$args ) {
				$this->issued[] = $args;
			},
			10,
			5
		);
		reset_phpmailer_instance();
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Services over the test store and the doubles.
	 *
	 * @param array $overrides Extra slot overrides.
	 * @return Remote_MCP_Panel_Test_Services
	 */
	protected function panel_services( array $overrides = array() ): Remote_MCP_Panel_Test_Services {
		return new Remote_MCP_Panel_Test_Services(
			array_merge(
				array(
					'tool_executor' => $this->catalog,
					'known_clients' => $this->known,
					'enable_check'  => $this->enable_check,
				),
				$overrides
			),
			$this->store,
			new Remote_MCP_Urls( 'https://dash.example.org' )
		);
	}

	/**
	 * Panel over test services.
	 *
	 * @return Remote_MCP_Admin
	 */
	protected function admin(): Remote_MCP_Admin {
		return new Remote_MCP_Admin( $this->panel_services() );
	}

	/**
	 * Post one panel action as the current user, with a valid nonce unless one is given.
	 *
	 * @param string                $action Action.
	 * @param string                $target Target.
	 * @param array                 $fields Extra fields.
	 * @param string|null           $nonce  Nonce, or null for a valid one.
	 * @param Remote_MCP_Admin|null $admin Panel.
	 * @return array Result.
	 */
	protected function post( string $action, string $target = '', array $fields = array(), ?string $nonce = null, $admin = null ): array {
		$admin = $admin ? $admin : $this->admin();
		return $admin->handle_post(
			array_merge(
				$fields,
				array(
					'mainwp_remote_mcp_action' => $action,
					'target'                   => $target,
					'_wpnonce'                 => null === $nonce ? wp_create_nonce( Remote_MCP_Admin::nonce_action( $action, $target ) ) : $nonce,
				)
			)
		);
	}

	/**
	 * Connect a user with read and write scope under a policy.
	 *
	 * @param int    $user_id User.
	 * @param string $policy  Policy.
	 * @param array  $request Request overrides.
	 * @return array Exchange result.
	 */
	protected function connect_write( int $user_id, string $policy = 'standard', array $request = array() ): array {
		$params  = $this->request_params( $user_id, array_merge( array( 'scope' => 'mainwp:read mainwp:write offline_access' ), $request ) );
		$created = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $created['id'], $user_id, true, 'mainwp:read mainwp:write offline_access', $policy ), 'consent claim' );
		$result = $this->store->redeem_code( $this->store->issue_code( $created['id'] ), $params['client_id'], self::VERIFIER );
		$this->assertSame( OAuth_Store::OK, $result['outcome'], 'connect' );
		return $result;
	}

	/**
	 * An administrator whom Team Control limits to two sites.
	 *
	 * @return int
	 */
	protected function limited_admin(): int {
		$limited = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_filter(
			'mainwp_currentuserallowedaccesssites',
			function ( $allowed ) use ( $limited ) {
				return get_current_user_id() === $limited ? array( 1, 2 ) : $allowed;
			}
		);
		return $limited;
	}

	/**
	 * Audit events of one type.
	 *
	 * @param string $event Event.
	 * @return array Field arrays.
	 */
	protected function audits_of( string $event ): array {
		$found = array();
		foreach ( $this->audits as list( $name, $fields ) ) {
			if ( $event === $name ) {
				$found[] = $fields;
			}
		}
		return $found;
	}

	/**
	 * Mail sent during the test.
	 *
	 * @return array Each with `to`, `subject` and `body`.
	 */
	protected function mails(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * Grant ids in a list of rows.
	 *
	 * @param array $rows Rows with `id`.
	 * @return int[]
	 */
	protected function ids( array $rows ): array {
		return array_map( 'intval', array_column( $rows, 'id' ) );
	}
}
