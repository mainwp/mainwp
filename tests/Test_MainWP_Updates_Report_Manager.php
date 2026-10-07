<?php
/**
 * Test MainWP_Updates_Report_Manager.
 *
 * @package MainWP\Tests
 */

use MainWP\Dashboard\MainWP_Notification;
use MainWP\Dashboard\MainWP_Notification_Settings;
use MainWP\Dashboard\MainWP_Updates_Handler;
use MainWP\Dashboard\MainWP_Updates_Report_Manager;

/**
 * Class Test_MainWP_Updates_Report_Manager
 */
class Test_MainWP_Updates_Report_Manager extends WP_UnitTestCase {

    /**
     * Mail captured from the wp_mail filter.
     *
     * @var array
     */
    protected $captured_mail = array();

    /**
     * Child site rows created by these tests.
     *
     * @var int[]
     */
    protected $created_site_ids = array();

    /**
     * Clean option before each test.
     */
    public function setUp(): void {
        parent::setUp();

        delete_option( MainWP_Updates_Report_Manager::OPTION_NAME );
        delete_option( 'mainwp_automatic_updates_ready_to_send_notification' );
        delete_option( 'mainwp_settings_notification_emails' );
        $this->captured_mail    = array();
        $this->created_site_ids = array();
        add_filter( 'wp_mail', array( $this, 'capture_wp_mail' ) );
    }

    /**
     * Clean option after each test.
     */
    public function tearDown(): void {
        remove_filter( 'wp_mail', array( $this, 'capture_wp_mail' ) );
        $this->delete_created_sites();
        delete_option( MainWP_Updates_Report_Manager::OPTION_NAME );
        delete_option( 'mainwp_automatic_updates_ready_to_send_notification' );
        delete_option( 'mainwp_settings_notification_emails' );

        parent::tearDown();
    }

    /**
     * Capture wp_mail arguments.
     *
     * @param array $atts Mail arguments.
     *
     * @return array
     */
    public function capture_wp_mail( $atts ) {
        $this->captured_mail[] = $atts;
        return $atts;
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

    /**
     * Accessible sites are included in the summary payload.
     */
    public function test_get_auto_update_notice_data_grouped_by_site_includes_site() {
        $site_id = $this->create_notice_site( 'Grouped Site', 'https://grouped.example/' );

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_PLUGIN,
            array(
                'name'    => 'Hello',
                'site_id' => $site_id,
                'success' => 1,
            )
        );

        $grouped = MainWP_Updates_Report_Manager::get_auto_update_notice_data_grouped_by_site();

        $this->assertArrayHasKey( $site_id, $grouped );
        $this->assertSame( 'Grouped Site', $grouped[ $site_id ]['site_name'] );
        $this->assertSame( 'https://grouped.example/', $grouped[ $site_id ]['site_url'] );
    }

    /**
     * Disabled Auto Updates Email does not send, and Daily Digest settings stay put.
     */
    public function test_send_auto_update_info_disabled_does_not_send() {
        $site_id = $this->create_notice_site( 'Disabled Site', 'https://disabled.example/' );
        $this->save_successful_plugin_update( $site_id, 'Disabled Plugin' );
        $this->save_email_settings(
            array(
                'disable'    => 1,
                'recipients' => 'auto@example.com',
                'subject'    => 'Should not send',
                'heading'    => 'Should not send',
            ),
            array(
                'disable'    => 0,
                'recipients' => 'digest@example.com',
                'subject'    => 'Digest subject',
                'heading'    => 'Digest heading',
            )
        );

        MainWP_Updates_Report_Manager::send_auto_update_info();

        $this->assertSame( array(), $this->captured_mail );
        $this->assertSame( 'digest@example.com', MainWP_Notification_Settings::get_general_email() );
    }

