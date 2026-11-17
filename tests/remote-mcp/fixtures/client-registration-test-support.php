<?php
/**
 * Shared support for the Remote MCP client registration tests: a registry wired to a clocked
 * store, a client lookup that reads fixture documents instead of the bundled files, and request
 * helpers for the registration endpoint.
 *
 * It lives under fixtures/ so the suite never runs it as a test file.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Known_Clients;
use MainWP\Dashboard\Remote_MCP\OAuth_Registration_Endpoint;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Response;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

require_once dirname( __DIR__ ) . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

if ( class_exists( Remote_MCP_Services::class ) && ! class_exists( Remote_MCP_Registration_Test_Services::class, false ) ) {

	/**
	 * Registry whose store is the test's clocked store.
	 */
	class Remote_MCP_Registration_Test_Services extends Remote_MCP_Services {

		public $test_store;

		public function __construct( array $overrides, Remote_MCP_Urls $urls, $store ) {
			parent::__construct( $overrides, $urls );
			$this->test_store = $store;
		}

		public function store(): ?OAuth_Store {
			return $this->test_store;
		}
	}
}

if ( class_exists( OAuth_Known_Clients::class ) && ! class_exists( Remote_MCP_Registration_Test_Known_Clients::class, false ) ) {

	/**
	 * Client lookup over fixture documents: the registry and the file reader are replaced, the
	 * hash check, validation and redirect rules are the real class's own.
	 */
	class Remote_MCP_Registration_Test_Known_Clients extends OAuth_Known_Clients {

		/**
		 * Registry entries, keyed by client_id.
		 *
		 * @var array
		 */
		public $test_registry = array();

		/**
		 * Raw documents, keyed by file name.
		 *
		 * @var array
		 */
		public $documents = array();

		/**
		 * File reads, keyed by file name.
		 *
		 * @var array
		 */
		public $reads = array();

		protected function registry(): array {
			return $this->test_registry;
		}

		protected function read_document( string $file ): ?string {
			$this->reads[ $file ] = ( $this->reads[ $file ] ?? 0 ) + 1;
			return $this->documents[ $file ] ?? null;
		}
	}
}

/**
 * Client registration fixtures: an enabled connector on an HTTPS home, real module tables, a
 * clocked store and the client lookup and registration endpoint wired to them.
 */
trait Remote_MCP_Registration_Fixtures {

	use Remote_MCP_Store_Fixtures {
		set_up as store_set_up;
	}

	/**
	 * Audit events fired during the test, as [ event, fields ].
	 *
	 * @var array
	 */
	protected $audits = array();

	/**
	 * Outbound HTTP attempts seen through pre_http_request.
	 *
	 * @var array
	 */
	protected $outbound = array();

