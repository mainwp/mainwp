<?php
/**
 * Remote MCP tier registry tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\MCP_Tier_Registry;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\MCP_Tool_Catalog;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Plan_Types;

require_once __DIR__ . '/fixtures/endpoint-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Tier_Registry
 */
class Test_MainWP_MCP_Tier_Registry extends \WP_UnitTestCase {

	use Remote_MCP_Endpoint_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Core abilities from the audited tier table: ability => [ tier (the strictest one for
	 * conditional rows), resolver, adapter ]. reconnect-site(s) are write, and admin_username takes
	 * update-site's own tier. Labels mapped through Remote_MCP_Plan_Types' docblock.
	 *
	 * @var array
	 */
	const AUDITED_CORE = array(
		'list-sites'               => array( 'read', 'none', 'direct' ),
		'get-site'                 => array( 'read', 'none', 'direct' ),
		'sync-sites'               => array( 'write', 'sites', 'chunked_sites' ),
		'get-site-plugins'         => array( 'read', 'none', 'direct' ),
		'get-site-themes'          => array( 'read', 'none', 'direct' ),
		'add-site'                 => array( 'dashboard', 'none', 'atomic' ),
		'update-site'              => array( 'dashboard', 'site', 'atomic' ),
		'delete-site'              => array( 'dashboard', 'site', 'chunked_sites' ),
		'reconnect-site'           => array( 'write', 'site', 'chunked_sites' ),
		'disconnect-site'          => array( 'dashboard', 'site', 'chunked_sites' ),
		'suspend-site'             => array( 'confirm', 'site', 'chunked_sites' ),
		'unsuspend-site'           => array( 'write', 'site', 'chunked_sites' ),
		'check-site'               => array( 'write', 'site', 'chunked_sites' ),
		'activate-site-plugins'    => array( 'confirm', 'site', 'chunked_sites' ),
		'deactivate-site-plugins'  => array( 'confirm', 'site', 'chunked_sites' ),
		'delete-site-plugins'      => array( 'dashboard', 'site', 'chunked_sites' ),
		'get-abandoned-plugins'    => array( 'read', 'none', 'direct' ),
		'activate-site-theme'      => array( 'confirm', 'site', 'chunked_sites' ),
		'delete-site-themes'       => array( 'dashboard', 'site', 'chunked_sites' ),
		'get-abandoned-themes'     => array( 'read', 'none', 'direct' ),
		'get-site-security'        => array( 'read', 'none', 'direct' ),
		'get-site-changes'         => array( 'read', 'none', 'direct' ),
		'get-site-client'          => array( 'read', 'none', 'direct' ),
		'get-site-costs'           => array( 'read', 'none', 'direct' ),
		'count-sites'              => array( 'read', 'none', 'direct' ),
		'get-sites-basic'          => array( 'read', 'none', 'direct' ),
		'reconnect-sites'          => array( 'write', 'sites', 'chunked_sites' ),
		'disconnect-sites'         => array( 'dashboard', 'sites', 'chunked_sites' ),
		'check-sites'              => array( 'write', 'sites', 'chunked_sites' ),
		'suspend-sites'            => array( 'confirm', 'sites', 'chunked_sites' ),
		'list-updates'             => array( 'read', 'none', 'direct' ),
		'run-updates'              => array( 'confirm', 'sites', 'updates' ),
		'list-ignored-updates'     => array( 'read', 'none', 'direct' ),
		'set-ignored-updates'      => array( 'write', 'site', 'atomic' ),
		'unignore-site-updates'    => array( 'confirm', 'site', 'atomic' ),
		'get-site-updates'         => array( 'read', 'none', 'direct' ),
		'update-site-core'         => array( 'confirm', 'site', 'updates' ),
		'update-site-plugins'      => array( 'confirm', 'site', 'updates' ),
		'update-site-themes'       => array( 'confirm', 'site', 'updates' ),
		'update-site-translations' => array( 'confirm', 'site', 'updates' ),
		'ignore-site-core'         => array( 'write', 'site', 'atomic' ),
		'ignore-site-plugins'      => array( 'write', 'site', 'atomic' ),
		'ignore-site-themes'       => array( 'write', 'site', 'atomic' ),
		'update-all'               => array( 'confirm', 'sites', 'updates' ),
		'get-batch-job-status'     => array( 'read', 'none', 'direct' ),
		'list-clients'             => array( 'read', 'none', 'direct' ),
		'count-clients'            => array( 'read', 'none', 'direct' ),
		'get-client'               => array( 'read', 'none', 'direct' ),
		'add-client'               => array( 'confirm', 'none', 'atomic' ),
		'update-client'            => array( 'confirm', 'client', 'atomic' ),
		'delete-client'            => array( 'dashboard', 'client', 'atomic' ),
		'get-client-sites'         => array( 'read', 'none', 'direct' ),
		'count-client-sites'       => array( 'read', 'none', 'direct' ),
		'get-client-costs'         => array( 'read', 'none', 'direct' ),
		'suspend-client'           => array( 'confirm', 'client', 'atomic' ),
		'unsuspend-client'         => array( 'write', 'client', 'atomic' ),
		'list-tags'                => array( 'read', 'none', 'direct' ),
		'get-tag'                  => array( 'read', 'none', 'direct' ),
		'add-tag'                  => array( 'write', 'none', 'atomic' ),
		'update-tag'               => array( 'write', 'tag', 'atomic' ),
		'delete-tag'               => array( 'confirm', 'tag', 'atomic' ),
		'get-tag-sites'            => array( 'read', 'none', 'direct' ),
		'get-tag-clients'          => array( 'read', 'none', 'direct' ),
		'get-knowledge-record'     => array( 'read', 'none', 'direct' ),
		'get-site-knowledge'       => array( 'read', 'none', 'direct' ),
		'get-client-knowledge'     => array( 'read', 'none', 'direct' ),
		'list-knowledge'           => array( 'read', 'none', 'direct' ),
		'create-knowledge-record'  => array( 'confirm', 'none', 'atomic' ),
		'update-knowledge-record'  => array( 'confirm', 'none', 'atomic' ),
		'delete-knowledge-record'  => array( 'confirm', 'none', 'atomic' ),
	);