    /**
     * Enabled Auto Updates Email uses its saved recipients, subject, and heading.
     */
    public function test_send_auto_update_info_uses_saved_settings() {
        $site_id = $this->create_notice_site( 'Saved Site', 'https://saved.example/' );
        $this->save_successful_plugin_update( $site_id, 'Saved Plugin' );
        $this->save_email_settings(
            array(
                'disable'    => 0,
                'recipients' => 'auto-updates@example.com',
                'subject'    => 'Custom Auto Subject',
                'heading'    => 'Custom Auto Heading',
            ),
            array(
                'disable'    => 0,
                'recipients' => 'digest@example.com',
                'subject'    => 'Digest subject',
                'heading'    => 'Digest heading',
            )
        );

        MainWP_Updates_Report_Manager::send_auto_update_info();

        $this->assertCount( 1, $this->captured_mail );
        $this->assertSame( 'auto-updates@example.com', $this->captured_mail[0]['to'] );
        $this->assertSame( 'Custom Auto Subject', $this->captured_mail[0]['subject'] );
        $this->assertStringContainsString( 'Custom Auto Heading', $this->captured_mail[0]['message'] );
        $this->assertStringNotContainsString( 'digest@example.com', $this->captured_mail[0]['to'] );
        $this->assertSame( 'digest@example.com', MainWP_Notification_Settings::get_general_email() );
    }

    /**
     * Empty saved fields fall back to Auto Updates defaults, not Daily Digest recipients.
     */
    public function test_send_auto_update_info_empty_fields_use_defaults() {
        $site_id = $this->create_notice_site( 'Default Site', 'https://default.example/' );
        $this->save_successful_plugin_update( $site_id, 'Default Plugin' );
        $this->save_email_settings(
            array(
                'disable'    => 0,
                'recipients' => '',
                'subject'    => '',
                'heading'    => '',
            ),
            array(
                'disable'    => 0,
                'recipients' => 'digest@example.com',
                'subject'    => 'Digest subject',
                'heading'    => 'Digest heading',
            )
        );

        MainWP_Updates_Report_Manager::send_auto_update_info();

        $this->assertCount( 1, $this->captured_mail );
        $this->assertSame( get_option( 'admin_email' ), $this->captured_mail[0]['to'] );
        $this->assertNotSame( 'digest@example.com', $this->captured_mail[0]['to'] );
        $this->assertSame( 'Auto Updates Notification from Your MainWP Dashboard', $this->captured_mail[0]['subject'] );
        $this->assertStringContainsString( 'Auto Updates Notification', $this->captured_mail[0]['message'] );
        $this->assertStringNotContainsString( 'digest@example.com', $this->captured_mail[0]['message'] );
        $this->assertSame( 'digest@example.com', MainWP_Notification_Settings::get_general_email() );
    }

    /**
     * A custom subject is kept when only the other fields are empty.
     */
    public function test_send_auto_update_info_fills_only_empty_fields() {
        $site_id = $this->create_notice_site( 'Partial Site', 'https://partial.example/' );
        $this->save_successful_plugin_update( $site_id, 'Partial Plugin' );
        $this->save_email_settings(
            array(
                'disable'    => 0,
                'recipients' => '',
                'subject'    => 'Kept Subject',
                'heading'    => '',
            )
        );

        MainWP_Updates_Report_Manager::send_auto_update_info();

        $this->assertCount( 1, $this->captured_mail );
        $this->assertSame( get_option( 'admin_email' ), $this->captured_mail[0]['to'] );
        $this->assertSame( 'Kept Subject', $this->captured_mail[0]['subject'] );
        $this->assertStringContainsString( 'Auto Updates Notification', $this->captured_mail[0]['message'] );
    }

