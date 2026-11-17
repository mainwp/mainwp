<?php
/**
 * MainWP Suspend Client Ability Tests
 *
 * Tests for the mainwp/suspend-client-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class Test_Suspend_Client_Ability
 *
 * Tests for the mainwp/suspend-client-v1 ability.
 */
class Test_Suspend_Client_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Test that the ability is registered.
	 *
	 * @return void
	 */
	public function test_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/suspend-client-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/suspend-client-v1 should be registered.' );
	}

	/**
	 * Test that suspend-client returns expected structure.
	 *
	 * @return void
	 */
	public function test_suspend_client_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$client_id = $this->create_test_client( [
			'name'      => 'Client To Suspend',
			'suspended' => 0,
		] );

		$result = $this->execute_ability( 'mainwp/suspend-client-v1', [
			'client_id_or_email' => $client_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'suspended', $result );
		$this->assertEquals( $client_id, $result['id'] );
		$this->assertEquals( 1, $result['suspended'] );
	}

	/**
	 * Suspending a client walks the canonical path: the mainwp_client_suspend
	 * hook fires and the client's sites are suspended too (M19).
	 *
	 * @return void
	 */
	public function test_suspend_client_fires_hook_and_suspends_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$client_id = $this->create_test_client( [
			'name'      => 'Client With Sites',
			'suspended' => 0,
		] );
		$site_id   = $this->create_test_site( [ 'name' => 'ClientSite', 'url' => 'https://clientsite.example/' ] );
		global $wpdb;
		$wpdb->update( \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' ), [ 'client_id' => $client_id ], [ 'id' => $site_id ] );

		$hook_calls = [];
		$listener   = static function ( $client, $suspended ) use ( &$hook_calls ) {
			$hook_calls[] = [ $client, $suspended ];
		};
		add_action( 'mainwp_client_suspend', $listener, 10, 2 );

		$result = $this->execute_ability( 'mainwp/suspend-client-v1', [
			'client_id_or_email' => $client_id,
		] );
		remove_action( 'mainwp_client_suspend', $listener, 10 );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $hook_calls, 'mainwp_client_suspend must fire once.' );
		$this->assertEquals( $client_id, $hook_calls[0][0]->client_id );
		$this->assertTrue( (bool) $hook_calls[0][1] );
		$site = $this->get_test_site( $site_id );
		$this->assertEquals( 1, (int) $site->suspended, 'Client sites must be suspended with the client.' );
	}

	/**
	 * Test that suspend-client requires authentication.
	 *
	 * @return void
	 */
	public function test_suspend_client_requires_authentication() {
		$this->skip_if_no_abilities_api();
		wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/suspend-client-v1', [
			'client_id_or_email' => 1,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that suspend-client requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_suspend_client_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_subscriber();

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/suspend-client-v1', [
			'client_id_or_email' => 1,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that suspend-client validates input.
	 *
	 * @return void
	 */
	public function test_suspend_client_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/suspend-client-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Test that suspend-client updates database.
	 *
	 * @return void
	 */
	public function test_suspend_client_updates_database() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$client_id = $this->create_test_client( [
			'name'      => 'Client To Suspend In DB',
			'suspended' => 0,
		] );

		$result = $this->execute_ability( 'mainwp/suspend-client-v1', [
			'client_id_or_email' => $client_id,
		] );

		$this->assertNotWPError( $result );

		$client = $this->get_test_client( $client_id );
		$this->assertNotNull( $client );
		$this->assertEquals( 1, $client->suspended );
	}

	/**
	 * Test that suspend-client returns not found error.
	 *
	 * @return void
	 */
	public function test_suspend_client_returns_not_found_error() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/suspend-client-v1', [
			'client_id_or_email' => 999999,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_client_not_found', $result->get_error_code() );
	}
}
