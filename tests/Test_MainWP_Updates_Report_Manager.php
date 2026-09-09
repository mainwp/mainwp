<?php
/**
 * Test MainWP_Updates_Report_Manager.
 *
 * @package MainWP\Tests
 */

use MainWP\Dashboard\MainWP_Updates_Report_Manager;

/**
 * Class Test_MainWP_Updates_Report_Manager
 */
class Test_MainWP_Updates_Report_Manager extends WP_UnitTestCase {

    /**
     * Clean option before each test.
     */
    public function setUp(): void {
        parent::setUp();

        delete_option( MainWP_Updates_Report_Manager::OPTION_NAME );
        delete_option( 'mainwp_automatic_updates_ready_to_send_notification' );
    }

    /**
     * Clean option after each test.
     */
    public function tearDown(): void {
        delete_option( MainWP_Updates_Report_Manager::OPTION_NAME );
        delete_option( 'mainwp_automatic_updates_ready_to_send_notification' );

        parent::tearDown();
    }

    /**
     * Test init().
     */
    public function test_init() {

        MainWP_Updates_Report_Manager::init();

        $this->assertEquals(
            0,
            get_option( 'mainwp_automatic_updates_ready_to_send_notification' )
        );

        $this->assertSame(
            array(
                MainWP_Updates_Report_Manager::TYPE_PLUGIN => array(),
                MainWP_Updates_Report_Manager::TYPE_THEME  => array(),
                MainWP_Updates_Report_Manager::TYPE_CORE   => array(),
                MainWP_Updates_Report_Manager::TYPE_TRANSLATION => array(),
            ),
            get_option( MainWP_Updates_Report_Manager::OPTION_NAME )
        );
    }

    /**
     * Invalid save type.
     */
    public function test_save_invalid_type() {

        $this->assertFalse(
            MainWP_Updates_Report_Manager::save(
                'invalid',
                array()
            )
        );
    }

    /**
     * Save one plugin item.
     */
    public function test_save_plugin_item() {

        $item = array(
            'name'    => 'Hello Dolly',
            'success' => 1,
            'site_id' => 1,
        );

        $this->assertTrue(
            MainWP_Updates_Report_Manager::save(
                MainWP_Updates_Report_Manager::TYPE_PLUGIN,
                $item
            )
        );

        $data = get_option(
            MainWP_Updates_Report_Manager::OPTION_NAME
        );

        $this->assertCount(
            1,
            $data[ MainWP_Updates_Report_Manager::TYPE_PLUGIN ]
        );

        $this->assertEquals(
            $item,
            $data[ MainWP_Updates_Report_Manager::TYPE_PLUGIN ][0]
        );
    }