	/**
	 * mainwp-backups abilities from the audited tier table: ability => [ tier, resolver, adapter ].
	 * Every row's contract is dry_run, the write rows included, because each backup write refuses a
	 * call without dry_run or confirm.
	 *
	 * @var array
	 */
	const AUDITED_BACKUPS = array(
		'get-backup-status'              => array( 'read', 'none', 'direct' ),
		'list-api-backups-providers'     => array( 'read', 'none', 'direct' ),
		'list-api-backups-site-status'   => array( 'read', 'none', 'direct' ),
		'list-backup-history'            => array( 'read', 'none', 'direct' ),
		'list-backup-destinations'       => array( 'read', 'none', 'direct' ),
		'get-backup-destination'         => array( 'read', 'none', 'direct' ),
		'get-backup-settings'            => array( 'read', 'none', 'direct' ),
		'list-backup-tasks'              => array( 'read', 'none', 'direct' ),
		'get-backup-task'                => array( 'read', 'none', 'direct' ),
		'get-backup-task-run'            => array( 'read', 'none', 'direct' ),
		'get-site-backup-progress'       => array( 'read', 'none', 'direct' ),
		'list-backup-snapshots'          => array( 'read', 'none', 'direct' ),
		'get-backup-snapshot'            => array( 'read', 'none', 'direct' ),
		'list-restore-jobs'              => array( 'read', 'none', 'direct' ),
		'get-restore-job'                => array( 'read', 'none', 'direct' ),
		'run-site-backup'                => array( 'confirm', 'site', 'atomic' ),
		'run-backup-task'                => array( 'confirm', 'none', 'atomic' ),
		'cancel-site-backup'             => array( 'confirm', 'site', 'atomic' ),
		'reset-backup-task-run'          => array( 'confirm', 'none', 'atomic' ),
		'cleanup-site-backups'           => array( 'dashboard', 'site', 'atomic' ),
		'delete-backup-snapshot'         => array( 'dashboard', 'site', 'atomic' ),
		'create-backup-task'             => array( 'confirm', 'none', 'atomic' ),
		'update-backup-task'             => array( 'dashboard', 'none', 'atomic' ),
		'delete-backup-task'             => array( 'dashboard', 'none', 'atomic' ),
		'pause-backup-task'              => array( 'confirm', 'none', 'atomic' ),
		'resume-backup-task'             => array( 'write', 'none', 'atomic' ),
		'restore-site-snapshot'          => array( 'dashboard', 'site', 'atomic' ),
		'cancel-restore'                 => array( 'dashboard', 'none', 'atomic' ),
		'rollback-restore'               => array( 'dashboard', 'none', 'atomic' ),
		'test-backup-destination'        => array( 'write', 'none', 'atomic' ),
		'set-default-backup-destination' => array( 'confirm', 'none', 'atomic' ),
		'update-backup-destination'      => array( 'dashboard', 'none', 'atomic' ),
		'delete-backup-destination'      => array( 'dashboard', 'none', 'atomic' ),
		'update-backup-settings'         => array( 'dashboard', 'none', 'atomic' ),
	);

