<?php
/**
 * Tests that Insights logs are created after updates.
 *
 * Checks plugin, theme, translation, and WordPress core updates to ensure
 * logs and corresponding metadata are accurately saved in the database.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_UnitTestCase;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Updates_Handler;
use MainWP\Dashboard\Module\Log\Log_DB;
use MainWP\Dashboard\Module\Log\Log_DB_Driver_WPDB;

/**
 * Test update Insights logs.
 */
class Test_After_Updates_Create_Insights_Logs extends WP_UnitTestCase {

    /**
     * Test site ID.
     *
     * @var int
     */
    private $site_id;

    /**
     * Test website object.
     *
     * @var object
     */
    private $website;

    /**
     * Logs database instance used by the tests.
     *
     * @var Log_DB
     */
    private $db;

    /**
     * Set up the test environment.
     *
     * Initializes the Logs database component used by the test cases.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->site_id = $this->create_test_site(
            array(
                'name' => 'Test Site',
                'url'  => 'https://test-perform-updates.example.com/',
            )
        );

        $this->website = MainWP_DB::instance()->get_website_by_id( $this->site_id );

        $driver     = new Log_DB_Driver_WPDB();
        $this->db  = new Log_DB( $driver );
    }



    /**
     * Callback to force-enable the logs module.
     *
     * @param array $modules Associative array of enabled modules.
     * @return array Modified array with logs module enabled.
     */
    public function filter_enable_logs_module( $modules ) {
        if ( ! is_array( $modules ) ) {
            $modules = array();
        }
        $modules['logs'] = true;
        return $modules;
    }

    /**
     * Test plugin update creates Insights logs.
     *
     * @return void
     */
    public function test_plugins_updates_create_insights_logs() {

        // Skip if Logs module is not available.
        if ( ! class_exists( 'MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
            $this->markTestSkipped( 'Logs module is not available.' );
        }

        $output_array = array(
            'updated_data' => array(
                'akismet/akismet.php' => array(
                    'name'        => 'Akismet Anti-spam',
                    'old_version' => '5.3',
                    'slug'        => 'akismet/akismet.php',
                    'success'     => 1,
                    'version'     => '5.4',
                ),
                'hello-dolly/hello.php' => array(
                    'name'        => 'Hello Dolly',
                    'old_version' => '1.7.2',
                    'slug'        => 'hello-dolly/hello.php',
                    'success'     => 0,
                    'error'       => 'Update failed.',
                ),
                'woocommerce/woocommerce.php' => array(
                    'name'        => 'WooCommerce',
                    'old_version' => '9.8.1',
                    'slug'        => 'woocommerce/woocommerce.php',
                    'success'     => 1,
                    'rollback'    => 1,
                    'version'     => '9.9.0',
                ),
            ),
        );

        mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions(
            $this->website,
            'updated',
            $output_array,
            'plugin',
            array(),
            false,
            true
        );

        // Successful plugin.
        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'slug' => 'akismet/akismet.php',
                'type' => 'plugin',
            )
        );

        $this->assertNotNull( $log );
        $this->assertSame( 'plugin', $log->context );
        $this->assertSame( 1, (int) $log->state );

        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $this->assertSame( 'Akismet Anti-spam', $log->meta['name'] );
        $this->assertSame( '5.4', $log->meta['version'] );

        // Json-decode extra_info and assert properties including slug.
        $this->assertArrayHasKey( 'extra_info', $log->meta );
        $extra_info = json_decode( $log->meta['extra_info'], true );
        $this->assertIsArray( $extra_info );
        $this->assertSame( 'akismet/akismet.php', $extra_info['slug'] ?? null );
        $this->assertSame( 'Akismet Anti-spam', $extra_info['name'] ?? null );
        $this->assertSame( '5.3', $extra_info['old_version'] ?? null );
        $this->assertSame( '5.4', $extra_info['version'] ?? null );
        $this->assertSame( '1', (string) ( $extra_info['success'] ?? '' ) );

