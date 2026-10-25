<?php
/**
 * API Backups nonces must not bypass rights, site scope or object ownership.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Separate Error types distinguish response exits from boundary stops.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Fixtures restore request globals rather than process live GET input.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Fixtures exercise production authorization and nonce checks.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Assertions compare captured responses without browser output.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Source copies capture legacy exits without changing production code.

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Credential_Render;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Settings;
use MainWP\Dashboard\Module\ApiBackups\Rights_Api_Backups_3rd_Party;
use MainWP\Dashboard\Module\ApiBackups\Rights_Api_Backups_Handler;

/** Stop before any transport can run. */
class Api_Backups_Boundary_Stop extends \Error {}

/** Capture JSON and bare nonce exits. */
class Api_Backups_Response_Stop extends \Error {}

/** Exercise every registered, implemented provider callback. */
class Test_Dashboard_Api_Backups_Action_Rights extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Test-owned filters.
     *
     * @var array
     */
    private $filters = array();

    /**
     * Original hooks replaced during a request.
     *
     * @var array
     */
    private $hooks = array();

    /**
     * Original request and user state.
     *
     * @var array
     */
    private $state = array();

    /**
     * Accessible site.
     *
     * @var int
     */
    private $site;

    /**
     * Hidden site.
     *
     * @var int
     */
    private $foreign;

    /**
     * Boundary reads or Child dispatches.
     *
     * @var int
     */
    private $hits = 0;

    /**
     * Observed mapping target.
     *
     * @var array
     */
    private $target = array();

    /** Capture exits and supply AJAX context without process-wide constants. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'Api_Backups_Helper'       => 'Rights_Api_Backups_Helper',
            'Api_Backups_3rd_Party'    => 'Rights_Api_Backups_3rd_Party',
            'Api_Backups_Handler'      => 'Rights_Api_Backups_Handler',
            'MainWP_Post_Base_Handler' => 'Api_Backups_Rights_Base_Handler',
        );
        $files = array(
            'class/class-mainwp-post-base-handler.php',
            'modules/api-backups/classes/class-api-backups-helper.php',
            'modules/api-backups/classes/class-api-backups-3rd-party.php',
            'modules/api-backups/classes/class-api-backups-handler.php',
        );
        foreach ( $files as $file ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            $code = str_replace( array( '\\wp_die;', "defined( 'DOING_AJAX' ) && DOING_AJAX" ), array( '\\wp_die();', '\\wp_doing_ajax()' ), $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Retain production gates and nonce writes while capturing exits and AJAX context.
        }
    }

    /** Grant WP administrator capabilities while varying Team Control access. */
    public function setUp(): void {
        parent::setUp();
        $this->state = array( $_GET, $_POST, $_REQUEST, get_current_user_id(), get_current_screen() );
        wp_create_nonce( 'api-backups-rights-fixture' );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        set_current_screen( 'dashboard' );
        $_GET          = array();
        $_POST         = array();
        $_REQUEST      = array();
        $this->site    = $this->create_test_site();
        $this->foreign = $this->create_test_site();
        $this->lists( $this->site, '101', 'snapshot-owned' );
        $this->lists( $this->foreign, '202', 'snapshot-foreign' );
        update_option( 'mainwp_ajaxposts', array( 'preserved' => 'original' ) );
        $this->scope( false );
        $this->filter( 'wp_doing_ajax', '__return_true' );
        $this->filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        global $wp_filter;
        foreach ( array( 'mainwp_secure_request', 'admin_init' ) as $hook ) {
            $this->hooks[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
            remove_all_actions( $hook );
        }
        $base = new class() extends \MainWP\Dashboard\Api_Backups_Rights_Base_Handler {
            /** No registration is needed for direct nonce dispatch. */
            protected function init() {}
        };
        add_action( 'mainwp_secure_request', array( $base, 'secure_request' ) );
    }

    /** Restore every hook and request fixture. */
    public function tearDown(): void {
        global $wp_filter;
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 'mainwp_getwebsiteoptions' === $hook ? -999 : 999 );
        }
        foreach ( $this->hooks as $hook => $value ) {
            if ( null === $value ) {
                unset( $wp_filter[ $hook ] );
            } else {
                $wp_filter[ $hook ] = $value;
            }
        }
        list( $_GET, $_POST, $_REQUEST, $user, $screen ) = $this->state;
        wp_set_current_user( $user );
        $GLOBALS['current_screen'] = $screen;
        parent::tearDown();
    }

    /**
     * Registered action, callback and required right.
     *
     * @return array Action matrix.
     */
    public static function actions(): array {
        return array(
            'cloudways_action_backup'                      => array( 'ajax_cloudways_action_backup', 'execute_backups' ),
            'cloudways_action_individual_backup'           => array( 'ajax_cloudways_action_individual_backup', 'execute_backups' ),
            'cloudways_action_update_ids'                  => array( 'ajax_cloudways_action_update_ids', 'execute_backups' ),
            'cloudways_action_refresh_available_backups'   => array( 'cloudways_action_refresh_available_backups', 'execute_backups' ),
            'cloudways_action_restore_backup'              => array( 'cloudways_action_restore_backup', 'restore_backups' ),
            'cloudways_action_delete_backup'               => array( 'cloudways_action_delete_backup', 'execute_backups' ),
            'gridpane_action_create_backup'                => array( 'ajax_gridpane_action_create_backup', 'execute_backups' ),
            'gridpane_action_individual_create_backup'     => array( 'ajax_gridpane_action_individual_create_backup', 'execute_backups' ),
            'gridpane_action_update_ids'                   => array( 'ajax_gridpane_action_update_ids', 'execute_backups' ),
            'gridpane_action_refresh_available_backups'    => array( 'gridpane_action_refresh_available_backups', 'execute_backups' ),
            'gridpane_action_restore_backup'               => array( 'gridpane_action_restore_backup', 'restore_backups' ),
            'gridpane_action_delete_backup'                => array( 'gridpane_action_delete_backup', 'execute_backups' ),
            'vultr_action_create_snapshot'                 => array( 'ajax_vultr_action_create_snapshot', 'execute_backups' ),
            'vultr_action_individual_create_snapshot'      => array( 'ajax_vultr_action_individual_create_snapshot', 'execute_backups' ),
            'vultr_action_refresh_available_backups'       => array( 'vultr_action_refresh_available_backups', 'execute_backups' ),
            'vultr_action_restore_backup'                  => array( 'ajax_vultr_action_restore_backup', 'restore_backups' ),
            'vultr_action_delete_backup'                   => array( 'vultr_action_delete_backup', 'execute_backups' ),
            'linode_action_create_backup'                  => array( 'ajax_linode_action_create_backup', 'execute_backups' ),
            'linode_action_individual_create_backup'       => array( 'ajax_linode_action_individual_create_backup', 'execute_backups' ),
            'linode_action_refresh_available_backups'      => array( 'linode_action_refresh_available_backups', 'execute_backups' ),
            'linode_action_restore_backup'                 => array( 'linode_action_restore_backup', 'restore_backups' ),
            'linode_action_cancel_backups'                 => array( 'linode_action_cancel_backups', 'execute_backups' ),
            'digitalocean_action_create_backup'            => array( 'ajax_digitalocean_action_create_backup', 'execute_backups' ),
            'digitalocean_action_individual_create_backup' => array( 'ajax_digitalocean_action_individual_create_backup', 'execute_backups' ),
            'digitalocean_action_refresh_available_backups' => array( 'digitalocean_action_refresh_available_backups', 'execute_backups' ),
            'digitalocean_action_restore_backup'           => array( 'digitalocean_action_restore_backup', 'restore_backups' ),
            'digitalocean_action_delete_backup'            => array( 'digitalocean_action_delete_backup', 'execute_backups' ),
            'cpanel_action_refresh_available_backups'      => array( 'ajax_cpanel_action_refresh_available_backups', 'execute_backups' ),
            'cpanel_action_create_manual_backup'           => array( 'ajax_cpanel_action_create_manual_backup', 'execute_backups' ),
            'cpanel_action_create_wptk_backup'             => array( 'ajax_cpanel_action_create_wptk_backup', 'execute_backups' ),
            'cpanel_action_restore_wptk_backup'            => array( 'ajax_cpanel_action_restore_wptk_backup', 'restore_backups' ),
            'cpanel_action_delete_wptk_backup'             => array( 'ajax_cpanel_action_delete_wptk_backup', 'execute_backups' ),
            'cpanel_action_restore_backup'                 => array( 'ajax_cpanel_action_restore_backup', 'restore_backups' ),
            'cpanel_action_restore_database_backup'        => array( 'ajax_cpanel_action_restore_database_backup', 'restore_backups' ),
            'cpanel_action_restore_manual_backup'          => array( 'ajax_cpanel_action_restore_manual_backup', 'restore_backups' ),
            'cpanel_action_create_database_backup'         => array( 'ajax_cpanel_action_create_database_backup', 'execute_backups' ),
            'cpanel_action_create_full_backup'             => array( 'ajax_cpanel_action_create_full_backup', 'execute_backups' ),
            'plesk_action_refresh_available_backups'       => array( 'ajax_plesk_action_refresh_available_backups', 'execute_backups' ),
            'plesk_action_create_backup'                   => array( 'ajax_plesk_action_create_backup', 'execute_backups' ),
            'plesk_action_restore_backup'                  => array( 'ajax_plesk_action_restore_backup', 'restore_backups' ),
            'plesk_action_delete_backup'                   => array( 'ajax_plesk_action_delete_backup', 'execute_backups' ),
            'kinsta_action_refresh_available_backups'      => array( 'ajax_kinsta_action_refresh_available_backups', 'execute_backups' ),
            'kinsta_action_create_backup'                  => array( 'ajax_kinsta_action_create_backup', 'execute_backups' ),
            'kinsta_action_restore_backup'                 => array( 'ajax_kinsta_action_restore_backup', 'restore_backups' ),
            'kinsta_action_delete_backup'                  => array( 'ajax_kinsta_action_delete_backup', 'execute_backups' ),
            'mainwp_api_backups_selected_websites'         => array( 'ajax_backups_selected_websites', 'execute_backups' ),
        );
    }

    /**
     * Every entry needs its own operation right before any boundary or nonce write.
     *
     * @dataProvider actions
     * @param string $method Callback.
     * @param string $right Required right.
     */
    public function test_missing_right( string $method, string $right ): void {
        $this->rights( $right, false );
        $this->refused( $method );
    }

    /**
     * Posted site ids cannot escape the current user's accessible sites.
     *
     * @dataProvider site_actions
     * @param string $method Callback.
     * @param string $right Required right.
     */
    public function test_hidden_site( string $method, string $right ): void {
        $this->rights( $right, true );
        $this->refused( $method, array( $this->site_field( $method ) => (string) $this->foreign ) );
    }

    /**
     * In-scope actions reach their existing boundary without network access.
     *
     * @dataProvider actions
     * @param string $method Callback.
     * @param string $right Required right.
     */
    public function test_permitted( string $method, string $right ): void {
        // Only discovery writes mapping; every other action must pass on its own operation right.
        $this->rights( $right, true, in_array( $method, array( 'ajax_cloudways_action_update_ids', 'ajax_gridpane_action_update_ids' ), true ) ? array( 'manage_api_backups' ) : array() );
        if ( 'ajax_cpanel_action_restore_manual_backup' === $method ) {
            $this->scope( true );
        }
        $response = $this->request( $method, 'ajax_cpanel_action_restore_backup' === $method ? array( 'dts' => null ) : array() );
        if ( 'ajax_cloudways_action_individual_backup' === $method ) {
            $this->assertStringContainsString( 'cloudways_action_backup', $response );
            $this->assertSame( 0, $this->hits, 'This existing callback calls an undefined method.' );
        } else {
            $this->assertSame( 'boundary', $response );
            $this->assertSame( 1, $this->hits );
            if ( $this->target ) {
                $this->assertSame( $this->site, $this->target[0] );
            }
        }
    }

    /**
     * Site-bearing actions only.
     *
     * @return array Site action matrix.
     */
    public static function site_actions(): array {
        return array_filter(
            self::actions(),
            static function ( $row ) {
                return ! in_array( $row[0], array( 'ajax_cloudways_action_update_ids', 'ajax_gridpane_action_update_ids', 'digitalocean_action_delete_backup' ), true );
            }
        );
    }

    /**
     * Unscoped provider ids and per-site cPanel database files.
     *
     * @return array Ownership action matrix.
     */
    public static function owned_actions(): array {
        return array_intersect_key(
            self::actions(),
            array_flip( array( 'vultr_action_restore_backup', 'vultr_action_delete_backup', 'digitalocean_action_restore_backup', 'kinsta_action_restore_backup', 'kinsta_action_delete_backup', 'cpanel_action_restore_backup', 'cpanel_action_restore_database_backup' ) )
        );
    }

    /**
     * Another site's object must be refused before credentials or mappings.
     *
     * @dataProvider owned_actions
     * @param string $method Callback.
     * @param string $right Required right.
     */
    public function test_foreign_object( string $method, string $right ): void {
        $this->rights( $right, true );
        $this->refused(
            $method,
            array(
                'snapshot_id' => 'snapshot-foreign',
                'backup_id'   => '202',
            )
        );
    }

    /**
     * Stale lists refuse unknown objects with a refresh instruction.
     *
     * @dataProvider owned_actions
     * @param string $method Callback.
     * @param string $right Required right.
     */
    public function test_stale_object( string $method, string $right ): void {
        $this->rights( $right, true );
        $before = $this->snapshot();
        $body   = $this->request(
            $method,
            array(
                'snapshot_id' => 'missing-snapshot',
                'backup_id'   => '999',
            )
        );
        $this->assertSame( 0, $this->hits );
        $this->assertSame( $before, $this->snapshot() );
        $this->assertStringContainsString( 'Refresh', $body );
    }

    /** Kinsta restore must validate exactly the integer the worker uses. */
    public function test_kinsta_coercion_and_leading_zeroes(): void {
        $this->rights( 'restore_backups', true );
        foreach ( array( '101junk', '101.5', '+101', array( '101' ) ) as $input ) {
            $this->refused( 'ajax_kinsta_action_restore_backup', array( 'backup_id' => $input ) );
        }
        $this->assertSame( 'boundary', $this->request( 'ajax_kinsta_action_restore_backup', array( 'backup_id' => '00101' ) ) );
    }

    /** Vultr may own a snapshot through either stored list shape. */
    public function test_vultr_available_list_shapes(): void {
        $this->rights( 'restore_backups', true );
        MainWP_DB::instance()->update_website_option( $this->site, 'mainwp_3rd_party_vultr_snapshot_list', '[]' );
        $this->assertSame( 'boundary', $this->request( 'ajax_vultr_action_restore_backup' ) );
        $this->assertSame( 'boundary', $this->request( 'ajax_vultr_action_restore_backup', array( 'snapshot_id' => 'automatic-' . $this->site ) ) );
    }

    /** A nonexistent site must be refused even with unrestricted rights. */
    public function test_missing_site_and_leading_zeroes(): void {
        $this->rights( 'execute_backups', true );
        $this->scope( true );
        $this->refused( 'ajax_cloudways_action_backup', array( 'website_id' => '2147483647' ) );
        $this->assertSame( 'boundary', $this->request( 'ajax_cloudways_action_backup', array( 'website_id' => '00' . $this->site ) ) );
    }

    /**
     * Account-wide cPanel restores require unrestricted site access.
     *
     * @return array Credential mode and scope matrix.
     */
    public static function account_actions(): array {
        $cases = array();
        foreach ( array( 'restore_backup', 'restore_database_backup', 'restore_manual_backup' ) as $suffix ) {
            foreach ( array( false, true ) as $unrestricted ) {
                $cases[ $suffix . ( $unrestricted ? ' all' : ' scoped' ) ] = array( 'ajax_cpanel_action_' . $suffix, $unrestricted );
            }
        }
        return $cases;
    }

    /**
     * Global configured paths and manual account files are not site ownership.
     *
     * @dataProvider account_actions
     * @param string $method Callback.
     * @param bool   $unrestricted All sites allowed.
     */
    public function test_account_restore( string $method, bool $unrestricted ): void {
        MainWP_DB::instance()->update_website_option( $this->site, 'mainwp_enable_cpanel_individual', '0' );
        $this->rights( 'restore_backups', true );
        $this->scope( $unrestricted );
        if ( $unrestricted ) {
            $this->assertSame( 'boundary', $this->request( $method, 'ajax_cpanel_action_restore_backup' === $method ? array( 'dts' => null ) : array() ) );
            $this->assertSame( 1, $this->hits );
        } else {
            $this->refused( $method );
        }
    }

    /**
     * Snapshot-only DigitalOcean deletes: owner resolution matrix.
     *
     * @return array Posted snapshot, unrestricted scope, extra fixture, expected result.
     */
    public static function digitalocean_delete_owners(): array {
        return array(
            'own listed, restricted'             => array( 'snapshot-owned', false, '', 'boundary' ),
            'integer id posted as digits'        => array( '9972001', true, 'integer', 'boundary' ),
            'foreign listed, restricted'         => array( 'snapshot-foreign', false, '', 'refused' ),
            'unlisted'                           => array( 'snapshot-missing', true, '', 'refused' ),
            'empty id'                           => array( '', true, '', 'refused' ),
            'array id'                           => array( array( 'snapshot-owned' ), true, '', 'refused' ),
            'two accessible owners'              => array( 'snapshot-owned', true, 'shared', 'boundary' ),
            'accessible and hidden owner'        => array( 'snapshot-owned', false, 'shared', 'boundary' ),
            'duplicate option rows for one site' => array( 'snapshot-owned', true, 'duplicate', 'boundary' ),
            'staging owner only'                 => array( 'snapshot-staging', true, 'staging', 'boundary' ),
            'production and staging owners'      => array( 'snapshot-owned', true, 'staging-shared', 'boundary' ),
            'row for a deleted site'             => array( 'snapshot-orphan', true, 'orphan', 'refused' ),
        );
    }

    /**
     * At least one accessible site must own the snapshot.
     *
     * @dataProvider digitalocean_delete_owners
     * @param mixed  $snapshot Posted snapshot id.
     * @param bool   $all Unrestricted scope.
     * @param string $fixture Extra stored lists.
     * @param string $expect Boundary or refused.
     */
    public function test_digitalocean_delete_owner_matrix( $snapshot, bool $all, string $fixture, string $expect ): void {
        $this->rights( 'execute_backups', true );
        $this->scope( $all );
        $checked = array();
        $this->filter(
            'mainwp_getwebsite_by_id',
            static function ( $website, $site ) use ( &$checked ) {
                $checked[] = (int) $site;
                return $website;
            }
        );
        $name = 'mainwp_3rd_party_digitalocean_available_backups';
        $list = static function ( $id ) {
            return wp_json_encode( array( 'snapshots' => array( array( 'id' => $id ) ) ) );
        };
        if ( 'integer' === $fixture ) {
            MainWP_DB::instance()->update_website_option( $this->site, $name, $list( 9972001 ) );
        } elseif ( 'shared' === $fixture ) {
            MainWP_DB::instance()->update_website_option( $this->foreign, $name, $list( 'snapshot-owned' ) );
        } elseif ( 'duplicate' === $fixture ) {
            // set_site_option() inserts, so this adds a second row for the same site and option.
            $this->set_site_option( $this->site, $name, $list( 'snapshot-owned' ) );
        } elseif ( 'orphan' === $fixture ) {
            $this->set_site_option( 999999, $name, $list( 'snapshot-orphan' ) );
        } elseif ( 0 === strpos( $fixture, 'staging' ) ) {
            $staging = $this->create_test_site( array( 'is_staging' => 1 ) );
            $this->lists( $staging, '303', 'staging' === $fixture ? 'snapshot-staging' : 'snapshot-owned' );
        }
        if ( 'refused' === $expect ) {
            $this->refused( 'digitalocean_action_delete_backup', array( 'snapshot_id' => $snapshot ) );
            return;
        }
        $this->assertSame( 'boundary', $this->request( 'digitalocean_action_delete_backup', array( 'snapshot_id' => $snapshot ) ) );
        $this->assertSame( 1, $this->hits );
        if ( in_array( $fixture, array( 'shared', 'staging-shared' ), true ) ) {
            $this->assertSame( array( $this->site ), array_values( array_unique( $checked ) ), 'Use the first accessible owner.' );
        }
    }

    /**
     * Mixed forms and direct hook calls must protect the complete mapping block.
     *
     * @return array Rights, scope, entry point and key submission matrix.
     */
    public static function mapping_site_edits(): array {
        return array(
            'mixed denied replacement' => array( false, true, true, 'replace' ),
            'mixed denied clear'       => array( false, true, true, 'clear' ),
            'mixed denied retained'    => array( false, true, true, 'retain' ),
            'mixed permitted replace'  => array( true, true, true, 'replace' ),
            'mixed permitted clear'    => array( true, true, true, 'clear' ),
            'mixed permitted retained' => array( true, true, true, 'retain' ),
            'mixed hidden site'        => array( true, false, true, 'replace' ),
            'hook denied'              => array( false, true, false, 'replace' ),
            'hook hidden site'         => array( true, false, false, 'replace' ),
            'hook permitted'           => array( true, true, false, 'replace' ),
        );
    }

    /**
     * Site settings still save when the mapping right is absent.
     *
     * @dataProvider mapping_site_edits
     * @param bool   $manage Mapping right.
     * @param bool   $accessible Site right and scope.
     * @param bool   $form Submit the ordinary site form.
     * @param string $keys Replace, clear or retain per-site keys.
     */
    public function test_mapping_site_edit( bool $manage, bool $accessible, bool $form, string $keys ): void {
        $this->rights( 'edit_sites', true, $manage ? array( 'manage_api_backups' ) : array() );
        $site    = $accessible ? $this->site : $this->foreign;
        $mapping = array(
            'mainwp_3rd_party_api'            => array( 'mainwp_managesites_edit_module_api_backups_provider', '6', 'Kinsta' ),
            'mainwp_3rd_party_instance_id'    => array( 'edit_site_module_api_backups_provider_instance_id', 'new-instance', 'new-instance' ),
            'cpanel_api_url'                  => array( 'cpanel_api_url', 'https://new-cpanel.example:2083', 'https://new-cpanel.example:2083' ),
            'mainwp_enable_wp_toolkit'        => array( 'mainwp_enable_wp_toolkit', 'on', 'on' ),
            'mainwp_enable_cpanel_individual' => array( 'mainwp_enable_cpanel_individual', 'on', 'on' ),
            'cpanel_account_username'         => array( 'mainwp_cpanel_account_username', 'new-account', 'new-account' ),
            'cpanel_site_path'                => array( 'cpanel_site_path', '/new/site', '/new/site' ),
            'mainwp_enable_plesk_individual'  => array( 'mainwp_enable_plesk_individual', 'on', 'on' ),
            'plesk_api_url'                   => array( 'plesk_api_url', 'https://new-plesk.example:8443/', 'https://new-plesk.example:8443' ),
            'mainwp_plesk_installation_id'    => array( 'plesk_installation_id', 'new-installation', 'new-installation' ),
            'mainwp_enable_kinsta_individual' => array( 'mainwp_enable_kinsta_individual', 'on', 'on' ),
            'mainwp_kinsta_environment_id'    => array( 'kinsta_environment_id', 'new-environment', 'new-environment' ),
            'mainwp_kinsta_account_email'     => array( 'kinsta_account_email', 'fixture@example.com', 'fixture@example.com' ),
            'mainwp_kinsta_company_id'        => array( 'kinsta_company_id', 'new-company', 'new-company' ),
        );
        $_POST   = array(
            'submit'                                   => 'Save Settings',
            'wp_nonce'                                 => wp_create_nonce( 'UpdateWebsite' . $site ),
            'mainwp_managesites_edit_siteadmin'        => 'admin',
            'mainwp_managesites_edit_sitename'         => 'Changed ordinary setting',
            'mainwp_managesites_edit_siteurl_protocol' => 'https',
            'mainwp_automaticDailyUpdate'              => '1',
            'mainwp_managesites_edit_monitoringNotificationEmails' => 'monitor@example.com',
        );
        foreach ( $mapping as $option => $values ) {
            MainWP_DB::instance()->update_website_option( $site, $option, 'original-' . $option );
            $_POST[ $values[0] ] = $values[1];
        }
        MainWP_DB::instance()->update_website_option( $site, 'mainwp_3rd_party_app_id', 'original-app' );
        $key_fields = array(
            'cpanel' => 'mainwp_cpanel_account_password',
            'plesk'  => 'mainwp_plesk_api_key',
            'kinsta' => 'mainwp_kinsta_api_key',
        );
        foreach ( $key_fields as $provider => $field ) {
            MainWP_DB::instance()->update_website_option( $site, 'mainwp_api_backups_' . $provider . '_api_key', wp_json_encode( array( 'encrypted_val' => 'original-' . $provider ) ) );
            $_POST[ $field ] = 'retain' === $keys ? MainWP_Credential_Render::value_for_input( true ) : ( 'clear' === $keys ? '' : 'fixture-' . $provider );
        }
        $_REQUEST = array_merge( $_POST, array( 'id' => (string) $site ) );
        global $wp_filter;
        foreach ( array( 'mainwp_update_site', 'mainwp_site_updated', 'mainwp_encrypt_key_value' ) as $hook ) {
            $this->hooks[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
            remove_all_filters( $hook );
        }
        $this->filter(
            'mainwp_encrypt_key_value',
            static function ( $value, $data ) {
                return array( 'encrypted_val' => $data, 'file_key' => 'fixture-key-file' );
            }
        );
        $settings = new class() extends Api_Backups_Settings {
            /** Register only the hook under test. */
            public function __construct() {}
        };
        add_action( 'mainwp_update_site', array( $settings, 'hook_mainwp_update_site' ) );
        $before = $this->snapshot();
        if ( $form ) {
            $method = new \ReflectionMethod( MainWP_Manage_Sites::class, 'update_site_handle' );
            $method->setAccessible( true );
            $this->assertSame( $accessible, $method->invoke( null, MainWP_DB::instance()->get_website_by_id_unscoped( $site ) ) );
            $website = MainWP_DB::instance()->get_website_by_id_unscoped( $site );
            $this->assertSame( $accessible ? 'Changed ordinary setting' : 'Test Site', $website->name );
            if ( $accessible ) {
                $this->assertSame( '1', $website->automatic_update );
                $this->assertSame( 'monitor@example.com', MainWP_DB::instance()->get_website_option( $site, 'monitoring_notification_emails' ) );
            }
        } else {
            $settings->hook_mainwp_update_site( $site );
        }
        $after = $this->snapshot();
        $this->assertSame( array( 'preserved' => 'original' ), get_option( 'mainwp_ajaxposts' ) );
        if ( $form && $accessible ) {
            // Ordinary site saves invalidate the site-list and update-list cache prefixes.
            $persistent = static function ( $row ) {
                return ! preg_match( '/^_transient_(timeout_)?mainwp_cache_(manage_sites|updates)_[0-9]+_prefix$/', $row['option_name'] );
            };
            $this->assertSame( array_values( array_filter( $before[0], $persistent ) ), array_values( array_filter( $after[0], $persistent ) ) );
        } else {
            $this->assertSame( $before[0], $after[0] );
        }
        if ( ! $accessible || ( ! $manage && ! $form ) ) {
            $this->assertSame( $before, $after );
        }
        foreach ( $mapping as $option => $values ) {
            $this->assertSame( $manage && $accessible ? $values[2] : 'original-' . $option, MainWP_DB::instance()->get_website_option( $site, $option ), $option );
        }
        $this->assertSame( 'original-app', MainWP_DB::instance()->get_website_option( $site, 'mainwp_3rd_party_app_id' ) );
        foreach ( $key_fields as $provider => $field ) {
            $expected = 'original-' . $provider;
            if ( $manage && $accessible && 'retain' !== $keys ) {
                $expected = 'clear' === $keys ? '' : 'fixture-' . $provider;
            }
            $stored = MainWP_DB::instance()->get_website_option( $site, 'mainwp_api_backups_' . $provider . '_api_key', '' );
            $envelope = array( 'encrypted_val' => $expected );
            if ( $manage && $accessible && 'replace' === $keys ) {
                $envelope['file_key'] = 'fixture-key-file';
            }
            $this->assertSame( '' === $expected ? '' : wp_json_encode( $envelope ), $stored, $field );
        }
    }

    /**
     * Discovery writes need a management right and each matched site's right.
     *
     * @return array Provider, entry point and rights matrix.
     */
    public static function mapping_discovery_actions(): array {
        $rows = array();
        foreach ( array( 'cloudways', 'gridpane' ) as $provider ) {
            foreach ( array( 'ajax', 'worker' ) as $entry ) {
                foreach ( array( 'denied', 'permitted', 'site-denied', 'hidden-only' ) as $rights ) {
                    $rows[ $provider . ' ' . $entry . ' ' . $rights ] = array( $provider, $entry, $rights );
                }
            }
        }
        return $rows;
    }

    /**
     * Use fixture provider replies to exercise the actual mapping writes.
     *
     * @dataProvider mapping_discovery_actions
     * @param string $provider Discovery provider.
     * @param string $entry AJAX callback or settings worker.
     * @param string $rights Granted access.
     */
    public function test_mapping_discovery( string $provider, string $entry, string $rights ): void {
        $this->rights( 'execute_backups', true, 'denied' === $rights ? array() : array( 'manage_api_backups' ) );
        $url     = 'mapping-' . $this->site . '.example.com';
        $staging = $this->create_test_site( array( 'url' => 'https://staging.' . $url . '/' ) );
        MainWP_DB::instance()->update_website_values( $this->site, array( 'url' => 'https://' . $url . '/' ) );
        $sites = array( $this->site, $staging );
        $this->filter(
            'mainwp_currentuserallowedaccesssites',
            static function () use ( $sites ) {
                return $sites;
            }
        );
        if ( 'site-denied' === $rights ) {
            $this->filter(
                'mainwp_currentusercan',
                static function ( $can, $type, $cap ) {
                    return 'site' === $type ? false : ( 'dashboard' === $type && in_array( $cap, array( 'execute_backups', 'manage_api_backups' ), true ) );
                }
            );
        }
        foreach ( array( $this->site, $staging, $this->foreign ) as $site ) {
            foreach ( array( 'mainwp_3rd_party_api', 'mainwp_3rd_party_instance_id', 'mainwp_3rd_party_app_id' ) as $option ) {
                MainWP_DB::instance()->update_website_option( $site, $option, 'original-' . $option );
            }
        }
        $handler         = new class() extends Rights_Api_Backups_3rd_Party {
            /**
             * Fixture provider sites.
             *
             * @var array
             */
            public static $sites = array();
            /**
             * Provider reads.
             *
             * @var int
             */
            public static $reads = 0;
            /** Direct calls do not need AJAX registrations. */
            public function __construct() {}
            /**
             * Supply the provider reply without network access.
             *
             * @return array Fixture Cloudways servers.
             */
            public static function fetch_cloudways_server_list() {
                ++self::$reads;
                return array( (object) array( 'apps' => self::$sites ) );
            }
            /**
             * Supply the provider reply without network access.
             *
             * @param int $sites_count Requested limit.
             * @return array Fixture GridPane sites.
             */
            public static function gridpane_get_sites_list( $sites_count ) {
                unset( $sites_count );
                ++self::$reads;
                return self::$sites;
            }
        };
        $foreign_url     = wp_parse_url( MainWP_DB::instance()->get_website_by_id_unscoped( $this->foreign )->url, PHP_URL_HOST );
        $handler::$reads = 0;
        $handler::$sites = array(
            (object) array(
                'url'       => $foreign_url,
                'cname'     => $foreign_url,
                'app_fqdn'  => $foreign_url,
                'id'        => 500,
                'server_id' => 600,
            ),
        );
        if ( 'hidden-only' !== $rights ) {
            $handler::$sites[] = (object) array(
                'url'                   => $url,
                'cname'                 => $url,
                'app_fqdn'              => $url,
                'id'                    => 700,
                'server_id'             => 800,
                'staging_site_built_at' => 'fixture',
            );
        }
        $action   = $provider . '_action_update_ids';
        $_POST    = array(
            'security' => wp_create_nonce( $action ),
            'dts'      => wp_generate_uuid4(),
        );
        $_REQUEST = $_POST;
        $before   = $this->snapshot();
        ob_start();
        try {
            $method = ( 'ajax' === $entry ? 'ajax_' : '' ) . $action;
            $handler->$method();
        } catch ( Api_Backups_Response_Stop $stop ) {
            // The AJAX gate returns JSON through the test-owned die handler.
        } finally {
            $body = ob_get_clean();
        }
        $after = $this->snapshot();
        if ( 'denied' === $rights ) {
            $this->assertSame( 0, $handler::$reads );
            $this->assertSame( $before, $after, 'A denied mapping write must preserve all options and nonce state.' );
            if ( 'ajax' === $entry ) {
                $this->assertStringContainsString( 'insufficient permissions', $body );
            }
        } else {
            $this->assertSame( 1, $handler::$reads );
            if ( 'permitted' !== $rights ) {
                $this->assertSame( $before[1], $after[1] );
            }
        }
        foreach ( array( $this->site, $staging, $this->foreign ) as $site ) {
            $writes   = 'permitted' === $rights && $site !== $this->foreign && ( 'gridpane' === $provider || $site === $this->site );
            $instance = 'cloudways' === $provider ? '800' : ( $site === $staging ? '701' : '700' );
            $this->assertSame( $writes ? ( 'cloudways' === $provider ? 'Cloudways' : 'GridPane' ) : 'original-mainwp_3rd_party_api', MainWP_DB::instance()->get_website_option( $site, 'mainwp_3rd_party_api' ) );
            $this->assertSame( $writes ? $instance : 'original-mainwp_3rd_party_instance_id', MainWP_DB::instance()->get_website_option( $site, 'mainwp_3rd_party_instance_id' ) );
            $this->assertSame( $writes && 'cloudways' === $provider ? '700' : 'original-mainwp_3rd_party_app_id', MainWP_DB::instance()->get_website_option( $site, 'mainwp_3rd_party_app_id' ) );
        }
    }

    /** Bulk workers must use the site authorized through websiteId. */
    public function test_selected_websites_ignores_conflicting_site_id(): void {
        $this->rights( 'execute_backups', true );
        $before = $this->snapshot();
        $reads  = array();
        $body   = $this->request(
            'ajax_backups_selected_websites',
            array( 'website_id' => (string) $this->foreign ),
            function ( $value, $site, $options ) use ( &$reads ) {
                $reads[] = array( (int) $site, $options );
                if ( 1 === count( $reads ) ) {
                    return array(
                        'mainwp_3rd_party_api'         => 'Vultr',
                        'mainwp_3rd_party_instance_id' => 'instance-' . $this->site,
                    );
                }
                throw new Api_Backups_Boundary_Stop();
            }
        );
        $this->assertSame( 'boundary', $body );
        $this->assertSame( 2, $this->hits );
        $this->assertSame( array( $this->site, $this->site ), array_column( $reads, 0 ), 'Every mapping read must target the authorized site.' );
        $this->assertSame( $before[1], $this->snapshot()[1], 'Site options, including the hidden site, must remain unchanged.' );
        $this->assertArrayNotHasKey( 'website_id', $_POST );
        $this->assertArrayNotHasKey( 'website_id', $_REQUEST );
    }

    /** Non-admins receive the same bare response before ownership checks. */
    public function test_subscriber_restore_does_not_disclose_ownership(): void {
        $this->rights( 'restore_backups', true );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
        $before    = $this->snapshot();
        $responses = array();
        foreach ( array( 'snapshot-owned', 'unlisted-snapshot' ) as $snapshot ) {
            $responses[] = $this->request( 'ajax_vultr_action_restore_backup', array( 'snapshot_id' => $snapshot ) );
            $this->assertSame( 0, $this->hits );
            $this->assertSame( $before, $this->snapshot(), 'Subscriber requests must preserve all options and nonce state.' );
        }
        $this->assertSame( array( '0', '0' ), $responses );
    }

    /** Automatic lists may normalize the path; the worker uses its configured path. */
    public function test_cpanel_automatic_restore_accepts_normalized_path(): void {
        $this->rights( 'restore_backups', true );
        MainWP_DB::instance()->update_website_option(
            $this->site,
            'mainwp_3rd_party_cpanel_available_backups',
            wp_json_encode(
                array(
                    'data' => array(
                        array(
                            'backupID' => '101',
                            'path'     => '/normalized/site',
                        ),
                    ),
                )
            )
        );
        $this->assertSame(
            'boundary',
            $this->request(
                'ajax_cpanel_action_restore_backup',
                array(
                    'dts'         => null,
                    'backup_path' => '/ignored/posted/path',
                )
            )
        );
        $this->assertSame( 1, $this->hits );
        $this->assertSame( $this->site, $this->target[0] );
    }

    /**
     * Malformed provider list fields must refuse without PHP errors.
     *
     * @return array Provider callback and malformed option matrix.
     */
    public static function malformed_lists(): array {
        return array(
            'kinsta'       => array( 'ajax_kinsta_action_restore_backup', 'kinsta', array( 'environment' => array( 'backups' => 'malformed' ) ) ),
            'digitalocean' => array( 'digitalocean_action_restore_backup', 'digitalocean', array( 'snapshots' => 'malformed' ) ),
            'vultr'        => array(
                'ajax_vultr_action_restore_backup',
                'vultr',
                array(
                    'snapshots' => array(),
                    'backups'   => 'malformed',
                ),
            ),
            'cpanel'       => array( 'ajax_cpanel_action_restore_backup', 'cpanel', array( 'data' => 'malformed' ) ),
        );
    }

    /**
     * Invalid nested values use the normal ownership refusal and preserve state.
     *
     * @dataProvider malformed_lists
     * @param string $method Callback.
     * @param string $provider Provider name.
     * @param array  $stored Malformed stored list.
     */
    public function test_malformed_nested_list( string $method, string $provider, array $stored ): void {
        $this->rights( 'restore_backups', true );
        MainWP_DB::instance()->update_website_option( $this->site, 'mainwp_3rd_party_' . $provider . '_available_backups', wp_json_encode( $stored ) );
        if ( 'vultr' === $provider ) {
            MainWP_DB::instance()->update_website_option( $this->site, 'mainwp_3rd_party_vultr_snapshot_list', '[]' );
        }
        $before = $this->snapshot();
        $body   = $this->request( $method );
        $this->assertSame( 0, $this->hits );
        $this->assertSame( $before, $this->snapshot() );
        $this->assertStringContainsString( 'Refresh', $body );
    }

    /** A known backup cannot authorize another path for the database restore. */
    public function test_database_path_must_match(): void {
        $this->rights( 'restore_backups', true );
        $this->refused( 'ajax_cpanel_action_restore_database_backup', array( 'backup_path' => '/another/site' ) );
    }

    /**
     * Raw site values that intval would otherwise turn into an accessible id.
     *
     * @return array Invalid input matrix.
     */
    public static function invalid_sites(): array {
        return array(
            'missing'  => array( null ),
            'array'    => array( array( '1' ) ),
            'suffix'   => array( 'suffix' ),
            'decimal'  => array( 'decimal' ),
            'signed'   => array( 'signed' ),
            'space'    => array( 'space' ),
            'all'      => array( '_ALL_' ),
            'zero'     => array( '0' ),
            'negative' => array( '-1' ),
        );
    }

    /**
     * Validate the raw site value before the worker's integer conversion.
     *
     * @dataProvider invalid_sites
     * @param mixed $input Raw site input.
     */
    public function test_invalid_site( $input ): void {
        $values = array(
            'suffix'  => $this->site . 'junk',
            'decimal' => $this->site . '.5',
            'signed'  => '+' . $this->site,
            'space'   => ' ' . $this->site,
        );
        $input  = is_string( $input ) && isset( $values[ $input ] ) ? $values[ $input ] : $input;
        $this->rights( 'execute_backups', true );
        $this->refused( 'ajax_cloudways_action_backup', array( 'website_id' => $input ) );
    }

    /** Invalid nonces stop before the boundary and preserve the deduplication option. */
    public function test_invalid_nonce(): void {
        $this->rights( 'execute_backups', true );
        $this->refused( 'ajax_cloudways_action_backup', array( 'security' => 'invalid' ) );
    }

    /** The existing automatic-restore worker verifies its nonce a second time. */
    public function test_automatic_restore_duplicate_nonce(): void {
        $this->rights( 'restore_backups', true );
        $this->assertSame( array( 'error' => 'Double request!' ), json_decode( $this->request( 'ajax_cpanel_action_restore_backup' ), true ) );
        $this->assertSame( 0, $this->hits );
    }

    /**
     * Populate only non-secret ownership and mapping fixtures.
     *
     * @param int    $site Site id.
     * @param string $id Backup id.
     * @param string $snapshot Snapshot id.
     */
    private function lists( int $site, string $id, string $snapshot ): void {
        $options = array(
            'mainwp_3rd_party_instance_id'              => 'instance-' . $site,
            'mainwp_3rd_party_app_id'                   => 'app-' . $site,
            'mainwp_3rd_party_api'                      => 'Cloudways',
            'mainwp_3rd_party_vultr_snapshot_list'      => wp_json_encode( array( $snapshot ) ),
            'mainwp_3rd_party_vultr_available_backups'  => wp_json_encode(
                array(
                    'snapshots' => array( 'snapshot' => array( 'id' => $snapshot ) ),
                    'backups'   => array( array( 'id' => 'automatic-' . $site ) ),
                )
            ),
            'mainwp_3rd_party_digitalocean_available_backups' => wp_json_encode( array( 'snapshots' => array( array( 'id' => $snapshot ) ) ) ),
            'mainwp_3rd_party_kinsta_available_backups' => wp_json_encode( array( 'environment' => array( 'backups' => array( array( 'id' => (int) $id ) ) ) ) ),
            'mainwp_enable_cpanel_individual'           => 'on',
            'cpanel_site_path'                          => '/home/account/site-' . $site,
            'mainwp_3rd_party_cpanel_available_backups' => wp_json_encode(
                array(
                    'data' => array(
                        array(
                            'backupID' => $id,
                            'path'     => '/home/account/site-' . $site,
                        ),
                    ),
                )
            ),
            'mainwp_3rd_party_cpanel_manual_database_backups' => wp_json_encode(
                array(
                    array(
                        'file_name'    => $id,
                        'absolute_dir' => '/home/account/site-' . $site,
                    ),
                )
            ),
            'mainwp_3rd_party_cpanel_manual_backups'    => wp_json_encode(
                array(
                    array(
                        'file_name'    => $id,
                        'absolute_dir' => '/home/account',
                    ),
                )
            ),
        );
        foreach ( $options as $name => $value ) {
            $this->set_site_option( $site, $name, $value );
        }
    }

    /**
     * Assert the actual Team Control right rather than WP capabilities.
     *
     * @param string $right Required right.
     * @param bool   $allowed Grant.
     * @param array  $additional Other granted Dashboard rights.
     */
    private function rights( string $right, bool $allowed, array $additional = array() ): void {
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $cap ) use ( $right, $allowed, $additional ) {
                if ( 'site' === $type ) {
                    $scope = apply_filters( 'mainwp_currentuserallowedaccesssites', 'all' );
                    return 'all' === $scope || in_array( (int) $cap, $scope, true );
                }
                return 'dashboard' === $type ? ( ( $cap === $right && $allowed ) || in_array( $cap, $additional, true ) ) : $can;
            }
        );
        $this->assertFalse( defined( 'MAINWP_REST_API_DOING' ), 'Run this test before REST tests define MAINWP_REST_API_DOING.' );
        $this->assertSame( $allowed, \mainwp_current_user_can( 'dashboard', $right ) );
    }

    /**
     * Restrict SQL enumeration and the per-site right together.
     *
     * @param bool $all Unrestricted.
     */
    private function scope( bool $all ): void {
        $site = $this->site;
        $this->filter(
            'mainwp_currentuserallowedaccesssites',
            static function () use ( $all, $site ) {
                return $all ? 'all' : array( $site );
            }
        );
    }

    /**
     * Refusals must preserve all options, including mainwp_ajaxposts.
     *
     * @param string $method Callback.
     * @param array  $post Overrides.
     */
    private function refused( string $method, array $post = array() ): void {
        $before   = $this->snapshot();
        $response = json_decode( $this->request( $method, $post ), true );
        $this->assertSame( 0, $this->hits, 'Reached a credential, mapping or transport boundary.' );
        $this->assertSame( $before, $this->snapshot(), 'A refused action changed stored state.' );
        $this->assertIsArray( $response );
        $this->assertNotEmpty( $response['error'] ?? null );
    }

    /**
     * Complete raw option and site-option state.
     *
     * @return array Stored state.
     */
    private function snapshot(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Complete raw snapshots detect unauthorized writes, including secure_request deduplication.
        $options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A );
        $table   = $wpdb->prefix . 'mainwp_wp_options';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table derives only from the configured test prefix.
        return array( $options, $wpdb->get_results( "SELECT wpid, name, value FROM {$table} ORDER BY wpid, name", ARRAY_A ) );
    }

    /**
     * Stop at the first boundary and capture all termination paths.
     *
     * @param string        $method Callback.
     * @param array         $post Overrides.
     * @param callable|null $mapping Optional mapping read callback.
     * @return string Response.
     * @throws \Error Unexpected worker error.
     * @throws \PHPUnit\Framework\Error\Warning Unexpected worker warning.
     */
    private function request( string $method, array $post = array(), ?callable $mapping = null ): string {
        $actions = array_column( self::actions(), 0 );
        $action  = array_keys( self::actions() )[ array_search( $method, $actions, true ) ];
        $_POST   = array_merge(
            array(
                'website_id'  => (string) $this->site,
                'websiteId'   => (string) $this->site,
                'snapshot_id' => 'snapshot-owned',
                'backup_id'   => '101',
                'backup_path' => '/home/account/site-' . $this->site,
                'backup_name' => 'backup-owned',
                'backup_type' => 'manual',
                'backup_date' => '2026-10-12',
                'backup_api'  => 'vultr',
                'security'    => wp_create_nonce( $action ),
                'dts'         => wp_generate_uuid4(),
            ),
            $post
        );
        if ( in_array( $method, array( 'ajax_cloudways_action_update_ids', 'ajax_gridpane_action_update_ids', 'digitalocean_action_delete_backup' ), true ) ) {
            unset( $_POST['website_id'], $_POST['websiteId'] );
        }
        $_REQUEST     = $_POST;
        $this->hits   = 0;
        $this->target = array();
        $this->filter(
            'mainwp_getwebsiteoptions',
            function ( $value, $site, $options ) use ( $mapping ) {
                ++$this->hits;
                $this->target = array( is_array( $site ) ? (int) ( $site['id'] ?? 0 ) : ( is_object( $site ) ? (int) $site->id : (int) $site ), $options );
                if ( null !== $mapping ) {
                    return $mapping( $value, $site, $options );
                }
                throw new Api_Backups_Boundary_Stop();
            }
        );
        $this->filter(
            'mainwp_fetch_url_authed_pre',
            function () {
                ++$this->hits;
                throw new Api_Backups_Boundary_Stop();
            }
        );
        $names = array( 'mainwp_cloudways_api_account_email', 'mainwp_cpanel_url', 'mainwp_cpanel_account_username', 'mainwp_cpanel_site_path', 'mainwp_plesk_api_url', 'mainwp_kinsta_api_account_email', 'mainwp_kinsta_company_id', 'mainwp_api_backups_cloudways_access_token' );
        foreach ( array( 'cloudways', 'gridpane', 'vultr', 'linode', 'digitalocean', 'cpanel', 'plesk', 'kinsta' ) as $provider ) {
            $names[] = 'mainwp_api_backups_' . $provider . '_api_key';
        }
        foreach ( $names as $name ) {
            $this->filter(
                'pre_option_' . $name,
                function () {
                    ++$this->hits;
                    throw new Api_Backups_Boundary_Stop();
                }
            );
        }
        ob_start();
        $body = '';
        try {
            $handler = 'ajax_backups_selected_websites' === $method ? new Rights_Api_Backups_Handler() : new Rights_Api_Backups_3rd_Party();
            $handler->$method();
        } catch ( Api_Backups_Boundary_Stop $stop ) {
            $body = 'boundary';
        } catch ( Api_Backups_Response_Stop $stop ) {
            $body = $stop->getMessage();
        } catch ( \Error $error ) {
            if ( 'ajax_cloudways_action_individual_backup' !== $method ) {
                throw $error;
            }
            $body = $error->getMessage();
        } catch ( \PHPUnit\Framework\Error\Warning $warning ) {
            if ( 'ajax_cpanel_action_create_database_backup' !== $method || 'Undefined array key "result"' !== $warning->getMessage() ) {
                throw $warning;
            }
            $body = wp_json_encode( array( 'error' => $warning->getMessage() ) );
        } finally {
            $buffer = ob_get_clean();
        }
        return $buffer . $body;
    }

    /**
     * Selected-websites uses its existing camel-case payload.
     *
     * @param string $method Callback.
     * @return string Site field.
     */
    private function site_field( string $method ): string {
        return 'ajax_backups_selected_websites' === $method ? 'websiteId' : 'website_id';
    }

    /**
     * Replace only test-owned callbacks.
     *
     * @param string   $hook Hook name.
     * @param callable $callback Callback.
     */
    private function filter( string $hook, callable $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 'mainwp_getwebsiteoptions' === $hook ? -999 : 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 'mainwp_getwebsiteoptions' === $hook ? -999 : 999, 3 );
    }

    /**
     * AJAX exit capture.
     *
     * @return callable Die handler.
     */
    public function ajax_die_handler(): callable {
        return static function ( $message = '' ) {
            throw new Api_Backups_Response_Stop( (string) $message );
        };
    }
}
