<?php
/**
 * MainWP Abilities Discovery Tests
 *
 * Tests for ability and category registration and discoverability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Abilities_Discovery_Test
 *
 * Tests that abilities and categories are properly registered and discoverable.
 */
class MainWP_Abilities_Discovery_Test extends MainWP_Abilities_Test_Case { //phpcs:ignore -- NOSONAR - multi methods.

	/**
	 * Discovery must distinguish removal of holds from adding them.
	 *
	 * @return void
	 */
	public function test_c3_unignore_discovery_and_add_only_annotations() {
		$this->skip_if_no_abilities_api();
		$ability = wp_get_ability( 'mainwp/unignore-site-updates-v1' );
		$this->assertNotNull( $ability );
		$this->assertSame( 'mainwp-updates', $ability->get_category() );
		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
		$this->assertStringStartsWith( 'Removes update holds on the Dashboard. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user.', $meta['annotations']['instructions'] );
		$schema = $ability->get_input_schema();
		$this->assertSame( array( 'site_id_or_domain', 'type' ), $schema['required'] );
		$this->assertSame( array( 'core', 'plugin', 'theme' ), $schema['properties']['type']['enum'] );
		$this->assertSame( 1, $schema['properties']['slugs']['minItems'] );
		foreach ( array( 'confirm', 'dry_run' ) as $flag ) {
			$this->assertSame( 'boolean', $schema['properties'][ $flag ]['type'] );
			$this->assertFalse( $schema['properties'][ $flag ]['default'] );
		}
		$output = $ability->get_output_schema();
		$this->assertCount( 2, $output['oneOf'] );
		foreach ( array( true, false ) as $index => $value ) {
			$this->assertSame( $value, $output['oneOf'][ $index ]['properties']['dry_run']['const'] );
			$this->assertContains( 'dry_run', $output['oneOf'][ $index ]['required'] );
		}
		foreach ( array( 'set-ignored-updates', 'ignore-site-core', 'ignore-site-plugins', 'ignore-site-themes' ) as $name ) {
			$old         = wp_get_ability( 'mainwp/' . $name . '-v1' );
			$annotations = $old->get_meta()['annotations'];
			$this->assertFalse( $annotations['readonly'] );
			$this->assertFalse( $annotations['destructive'] );
			$this->assertTrue( $annotations['idempotent'] );
			$this->assertStringContainsString( 'mainwp/unignore-site-updates-v1', $annotations['instructions'] );
			$this->assertSame( 'set-ignored-updates' === $name ? array( 'ignore', 'unignore' ) : array( 'add', 'remove' ), $old->get_input_schema()['properties']['action']['enum'] );
		}
	}

	/**
	 * Shared instruction sentences must remain discoverable by tool consumers.
	 *
	 * @return array
	 */
	public static function c3_hold_ability_instruction_sentences(): array {
		$replacement = 'To remove holds, use mainwp/unignore-site-updates-v1.';
		return array(
			'set-ignored-updates'   => array( 'mainwp/set-ignored-updates-v1', $replacement ),
			'ignore-site-core'      => array( 'mainwp/ignore-site-core-v1', $replacement ),
			'ignore-site-plugins'   => array( 'mainwp/ignore-site-plugins-v1', $replacement ),
			'ignore-site-themes'    => array( 'mainwp/ignore-site-themes-v1', $replacement ),
			'unignore-site-updates' => array( 'mainwp/unignore-site-updates-v1', 'Global holds and whole-site ignore flags remain in effect.' ),
		);
	}

