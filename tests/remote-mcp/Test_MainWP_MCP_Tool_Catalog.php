<?php
/**
 * Remote MCP tool catalog tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\MCP_Tool_Catalog;
use MainWP\Dashboard\Remote_MCP\MCP_Tier_Registry;
use MainWP\Dashboard\Remote_MCP\MCP_Tool_Executor;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/endpoint-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Tool_Catalog
 */
class Test_MainWP_MCP_Tool_Catalog extends \WP_UnitTestCase {

	use Remote_MCP_Endpoint_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	const READ  = array(
		'readonly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	);
	const WRITE = array(
		'readonly'    => false,
		'destructive' => false,
		'idempotent'  => false,
	);

	/**
	 * Annotation sets that must never give the read tier or the uncontracted path.
	 *
	 * @var array
	 */
	const INVALID_ANNOTATIONS = array(
		'contradictory'       => array(
			'readonly'    => true,
			'destructive' => true,
		),
		'destructive_missing' => array( 'readonly' => true ),
		'missing'             => array(),
		'readonly_string'     => array(
			'readonly'    => 'true',
			'destructive' => false,
		),
		'readonly_one'        => array(
			'readonly'    => 1,
			'destructive' => false,
		),
		'readonly_null'       => array(
			'readonly'    => null,
			'destructive' => false,
		),
		'destructive_zero'    => array(
			'readonly'    => true,
			'destructive' => 0,
		),
		'destructive_empty'   => array(
			'readonly'    => true,
			'destructive' => '',
		),
		'destructive_string'  => array(
			'readonly'    => true,
			'destructive' => 'false',
		),
	);

	/**
	 * Executor over the default services.
	 *
	 * @return MCP_Tool_Executor
	 */
	private function executor(): MCP_Tool_Executor {
		return $this->services()->get( 'tool_executor' );
	}

	/**
	 * Tool names listed for a context.
	 *
	 * @param array $context Context overrides.
	 * @return string[]
	 */
	private function listed( array $context = array() ): array {
		return array_column( $this->executor()->list_tools( $this->context( $context ) ), 'name' );
	}

	/**
	 * Whether call_tool refused the name as unknown.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	private function unknown_tool( string $name ): bool {
		$result = $this->executor()->call_tool( $this->context(), $name, array() );
		return isset( $result['jsonrpc_error'] ) && -32602 === $result['jsonrpc_error']['code'] && 'Unknown tool' === $result['jsonrpc_error']['message'];
	}

	/**
	 * Catalog row by tool name.
	 *
	 * @param string $tool Tool name.
	 * @return array|null
	 */
	private function row( string $tool ): ?array {
		foreach ( $this->executor()->catalog() as $row ) {
			if ( $tool === $row['tool_name'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Every core ability name registered in includes/abilities, read from the source.
	 *
	 * @return string[]
	 */
	private function core_ability_names(): array {
		$names = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/includes/abilities/*.php' ) as $file ) {
			preg_match_all( "/wp_register_ability\\(\\s*'(mainwp\\/[a-z0-9-]+)'/", (string) file_get_contents( $file ), $found ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
			$names = array_merge( $names, $found[1] );
		}
		// API Backups picks each name with a ternary, which the pattern above can't read.
		$names = array_merge( $names, array( 'mainwp/list-api-backups-providers-v1', 'mainwp/list-api-backups-site-status-v1' ) );
		sort( $names );
		return $names;
	}

	public function test_tool_names_match_stdio_naming() {
		$this->assertSame( 'list_sites_v1', MCP_Tool_Catalog::tool_name( 'mainwp/list-sites-v1' ) );
		$this->assertSame( 'run_updates_v1', MCP_Tool_Catalog::tool_name( 'mainwp/run-updates-v1' ) );
		$this->ability( 'mainwp/remote-test-get-thing-v2' );
		$listed = $this->listed();
		$this->assertContains( 'list_sites_v1', $listed );
		$this->assertContains( 'update_site_plugins_v1', $listed );
		$this->assertContains( 'remote_test_get_thing_v2', $listed );
		foreach ( $listed as $name ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $name );
		}
	}

	public function test_only_allowlisted_namespace_and_show_in_rest_abilities_listed() {
		$this->ability( 'other-plugin/remote-test-read-v1' );
		$this->ability( 'mainwp/remote-test-hidden-v1', self::READ, array(), false );
		$this->ability( 'mainwp/remote-test-visible-v1' );
		$listed = $this->listed();
		$this->assertContains( 'remote_test_visible_v1', $listed );
		$this->assertNotContains( 'remote_test_hidden_v1', $listed );
		$this->assertNotContains( 'other_plugin__remote_test_read_v1', $listed );
		$this->assertNotContains( 'remote_test_read_v1', $listed );
		$abilities = array_column( $this->executor()->catalog(), 'ability' );
		$this->assertNotContains( 'other-plugin/remote-test-read-v1', $abilities );
		$this->assertNotContains( 'mainwp/remote-test-hidden-v1', $abilities );
		$this->assertTrue( $this->unknown_tool( 'remote_test_hidden_v1' ) );
	}

	public function test_read_tier_only_for_literal_readonly_true_and_destructive_false() {
		$registry = $this->services()->get( 'tier_registry' );
		$this->ability( 'mainwp/remote-test-valid-read-v1', self::READ );
		$this->assertSame( 'read', $registry->entry( 'mainwp/remote-test-valid-read-v1' )['tier'] );
		$this->ability(
			'mainwp/remote-test-readonly-false-v1',
			array(
				'readonly'    => false,
				'destructive' => false,
			)
		);
		$this->assertSame( 'dashboard', $registry->entry( 'mainwp/remote-test-readonly-false-v1' )['tier'] );
		$i = 0;
		foreach ( self::INVALID_ANNOTATIONS as $case => $annotations ) {
			$name = 'mainwp/remote-test-invalid-' . ( ++$i ) . '-v1';
			$this->ability( $name, $annotations );
			$entry = $registry->entry( $name );
			$this->assertTrue( null === $entry || 'read' !== $entry['tier'], $case . ' never gives read' );
		}
		// Every ability with valid annotations is listed, read and write alike.
		$listed = $this->listed();
		$this->assertContains( 'remote_test_valid_read_v1', $listed );
		$this->assertContains( 'remote_test_readonly_false_v1', $listed );
	}

	public function test_contradictory_extension_annotations_unavailable_without_audited_entry() {
		$this->ability( 'mainwp/remote-test-contradictory-v1', self::INVALID_ANNOTATIONS['contradictory'] );
		$this->assertNull( $this->services()->get( 'tier_registry' )->entry( 'mainwp/remote-test-contradictory-v1' ) );
		$this->assertNotContains( 'remote_test_contradictory_v1', $this->listed( array( 'policy' => 'hands_off' ) ) );
		$this->assertTrue( $this->unknown_tool( 'remote_test_contradictory_v1' ) );
		$this->assertSame( 'not_available', $this->row( 'remote_test_contradictory_v1' )['availability'] );
		$this->assertSame( array(), self::$executions );
	}

	public function test_missing_extension_annotations_unavailable_without_audited_entry() {
		$this->ability( 'mainwp/remote-test-missing-v1', array() );
		$this->ability( 'mainwp/remote-test-half-v1', array( 'readonly' => false ) );
		foreach ( array( 'remote_test_missing_v1', 'remote_test_half_v1' ) as $tool ) {
			$this->assertNull( $this->services()->get( 'tier_registry' )->entry( 'mainwp/' . str_replace( '_', '-', $tool ) ) );
			$this->assertNotContains( $tool, $this->listed() );
			$this->assertTrue( $this->unknown_tool( $tool ) );
		}
		$this->assertSame( array(), self::$executions );
	}

	public function test_malformed_extension_annotation_values_unavailable_without_audited_entry() {
		$cases = array_diff_key( self::INVALID_ANNOTATIONS, array_flip( array( 'contradictory', 'missing', 'destructive_missing' ) ) );
		$i     = 0;
		foreach ( $cases as $case => $annotations ) {
			$name = 'mainwp/remote-test-malformed-' . ( ++$i ) . '-v1';
			$this->ability( $name, $annotations );
			$tool = MCP_Tool_Catalog::tool_name( $name );
			$this->assertNull( $this->services()->get( 'tier_registry' )->entry( $name ), $case );
			$this->assertNotContains( $tool, $this->listed(), $case );
			$this->assertTrue( $this->unknown_tool( $tool ), $case );
		}
		$this->assertSame( array(), self::$executions );
	}

	public function test_name_collision_drops_both_tools() {
		$this->category();
		$make          = function ( string $name ) {
			return new \WP_Ability(
				$name,
				array(
					'label'               => 'Collision',
					'description'         => 'Collision.',
					'category'            => 'remote-mcp-test',
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
					'meta'                => array(
						'annotations'  => self::READ,
						'show_in_rest' => true,
					),
				)
			);
		};
		$fakes         = array( $make( 'mainwp/remote-test-dup-v1' ), $make( 'mainwp/remote_test-dup-v1' ), $make( 'mainwp/get-remote-job-status-v1' ), $make( 'mainwp/list-sites-v1' ) );
		// The synthetic underscore name bypasses WordPress's name restriction, so both fixtures
		// need a known read-tier entry before the catalog can test its own collision guard.
		$registry = new class( $this->services() ) extends MCP_Tier_Registry {
			public function entry( string $ability ): ?array {
				if ( in_array( $ability, array( 'mainwp/remote-test-dup-v1', 'mainwp/remote_test-dup-v1' ), true ) ) {
					return MCP_Tool_Catalog::entry( 'annotations', 'read', 'none', 'direct', 'none', null, array(), array(), 'valid_read_annotations' );
				}
				return parent::entry( $ability );
			}
		};
		$services = $this->services( array( 'tier_registry' => $registry ) );
		$make_executor = function ( array $abilities ) use ( $services ) {
			return new class( $services, $abilities ) extends MCP_Tool_Executor {
				private $fakes;
				public function __construct( $services, array $fakes ) {
					parent::__construct( $services );
					$this->fakes = $fakes;
				}
				protected function abilities(): array {
					return $this->fakes;
				}
			};
		};
		foreach ( array_slice( $fakes, 0, 2 ) as $ability ) {
			$single = $make_executor( array( $ability ) );
			$this->assertContains( 'remote_test_dup_v1', array_column( $single->list_tools( $this->context() ), 'name' ), $ability->get_name() . ' is exposable alone' );
		}
		$executor = $make_executor( $fakes );
		$names    = array_column( $executor->list_tools( $this->context() ), 'name' );
		$this->assertNotContains( 'remote_test_dup_v1', $names, 'both colliding abilities dropped' );
		$this->assertContains( 'list_sites_v1', $names, 'a name nobody shares stays' );
		$this->assertContains( 'get_remote_job_status_v1', $names, 'the module tool keeps its name' );
		$this->assertSame( 1, count( array_keys( $names, 'get_remote_job_status_v1', true ) ) );
		$rows = array_filter(
			$executor->catalog(),
			function ( $row ) {
				return 'remote_test_dup_v1' === $row['tool_name'];
			}
		);
		$this->assertCount( 2, $rows, 'each collision is recorded' );
		foreach ( $rows as $row ) {
			$this->assertSame( 'not_available', $row['availability'] );
			$this->assertSame( 'name_collision', $row['reason'] );
		}
		$result = $executor->call_tool( $this->context(), 'remote_test_dup_v1', array() );
		$this->assertSame( -32602, $result['jsonrpc_error']['code'] );
	}

	public function test_excluded_slow_abilities_are_neither_listed_nor_callable() {
		$this->assertSame( array(), MCP_Tool_Catalog::EXCLUDED_ABILITIES, 'nothing measured over 90 s yet' );
		$executor = new class( $this->services() ) extends MCP_Tool_Executor {
			protected function excluded_abilities(): array {
				return array( 'mainwp/list-sites-v1' );
			}
		};
		$this->assertNotContains( 'list_sites_v1', array_column( $executor->list_tools( $this->context() ), 'name' ) );
		$this->assertSame( -32602, $executor->call_tool( $this->context(), 'list_sites_v1', array() )['jsonrpc_error']['code'] );
		$this->assertSame( array(), $this->gate->checks );
	}

	public function test_correctly_annotated_extension_read_tool_listed_without_audited_list() {
		$this->ability( 'mainwp/remote-test-ext-read-v1' );
		$entry = $this->services()->get( 'tier_registry' )->entry( 'mainwp/remote-test-ext-read-v1' );
		$this->assertIsArray( $entry, 'the registry has an entry' );
		$this->assertSame( 'annotations', $entry['source'] );
		$this->assertSame( 'read', $entry['tier'] );
		$this->assertSame( 'direct', $entry['adapter'] );
		$this->assertNull( $entry['contract'] );
		$this->assertContains( 'remote_test_ext_read_v1', $this->listed( array( 'scopes' => array( 'mainwp:read' ) ) ) );

		$token    = $this->token( 'mainwp:read' );
		$response = $this->legacy(
			'tools/call',
			array(
				'name'      => 'remote_test_ext_read_v1',
				'arguments' => array( 'site_id' => 3 ),
			),
			$token
		);
		$this->assertSame( 200, $response->status() );
		$body = $this->json( $response );
		$this->assertFalse( $body['result']['isError'] );
		$this->assertSame( 1, self::$executions['mainwp/remote-test-ext-read-v1'] );
		$this->assertSame( 'read', $this->gate->checks[0]['context']->has_scope( 'mainwp:write' ) ? 'write' : 'read' );
		$this->assertSame( 'read', $this->services()->get( 'scope_policy' )->effective_tier( $this->gate->checks[0]['context'], 'mainwp/remote-test-ext-read-v1', array() ) );
	}

	public function test_every_tool_carries_security_schemes_with_required_scope() {
		$this->ability( 'mainwp/remote-test-ext-write-v1', self::WRITE );
		foreach ( array( array( 'mainwp:read' ), array( 'mainwp:read', 'mainwp:write' ) ) as $scopes ) {
			$tools = $this->executor()->list_tools( $this->context( array( 'scopes' => $scopes ) ) );
			$this->assertNotEmpty( $tools );
			foreach ( $tools as $tool ) {
				$scope = true === $tool['annotations']['readOnlyHint'] ? 'mainwp:read' : 'mainwp:write';
				$this->assertSame(
					array(
						array(
							'type'   => 'oauth2',
							'scopes' => array( $scope ),
						),
					),
					$tool['securitySchemes'],
					$tool['name']
				);
			}
			$this->assertSame( array( 'mainwp:read' ), $this->tool( $tools, 'list_sites_v1' )['securitySchemes'][0]['scopes'] );
			$this->assertSame( array( 'mainwp:write' ), $this->tool( $tools, 'delete_site_v1' )['securitySchemes'][0]['scopes'], 'write tools listed on read grants too' );
			$this->assertSame( array( 'mainwp:write' ), $this->tool( $tools, 'remote_test_ext_write_v1' )['securitySchemes'][0]['scopes'] );
			$this->assertSame( array( 'mainwp:read' ), $this->tool( $tools, 'get_remote_job_status_v1' )['securitySchemes'][0]['scopes'] );
		}
	}

	public function test_every_core_ability_has_a_tier() {
		$names = $this->core_ability_names();
		$this->assertCount( 104, $names );
		$this->assertSame( $names, array_values( array_unique( $names ) ) );
		$this->assertSame( $names, array_keys( array_intersect_key( array_flip( $names ), MCP_Tool_Catalog::CORE_TIERS ) ), 'every core ability in CORE_TIERS' );
		$this->assertCount( 104, MCP_Tool_Catalog::CORE_TIERS, 'and nothing else' );
		foreach ( wp_get_abilities() as $ability ) {
			if ( in_array( $ability->get_category(), MCP_Tool_Catalog::CORE_CATEGORIES, true ) && 0 === strpos( $ability->get_name(), 'mainwp/' ) ) {
				$this->assertArrayHasKey( $ability->get_name(), MCP_Tool_Catalog::CORE_TIERS );
				$this->assertSame( 'core', $this->services()->get( 'tier_registry' )->entry( $ability->get_name() )['source'] );
			}
		}
	}

	public function test_core_write_that_has_a_native_preview_uses_it() {
		$checked = 0;
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( ! isset( MCP_Tool_Catalog::CORE_TIERS[ $name ] ) || 'read' === MCP_Tool_Catalog::CORE_TIERS[ $name ][0] ) {
				continue;
			}
			$schema = $ability->get_input_schema();
			if ( isset( $schema['properties']['dry_run'] ) ) {
				++$checked;
				$this->assertSame( 'dry_run', MCP_Tool_Catalog::CORE_TIERS[ $name ][3], $name );
			}
		}
		$this->assertGreaterThan( 0, $checked );
	}

	public function test_core_write_declared_destructive_is_never_write_tier() {
		$checked = 0;
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( ! isset( MCP_Tool_Catalog::CORE_TIERS[ $name ] ) ) {
				continue;
			}
			if ( true === ( MCP_Tool_Catalog::annotations( $ability )['destructive'] ?? null ) ) {
				++$checked;
				$this->assertNotSame( 'write', MCP_Tool_Catalog::CORE_TIERS[ $name ][0], $name );
			}
		}
		$this->assertGreaterThan( 0, $checked );
	}

	public function test_destination_minimum_retention_needs_dashboard_approval_in_every_policy() {
		$policy  = $this->services()->get( 'scope_policy' );
		$ability = 'mainwp/update-backup-destination-v1';
		foreach ( array( 'standard', 'strict', 'hands_off' ) as $name ) {
			$context = $this->context( array( 'policy' => $name ) );
			$this->assertSame(
				'dashboard',
				$policy->effective_tier(
					$context,
					$ability,
					array(
						'destination_id'                => 1,
						'minimum_remote_retention_days' => 0,
					)
				),
				$name
			);
		}
		$rename = array(
			'destination_id' => 1,
			'name'           => 'Offsite',
		);
		$this->assertSame( 'confirm', $policy->effective_tier( $this->context(), $ability, $rename ), 'other edits keep their tier' );
	}

	public function test_delete_task_preview_is_refused_only_when_it_says_it_was_cut() {
		$preview = static function ( array $effect ): array {
			return array(
				'would_affect' => $effect + array(
					'snapshots_truncated'    => false,
					'cloud_copies_truncated' => false,
					'cloud_copies'           => array_fill( 0, 500, array() ),
				),
			);
		};
		$ability = 'mainwp/delete-backup-task-v1';
		$this->assertNull( MCP_Tool_Catalog::preview_refusal( $ability, $preview( array() ) ), 'exactly 500 is the whole list' );
		$this->assertSame( 'backup_preview_capped', MCP_Tool_Catalog::preview_refusal( $ability, $preview( array( 'cloud_copies_truncated' => true ) ) ) );
		$this->assertSame( 'backup_preview_capped', MCP_Tool_Catalog::preview_refusal( $ability, $preview( array( 'snapshots_truncated' => true ) ) ) );
		$this->assertSame( 'backup_preview_capped', MCP_Tool_Catalog::preview_refusal( $ability, array( 'would_affect' => array( 'snapshots_truncated' => false ) ) ), 'a missing flag counts as cut' );
	}

	public function test_untiered_core_ability_is_dashboard() {
		$this->ability( 'mainwp/remote-test-core-read-v1', self::READ, array( 'category' => 'mainwp-sites' ) );
		$entry = $this->services()->get( 'tier_registry' )->entry( 'mainwp/remote-test-core-read-v1' );
		$this->assertIsArray( $entry, 'the registry has an entry' );
		$this->assertSame( 'core', $entry['source'] );
		$this->assertSame( 'dashboard', $entry['tier'] );
		$this->assertSame( 'dashboard', $this->services()->get( 'scope_policy' )->effective_tier( $this->context(), 'mainwp/remote-test-core-read-v1', array() ) );
	}

	public function test_unaudited_extension_write_is_dashboard() {
		$this->ability( 'mainwp/remote-test-unaudited-v1', self::WRITE );
		$entry = $this->services()->get( 'tier_registry' )->entry( 'mainwp/remote-test-unaudited-v1' );
		$this->assertIsArray( $entry, 'the registry has an entry' );
		$this->assertSame( 'uncontracted', $entry['source'] );
		$this->assertSame( 'dashboard', $entry['tier'] );
		$policy = $this->services()->get( 'scope_policy' );
		$this->assertSame( 'dashboard', $policy->effective_tier( $this->context(), 'mainwp/remote-test-unaudited-v1', array() ) );
		$this->assertSame( 'dashboard', $policy->effective_tier( $this->context( array( 'policy' => 'strict' ) ), 'mainwp/remote-test-unaudited-v1', array() ) );
		$this->assertSame( 'confirm', $policy->effective_tier( $this->context( array( 'policy' => 'hands_off' ) ), 'mainwp/remote-test-unaudited-v1', array() ) );
		$this->assertSame( 'not_reviewed', $this->row( 'remote_test_unaudited_v1' )['availability'] );
		$this->assertContains( 'remote_test_unaudited_v1', $this->listed() );
	}

	public function test_invalid_annotations_checked_before_read_tier() {
		$registry = $this->services()->get( 'tier_registry' );
		$this->ability( 'mainwp/remote-test-loose-v1', self::INVALID_ANNOTATIONS['destructive_string'] );
		$this->ability( 'mainwp/remote-test-loose-two-v1', self::INVALID_ANNOTATIONS['destructive_missing'] );
		$this->assertNull( $registry->entry( 'mainwp/remote-test-loose-v1' ), 'readonly true with a non-boolean destructive is not read' );
		$this->assertNull( $registry->entry( 'mainwp/remote-test-loose-two-v1' ), 'and not the uncontracted path either' );
		$this->assertNull( $this->services()->get( 'scope_policy' )->effective_tier( $this->context(), 'mainwp/remote-test-loose-v1', array() ) );
	}

	public function test_authorization_changing_extension_is_dashboard_outside_hands_off() {
		$name = 'mainwp/replace-team-control-user-roles-v1';
		$this->ability( $name, self::WRITE );
		$policy = $this->services()->get( 'scope_policy' );
		$this->assertSame( 'dashboard', $policy->effective_tier( $this->context(), $name, array() ) );
		$this->assertSame( 'dashboard', $policy->effective_tier( $this->context( array( 'policy' => 'strict' ) ), $name, array() ) );
		$this->assertSame( 'confirm', $policy->effective_tier( $this->context( array( 'policy' => 'hands_off' ) ), $name, array() ) );

		// A declared contract can't move it below dashboard, and false read annotations can't either.
		$this->contracts->declared[ $name ] = Remote_MCP_Endpoint_Test_Contracts::contract( 'confirm' );
		$this->assertSame( 'dashboard', $this->services()->get( 'scope_policy' )->effective_tier( $this->context(), $name, array() ) );
		$this->ability( 'mainwp/create-team-control-role-v1', self::READ );
		$this->assertSame( 'dashboard', $this->services()->get( 'scope_policy' )->effective_tier( $this->context(), 'mainwp/create-team-control-role-v1', array() ) );
	}

	public function test_disabled_tool_neither_listed_nor_callable() {
		$this->ability( 'mainwp/remote-test-unreviewed-v1', self::WRITE );
		$this->assertContains( 'list_sites_v1', $this->listed() );
		Remote_MCP_Settings::set_tool_enabled( 'list_sites_v1', false );
		$this->assertNotContains( 'list_sites_v1', $this->listed() );
		$this->assertTrue( $this->unknown_tool( 'list_sites_v1' ) );

		Remote_MCP_Settings::set_category_enabled( 'mainwp-clients', false );
		$this->assertNotContains( 'list_clients_v1', $this->listed() );
		$this->assertTrue( $this->unknown_tool( 'get_client_v1' ) );

		$this->assertContains( 'remote_test_unreviewed_v1', $this->listed() );
		Remote_MCP_Settings::set_unaudited_extension_tools_enabled( false );
		$this->assertNotContains( 'remote_test_unreviewed_v1', $this->listed() );
		$this->assertTrue( $this->unknown_tool( 'remote_test_unreviewed_v1' ) );
		$this->assertSame( array(), $this->gate->checks, 'nothing reached the gate' );
	}

	public function test_published_schema_hides_the_backup_gate_override() {
		$published = MCP_Tool_Catalog::published_schema(
			array(
				'type'       => 'object',
				'properties' => array(
					'slugs'              => array( 'type' => 'array' ),
					'ignore_backup_gate' => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'slugs', 'ignore_backup_gate' ),
			),
			array(),
			false
		);
		$this->assertArrayHasKey( 'slugs', $published['properties'] );
		$this->assertArrayNotHasKey( 'ignore_backup_gate', $published['properties'] );
		$this->assertSame( array( 'slugs' ), $published['required'] );
	}

	public function test_published_schema_publishes_object_for_a_nullable_object_type() {
		$this->assertSame( 'object', MCP_Tool_Catalog::published_schema( array( 'type' => array( 'object', 'null' ) ), array(), true )['type'] );
		$this->assertSame( 'object', MCP_Tool_Catalog::published_schema( array( 'type' => array( 'null', 'object' ) ), array(), false )['type'] );
	}

	public function test_published_schema_hides_confirm_dry_run_and_preview_token_including_required() {
		$this->ability(
			'mainwp/replace-site-google-search-console-mapping-v1',
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			array(
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'site_id_or_domain' => array( 'type' => 'string' ),
						'request_ref'       => array( 'type' => 'string' ),
						'dry_run'           => array( 'type' => 'boolean' ),
						'confirm'           => array( 'type' => 'boolean' ),
						'preview_token'     => array( 'type' => array( 'string', 'null' ) ),
					),
					'required'             => array( 'site_id_or_domain', 'request_ref', 'dry_run', 'confirm', 'preview_token' ),
					'additionalProperties' => false,
				),
			)
		);
		$tools  = $this->executor()->list_tools( $this->context() );
		$schema = $this->tool( $tools, 'replace_site_google_search_console_mapping_v1' )['inputSchema'];
		$this->assertSame( array( 'site_id_or_domain', 'user_confirmed', 'confirmation_token' ), array_keys( (array) $schema['properties'] ) );
		$this->assertSame( array( 'site_id_or_domain' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );

		$delete = $this->tool( $tools, 'delete_site_v1' )['inputSchema'];
		foreach ( array( 'confirm', 'dry_run', 'preview_token' ) as $control ) {
			$this->assertArrayNotHasKey( $control, (array) $delete['properties'] );
			$this->assertNotContains( $control, $delete['required'] ?? array() );
		}
	}

	public function test_every_non_read_tool_has_optional_confirmation_fields_in_every_policy() {
		$this->ability( 'mainwp/remote-test-ext-write-v1', self::WRITE, array( 'input_schema' => array() ) );
		$schemas = array();
		foreach ( array( 'standard', 'strict', 'hands_off' ) as $policy ) {
			foreach ( $this->executor()->list_tools( $this->context( array( 'policy' => $policy ) ) ) as $tool ) {
				$properties = (array) ( $tool['inputSchema']['properties'] ?? array() );
				if ( true === $tool['annotations']['readOnlyHint'] ) {
					$this->assertArrayNotHasKey( 'user_confirmed', $properties, $tool['name'] );
				} else {
					$this->assertArrayHasKey( 'user_confirmed', $properties, $tool['name'] );
					$this->assertArrayHasKey( 'confirmation_token', $properties, $tool['name'] );
					$this->assertSame( array( 'type' => 'boolean' ), array_intersect_key( (array) $properties['user_confirmed'], array( 'type' => 1 ) ), $tool['name'] );
					$this->assertSame( array( 'type' => 'string' ), array_intersect_key( (array) $properties['confirmation_token'], array( 'type' => 1 ) ), $tool['name'] );
					$this->assertNotContains( 'user_confirmed', $tool['inputSchema']['required'] ?? array() );
					$this->assertNotContains( 'confirmation_token', $tool['inputSchema']['required'] ?? array() );
				}
				$schemas[ $policy ][ $tool['name'] ] = wp_json_encode( $tool['inputSchema'] ) . wp_json_encode( $tool['description'] );
			}
		}
		$this->assertSame( $schemas['standard'], $schemas['strict'], 'schemas and descriptions never depend on the policy' );
		$this->assertSame( $schemas['standard'], $schemas['hands_off'] );
		$this->assertSame( '{"type":"object"', substr( $schemas['standard']['remote_test_ext_write_v1'], 0, 16 ) );
	}

	public function test_job_status_tool_listed_read_scope_and_dispatched_to_job_runner() {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/mcp/job-status-tool.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$tool    = $this->tool( $this->executor()->list_tools( $this->context( array( 'scopes' => array( 'mainwp:read' ) ) ) ), 'get_remote_job_status_v1' );
		$this->assertSame( $fixture, json_decode( (string) wp_json_encode( $tool ), true ) );
		$this->assertSame( 'mainwp-remote-mcp', $this->row( 'get_remote_job_status_v1' )['category'] );

		$status               = array(
			'job_id'           => 12,
			'status'           => 'running',
			'entries_done'     => 1,
			'entries_total'    => 3,
			'current_site_ids' => array( 4 ),
			'cancel_reason'    => null,
		);
		$this->jobs->jobs[12] = array( $this->user_id, $status );
		$result               = $this->executor()->call_tool( $this->context( array( 'scopes' => array( 'mainwp:read' ) ) ), 'get_remote_job_status_v1', array( 'job_id' => 12 ) );
		$this->assertFalse( $result['isError'] );
		$this->assertSame( $status, $result['structuredContent'] );
		$this->assertSame( array( 12, $this->user_id ), $this->jobs->calls[0] );

		$other = $this->executor()->call_tool( $this->context( array( 'scopes' => array( 'mainwp:read' ) ) ), 'get_remote_job_status_v1', array( 'job_id' => 99 ) );
		$this->assertTrue( $other['isError'] );
		$this->assertSame( 'Job not found', $other['content'][0]['text'] );

		$no_read = $this->executor()->call_tool( $this->context( array( 'scopes' => array( 'offline_access' ) ) ), 'get_remote_job_status_v1', array( 'job_id' => 12 ) );
		$this->assertTrue( $no_read['isError'] );
		$this->assertCount( 2, $this->jobs->calls, 'no status lookup without read scope' );
		$this->assertSame( array(), $this->gate->checks, 'the module tool skips the gate' );
	}

	public function test_catalog_rows_carry_category_tier_and_availability_for_every_exposable_ability() {
		$this->ability( 'mainwp/remote-test-catalog-read-v1' );
		$this->ability( 'mainwp/remote-test-catalog-write-v1', self::WRITE );
		$this->ability( 'mainwp/remote-test-catalog-bad-v1', array() );
		$this->ability( 'mainwp/get-site-clone-policy-status-v1' );
		$this->ability( 'other-plugin/remote-test-catalog-v1' );
		Remote_MCP_Settings::set_tool_enabled( 'list_sites_v1', false );

		$catalog = $this->executor()->catalog();
		$keys    = array( 'tool_name', 'ability', 'category', 'standard_tier', 'availability', 'reason' );
		$by_tool = array();
		foreach ( $catalog as $row ) {
			$this->assertSame( $keys, array_keys( $row ) );
			$by_tool[ $row['tool_name'] ] = $row;
		}
		$exposable = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 === strpos( $ability->get_name(), 'mainwp/' ) && true === $ability->get_meta_item( 'show_in_rest' ) ) {
				$exposable[] = MCP_Tool_Catalog::tool_name( $ability->get_name() );
			}
		}
		$exposable[] = 'get_remote_job_status_v1';
		sort( $exposable );
		$tools = array_keys( $by_tool );
		sort( $tools );
		$this->assertSame( $exposable, $tools, 'one row per exposable tool, switched off ones included' );

		$this->assertSame( array( 'mainwp-sites', 'read', 'available' ), array( $by_tool['list_sites_v1']['category'], $by_tool['list_sites_v1']['standard_tier'], $by_tool['list_sites_v1']['availability'] ) );
		$this->assertSame( array( 'dashboard', 'available' ), array( $by_tool['delete_site_v1']['standard_tier'], $by_tool['delete_site_v1']['availability'] ) );
		$this->assertSame( array( 'remote-mcp-test', 'read', 'available' ), array( $by_tool['remote_test_catalog_read_v1']['category'], $by_tool['remote_test_catalog_read_v1']['standard_tier'], $by_tool['remote_test_catalog_read_v1']['availability'] ) );
		$this->assertSame( array( 'dashboard', 'not_reviewed' ), array( $by_tool['remote_test_catalog_write_v1']['standard_tier'], $by_tool['remote_test_catalog_write_v1']['availability'] ) );
		$this->assertSame( array( null, 'not_available' ), array( $by_tool['remote_test_catalog_bad_v1']['standard_tier'], $by_tool['remote_test_catalog_bad_v1']['availability'] ) );
		$this->assertSame( 'not_available_yet', $by_tool['get_site_clone_policy_status_v1']['availability'], 'unreleased extension entry stays inert' );
		$this->assertSame( array( 'mainwp-remote-mcp', 'read', 'available' ), array( $by_tool['get_remote_job_status_v1']['category'], $by_tool['get_remote_job_status_v1']['standard_tier'], $by_tool['get_remote_job_status_v1']['availability'] ) );
		foreach ( $catalog as $row ) {
			$this->assertIsString( $row['reason'] );
			$this->assertContains( $row['availability'], array( 'available', 'not_reviewed', 'not_available_yet', 'not_available' ) );
		}
	}
}
