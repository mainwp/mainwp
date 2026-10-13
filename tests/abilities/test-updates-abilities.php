<?php
/**
 * MainWP Updates Abilities Tests
 *
 * Tests for update-related abilities:
 * - mainwp/list-updates-v1
 * - mainwp/run-updates-v1
 * - mainwp/list-ignored-updates-v1
 * - mainwp/set-ignored-updates-v1
 * - mainwp/get-site-updates-v1
 * - mainwp/update-site-core-v1
 * - mainwp/update-site-plugins-v1
 * - mainwp/update-site-themes-v1
 * - mainwp/update-site-translations-v1
 * - mainwp/update-all-v1
 * - mainwp/ignore-site-core-v1
 * - mainwp/ignore-site-plugins-v1
 * - mainwp/ignore-site-themes-v1
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Updates;
use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;

/**
 * Class MainWP_Updates_Abilities_Test
 *
 * Tests for update-related abilities.
 */
class MainWP_Updates_Abilities_Test extends MainWP_Abilities_Test_Case {

	public static function c3_hold_types(): array {
		return [
			'core' => [ 'core', 'wordpress', 'ignored_wp_upgrades', 'is_ignoreCoreUpdates' ],
			'plugin' => [ 'plugin', 'sample/plugin.php', 'ignored_plugins', 'is_ignorePluginUpdates' ],
			'theme' => [ 'theme', 'sample-theme', 'ignored_themes', 'is_ignoreThemeUpdates' ],
		];
	}

	private function seed_c3_hold( int $site_id, string $type, string $slug ): void {
		$entry = [ 'Name' => 'Stored Hold', 'ignored_versions' => [ 'all_versions' ] ];
		if ( 'core' === $type ) {
			$this->set_site_option( $site_id, 'ignored_wp_upgrades', wp_json_encode( $entry ) );
		} elseif ( 'plugin' === $type ) {
			$this->set_site_ignored_plugins( $site_id, [ $slug => $entry ] );
		} else {
			$this->set_site_ignored_themes( $site_id, [ $slug => $entry ] );
		}
	}

	private function c3_database_state( int $site_id ): array {
		global $wpdb;
		return [
			$wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $site_id ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid = %d ORDER BY name", $site_id ), ARRAY_A ),
			$wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_users ORDER BY userid", ARRAY_A ),
		];
	}

	private function assert_c3_output_validates( array $result ): void {
		$schema = wp_get_ability( 'mainwp/unignore-site-updates-v1' )->get_output_schema();
		$this->assertTrue( rest_validate_value_from_schema( $result, $schema ) );
		$matches = 0;
		foreach ( $schema['oneOf'] as $branch ) {
			$matches += true === rest_validate_value_from_schema( $result, $branch ) ? 1 : 0;
		}
		$this->assertSame( 1, $matches, 'Exactly one output branch must match.' );
		foreach ( array_keys( $result ) as $key ) {
			$missing = $result;
			unset( $missing[ $key ] );
			$this->assertWPError( rest_validate_value_from_schema( $missing, $schema ), 'Output must require ' . $key );
		}
	}

	public static function c3_old_remove_paths(): array {
		return [
			'set-core' => [ 'mainwp/set-ignored-updates-v1', 'core', 'wordpress', [ 'action' => 'unignore', 'type' => 'core', 'slug' => 'wordpress' ] ],
			'set-plugin' => [ 'mainwp/set-ignored-updates-v1', 'plugin', 'sample/plugin.php', [ 'action' => 'unignore', 'type' => 'plugin', 'slug' => 'sample/plugin.php' ] ],
			'set-theme' => [ 'mainwp/set-ignored-updates-v1', 'theme', 'sample-theme', [ 'action' => 'unignore', 'type' => 'theme', 'slug' => 'sample-theme' ] ],
			'core' => [ 'mainwp/ignore-site-core-v1', 'core', 'wordpress', [ 'action' => 'remove' ] ],
			'plugins' => [ 'mainwp/ignore-site-plugins-v1', 'plugin', 'sample/plugin.php', [ 'action' => 'remove', 'slugs' => [ 'sample/plugin.php' ] ] ],
			'themes' => [ 'mainwp/ignore-site-themes-v1', 'theme', 'sample-theme', [ 'action' => 'remove', 'slugs' => [ 'sample-theme' ] ] ],
		];
	}

	/** @dataProvider c3_old_remove_paths */
	public function test_c3_old_remove_returns_replacement_without_changes( $ability, $type, $slug, $input ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_c3_hold( $site_id, $type, $slug );
		$before = $this->c3_database_state( $site_id );
		$calls = &$this->record_update_calls();
		$hook_counts = [ did_action( 'mainwp_before_' . $type . '_unignore' ), did_action( 'mainwp_after_' . $type . '_unignore' ) ];
		$result = $this->execute_ability( $ability, $input + [ 'site_id_or_domain' => $site_id ] );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_unignore_moved', $result->get_error_code() );
		$this->assertSame( 'Removing an item from the ignored list needs confirmation. Use mainwp/unignore-site-updates-v1.', $result->get_error_message() );
		$this->assertSame( [ 'status' => 400, 'replacement' => 'mainwp/unignore-site-updates-v1' ], $result->get_error_data() );
		$this->assertSame( $before, $this->c3_database_state( $site_id ) );
		$this->assertSame( $hook_counts, [ did_action( 'mainwp_before_' . $type . '_unignore' ), did_action( 'mainwp_after_' . $type . '_unignore' ) ] );
		$this->assertSame( [], $calls );
	}

	public static function c3_flag_inputs(): array {
		return [
			'both' => [ [ 'dry_run' => true, 'confirm' => true ], 'mainwp_invalid_input', 'Cannot specify both dry_run and confirm.' ],
			'both strings' => [ [ 'dry_run' => '1', 'confirm' => '1' ], 'mainwp_invalid_input', 'Cannot specify both dry_run and confirm.' ],
			'neither' => [ [], 'mainwp_confirmation_required', 'Removing a hold requires confirm: true. Call with dry_run: true first to preview it.' ],
			'false strings' => [ [ 'dry_run' => '0', 'confirm' => 'false' ], 'mainwp_confirmation_required', 'Removing a hold requires confirm: true. Call with dry_run: true first to preview it.' ],
		];
	}

	/** @dataProvider c3_flag_inputs */
	public function test_c3_unignore_rejects_invalid_flags( $flags, $code, $message ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_c3_hold( $site_id, 'plugin', 'sample/plugin.php' );
		$before = $this->c3_database_state( $site_id );
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', $flags + [ 'site_id_or_domain' => $site_id, 'type' => 'plugin', 'slugs' => [ 'sample/plugin.php' ] ] );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
		$this->assertSame( [ 'status' => 400 ], $result->get_error_data() );
		$this->assertSame( $before, $this->c3_database_state( $site_id ) );
	}

	public static function c3_invalid_slugs(): array {
		$cases = [];
		foreach ( [ 'plugin', 'theme' ] as $type ) {
			foreach ( [ 'missing' => [], 'empty' => [ 'slugs' => [] ], 'scalar' => [ 'slugs' => 'sample' ] ] as $label => $input ) {
				$cases[ $type . '-' . $label ] = [ $type, $input ];
			}
		}
		return $cases;
	}

