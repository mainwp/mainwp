<?php
/**
 * MainWP Site Plugins and Themes Ability Tests
 *
 * Tests for mainwp/get-site-plugins-v1 and mainwp/get-site-themes-v1 abilities.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Site_Plugins_Themes_Ability_Test
 *
 * Tests for plugin and theme listing abilities.
 */
class MainWP_Site_Plugins_Themes_Ability_Test extends MainWP_Abilities_Test_Case {

	// =========================================================================
	// Plugins Tests
	// =========================================================================

	/**
	 * Test that get-site-plugins ability is registered.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/get-site-plugins-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/get-site-plugins-v1 should be registered.' );
	}

	/**
	 * Test that get-site-plugins returns plugin list.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_returns_plugin_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Plugins Test Site',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'site_id', $result );
		$this->assertArrayHasKey( 'plugins', $result );
		$this->assertEquals( $site_id, $result['site_id'] );
		$this->assertIsArray( $result['plugins'] );
	}

	/**
	 * Test that get-site-plugins filters by status.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_filters_by_status() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create site with plugin data.
		$site_id = $this->create_test_site( [
			'name' => 'Plugin Status Test Site',
		] );

		// Seed plugin data with active and inactive plugins.
		$this->set_site_plugins( $site_id, [
			'akismet/akismet.php'   => [
				'Name'    => 'Akismet',
				'Version' => '5.0',
				'active'  => 1,
			],
			'hello-dolly/hello.php' => [
				'Name'    => 'Hello Dolly',
				'Version' => '1.7',
				'active'  => 0,
			],
		] );

		$result = $this->execute_ability( 'mainwp/get-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'status'            => 'active',
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['plugins'] );

		// All returned plugins should be active.
		foreach ( $result['plugins'] as $plugin ) {
			$this->assertArrayHasKey( 'active', $plugin, 'Plugin should have active key.' );
			$this->assertEquals( 1, $plugin['active'], 'Filtered plugins should be active.' );
		}
	}

	/**
	 * Test that get-site-plugins filters to plugins with updates when has_update=true.
	 *
	 * The has_update filter returns only plugins that have available updates.
	 * The update_version field is always included for plugins with updates.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_includes_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Plugin Updates Test Site',
		] );

		// Seed installed plugins.
		$this->set_site_plugins( $site_id, [
			'akismet/akismet.php' => [
				'name'    => 'Akismet',
				'version' => '5.0',
				'active'  => 1,
			],
			'hello-dolly/hello.php' => [
				'name'    => 'Hello Dolly',
				'version' => '1.0',
				'active'  => 0,
			],
		] );

		// Seed plugin upgrade data (only akismet has update).
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		// Without filter - should return all plugins.
		$result_all = $this->execute_ability( 'mainwp/get-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result_all );
		$this->assertCount( 2, $result_all['plugins'] );

		// With has_update filter - should return only akismet.
		$result = $this->execute_ability( 'mainwp/get-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'has_update'        => true,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['plugins'] );
		$this->assertEquals( 'akismet/akismet.php', $result['plugins'][0]['slug'] );
		$this->assertEquals( '5.1', $result['plugins'][0]['update_version'] );
	}

	/**
	 * Test that get-site-plugins returns error for non-existent site.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error (mainwp_site_not_found)
	 * is preserved in the error message.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_not_found_returns_error() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-site-plugins-v1', [
			'site_id_or_domain' => 999999,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		// Original error message should mention the site was not found.
		$this->assertStringContainsString( 'site', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test that get-site-plugins handles empty plugin list.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_handles_empty_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Empty Plugins Site',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['plugins'] );
		// Empty list is valid.
	}

	/**
	 * Test that get-site-plugins accepts domain input.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_accepts_domain() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Domain Plugins Site',
			'url'  => 'https://test-domainplugins.example.com/',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-plugins-v1', [
			'site_id_or_domain' => 'test-domainplugins.example.com',
		] );

		$this->assertNotWPError( $result );
		$this->assertEquals( $site_id, $result['site_id'] );
	}

	// =========================================================================
	// Themes Tests
	// =========================================================================

	/**
	 * Test that get-site-themes ability is registered.
	 *
	 * @return void
	 */
	public function test_get_site_themes_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/get-site-themes-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/get-site-themes-v1 should be registered.' );
	}

	/**
	 * Test that get-site-themes returns theme list.
	 *
	 * @return void
	 */
	public function test_get_site_themes_returns_theme_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Themes Test Site',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-themes-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'site_id', $result );
		$this->assertArrayHasKey( 'themes', $result );
		$this->assertArrayHasKey( 'active_theme', $result );
		$this->assertEquals( $site_id, $result['site_id'] );
		$this->assertIsArray( $result['themes'] );
	}

	/**
	 * Test that get-site-themes filters by status.
	 *
	 * @return void
	 */
	public function test_get_site_themes_filters_by_status() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Theme Status Test Site',
		] );

		// Seed theme data.
		$this->set_site_themes( $site_id, [
			'twentytwentyfour'  => [
				'Name'    => 'Twenty Twenty-Four',
				'Version' => '1.0',
				'active'  => 1,
			],
			'twentytwentythree' => [
				'Name'    => 'Twenty Twenty-Three',
				'Version' => '1.2',
				'active'  => 0,
			],
		] );

		$result = $this->execute_ability( 'mainwp/get-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'status'            => 'active',
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['themes'] );

		// All returned themes should be active.
		foreach ( $result['themes'] as $theme ) {
			$this->assertArrayHasKey( 'active', $theme, 'Theme should have active key.' );
			$this->assertEquals( 1, $theme['active'], 'Filtered themes should be active.' );
		}
	}

	/**
	 * Test that get-site-themes filters to themes with updates when has_update=true.
	 *
	 * The has_update filter returns only themes that have available updates.
	 * The update_version field is always included for themes with updates.
	 *
	 * @return void
	 */
	public function test_get_site_themes_includes_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Theme Updates Test Site',
		] );

		// Seed installed themes.
		$this->set_site_themes( $site_id, [
			'twentytwentyfour' => [
				'name'    => 'Twenty Twenty-Four',
				'version' => '1.0',
				'active'  => 1,
			],
			'twentytwentythree' => [
				'name'    => 'Twenty Twenty-Three',
				'version' => '1.0',
				'active'  => 0,
			],
		] );

		// Seed theme upgrade data (only twentytwentyfour has update).
		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Without filter - should return all themes.
		$result_all = $this->execute_ability( 'mainwp/get-site-themes-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result_all );
		$this->assertCount( 2, $result_all['themes'] );

		// With has_update filter - should return only twentytwentyfour.
		$result = $this->execute_ability( 'mainwp/get-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'has_update'        => true,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['themes'] );
		$this->assertEquals( 'twentytwentyfour', $result['themes'][0]['slug'] );
		$this->assertEquals( '1.1', $result['themes'][0]['update_version'] );
	}

	/**
	 * Test that get-site-themes returns error for non-existent site.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_get_site_themes_not_found_returns_error() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-site-themes-v1', [
			'site_id_or_domain' => 999999,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		// Original error message should mention the site was not found.
		$this->assertStringContainsString( 'site', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test that get-site-themes handles empty theme list.
	 *
	 * @return void
	 */
	public function test_get_site_themes_handles_empty_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Empty Themes Site',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-themes-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['themes'] );
	}

	/**
	 * Test that get-site-themes accepts domain input.
	 *
	 * @return void
	 */
	public function test_get_site_themes_accepts_domain() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Domain Themes Site',
			'url'  => 'https://test-domainthemes.example.com/',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-themes-v1', [
			'site_id_or_domain' => 'test-domainthemes.example.com',
		] );

		$this->assertNotWPError( $result );
		$this->assertEquals( $site_id, $result['site_id'] );
	}

	/**
	 * Test that get-site-themes returns active theme.
	 *
	 * @return void
	 */
	public function test_get_site_themes_returns_active_theme() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Active Theme Test Site',
		] );

		// Seed theme data with active theme indicator.
		$this->set_site_themes( $site_id, [
			'twentytwentyfour'  => [
				'Name'    => 'Twenty Twenty-Four',
				'Version' => '1.0',
				'active'  => 1,
			],
			'twentytwentythree' => [
				'Name'    => 'Twenty Twenty-Three',
				'Version' => '1.2',
				'active'  => 0,
			],
		] );

		$result = $this->execute_ability( 'mainwp/get-site-themes-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'active_theme', $result );
	}

	/**
	 * Test that get-site-themes requires site_id_or_domain.
	 *
	 * @return void
	 */
	public function test_get_site_themes_requires_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-site-themes-v1', [] );

		// Should return error due to missing required input.
		$this->assertWPError( $result );
	}

	/**
	 * Test that get-site-plugins requires site_id_or_domain.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_requires_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-site-plugins-v1', [] );

		// Should return error due to missing required input.
		$this->assertWPError( $result );
	}
}