	/**
	 * Extension writes from the audited tier table: ability => [ outcome source, tier, resolver,
	 * adapter ]. Reads are `read` / none / direct. Replacing the custom dashboard snippet has a
	 * tier and is not refused.
	 *
	 * @var array
	 */
	const AUDITED_EXTENSION_WRITES = array(
		'set-global-cache-control-policy-v1'               => array( 'audit', 'confirm', 'none', 'atomic' ),
		'set-site-cache-control-policy-v1'                 => array( 'audit', 'write', 'site', 'atomic' ),
		'purge-site-cache-v1'                              => array( 'audit', 'confirm', 'site', 'atomic' ),
		'set-clone-enabled-v1'                             => array( 'audit', 'dashboard', 'none', 'atomic' ),
		'set-site-clone-participation-v1'                  => array( 'audit', 'dashboard', 'site', 'atomic' ),
		'validate-custom-dashboard-php-v1'                 => array( 'audit', 'write', 'none', 'atomic' ),
		'replace-custom-dashboard-snippet-v1'              => array( 'audit', 'dashboard', 'none', 'atomic' ),
		'clear-custom-dashboard-snippet-v1'                => array( 'audit', 'confirm', 'none', 'atomic' ),
		'replace-site-google-search-console-mapping-v1'    => array( 'audit', 'confirm', 'site', 'atomic' ),
		'replace-google-search-console-refresh-policy-v1'  => array( 'audit', 'confirm', 'none', 'atomic' ),
		'request-site-google-search-console-refresh-v1'    => array( 'uncontracted', 'dashboard', 'site', 'atomic' ),
		'request-google-search-console-catalog-refresh-v1' => array( 'uncontracted', 'dashboard', 'none', 'atomic' ),
		'replace-matomo-connection-v1'                     => array( 'audit', 'dashboard', 'none', 'atomic' ),
		'replace-site-matomo-assignment-v1'                => array( 'audit', 'confirm', 'site', 'atomic' ),
		'request-matomo-property-refresh-v1'               => array( 'uncontracted', 'dashboard', 'none', 'atomic' ),
		'request-site-matomo-refresh-v1'                   => array( 'uncontracted', 'dashboard', 'site', 'atomic' ),
		'cancel-matomo-operation-v1'                       => array( 'audit', 'confirm', 'none', 'atomic' ),
		'replace-updraftplus-policy-v1'                    => array( 'audit', 'dashboard', 'site', 'atomic' ),
		'start-updraftplus-backup-v1'                      => array( 'audit', 'confirm', 'site', 'atomic' ),
		'cancel-updraftplus-operation-v1'                  => array( 'audit', 'confirm', 'site', 'atomic' ),
		'delete-updraftplus-backup-v1'                     => array( 'audit', 'dashboard', 'site', 'atomic' ),
		'restore-updraftplus-backup-v1'                    => array( 'audit', 'dashboard', 'site', 'atomic' ),
		'replace-termageddon-credential-v1'                => array( 'audit', 'dashboard', 'none', 'atomic' ),
		'disconnect-termageddon-v1'                        => array( 'audit', 'dashboard', 'none', 'atomic' ),
		'replace-termageddon-site-policy-v1'               => array( 'audit', 'confirm', 'site', 'atomic' ),
		'remove-termageddon-page-v1'                       => array( 'audit', 'dashboard', 'site', 'atomic' ),
	);

