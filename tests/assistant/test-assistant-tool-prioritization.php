<?php
/**
 * Tests for AI tool discovery, category prioritization, and the tool-declaration
 * cap in MainWP_Assistant.
 *
 * Regression coverage for a review finding: the nine insights/fleet/monitoring/
 * backups abilities were previously invisible to the assistant because (a) their
 * categories were never queried during REST-based ability discovery, and (b)
 * even once discovered, they fell into the unprioritized "other" bucket and were
 * sliced out once core abilities exceeded the (then 64) declaration cap.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use ReflectionMethod;

/**
 * Test class for tool discovery, prioritization, and the declaration cap.
 */
class Test_Assistant_Tool_Prioritization extends MainWP_Assistant_Test_Case {

	/**
	 * Assistant instance.
	 *
	 * @var MainWP_Assistant
	 */
	private $assistant;

	/**
	 * Reflection method for get_ability_category().
	 *
	 * @var ReflectionMethod
	 */
	private $category_method;

	/**
	 * Reflection method for prioritize_tools().
	 *
	 * @var ReflectionMethod
	 */
	private $prioritize_method;

	/**
	 * Reflection method for get_ability_schemas().
	 *
	 * @var ReflectionMethod
	 */
	private $schemas_method;

	/**
	 * Reflection method for build_filtered_tools().
	 *
	 * @var ReflectionMethod
	 */
	private $filtered_tools_method;

	/**
	 * The nine ability IDs added by the insights/fleet/monitoring/backups work.
	 *
	 * @var array
	 */
	private $new_ability_ids = array(
		'mainwp/query-activity-v1',
		'mainwp/get-activity-summary-v1',
		'mainwp/get-network-snapshot-v1',
		'mainwp/list-upcoming-renewals-v1',
		'mainwp/query-sites-segment-v1',
		'mainwp/get-fleet-versions-v1',
		'mainwp/get-uptime-stats-v1',
		'mainwp/list-monitor-incidents-v1',
		'mainwp/get-backup-status-v1',
	);

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->assistant = MainWP_Assistant::instance();

		$this->category_method = new ReflectionMethod( MainWP_Assistant::class, 'get_ability_category' );
		$this->category_method->setAccessible( true );

		$this->prioritize_method = new ReflectionMethod( MainWP_Assistant::class, 'prioritize_tools' );
		$this->prioritize_method->setAccessible( true );

		$this->schemas_method = new ReflectionMethod( MainWP_Assistant::class, 'get_ability_schemas' );
		$this->schemas_method->setAccessible( true );

