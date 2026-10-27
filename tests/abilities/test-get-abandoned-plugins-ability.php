<?php
/**
 * MainWP GetAbandonedPlugins Ability Tests
 *
 * Tests for the mainwp/get-abandoned-plugins-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-abandoned-plugins-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_GetAbandonedPlugins_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/get-abandoned-plugins-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/get-abandoned-plugins-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_get_abandoned_plugins_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-get-abandoned-plugins.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/get-abandoned-plugins-v1', [
            'site_id_or_domain' => $site_id,
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey('plugins', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertIsArray($result['plugins']);
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_get_abandoned_plugins_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/get-abandoned-plugins-v1', [
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
     * Test that users without manage_options capability are denied.
     *
     * @return void
     */
    public function test_get_abandoned_plugins_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/get-abandoned-plugins-v1', [
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
     * Test input validation rejects invalid values.
     *
     * @return void
     */
    public function test_get_abandoned_plugins_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/get-abandoned-plugins-v1', [
            'site_id_or_domain' => 999999,
        ] );

        $this->assertWPError( $result, 'Invalid input should return WP_Error.' );
		$this->assertSame( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
    }

    /**
     * Test that plugins_outdate_info option is correctly parsed.
     *
     * Seeds the plugins_outdate_info option with representative sync data
     * and verifies the ability correctly transforms it into the expected output.
     *
     * @return void
     */
    public function test_get_abandoned_plugins_parses_outdate_info_option() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Option Parsing Test Site',
            'url'  => 'https://test-option-parsing-plugins.example.com/',
        ] );

        // Seed representative outdate data matching sync structure.
        $outdate_timestamp = time() - ( 400 * DAY_IN_SECONDS ); // 400 days ago.
        $outdate_data = array(
            'old-plugin/old-plugin.php' => array(
                'Name'              => 'Old Plugin',
                'Version'           => '1.2.3',
                'last_updated'      => $outdate_timestamp,
                'outdate_timestamp' => $outdate_timestamp,
                'PluginURI'         => 'https://example.com/old-plugin',
            ),
            'abandoned-plugin/main.php' => array(
                'Name'              => 'Abandoned Plugin',
                'Version'           => '2.0.0',
                'last_updated'      => $outdate_timestamp - ( 100 * DAY_IN_SECONDS ), // 500 days ago.
                'outdate_timestamp' => $outdate_timestamp - ( 100 * DAY_IN_SECONDS ),
                'PluginURI'         => 'https://example.com/abandoned-plugin',
            ),
        );

        $this->set_site_option( $site_id, 'plugins_outdate_info', wp_json_encode( $outdate_data ) );

        $result = $this->execute_ability( 'mainwp/get-abandoned-plugins-v1', [
            'site_id_or_domain' => $site_id,
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertArrayHasKey( 'plugins', $result, 'Result should have plugins key.' );
        $this->assertArrayHasKey( 'total', $result, 'Result should have total key.' );
        $this->assertEquals( 2, $result['total'], 'Total should match number of seeded plugins.' );
        $this->assertCount( 2, $result['plugins'], 'Plugins array should contain 2 items.' );

        // Find the old-plugin entry and verify fields.
        $old_plugin = null;
        $abandoned_plugin = null;
        foreach ( $result['plugins'] as $plugin ) {
            if ( 'old-plugin/old-plugin.php' === $plugin['slug'] ) {
                $old_plugin = $plugin;
            } elseif ( 'abandoned-plugin/main.php' === $plugin['slug'] ) {
                $abandoned_plugin = $plugin;
            }
        }

        $this->assertNotNull( $old_plugin, 'Should contain old-plugin entry.' );
        $this->assertEquals( 'Old Plugin', $old_plugin['name'], 'Plugin name should match seeded data.' );
        $this->assertEquals( '1.2.3', $old_plugin['version'], 'Plugin version should match seeded data.' );
        // Timestamps are converted to ISO 8601 format by the ability.
        $this->assertEquals( gmdate( 'c', $outdate_timestamp ), $old_plugin['last_updated'], 'Plugin last_updated should match seeded data as ISO date.' );
        $this->assertArrayHasKey( 'days_since_update', $old_plugin, 'Should have days_since_update field.' );
        $this->assertGreaterThanOrEqual( 400, $old_plugin['days_since_update'], 'days_since_update should be at least 400.' );

        $this->assertNotNull( $abandoned_plugin, 'Should contain abandoned-plugin entry.' );
        $this->assertEquals( 'Abandoned Plugin', $abandoned_plugin['name'], 'Plugin name should match seeded data.' );
        $this->assertEquals( '2.0.0', $abandoned_plugin['version'], 'Plugin version should match seeded data.' );
        $this->assertGreaterThanOrEqual( 500, $abandoned_plugin['days_since_update'], 'days_since_update should be at least 500.' );
    }

    /**
     * Test empty plugins_outdate_info option returns empty array.
     *
     * @return void
     */
    public function test_get_abandoned_plugins_handles_empty_option() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Empty Option Test Site',
            'url'  => 'https://test-empty-option-plugins.example.com/',
        ] );

        // Explicitly set empty JSON array.
        $this->set_site_option( $site_id, 'plugins_outdate_info', '[]' );

        $result = $this->execute_ability( 'mainwp/get-abandoned-plugins-v1', [
            'site_id_or_domain' => $site_id,
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertEquals( 0, $result['total'], 'Total should be 0 for empty option.' );
        $this->assertCount( 0, $result['plugins'], 'Plugins array should be empty.' );
    }

    /**
     * Test plugins_outdate_info with missing optional fields.
     *
     * Verifies graceful handling when some fields are missing from sync data.
     *
     * @return void
     */
    public function test_get_abandoned_plugins_handles_partial_data() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Partial Data Test Site',
            'url'  => 'https://test-partial-data-plugins.example.com/',
        ] );

        // Seed minimal data - only slug as key, missing most fields.
        $outdate_data = array(
            'minimal-plugin/plugin.php' => array(
                'Name' => 'Minimal Plugin',
                // Missing: Version, last_updated, outdate_timestamp.
            ),
        );

        $this->set_site_option( $site_id, 'plugins_outdate_info', wp_json_encode( $outdate_data ) );

        $result = $this->execute_ability( 'mainwp/get-abandoned-plugins-v1', [
            'site_id_or_domain' => $site_id,
        ] );

        $this->assertNotWPError( $result, 'Should return successful result with partial data.' );
        $this->assertEquals( 1, $result['total'], 'Total should be 1.' );

        $plugin = $result['plugins'][0];
        $this->assertEquals( 'minimal-plugin/plugin.php', $plugin['slug'], 'Slug should be set.' );
        $this->assertEquals( 'Minimal Plugin', $plugin['name'], 'Name should be set from data.' );
        $this->assertEquals( '', $plugin['version'], 'Version should default to empty string.' );
        $this->assertEquals( '', $plugin['last_updated'], 'last_updated should default to empty string.' );
        $this->assertEquals( 0, $plugin['days_since_update'], 'days_since_update should default to 0.' );
    }
}