	/** Stable native-preview effect fields from the extension implementations. */
	const AUDITED_NATIVE_COMPARE_FIELDS = array(
		'replace-site-matomo-assignment-v1'  => array( 'site_id', 'desired_state', 'would_change', 'cache_effect', 'conflict_state' ),
		'replace-updraftplus-policy-v1'      => array( 'site_id', 'schedule_changed', 'policy_generation' ),
		'cancel-updraftplus-operation-v1'    => array( 'operation_ref', 'state', 'quiescent' ),
		'delete-updraftplus-backup-v1'       => array( 'backup_ref', 'component_count', 'locations' ),
		'restore-updraftplus-backup-v1'      => array( 'backup_ref', 'component_count', 'state' ),
		'replace-termageddon-credential-v1'  => array( 'policy_set_count', 'mappings_to_revalidate' ),
		'disconnect-termageddon-v1'          => array( 'mappings_to_invalidate', 'pages_to_mark_stale' ),
		'replace-termageddon-site-policy-v1' => array( 'action', 'target_mapping_generation' ),
		'remove-termageddon-page-v1'         => array( 'deletion_mode', 'child_effect', 'target_generation' ),
	);

	/**
	 * Extension reads from the audited tier table.
	 *
	 * @var string[]
	 */
	const AUDITED_EXTENSION_READS = array(
		'get-site-cache-control-status-v1',
		'list-site-cache-control-statuses-v1',
		'get-global-cache-control-configuration-v1',
		'validate-cloudflare-credentials-v1',
		'get-site-cache-purge-outcome-v1',
		'get-site-clone-policy-status-v1',
		'get-custom-dashboard-snippet-metadata-v1',
		'list-google-search-console-connections-v1',
		'list-google-search-console-properties-v1',
		'get-site-google-search-console-mapping-v1',
		'get-site-google-search-console-observation-v1',
		'get-google-search-console-refresh-policy-v1',
		'get-google-search-console-refresh-status-v1',
		'get-matomo-connection-v1',
		'list-matomo-properties-v1',
		'get-site-matomo-assignment-v1',
		'preview-site-matomo-assignment-v1',
		'get-site-matomo-observation-v1',
		'get-site-matomo-trend-v1',
		'get-matomo-operation-v1',
		'get-updraftplus-site-v1',
		'get-updraftplus-policy-v1',
		'list-updraftplus-backups-v1',
		'get-updraftplus-backup-v1',
		'get-updraftplus-operation-v1',
		'preview-updraftplus-restore-v1',
		'get-woocommerce-shortcuts-status-v1',
		'get-termageddon-connection-v1',
		'get-termageddon-policy-sets-v1',
		'get-termageddon-site-policy-v1',
		'list-termageddon-pages-v1',
		'get-termageddon-page-v1',
		'get-termageddon-operation-v1',
	);

	/**
	 * Registry over the default services.
	 *
	 * @return MCP_Tier_Registry
	 */
	private function registry(): MCP_Tier_Registry {
		return $this->services()->get( 'tier_registry' );
	}