	/**
	 * Descriptions must leave workflow instructions in their dedicated field.
	 *
	 * @dataProvider c3_hold_ability_instruction_sentences
	 * @param string $name Registered ability name.
	 * @param string $sentence Instruction sentence.
	 * @return void
	 */
	public function test_c3_hold_ability_description_does_not_repeat_instructions( $name, $sentence ) {
		$this->skip_if_no_abilities_api();
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability );
		$this->assertStringContainsString( strtolower( $sentence ), strtolower( $ability->get_meta()['annotations']['instructions'] ) );
		$this->assertStringNotContainsString( strtolower( $sentence ), strtolower( $ability->get_description() ) );
	}

	/**
	 * Test that all MainWP abilities are discoverable.
	 *
	 * @return void
	 */
	public function test_abilities_are_discoverable() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$mainwp_abilities = array_filter(
			$abilities,
			function ( $ability ) {
				return 0 === strpos( $ability->get_name(), 'mainwp/' );
			}
		);

		// Verify the expected abilities exist.
		$expected = array(
			'mainwp/get-backup-status-v1',
			'mainwp/list-backup-history-v1',
			'mainwp/list-backup-tasks-v1',
			'mainwp/get-backup-task-v1',
			'mainwp/get-backup-task-run-v1',
			'mainwp/get-site-backup-progress-v1',
			'mainwp/list-backup-snapshots-v1',
			'mainwp/get-backup-snapshot-v1',
			'mainwp/list-restore-jobs-v1',
			'mainwp/get-restore-job-v1',
			'mainwp/list-backup-destinations-v1',
			'mainwp/get-backup-destination-v1',
			'mainwp/get-backup-settings-v1',
			'mainwp/run-site-backup-v1',
			'mainwp/run-backup-task-v1',
			'mainwp/cancel-site-backup-v1',
			'mainwp/reset-backup-task-run-v1',
			'mainwp/cleanup-site-backups-v1',
			'mainwp/create-backup-task-v1',
			'mainwp/update-backup-task-v1',
			'mainwp/delete-backup-task-v1',
			'mainwp/pause-backup-task-v1',
			'mainwp/resume-backup-task-v1',

			'mainwp/list-sites-v1',
			'mainwp/get-site-v1',
			'mainwp/sync-sites-v1',
			'mainwp/get-site-plugins-v1',
			'mainwp/get-site-themes-v1',
			'mainwp/list-updates-v1',
			'mainwp/run-updates-v1',
			'mainwp/list-ignored-updates-v1',
			'mainwp/set-ignored-updates-v1',
			'mainwp/unignore-site-updates-v1',
		);

		$ability_names = array_map(
			function ( $ability ) {
				return $ability->get_name();
			},
			$mainwp_abilities
		);

		foreach ( $expected as $name ) {
			$this->assertContains(
				$name,
				$ability_names,
				"Expected ability {$name} to be registered."
			);
		}

		// Allow additional abilities beyond the expected set (future-proof).
		$this->assertGreaterThanOrEqual(
			count( $expected ),
			count( $mainwp_abilities ),
			'MainWP should register at least ' . count( $expected ) . ' abilities.'
		);
	}

	/**
	 * Test that MainWP categories are registered.
	 *
	 * @return void
	 */
	public function test_categories_are_registered() {
		$this->skip_if_no_abilities_api();

		if ( ! function_exists( 'wp_get_ability_categories' ) ) {
			$this->markTestSkipped( 'wp_get_ability_categories function not available.' );
		}

		$categories = wp_get_ability_categories();
		$this->assertArrayHasKey( 'mainwp-backups', $categories );

		$this->assertArrayHasKey(
			'mainwp-sites',
			$categories,
			'mainwp-sites category should be registered.'
		);
		$this->assertArrayHasKey(
			'mainwp-updates',
			$categories,
			'mainwp-updates category should be registered.'
		);
	}

	/**
	 * Test that ability schemas are valid.
	 *
	 * Validates both input and output schema structure for all abilities,
	 * including checking that output schemas have the expected keys for
	 * their ability type (list, sync, updates, etc.).
	 *
	 * Note: Some schema properties are conditional at runtime:
	 * - sync-sites: 'synced'/'errors' only present for immediate execution,
	 *   'job_id'/'status_url' only present when queued=true.
	 * - run-updates: similar conditional structure based on queued state.
	 *
	 * These tests validate the schema declares these properties, not that
	 * they're always present in responses.
	 *
	 * @return void
	 */
	public function test_ability_schemas_are_valid() {
		$this->skip_if_no_abilities_api();

		// Map abilities to their expected output properties.
		// These are keys that must exist in the output schema definition.
		// Note: Some properties are conditional at runtime (e.g., 'synced' only
		// for immediate responses, 'job_id' only for queued responses).
		$abilities_with_expected_output = array(
			// List abilities should have items and total.
			'mainwp/list-sites-v1'            => array( 'items', 'total' ),
			// Get abilities return single object, properties vary.
			'mainwp/get-site-v1'              => array( 'id', 'url', 'name' ),
			// Sync abilities - schema includes both immediate and queued response properties.
			// At runtime, 'synced' only present for immediate; 'job_id' only for queued.
			'mainwp/sync-sites-v1'            => array( 'synced', 'errors', 'queued' ),
			// Plugin getter returns site_id and plugins array.
			'mainwp/get-site-plugins-v1'      => array( 'site_id', 'plugins' ),
			// Theme getter returns site_id, themes array, and active_theme.
			'mainwp/get-site-themes-v1'       => array( 'site_id', 'themes', 'active_theme' ),
			// Update abilities.
			'mainwp/list-updates-v1'          => array( 'updates', 'summary' ),
			'mainwp/run-updates-v1'           => array( 'updated', 'errors', 'queued' ),
			// List ignored returns 'ignored' array and 'total' count.
			'mainwp/list-ignored-updates-v1'  => array( 'ignored', 'total' ),
			// Set ignored echoes back the action performed.
			'mainwp/set-ignored-updates-v1'   => array( 'success', 'action', 'site_id', 'type', 'slug' ),
			'mainwp/unignore-site-updates-v1' => array( 'dry_run', 'would_affect', 'count', 'warnings', 'errors', 'removed', 'not_held' ),
		);

		foreach ( $abilities_with_expected_output as $name => $expected_keys ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "Ability {$name} should exist." );

			// Check input schema.
			$input_schema = $ability->get_input_schema();
			$this->assertIsArray( $input_schema, "Input schema for {$name} should be an array." );
			$this->assertArrayHasKey( 'type', $input_schema, "Input schema for {$name} should have 'type' key." );
			// Type can be a string ('object') or an array (['object', 'null']) for nullable schemas.
			$type           = $input_schema['type'];
			$is_object_type = ( 'object' === $type ) || ( is_array( $type ) && in_array( 'object', $type, true ) );
			$this->assertTrue( $is_object_type, "Input schema for {$name} should include 'object' type." );
			$this->assertArrayHasKey( 'properties', $input_schema, "Input schema for {$name} should have 'properties' key." );

			// Check output schema structure.
			$output_schema = $ability->get_output_schema();
			$this->assertIsArray( $output_schema, "Output schema for {$name} should be an array." );
			$this->assertArrayHasKey( 'type', $output_schema, "Output schema for {$name} should have 'type' key." );
			$this->assertEquals( 'object', $output_schema['type'], "Output schema for {$name} should be of type 'object'." );

			// Handle polymorphic schemas (using oneOf) vs standard properties.
			// Some abilities like run-updates-v1 use oneOf to define different response shapes
			// for immediate vs queued execution modes.
			if ( isset( $output_schema['oneOf'] ) && is_array( $output_schema['oneOf'] ) ) {
				// For oneOf schemas, collect all properties from all variants.
				$output_properties = array();
				foreach ( $output_schema['oneOf'] as $variant ) {
					if ( isset( $variant['properties'] ) ) {
						$output_properties = array_merge( $output_properties, $variant['properties'] );
					}
				}
				$this->assertNotEmpty( $output_properties, "Output schema for {$name} with oneOf should have properties in variants." );
			} else {
				$this->assertArrayHasKey( 'properties', $output_schema, "Output schema for {$name} should have 'properties' key." );
				$output_properties = $output_schema['properties'];
			}

			// Check expected output properties exist.
			foreach ( $expected_keys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$output_properties,
					"Output schema for {$name} should have '{$key}' property."
				);
			}
		}
	}

	/**
	 * Test that ability annotations are present.
	 *
	 * Validates readonly, destructive, and idempotent flags for all abilities
	 * according to the integration plan:
	 * - Readonly abilities: readonly=true, destructive=false, idempotent=true
	 * - Write abilities: readonly=false, destructive=false
	 *   - sync-sites and set-ignored-updates: idempotent=true
	 *   - run-updates: idempotent=false (each run has side effects)
	 *
	 * @return void
	 */
	public function test_ability_annotations_are_present() {
		$this->skip_if_no_abilities_api();

		$readonly_abilities = array(
			'mainwp/list-sites-v1',
			'mainwp/get-site-v1',
			'mainwp/get-site-plugins-v1',
			'mainwp/get-site-themes-v1',
			'mainwp/list-updates-v1',
			'mainwp/list-ignored-updates-v1',
		);

		// Write abilities with their expected idempotent values per integration plan.
		$write_abilities = array(
			'mainwp/sync-sites-v1'               => true,  // Syncing same sites again has no additional effect.
			'mainwp/run-updates-v1'              => false, // Each run may apply different updates.
			'mainwp/update-all-v1'               => false,
			'mainwp/update-site-core-v1'         => false,
			'mainwp/update-site-plugins-v1'      => false,
			'mainwp/update-site-themes-v1'       => false,
			'mainwp/update-site-translations-v1' => false,
			'mainwp/set-ignored-updates-v1'      => true,  // Ignoring same item again has no additional effect.
			'mainwp/ignore-site-core-v1'         => true,
			'mainwp/ignore-site-plugins-v1'      => true,
			'mainwp/ignore-site-themes-v1'       => true,
			'mainwp/unignore-site-updates-v1'    => true,
			'mainwp/run-site-backup-v1'          => false,
			'mainwp/run-backup-task-v1'          => false,
			'mainwp/cancel-site-backup-v1'       => true,
			'mainwp/reset-backup-task-run-v1'    => true,
			'mainwp/cleanup-site-backups-v1'     => false,
			'mainwp/create-backup-task-v1'       => false,
			'mainwp/update-backup-task-v1'       => false,
			'mainwp/delete-backup-task-v1'       => false,
			'mainwp/pause-backup-task-v1'        => true,
			'mainwp/resume-backup-task-v1'       => true,
		);

		// Check readonly abilities - all should be idempotent.
		foreach ( $readonly_abilities as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "Ability {$name} should exist." );

			$meta = $ability->get_meta();
			$this->assertIsArray( $meta, "Meta for {$name} should be an array." );
			$this->assertArrayHasKey( 'annotations', $meta, "Meta for {$name} should have 'annotations' key." );

			$annotations = $meta['annotations'];
			$this->assertTrue( $annotations['readonly'], "Ability {$name} should be readonly." );
			$this->assertFalse( $annotations['destructive'], "Ability {$name} should not be destructive." );

			// All readonly abilities should be idempotent.
			if ( isset( $annotations['idempotent'] ) ) {
				$this->assertTrue(
					$annotations['idempotent'],
					"Readonly ability {$name} should be idempotent."
				);
			}
		}

		// Check write abilities with expected idempotent values.
		foreach ( $write_abilities as $name => $expected_idempotent ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "Ability {$name} should exist." );

			$meta = $ability->get_meta();
			$this->assertIsArray( $meta, "Meta for {$name} should be an array." );
			$this->assertArrayHasKey( 'annotations', $meta, "Meta for {$name} should have 'annotations' key." );

			$annotations = $meta['annotations'];
			$this->assertFalse( $annotations['readonly'], "Ability {$name} should not be readonly." );
			$expected_destructive = in_array(
				$name,
				array(
					'mainwp/run-updates-v1',
					'mainwp/update-all-v1',
					'mainwp/update-site-core-v1',
					'mainwp/update-site-plugins-v1',
					'mainwp/update-site-themes-v1',
					'mainwp/update-site-translations-v1',
					'mainwp/unignore-site-updates-v1',
					'mainwp/run-site-backup-v1',
					'mainwp/run-backup-task-v1',
					'mainwp/cancel-site-backup-v1',
					'mainwp/reset-backup-task-run-v1',
					'mainwp/cleanup-site-backups-v1',
					'mainwp/update-backup-task-v1',
					'mainwp/delete-backup-task-v1',
				),
				true
			);
			$this->assertSame( $expected_destructive, $annotations['destructive'], "Ability {$name} destructive annotation should match its update behavior." );

			// Check idempotent flag matches expected value.
			if ( isset( $annotations['idempotent'] ) ) {
				$this->assertSame(
					$expected_idempotent,
					$annotations['idempotent'],
					"Ability {$name} idempotent should be " . ( $expected_idempotent ? 'true' : 'false' ) . '.'
				);
			}
		}
	}

	/**
	 * Test that list-sites ability is registered with correct properties.
	 *
	 * @return void
	 */
	public function test_list_sites_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/list-sites-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/list-sites-v1 should be registered.' );

		// Check category.
		$this->assertEquals( 'mainwp-sites', $ability->get_category(), 'Ability should be in mainwp-sites category.' );

		// Check input schema has expected properties.
		$input_schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'page', $input_schema['properties'], 'Input schema should have page property.' );
		$this->assertArrayHasKey( 'per_page', $input_schema['properties'], 'Input schema should have per_page property.' );
		$this->assertArrayHasKey( 'status', $input_schema['properties'], 'Input schema should have status property.' );
		$this->assertArrayHasKey( 'search', $input_schema['properties'], 'Input schema should have search property.' );

		// Check output schema has expected properties.
		$output_schema = $ability->get_output_schema();
		$this->assertArrayHasKey( 'items', $output_schema['properties'], 'Output schema should have items property.' );
		$this->assertArrayHasKey( 'page', $output_schema['properties'], 'Output schema should have page property.' );
		$this->assertArrayHasKey( 'per_page', $output_schema['properties'], 'Output schema should have per_page property.' );
		$this->assertArrayHasKey( 'total', $output_schema['properties'], 'Output schema should have total property.' );
	}

	/**
	 * Test that get-site ability is registered with correct properties.
	 *
	 * @return void
	 */
	public function test_get_site_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/get-site-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/get-site-v1 should be registered.' );

		// Check category.
		$this->assertEquals( 'mainwp-sites', $ability->get_category(), 'Ability should be in mainwp-sites category.' );

		// Check input schema has required site_id_or_domain.
		$input_schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'site_id_or_domain', $input_schema['properties'], 'Input schema should have site_id_or_domain property.' );
		$this->assertContains( 'site_id_or_domain', $input_schema['required'], 'site_id_or_domain should be required.' );
	}

	/**
	 * Test that sync-sites ability is registered with correct properties.
	 *
	 * @return void
	 */
	public function test_sync_sites_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/sync-sites-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/sync-sites-v1 should be registered.' );

		// Check category.
		$this->assertEquals( 'mainwp-sites', $ability->get_category(), 'Ability should be in mainwp-sites category.' );

		// Check meta.
		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'], 'sync-sites should not be readonly.' );
		$this->assertTrue( $meta['annotations']['idempotent'], 'sync-sites should be idempotent.' );
	}

	/**
	 * Test that update abilities are registered.
	 *
	 * @return void
	 */
	public function test_update_abilities_are_registered() {
		$this->skip_if_no_abilities_api();

		$update_abilities = array(
			'mainwp/list-updates-v1',
			'mainwp/run-updates-v1',
			'mainwp/list-ignored-updates-v1',
			'mainwp/set-ignored-updates-v1',
			'mainwp/unignore-site-updates-v1',
		);

		foreach ( $update_abilities as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "Ability {$name} should be registered." );
			$this->assertEquals( 'mainwp-updates', $ability->get_category(), "Ability {$name} should be in mainwp-updates category." );
		}
	}

	/**
	 * Test that run-updates ability is not idempotent.
	 *
	 * @return void
	 */
	public function test_run_updates_is_not_idempotent() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/run-updates-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/run-updates-v1 should be registered.' );

		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['idempotent'], 'run-updates should not be idempotent.' );
	}
}