        // Failed plugin.
        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'slug' => 'hello-dolly/hello.php',
                'type' => 'plugin',
            )
        );

        $this->assertNotNull( $log );
        $this->assertSame( 'plugin', $log->context );
        $this->assertSame( 0, (int) $log->state );

        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $extra_info = json_decode( $log->meta['extra_info'], true );
        $this->assertIsArray( $extra_info );
        $this->assertSame( 'hello-dolly/hello.php', $extra_info['slug'] ?? null );
        $this->assertSame( '0', (string) ( $extra_info['success'] ?? '' ) );
        $this->assertSame( 'Update failed.', $extra_info['error'] ?? null );

        // Rollback plugin.
        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'slug' => 'woocommerce/woocommerce.php',
                'type' => 'plugin',
            )
        );

        $this->assertNotNull( $log );
        $this->assertSame( 1, (int) $log->state );
        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $extra_info = json_decode( $log->meta['extra_info'], true );
        $this->assertIsArray( $extra_info );
        $this->assertSame( 'woocommerce/woocommerce.php', $extra_info['slug'] ?? null );
        $this->assertSame( '1', (string) ( $extra_info['success'] ?? '' ) );
        $this->assertSame( '1', (string) ( $extra_info['rollback'] ?? '' ) );
        $this->assertSame( '9.9.0', $extra_info['version'] ?? null );
    }

    /**
     * Test theme update creates Insights logs.
     *
     * @return void
     */
    public function test_updates_themes_create_insights_logs() {


        $output_array = array(
            'updated_data' => array(
                'twentytwentyfour' => array(
                    'name'        => 'Twenty Twenty-Four',
                    'old_version' => '1.3',
                    'slug'        => 'twentytwentyfour',
                    'success'     => 1,
                    'version'     => '1.4',
                ),
                'astra' => array(
                    'name'        => 'Astra',
                    'old_version' => '4.8.0',
                    'slug'        => 'astra',
                    'success'     => 0,
                    'error'       => 'Theme update failed.',
                ),
                'generatepress' => array(
                    'name'        => 'GeneratePress',
                    'old_version' => '3.5.1',
                    'slug'        => 'generatepress',
                    'success'     => 1,
                    'rollback'    => 1,
                    'version'     => '3.5.2',
                ),
            ),
        );

        mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions(
            $this->website,
            'updated',
            $output_array,
            'theme',
            array(),
            false,
            true
        );

        // Successful theme.
        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'slug' => 'twentytwentyfour',
                'type' => 'theme',
            )
        );

        $this->assertNotNull( $log );
        $this->assertSame( 'theme', $log->context );
        $this->assertSame( 1, (int) $log->state );

        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $this->assertSame( 'Twenty Twenty-Four', $log->meta['name'] );
        $this->assertSame( '1.4', $log->meta['version'] );

        $extra_info = json_decode( $log->meta['extra_info'], true );
        $this->assertIsArray( $extra_info );
        $this->assertSame( 'twentytwentyfour', $extra_info['slug'] ?? null );
        $this->assertSame( '1', (string) ( $extra_info['success'] ?? '' ) );
        $this->assertSame( '1.3', $extra_info['old_version'] ?? null );

        // Failed theme.
        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'slug' => 'astra',
                'type' => 'theme',
            )
        );

        $this->assertNotNull( $log );
        $this->assertSame( 0, (int) $log->state );
        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $extra_info = json_decode( $log->meta['extra_info'], true );
        $this->assertIsArray( $extra_info );
        $this->assertSame( 'astra', $extra_info['slug'] ?? null );
        $this->assertSame( '0', (string) ( $extra_info['success'] ?? '' ) );
        $this->assertSame( 'Theme update failed.', $extra_info['error'] ?? null );

        // Rollback theme.
        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'slug' => 'generatepress',
                'type' => 'theme',
            )
        );

        $this->assertNotNull( $log );
        $this->assertSame( 1, (int) $log->state );
        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $extra_info = json_decode( $log->meta['extra_info'], true );
        $this->assertIsArray( $extra_info );
        $this->assertSame( 'generatepress', $extra_info['slug'] ?? null );
        $this->assertSame( '1', (string) ( $extra_info['success'] ?? '' ) );
        $this->assertSame( '1', (string) ( $extra_info['rollback'] ?? '' ) );
        $this->assertSame( '3.5.2', $extra_info['version'] ?? null );
    }

    /**
     * Test translation update creates Insights logs.
     *
     * @return void
     */
    public function test_updates_trans_create_insights_logs() {

        $output_array = array(
            'updated_data' => array(
                'en_US' => array(
                    'name'    => 'English (United States)',
                    'slug'    => 'en_US',
                    'type'    => 'core',
                    'version' => '6.8',
                    'success' => 1,
                ),
                'fr_FR' => array(
                    'name'    => 'French (France)',
                    'slug'    => 'fr_FR',
                    'type'    => 'core',
                    'version' => '6.8',
                    'success' => 0,
                    'error'   => 'Translation update failed.',
                ),
            ),
        );

        mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions(
            $this->website,
            'updated',
            $output_array,
            'trans'
        );

        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'slug' => 'fr_FR',
                'type' => 'trans',
            )
        );

        $this->assertNotNull( $log );

        $this->assertSame( 'translation', $log->context );
        $this->assertSame( 0, (int) $log->state );

        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $this->assertSame( 'French (France)', $log->meta['name'] );
        $this->assertSame( '6.8', $log->meta['version'] );

        $extra_info = json_decode( $log->meta['extra_info'], true );
        $this->assertIsArray( $extra_info );
        $this->assertSame( 'fr_FR', $extra_info['slug'] ?? null );
        $this->assertSame( '0', (string) ( $extra_info['success'] ?? '' ) );
        $this->assertSame( 'Translation update failed.', $extra_info['error'] ?? null );
    }

    /**
     * Test core update creates Insights logs.
     *
     * @return void
     */
    public function test_updates_core_create_insights_logs() {

        $information = array(
            'upgrade'     => 'SUCCESS',
            'old_version' => '6.8.2',
            'version'     => '6.8.3',
            'other_data'  => array(),
        );

        MainWP_Updates_Handler::activity_log_upgrade(
            $this->website,
            $information
        );

        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'type' => 'core',
            )
        );

        $this->assertNotNull( $log );

        $this->assertSame( 'core', $log->context );
        $this->assertSame( 1, (int) $log->state );

        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $this->assertSame( 'WordPress', $log->meta['name'] );
        $this->assertSame( '6.8.3', $log->meta['version'] );
        $this->assertSame( '6.8.2', $log->meta['old_version'] );
    }

    /**
     * Test core update error creates Insights logs.
     *
     * @return void
     */
    public function test_updates_core_error_create_insights_logs() {

        $information = array(
            'upgrade'    => 'WPERROR',
            'other_data' => array(
                'upgrade_error_codes' => 'download_failed,filesystem_error',
            ),
        );

        MainWP_Updates_Handler::activity_log_upgrade(
            $this->website,
            $information
        );

        $log = $this->get_last_changes_log_by(
            array(
                'wpid' => $this->site_id,
                'type' => 'core',
            )
        );

        $this->assertNotNull( $log );

        $this->assertSame( 'core', $log->context ?? null );
        $this->assertSame( 0, (int) $log->state );

        $this->assertObjectHasProperty( 'meta', $log );
        $this->assertIsArray( $log->meta );

        $this->assertSame( 'WordPress', $log->meta['name'] ?? null );
    }


    /**
     * Test that failed plugin updates are logged.
     *
     * @return void
     */
    public function test_failed_plugin_update_is_logged() {
        $output_array = array(
            'updated_data' => array(
                'hello-dolly/hello.php' => array(
                    'name'        => 'Hello Dolly',
                    'old_version' => '1.7.2',
                    'slug'        => 'hello-dolly/hello.php',
                    'success'     => 0,
                    'error'       => 'Update failed.',
                ),
            ),
        );

        mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions(
            $this->website,
            'updated',
            $output_array,
            'plugin',
            array(),
            false,
            true
        );

        $logs = $this->get_logs_for_test();

        $this->assertTrue(
            $this->has_failed_update_log(
                $logs,
                'hello-dolly/hello.php'
            ),
            'The failed plugin update should be present in Insights logs.'
        );
    }

    /**
     * Test that failed theme updates are logged.
     *
     * @return void
     */
    public function test_failed_theme_update_is_logged() {
        $output_array = array(
            'updated_data' => array(
                'twentytwentyfour' => array(
                    'name'        => 'Twenty Twenty-Four',
                    'old_version' => '1.2',
                    'slug'        => 'twentytwentyfour',
                    'success'     => 0,
                    'error'       => 'Update failed.',
                ),
            ),
        );

        mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions(
            $this->website,
            'updated',
            $output_array,
            'theme',
            array(),
            false,
            true
        );

        $logs = $this->get_logs_for_test();

        $this->assertTrue(
            $this->has_failed_update_log(
                $logs,
                'twentytwentyfour'
            ),
            'The failed theme update should be present in Insights logs.'
        );
    }

    /**
     * Test that failed core updates are logged.
     *
     * @return void
     */
    public function test_failed_core_update_is_logged() {

        $information = array(
            'upgrade'    => 'WPERROR',
            'other_data' => array(
                'upgrade_error_codes' => 'download_failed,filesystem_error',
            ),
        );

        MainWP_Updates_Handler::activity_log_upgrade( $this->website, $information ); // see do_action_mainwp_install_actions for more details about the upgrade: plugins, themes, translation.

        $logs = $this->get_logs_for_test();

        $this->assertTrue(
            $this->has_failed_update_log(
                $logs,
                'wordpress' // phpcs:ignore - slug.
            ),
            'The failed WordPress core update should be present in Insights logs.'
        );
    }

    /**
     * Test that failed translation updates are logged.
     *
     * @return void
     */
    public function test_failed_translation_update_is_logged() {
        $output_array = array(
            'updated_data' => array(
                'en_US' => array(
                    'name'        => 'English (US)',
                    'old_version' => '',
                    'slug'        => 'en_US',
                    'success'     => 0,
                    'error'       => 'Update failed.',
                ),
            ),
        );

        mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions(
            $this->website,
            'updated',
            $output_array,
            'trans',
            array(),
            false,
            true
        );

        $logs = $this->get_logs_for_test();

        $this->assertTrue(
            $this->has_failed_update_log(
                $logs,
                'en_US'
            ),
            'The failed translation update should be present in Insights logs.'
        );
    }

    /**
     * Get the latest update log item matching criteria.
     *
     * @param array $args {
     *     Optional. Query arguments for filtering log results.
     *
     *     @type int    $wpid Site ID.
     *     @type string $slug Component slug.
     *     @type string $type Log type ('plugin', 'theme', 'trans', 'translation', 'core').
     * }
     * @return object|null Latest log object or null if none found.
     */
    protected function get_last_changes_log_by( $args ) {

        $site_id = isset( $args['wpid'] ) ? intval( $args['wpid'] ) : 0;
        $slug    = isset( $args['slug'] ) ? sanitize_text_field( $args['slug'] ) : '';
        $type    = isset( $args['type'] ) ? sanitize_text_field( $args['type'] ) : '';

        $contexts = array(
            'plugin'      => 'plugin',
            'theme'       => 'theme',
            'trans'       => 'translation',
            'translation' => 'translation',
            'core'        => 'core',
        );

        if ( empty( $site_id ) || ! isset( $contexts[ $type ] ) ) {
            return null;
        }

        $context = $contexts[ $type ];

        global $wpdb;

        $query = $wpdb->prepare(
            'SELECT * FROM ' . $this->table_name( 'wp_logs' ) . '
            WHERE site_id = %d
            AND context = %s
            ORDER BY created DESC
            LIMIT 100',
            $site_id,
            $context
        );

        $items = $wpdb->get_results( $query );

        $item = $this->get_last_item_with_meta( $items, $type, $slug );

        return $item;
    }

    /**
     * Get the latest update log item with meta.
     *
     * @param array  $items   List of log items to inspect.
     * @param string $type    Log type (e.g. 'plugin', 'theme', 'trans', 'core').
     * @param string $slug    Log slug.
     *
     * @return object|null Latest log object or null if none found.
     */
    protected function get_last_item_with_meta( $items, $type, $slug ) {

        if( ! is_array( $items ) ){
            return null;
        }

        foreach ( $items as $item ) {
            $this->get_item_meta( $item );

            if ( 'core' === $type ) {
                return $item;
            }

            if ( ! empty( $slug ) && ! empty( $item->meta ) && is_array( $item->meta ) ) {
                $item_slug = '';

                if ( ! empty( $item->meta['slug'] ) ) {
                    $item_slug = $item->meta['slug'];
                } elseif ( ! empty( $item->meta['extra_info'] ) ) {
                    $decoded_extra = json_decode( $item->meta['extra_info'], true );
                    if ( is_array( $decoded_extra ) && ! empty( $decoded_extra['slug'] ) ) {
                        $item_slug = $decoded_extra['slug'];
                    }
                }

                if ( ! empty( $item_slug ) && $item_slug === $slug ) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Get metadata for a single log item.
     *
     * @param object $item Log item passed by reference.
     */
    public function get_item_meta( &$item ) {
        global $wpdb;

        if ( ! is_object( $item ) || empty( $item->log_id ) ) {
            return;
        }

        $log_id = absint( $item->log_id );

        $sql_meta = $wpdb->prepare(
            'SELECT meta_key, meta_value FROM ' . $this->table_name( 'wp_logs_meta' ) . ' WHERE meta_log_id = %d',
            $log_id
        );

        $meta_records = $wpdb->get_results( $sql_meta );

        if ( ! is_array( $meta_records ) ) {
            return;
        }

        if ( empty( $item->meta ) ) {
            $item->meta = array();
        }

        foreach ( $meta_records as $meta_record ) {
            if ( empty( $meta_record->meta_value ) ) {
                continue;
            }

            $item->meta[ $meta_record->meta_key ] = $meta_record->meta_value;
        }
    }

    /**
     * Get Insights logs for testing.
     *
     * @param array $overrides Arguments to override the default query arguments.
     *
     * @return array
     */
    private function get_logs_for_test( $overrides = array() ) {
        $args = array(
            'order'              => 'desc',
            'orderby'            => 'time',
            'start'              => 0,
            'recent_number'      => 0,
            'search'             => '',
            'groups_ids'         => array(),
            'client_ids'         => array(),
            'usersfilter_sites_ids' => array(),
            'timestart'          => '',
            'timestop'           => '',
            'dismiss'            => 0,
            'view'               => 'events_list',
            'wpid'               => 0,
            'sources_conds'      => array(),
            'sites_ids'          => array(),
            'events'             => array(),
            'records_per_page'   => 100,
            'dev_log_query'      => 0,
            'with_all_logs_meta' => 1,
        );

        $args = array_merge( $args, $overrides );

        return $this->db->get_records( $args );
    }

    /**
     * Check whether a failed update exists in the Logs records.
     *
     * @param array  $logs       Logs records.
     * @param string $update_slug Update slug.
     *
     * @return bool
     */
    private function has_failed_update_log( $logs, $update_slug ) {
        foreach ( $logs as $log ) {
            if ( $this->site_id !== (int) $log->site_id ) {
                continue;
            }

            if( isset( $log->meta['slug'] ) && $update_slug === $log->meta['slug'] && 0 === (int) $log->state ){
                return true;
            }

            $extra_info = ! empty( $log->extra_info ) ? json_decode( $log->extra_info, true ) : array();

            if ( ! is_array( $extra_info ) ) {
                continue;
            }

            if (
                isset( $extra_info['slug'] ) &&
                $update_slug === $extra_info['slug'] &&
                0 === (int) $log->state
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper method to wrap table names with WPDB prefix.
     *
     * @param string $table Table name without prefix.
     * @return string Full table name with prefix.
     */
    protected function table_name( $table ) {
        global $wpdb;
        return $wpdb->prefix . 'mainwp_' . $table;
    }

    /**
     * Create a MainWP test site in the database.
     *
     * @param array $args {
     *     Optional. Custom site properties.
     *
     *     @type int    $userid         User ID associated with site. Default 1.
     *     @type string $url            Site URL.
     *     @type string $name           Site name. Default 'Test Site'.
     *     @type string $adminname      Admin username. Default 'admin'.
     *     @type string $pubkey         Public key.
     *     @type string $privkey        Private key.
     *     @type int    $ssl_version    SSL version flag. Default 0.
     *     @type string $http_user      HTTP authentication user.
     *     @type string $http_pass      HTTP authentication password.
     *     @type int    $suspended      Suspended status flag. Default 0.
     *     @type int    $verify_method Verification method option. Default 1.
     *     @type string $version        Plugin version. Default '5.0.0'.
     *     @type string $sync_errors    Sync errors message. Default empty.
     * }
     * @return int Inserted site ID.
     */
    protected function create_test_site( $args = array() ) {
        global $wpdb;

        $verify_method = $args['verify_method'] ?? 1;
        $version       = $args['version'] ?? '5.0.0';
        $sync_errors   = $args['sync_errors'] ?? '';

        unset( $args['verify_method'], $args['version'], $args['sync_errors'] );

        $defaults = array(
            'userid'               => max( 1, get_current_user_id() ),
            'url'                  => 'https://test-site-' . wp_generate_uuid4() . '.example.com/',
            'name'                 => 'Test Site',
            'adminname'            => 'admin',
            'pubkey'               => 'test-pubkey',
            'privkey'              => 'test-privkey',
            'ssl_version'          => 0,
            'http_user'            => '',
            'http_pass'            => '',
            'suspended'            => 0,
            'offline_check_result' => 1,
            'client_id'            => 0,
        );

        $formats = array(
            'userid'               => '%d',
            'url'                  => '%s',
            'name'                 => '%s',
            'adminname'            => '%s',
            'pubkey'               => '%s',
            'privkey'              => '%s',
            'ssl_version'          => '%d',
            'http_user'            => '%s',
            'http_pass'            => '%s',
            'suspended'            => '%d',
            'offline_check_result' => '%d',
            'client_id'            => '%d',
        );

        $data = array_merge( $defaults, $args );

        $format_array = array();

        foreach ( array_keys( $data ) as $key ) {
            $format_array[] = $formats[ $key ] ?? '%s';
        }

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp',
            $data,
            $format_array
        );

        $site_id = (int) $wpdb->insert_id;

        $this->set_site_option( $site_id, 'verify_method', $verify_method );

        $this->create_test_site_sync(
            $site_id,
            array(
                'version'     => $version,
                'sync_errors' => $sync_errors,
            )
        );

        return $site_id;
    }

    /**
     * Set a MainWP site option in the database.
     *
     * @param int    $site_id Site ID.
     * @param string $option  Option name.
     * @param mixed  $value   Option value.
     * @return void
     */
    protected function set_site_option( $site_id, $option, $value ) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp_options',
            array(
                'wpid'  => $site_id,
                'name'  => $option,
                'value' => is_scalar( $value ) ? $value : maybe_serialize( $value ),
            ),
            array( '%d', '%s', '%s' )
        );
    }

    /**
     * Create a MainWP site sync record in the database.
     *
     * @param int   $site_id Site ID.
     * @param array $args {
     *     Optional. Sync parameters.
     *
     *     @type string $version     MainWP Child version. Default '5.0.0'.
     *     @type string $sync_errors Error messages during sync, if any.
     * }
     * @return void
     */
    protected function create_test_site_sync( $site_id, $args = array() ) {
        global $wpdb;

        $data = array_merge(
            array(
                'wpid'        => $site_id,
                'version'     => '5.0.0',
                'sync_errors' => '',
            ),
            $args
        );

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp_sync',
            $data,
            array( '%d', '%s', '%s' )
        );
    }
}