	public function test_tier_registry_entries_match_the_audited_tier_table() {
		$this->assertInstanceOf( MCP_Tier_Registry::class, $this->registry(), 'the implementing class fills the slot' );
		$keys = array( 'source', 'tier', 'conditional', 'contract', 'resolver', 'adapter', 'sensitive', 'deferred', 'credential_bearing', 'gate_supplied', 'compare_fields', 'min_version', 'inert', 'reason' );
		$this->assertCount( 70, self::AUDITED_CORE );
		foreach ( self::AUDITED_CORE as $short => list( $tier, $resolver, $adapter ) ) {
			$entry = $this->registry()->entry( 'mainwp/' . $short . '-v1' );
			$this->assertIsArray( $entry, $short );
			$this->assertSame( $keys, array_keys( $entry ), $short );
			$this->assertSame( array( 'core', $tier, $resolver, $adapter ), array( $entry['source'], $entry['tier'], $entry['resolver'], $entry['adapter'] ), $short );
		}
		$this->assertCount( 34, self::AUDITED_BACKUPS );
		$this->assertCount( count( self::AUDITED_CORE ) + count( self::AUDITED_BACKUPS ), MCP_Tool_Catalog::CORE_TIERS, 'nothing outside the audited tier table' );
		foreach ( self::AUDITED_BACKUPS as $short => list( $tier, $resolver, $adapter ) ) {
			$entry = $this->registry()->entry( 'mainwp/' . $short . '-v1' );
			$this->assertIsArray( $entry, $short );
			$this->assertSame( $keys, array_keys( $entry ), $short );
			$expected = array( 'core', $tier, $resolver, $adapter, 'read' === $tier ? null : 'dry_run', array(), false, in_array( $short, array( 'run-backup-task', 'delete-backup-task', 'cleanup-site-backups' ), true ) ? array( 'would_affect' ) : array() );
			$this->assertSame( $expected, array( $entry['source'], $entry['tier'], $entry['resolver'], $entry['adapter'], $entry['contract']['preview']['kind'] ?? null, $entry['sensitive'], $entry['credential_bearing'], $entry['compare_fields'] ), $short );
		}
		$extensions = array_merge(
			self::AUDITED_EXTENSION_WRITES,
			array_fill_keys( self::AUDITED_EXTENSION_READS, array( 'audit', 'read', 'none', 'direct' ) )
		);
		$this->assertCount( count( $extensions ), MCP_Tool_Catalog::EXTENSION_TIERS, 'nothing outside the audited tier table' );
		foreach ( $extensions as $short => list( $source, $tier, $resolver, $adapter ) ) {
			$name = 'mainwp/' . $short;
			$this->assertArrayHasKey( $name, MCP_Tool_Catalog::EXTENSION_TIERS );
			if ( 'uncontracted' === $source ) {
				$this->ability(
					$name,
					array(
						'readonly'    => false,
						'destructive' => true,
					)
				);
			}
			$entry = $this->registry()->entry( $name );
			$this->assertIsArray( $entry, $short );
			$this->assertSame( $keys, array_keys( $entry ), $short );
			$this->assertSame( array( $source, $tier, $resolver, $adapter ), array( $entry['source'], $entry['tier'], $entry['resolver'], $entry['adapter'] ), $short );
		}

		$native = array();
		foreach ( MCP_Tool_Catalog::EXTENSION_TIERS as $name => $row ) {
			$entry = $this->registry()->entry( $name );
			if ( ! empty( $entry['contract']['execute']['native_token_field'] ) ) {
				$native[] = substr( $name, 7 );
			}
		}
		$this->assertSame( array_keys( self::AUDITED_NATIVE_COMPARE_FIELDS ), $native );
		foreach ( self::AUDITED_NATIVE_COMPARE_FIELDS as $short => $fields ) {
			$entry = $this->registry()->entry( 'mainwp/' . $short );
			$this->assertSame( $fields, $entry['compare_fields'], $short );
			$this->assertSame( $fields, $entry['contract']['compare_fields'], $short );
		}
		foreach ( MCP_Tool_Catalog::CORE_TIERS as $name => $row ) {
			if ( 'updates' === $row[2] ) {
				$entry = $this->registry()->entry( $name );
				$this->assertSame( array(), $entry['compare_fields'], $name );
				$this->assertSame( array(), $entry['contract']['compare_fields'], $name );
			}
		}

		// Every entry names frozen plan types, and the value-conditional rows carry their callbacks.
		foreach ( array_merge( array_keys( MCP_Tool_Catalog::CORE_TIERS ), array_keys( MCP_Tool_Catalog::EXTENSION_TIERS ) ) as $name ) {
			$entry = $this->registry()->entry( $name );
			$this->assertContains( $entry['resolver'], Remote_MCP_Plan_Types::RESOLVERS, $name );
			$this->assertContains( $entry['adapter'], Remote_MCP_Plan_Types::ADAPTERS, $name );
		}
		$conditional = array(
			'mainwp/update-site-v1'                  => array(
				array( array( 'name' => 'x' ), 'write' ),
				array( array( 'admin_username' => 'x' ), 'write' ),
				array( array( 'suspended' => 1 ), 'confirm' ),
				array( array( 'url' => 'https://a.example' ), 'dashboard' ),
				array( array( 'verify_certificate' => 0 ), 'dashboard' ),
				array( array( 'ssl_version' => 'tlsv1_2' ), 'dashboard' ),
				array( array( 'http_user' => 'u' ), 'dashboard' ),
				array( array( 'http_pass' => 'p' ), 'dashboard' ),
			),
			'mainwp/add-client-v1'                   => array(
				array( array( 'name' => 'Acme' ), 'write' ),
				array( array( 'selected_sites' => array( 1 ) ), 'confirm' ),
			),
			'mainwp/update-client-v1'                => array(
				array( array( 'client_id' => 2 ), 'write' ),
				array( array( 'selected_sites' => array() ), 'confirm' ),
			),
			'mainwp/set-clone-enabled-v1'            => array(
				array( array( 'enabled' => false ), 'write' ),
				array( array( 'enabled' => true ), 'dashboard' ),
				array( array( 'enabled' => 'false' ), 'dashboard' ),
			),
			'mainwp/set-site-clone-participation-v1' => array(
				array( array( 'allowed' => false ), 'write' ),
				array( array( 'allowed' => true ), 'dashboard' ),
			),
			'mainwp/update-backup-destination-v1'    => array(
				array( array( 'minimum_remote_retention_days' => 0 ), 'dashboard' ),
				array( array( 'minimum_remote_retention_days' => 30 ), 'dashboard' ),
				array( array( 'name' => 'Offsite' ), 'confirm' ),
				array( array(), 'confirm' ),
			),
			'mainwp/remove-termageddon-page-v1'      => array(
				array( array( 'deletion_mode' => 'unlink_local' ), 'confirm' ),
				array( array( 'deletion_mode' => 'delete_child_and_unlink' ), 'dashboard' ),
				array( array(), 'dashboard' ),
			),
		);
		foreach ( array_merge( array_keys( MCP_Tool_Catalog::CORE_TIERS ), array_keys( MCP_Tool_Catalog::EXTENSION_TIERS ) ) as $name ) {
			$entry = $this->registry()->entry( $name );
			if ( isset( $conditional[ $name ] ) ) {
				$this->assertIsString( $entry['conditional'], $name );
				foreach ( $conditional[ $name ] as list( $args, $tier ) ) {
					$this->assertSame( $tier, MCP_Tool_Catalog::conditional_tier( $entry['conditional'], $args ), $name . ' ' . wp_json_encode( $args ) );
				}
			} else {
				$this->assertNull( $entry['conditional'], $name );
			}
		}

		// Tier table details the gate relies on.
		$this->assertSame( array( 'request_ref' ), $this->registry()->entry( 'mainwp/replace-site-google-search-console-mapping-v1' )['gate_supplied'] );
		$this->assertTrue( $this->registry()->entry( 'mainwp/replace-matomo-connection-v1' )['credential_bearing'] );
		$this->assertContains( 'api_token', $this->registry()->entry( 'mainwp/replace-matomo-connection-v1' )['sensitive'] );
		$this->assertContains( 'api_key', $this->registry()->entry( 'mainwp/replace-termageddon-credential-v1' )['sensitive'] );
		$this->assertContains( 'adminpassword', $this->registry()->entry( 'mainwp/add-site-v1' )['sensitive'] );
		$this->assertSame( 'preview_token', $this->registry()->entry( 'mainwp/replace-site-matomo-assignment-v1' )['contract']['execute']['native_token_field'] );
		$this->assertSame( 'mainwp/preview-site-matomo-assignment-v1', $this->registry()->entry( 'mainwp/replace-site-matomo-assignment-v1' )['contract']['preview']['ability'] );
		$this->assertSame( array( 'dry_run' => true ), $this->registry()->entry( 'mainwp/delete-site-v1' )['contract']['preview']['controls'] );
		$this->assertSame(
			array(
				'dry_run' => false,
				'confirm' => true,
			),
			$this->registry()->entry( 'mainwp/delete-site-v1' )['contract']['execute']['controls']
		);
		$this->assertTrue( $this->registry()->entry( 'mainwp/replace-updraftplus-policy-v1' )['inert'], 'UpdraftPlus stays inert' );
	}