	/**
	 * Enabled connector, default switches, audit and outbound recorders.
	 */
	public function set_up() {
		$this->store_set_up();
		update_option( 'home', 'https://dash.example.org' );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		delete_option( Remote_MCP_Settings::OPTION_REGISTRATION_ENABLED );
		delete_option( Remote_MCP_Settings::OPTION_STATIC_CLIENTS );
		$this->audits   = array();
		$this->outbound = array();
		add_action(
			'mainwp_remote_mcp_audit',
			function ( $event, $fields ) {
				$this->audits[] = array( $event, $fields );
			},
			10,
			2
		);
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->outbound[] = $url;
				return new \WP_Error( 'blocked', 'outbound request in a test' );
			},
			10,
			3
		);
	}

	/**
	 * Services over the test store.
	 *
	 * @param array $overrides Slot overrides.
	 * @return Remote_MCP_Registration_Test_Services
	 */
	protected function services( array $overrides = array() ) {
		return new Remote_MCP_Registration_Test_Services( $overrides, new Remote_MCP_Urls( 'https://dash.example.org' ), $this->store );
	}

	/**
	 * The real client lookup over the bundled files.
	 *
	 * @return OAuth_Known_Clients
	 */
	protected function real_clients(): OAuth_Known_Clients {
		return new OAuth_Known_Clients( $this->services() );
	}

	/**
	 * Fixture document contents.
	 *
	 * @param string $name File name under fixtures/clients.
	 * @return string
	 */
	protected function fixture( string $name ): string {
		$raw = file_get_contents( __DIR__ . '/clients/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture file.
		$this->assertIsString( $raw, $name );
		return $raw;
	}

	/**
	 * A client lookup over fixture documents for all seven bundled ids. Shape fixtures stand in for
	 * claude, claude-code, chatgpt and goose; the other three get a minimal valid document.
	 *
	 * @param array $documents File name => raw document, replacing the defaults.
	 * @return Remote_MCP_Registration_Test_Known_Clients
	 */
	protected function fixture_clients( array $documents = array() ) {
		$shapes  = array(
			'claude.json'      => 'claude-connector-shape.json',
			'claude-code.json' => 'claude-code-shape.json',
			'chatgpt.json'     => 'chatgpt-shape.json',
			'goose.json'       => 'goose-shape.json',
		);
		$clients = new Remote_MCP_Registration_Test_Known_Clients( $this->services() );
		foreach ( OAuth_Known_Clients::REGISTRY as $client_id => $entry ) {
			$raw                                  = isset( $shapes[ $entry['file'] ] )
				? $this->fixture( $shapes[ $entry['file'] ] )
				: (string) wp_json_encode(
					array(
						'client_id'                  => $client_id,
						'client_name'                => $entry['label'],
						'redirect_uris'              => array( 'http://127.0.0.1/callback' ),
						'token_endpoint_auth_method' => 'none',
					),
					JSON_UNESCAPED_SLASHES
				);
			$clients->documents[ $entry['file'] ] = $raw;
			$entry['sha256']                      = hash( 'sha256', $raw );
			$entry['fetched']                     = '2026-10-20';
			$clients->test_registry[ $client_id ] = $entry;
		}
		foreach ( $documents as $file => $raw ) {
			$clients->documents[ $file ] = $raw;
		}
		return $clients;
	}

	/**
	 * Registered app row through the store, returning its client_id.
	 *
	 * @param array  $redirect_uris Redirects, stored as given.
	 * @param string $name          Name.
	 * @return string
	 */
	protected function registered_client( array $redirect_uris, string $name = '' ): string {
		$created = $this->store->create_client(
			'registered',
			array(
				'redirect_uris' => $redirect_uris,
				'client_name'   => $name,
				'created_ip'    => '127.0.0.1',
			)
		);
		$this->assertSame( OAuth_Store::OK, $created['outcome'] );
		return $created['client_id'];
	}

	/**
	 * Web app row through the store, returning its client_id.
	 *
	 * @param array $redirect_uris Redirects, stored as given.
	 * @return string
	 */
	protected function webapp_client( array $redirect_uris ): string {
		$created = $this->store->create_client(
			'webapp',
			array(
				'redirect_uris' => $redirect_uris,
				'client_name'   => 'Team Chat',
				'created_by'    => $this->user_id,
			)
		);
		$this->assertSame( OAuth_Store::OK, $created['outcome'] );
		return $created['client_id'];
	}

	/**
	 * Registration endpoint over the test store.
	 *
	 * @return OAuth_Registration_Endpoint
	 */
	protected function endpoint(): OAuth_Registration_Endpoint {
		return new OAuth_Registration_Endpoint( $this->services() );
	}

	/**
	 * POST a registration body.
	 *
	 * @param array|string $body   Body; an array is JSON-encoded.
	 * @param string       $ip     REMOTE_ADDR.
	 * @param array        $server Extra server variables.
	 * @param string       $method Method.
	 * @return Remote_MCP_Response
	 */
	protected function register( $body, string $ip = '127.0.0.1', array $server = array(), string $method = 'POST' ): Remote_MCP_Response {
		$raw     = is_array( $body ) ? (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES ) : $body;
		$request = new Remote_MCP_Request( 'route_register', $method, '', $raw, array_merge( array( 'REMOTE_ADDR' => $ip ), $server ) );
		return $this->endpoint()->handle( $request );
	}

	/**
	 * Decoded JSON body.
	 *
	 * @param Remote_MCP_Response $response Response.
	 * @return array
	 */
	protected function json( Remote_MCP_Response $response ): array {
		$data = json_decode( $response->body(), true );
		$this->assertIsArray( $data, 'JSON body' );
		return $data;
	}

	/**
	 * Registered client rows.
	 *
	 * @return array
	 */
	protected function client_rows(): array {
		return $this->rows( 'clients', "kind = 'registered'" );
	}

	/**
	 * Set a client's created_at directly.
	 *
	 * @param string $client_id  Client id.
	 * @param int    $created_at Timestamp.
	 */
	protected function set_created_at( string $client_id, int $created_at ): void {
		global $wpdb;
		$wpdb->update( Remote_MCP_Install::table( 'clients' ), array( 'created_at' => $created_at ), array( 'client_id' => $client_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test row setup.
	}

	/**
	 * Events fired with a given name.
	 *
	 * @param string $event Event.
	 * @return array Field arrays.
	 */
	protected function audited( string $event ): array {
		$found = array();
		foreach ( $this->audits as list( $name, $fields ) ) {
			if ( $name === $event ) {
				$found[] = $fields;
			}
		}
		return $found;
	}
}