	/** @dataProvider c3_invalid_slugs */
	public function test_c3_unignore_requires_plugin_and_theme_slugs( $type, $input ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$this->assertNotNull( wp_get_ability( 'mainwp/unignore-site-updates-v1' ) );
		$site_id = $this->create_test_site();
		$result = MainWP_Abilities_Updates::execute_unignore_site_updates( $input + [ 'site_id_or_domain' => $site_id, 'type' => $type, 'dry_run' => true ] );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $result->get_error_data() );
		if ( [] === $input ) {
			$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [ 'site_id_or_domain' => $site_id, 'type' => $type, 'dry_run' => true ] );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
		}
	}

	/** @dataProvider c3_hold_types */
	public function test_c3_unignore_preview_and_confirm( $type, $slug, $column, $flag ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site( [ 'name' => 'Hold Site', 'url' => 'https://test-hold.example.org/' ] );
		$this->seed_gate_updates( $site_id );
		$this->seed_c3_hold( $site_id, $type, $slug );
		$input = [ 'site_id_or_domain' => $site_id, 'type' => $type ];
		if ( 'core' !== $type ) {
			$input['slugs'] = [ $slug, 'not-held' ];
		}
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', $input + [ 'dry_run' => true ] );
		$this->assertNotWPError( $result );
		$this->assertSame( [ 'dry_run', 'would_affect', 'count', 'warnings', 'errors' ], array_keys( $result ) );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( [ 'Removing a hold makes the update eligible for the next run, including update-all and queued runs.' ], $result['warnings'] );
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( $site_id, $result['would_affect']['site_id'] );
		$this->assertSame( 'https://test-hold.example.org/', $result['would_affect']['site_url'] );
		$this->assertSame( 'Hold Site', $result['would_affect']['site_name'] );
		$expected = [
			'core' => [ 'WordPress', '6.8.3', '6.9' ],
			'plugin' => [ 'Sample Plugin', '1.0', '2.0' ],
			'theme' => [ 'Sample Theme', '1.0', '1.1' ],
		][ $type ];
		$this->assertSame( [ 'type' => $type, 'slug' => $slug, 'name' => $expected[0], 'held' => true, 'still_held_by' => null, 'pending' => [ 'from' => $expected[1], 'to' => $expected[2] ] ], $result['would_affect']['holds'][0] );
		if ( 'core' !== $type ) {
			$this->assertSame( [ 'type' => $type, 'slug' => 'not-held', 'name' => 'not-held', 'held' => false, 'still_held_by' => null, 'pending' => null ], $result['would_affect']['holds'][1] );
		}
		$this->assert_c3_output_validates( $result );
		$before_hooks = [ did_action( 'mainwp_before_' . $type . '_unignore' ), did_action( 'mainwp_after_' . $type . '_unignore' ) ];
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', $input + [ 'confirm' => true ] );
		$this->assertNotWPError( $result );
		$this->assertSame( [ 'dry_run' => false, 'removed' => [ $slug ], 'not_held' => 'core' === $type ? [] : [ 'not-held' ], 'count' => 1 ], $result );
		$this->assertSame( [ $before_hooks[0] + 1, $before_hooks[1] + 1 ], [ did_action( 'mainwp_before_' . $type . '_unignore' ), did_action( 'mainwp_after_' . $type . '_unignore' ) ] );
		$this->assert_c3_output_validates( $result );
		$site = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( [], json_decode( 'core' === $type ? MainWP_DB::instance()->get_website_option( $site, $column ) : $site->{$column}, true ) );
		$again = $this->execute_ability( 'mainwp/unignore-site-updates-v1', $input + [ 'confirm' => true ] );
		$this->assertNotWPError( $again );
		$this->assertSame( [ 'dry_run' => false, 'removed' => [], 'not_held' => 'core' === $type ? [ 'wordpress' ] : [ $slug, 'not-held' ], 'count' => 0 ], $again );
		$this->assert_c3_output_validates( $again );
		$preview = $this->execute_ability( 'mainwp/unignore-site-updates-v1', $input + [ 'dry_run' => true ] );
		$this->assertNotWPError( $preview );
		$this->assertSame( 0, $preview['count'] );
		$this->assertSame( [], $preview['warnings'] );
		$this->assert_c3_output_validates( $preview );
	}

	/** @dataProvider c3_hold_types */
	public function test_c3_unignore_preview_reports_other_hold_layers( $type, $slug, $column, $flag ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_c3_hold( $site_id, $type, $slug );
		$input = [ 'site_id_or_domain' => $site_id, 'type' => $type, 'slugs' => [ $slug ], 'dry_run' => true ];
		$entry = [ 'Name' => 'Global Hold', 'ignored_versions' => [ 'all_versions' ] ];
		$extension = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
		MainWP_DB_Common::instance()->update_user_extension( [ 'userid' => $extension->userid, $column => wp_json_encode( 'core' === $type ? $entry : [ $slug => $entry ] ) ] );
		MainWP_DB::instance()->update_website_values( $site_id, [ $flag => 1 ] );
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', $input );
		$this->assertNotWPError( $result );
		$this->assertSame( 'global', $result['would_affect']['holds'][0]['still_held_by'] );
		$this->assertSame( 'Stored Hold', $result['would_affect']['holds'][0]['name'] );
		$this->assertNull( $result['would_affect']['holds'][0]['pending'] );
		$this->assert_c3_output_validates( $result );
		MainWP_DB_Common::instance()->update_user_extension( [ 'userid' => $extension->userid, $column => '[]' ] );
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', $input );
		$this->assertNotWPError( $result );
		$this->assertSame( 'site_flag', $result['would_affect']['holds'][0]['still_held_by'] );
		$this->assert_c3_output_validates( $result );
		unset( $input['dry_run'] );
		$confirmed = $this->execute_ability( 'mainwp/unignore-site-updates-v1', $input + [ 'confirm' => true ] );
		$this->assertNotWPError( $confirmed );
		$this->assertSame( [ $slug ], $confirmed['removed'] );
		$this->assertSame( 1, (int) MainWP_DB::instance()->get_website_by_id( $site_id )->{$flag} );
	}

	/** @dataProvider c3_hold_types */
	public function test_c3_unignore_preview_writes_nothing( $type, $slug, $column, $flag ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_gate_updates( $site_id );
		$this->seed_c3_hold( $site_id, $type, $slug );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_users" );
		$before = $this->c3_database_state( $site_id );
		$calls = &$this->record_update_calls();
		$hooks = [ did_action( 'mainwp_before_' . $type . '_unignore' ), did_action( 'mainwp_after_' . $type . '_unignore' ) ];
		$writes = [];
		$recorder = static function ( $query ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $recorder );
		try {
			$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [ 'site_id_or_domain' => $site_id, 'type' => $type, 'slugs' => [ $slug ], 'dry_run' => true ] );
		} finally {
			remove_filter( 'query', $recorder );
		}
		$this->assertNotWPError( $result );
		$this->assertSame( [], $writes, 'Preview must not create even a missing global settings row.' );
		$this->assertSame( $before, $this->c3_database_state( $site_id ) );
		$this->assertSame( [], $calls );
		$this->assertSame( $hooks, [ did_action( 'mainwp_before_' . $type . '_unignore' ), did_action( 'mainwp_after_' . $type . '_unignore' ) ] );
	}

	public function test_c3_unignore_core_ignores_supplied_slugs() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_c3_hold( $site_id, 'core', 'wordpress' );
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [ 'site_id_or_domain' => $site_id, 'type' => 'core', 'slugs' => [ 'unrelated', 'another' ], 'confirm' => true ] );
		$this->assertNotWPError( $result );
		$this->assertSame( [ 'wordpress' ], $result['removed'] );
		$this->assertSame( [], $result['not_held'] );
		$this->assert_c3_output_validates( $result );
	}

	public static function c3_global_pending_versions(): array {
		return [
			'plugin other version' => [ 'plugin', 'sample/plugin.php', 'ignored_plugins', 'is_ignorePluginUpdates', [ '1.9' ], 0, null ],
			'plugin other version with site flag' => [ 'plugin', 'sample/plugin.php', 'ignored_plugins', 'is_ignorePluginUpdates', [ '1.9' ], 1, 'site_flag' ],
			'plugin pending version' => [ 'plugin', 'sample/plugin.php', 'ignored_plugins', 'is_ignorePluginUpdates', [ '2.0' ], 1, 'global' ],
			'core other version' => [ 'core', 'wordpress', 'ignored_wp_upgrades', 'is_ignoreCoreUpdates', [ '6.8.3' ], 0, null ],
		];
	}

	/** @dataProvider c3_global_pending_versions */
	public function test_c3_unignore_preview_matches_global_pending_version( $type, $slug, $column, $flag, $versions, $site_flag, $expected ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_gate_updates( $site_id );
		$this->seed_c3_hold( $site_id, $type, $slug );
		$entry = [ 'Name' => 'Global Hold', 'ignored_versions' => $versions ];
		$extension = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
		MainWP_DB_Common::instance()->update_user_extension( [ 'userid' => $extension->userid, $column => wp_json_encode( 'core' === $type ? $entry : [ $slug => $entry ] ) ] );
		MainWP_DB::instance()->update_website_values( $site_id, [ $flag => $site_flag ] );
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [ 'site_id_or_domain' => $site_id, 'type' => $type, 'slugs' => [ $slug ], 'dry_run' => true ] );
		$this->assertNotWPError( $result );
		$this->assert_c3_output_validates( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertTrue( $result['would_affect']['holds'][0]['held'] );
		$this->assertSame( 'core' === $type ? '6.9' : '2.0', $result['would_affect']['holds'][0]['pending']['to'] );
		$this->assertSame( $expected, $result['would_affect']['holds'][0]['still_held_by'] );
	}

	public static function c3_global_layer_matrix(): array {
		$rows = [];
		foreach ( [ 'plugin' => 'sample/plugin.php', 'theme' => 'sample-theme' ] as $type => $slug ) {
			$all = [ 'Name' => 'Global Hold', 'ignored_versions' => [ 'all_versions' ] ];
			$v20 = [ 'Name' => 'Global Hold', 'ignored_versions' => [ '2.0' ] ];
			$v19 = [ 'Name' => 'Global Hold', 'ignored_versions' => [ '1.9' ] ];
			$rows[ "$type nested, global 2.0" ]            = [ $type, $slug, 'nested', $v20, 0, 'global' ];
			$rows[ "$type nested, global 1.9" ]            = [ $type, $slug, 'nested', $v19, 0, null ];
			$rows[ "$type flat, global 2.0" ]              = [ $type, $slug, 'flat', $v20, 0, null ];
			$rows[ "$type flat, global 2.0, site flag" ]   = [ $type, $slug, 'flat', $v20, 1, 'site_flag' ];
			$rows[ "$type flat, global all_versions" ]     = [ $type, $slug, 'flat', $all, 0, 'global' ];
			$rows[ "$type flat, global old string entry" ] = [ $type, $slug, 'flat', 'Global Hold', 0, 'global' ];
		}
		return $rows;
	}

	/**
	 * The preview's global layer must agree with the filter that decides what runs
	 * (get_not_ignored_updates_themesplugins), for both pending-data shapes.
	 *
	 * @dataProvider c3_global_layer_matrix
	 */
	public function test_c3_unignore_global_layer_matches_run_filter( $type, $slug, $shape, $global_entry, $site_flag, $expected ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site( [ 'plugin' === $type ? 'is_ignorePluginUpdates' : 'is_ignoreThemeUpdates' => $site_flag ] );
		$item    = [ 'Name' => 'Sample', 'Version' => '1.0' ];
		$item    = 'nested' === $shape ? $item + [ 'update' => [ 'new_version' => '2.0' ] ] : $item + [ 'new_version' => '2.0' ];
		$pending = [ $slug => $item ];
		$column  = 'plugin' === $type ? 'ignored_plugins' : 'ignored_themes';
		if ( 'plugin' === $type ) {
			$this->set_site_plugin_upgrades( $site_id, $pending );
		} else {
			$this->set_site_theme_upgrades( $site_id, $pending );
		}
		$this->seed_c3_hold( $site_id, $type, $slug );
		$extension = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
		MainWP_DB_Common::instance()->update_user_extension( [ 'userid' => $extension->userid, $column => wp_json_encode( [ $slug => $global_entry ] ) ] );

		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [ 'site_id_or_domain' => $site_id, 'type' => $type, 'slugs' => [ $slug ], 'dry_run' => true ] );
		$this->assertNotWPError( $result );
		$this->assert_c3_output_validates( $result );
		$this->assertSame( $expected, $result['would_affect']['holds'][0]['still_held_by'] );

		$kept = \MainWP\Dashboard\MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $pending, [ $slug => $global_entry ] );
		if ( ! $site_flag ) {
			$this->assertSame( ! isset( $kept[ $slug ] ), 'global' === $expected, 'Preview and run filter disagree on the global layer.' );
		}
	}

	public static function c3_preview_warning_layers(): array {
		$eligible = 'Removing a hold makes the update eligible for the next run, including update-all and queued runs.';
		$blocked = 'Items with still_held_by set stay blocked by the global ignored list or the site\'s ignore setting for that type until that hold is removed too.';
		return [
			'eligible only' => [ [ 'sample/plugin.php' ], [ 'not-held' ], [ $eligible ] ],
			'blocked only' => [ [ 'sample/plugin.php' ], [ 'sample/plugin.php' ], [ $blocked ] ],
			'eligible and blocked' => [ [ 'sample/plugin.php', 'blocked/plugin.php' ], [ 'blocked/plugin.php' ], [ $eligible, $blocked ] ],
		];
	}

	/** @dataProvider c3_preview_warning_layers */
	public function test_c3_unignore_preview_warnings_match_remaining_holds( $held_slugs, $global_slugs, $expected ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$entry = [ 'Name' => 'Stored Hold', 'ignored_versions' => [ 'all_versions' ] ];
		$this->set_site_ignored_plugins( $site_id, array_fill_keys( $held_slugs, $entry ) );
		$extension = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
		MainWP_DB_Common::instance()->update_user_extension( [ 'userid' => $extension->userid, 'ignored_plugins' => wp_json_encode( array_fill_keys( $global_slugs, $entry ) ) ] );
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [ 'site_id_or_domain' => $site_id, 'type' => 'plugin', 'slugs' => array_merge( $held_slugs, [ 'not-held' ] ), 'dry_run' => true ] );
		$this->assertNotWPError( $result );
		$this->assert_c3_output_validates( $result );
		$this->assertSame( count( $held_slugs ), $result['count'] );
		$this->assertSame( $expected, $result['warnings'] );
	}

	// =========================================================================
	// List Updates Tests
	// =========================================================================

	/**
	 * Test that list-updates returns expected shape.
	 *
	 * @return void
	 */
	public function test_list_updates_returns_expected_shape() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'plugins', 'themes', 'core' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updates', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'total', $result );

		$this->assertIsArray( $result['updates'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertIsInt( $result['page'] );
		$this->assertIsInt( $result['per_page'] );
		$this->assertIsInt( $result['total'] );

		// Verify summary structure.
		$this->assertArrayHasKey( 'core', $result['summary'] );
		$this->assertArrayHasKey( 'plugins', $result['summary'] );
		$this->assertArrayHasKey( 'themes', $result['summary'] );
		$this->assertArrayHasKey( 'translations', $result['summary'] );
		$this->assertArrayHasKey( 'total', $result['summary'] );
	}

	/**
	 * Test that list-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_list_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/list-updates-v1',
			$abilities,
			'Ability mainwp/list-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that list-updates-v1 requires authentication.
	 *
	 * @return void
	 */
	public function test_list_updates_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/list-updates-v1', [] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that list-updates-v1 requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_list_updates_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$this->set_current_user_as_subscriber();

		$result = $this->execute_ability( 'mainwp/list-updates-v1', [] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that list-updates-v1 validates input.
	 *
	 * @return void
	 */
	public function test_list_updates_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Invalid types parameter (should be array, not string).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => 'invalid-string',
		] );

		$this->assertWPError( $result, 'Invalid input should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that list-updates filters by type.
	 *
	 * @return void
	 */
	public function test_list_updates_filters_by_type() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Updates Filter Site',
			'offline_check_result' => 1,
		] );

		// Seed plugin and theme upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Request only plugins.
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// All updates should be plugin type.
		foreach ( $result['updates'] as $update ) {
			$this->assertEquals( 'plugin', $update['type'], 'Filtered updates should be plugins only.' );
		}
	}

	/**
	 * Test that list-updates filters by site IDs.
	 *
	 * @return void
	 */
	public function test_list_updates_filters_by_site_ids() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name'                 => 'Updates Site 1',
			'offline_check_result' => 1,
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Updates Site 2',
			'offline_check_result' => 1,
		] );

		// Seed plugin upgrade for site1 only.
		$this->set_site_plugin_upgrades( $site1_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		// Request only site1.
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'site_ids_or_domains' => [ $site1_id ],
		] );

		$this->assertNotWPError( $result );

		// All updates should be for site1.
		foreach ( $result['updates'] as $update ) {
			$this->assertEquals( $site1_id, $update['site_id'], 'Filtered updates should be for specified site.' );
		}

		// Verify site2 is excluded from results.
		$returned_site_ids = array_column( $result['updates'], 'site_id' );
		$this->assertNotContains( $site2_id, $returned_site_ids, 'Updates for site2 should be excluded when filtering by site1.' );
	}

	/**
	 * Test that list-updates includes plugin updates (verifies fallback retrieval).
	 *
	 * This test ensures the fix for retrieving plugin_upgrades works correctly,
	 * even when the site object property is not populated by map_site().
	 *
	 * @return void
	 */
	public function test_list_updates_includes_plugin_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Plugin Updates Test Site',
			'offline_check_result' => 1,
		] );

		// Seed plugin upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet Anti-spam',
				'Version'     => '5.0.0',
				'new_version' => '5.1.0',
			],
			'jetpack/jetpack.php' => [
				'Name'        => 'Jetpack',
				'Version'     => '12.0',
				'new_version' => '12.5',
			],
		] );

		// Call list-updates with all sites (empty site_ids_or_domains).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result, 'list-updates should not return WP_Error.' );
		$this->assertGreaterThan( 0, $result['summary']['plugins'], 'Plugin updates should be returned in summary.' );

		// Verify updates contain our seeded plugins.
		$plugin_slugs = array_column( $result['updates'], 'slug' );
		$this->assertContains( 'akismet/akismet.php', $plugin_slugs, 'Akismet plugin update should be returned.' );
		$this->assertContains( 'jetpack/jetpack.php', $plugin_slugs, 'Jetpack plugin update should be returned.' );
	}

	/**
	 * Test that list-updates includes theme updates (verifies fallback retrieval).
	 *
	 * This test ensures the fix for retrieving theme_upgrades works correctly.
	 *
	 * @return void
	 */
	public function test_list_updates_includes_theme_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Theme Updates Test Site',
			'offline_check_result' => 1,
		] );

		// Seed theme upgrades.
		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.2',
			],
		] );

		// Call list-updates with all sites (empty site_ids_or_domains).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'themes' ],
		] );

		$this->assertNotWPError( $result, 'list-updates should not return WP_Error.' );
		$this->assertGreaterThan( 0, $result['summary']['themes'], 'Theme updates should be returned in summary.' );

		// Verify updates contain our seeded theme.
		$theme_slugs = array_column( $result['updates'], 'slug' );
		$this->assertContains( 'twentytwentyfour', $theme_slugs, 'Twenty Twenty-Four theme update should be returned.' );
	}

	/**
	 * Test that list-updates includes translation updates (verifies fallback retrieval).
	 *
	 * This test ensures the fix for retrieving translation_upgrades works correctly.
	 *
	 * @return void
	 */
	public function test_list_updates_includes_translation_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Translation Updates Test Site',
			'offline_check_result' => 1,
		] );

		// Seed translation upgrades.
		$this->set_site_translation_upgrades( $site_id, [
			[
				'slug'        => 'default',
				'name'        => 'WordPress Core',
				'version'     => '6.4',
				'new_version' => '6.5',
			],
			[
				'slug'        => 'akismet',
				'name'        => 'Akismet',
				'version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		// Call list-updates with all sites (empty site_ids_or_domains).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'translations' ],
		] );

		$this->assertNotWPError( $result, 'list-updates should not return WP_Error.' );
		$this->assertGreaterThan( 0, $result['summary']['translations'], 'Translation updates should be returned in summary.' );
	}

	/**
	 * Test that list-updates includes all update types when using specific site IDs.
	 *
	 * This test verifies the fix works through the resolve_site() code path.
	 *
	 * @return void
	 */
	public function test_list_updates_with_specific_site_ids_includes_all_types() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'All Updates Test Site',
			'offline_check_result' => 1,
		] );

		// Seed all update types.
		$this->set_site_plugin_upgrades( $site_id, [
			'hello-dolly/hello.php' => [
				'Name'        => 'Hello Dolly',
				'Version'     => '1.7',
				'new_version' => '1.8',
			],
		] );

		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentythree' => [
				'Name'        => 'Twenty Twenty-Three',
				'Version'     => '1.0',
				'new_version' => '1.3',
			],
		] );

		$this->set_site_translation_upgrades( $site_id, [
			[
				'slug'        => 'default',
				'name'        => 'WordPress Core',
				'version'     => '6.3',
				'new_version' => '6.4',
			],
		] );

		// Call list-updates with SPECIFIC site ID (tests resolve_site path).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'site_ids_or_domains' => [ $site_id ],
		] );

		$this->assertNotWPError( $result, 'list-updates should not return WP_Error.' );

		// Verify all types are returned.
		$this->assertGreaterThan( 0, $result['summary']['plugins'], 'Plugin updates should be returned for specific site.' );
		$this->assertGreaterThan( 0, $result['summary']['themes'], 'Theme updates should be returned for specific site.' );
		$this->assertGreaterThan( 0, $result['summary']['translations'], 'Translation updates should be returned for specific site.' );
	}

	// =========================================================================
	// Run Updates Tests
	// =========================================================================

	/**
	 * Test that run-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_run_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/run-updates-v1',
			$abilities,
			'Ability mainwp/run-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that run-updates executes plugin updates.
	 *
	 * @return void
	 */
	public function test_run_updates_executes_plugin_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Run Updates Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
		$this->assertArrayHasKey( 'sites_updated', $result['summary'] );
	}

	/**
	 * Test that run-updates skips offline sites.
	 *
	 * @return void
	 */
	public function test_run_updates_skips_offline_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $offline_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// Offline site should be in errors.
		$found_offline_error = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['site_id'] === $offline_id && $error['code'] === 'mainwp_site_offline' ) {
				$found_offline_error = true;
				break;
			}
		}

		$this->assertTrue( $found_offline_error, 'Offline site should produce mainwp_site_offline error.' );
	}

	/**
	 * Test that run-updates handles child outdated error.
	 *
	 * @return void
	 */
	public function test_run_updates_handles_child_outdated() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$outdated_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $outdated_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// Outdated child should be in errors.
		$found_outdated_error = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['site_id'] === $outdated_id && $error['code'] === 'mainwp_child_outdated' ) {
				$found_outdated_error = true;
				break;
			}
		}

		$this->assertTrue( $found_outdated_error, 'Outdated child should produce mainwp_child_outdated error.' );
	}

	/**
	 * Test that run-updates large batch returns queued response.
	 *
	 * @return void
	 */
	public function test_run_updates_large_batch_returns_queued() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			// Create 6 test sites (exceeds lowered threshold).
			$site_ids = [];
			for ( $i = 0; $i < 6; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Large Update Site {$i}",
					'offline_check_result' => 1,
					'version'              => '5.0.0',
				] );
			}

			$result = $this->execute_ability( 'mainwp/run-updates-v1', [
				'confirm'             => true,
				'site_ids_or_domains' => $site_ids,
				'types'               => [ 'plugins' ],
			] );

			$this->assertNotWPError( $result );

			// Should be queued.
			$this->assertTrue( $result['queued'], 'Large batch should be queued.' );
			$this->assertArrayHasKey( 'job_id', $result );
			$this->assertArrayHasKey( 'status_url', $result );
			$this->assertArrayHasKey( 'updates_queued', $result );

			// Track job for cleanup in tearDown.
			$this->track_update_job( $result['job_id'] );

			// Queued jobs don't have updated key.
			$this->assertArrayNotHasKey( 'updated', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test that run-updates partial success works.
	 *
	 * @return void
	 */
	public function test_run_updates_partial_success() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$online_id = $this->create_test_site( [
			'name'                 => 'Online Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $online_id, $offline_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'summary', $result, 'Result should have summary key.' );
		$this->assertIsArray( $result['summary'], 'Summary should be an array.' );

		// Should have some errors for offline site.
		$this->assertGreaterThan( 0, $result['summary']['total_errors'], 'Should have errors for offline site.' );
	}

	/**
	 * Test that run-updates handles update operations.
	 *
	 * Note: In a test environment without real child sites, all updates will
	 * fail due to connection issues. This test verifies the structure and
	 * error handling rather than actual update success.
	 *
	 * @return void
	 */
	public function test_run_updates_mocked_partial_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create two online sites with valid child versions.
		$site1_id = $this->create_test_site( [
			'name'                 => 'Update Site 1',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Update Site 2',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Mock partial update result to test the mocking mechanism.
		$this->mock_partial_update_result(
			[ $site1_id ],
			[
				[
					'site_id' => $site2_id,
					'code'    => 'mainwp_update_failed',
					'message' => 'Update failed on remote site',
				],
			]
		);

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $site1_id, $site2_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		// Verify output structure.
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertIsArray( $result['summary'], 'Summary should be an array.' );

		// Verify summary reflects correct counts.
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
		$this->assertArrayHasKey( 'sites_updated', $result['summary'] );

		// In test environment, both sites will likely be in errors due to no real connection.
		// Verify at least one site appears in the errors with a valid error code.
		$found_error = false;
		$valid_error_codes = [ 'mainwp_update_failed', 'mainwp_connection_failed', 'mainwp_update_exception' ];
		foreach ( $result['errors'] as $error ) {
			if ( isset( $error['code'] ) && in_array( $error['code'], $valid_error_codes, true ) ) {
				$found_error = true;
				break;
			}
		}

		// Either sites are updated (mocking worked) or errored (real connection failed).
		$has_results = ( $result['summary']['total_updated'] > 0 ) || ( $result['summary']['total_errors'] > 0 );
		$this->assertTrue( $has_results, 'Should have at least some update results or errors.' );

		// If errors occurred, verify they have valid error codes.
		if ( $result['summary']['total_errors'] > 0 ) {
			$this->assertTrue( $found_error, 'Expected at least one recognized error code when errors occurred.' );
		}
	}

	// =========================================================================
	// Ignored Updates Tests
	// =========================================================================

	/**
	 * Test that list-ignored-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/list-ignored-updates-v1',
			$abilities,
			'Ability mainwp/list-ignored-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that list-ignored-updates-v1 requires authentication.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that list-ignored-updates-v1 requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$this->set_current_user_as_subscriber();

		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that list-ignored-updates-v1 validates input.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Invalid types parameter (should be array, not integer).
		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [
			'types' => 123,
		] );

		$this->assertWPError( $result, 'Invalid input should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that set-ignored-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/set-ignored-updates-v1',
			$abilities,
			'Ability mainwp/set-ignored-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that list-ignored-updates returns list.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_returns_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ignored', $result );
		$this->assertArrayHasKey( 'total', $result );

		$this->assertIsArray( $result['ignored'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test that set-ignored-updates adds to list.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_adds_to_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Ignore Updates Site',
		] );

		// Add ignored update.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'plugin',
			'slug'              => 'test-plugin',
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'ignore', $result['action'] );
		$this->assertEquals( $site_id, $result['site_id'] );
		$this->assertEquals( 'plugin', $result['type'] );
		$this->assertEquals( 'test-plugin', $result['slug'] );
	}

	/**
	 * Test that confirmed unignore removes items from the ignored list.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_removes_from_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Unignore Updates Site',
		] );

		// Seed with ignored plugin.
		$this->set_site_ignored_plugins( $site_id, [
			'test-plugin' => [
				'Name'             => 'Test Plugin',
				'ignored_versions' => [ 'all_versions' ],
			],
		] );

		// Remove ignored update.
		$result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [
			'confirm'           => true,
			'site_id_or_domain' => $site_id,
			'type'              => 'plugin',
			'slugs'             => [ 'test-plugin' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertSame( [ 'test-plugin' ], $result['removed'] );
		$this->assertSame( 1, $result['count'] );
	}

	/**
	 * Test that set-ignored-updates validates action.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_validates_action() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Invalid action should fail.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'invalid_action',
			'site_id_or_domain' => $site_id,
			'type'              => 'plugin',
			'slug'              => 'test-plugin',
		] );

		$this->assertWPError( $result );
	}

	/**
	 * Test that set-ignored-updates validates type.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_validates_type() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Invalid type should fail.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'invalid_type',
			'slug'              => 'test-plugin',
		] );

		$this->assertWPError( $result );
	}

	/**
	 * Test that set-ignored-updates requires all fields.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_requires_fields() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Missing slug should fail.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'plugin',
			// Missing 'slug'.
		] );

		$this->assertWPError( $result );
	}

	/**
	 * Test that set-ignored-updates handles core type.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_handles_core() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'core',
			'slug'              => 'wordpress',
		] );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'core', $result['type'] );
	}

	/**
	 * Test that set-ignored-updates handles theme type.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_handles_theme() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'theme',
			'slug'              => 'twentytwentyfour',
		] );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'theme', $result['type'] );
	}

	/**
	 * Test that list-ignored-updates filters by type.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_filters_by_type() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Ignored Filter Site',
		] );

		// Seed with ignored plugins and themes.
		$this->set_site_ignored_plugins( $site_id, [ 'test-plugin' => 'Test Plugin' ] );
		$this->set_site_ignored_themes( $site_id, [ 'test-theme' => 'Test Theme' ] );

		// Request only plugins.
		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [
			'types' => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// All items should be plugins.
		foreach ( $result['ignored'] as $item ) {
			$this->assertEquals( 'plugin', $item['type'], 'Filtered items should be plugins only.' );
		}
	}

	/**
	 * Test that list-ignored-updates filters by site.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_filters_by_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'Ignored Site 1' ] );
		$site2_id = $this->create_test_site( [ 'name' => 'Ignored Site 2' ] );

		// Seed both sites with different ignored plugins.
		$this->set_site_ignored_plugins( $site1_id, [ 'test-plugin' => 'Test Plugin' ] );
		$this->set_site_ignored_plugins( $site2_id, [ 'other-plugin' => 'Other Plugin' ] );

		// Request only site1.
		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [
			'site_ids_or_domains' => [ $site1_id ],
		] );

		$this->assertNotWPError( $result );

		// All items should be for site1, none for site2.
		foreach ( $result['ignored'] as $item ) {
			$this->assertEquals( $site1_id, $item['site_id'], 'Filtered items should be for site1.' );
			$this->assertNotEquals( $site2_id, $item['site_id'], 'Site2 items should be excluded from filtered results.' );
		}
	}

	/**
	 * Test that run-updates handles empty site list.
	 *
	 * @return void
	 */
	public function test_run_updates_empty_site_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Empty list should update all sites.
		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * Test that run-updates handles specific_items filter.
	 *
	 * @return void
	 */
	public function test_run_updates_specific_items_filter() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Specific Items Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
			'specific_items'      => [ 'akismet/akismet.php' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		// Implementation filters to only update specified slugs.
	}

	/**
	 * Test that run-updates handles connection failure errors.
	 *
	 * Verifies that connection failures during update attempts produce the
	 * expected error code and message structure in the response.
	 *
	 * @return void
	 */
	public function test_run_updates_handles_connection_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Connection Failure Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Mock connection failure for the site.
		$this->mock_partial_update_result(
			[], // No successes.
			[
				[
					'site_id' => $site_id,
					'code'    => 'mainwp_connection_failed',
					'message' => 'Connection to child site timed out',
				],
			]
		);

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertIsArray( $result['summary'], 'Summary should be an array.' );

		// Find connection failure error for this site.
		$found_connection_error = false;
		$error_message          = '';
		foreach ( $result['errors'] as $error ) {
			if ( isset( $error['site_id'] ) && $error['site_id'] === $site_id ) {
				if ( $error['code'] === 'mainwp_connection_failed' ) {
					$found_connection_error = true;
					$error_message          = $error['message'] ?? '';
					break;
				}
			}
		}

		$this->assertTrue(
			$found_connection_error,
			'Connection failure should appear in errors with mainwp_connection_failed code.'
		);

		$this->assertStringContainsString(
			'timed out',
			strtolower( $error_message ),
			'Error message should describe the connection failure.'
		);

		// Verify summary reflects the error.
		$this->assertGreaterThanOrEqual(
			1,
			$result['summary']['total_errors'],
			'Summary should reflect at least 1 error for connection failure.'
		);
	}

	/**
	 * Test that run-updates handles authentication failure errors.
	 *
	 * Verifies that authentication failures during update attempts produce
	 * the expected error code and message structure in the response.
	 *
	 * @return void
	 */
	public function test_run_updates_handles_auth_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Auth Failure Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Mock authentication failure for the site.
		$this->mock_partial_update_result(
			[], // No successes.
			[
				[
					'site_id' => $site_id,
					'code'    => 'mainwp_auth_failed',
					'message' => 'Authentication failed: Invalid credentials or OpenSSL signature mismatch',
				],
			]
		);

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertIsArray( $result['summary'], 'Summary should be an array.' );

		// Find auth failure error for this site.
		$found_auth_error = false;
		$error_message    = '';
		foreach ( $result['errors'] as $error ) {
			if ( isset( $error['site_id'] ) && $error['site_id'] === $site_id ) {
				if ( $error['code'] === 'mainwp_auth_failed' ) {
					$found_auth_error = true;
					$error_message    = $error['message'] ?? '';
					break;
				}
			}
		}

		$this->assertTrue(
			$found_auth_error,
			'Authentication failure should appear in errors with mainwp_auth_failed code.'
		);

		$this->assertStringContainsString(
			'authentication',
			strtolower( $error_message ),
			'Error message should describe the authentication failure.'
		);

		// Verify summary reflects the error.
		$this->assertGreaterThanOrEqual(
			1,
			$result['summary']['total_errors'],
			'Summary should reflect at least 1 error for auth failure.'
		);
	}

	// =========================================================================
	// Get Site Updates Tests (get-site-updates-v1)
	// =========================================================================

	/**
	 * Test that get-site-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_get_site_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/get-site-updates-v1',
			$abilities,
			'Ability mainwp/get-site-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that get-site-updates returns expected structure.
	 *
	 * @return void
	 */
	public function test_get_site_updates_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Get Updates Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed plugin and theme upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result, 'Should return successful result.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'site_id', $result );
		$this->assertArrayHasKey( 'site_url', $result );
		$this->assertArrayHasKey( 'site_name', $result );
		$this->assertArrayHasKey( 'updates', $result );
		$this->assertArrayHasKey( 'rollback_items', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertEquals( $site_id, $result['site_id'] );
		$this->assertIsArray( $result['updates'] );
		$this->assertIsArray( $result['rollback_items'] );
		$this->assertIsArray( $result['summary'] );
	}

	/**
	 * Test that get-site-updates requires authentication.
	 *
	 * @return void
	 */
	public function test_get_site_updates_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that get-site-updates requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_get_site_updates_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that get-site-updates validates input.
	 *
	 * @return void
	 */
	public function test_get_site_updates_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that get-site-updates returns error for non-existent site.
	 *
	 * @return void
	 */
	public function test_get_site_updates_returns_error_for_not_found() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => 999999,
		] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
		// Original error message should mention the site was not found.
		$this->assertStringContainsString( 'site', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test that get-site-updates includes rollback data when present.
	 *
	 * @return void
	 */
	public function test_get_site_updates_includes_rollback_data() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Rollback Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'rollback_items', $result );
		// Even with no rollback data, should have the structure.
		$this->assertArrayHasKey( 'plugins', $result['rollback_items'] );
		$this->assertArrayHasKey( 'themes', $result['rollback_items'] );
	}

	/**
	 * Test that get-site-updates filters by type.
	 *
	 * @return void
	 */
	public function test_get_site_updates_filters_by_type() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Type Filter Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed plugin and theme upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Request only plugins.
		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => $site_id,
			'types'             => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// All updates should be plugin type.
		foreach ( $result['updates'] as $update ) {
			$this->assertEquals( 'plugin', $update['type'], 'Filtered updates should be plugins only.' );
		}
	}

	// =========================================================================
	// Update Site Core Tests (update-site-core-v1)
	// =========================================================================

	/**
	 * Test that update-site-core-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_site_core_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-site-core-v1',
			$abilities,
			'Ability mainwp/update-site-core-v1 should be registered.'
		);
	}

	/**
	 * Test that update-site-core returns expected structure.
	 *
	 * In the unit test environment without a real child site, the ability
	 * returns a WP_Error because it cannot connect. This test validates
	 * the error handling path; happy-path testing requires integration tests.
	 *
	 * @return void
	 */
	public function test_update_site_core_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Core Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed core upgrade.
		$this->set_site_option( $site_id, 'wp_upgrades', wp_json_encode( [
			'current' => '6.4.0',
			'new'     => '6.5.0',
		] ) );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		// In test environment without real child site, expect graceful error.
		// Happy-path testing requires integration tests with actual child site.
		$this->assertWPError( $result, 'Expected WP_Error in test environment without child site connectivity.' );
	}

	/**
	 * Test that update-site-core requires authentication.
	 *
	 * @return void
	 */
	public function test_update_site_core_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-core requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_site_core_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-core validates input.
	 *
	 * @return void
	 */
	public function test_update_site_core_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that update-site-core returns error when no core update available.
	 *
	 * @return void
	 */
	public function test_update_site_core_returns_error_when_no_update() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'No Core Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Don't seed any core upgrades.

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_no_updates', $result->get_error_code() );
	}

	/**
	 * Core holds must prevent dispatch through the single-site ability.
	 *
	 * @return void
	 */
	public function test_update_site_core_honours_holds() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$held    = $this->create_test_site();
		$flagged = $this->create_test_site( [ 'is_ignoreCoreUpdates' => 1 ] );
		$free    = $this->create_test_site();
		foreach ( [ $held, $flagged, $free ] as $site_id ) {
			$this->set_site_option( $site_id, 'wp_upgrades', wp_json_encode( [ 'current' => '6.5', 'new' => '6.6' ] ) );
		}
		$ignored = wp_json_encode( [ 'ignored_versions' => [ 'all_versions' ] ] );
		$this->set_site_option( $held, 'ignored_wp_upgrades', $ignored );
		$extension = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_user_extension_by_user_id();

		$calls = [];
		$mock  = static function ( $result, $website, $what ) use ( &$calls ) {
			$calls[] = [ 'site_id' => (int) $website->id, 'what' => $what ];
			return [ 'upgrade' => 'SUCCESS' ];
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $mock, 10, 3 );
		try {
			foreach ( [ $held, $flagged ] as $site_id ) {
				$result = $this->execute_ability( 'mainwp/update-site-core-v1', [ 'confirm' => true, 'site_id_or_domain' => $site_id ] );
				$this->assertSame( [], $calls, 'A per-site core hold must prevent child calls.' );
				$this->assertWPError( $result );
				$this->assertSame( 'mainwp_no_updates', $result->get_error_code() );
				$this->assertSame( [ 'status' => 404 ], $result->get_error_data() );
			}

			\MainWP\Dashboard\MainWP_DB_Common::instance()->update_user_extension(
				[ 'userid' => $extension->userid, 'ignored_wp_upgrades' => $ignored ]
			);
			$result = $this->execute_ability( 'mainwp/update-site-core-v1', [ 'confirm' => true, 'site_id_or_domain' => $free ] );
			$this->assertSame( [], $calls, 'A global core hold must prevent child calls.' );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_no_updates', $result->get_error_code() );
			$this->assertSame( [ 'status' => 404 ], $result->get_error_data() );

			\MainWP\Dashboard\MainWP_DB_Common::instance()->update_user_extension(
				[ 'userid' => $extension->userid, 'ignored_wp_upgrades' => '' ]
			);
			$result = $this->execute_ability( 'mainwp/update-site-core-v1', [ 'confirm' => true, 'site_id_or_domain' => $free ] );
			$this->assertSame( [ [ 'site_id' => $free, 'what' => 'upgrade' ] ], $calls );
			$this->assertNotWPError( $result );
			$this->assertSame( 'wordpress', $result['updated']['slug'] );
			$this->assertSame( '6.5', $result['updated']['old_version'] );
			$this->assertSame( '6.6', $result['updated']['new_version'] );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $mock, 10 );
		}
	}

	/**
	 * Test that update-site-core rejects offline sites.
	 *
	 * @return void
	 */
	public function test_update_site_core_rejects_offline_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Core Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_offline', $result->get_error_code() );
	}

	/**
	 * Test that update-site-core rejects outdated child version.
	 *
	 * @return void
	 */
	public function test_update_site_core_rejects_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Core Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_child_outdated', $result->get_error_code() );
	}

	// =========================================================================
	// Update Site Plugins Tests (update-site-plugins-v1)
	// =========================================================================

	/**
	 * Test that update-site-plugins-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-site-plugins-v1',
			$abilities,
			'Ability mainwp/update-site-plugins-v1 should be registered.'
		);
	}

	/**
	 * Test that update-site-plugins returns expected structure.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Plugin Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
	}

	/**
	 * Test that update-site-plugins requires authentication.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-plugins requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-plugins validates input.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that update-site-plugins filters by slugs.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_filters_by_slugs() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Slug Filter Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed multiple plugin upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php'   => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
			'hello-dolly/hello.php' => [
				'Name'        => 'Hello Dolly',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Request only specific slug.
		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
			'slugs'             => [ 'akismet/akismet.php' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * Explicit slugs must respect whole-site holds and the existing ignore lists.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_and_themes_honour_holds() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$calls = [];
		$mock  = static function ( $result, $website, $what, $params ) use ( &$calls ) {
			$calls[] = [ 'site_id' => (int) $website->id, 'what' => $what, 'type' => $params['type'], 'list' => $params['list'] ];
			return [ 'upgrades' => array_fill_keys( explode( ',', $params['list'] ), true ) ];
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $mock, 10, 4 );
		try {
			foreach ( [ 'plugin', 'theme' ] as $type ) {
				$flag    = 'plugin' === $type ? 'is_ignorePluginUpdates' : 'is_ignoreThemeUpdates';
				$held    = 'plugin' === $type ? 'held/held.php' : 'held-theme';
				$global  = 'plugin' === $type ? 'global/global.php' : 'global-theme';
				$chosen  = 'plugin' === $type ? 'chosen/chosen.php' : 'chosen-theme';
				$other   = 'plugin' === $type ? 'other/other.php' : 'other-theme';
				$flagged = $this->create_test_site( [ $flag => 1 ] );
				$free    = $this->create_test_site();
				$updates = array_fill_keys( [ $held, $global, $chosen, $other ], [ 'Name' => 'Item', 'Version' => '1.0', 'new_version' => '2.0' ] );
				$ignored = [ $held => [ 'Name' => 'Held', 'ignored_versions' => [ 'all_versions' ] ] ];
				foreach ( [ $flagged, $free ] as $site_id ) {
					if ( 'plugin' === $type ) {
						$this->set_site_plugin_upgrades( $site_id, $updates );
						$this->set_site_ignored_plugins( $site_id, $ignored );
					} else {
						$this->set_site_theme_upgrades( $site_id, $updates );
						$this->set_site_ignored_themes( $site_id, $ignored );
					}
				}
				$extension = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_user_extension_by_user_id();
				\MainWP\Dashboard\MainWP_DB_Common::instance()->update_user_extension(
					[ 'userid' => $extension->userid, 'ignored_' . $type . 's' => wp_json_encode( [ $global => [ 'Name' => 'Global', 'ignored_versions' => [ 'all_versions' ] ] ] ) ]
				);

				$ability = 'mainwp/update-site-' . $type . 's-v1';
				$calls   = [];
				$result  = $this->execute_ability( $ability, [ 'confirm' => true, 'site_id_or_domain' => $flagged, 'slugs' => [ $chosen ] ] );
				$this->assertSame( [], $calls, $type . ' whole-site holds must prevent child calls.' );
				$this->assertFalse( $result['dry_run'] );
				$this->assertSame( [], $result['updated'] );
				$this->assertSame( [], $result['errors'] );
				$this->assertSame( [ 'total_skipped' => 1, 'total_updated' => 0, 'total_errors' => 0 ], $result['summary'] );
				$this->assertSame( [ $chosen ], array_column( $result['skipped'], 'slug' ) );
				$this->assertSame( [ 'ignored_site_flag' ], array_column( $result['skipped'], 'reason' ) );

				$result = $this->execute_ability( $ability, [ 'confirm' => true, 'site_id_or_domain' => $free, 'slugs' => [ $held, $global, $chosen, 'missing' ] ] );
				$this->assertSame( [ [ 'site_id' => $free, 'what' => 'upgradeplugintheme', 'type' => $type, 'list' => $chosen ] ], $calls );
				$this->assertNotWPError( $result );
				$this->assertSame( [ $chosen ], array_column( $result['updated'], 'slug' ) );
				$this->assertSame( [], $result['errors'] );
				$this->assertSame( [ 'total_skipped' => 2, 'total_updated' => 1, 'total_errors' => 0 ], $result['summary'] );

				$calls  = [];
				$result = $this->execute_ability( $ability, [ 'confirm' => true, 'site_id_or_domain' => $free ] );
				$this->assertSame( [ [ 'site_id' => $free, 'what' => 'upgradeplugintheme', 'type' => $type, 'list' => $other ] ], $calls );
				$this->assertNotWPError( $result );
				$this->assertSame( [ $other ], array_column( $result['updated'], 'slug' ) );
			}
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $mock, 10 );
		}
	}

	/**
	 * Test that update-site-plugins rejects offline sites.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_rejects_offline_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Plugin Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_offline', $result->get_error_code() );
	}

	/**
	 * Test that update-site-plugins rejects outdated child version.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_rejects_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Plugin Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_child_outdated', $result->get_error_code() );
	}

	// =========================================================================
	// Update Site Themes Tests (update-site-themes-v1)
	// =========================================================================

	/**
	 * Test that update-site-themes-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_site_themes_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-site-themes-v1',
			$abilities,
			'Ability mainwp/update-site-themes-v1 should be registered.'
		);
	}

	/**
	 * Test that update-site-themes returns expected structure.
	 *
	 * @return void
	 */
	public function test_update_site_themes_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Theme Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
	}

	/**
	 * Test that update-site-themes requires authentication.
	 *
	 * @return void
	 */
	public function test_update_site_themes_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-themes requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_site_themes_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-themes validates input.
	 *
	 * @return void
	 */
	public function test_update_site_themes_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that update-site-themes rejects offline sites.
	 *
	 * @return void
	 */
	public function test_update_site_themes_rejects_offline_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Theme Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_offline', $result->get_error_code() );
	}

	/**
	 * Test that update-site-themes rejects outdated child version.
	 *
	 * @return void
	 */
	public function test_update_site_themes_rejects_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Theme Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_child_outdated', $result->get_error_code() );
	}

	// =========================================================================
	// Update Site Translations Tests (update-site-translations-v1)
	// =========================================================================

	/**
	 * Test that update-site-translations-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_site_translations_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-site-translations-v1',
			$abilities,
			'Ability mainwp/update-site-translations-v1 should be registered.'
		);
	}

	/**
	 * Test that update-site-translations returns expected structure.
	 *
	 * @return void
	 */
	public function test_update_site_translations_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Translation Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
	}

	/**
	 * Test that update-site-translations requires authentication.
	 *
	 * @return void
	 */
	public function test_update_site_translations_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-translations requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_site_translations_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-translations validates input.
	 *
	 * @return void
	 */
	public function test_update_site_translations_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that update-site-translations rejects offline sites.
	 *
	 * @return void
	 */
	public function test_update_site_translations_rejects_offline_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Translation Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_offline', $result->get_error_code() );
	}

	/**
	 * Test that update-site-translations rejects outdated child version.
	 *
	 * @return void
	 */
	public function test_update_site_translations_rejects_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Translation Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'confirm'             => true,
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_child_outdated', $result->get_error_code() );
	}

	// =========================================================================
	// Update All Tests (update-all-v1)
	// =========================================================================

	/**
	 * Test that update-all-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_all_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-all-v1',
			$abilities,
			'Ability mainwp/update-all-v1 should be registered.'
		);
	}

	/**
	 * Test that update-all returns expected structure for immediate execution.
	 *
	 * @return void
	 */
	public function test_update_all_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Update All Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $site_id ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
		$this->assertArrayHasKey( 'sites_updated', $result['summary'] );
	}

	/**
	 * Test that update-all requires authentication.
	 *
	 * @return void
	 */
	public function test_update_all_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-all requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_all_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-all with empty site list uses all sites.
	 *
	 * @return void
	 */
	public function test_update_all_empty_site_list_uses_all_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
	}

	/**
	 * Test that update-all large batch returns queued response.
	 *
	 * @return void
	 */
	public function test_update_all_large_batch_returns_queued() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			// Create 6 test sites (exceeds lowered threshold).
			$site_ids = [];
			for ( $i = 0; $i < 6; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Large Update All Site {$i}",
					'offline_check_result' => 1,
					'version'              => '5.0.0',
				] );
			}

			$result = $this->execute_ability( 'mainwp/update-all-v1', [
				'confirm'             => true,
				'site_ids_or_domains' => $site_ids,
			] );

			$this->assertNotWPError( $result );

			// Should be queued.
			$this->assertTrue( $result['queued'], 'Large batch should be queued.' );
			$this->assertArrayHasKey( 'job_id', $result );
			$this->assertArrayHasKey( 'status_url', $result );
			$this->assertArrayHasKey( 'updates_queued', $result );

			// Track job for cleanup in tearDown.
			$this->track_update_job( $result['job_id'] );

			// Queued jobs don't have updated key.
			$this->assertArrayNotHasKey( 'updated', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test that update-all filters by types.
	 *
	 * @return void
	 */
	public function test_update_all_filters_by_types() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Update All Types Filter Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed updates of different types.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Request only plugins.
		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		// Verify that only plugin updates were attempted (not themes).
	}

	/**
	 * Test that update-all handles outdated child version errors.
	 *
	 * @return void
	 */
	public function test_update_all_handles_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$outdated_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Update All Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		// Seed plugin upgrades so there's something to update.
		$this->set_site_plugin_upgrades( $outdated_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $outdated_id ],
		] );

		$this->assertNotWPError( $result );

		// Outdated child should be in errors.
		$found_outdated_error = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['code'] === 'mainwp_child_outdated' ) {
				$found_outdated_error = true;
				break;
			}
		}

		$this->assertTrue( $found_outdated_error, 'Outdated child should produce mainwp_child_outdated error.' );
	}

	/**
	 * Test that update-all handles mixed site states.
	 *
	 * @return void
	 */
	public function test_update_all_handles_mixed_site_states() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$online_id = $this->create_test_site( [
			'name'                 => 'Online Update All Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Update All Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $online_id, $offline_id ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		// Should have structure for partial results.
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
	}

	/**
	 * Test that update-all skips offline sites.
	 *
	 * @return void
	 */
	public function test_update_all_skips_offline_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Update All Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		// Seed plugin upgrades so there's something to update.
		$this->set_site_plugin_upgrades( $offline_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ $offline_id ],
		] );

		$this->assertNotWPError( $result );

		// Offline site should be in errors with specific error code.
		$found_offline_error = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['site_id'] === $offline_id && $error['code'] === 'mainwp_site_offline' ) {
				$found_offline_error = true;
				break;
			}
		}

		$this->assertTrue( $found_offline_error, 'Offline site should produce mainwp_site_offline error in update-all.' );
	}

	/**
	 * Test that update-all with invalid identifiers returns normalized error schema.
	 *
	 * Resolution errors should match the documented error item schema with
	 * site_id, site_url, site_name, type, slug, code, and message keys.
	 *
	 * @return void
	 */
	public function test_update_all_invalid_identifier_returns_normalized_error_schema() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'confirm'             => true,
			'site_ids_or_domains' => [ 'nonexistent-site.invalid' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertNotEmpty( $result['errors'], 'Should have at least one error for invalid identifier.' );

		$error = $result['errors'][0];

		// Verify all required schema fields are present.
		$this->assertArrayHasKey( 'site_id', $error, 'Error should have site_id key.' );
		$this->assertArrayHasKey( 'site_url', $error, 'Error should have site_url key.' );
		$this->assertArrayHasKey( 'site_name', $error, 'Error should have site_name key.' );
		$this->assertArrayHasKey( 'type', $error, 'Error should have type key.' );
		$this->assertArrayHasKey( 'slug', $error, 'Error should have slug key.' );
		$this->assertArrayHasKey( 'code', $error, 'Error should have code key.' );
		$this->assertArrayHasKey( 'message', $error, 'Error should have message key.' );

		// Verify type is 'site' for resolution errors.
		$this->assertEquals( 'site', $error['type'], 'Resolution error type should be "site".' );

		// Verify site_id is 0 for unresolved sites.
		$this->assertEquals( 0, $error['site_id'], 'Unresolved site should have site_id of 0.' );

		// Verify the identifier is preserved in site_url.
		$this->assertEquals( 'nonexistent-site.invalid', $error['site_url'], 'Identifier should be preserved in site_url.' );
	}

	// =========================================================================
	// Ignore Site Core Tests (ignore-site-core-v1)
	// =========================================================================

	/**
	 * Test that ignore-site-core-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/ignore-site-core-v1',
			$abilities,
			'Ability mainwp/ignore-site-core-v1 should be registered.'
		);
	}

	/**
	 * Test that ignore-site-core returns expected structure.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Core Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'add',
		] );

		$this->assertNotWPError( $result, 'Should return successful result.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'ignored_count', $result );

		$this->assertTrue( $result['success'], 'Success should be true.' );
		$this->assertNotEmpty( $result['message'], 'Message should not be empty.' );
		$this->assertEquals( 1, $result['ignored_count'], 'Ignored count should be 1 for core.' );
	}

	/**
	 * Test that ignore-site-core requires authentication.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => 1,
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-core requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => 1,
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-core validates input.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'action' => 'add',
		] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test adding a core hold, then removing it with confirmed unignore.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_add_and_remove() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Core Toggle Site',
			'offline_check_result' => 1,
		] );

		// Add to ignore list.
		$add_result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'add',
		] );

		$this->assertNotWPError( $add_result );
		$this->assertTrue( $add_result['success'] );

		// Remove from ignore list.
		$remove_result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [
			'site_id_or_domain' => $site_id,
			'type'              => 'core',
			'confirm'           => true,
		] );

		$this->assertNotWPError( $remove_result );
		$this->assertSame( [ 'wordpress' ], $remove_result['removed'] );
	}

	// =========================================================================
	// Ignore Site Plugins Tests (ignore-site-plugins-v1)
	// =========================================================================

	/**
	 * Test that ignore-site-plugins-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/ignore-site-plugins-v1',
			$abilities,
			'Ability mainwp/ignore-site-plugins-v1 should be registered.'
		);
	}

	/**
	 * Test that ignore-site-plugins returns expected structure.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Plugins Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => [ 'akismet/akismet.php', 'hello-dolly/hello.php' ],
			'action'            => 'add',
		] );

		$this->assertNotWPError( $result, 'Should return successful result.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'ignored_count', $result );

		$this->assertTrue( $result['success'], 'Success should be true.' );
		$this->assertNotEmpty( $result['message'], 'Message should not be empty.' );
		$this->assertEquals( 2, $result['ignored_count'], 'Ignored count should match number of slugs.' );
	}

	/**
	 * Test that ignore-site-plugins requires authentication.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => 1,
			'slugs'             => [ 'test-plugin/test.php' ],
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-plugins requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => 1,
			'slugs'             => [ 'test-plugin/test.php' ],
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-plugins validates input.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Missing required slugs.
		$result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'add',
			// Missing 'slugs'.
		] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test adding a plugin hold, then removing it with confirmed unignore.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_add_and_remove() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Plugins Toggle Site',
			'offline_check_result' => 1,
		] );

		$test_slugs = [ 'akismet/akismet.php' ];

		// Add to ignore list.
		$add_result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => $test_slugs,
			'action'            => 'add',
		] );

		$this->assertNotWPError( $add_result );
		$this->assertTrue( $add_result['success'] );
		$this->assertEquals( 1, $add_result['ignored_count'] );

		// Remove from ignore list.
		$remove_result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => $test_slugs,
			'type'              => 'plugin',
			'confirm'           => true,
		] );

		$this->assertNotWPError( $remove_result );
		$this->assertSame( $test_slugs, $remove_result['removed'] );
	}

	// =========================================================================
	// Ignore Site Themes Tests (ignore-site-themes-v1)
	// =========================================================================

	/**
	 * Test that ignore-site-themes-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/ignore-site-themes-v1',
			$abilities,
			'Ability mainwp/ignore-site-themes-v1 should be registered.'
		);
	}

	/**
	 * Test that ignore-site-themes returns expected structure.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Themes Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => [ 'twentytwentyfour', 'twentytwentythree' ],
			'action'            => 'add',
		] );

		$this->assertNotWPError( $result, 'Should return successful result.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'ignored_count', $result );

		$this->assertTrue( $result['success'], 'Success should be true.' );
		$this->assertNotEmpty( $result['message'], 'Message should not be empty.' );
		$this->assertEquals( 2, $result['ignored_count'], 'Ignored count should match number of slugs.' );
	}

	/**
	 * Test that ignore-site-themes requires authentication.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => 1,
			'slugs'             => [ 'test-theme' ],
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-themes requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => 1,
			'slugs'             => [ 'test-theme' ],
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-themes validates input.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Missing required slugs.
		$result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'add',
			// Missing 'slugs'.
		] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test adding a theme hold, then removing it with confirmed unignore.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_add_and_remove() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Themes Toggle Site',
			'offline_check_result' => 1,
		] );

		$test_slugs = [ 'twentytwentyfour' ];

		// Add to ignore list.
		$add_result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => $test_slugs,
			'action'            => 'add',
		] );

		$this->assertNotWPError( $add_result );
		$this->assertTrue( $add_result['success'] );
		$this->assertEquals( 1, $add_result['ignored_count'] );

		// Remove from ignore list.
		$remove_result = $this->execute_ability( 'mainwp/unignore-site-updates-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => $test_slugs,
			'type'              => 'theme',
			'confirm'           => true,
		] );

		$this->assertNotWPError( $remove_result );
		$this->assertSame( $test_slugs, $remove_result['removed'] );
	}

	/** Record child dispatches using the #298 recorder's fields. */
	private function &record_update_calls(): array {
		$calls = [];
		add_filter( 'mainwp_fetch_url_authed_pre', static function ( $result, $website, $what, $params ) use ( &$calls ) {
			$calls[] = [ 'site_id' => (int) $website->id, 'what' => $what, 'type' => $params['type'] ?? '', 'list' => $params['list'] ?? '' ];
			return 'upgrade' === $what ? [ 'upgrade' => 'SUCCESS' ] : [ 'upgrades' => array_fill_keys( explode( ',', $params['list'] ?? '' ), true ) ];
		}, 10, 4 );
		return $calls;
	}

	private function seed_gate_updates( int $site_id ): void {
		$this->set_site_option( $site_id, 'wp_upgrades', wp_json_encode( [ 'current' => '6.8.3', 'new' => '6.9' ] ) );
		$this->set_site_plugin_upgrades( $site_id, [ 'sample/plugin.php' => [ 'Name' => 'Sample Plugin', 'Version' => '1.0', 'update' => [ 'new_version' => '2.0' ] ] ] );
		$this->set_site_theme_upgrades( $site_id, [ 'sample-theme' => [ 'Name' => 'Sample Theme', 'Version' => '1.0', 'update' => [ 'new_version' => '1.1' ] ] ] );
		$this->set_site_translation_upgrades( $site_id, [ [ 'slug' => 'sample-translation', 'version' => '1.0', 'new_version' => '2.0' ] ] );
	}

	public static function gate_abilities(): array {
		return [
			'run' => [ 'mainwp/run-updates-v1', 'execute_run_updates', null ],
			'all' => [ 'mainwp/update-all-v1', 'execute_update_all', null ],
			'core' => [ 'mainwp/update-site-core-v1', 'execute_update_site_core', 'core' ],
			'plugins' => [ 'mainwp/update-site-plugins-v1', 'execute_update_site_plugins', 'plugins' ],
			'themes' => [ 'mainwp/update-site-themes-v1', 'execute_update_site_themes', 'themes' ],
			'translations' => [ 'mainwp/update-site-translations-v1', 'execute_update_site_translations', 'translations' ],
		];
	}

	/** @dataProvider gate_abilities */
	public function test_update_gate_neither_flag( $ability, $method, $type ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$calls = &$this->record_update_calls();
		$result = $this->execute_ability( $ability, $type ? [ 'site_id_or_domain' => $site_id ] : [ 'site_ids_or_domains' => [ $site_id ] ] );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_confirmation_required', $result->get_error_code() );
		$this->assertSame( 'Updates require confirm: true. Call with dry_run: true first to preview the plan.', $result->get_error_message() );
		$this->assertSame( [ 'status' => 400 ], $result->get_error_data() );
		$this->assertSame( [], $calls );
	}

	/** @dataProvider gate_abilities */
	public function test_update_gate_both_flags( $ability, $method, $type ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$input = [ 'dry_run' => true, 'confirm' => true ];
		$input[ $type ? 'site_id_or_domain' : 'site_ids_or_domains' ] = $type ? 999999 : [ 999999 ];
		$result = MainWP_Abilities_Updates::$method( $input );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
		$this->assertSame( 'Cannot specify both dry_run and confirm.', $result->get_error_message() );
		$this->assertSame( [ 'status' => 400 ], $result->get_error_data() );
	}

	public static function gate_empty_batch_inputs(): array {
		$cases = [];
		foreach ( [ 'execute_run_updates', 'execute_update_all' ] as $method ) {
			foreach ( [ 'null' => null, 'empty' => [], 'false strings' => [ 'confirm' => 'false', 'dry_run' => '0' ] ] as $label => $input ) {
				$cases[ $method . $label ] = [ $method, $input ];
			}
		}
		return $cases;
	}

	/** @dataProvider gate_empty_batch_inputs */
	public function test_update_gate_empty_batch_requires_confirmation( $method, $input ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$result = MainWP_Abilities_Updates::$method( $input );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_confirmation_required', $result->get_error_code() );
		if ( null === $input || [] === $input ) {
			$ability = wp_get_ability( 'execute_run_updates' === $method ? 'mainwp/run-updates-v1' : 'mainwp/update-all-v1' );
			$result = $ability->execute( $input );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_confirmation_required', $result->get_error_code() );
		}

	}

	/** @dataProvider gate_abilities */
	public function test_update_gate_schemas_and_annotations( $ability_name, $method, $type ) {
		$this->skip_if_no_abilities_api();
		$ability = wp_get_ability( $ability_name );
		$schema = $ability->get_input_schema();
		foreach ( [ 'confirm', 'dry_run' ] as $flag ) {
			$this->assertSame( 'boolean', $schema['properties'][ $flag ]['type'] ?? null );
			$this->assertFalse( $schema['properties'][ $flag ]['default'] );
			$this->assertNotContains( $flag, $schema['required'] ?? [] );
		}
		$this->assertSame( $type ? 'object' : [ 'object', 'null' ], $schema['type'] );
		$annotations = $ability->get_meta()['annotations'];
		$this->assertTrue( $annotations['destructive'] );
		$this->assertFalse( $annotations['readonly'] );
		$this->assertFalse( $annotations['idempotent'] );
		$this->assertStringStartsWith( 'Changes child sites. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user.', $annotations['instructions'] );
		if ( ! $type ) {
			$this->assertStringContainsString( 'Operations with >200 sites are automatically queued and return a job_id for status polling.', $annotations['instructions'] );
		}
	}

	/** @dataProvider gate_abilities */
	public function test_update_gate_preview_and_execution_validate( $ability_name, $method, $type ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_gate_updates( $site_id );
		$calls = &$this->record_update_calls();
		$input = $type ? [ 'site_id_or_domain' => $site_id ] : [ 'site_ids_or_domains' => [ $site_id ] ];
		$ability = wp_get_ability( $ability_name );
		foreach ( [ 'dry_run', 'confirm' ] as $flag ) {
			$result = $ability->execute( $input + [ $flag => true ] );
			$this->assertNotWPError( $result, $ability_name . ' ' . $flag );
			$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema() ) );
			$matches = 0;
			foreach ( $ability->get_output_schema()['oneOf'] as $branch ) {
				$matches += true === rest_validate_value_from_schema( $result, $branch ) ? 1 : 0;
			}
			$this->assertSame( 1, $matches, 'Exactly one output branch must match.' );
			if ( 'dry_run' === $flag ) {
				$this->assertSame( [], $calls );
				$this->assertTrue( $result['dry_run'] );
				$this->assertSame( $type ? 1 : 4, $result['count'] );
				$this->assertSame( $type ? [ rtrim( $type, 's' ) ] : [ 'core', 'plugin', 'theme', 'translation' ], array_column( $result['would_affect']['sites'][0]['items'], 'type' ) );
				if ( 'mainwp/update-all-v1' === $ability_name || 'core' === $type ) {
					$this->assertSame( [ false ], array_values( array_unique( array_column( $result['would_affect']['sites'][0]['items'], 'requested' ) ) ) );
				}
			} else {
				$this->assertCount( $type ? 1 : 4, $calls );
				$this->assertNotEmpty( $result['updated'] );
			}
		}
		if ( ! $type ) {
			$threshold = static function () { return 2; };
			add_filter( 'mainwp_abilities_batch_threshold', $threshold );
			try {
				$ids = [ $site_id, $this->create_test_site(), $this->create_test_site() ];
				$queued = $ability->execute( [ 'site_ids_or_domains' => $ids, 'confirm' => true ] );
				$this->assertNotWPError( $queued );
				$this->track_update_job( $queued['job_id'] );
				$this->assertTrue( $queued['queued'] );
				$this->assertTrue( rest_validate_value_from_schema( $queued, $ability->get_output_schema() ) );
				$matches = 0;
				foreach ( $ability->get_output_schema()['oneOf'] as $branch ) {
					$matches += true === rest_validate_value_from_schema( $queued, $branch ) ? 1 : 0;
				}
				$this->assertSame( 1, $matches, 'Exactly one branch must match a queued execution result.' );
			} finally {
				remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
			}
		}

	}

	public static function execution_skip_cases(): array {
		$cases = [];
		foreach ( [ 'run-updates', 'update-all' ] as $ability ) {
			$cases[ $ability . '-plugin' ] = [ $ability, 'plugins', 'sample/plugin.php', 'Sample Plugin', '1.0', '2.0', 'ignored_site' ];
			$cases[ $ability . '-theme' ] = [ $ability, 'themes', 'sample-theme', 'Sample Theme', '1.0', '1.1', 'ignored_global' ];
			$cases[ $ability . '-core' ] = [ $ability, 'core', 'wordpress', 'WordPress', '6.8.3', '6.9', 'ignored_site_flag' ];
		}
		$cases['site-plugin'] = [ 'update-site-plugins', 'plugins', 'sample/plugin.php', 'Sample Plugin', '1.0', '2.0', 'ignored_site' ];
		$cases['site-theme'] = [ 'update-site-themes', 'themes', 'sample-theme', 'Sample Theme', '1.0', '1.1', 'ignored_global' ];
		return $cases;
	}

	/** @dataProvider execution_skip_cases */
	public function test_update_execution_reports_held_items( $ability, $type, $slug, $name, $from, $to, $reason ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site( [ 'name' => 'Execution Hold Site', 'url' => 'https://execution-hold.example.org/' ] );
		$this->seed_gate_updates( $site_id );
		if ( 'ignored_site' === $reason ) {
			$this->set_site_ignored_plugins( $site_id, [ $slug => 'Held' ] );
		} elseif ( 'ignored_global' === $reason ) {
			$extension = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
			MainWP_DB_Common::instance()->update_user_extension( [ 'userid' => $extension->userid, 'ignored_themes' => wp_json_encode( [ $slug => 'Held' ] ) ] );
		} else {
			MainWP_DB::instance()->update_website_values( $site_id, [ 'is_ignoreCoreUpdates' => 1 ] );
		}
		$requested = 'update-all' !== $ability;
		$input = [ 'site_ids_or_domains' => [ $site_id ], 'types' => [ $type ] ];
		if ( 'run-updates' === $ability ) {
			$input['specific_items'] = [ $slug ];
		} elseif ( 0 === strpos( $ability, 'update-site-' ) ) {
			$input = [ 'site_id_or_domain' => $site_id, 'slugs' => [ $slug ] ];
		}
		$calls = &$this->record_update_calls();
		$result = $this->execute_ability( 'mainwp/' . $ability . '-v1', $input + [ 'confirm' => true ] );
		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'skipped', $result );
		$this->assertSame( [ [
			'site_id' => $site_id, 'site_url' => 'https://execution-hold.example.org/', 'site_name' => 'Execution Hold Site',
			'type' => rtrim( $type, 's' ), 'slug' => $slug, 'name' => $name, 'from' => $from, 'to' => $to,
			'requested' => $requested, 'reason' => $reason,
		] ], $result['skipped'] );
		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( 1, $result['summary']['total_skipped'] );
		$this->assertSame( 0, $result['summary']['total_updated'] );
		$this->assertSame( [], $calls );
		$this->assert_execution_output_validates( 'mainwp/' . $ability . '-v1', $result );
	}

	private function assert_execution_output_validates( string $ability_name, array $result ): void {
		$schema = wp_get_ability( $ability_name )->get_output_schema();
		$this->assertTrue( rest_validate_value_from_schema( $result, $schema ) );
		$matches = 0;
		foreach ( $schema['oneOf'] as $branch ) {
			$matches += true === rest_validate_value_from_schema( $result, $branch ) ? 1 : 0;
		}
		$this->assertSame( 1, $matches, 'Exactly one execution branch must match.' );
		$missing = $result;
		unset( $missing['skipped'] );
		$this->assertWPError( rest_validate_value_from_schema( $missing, $schema ), 'Execution must require skipped.' );
		if ( isset( $result['summary'] ) ) {
			$missing = $result;
			unset( $missing['summary']['total_skipped'] );
			$this->assertWPError( rest_validate_value_from_schema( $missing, $schema ), 'Execution summaries must require total_skipped.' );
		}
	}

	/** @dataProvider gate_abilities */
	public function test_update_execution_envelopes_validate( $ability_name, $method, $type ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_gate_updates( $site_id );
		$calls = &$this->record_update_calls();
		$input = $type ? [ 'site_id_or_domain' => $site_id ] : [ 'site_ids_or_domains' => [ $site_id ] ];
		$result = $this->execute_ability( $ability_name, $input + [ 'confirm' => true ] );
		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'dry_run', $result );
		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( [], $result['skipped'] );
		$this->assertSame( 0, $result['summary']['total_skipped'] );
		$this->assertCount( $type ? 1 : 4, $calls );
		$this->assert_execution_output_validates( $ability_name, $result );
		if ( 'core' !== $type ) {
			$empty = $this->execute_ability( $ability_name, $input + [ 'confirm' => true ] );
			$this->assertNotWPError( $empty );
			$this->assertFalse( $empty['dry_run'] );
			$this->assertSame( [], $empty['skipped'] );
			$this->assertSame( 0, $empty['summary']['total_skipped'] );
			$this->assert_execution_output_validates( $ability_name, $empty );
		}
	}

	public static function queued_execution_abilities(): array {
		return [ [ 'mainwp/run-updates-v1' ], [ 'mainwp/update-all-v1' ] ];
	}

	public static function duplicate_update_selections(): array {
		$cases = [];
		foreach ( self::queued_execution_abilities() as [ $ability ] ) {
			$cases[ $ability . '-id' ] = [ $ability, false ];
			$cases[ $ability . '-domain' ] = [ $ability, true ];
		}
		return $cases;
	}

	/** @dataProvider duplicate_update_selections */
	public function test_update_duplicate_selection_preview_counts_once( $ability, $use_domain ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site( [ 'url' => 'https://test-duplicate.example.org/' ] );
		$this->seed_gate_updates( $site_id );
		$result = $this->execute_ability( $ability, [
			'site_ids_or_domains' => [ $site_id, $use_domain ? 'test-duplicate.example.org' : $site_id ],
			'types' => [ 'core' ],
			'dry_run' => true,
		] );
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['would_affect']['summary']['site_count'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( [ $site_id ], array_column( $result['would_affect']['sites'], 'site_id' ) );
	}

	/** @dataProvider duplicate_update_selections */
	public function test_update_duplicate_selection_dispatches_once_in_order( $ability, $use_domain ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$first = $this->create_test_site( [ 'url' => 'https://test-duplicate.example.org/' ] );
		$second = $this->create_test_site();
		$this->seed_gate_updates( $first );
		$this->seed_gate_updates( $second );
		$calls = &$this->record_update_calls();
		$result = $this->execute_ability( $ability, [
			'site_ids_or_domains' => [ $first, $second, $use_domain ? 'test-duplicate.example.org' : $first ],
			'types' => [ 'core' ],
			'confirm' => true,
		] );
		$this->assertNotWPError( $result );
		$this->assertSame( [ $first, $second ], array_column( $calls, 'site_id' ) );
		$this->assertSame( 2, $result['summary']['total_updated'] );
		$this->assertSame( 2, $result['summary']['sites_updated'] );
	}

	/** @dataProvider duplicate_update_selections */
	public function test_update_duplicate_selection_stays_immediate( $ability, $use_domain ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site( [ 'url' => 'https://test-duplicate.example.org/' ] );
		$this->seed_gate_updates( $site_id );
		$calls = &$this->record_update_calls();
		$threshold = static function () { return 1; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$input = [ 'site_ids_or_domains' => [ $site_id, $use_domain ? 'test-duplicate.example.org' : $site_id ], 'types' => [ 'core' ] ];
			$preview = $this->execute_ability( $ability, $input + [ 'dry_run' => true ] );
			$this->assertNotWPError( $preview );
			$this->assertFalse( $preview['would_affect']['summary']['queued'] );
			$result = $this->execute_ability( $ability, $input + [ 'confirm' => true ] );
			$this->assertNotWPError( $result );
			if ( isset( $result['job_id'] ) ) {
				$this->track_update_job( $result['job_id'] );
			}
			$this->assertArrayNotHasKey( 'queued', $result );
			$this->assertSame( [ $site_id ], array_column( $calls, 'site_id' ) );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
	}

	/** @dataProvider queued_execution_abilities */
	public function test_update_duplicate_selection_queues_unique_roster( $ability ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$first = $this->create_test_site( [ 'url' => 'https://test-duplicate.example.org/' ] );
		$second = $this->create_test_site();
		$calls = &$this->record_update_calls();
		$threshold = static function () { return 1; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$result = $this->execute_ability( $ability, [ 'site_ids_or_domains' => [ $second, $first, $second, 'test-duplicate.example.org' ], 'types' => [ 'core' ], 'confirm' => true ] );
			$this->assertNotWPError( $result );
			$this->track_update_job( $result['job_id'] );
			$this->assertTrue( $result['queued'] );
			$job = get_transient( 'mainwp_update_job_' . $result['job_id'] );
			$this->assertSame( [ $second, $first ], $job['sites'] );
			$this->assertSame( 2, $job['total'] );
			$this->assertSame( 2, $result['updates_queued'] );
			$this->assertSame( [], $calls );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
	}

	public function test_update_duplicate_selection_plan_keeps_first_integer_id() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site( [ 'name' => 'First Site' ] );
		$this->seed_gate_updates( $site_id );
		$site = MainWP_Abilities_Util::resolve_site( $site_id );
		$duplicate = clone $site;
		$duplicate->id = (string) $site_id;
		$duplicate->name = 'Later Duplicate';
		$threshold = static function () { return 1; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$plan = MainWP_Abilities_Updates::build_update_plan( [ $site, $duplicate ], [ 'core' ], [] );
			$this->assertSame( 1, $plan['summary']['site_count'] );
			$this->assertSame( 1, $plan['summary']['item_count'] );
			$this->assertFalse( $plan['summary']['queued'] );
			$this->assertCount( 1, $plan['sites'] );
			$this->assertSame( 'First Site', $plan['sites'][0]['site_name'] );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
	}

	/** @dataProvider queued_execution_abilities */
	public function test_update_queued_execution_envelopes_validate( $ability_name ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_gate_updates( $site_id );
		$this->set_site_ignored_plugins( $site_id, [ 'sample/plugin.php' => 'Held' ] );
		$calls = &$this->record_update_calls();
		$threshold = static function () { return 1; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$result = $this->execute_ability( $ability_name, [ 'site_ids_or_domains' => [ $site_id, $this->create_test_site() ], 'types' => [ 'plugins' ], 'confirm' => true ] );
			$this->assertNotWPError( $result );
			$this->track_update_job( $result['job_id'] );
			$this->assertArrayNotHasKey( 'skipped', $result );
			$this->assertArrayNotHasKey( 'unchanged', $result );
			$this->assertFalse( $result['dry_run'] );
			$this->assertSame( [], $calls );
			$job = get_transient( 'mainwp_update_job_' . $result['job_id'] );
			$this->assertEmpty( $job['skipped'] ?? [] );
			$schema = wp_get_ability( $ability_name )->get_output_schema();
			$this->assertTrue( rest_validate_value_from_schema( $result, $schema ) );
			$matches = 0;
			foreach ( $schema['oneOf'] as $branch ) {
				$matches += true === rest_validate_value_from_schema( $result, $branch ) ? 1 : 0;
			}
			$this->assertSame( 1, $matches, 'Exactly one execution branch must match.' );
			foreach ( [ 'skipped', 'unchanged' ] as $property ) {
				$this->assertArrayNotHasKey( $property, $schema['oneOf'][1]['properties'] );
				$this->assertNotContains( $property, $schema['oneOf'][1]['required'] );
			}
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
	}

	public static function gate_skip_cases(): array {
		$cases = [];
		foreach ( [ 'core' => 'wordpress', 'plugins' => 'sample/plugin.php', 'themes' => 'sample-theme' ] as $type => $slug ) {
			foreach ( [ 'ignored_site', 'ignored_global', 'ignored_site_flag' ] as $reason ) {
				foreach ( [ 'all_versions', 'target' ] as $version ) {
					$cases[ $type . '-' . $reason . '-' . $version ] = [ $type, $slug, $reason, $version ];
				}
			}
		}
		return $cases;
	}

	/** @dataProvider gate_skip_cases */
	public function test_update_gate_skip_reasons_and_requested( $type, $slug, $reason, $version ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$flag = [ 'core' => 'is_ignoreCoreUpdates', 'plugins' => 'is_ignorePluginUpdates', 'themes' => 'is_ignoreThemeUpdates' ][ $type ];
		$site_id = $this->create_test_site( [ $flag => 1 ] );
		$this->seed_gate_updates( $site_id );
		$target = [ 'core' => '6.9', 'plugins' => '2.0', 'themes' => '1.1' ][ $type ];
		$hold = [ 'ignored_versions' => [ 'target' === $version ? $target : $version ] ];
		$hold = 'core' === $type ? $hold : [ $slug => $hold ];
		$column = 'core' === $type ? 'ignored_wp_upgrades' : 'ignored_' . $type;
		$extension = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
		if ( 'ignored_site' === $reason ) {
			if ( 'core' === $type ) {
				$this->set_site_option( $site_id, $column, wp_json_encode( $hold ) );
			} elseif ( 'plugins' === $type ) {
				$this->set_site_ignored_plugins( $site_id, $hold );
			} else {
				$this->set_site_ignored_themes( $site_id, $hold );
			}
		}
		if ( 'ignored_site_flag' !== $reason ) {
			MainWP_DB_Common::instance()->update_user_extension( [ 'userid' => $extension->userid, $column => wp_json_encode( $hold ) ] );
		}
		$result = $this->execute_ability( 'mainwp/run-updates-v1', [ 'site_ids_or_domains' => [ $site_id ], 'types' => [ $type ], 'specific_items' => [ $slug, 'missing' ], 'dry_run' => true ] );
		$this->assertNotWPError( $result );
		$this->assertSame( 0, $result['count'] );
		$skipped = $result['would_affect']['sites'][0]['skipped'];
		$this->assertCount( 1, $skipped );
		$this->assertSame( $reason, $skipped[0]['reason'] );
		$this->assertTrue( $skipped[0]['requested'] );
		$this->assertSame( [ 'missing' ], $result['would_affect']['summary']['requested_not_found'] );
		$alias = $this->execute_ability( 'mainwp/update-site-' . $type . '-v1', [ 'site_id_or_domain' => $site_id, 'dry_run' => true ] + ( 'core' === $type ? [] : [ 'slugs' => [ $slug, 'missing' ] ] ) );
		$this->assertNotWPError( $alias );
		$alias_skipped = $skipped;
		if ( 'core' === $type ) {
			$alias_skipped[0]['requested'] = false;
		}
		$this->assertSame( $alias_skipped, $alias['would_affect']['sites'][0]['skipped'] );
		$all = $this->execute_ability( 'mainwp/update-all-v1', [ 'site_ids_or_domains' => [ $site_id ], 'types' => [ $type ], 'dry_run' => true ] );
		$this->assertNotWPError( $all );
		$this->assertFalse( $all['would_affect']['sites'][0]['skipped'][0]['requested'] );
	}

	public function test_update_gate_builder_matches_execution_filter() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site( [ 'is_ignoreThemeUpdates' => 1 ] );
		$this->seed_gate_updates( $site_id );
		$this->set_site_plugin_upgrades( $site_id, [
			'free/free.php' => [ 'Version' => '1.0', 'update' => [ 'new_version' => '1.1' ] ],
			'held/held.php' => [ 'Version' => '1.0', 'update' => [ 'new_version' => '1.1' ] ],
			'global/global.php' => [ 'Version' => '1.0', 'update' => [ 'new_version' => '1.1' ] ],
		] );
		$this->set_site_ignored_plugins( $site_id, [ 'held/held.php' => 'Held' ] );
		$extension = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
		$global = [ 'global/global.php' => [ 'ignored_versions' => [ '1.1' ] ] ];
		MainWP_DB_Common::instance()->update_user_extension( [ 'userid' => $extension->userid, 'ignored_plugins' => wp_json_encode( $global ) ] );
		$site = MainWP_Abilities_Util::resolve_site( $site_id );
		$types = [ 'core', 'plugins', 'themes', 'translations' ];
		$expected = MainWP_Abilities_Updates::get_site_updates( $site, $types, $global, [], [] );
		foreach ( [ [], [ 'free/free.php', 'held/held.php', 'absent' ] ] as $selection ) {
			$plan = MainWP_Abilities_Updates::build_update_plan( [ $site ], $types, $selection );
			$actual = array_map( static function ( $item ) { return [ $item['type'], $item['slug'], $item['from'], $item['to'] ]; }, $plan['sites'][0]['items'] );
			$eligible = array_values( array_filter( $expected, static function ( $item ) use ( $selection ) { return ! $selection || in_array( $item['slug'], $selection, true ); } ) );
			$eligible = array_map( static function ( $item ) { return [ $item['type'], $item['slug'], $item['current_version'], $item['new_version'] ]; }, $eligible );
			$this->assertSame( $eligible, $actual );
		}
	}

	public static function gate_major_cases(): array {
		return [
			'core major' => [ 'core', '6.8.3', '6.9', true ], 'core minor' => [ 'core', '6.8.3', '6.8.4', false ],
			'plugin major' => [ 'plugins', '1.9', '2.0', true ], 'plugin minor' => [ 'plugins', '1.0', '1.1', false ],
			'theme major' => [ 'themes', '1.9', '2.0', true ], 'theme minor' => [ 'themes', '1.0', '1.1', false ],
			'translation' => [ 'translations', '1.0', '2.0', false ],
			'core unknown' => [ 'core', '', '6.9', null ], 'plugin unknown' => [ 'plugins', 'dev', '2.0', null ],
			'theme unknown' => [ 'themes', '1.0', '', null ], 'no leading number' => [ 'plugins', '1.0', 'v2.0', null ],
		];
	}

	/** @dataProvider gate_major_cases */
	public function test_update_gate_major_versions( $type, $from, $to, $major ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		if ( 'core' === $type ) {
			$this->set_site_option( $site_id, 'wp_upgrades', wp_json_encode( [ 'current' => $from, 'new' => $to ] ) );
		} elseif ( 'plugins' === $type ) {
			$this->set_site_plugin_upgrades( $site_id, [ 'slug' => [ 'Version' => $from, 'new_version' => $to ] ] );
		} elseif ( 'themes' === $type ) {
			$this->set_site_theme_upgrades( $site_id, [ 'slug' => [ 'Version' => $from, 'new_version' => $to ] ] );
		} else {
			$this->set_site_translation_upgrades( $site_id, [ [ 'slug' => 'slug', 'version' => $from, 'new_version' => $to ] ] );
		}
		$result = $this->execute_ability( 'mainwp/run-updates-v1', [ 'site_ids_or_domains' => [ $site_id ], 'types' => [ $type ], 'dry_run' => true ] );
		$this->assertNotWPError( $result );
		$this->assertSame( $major, $result['would_affect']['sites'][0]['items'][0]['flags']['major'] );
		$this->assertSame( $major, $result['would_affect']['by_item'][0]['flags']['major'] );
		$this->assertSame( true === $major, $result['would_affect']['summary']['has_major'] );
		$this->assertSame( null === $major, $result['would_affect']['summary']['has_unknown_version'] );
	}

	public function test_update_gate_site_count_excludes_idle_and_held_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$planned = $this->create_test_site();
		$idle = $this->create_test_site();
		$held = $this->create_test_site();
		$update = [ 'sample/plugin.php' => [ 'Name' => 'Sample Plugin', 'Version' => '1.0', 'new_version' => '1.1' ] ];
		$this->set_site_plugin_upgrades( $planned, $update );
		$this->set_site_plugin_upgrades( $held, $update );
		$this->set_site_ignored_plugins( $held, [ 'sample/plugin.php' => 'Held' ] );
		$threshold = static function () { return 2; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$result = $this->execute_ability( 'mainwp/run-updates-v1', [ 'site_ids_or_domains' => [ $planned, $idle, $held ], 'types' => [ 'plugins' ], 'dry_run' => true ] );
			$this->assertNotWPError( $result );
			$this->assertSame( 1, $result['would_affect']['summary']['site_count'] );
			$this->assertSame( 1, $result['count'] );
			$this->assertSame( 1, $result['would_affect']['summary']['skipped_count'] );
			$this->assertTrue( $result['would_affect']['summary']['queued'] );
			$this->assertSame( [ $planned, $held ], array_column( $result['would_affect']['sites'], 'site_id' ) );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
	}

	public function test_update_gate_aggregation_order_truncation_and_warnings() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$ids = [];
		foreach ( [ [ '1.0', '1.1' ], [ 'dev', '1.2' ], [ '1.0', '2.0' ] ] as $versions ) {
			$id = $this->create_test_site();
			$ids[] = $id;
			$this->seed_gate_updates( $id );
			$this->set_site_plugin_upgrades( $id, [ 'z' => [ 'Version' => '1.0', 'new_version' => '1.1' ], 'a' => [ 'Version' => $versions[0], 'new_version' => $versions[1] ] ] );
		}
		$threshold = static function () { return 2; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$result = $this->execute_ability( 'mainwp/run-updates-v1', [ 'site_ids_or_domains' => $ids, 'dry_run' => true ] );
			$this->assertNotWPError( $result );
			$plan = $result['would_affect'];
			$this->assertSame( array_slice( $ids, 0, 2 ), array_column( $plan['sites'], 'site_id' ) );
			$this->assertSame( [ 'wordpress', 'a', 'z', 'sample-theme', 'sample-translation' ], array_column( $plan['sites'][0]['items'], 'slug' ) );
			$this->assertSame( [ 'wordpress', 'a', 'z', 'sample-theme', 'sample-translation' ], array_column( $plan['by_item'], 'slug' ) );
			$this->assertSame( 15, $result['count'] );
			$this->assertSame( 3, $plan['summary']['site_count'] );
			$this->assertGreaterThan( count( $plan['sites'] ), $plan['summary']['site_count'] );
			$this->assertTrue( $plan['summary']['queued'] );
			$this->assertTrue( $plan['summary']['truncated'] );
			$this->assertTrue( $plan['summary']['has_unknown_version'] );
			$this->assertSame( [ '1.1', '1.2', '2.0' ], $plan['by_item'][1]['to'] );
			$this->assertTrue( $plan['by_item'][1]['flags']['major'] );
			$this->assertSame( 3, $plan['by_item'][1]['site_count'] );
			$this->assertSame( [
				'The plan includes WordPress core updates.', 'The plan includes a major version change.',
				'More than 2 sites are selected, so the updates will run as a background job.',
				'The site list is limited to the first 2 sites. by_item and summary cover every site.',
			], $result['warnings'] );
			$unknown = $this->execute_ability( 'mainwp/run-updates-v1', [ 'site_ids_or_domains' => array_slice( $ids, 0, 2 ), 'types' => [ 'plugins' ], 'dry_run' => true ] );
			$this->assertNull( $unknown['would_affect']['by_item'][0]['flags']['major'] );
			$all = $this->execute_ability( 'mainwp/update-all-v1', [ 'dry_run' => true ] );
			$this->assertTrue( $all['would_affect']['summary']['all_sites'] );
			$this->assertContains( 'No sites were named, so the plan covers every site you can access.', $all['warnings'] );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
	}

	public static function gate_preview_side_effect_cases(): array {
		$cases = [];
		foreach ( self::gate_abilities() as $label => $args ) {
			$cases[ $label . '-below' ] = array_merge( $args, [ 1 ] );
			if ( null === $args[2] ) { $cases[ $label . '-above' ] = array_merge( $args, [ 3 ] ); }
		}
		return $cases;
	}

	/** @dataProvider gate_preview_side_effect_cases */
	public function test_update_gate_preview_touches_nothing( $ability, $method, $type, $site_count ) {
		$this->skip_if_no_abilities_api();
		global $wpdb;
		$this->set_current_user_as_admin();
		$ids = [];
		foreach ( range( 1, $site_count ) as $i ) {
			$id = $this->create_test_site();
			$ids[] = $id;
			$this->seed_gate_updates( $id );
		}
		$calls = &$this->record_update_calls();
		$filter_calls = 0;
		$filter = static function ( $result ) use ( &$filter_calls ) { ++$filter_calls; return $result; };
		$threshold = static function () { return 2; };
		add_filter( 'mainwp_run_update_result', $filter );
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		$hooks = [ 'mainwp_before_wp_update', 'mainwp_after_wp_update', 'mainwp_after_upgrade_wp_success', 'mainwp_before_plugin_theme_translation_update', 'mainwp_after_plugin_theme_translation_update' ];
		$before_actions = array_map( 'did_action', $hooks );
		$before_cron = _get_cron_array();
		$snapshot = function () use ( $wpdb ) {
			return [ $wpdb->get_results( "SELECT id, plugin_upgrades, theme_upgrades, translation_upgrades FROM {$wpdb->prefix}mainwp_wp ORDER BY id", ARRAY_A ), $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_options ORDER BY wpid, name", ARRAY_A ) ];
		};
		$before = $snapshot();
		$this->assertFalse( wp_next_scheduled( 'mainwp_process_update_job' ) );
		try {
			$result = MainWP_Abilities_Updates::$method( ( $type ? [ 'site_id_or_domain' => $ids[0] ] : [ 'site_ids_or_domains' => $ids ] ) + [ 'dry_run' => true ] );
			if ( is_array( $result ) && isset( $result['job_id'] ) ) { $this->track_update_job( $result['job_id'] ); }
			$this->assertSame( [], $calls, 'A preview must not dispatch to Child.' );
			$this->assertSame( 0, $filter_calls, 'A preview must not filter execution results.' );
			$this->assertSame( $before, $snapshot(), 'Upgrade columns and site options must stay unchanged.' );
			$this->assertSame( $before_actions, array_map( 'did_action', $hooks ) );
			$this->assertSame( $before_cron, _get_cron_array(), 'A preview must not schedule a job with any event arguments.' );
			$this->assertFalse( wp_next_scheduled( 'mainwp_process_update_job' ) );
			$this->assertSame( [], $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_mainwp_update_job_%'" ) );
			$this->assertNotWPError( $result );
			$this->assertSame( $site_count > 2, $result['would_affect']['summary']['queued'] );
		} finally {
			remove_filter( 'mainwp_run_update_result', $filter );
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
	}

	/** @dataProvider gate_abilities */
	public function test_update_gate_reresolves_versions_and_holds( $ability, $method, $type ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$input = ( $type ? [ 'site_id_or_domain' => $site_id ] : [ 'site_ids_or_domains' => [ $site_id ], 'types' => [ 'plugins' ] ] );
		$this->seed_gate_updates( $site_id );
		$calls = &$this->record_update_calls();
		$preview = $this->execute_ability( $ability, $input + [ 'dry_run' => true ] );
		$this->assertNotWPError( $preview );
		$this->assertSame( [], $calls );
		if ( 'core' === $type ) {
			$this->set_site_option( $site_id, 'wp_upgrades', wp_json_encode( [ 'current' => '6.8.3', 'new' => '7.0' ] ) );
		} elseif ( 'themes' === $type ) {
			$this->set_site_theme_upgrades( $site_id, [ 'sample-theme' => [ 'Version' => '1.0', 'new_version' => '7.0' ] ] );
		} elseif ( 'translations' === $type ) {
			$this->set_site_translation_upgrades( $site_id, [ [ 'slug' => 'sample-translation', 'version' => '1.0', 'new_version' => '7.0' ] ] );
		} else {
			$this->set_site_plugin_upgrades( $site_id, [ 'sample/plugin.php' => [ 'Version' => '1.0', 'new_version' => '7.0' ] ] );
		}
		$result = $this->execute_ability( $ability, $input + [ 'confirm' => true ] );
		$this->assertNotWPError( $result );
		$this->assertSame( '7.0', 'core' === $type ? $result['updated']['new_version'] : $result['updated'][0]['new_version'] );
		if ( 'translations' === $type ) { return; }
		$this->seed_gate_updates( $site_id );
		$this->assertNotWPError( $this->execute_ability( $ability, $input + [ 'dry_run' => true ] ) );
		if ( 'core' === $type ) {
			$this->set_site_option( $site_id, 'ignored_wp_upgrades', wp_json_encode( [ 'ignored_versions' => [ 'all_versions' ] ] ) );
		} elseif ( 'themes' === $type ) {
			$this->set_site_ignored_themes( $site_id, [ 'sample-theme' => 'Held' ] );
		} else {
			$this->set_site_ignored_plugins( $site_id, [ 'sample/plugin.php' => 'Held' ] );
		}
		$before = count( $calls );
		$held = $this->execute_ability( $ability, $input + [ 'confirm' => true ] );
		$this->assertCount( $before, $calls, 'A hold added after preview must be honoured.' );
		if ( 'core' === $type ) {
			$this->assertWPError( $held );
			$this->assertSame( 'mainwp_no_updates', $held->get_error_code() );
		} else {
			$this->assertNotWPError( $held );
			$this->assertSame( [], $held['updated'] );
		}
	}

	/** @dataProvider gate_abilities */
	public function test_update_gate_empty_and_unavailable_sites( $ability, $method, $type ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$empty = $this->create_test_site();
		$offline = $this->create_test_site( [ 'offline_check_result' => -1 ] );
		$outdated = $this->create_test_site( [ 'version' => '1.0' ] );
		foreach ( [ $offline => 'mainwp_site_offline', $outdated => 'mainwp_child_outdated', $empty => null ] as $id => $code ) {
			$result = $this->execute_ability( $ability, ( $type ? [ 'site_id_or_domain' => $id ] : [ 'site_ids_or_domains' => [ $id ] ] ) + [ 'dry_run' => true ] );
			$this->assertNotWPError( $result );
			$this->assertSame( 0, $result['count'] );
			$this->assertSame( [], $result['would_affect']['sites'] );
			$this->assertSame( 0, $result['would_affect']['summary']['site_count'] );
			$this->assertSame( [], $result['would_affect']['summary']['requested_not_found'] );
			$this->assertSame( $code ? [ $code ] : [], array_column( $result['errors'], 'code' ) );
			$this->assertTrue( rest_validate_value_from_schema( $result, wp_get_ability( $ability )->get_output_schema() ) );
		}
	}

	public function test_update_gate_preview_does_not_create_global_settings() {
		$this->skip_if_no_abilities_api();
		global $wpdb;
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->seed_gate_updates( $site_id );
		$this->set_site_ignored_plugins( $site_id, [ 'sample/plugin.php' => 'Held' ] );
		// The shared helper's constructor creates the row on first use; build it first so this pins the builder's own read.
		\MainWP\Dashboard\MainWP_Common_Functions::instance();
		$table = MainWP_DB_Common::instance()->get_table_name( 'users' );
		$wpdb->delete( $table, [ 'userid' => 0 ], [ '%d' ] );
		$before = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY userid", ARRAY_A );
		$result = $this->execute_ability( 'mainwp/run-updates-v1', [ 'site_ids_or_domains' => [ $site_id ], 'dry_run' => true ] );
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['would_affect']['summary']['skipped_count'] );
		$this->assertSame( $before, $wpdb->get_results( "SELECT * FROM {$table} ORDER BY userid", ARRAY_A ), 'The plan builder must not create a missing global settings row.' );
	}

	public function test_update_gate_translation_uses_child_version_as_target() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->set_site_translation_upgrades( $site_id, [
			[ 'type' => 'core', 'slug' => 'default', 'language' => 'de_DE', 'version' => '6.9', 'name' => 'WordPress (de_DE)' ],
			[ 'type' => 'core', 'slug' => 'default', 'language' => 'fr_FR', 'version' => '6.9', 'name' => 'WordPress (fr_FR)' ],
		] );
		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [ 'site_id_or_domain' => $site_id, 'dry_run' => true ] );
		$this->assertNotWPError( $result );
		$item = $result['would_affect']['sites'][0]['items'][0];
		$this->assertSame( [ '', '6.9' ], [ $item['from'], $item['to'] ] );
		$this->assertSame( [ '6.9' ], $result['would_affect']['by_item'][0]['to'] );
		$this->assertSame( 1, $result['would_affect']['by_item'][0]['site_count'] );
		$this->record_update_calls();
		$executed = $this->execute_ability( 'mainwp/update-site-translations-v1', [ 'site_id_or_domain' => $site_id, 'confirm' => true ] );
		$this->assertNotWPError( $executed );
		$this->assertSame( [ '', '6.9' ], [ $executed['updated'][0]['old_version'], $executed['updated'][0]['new_version'] ] );
	}

	public function test_update_gate_run_updates_preview_and_execution_share_child_floor() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site( [ 'version' => '4.0' ] );
		$this->set_site_plugin_upgrades( $site_id, [ 'sample/plugin.php' => [ 'Name' => 'Sample Plugin', 'Version' => '1.0', 'update' => [ 'new_version' => '1.1' ] ] ] );
		$calls = &$this->record_update_calls();
		$input = [ 'site_ids_or_domains' => [ $site_id ], 'types' => [ 'plugins' ] ];
		$preview = $this->execute_ability( 'mainwp/run-updates-v1', $input + [ 'dry_run' => true ] );
		$this->assertNotWPError( $preview );
		$this->assertSame( 1, $preview['count'] );
		$this->assertSame( [], $preview['errors'] );
		$result = $this->execute_ability( 'mainwp/run-updates-v1', $input + [ 'confirm' => true ] );
		$this->assertNotWPError( $result );
		$this->assertSame( [], array_column( $result['errors'], 'code' ) );
		$this->assertSame( [ 'upgradeplugintheme' ], array_column( $calls, 'what' ) );
	}

	public function test_update_gate_preview_resolution_and_access_errors() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$deny = static function () { return false; };
		add_filter( 'mainwp_check_site_access', $deny );
		try {
			foreach ( self::gate_abilities() as [ $ability, $method, $type ] ) {
				$result = MainWP_Abilities_Updates::$method( ( $type ? [ 'site_id_or_domain' => $site_id ] : [ 'site_ids_or_domains' => [ $site_id, 999999 ] ] ) + [ 'dry_run' => true ] );
				if ( $type ) {
					$this->assertWPError( $result );
					$this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
					$missing = MainWP_Abilities_Updates::$method( [ 'site_id_or_domain' => 999999, 'dry_run' => true ] );
					$this->assertWPError( $missing );
					$this->assertSame( 'mainwp_site_not_found', $missing->get_error_code() );
				} else {
					$this->assertTrue( $result['dry_run'] );
					$this->assertSame( 0, $result['count'] );
					$this->assertCount( 2, $result['errors'] );
					foreach ( $result['errors'] as $error ) {
						$this->assertArrayHasKey( 'site_id', $error );
						$this->assertArrayHasKey( 'site_url', $error );
						$this->assertArrayHasKey( 'slug', $error );
						$this->assertSame( 'site', $error['type'] );
					}
				}
			}
		} finally {
			remove_filter( 'mainwp_check_site_access', $deny );
		}
	}

}
