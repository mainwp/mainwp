<?php
/**
 * Legacy backup rights and provider settings must preserve refused state.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- An Error captures exits outside legacy Exception handlers.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Fixtures exercise production nonce checks.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Response bodies are captured for assertions without browser output.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Tokenized source copies capture legacy exits.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Snapshots detect refused writes across complete stored state.

use MainWP\Dashboard\Legacy_Rights_Post_Handler;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Settings;

/** Captured termination must escape handlers that catch Exception. */
class Legacy_Backup_Response_Stop extends \Error {}

/** Test the registered AJAX routes and the shared provider renderer. */
class Test_Dashboard_Legacy_Backup_Rights extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Test-owned filters.
     *
     * @var array
     */
    private $filters = array();

    /**
     * Hooks replaced for request isolation.
     *
     * @var array
     */
    private $hooks = array();

    /**
     * Original request globals, user and screen.
     *
     * @var array
     */
    private $state = array();

    /**
     * Child and provider requests observed by mocks.
     *
     * @var array
     */
    private $requests = array();

    /** Raw exits would terminate PHPUnit before stored-state assertions. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'MainWP_Post_Base_Handler'      => 'Legacy_Rights_Base_Handler',
            'MainWP_Manage_Backups_Handler' => 'Legacy_Rights_Manage_Handler',
            'MainWP_Post_Backup_Handler'    => 'Legacy_Rights_Post_Handler',
        );
        foreach ( array( 'class/class-mainwp-post-base-handler.php', 'pages/page-mainwp-manage-backups-handler.php', 'class/class-mainwp-post-backup-handler.php' ) as $file ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture exits while retaining production registration, nonce checks and writes.
        }
    }

    /** Keep WP administrator capabilities granted while MainWP rights vary. */
    public function setUp(): void {
        parent::setUp();
        $this->state = array( $_GET, $_POST, $_REQUEST, get_current_user_id(), $GLOBALS['current_screen'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve request globals for teardown.
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        wp_create_nonce( 'legacy-backup-fixture' );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        set_current_screen( 'dashboard' );
        $this->filter( 'mainwp_currentusercan', '__return_true' );
        $this->filter(
            'mainwp_currentuserallowedaccesssites',
            static function () {
                return 'all';
            }
        );
        $this->filter(
            'mainwp_fetch_url_authed_pre',
            function ( $pre, $website, $what, $params ) {
                $this->requests[] = array( $website->id, $what, $params );
                return array( 'result' => true );
            }
        );
        $this->filter(
            'pre_http_request',
            function () {
                $this->requests[] = 'provider';
                return new \WP_Error( 'fixture_no_provider', 'No live provider requests in rights tests.' );
            }
        );
    }

    /** Restore callbacks and globals independently of transaction rollback. */
    public function tearDown(): void {
        global $wp_filter;
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        foreach ( $this->hooks as $hook => $original ) {
            if ( null === $original ) {
                unset( $wp_filter[ $hook ] );
            } else {
                $wp_filter[ $hook ] = $original;
            }
        }
        list( $_GET, $_POST, $_REQUEST, $user, $screen ) = $this->state;
        wp_set_current_user( $user );
        $GLOBALS['current_screen'] = $screen;
        foreach ( array( 'security_names', 'security_nonces' ) as $name ) {
            $property = new \ReflectionProperty( 'MainWP\\Dashboard\\Legacy_Rights_Base_Handler', $name );
            $property->setAccessible( true );
            $property->setValue( null, null );
        }
        parent::tearDown();
    }

    /**
     * Viewers and deleted-site fixtures.
     *
     * @return array Input matrix for site existence, independent of viewer scope.
     */
    public static function existence_cases(): array {
        return array(
            'scoped existing' => array( 'scoped', false ),
            'scoped deleted'  => array( 'scoped', true ),
            'admin existing'  => array( 'admin', false ),
            'admin deleted'   => array( 'admin', true ),
            'cron existing'   => array( 'cron', false ),
            'cron deleted'    => array( 'cron', true ),
        );
    }

    /**
     * Validation may remove deleted sites but must retain inaccessible sites.
     *
     * @dataProvider existence_cases
     * @param string $viewer Viewer context.
     * @param bool   $deleted Include a deleted site ID.
     */
    public function test_validate_backup_tasks_uses_existence( string $viewer, bool $deleted ): void {
        $visible = $this->create_test_site();
        $hidden  = $this->create_test_site( array( 'userid' => self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
        $removed = $this->create_test_site();
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $removed ), array( '%d' ) );
        $first  = $this->task( "$visible,$hidden" . ( $deleted ? ",$removed" : '' ) );
        $second = $this->task( (string) $hidden );
        $third  = $this->task( (string) $visible );
        if ( 'scoped' === $viewer ) {
            $this->filter(
                'mainwp_currentuserallowedaccesssites',
                static function () use ( $visible ) {
                    return array( $visible );
                }
            );
            $this->assertNull( MainWP_DB::instance()->get_website_by_id( $hidden ) );
            $this->assertNotNull( MainWP_DB::instance()->get_website_by_id( $visible ) );
        } elseif ( 'cron' === $viewer ) {
            wp_set_current_user( 0 );
        }
        $before = $this->snapshot();
        $tasks  = $before['tasks'];
        if ( $deleted ) {
            foreach ( $tasks as &$row ) {
                if ( (int) $row['id'] === (int) $first->id ) {
                    $row['sites'] = "$visible,$hidden";
                }
            }
            unset( $row );
        }
        $unchanged = MainWP_Manage_Backups_Handler::validate_backup_tasks( array( $first, $second, $third ) );
        $after     = $this->snapshot();
        $this->assertSame( $tasks, $after['tasks'] );
        $this->assertSame( $before['options'], $after['options'] );
        $this->assertSame( $before['site_options'], $after['site_options'] );
        $this->assertSame( ! $deleted, $unchanged );
    }

    /**
     * Run Now viewers.
     *
     * @return array Viewer and the site keys each one may run.
     */
    public static function task_run_cases(): array {
        return array(
            'scoped' => array( 'scoped', array( 'visible' ) ),
            'admin'  => array( 'admin', array( 'visible', 'hidden' ) ),
        );
    }

    /**
     * A retained out-of-scope or deleted site id must not reach Run Now as an empty row.
     *
     * @dataProvider task_run_cases
     * @param string $viewer Viewer context.
     * @param array  $expected Site keys returned in task order.
     */
    public function test_task_run_sites_skip_sites_the_viewer_cannot_load( string $viewer, array $expected ): void {
        $ids = array(
            'visible' => $this->create_test_site(),
            'hidden'  => $this->create_test_site( array( 'userid' => self::factory()->user->create( array( 'role' => 'administrator' ) ) ) ),
            'removed' => $this->create_test_site(),
        );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $ids['removed'] ), array( '%d' ) );
        $task = $this->task( $ids['hidden'] . ',' . $ids['visible'] . ',' . $ids['removed'] );
        if ( 'scoped' === $viewer ) {
            $visible = $ids['visible'];
            $this->filter(
                'mainwp_currentuserallowedaccesssites',
                static function () use ( $visible ) {
                    return array( $visible );
                }
            );
        }
        $result = MainWP_Manage_Backups_Handler::get_backup_task_sites( $task->id );
        $want   = array();
        foreach ( array( 'hidden', 'visible' ) as $key ) {
            if ( in_array( $key, $expected, true ) ) {
                $want[] = (string) $ids[ $key ];
            }
        }
        $this->assertSame( $want, array_map( 'strval', array_column( $result['sites'], 'id' ) ) );
        $this->assertSame( $ids['hidden'] . ',' . $ids['visible'] . ',' . $ids['removed'], MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->sites );
    }

    /**
     * Actions and the independently granted dashboard rights.
     *
     * @return array Refused and accepted rights for each action.
     */
    public static function action_rights(): array {
        $actions = array(
            'mainwp_addbackup'                => 'add_backup_tasks',
            'mainwp_pausebackup'              => 'pause_resume_backup_tasks',
            'mainwp_resumebackup'             => 'pause_resume_backup_tasks',
            'mainwp_backup_checkpid'          => 'execute_backups',
            'mainwp_createbackup_getfilesize' => 'execute_backups',
            'mainwp_backup_download_file'     => 'execute_backups',
            'mainwp_backup_delete_file'       => 'execute_backups',
            'mainwp_backup_getfilesize'       => 'execute_backups',
        );
        $cases   = array();
        foreach ( $actions as $action => $right ) {
            $cases[ $action . ' refused' ]   = array( $action, array(), false );
            $cases[ $action . ' unrelated' ] = array( $action, array( 'manage_dashboard_settings' ), false );
            $cases[ $action . ' allowed' ]   = array( $action, array( $right ), true );
            if ( 'execute_backups' === $right ) {
                // Native backups removed the browser-driven task run, so run_backup_tasks alone admits none of these.
                $cases[ $action . ' task only' ] = array( $action, array( 'run_backup_tasks' ), false );
            }
        }
        return $cases;
    }

    /**
     * Refused dispatch must preserve task rows, options and duplicate markers.
     *
     * @dataProvider action_rights
     * @param string $action AJAX action.
     * @param array  $rights Granted rights.
     * @param bool   $allowed Expected admission.
     */
    public function test_ajax_action_rights_preserve_refused_state( string $action, array $rights, bool $allowed ): void {
        $site = $this->create_test_site();
        $task = $this->task( (string) $site );
        MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'paused' => 'mainwp_resumebackup' === $action ? 1 : 0 ) );
        update_option( 'mainwp_ajaxposts', array( 'existing' => 'preserve' ) );
        update_option(
            'mainwp_upload_progress',
            array(
                'fixture' => array(
                    'offset'   => 17,
                    'dts'      => time(),
                    'finished' => true,
                ),
            )
        );
        // Task writes go through native backups, which need the toggle on.
        update_option( 'mainwp_enableNativeBackups', 1 );
        $this->rights( $rights );
        $this->register_actions();
        $_POST    = array(
            'name'               => 'Added task',
            'schedule'           => 'daily',
            'type'               => 'full',
            'sites'              => array( (string) $site ),
            'id'                 => (string) $task->id,
            'task_id'            => (string) $task->id,
            'site_id'            => 'mainwp_backup_delete_file' === $action ? (string) $site : '0',
            'siteId'             => '0',
            'file'               => 'fixture-backup.zip',
            'local'              => 'missing-rights-fixture.zip',
            'unique'             => 'fixture',
            'remote_destination' => 'fixture',
            'security'           => wp_create_nonce( $action ),
            'dts'                => wp_generate_uuid4(),
        );
        $_REQUEST = $_POST;
        $before   = $this->snapshot();
        $body     = $this->capture(
            static function () use ( $action ) {
                do_action( 'wp_ajax_' . $action );
            }
        );
        if ( ! $allowed ) {
            $this->assertSame( $before, $this->snapshot() );
            $this->assertSame( array(), $this->requests );
            $this->assertFalse( has_action( 'wp_ajax_' . $action ) );
            $this->assertSame( '', $body );
            return;
        }
        $this->assertNotFalse( has_action( 'wp_ajax_' . $action ) );
        $this->assertSame( $_POST['dts'], get_option( 'mainwp_ajaxposts' )[ $action ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compare the marker to the exact test input.
        $response = json_decode( $body, true );
        if ( 'mainwp_addbackup' === $action ) {
            $after = $this->snapshot();
            $this->assertCount( count( $before['tasks'] ) + 1, $after['tasks'] );
            $this->assertSame( (string) $site, end( $after['tasks'] )['sites'] );
            $this->assertSame( array( 'result' => 'Task created successfully.' ), $response );
        } elseif ( in_array( $action, array( 'mainwp_pausebackup', 'mainwp_resumebackup' ), true ) ) {
            $this->assertSame( 'mainwp_pausebackup' === $action ? 1 : 0, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->paused );
            $this->assertSame( array( 'result' => 'SUCCESS' ), $response );
        } elseif ( 'mainwp_backup_delete_file' === $action ) {
            $this->assertSame( array( array( (string) $site, 'delete_backup', array( 'del' => 'fixture-backup.zip' ) ) ), $this->requests );
            $this->assertSame( array( 'result' => true ), $response );
        } elseif ( 'mainwp_backup_checkpid' === $action || 'mainwp_backup_download_file' === $action ) {
            $this->assertSame( 'Site ID not found. Please reload the page and try again.', $response['error']['message'] );
        } else {
            // Native backups authorize the posted site (0 here) before reading any file size.
            $this->assertSame( array( 'error' => 'You are not allowed to manage backups for this site.' ), $response );
        }
    }

    /** The read-only update-flow action remains available without backup rights. */
    public function test_checkbackups_remains_registered(): void {
        $this->rights( array() );
        $this->register_actions();
        $this->assertNotFalse( has_action( 'wp_ajax_mainwp_checkbackups' ) );
    }

    /**
     * Every provider's mode, right and nonce matrix.
     *
     * @return array Global save admission cases.
     */
    public static function provider_cases(): array {
        $cases = array();
        foreach ( array( 'cloudways', 'gridpane', 'vultr', 'linode', 'digitalocean', 'cpanel', 'plesk', 'kinsta' ) as $provider ) {
            foreach ( array( 'global refused', 'global allowed', 'individual refused', 'individual with right', 'invalid nonce' ) as $profile ) {
                $individual                          = 0 === strpos( $profile, 'individual' );
                $allowed                             = in_array( $profile, array( 'global allowed', 'individual with right', 'invalid nonce' ), true );
                $cases[ $provider . ' ' . $profile ] = array( $provider, $individual, $allowed, 'invalid nonce' !== $profile, 'replacement-key' );
            }
        }
        foreach ( array( false, true ) as $individual ) {
            foreach ( array( false, true ) as $right ) {
                $cases[ 'kinsta empty ' . ( $individual ? 'individual' : 'global' ) . ( $right ? ' allowed right' : ' refused right' ) ] = array( 'kinsta', $individual, $right, true, '' );
            }
        }
        $cases['kinsta sentinel allowed'] = array( 'kinsta', false, true, true, '••••••••' );
        return $cases;
    }

    /**
     * Individual renders and denied global saves must preserve every option.
     *
     * @dataProvider provider_cases
     * @param string $provider Provider name.
     * @param bool   $individual Per-site mode.
     * @param bool   $right Global manage right.
     * @param bool   $nonce Valid nonce.
     * @param string $key Posted key, including deletion and sentinel cases.
     */
    public function test_provider_renderer_global_writes( string $provider, bool $individual, bool $right, bool $nonce, string $key ): void {
        $this->create_test_site();
        $this->api_key_hooks();
        update_option( 'mainwp_show_legacy_cloudways_credentials', 0 );
        $this->capture(
            static function () use ( $individual ) {
                Api_Backups_Settings::render_3rd_party_api_manager( $individual );
            }
        );
        $enabled = 'mainwp_enable_' . $provider . '_api';
        $stored  = 'mainwp_api_backups_' . $provider . '_api_key';
        update_option( $enabled, 0 );
        update_option(
            $stored,
            array(
                'encrypted_val' => 'fixture:original-key',
                'file_key'      => 'fixture-only',
            )
        );
        update_option( 'mainwp_kinsta_api_account_email', 'original@example.org' );
        update_option( 'mainwp_kinsta_company_id', 'original-company' );
        update_option( 'mainwp_ajaxposts', array( 'existing' => 'preserve' ) );
        $this->rights( $right ? array( 'manage_api_backups' ) : array() );
        $_POST    = array(
            'submit'                           => '1',
            $enabled                           => '1',
            'mainwp_' . $provider . '_api_key' => $key,
            'mainwp_cpanel_account_password'   => $key,
            'mainwp_kinsta_api_account_email'  => 'replacement@example.org',
            'mainwp_kinsta_company_id'         => 'replacement-company',
            'mainwp_cloudways_access_token'    => 'replacement-access-token',
            'mainwp_cpanel_url'                => 'https://panel.example.org',
            'mainwp_cpanel_site_path'          => '/fixture',
            'mainwp_cpanel_account_username'   => 'fixture-user',
            'mainwp_plesk_api_url'             => 'https://plesk.example.org',
            'mainwp_plesk_installation_id'     => 'replacement-installation',
            'kinsta' === $provider ? 'wp_nonce' : 'wp_nonce_' . $provider => $nonce ? wp_create_nonce( 'kinsta' === $provider ? 'kinsta_api_form' : 'cloudways_api_form_general' ) : 'invalid',
        );
        $_REQUEST = $_POST;
        $before   = $this->snapshot();
        $body     = $this->capture(
            static function () use ( $individual ) {
                Api_Backups_Settings::render_3rd_party_api_manager( $individual );
            }
        );
        if ( $individual || ! $right || ! $nonce ) {
            $this->assertSame( $before, $this->snapshot() );
            $this->assertSame( array(), $this->requests );
        } else {
            $this->assertSame( 1, (int) get_option( $enabled ) );
            if ( '' === $key ) {
                $this->assertFalse( get_option( $stored ) );
            } else {
                $this->assertSame(
                    array(
                        'encrypted_val' => 'fixture:' . ( '••••••••' === $key ? 'original-key' : $key ),
                        'file_key'      => 'fixture-only',
                    ),
                    get_option( $stored )
                );
            }
            if ( 'kinsta' === $provider ) {
                $this->assertSame( 'replacement@example.org', get_option( 'mainwp_kinsta_api_account_email' ) );
                $this->assertSame( 'replacement-company', get_option( 'mainwp_kinsta_company_id' ) );
            }
        }
        $this->assertStringContainsString( 'data-tab="kinsta"', $body );
        $this->assertStringContainsString( 'name="mainwp_kinsta_api_key"', $body );
    }

    /**
     * Lazy credential display defaults are also global writes.
     *
     * @return array Mode and right matrix.
     */
    public static function display_default_cases(): array {
        return array(
            'individual refused'    => array( true, false ),
            'individual with right' => array( true, true ),
            'global refused'        => array( false, false ),
            'global allowed'        => array( false, true ),
        );
    }

    /**
     * Calculating the rendered default must not persist it for a scoped viewer.
     *
     * @dataProvider display_default_cases
     * @param bool $individual Per-site mode.
     * @param bool $right Global manage right.
     */
    public function test_cloudways_display_default_requires_global_right( bool $individual, bool $right ): void {
        $this->api_key_hooks();
        update_option(
            'mainwp_api_backups_cloudways_api_key',
            array(
                'encrypted_val' => 'fixture:original-key',
                'file_key'      => 'fixture-only',
            )
        );
        delete_option( 'mainwp_show_legacy_cloudways_credentials' );
        update_option( 'mainwp_ajaxposts', array( 'existing' => 'preserve' ) );
        $this->rights( $right ? array( 'manage_api_backups' ) : array() );
        $before = $this->snapshot();
        $body   = $this->capture(
            static function () use ( $individual ) {
                Api_Backups_Settings::render_3rd_party_api_manager( $individual );
            }
        );
        if ( $individual || ! $right ) {
            $this->assertSame( $before, $this->snapshot() );
        } else {
            $this->assertSame( 1, (int) get_option( 'mainwp_show_legacy_cloudways_credentials' ) );
        }
        $this->assertStringContainsString( 'id="mainwp_show_legacy_cloudways_credentials" checked="true"', $body );
        $this->assertMatchesRegularExpression( '/settings-field-icon-indicator visible-indicator[^<]*<\/i><\/span>\s*Show Legacy API Credentials/', $body );
    }

    /** Synthetic envelopes keep key creation and deletion inside the test. */
    private function api_key_hooks(): void {
        $this->replace_hook( 'mainwp_encrypt_key_value' );
        $this->replace_hook( 'mainwp_decrypt_key_value' );
        $this->replace_hook( 'mainwp_delete_key_file' );
        $this->filter(
            'mainwp_encrypt_key_value',
            static function ( $result, $value ) {
                return array(
                    'encrypted_val' => 'fixture:' . $value,
                    'file_key'      => 'fixture-only',
                );
            }
        );
        $this->filter(
            'mainwp_decrypt_key_value',
            static function ( $result, $envelope ) {
                return substr( $envelope['encrypted_val'], strlen( 'fixture:' ) );
            }
        );
    }

    /**
     * Exact task fixture uses the same stored IDs the writer will update.
     *
     * @param string $sites Comma-separated site IDs.
     * @return object Stored task.
     */
    private function task( string $sites ): object {
        $task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Fixture task', 'daily', 'full', '', $sites, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 0, 150, 0 );
        $this->assertIsObject( $task );
        return $task;
    }

    /**
     * Set rights independently of WordPress administrator capabilities.
     *
     * @param array $rights Granted dashboard rights.
     */
    private function rights( array $rights ): void {
        $this->assertFalse( defined( 'MAINWP_REST_API_DOING' ), 'Run this class before REST tests define MAINWP_REST_API_DOING.' );
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $cap ) use ( $rights ) {
                return 'dashboard' === $type ? in_array( $cap, $rights, true ) : $can;
            }
        );
        foreach ( array( 'add_backup_tasks', 'pause_resume_backup_tasks', 'run_backup_tasks', 'execute_backups', 'manage_api_backups' ) as $right ) {
            $this->assertSame( in_array( $right, $rights, true ), \mainwp_current_user_can( 'dashboard', $right ) );
        }
    }

    /** Re-run production registration under the test viewer's rights. */
    private function register_actions(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-post-backup-handler.php' );
        preg_match_all( '/\$this->add_action\( \'([^\']+)\'/', $source, $matches );
        foreach ( array_unique( $matches[1] ) as $action ) {
            $this->replace_hook( 'wp_ajax_' . $action );
        }
        ( new Legacy_Rights_Post_Handler() )->init();
    }

    /**
     * Isolate hooks without losing callbacks from other suites.
     *
     * @param string $hook Hook name.
     */
    private function replace_hook( string $hook ): void {
        global $wp_filter;
        if ( ! array_key_exists( $hook, $this->hooks ) ) {
            $this->hooks[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
        }
        remove_all_filters( $hook );
    }

    /**
     * Replace only callbacks owned by this test.
     *
     * @param string   $hook Filter name.
     * @param callable $callback Filter callback.
     */
    private function filter( string $hook, callable $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 4 );
    }

    /**
     * Full snapshots include secure_request's mainwp_ajaxposts option.
     *
     * @return array Stored state.
     */
    private function snapshot(): array {
        global $wpdb;
        return array(
            'options'      => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ),
            'tasks'        => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_backup ORDER BY id", ARRAY_A ),
            'site_options' => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_options ORDER BY wpid, name", ARRAY_A ),
        );
    }

    /**
     * Capture both wp_send_json and tokenized bare die() calls.
     *
     * @param callable $callback Request callback.
     * @return string Captured response.
     */
    private function capture( callable $callback ): string {
        global $wp_filter;
        $hooks = array();
        foreach ( array( 'wp_doing_ajax', 'wp_die_ajax_handler' ) as $hook ) {
            $hooks[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
        }
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( Legacy_Backup_Response_Stop $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
            foreach ( $hooks as $hook => $original ) {
                if ( null === $original ) {
                    unset( $wp_filter[ $hook ] );
                } else {
                    $wp_filter[ $hook ] = $original;
                }
            }
        }
        return $buffer . $body;
    }

    /**
     * AJAX termination callback.
     *
     * @return callable AJAX termination callback.
     */
    public function ajax_die_handler(): callable {
        return static function ( $message ) {
            throw new Legacy_Backup_Response_Stop( (string) $message );
        };
    }
}