    /**
     * Unsaved Auto Updates settings use that type's defaults, not Daily Digest recipients.
     */
    public function test_send_auto_update_info_without_saved_settings_ignores_daily_digest() {
        $site_id = $this->create_notice_site( 'Unsaved Site', 'https://unsaved.example/' );
        $this->save_successful_plugin_update( $site_id, 'Unsaved Plugin' );
        update_option(
            'mainwp_settings_notification_emails',
            array(
                'daily_digest' => array(
                    'disable'    => 0,
                    'recipients' => 'digest@example.com',
                    'subject'    => 'Digest subject',
                    'heading'    => 'Digest heading',
                ),
            )
        );

        MainWP_Updates_Report_Manager::send_auto_update_info();

        $this->assertCount( 1, $this->captured_mail );
        $this->assertSame( get_option( 'admin_email' ), $this->captured_mail[0]['to'] );
        $this->assertSame( 'Auto Updates Notification from Your MainWP Dashboard', $this->captured_mail[0]['subject'] );
        $this->assertNotSame( 'digest@example.com', $this->captured_mail[0]['to'] );
        $this->assertSame( 'digest@example.com', MainWP_Notification_Settings::get_general_email() );
    }

    /**
     * Enabled settings with nothing to report do not send.
     */
    public function test_send_auto_update_info_enabled_without_items_does_not_send() {
        $this->save_email_settings(
            array(
                'disable'    => 0,
                'recipients' => 'auto-updates@example.com',
                'subject'    => 'Custom Auto Subject',
                'heading'    => 'Custom Auto Heading',
            )
        );

        MainWP_Updates_Report_Manager::send_auto_update_info();

        $this->assertSame( array(), $this->captured_mail );
    }

    /**
     * The send helper itself refuses a disabled notification.
     */
    public function test_start_notification_auto_updates_info_respects_disable() {
        MainWP_Notification::start_notification_auto_updates_info(
            array(
                'disable'    => 1,
                'recipients' => 'auto-updates@example.com',
                'subject'    => 'Should not send',
                'heading'    => 'Should not send',
            ),
            array(
                'plain_text'   => false,
                'mail_content' => '<p>summary</p>',
            )
        );

        $this->assertSame( array(), $this->captured_mail );

        MainWP_Notification::start_notification_auto_updates_info(
            array(
                'disable'    => 0,
                'recipients' => 'auto-updates@example.com',
                'subject'    => 'Enabled subject',
                'heading'    => 'Enabled heading',
            ),
            array(
                'plain_text'   => false,
                'mail_content' => '<p>summary body</p>',
            )
        );

        $this->assertCount( 1, $this->captured_mail );
        $this->assertSame( 'auto-updates@example.com', $this->captured_mail[0]['to'] );
        $this->assertSame( 'Enabled subject', $this->captured_mail[0]['subject'] );
        $this->assertStringContainsString( 'summary body', $this->captured_mail[0]['message'] );
    }