	public function test_native_preview_effect_change_changes_baseline() {
		$entry    = $this->registry()->entry( 'mainwp/replace-termageddon-site-policy-v1' );
		$fields   = $entry['contract']['compare_fields'];
		$preview  = array(
			'action'                    => 'replace',
			'target_mapping_generation' => str_repeat( 'a', 64 ),
			'preview_token'             => 'native-before',
			'expires_at'                => '2026-10-20T12:00:00Z',
		);
		$baseline = $this->hasher->preview_baseline( $preview, $fields );
		$this->assertNotNull( $baseline );
		$renewed = array_merge(
			$preview,
			array(
				'preview_token' => 'native-after',
				'expires_at'    => '2026-10-20T12:05:00Z',
			)
		);
		$this->assertSame( $baseline, $this->hasher->preview_baseline( $renewed, $fields ), 'renewing the native token preserves the effect' );
		$renewed['target_mapping_generation'] = str_repeat( 'b', 64 );
		$this->assertNotSame( $baseline, $this->hasher->preview_baseline( $renewed, $fields ), 'changed mapping effect must stale approval' );

		$credential = $this->registry()->entry( 'mainwp/replace-termageddon-credential-v1' )['contract']['compare_fields'];
		$before     = array(
			'policy_set_count'          => 2,
			'mappings_to_revalidate'    => 3,
			'target_account_generation' => 'random-before',
		);
		$after      = array_merge( $before, array( 'target_account_generation' => 'random-after' ) );
		$this->assertSame( $this->hasher->preview_baseline( $before, $credential ), $this->hasher->preview_baseline( $after, $credential ) );
		$after['mappings_to_revalidate'] = 4;
		$this->assertNotSame( $this->hasher->preview_baseline( $before, $credential ), $this->hasher->preview_baseline( $after, $credential ) );
	}

