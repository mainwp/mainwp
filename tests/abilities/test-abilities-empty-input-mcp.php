<?php
/**
 * Tests that read-only abilities with no required input accept omitted input.
 *
 * The MCP client sends read-only abilities as a GET request with no query
 * string when the caller passes no arguments, so the Abilities API receives no
 * `input` at all. An ability whose input schema declares `'type' => 'object'`
 * rejects that request with `ability_invalid_input` ("input is not of type
 * object"), which makes the ability uninvokable over MCP with default
 * arguments. Declaring `'type' => array( 'object', 'null' )` lets the omitted
 * (null) input validate, matching the pattern already used by list-sites-v1.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests the empty-input contract for read-only abilities.
 *
 * @group abilities
 */
class Test_Abilities_Empty_Input_Mcp extends MainWP_Abilities_Test_Case {

	/**
	 * Read-only abilities whose input is entirely optional, so the MCP client
	 * invokes them with no `input` parameter. Each must accept null input.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function optional_input_ability_provider(): array {
		return array(
			'get-backup-status'      => array( 'mainwp/get-backup-status-v1' ),
			'query-sites-segment'    => array( 'mainwp/query-sites-segment-v1' ),
			'get-fleet-versions'     => array( 'mainwp/get-fleet-versions-v1' ),
			'get-uptime-stats'       => array( 'mainwp/get-uptime-stats-v1' ),
			'list-monitor-incidents' => array( 'mainwp/list-monitor-incidents-v1' ),
			'query-activity'         => array( 'mainwp/query-activity-v1' ),
			'get-activity-summary'   => array( 'mainwp/get-activity-summary-v1' ),
			'get-network-snapshot'   => array( 'mainwp/get-network-snapshot-v1' ),
			'list-upcoming-renewals' => array( 'mainwp/list-upcoming-renewals-v1' ),
		);
	}

	/**
	 * Omitted (null) input must validate against the ability's input schema.
	 *
	 * This reproduces the exact validation the Abilities API `/run` endpoint
	 * runs (`WP_Ability::validate_input()` -> `rest_validate_value_from_schema`)
	 * for an MCP argument-less GET.
	 *
	 * @dataProvider optional_input_ability_provider
	 *
	 * @param string $ability_name Ability slug.
	 *
	 * @return void
	 */
	public function test_optional_input_ability_accepts_null_input( string $ability_name ) {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability, "Ability {$ability_name} should be registered." );

		$result = $ability->validate_input( null );
		$this->assertNotWPError(
			$result,
			"Ability {$ability_name} must accept omitted (null) input so the MCP client can invoke it with default arguments."
		);
	}
}