    /**
     * Caller-shaped core data keeps a successful result when no upgrade key is present.
     */
    public function test_save_update_info_core_caller_shape_success() {
        $website = (object) array(
            'id' => 15,
        );

        $result = MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'old_version' => '6.4.2',
                'version'     => '6.8.1',
                'success'     => 1,
                'error'       => '',
            ),
            'core'
        );

        $this->assertTrue( $result );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );

        $this->assertCount( 1, $core );
        $this->assertSame( 1, $core[0]['success'] );
        $this->assertSame( '6.4.2', $core[0]['old_version'] );
        $this->assertSame( '6.8.1', $core[0]['version'] );
        $this->assertSame( 15, $core[0]['site_id'] );
        $this->assertArrayNotHasKey( 'upgrade', $core[0] );
    }

    /**
     * Caller-shaped core data keeps a failed result.
     */
    public function test_save_update_info_core_caller_shape_failure() {
        $website = (object) array(
            'id' => 16,
        );

        $result = MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'old_version' => '6.4.2',
                'version'     => '6.8.1',
                'success'     => 0,
                'error'       => 'Update failed.',
            ),
            'core'
        );

        $this->assertTrue( $result );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );

        $this->assertCount( 1, $core );
        $this->assertSame( 0, $core[0]['success'] );
        $this->assertSame( '6.4.2', $core[0]['old_version'] );
        $this->assertSame( '6.8.1', $core[0]['version'] );
    }

    /**
     * An explicit success flag wins over a conflicting upgrade value.
     */
    public function test_save_update_info_core_success_precedes_failed_upgrade() {
        $website = (object) array(
            'id' => 17,
        );

        MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'old_version' => '6.4.2',
                'version'     => '6.8.1',
                'success'     => 1,
                'upgrade'     => 'FAILED',
                'error'       => '',
            ),
            'core'
        );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );

        $this->assertCount( 1, $core );
        $this->assertSame( 1, $core[0]['success'] );
        $this->assertSame( 'FAILED', $core[0]['upgrade'] );
    }

    /**
     * An explicit failure wins over a successful raw upgrade value.
     */
    public function test_save_update_info_core_failure_precedes_successful_upgrade() {
        $website = (object) array(
            'id' => 19,
        );

        MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'old_version' => '6.4.2',
                'version'     => '6.8.1',
                'success'     => 0,
                'upgrade'     => 'SUCCESS',
                'error'       => 'Update failed.',
            ),
            'core'
        );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );

        $this->assertCount( 1, $core );
        $this->assertSame( 0, $core[0]['success'] );
        $this->assertSame( 'SUCCESS', $core[0]['upgrade'] );
    }

    /**
     * Without a success flag, the raw child upgrade result is still stored.
     */
    public function test_save_update_info_core_missing_success_falls_back_to_upgrade() {
        $website = (object) array(
            'id' => 18,
        );

        MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'upgrade'     => 'SUCCESS',
                'old_version' => '6.3.0',
                'version'     => '6.3.1',
            ),
            'core'
        );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );

        $this->assertCount( 1, $core );
        $this->assertSame( 1, $core[0]['success'] );
        $this->assertSame( 'SUCCESS', $core[0]['upgrade'] );
        $this->assertSame( '6.3.1', $core[0]['version'] );
    }

    /**
     * A successful core auto-update is included in the Auto Updates email.
     */
    public function test_send_auto_update_info_includes_core_caller_shape() {
        $site_id = $this->create_notice_site( 'Core Email Site', 'https://core-email.example/' );
        $website = (object) array(
            'id' => $site_id,
        );

        $saved = MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'old_version' => '6.4.3',
                'version'     => '6.8.4',
                'success'     => 1,
                'error'       => '',
            ),
            'core'
        );
        $this->assertTrue( $saved );

        $this->save_email_settings(
            array(
                'disable'    => 0,
                'recipients' => 'auto-updates@example.com',
                'subject'    => 'Core auto update',
                'heading'    => 'Core auto update heading',
            )
        );

        MainWP_Updates_Report_Manager::send_auto_update_info();

        $this->assertCount( 1, $this->captured_mail );
        $message = $this->captured_mail[0]['message'];
        $this->assertStringContainsString( 'WordPress Core', $message );
        $this->assertStringContainsString( '6.4.3', $message );
        $this->assertStringContainsString( '6.8.4', $message );
    }

    /**
     * A core-only email renders the core table and does not read missing update types.
     *
     * Stored with save() so the template still runs when save_update_info() would
     * record the core item as failed.
     */
    public function test_core_only_auto_update_email_renders_without_warnings() {
        $site_id = $this->create_notice_site( 'Core Only Site', 'https://core-only.example/' );

        MainWP_Updates_Report_Manager::save(
            MainWP_Updates_Report_Manager::TYPE_CORE,
            array(
                'old_version' => '6.5.1',
                'version'     => '6.8.5',
                'success'     => 1,
                'error'       => '',
                'site_id'     => $site_id,
            )
        );

        $this->save_email_settings(
            array(
                'disable'    => 0,
                'recipients' => 'auto-updates@example.com',
                'subject'    => 'Core only',
                'heading'    => 'Core only heading',
            )
        );

        set_error_handler(
            static function ( $errno, $errstr ) {
                if ( 0 === ( error_reporting() & $errno ) ) {
                    return false;
                }
                throw new \ErrorException( $errstr, 0, $errno );
            },
            E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED
        );

        try {
            MainWP_Updates_Report_Manager::send_auto_update_info();
        } finally {
            restore_error_handler();
        }

        $this->assertCount( 1, $this->captured_mail );
        $message = $this->captured_mail[0]['message'];
        $this->assertStringContainsString( 'WordPress Core', $message );
        $this->assertStringContainsString( '6.5.1', $message );
        $this->assertStringContainsString( '6.8.5', $message );
        $this->assertStringNotContainsString( 'Plugin Updates', $message );
        $this->assertStringNotContainsString( 'Theme Updates', $message );
        $this->assertStringNotContainsString( 'Translation Updates', $message );
    }

    /**
     * A raw core result with no version keys still renders the core table.
     */
    public function test_core_email_renders_raw_shape_without_version_keys() {
        $site_id = $this->create_notice_site( 'Raw Core Site', 'https://raw-core.example/' );
        $website = (object) array(
            'id' => $site_id,
        );

        $saved = MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'upgrade' => 'SUCCESS',
            ),
            'core'
        );
        $this->assertTrue( $saved );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );
        $this->assertCount( 1, $core );
        $this->assertSame( 1, $core[0]['success'] );
        $this->assertArrayNotHasKey( 'old_version', $core[0] );
        $this->assertArrayNotHasKey( 'version', $core[0] );

        $this->save_email_settings(
            array(
                'disable'    => 0,
                'recipients' => 'auto-updates@example.com',
                'subject'    => 'Raw core',
                'heading'    => 'Raw core heading',
            )
        );

        set_error_handler(
            static function ( $errno, $errstr ) {
                if ( 0 === ( error_reporting() & $errno ) ) {
                    return false;
                }
                throw new \ErrorException( $errstr, 0, $errno );
            },
            E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED
        );

        try {
            MainWP_Updates_Report_Manager::send_auto_update_info();
        } finally {
            restore_error_handler();
        }

        $this->assertCount( 1, $this->captured_mail );
        $message = $this->captured_mail[0]['message'];
        $this->assertStringContainsString( 'WordPress Core', $message );
        $this->assertStringContainsString( 'Success', $message );
        $this->assertStringNotContainsString( 'Plugin Updates', $message );
        $this->assertStringNotContainsString( 'Theme Updates', $message );
        $this->assertStringNotContainsString( 'Translation Updates', $message );
    }

    /**
     * Core and plugin updates for one site both render in the email.
     */
    public function test_mixed_core_and_plugin_auto_update_email_renders_both_tables() {
        $site_id = $this->create_notice_site( 'Mixed Site', 'https://mixed.example/' );
        $website = (object) array(
            'id' => $site_id,
        );

        MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'old_version' => '6.6.0',
                'version'     => '6.8.6',
                'success'     => 1,
                'error'       => '',
            ),
            'core'
        );
        MainWP_Updates_Report_Manager::save_update_info(
            $website,
            array(
                'updated_data' => array(
                    array(
                        'name'        => 'Hello Dolly',
                        'old_version' => '1.7.2',
                        'version'     => '1.7.3',
                        'success'     => 1,
                    ),
                ),
            ),
            'plugin'
        );

        $this->save_email_settings(
            array(
                'disable'    => 0,
                'recipients' => 'auto-updates@example.com',
                'subject'    => 'Mixed updates',
                'heading'    => 'Mixed updates heading',
            )
        );

        MainWP_Updates_Report_Manager::send_auto_update_info();

        $this->assertCount( 1, $this->captured_mail );
        $message = $this->captured_mail[0]['message'];
        $this->assertStringContainsString( 'WordPress Core', $message );
        $this->assertStringContainsString( '6.6.0', $message );
        $this->assertStringContainsString( '6.8.6', $message );
        $this->assertStringContainsString( 'Plugin Updates', $message );
        $this->assertStringContainsString( 'Hello Dolly', $message );
        $this->assertStringContainsString( '1.7.2', $message );
        $this->assertStringContainsString( '1.7.3', $message );
    }

    /**
     * The production auto-update logger stores one successful core item.
     */
    public function test_activity_log_upgrade_records_successful_core_auto_update() {
        $website = (object) array(
            'id' => 42,
        );

        MainWP_Updates_Handler::activity_log_upgrade(
            $website,
            array(
                'upgrade'     => 'SUCCESS',
                'old_version' => '6.7.1',
                'version'     => '6.8.2',
            ),
            true
        );

        $core = MainWP_Updates_Report_Manager::get(
            MainWP_Updates_Report_Manager::TYPE_CORE
        );

        $this->assertCount( 1, $core );
        $this->assertSame( 1, $core[0]['success'] );
        $this->assertSame( '6.7.1', $core[0]['old_version'] );
        $this->assertSame( '6.8.2', $core[0]['version'] );
        $this->assertSame( 42, $core[0]['site_id'] );
    }

    /**
     * Store general email settings for the two notification types under test.
     *
     * @param array      $auto_updates Auto Updates Email settings.
     * @param array|null $daily_digest Optional Daily Digest settings.
     */
    protected function save_email_settings( $auto_updates, $daily_digest = null ) {
        $settings = array(
            'auto_updates' => $auto_updates,
        );
        if ( null !== $daily_digest ) {
            $settings['daily_digest'] = $daily_digest;
        }
        update_option( 'mainwp_settings_notification_emails', $settings );
    }

    /**
     * Record one successful plugin update for a site.
     *
     * @param int    $site_id Site ID.
     * @param string $name    Plugin name.
     */
    protected function save_successful_plugin_update( $site_id, $name ) {
        $item = array(
            'name'        => $name,
            'site_id'     => $site_id,
            'success'     => 1,
            'old_version' => '1.0.0',
            'version'     => '1.1.0',
            'type'        => 'plugin',
        );

        MainWP_Updates_Report_Manager::save( MainWP_Updates_Report_Manager::TYPE_PLUGIN, $item );
        MainWP_Updates_Report_Manager::save( MainWP_Updates_Report_Manager::TYPE_THEME, $item );
        MainWP_Updates_Report_Manager::save( MainWP_Updates_Report_Manager::TYPE_TRANSLATION, $item );
    }

    /**
     * Insert a site row and its sync row so the summary query can see it.
     *
     * @param string $name Site name.
     * @param string $url  Site URL.
     *
     * @return int
     */
    protected function create_notice_site( $name, $url ) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp',
            array(
                'userid'               => 1,
                'url'                  => $url,
                'siteurl'              => $url,
                'name'                 => $name,
                'adminname'            => 'admin',
                'pubkey'               => 'test-pubkey',
                'privkey'              => 'test-privkey',
                'ga_id'                => '',
                'gas_id'               => 0,
                'offline_checks_last'  => 0,
                'offline_check_result' => 1,
                'note'                 => '',
                'statsUpdate'          => 0,
                'directories'          => '',
                'plugin_upgrades'      => '',
                'theme_upgrades'       => '',
                'translation_upgrades' => '',
                'premium_upgrades'     => '',
                'securityIssues'       => '',
                'themes'               => '',
                'ignored_themes'       => '',
                'plugins'              => '',
                'ignored_plugins'      => '',
                'users'                => '',
                'categories'           => '',
                'pluginDir'            => '',
                'automatic_update'     => 0,
                'mainwpdir'            => 0,
                'uniqueId'             => '',
                'wpe'                  => 0,
                'ssl_version'          => 0,
                'http_user'            => '',
                'http_pass'            => '',
                'suspended'            => 0,
                'client_id'            => 0,
                'is_staging'           => 0,
            )
        );

        $site_id                  = (int) $wpdb->insert_id;
        $this->created_site_ids[] = $site_id;

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp_sync',
            array(
                'wpid'        => $site_id,
                'version'     => '6.8',
                'sync_errors' => '',
                'dtsSync'     => time(),
            )
        );

        return $site_id;
    }

    /**
     * Remove sites created during the test.
     */
    protected function delete_created_sites() {
        global $wpdb;

        foreach ( $this->created_site_ids as $site_id ) {
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ), array( '%d' ) );
        }

        $this->created_site_ids = array();
    }
}