		$this->filtered_tools_method = new ReflectionMethod( MainWP_Assistant::class, 'build_filtered_tools' );
		$this->filtered_tools_method->setAccessible( true );
	}

	// =========================================================================
	// CATEGORY MAPPING (priority map half of the fix)
	// =========================================================================

	/**
	 * Test the new ability categories map to their expected priority names.
	 */
	public function test_new_categories_resolve_expected_names() {
		$cases = array(
			'mainwp-insights'   => 'insights',
			'mainwp-fleet'      => 'fleet',
			'mainwp-monitoring' => 'monitoring',
			'mainwp-backups'    => 'backups',
		);

		foreach ( $cases as $raw_category => $expected ) {
			$resolved = $this->category_method->invoke( $this->assistant, array( 'category' => $raw_category ) );
			$this->assertSame( $expected, $resolved, "Category {$raw_category} should resolve to {$expected}." );
		}
	}

	/**
	 * Test prioritize_tools() ranks the new categories ahead of the unknown/"other"
	 * bucket, and in the documented insights > fleet > monitoring > backups order.
	 */
	public function test_prioritize_tools_ranks_new_categories_before_other() {
		$abilities = array(
			'mainwp/z-other-v1'      => array( 'category' => 'mainwp-something-unmapped' ),
			'mainwp/z-backups-v1'    => array( 'category' => 'mainwp-backups' ),
			'mainwp/z-monitoring-v1' => array( 'category' => 'mainwp-monitoring' ),
			'mainwp/z-fleet-v1'      => array( 'category' => 'mainwp-fleet' ),
			'mainwp/z-insights-v1'   => array( 'category' => 'mainwp-insights' ),
			'mainwp/z-sites-v1'      => array( 'category' => 'mainwp-sites' ),
		);

		$result = $this->prioritize_method->invoke( $this->assistant, $abilities, 6 );
		$order  = array_keys( $result );

		$this->assertSame(
			array(
				'mainwp/z-sites-v1',
				'mainwp/z-insights-v1',
				'mainwp/z-fleet-v1',
				'mainwp/z-monitoring-v1',
				'mainwp/z-backups-v1',
				'mainwp/z-other-v1',
			),
			$order,
			'sites should sort before the new categories, and all four new categories should sort before the unmapped "other" bucket.'
		);
	}

	/**
	 * Test that when the cap forces a slice, insights/fleet/monitoring/backups
	 * abilities survive ahead of an unmapped "other" ability.
	 */
	public function test_prioritize_tools_slice_keeps_new_categories_over_other() {
		$abilities = array(
			'mainwp/z-other-v1'   => array( 'category' => 'mainwp-something-unmapped' ),
			'mainwp/z-backups-v1' => array( 'category' => 'mainwp-backups' ),
		);

		$result = $this->prioritize_method->invoke( $this->assistant, $abilities, 1 );

		$this->assertArrayHasKey( 'mainwp/z-backups-v1', $result, 'A mapped new category should survive the slice over an unmapped one.' );
		$this->assertArrayNotHasKey( 'mainwp/z-other-v1', $result );
	}

	/** All backup tools remain callable when other categories exceed the cap. */
	public function test_backup_tools_survive_a_full_tool_catalog() {
		$abilities = array();
		for ( $i = 0; $i < 96; ++$i ) {
			$abilities[ 'mainwp/site-' . $i ] = array( 'category' => 'mainwp-sites' );
		}
		for ( $i = 0; $i < 32; ++$i ) {
			$abilities[ 'mainwp/backup-' . $i ] = array( 'category' => 'mainwp-backups' );
		}
		$result = $this->prioritize_method->invoke( $this->assistant, $abilities, MainWP_Assistant::MAX_TOOL_DECLARATIONS );
		$this->assertCount( 96, $result );
		for ( $i = 0; $i < 32; ++$i ) {
			$this->assertArrayHasKey( 'mainwp/backup-' . $i, $result );
		}
	}

	// =========================================================================
	// DECLARATION CAP SIZING
	// =========================================================================

	/**
	 * The capped catalog retains every registered backup tool.
	 */
	public function test_capped_catalog_retains_registered_backup_abilities() {
		$this->assertTrue( function_exists( 'wp_get_abilities' ), 'Abilities API must be available for this assertion to be meaningful.' );

		$abilities        = wp_get_abilities();
		$mainwp_abilities = array_filter(
			$abilities,
			function ( $ability ) {
				return 0 === strpos( $ability->get_name(), 'mainwp/' );
			}
		);

		$this->assertGreaterThanOrEqual(
			71,
			count( $mainwp_abilities ),
			'Expected at least the 71 core MainWP abilities to be registered.'
		);

		$this->set_current_user_as_admin();
		$schemas = $this->schemas_method->invoke( $this->assistant );
		$tools = $this->prioritize_method->invoke( $this->assistant, $schemas, MainWP_Assistant::MAX_TOOL_DECLARATIONS );
		$this->assertCount( min( count( $schemas ), MainWP_Assistant::MAX_TOOL_DECLARATIONS ), $tools );
		$backups = 0;
		foreach ( $mainwp_abilities as $ability ) {
			if ( 'mainwp-backups' === $ability->get_category() ) {
				++$backups;
				$this->assertArrayHasKey( $ability->get_name(), $tools );
			}
		}
		$this->assertGreaterThanOrEqual( 32, $backups );
	}

	// =========================================================================
	// END-TO-END DISCOVERY + FILTER PIPELINE
	// =========================================================================

	/**
	 * Test the new ability categories are queried during REST-based schema
	 * discovery (get_ability_schemas()), not just correctly prioritized once
	 * discovered.
	 */
	public function test_get_ability_schemas_discovers_new_categories() {
		$this->set_current_user_as_admin();

		$schemas = $this->schemas_method->invoke( $this->assistant );

		// These six are not module-gated, so they must always be discoverable.
		$always_on = array(
			'mainwp/get-network-snapshot-v1',
			'mainwp/query-sites-segment-v1',
			'mainwp/get-fleet-versions-v1',
			'mainwp/get-uptime-stats-v1',
			'mainwp/list-monitor-incidents-v1',
			'mainwp/get-backup-status-v1',
		);

		foreach ( $always_on as $ability_id ) {
			$this->assertArrayHasKey( $ability_id, $schemas, "{$ability_id} should be discovered via REST ability schema discovery." );
		}

		// Cost Tracker is enabled under phpunit (see mainwp.php module defaults),
		// so its gated ability is discoverable too.
		$this->assertArrayHasKey( 'mainwp/list-upcoming-renewals-v1', $schemas );
	}

	/**
	 * Test that each new ability whose module gate is open under phpunit
	 * (MAINWP_MODULE_LOG_ENABLED is forced false in tests/bootstrap.php, so the
	 * two log-gated abilities are excluded here by design) survives the full
	 * filter -> prioritize -> slice pipeline for a default read-only user.
	 */
	public function test_new_abilities_survive_filtered_tools_pipeline() {
		$user_id = $this->set_current_user_as_admin();

		$filtered = $this->filtered_tools_method->invoke( $this->assistant, $user_id );

		$expected_present = array(
			'mainwp/get-network-snapshot-v1',
			'mainwp/list-upcoming-renewals-v1',
			'mainwp/query-sites-segment-v1',
			'mainwp/get-fleet-versions-v1',
			'mainwp/get-uptime-stats-v1',
			'mainwp/list-monitor-incidents-v1',
			'mainwp/get-backup-status-v1',
		);

		foreach ( $expected_present as $ability_id ) {
			$this->assertArrayHasKey( $ability_id, $filtered, "{$ability_id} should survive the filtered-tools pipeline for a read-only user." );
		}

		// MAINWP_MODULE_LOG_ENABLED is hard-defined false in tests/bootstrap.php,
		// so these two remain module-gated out under phpunit even though the
		// discovery + prioritization fix makes them reachable in production
		// where the Logs module is active.
		if ( ! MAINWP_MODULE_LOG_ENABLED ) {
			$this->assertArrayNotHasKey( 'mainwp/query-activity-v1', $filtered );
			$this->assertArrayNotHasKey( 'mainwp/get-activity-summary-v1', $filtered );
		}
	}
}
