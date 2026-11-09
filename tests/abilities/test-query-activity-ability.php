<?php
/**
 * Tests for mainwp/query-activity-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/query-activity-v1.
 *
 * @group abilities
 * @group abilities-insights
 */
class Test_Query_Activity_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Ability is registered and discoverable.
	 */
	public function test_ability_is_registered() {
		$this->skip_if_no_abilities_api();
		$abilities = wp_get_abilities();
		$this->assertArrayHasKey( 'mainwp/query-activity-v1', $abilities );
	}

	/**
	 * With the Logs module unavailable (always true under phpunit), the ability
	 * returns the structured module error, never a silent empty.
	 */
	public function test_query_activity_reports_module_not_available() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		if ( class_exists( 'MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
			$this->markTestSkipped( 'Logs module loaded in this environment; data path is covered live.' );
		}

		$result = $this->execute_ability( 'mainwp/query-activity-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_module_not_available', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertEquals( 501, $data['status'] );
	}

	/**
	 * Source-drift guard for S3: the logs query aliases the site name as
	 * log_site_name (class-log-query.php), so the formatter must read that
	 * column — reading ->name always yields ''. The Logs module is disabled
	 * under phpunit (tests/bootstrap.php), so the data path can't execute
	 * here; this pins the source instead.
	 */
	public function test_query_activity_reads_log_site_name_column() {
		$source = file_get_contents( MAINWP_PLUGIN_DIR . 'includes/abilities/class-mainwp-abilities-insights.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading own source for a drift guard.

		$this->assertStringContainsString( '$record->log_site_name', $source, 'query-activity must read the log_site_name alias.' );
		$this->assertStringNotContainsString( 'isset( $record->name )', $source, 'The bare ->name column does not exist on log rows.' );
	}

	/**
	 * Unauthenticated users are denied.
	 */
	public function test_query_activity_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/query-activity-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Users without manage_options are denied.
	 */
	public function test_query_activity_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/query-activity-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Schema rejects out-of-range input.
	 */
	public function test_query_activity_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/query-activity-v1', [ 'per_page' => 5000 ] );

		$this->assertWPError( $result, 'per_page above schema maximum must be rejected.' );
	}
}