	public function test_native_token_contract_without_effect_fields_is_refused() {
		foreach ( array( 'token', 'matomo_assignment' ) as $kind ) {
			$entry = MCP_Tool_Catalog::entry( 'audit', OAuth_Store::TIER_CONFIRM, 'site', 'atomic', $kind, null, array(), array(), 'test' );
			$this->assertSame( 'refused', $entry['source'], $kind );
			$this->assertNull( $entry['contract'], $kind );
			$this->assertSame( 'missing_effect_fields', $entry['reason'], $kind );
		}
	}

	public function test_effective_tier_takes_declared_contract_tier_through_tier_registry() {
		$policy = $this->services()->get( 'scope_policy' );
		$write  = array(
			'readonly'    => false,
			'destructive' => false,
		);

		// No table entry: a declared contract sets the tier.
		$this->ability( 'mainwp/remote-test-declared-v1', $write );
		$this->contracts->declared['mainwp/remote-test-declared-v1'] = Remote_MCP_Endpoint_Test_Contracts::contract( 'confirm' );
		$entry = $this->registry()->entry( 'mainwp/remote-test-declared-v1' );
		$this->assertSame( array( 'declared', 'confirm' ), array( $entry['source'], $entry['tier'] ) );
		$this->assertSame( 'confirm', $entry['contract']['tier'] );
		$this->assertSame( 'confirm', $policy->effective_tier( $this->context(), 'mainwp/remote-test-declared-v1', array() ) );
		$this->assertSame( 'dashboard', $policy->effective_tier( $this->context( array( 'policy' => 'strict' ) ), 'mainwp/remote-test-declared-v1', array() ) );

		// A declared contract can never claim write; the ability stays on the uncontracted path.
		$this->ability( 'mainwp/remote-test-declared-write-v1', $write );
		$this->contracts->declared['mainwp/remote-test-declared-write-v1'] = Remote_MCP_Endpoint_Test_Contracts::contract( 'write' );
		$this->assertSame( array( 'uncontracted', 'dashboard' ), array_values( array_intersect_key( $this->registry()->entry( 'mainwp/remote-test-declared-write-v1' ), array_flip( array( 'source', 'tier' ) ) ) ) );

		// An "uncontracted path" entry in the tier table yields to a declared contract.
		$gsc = 'mainwp/request-site-google-search-console-refresh-v1';
		$this->ability( $gsc, $write );
		$this->assertSame( 'uncontracted', $this->registry()->entry( $gsc )['source'] );
		$declared                          = Remote_MCP_Endpoint_Test_Contracts::contract( 'confirm' );
		$declared['deferred']              = true;
		$this->contracts->declared[ $gsc ] = $declared;
		$entry                             = $this->registry()->entry( $gsc );
		$this->assertSame( array( 'declared', 'confirm', true ), array( $entry['source'], $entry['tier'], $entry['deferred'] ) );
		$this->assertSame( 'confirm', $policy->effective_tier( $this->context(), $gsc, array() ) );

		// An explicit tier in the tier table wins over a declared contract.
		$mapping                               = 'mainwp/replace-site-google-search-console-mapping-v1';
		$this->contracts->declared[ $mapping ] = Remote_MCP_Endpoint_Test_Contracts::contract( 'dashboard' );
		$entry                                 = $this->registry()->entry( $mapping );
		$this->assertSame( array( 'audit', 'confirm' ), array( $entry['source'], $entry['tier'] ) );
		$this->assertSame( 'confirm', $policy->effective_tier( $this->context(), $mapping, array() ) );
	}
}
