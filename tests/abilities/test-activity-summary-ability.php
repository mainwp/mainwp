<?php
/**
 * Tests for mainwp/get-activity-summary-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-activity-summary-v1.
 *
 * @group abilities
 * @group abilities-insights
 */
class Test_Activity_Summary_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Ability is registered and discoverable.
	 */
	public function test_activity_summary_is_registered() {
		$this->skip_if_no_abilities_api();
		$abilities = wp_get_abilities();
		$this->assertArrayHasKey( 'mainwp/get-activity-summary-v1', $abilities );
	}

	/**
	 * With the Logs module unavailable (always true under phpunit), the ability
	 * returns the structured module error, never a silent empty.
	 */
	public function test_activity_summary_reports_module_not_available() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		if ( class_exists( 'MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
			$this->markTestSkipped( 'Logs module loaded in this environment; data path is covered live.' );
		}

		$result = $this->execute_ability( 'mainwp/get-activity-summary-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_module_not_available', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertEquals( 501, $data['status'] );
	}

	/**
	 * Unauthenticated users are denied.
	 */
	public function test_activity_summary_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/get-activity-summary-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Users without manage_options are denied.
	 */
	public function test_activity_summary_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/get-activity-summary-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Schema rejects unknown group_by values.
	 */
	public function test_activity_summary_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-activity-summary-v1', [ 'group_by' => 'nonsense' ] );

		$this->assertWPError( $result, 'group_by outside the enum must be rejected.' );
	}
}