    /**
     * Save multiple plugin items.
     */
    public function test_save_multiple_plugin_items() {

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            array(
                'name'    => 'Plugin A',
                'site_id' => 1,
                'success' => 1,
            )
        );

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            array(
                'name'    => 'Plugin B',
                'site_id' => 1,
                'success' => 1,
            )
        );

        $plugins = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN
        );

        $this->assertCount( 2, $plugins );

        $this->assertEquals(
            'Plugin A',
            $plugins[0]['name']
        );

        $this->assertEquals(
            'Plugin B',
            $plugins[1]['name']
        );
    }

    /**
     * Get empty.
     */
    public function test_get_empty() {

        $this->assertSame(
            array(),
            MainWP_Updates_Report_Manager::get()
        );
    }

    /**
     * Get invalid type.
     */
    public function test_get_invalid_type() {

        MainWP_Updates_Report_Manager::init();

        $this->assertSame(
            array(),
            MainWP_Updates_Report_Manager::get(
                'foobar'
            )
        );
    }

    /**
     * Get plugin type.
     */
    public function test_get_plugin_type() {

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            array(
                'name'    => 'Plugin',
                'success' => 1,
                'site_id' => 1,
            )
        );

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_THEME,
            array(
                'name'    => 'Theme',
                'success' => 1,
                'site_id' => 1,
            )
        );

        $plugins = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN
        );

        $this->assertCount( 1, $plugins );

        $this->assertEquals(
            'Plugin',
            $plugins[0]['name']
        );
    }

    /**
     * Get all types.
     */
    public function test_get_all_types() {

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            array(
                'name'    => 'Plugin',
                'success' => 1,
                'site_id' => 1,
            )
        );

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_CORE,
            array(
                'version' => '6.8',
                'success' => 1,
                'site_id' => 1,
            )
        );

        $data = MainWP_Updates_Report_Manager::get();

        $this->assertArrayHasKey(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            $data
        );

        $this->assertArrayHasKey(
            MainWP_Updates_Report_Manager::TYPE_CORE,
            $data
        );

        $this->assertCount(
            1,
            $data[ MainWP_Updates_Report_Manager::TYPE_PLUGIN ]
        );

        $this->assertCount(
            1,
            $data[ MainWP_Updates_Report_Manager::TYPE_CORE ]
        );
    }

    /**
     * Save initializes missing option.
     */
    public function test_save_when_option_missing() {

        delete_option(
            MainWP_Updates_Report_Manager::OPTION_NAME
        );

        $this->assertTrue(
            MainWP_Updates_Report_Manager::save(
                MainWP_Updates_Report_Manager::TYPE_THEME,
                array(
                    'name'    => 'Twenty Twenty',
                    'success' => 1,
                    'site_id' => 2,
                )
            )
        );

        $themes = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_THEME
        );

        $this->assertCount(
            1,
            $themes
        );
    }

        /**
         * Test save_update_info() with invalid website.
         */
    public function test_save_update_info_invalid_website() {

        $this->assertFalse(
            MainWP_Updates_Report_Manager::save_update_info(
                null,
                array(),
                'plugin'
            )
        );
    }

    /**
     * Test save_update_info() with invalid website id.
     */
    public function test_save_update_info_missing_website_id() {

        $website = (object) array();

        $this->assertFalse(
            MainWP_Updates_Report_Manager::save_update_info(
                $website,
                array(),
                'plugin'
            )
        );
    }

    /**
     * Test save_update_info() with invalid type.
     */
    public function test_save_update_info_invalid_type() {

        $website = (object) array(
            'id' => 123,
        );

        $this->assertFalse(
            MainWP_Updates_Report_Manager::save_update_info(
                $website,
                array(),
                'foobar'
            )
        );
    }

    /**
     * Test save_update_info() with empty plugin data.
     */
    public function test_save_update_info_empty_plugin_data() {

        $website = (object) array(
            'id' => 123,
        );

        $this->assertFalse(
            MainWP_Updates_Report_Manager::save_update_info(
                $website,
                array(
                    'updated_data' => array(),
                ),
                'plugin'
            )
        );
    }

    /**
     * Test save_update_info() with invalid updated_data.
     */
    public function test_save_update_info_invalid_updated_data() {

        $website = (object) array(
            'id' => 123,
        );

        $this->assertFalse(
            MainWP_Updates_Report_Manager::save_update_info(
                $website,
                array(
                    'updated_data' => 'invalid',
                ),
                'plugin'
            )
        );
    }

    /**
     * Test saving one plugin.
     */
    public function test_save_update_info_plugin() {

        $website = (object) array(
            'id' => 10,
        );

        $result = MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'updated_data' => array(
                    array(
                        'name'    => 'Akismet',
                        'success' => 1,
                        'version' => '5.0',
                    ),
                ),
            ),
            'plugin'
        );

        $this->assertTrue( $result );

        $plugins = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN
        );

        $this->assertCount( 1, $plugins );
        $this->assertEquals( 'Akismet', $plugins[0]['name'] );
        $this->assertEquals( 10, $plugins[0]['site_id'] );
    }

    /**
     * Test saving multiple plugins.
     */
    public function test_save_update_info_multiple_plugins() {

        $website = (object) array(
            'id' => 55,
        );

        $result = MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'updated_data' => array(
                    array(
                        'name'    => 'Plugin One',
                        'success' => 1,
                    ),
                    array(
                        'name'    => 'Plugin Two',
                        'success' => 1,
                    ),
                    array(
                        'name'    => 'Plugin Three',
                        'success' => 0,
                    ),
                ),
            ),
            'plugin'
        );

        $this->assertTrue( $result );

        $plugins = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN
        );

        $this->assertCount( 3, $plugins );
    }

    /**
     * Test invalid plugin entries are skipped.
     */
    public function test_save_update_info_skips_invalid_entries() {

        $website = (object) array(
            'id' => 77,
        );

        $result = MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'updated_data' => array(
                    array(),
                    array(
                        'version' => '1.0',
                    ),
                    array(
                        'name' => 'Valid Plugin',
                    ),
                ),
            ),
            'plugin'
        );

        $this->assertTrue( $result );

        $plugins = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN
        );

        $this->assertCount( 1, $plugins );
        $this->assertEquals( 'Valid Plugin', $plugins[0]['name'] );
    }

    /**
     * Test saving a core update.
     */
    public function test_save_update_info_core_success() {

        $website = (object) array(
            'id' => 5,
        );

        $result = MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'upgrade' => 'SUCCESS',
                'version' => '6.8',
            ),
            'core'
        );

        $this->assertTrue( $result );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );

        $this->assertCount( 1, $core );
        $this->assertEquals( 1, $core[0]['success'] );
        $this->assertEquals( 5, $core[0]['site_id'] );
    }

    /**
     * Test failed core update.
     */
    public function test_save_update_info_core_failure() {

        $website = (object) array(
            'id' => 99,
        );

        MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'upgrade' => 'FAILED',
            ),
            'core'
        );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );

        $this->assertCount( 1, $core );
        $this->assertEquals( 0, $core[0]['success'] );
    }

    /**
     * Test saving theme update.
     */
    public function test_save_update_info_theme() {

        $website = (object) array(
            'id' => 88,
        );

        MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'updated_data' => array(
                    array(
                        'name'    => 'Twenty Twenty-Five',
                        'success' => 1,
                    ),
                ),
            ),
            'theme'
        );

        $themes = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_THEME
        );

        $this->assertCount( 1, $themes );
    }

    /**
     * Test saving translation update.
     */
    public function test_save_update_info_translation() {

        $website = (object) array(
            'id' => 101,
        );

        MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'updated_data' => array(
                    array(
                        'name'    => 'WordPress',
                        'success' => 1,
                    ),
                ),
            ),
            'trans'
        );

        $translations = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_TRANSLATION
        );

        $this->assertCount( 1, $translations );
    }

    /**
     * Test grouped data returns empty when storage is empty.
     */
    public function test_get_auto_update_notice_data_grouped_by_site_empty() {

        $this->assertSame(
            array(),
            MainWP_Updates_Report_Manager::get_auto_update_notice_data_grouped_by_site()
        );
    }

    /**
     * Test failed updates are ignored.
     */
    public function test_get_auto_update_notice_data_grouped_by_site_failed_updates() {

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            array(
                'name'    => 'Plugin',
                'site_id' => 1,
                'success' => 0,
            )
        );

        $this->assertSame(
            array(),
            MainWP_Updates_Report_Manager::get_auto_update_notice_data_grouped_by_site()
        );
    }

    /**
     * Test entries without site_id are ignored.
     */
    public function test_get_auto_update_notice_data_grouped_by_site_missing_site_id() {

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            array(
                'name'    => 'Plugin',
                'success' => 1,
            )
        );

        $this->assertSame(
            array(),
            MainWP_Updates_Report_Manager::get_auto_update_notice_data_grouped_by_site()
        );
    }

    /**
     * Test deleted or inaccessible sites are skipped.
     */
    public function test_get_auto_update_notice_data_grouped_by_site_unknown_site() {

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            array(
                'name'    => 'Plugin',
                'site_id' => 999999,
                'success' => 1,
            )
        );

        $this->assertSame(
            array(),
            MainWP_Updates_Report_Manager::get_auto_update_notice_data_grouped_by_site()
        );
    }
}
