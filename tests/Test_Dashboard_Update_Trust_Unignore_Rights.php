<?php
/**
 * Update nonces must not bypass Team Control rights or site scope.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- A distinct Error captures legacy exits without changing production handlers.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Fixtures exercise production nonce checks rather than handling live requests.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve response bodies for assertions without browser output.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read production source to capture its legacy exits.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Read actual storage without scoped lookups hiding refused writes.

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\Update_Rights_Post_Handler;

/** AJAX termination must escape legacy Exception handlers. */
class Update_Rights_Response_Stop extends \Error {}

/** Preserve update state when an operation right or site access is refused. */
class Test_Dashboard_Update_Trust_Unignore_Rights extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Test-owned callbacks.
     *
     * @var array
     */
    private $filters = array();

    /**
     * Fixture site IDs.
     *
     * @var array
     */
    private $sites = array();

    /**
     * Request globals and current user before the fixture.
     *
     * @var array
     */
    private $request_state = array();

    /** Raw exits would prevent assertions against the unchanged handlers. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $code = '';
        foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-post-plugin-theme-handler.php' ) ) as $token ) {
            if ( is_array( $token ) ) {
                $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && 'MainWP_Post_Plugin_Theme_Handler' === $token[1] ? 'Update_Rights_Post_Handler' : $token[1] );
            } else {
                $code .= $token;
            }
        }
        $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
        eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture legacy exits while retaining real authorization and storage writes.
    }

    /** Team Control users retain WordPress administrator capabilities. */
    public function setUp(): void {
        parent::setUp();
        $this->request_state = array( $_GET, $_POST, $_REQUEST, get_current_user_id() );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        wp_create_nonce( 'mainwp-update-rights-fixture' );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        $this->sites = array( $this->create_test_site(), $this->create_test_site() );
        update_option( 'mainwp_ajaxposts', array( 'preserve' => 'original' ) );
    }

    /** Restore callbacks and request state; WordPress rolls back fixture rows. */
    public function tearDown(): void {
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        list( $_GET, $_POST, $_REQUEST, $user_id ) = $this->request_state;
        wp_set_current_user( $user_id );
        parent::tearDown();
    }

    /**
     * Action variants with their storage location and supported operation.
     *
     * @return array Action variants.
     */
    private static function actions(): array {
        $actions = array();
        foreach ( array( 'plugin', 'theme' ) as $type ) {
            foreach ( array( 'trust', 'untrust' ) as $operation ) {
                $actions[ "$type $operation" ] = array( "mainwp_trust_$type", "mainwp_trust_$type", $type, $operation, false );
            }
            foreach ( array( 'single', 'all' ) as $operation ) {
                $actions[ "global $type unignore $operation" ] = array( 'mainwp_unignorepluginsthemes', 'mainwp_unignore_global_pluginsthemes', $type, $operation, false );
                $actions[ "global $type abandoned $operation" ] = array( 'mainwp_unignoreabandonedpluginsthemes', 'mainwp_unignoreabandonedpluginsthemes', $type, $operation, false );
            }
            $actions[ "site $type ignore" ]    = array( 'mainwp_ignoreplugintheme', 'mainwp_ignoreplugintheme', $type, 'ignore', true );
            $actions[ "site $type dismiss" ]   = array( 'mainwp_dismissoutdateplugintheme', 'mainwp_dismissoutdateplugintheme', $type, 'dismiss', true );
            $actions[ "site $type unignore" ]  = array( 'mainwp_unignoreplugintheme', 'mainwp_unignoreplugintheme', $type, 'single', true );
            $actions[ "site $type abandoned" ] = array( 'mainwp_unignoreabandonedplugintheme', 'mainwp_unignoreabandonedplugintheme', $type, 'single', true );
        }
        $actions['site core unignore']   = array( 'mainwp_updates_unignore_upgrades', 'ajax_unignore_core_updates', 'core', 'single', true );
        $actions['global core unignore'] = array( 'mainwp_updates_unignore_global_upgrades', 'ajax_unignore_global_upgrades', 'core', 'all', false );
        return $actions;
    }

    /**
     * Each action with its right granted and refused.
     *
     * @return array Action and right cases.
     */
    public static function action_rights(): array {
        $cases = array();
        foreach ( self::actions() as $name => $action ) {
            foreach ( array( 'refused' => false, 'allowed' => true ) as $label => $allowed ) {
                $cases[ "$name $label" ] = array( $action, $allowed );
            }
        }
        return $cases;
    }

    /**
     * Numeric site actions exercised by a restricted administrator.
     *
     * @return array Numeric site cases.
     */
    public static function numeric_sites(): array {
        $cases = array();
        foreach ( self::actions() as $name => $action ) {
            if ( $action[4] ) {
                foreach ( array( 'out of scope' => false, 'in scope' => true ) as $label => $in_scope ) {
                    $cases[ "$name $label" ] = array( $action, $in_scope );
                }
            }
        }
        return $cases;
    }

    /**
     * Site ID spellings other than plain digits, for an in-scope and an out-of-scope site.
     *
     * The handlers read these through intval() or sanitize_text_field(), which
     * can name a different site than the one a permission check would read.
     *
     * @return array Spelling cases; %d is the site ID.
     */
    public static function site_id_spellings(): array {
        $spellings = array(
            'trailing text'  => '%dabc',
            'markup'         => '<b>%d</b>',
            'split markup'   => '1<b>%d</b>',
            'percent octet'  => '%d%%41',
            'leading octet'  => '1%%41%d',
            'leading space'  => ' %d',
            'plus sign'      => '+%d',
            'empty'          => '',
            'array'          => array( '%d' ),
        );
        $cases     = array();
        foreach ( self::actions() as $name => $action ) {
            if ( $action[4] ) {
                foreach ( array( 'out of scope' => false, 'in scope' => true ) as $scope => $in_scope ) {
                    foreach ( $spellings as $label => $spelling ) {
                        $cases[ "$name $scope $label" ] = array( $action, $spelling, $in_scope );
                    }
                }
            }
        }
        return $cases;
    }

    /**
     * Only the unignore site routes support the _ALL_ sentinel.
     *
     * @return array Scoped bulk cases.
     */
    public static function all_sites(): array {
        $cases = array();
        foreach ( self::actions() as $name => $action ) {
            if ( $action[4] && 'single' === $action[3] ) {
                foreach ( array( 'refused' => false, 'allowed' => true ) as $label => $allowed ) {
                    $cases[ "$name _ALL_ $label" ] = array( $action, $allowed );
                }
            }
        }
        return $cases;
    }

    /**
     * Valid nonces cannot replace the operation right.
     *
     * @dataProvider action_rights
     * @param array $action Action specification.
     * @param bool  $allowed Operation right.
     */
    public function test_action_requires_update_right( array $action, bool $allowed ): void {
        $this->seed( $action );
        $this->rights( $action, $allowed );
        $before   = $this->snapshot();
        $response = $this->ajax( $action, (string) $this->sites[0] );
        if ( $allowed ) {
            $this->assert_saved( $action, $before, $this->sites[0] );
            $this->assertSame( array( 'result' => false !== strpos( $action[0], 'mainwp_trust_' ) ? true : 'success' ), $response );
        } else {
            $this->assertSame( $before, $this->snapshot(), 'Refusal changed stored update or request state.' );
            $this->assert_refused( $response );
        }
    }

    /**
     * Numeric IDs require site access even when the operation right is granted.
     *
     * @dataProvider numeric_sites
     * @param array $action Action specification.
     * @param bool  $in_scope Whether the posted site is accessible.
     */
    public function test_numeric_site_requires_scope( array $action, bool $in_scope ): void {
        $this->seed( $action );
        $this->rights( $action, true, array( $this->sites[0] ) );
        $site     = $this->sites[ $in_scope ? 0 : 1 ];
        $before   = $this->snapshot();
        $response = $this->ajax( $action, (string) $site );
        if ( $in_scope ) {
            $this->assert_saved( $action, $before, $site );
            $this->assertSame( array( 'result' => 'success' ), $response );
        } else {
            $this->assertSame( $before, $this->snapshot(), 'Out-of-scope refusal changed stored state.' );
            $this->assert_refused( $response );
        }
    }

    /**
     * Site IDs other than plain digits or _ALL_ are refused before the request is recorded.
     *
     * @dataProvider site_id_spellings
     * @param array        $action Action specification.
     * @param string|array $spelling ID spelling; %d is the site ID.
     * @param bool         $in_scope Whether the spelled site is accessible.
     */
    public function test_site_id_spelling_is_refused( array $action, $spelling, bool $in_scope ): void {
        $this->seed( $action );
        $this->rights( $action, true, array( $this->sites[0] ) );
        $site     = $this->sites[ $in_scope ? 0 : 1 ];
        $id       = is_array( $spelling ) ? array( sprintf( $spelling[0], $site ) ) : sprintf( $spelling, $site );
        $before   = $this->snapshot();
        $response = $this->ajax( $action, $id );
        $this->assertSame( $before, $this->snapshot(), 'Refused site ID spelling changed stored or request state.' );
        $this->assert_refused( $response );
    }

    /**
     * Bulk unignore keeps the worker's current scoped expansion.
     *
     * @dataProvider all_sites
     * @param array $action Action specification.
     * @param bool  $allowed Operation right.
     */
    public function test_all_sites_preserves_scope( array $action, bool $allowed ): void {
        $this->seed( $action );
        $this->rights( $action, $allowed, array( $this->sites[0] ) );
        $before   = $this->snapshot();
        $response = $this->ajax( $action, '_ALL_' );
        if ( $allowed ) {
            $action[3] = 'all';
            $this->assert_saved( $action, $before, $this->sites[0] );
            $this->assertSame( array( 'result' => 'success' ), $response );
        } else {
            $this->assertSame( $before, $this->snapshot(), 'Refused bulk action changed stored state.' );
            $this->assert_refused( $response );
        }
    }

    /**
     * Populate every trust, ignore and dismiss store so refusals detect writes.
     *
     * @param array $action Action specification.
     */
    private function seed( array $action ): void {
        $extension = (array) MainWP_DB_Common::instance()->get_user_extension();
        foreach ( array( 'plugin', 'theme' ) as $type ) {
            $slug = 'plugin' === $type ? 'fixture/plugin.php' : 'fixture-theme';
            $list = array( $slug => array( 'Name' => 'Fixture', 'ignored_versions' => array( '1.0', '2.0' ) ), 'keep' => 'Keep' );
            $dismissed = array( $slug => 'Fixture', 'keep' => 'Keep' );
            $trusted   = 'untrust' === $action[3] ? array( 'keep', $slug ) : array( 'keep' );
            $extension[ "trusted_{$type}s" ]   = wp_json_encode( $trusted );
            $extension[ "ignored_{$type}s" ]   = wp_json_encode( $list );
            $extension[ "dismissed_{$type}s" ] = wp_json_encode( $dismissed );
            if ( 'ignore' === $action[3] ) {
                unset( $list[ $slug ] );
            }
            if ( 'dismiss' === $action[3] ) {
                unset( $dismissed[ $slug ] );
            }
            foreach ( $this->sites as $site ) {
                MainWP_DB::instance()->update_website_values( $site, array( "ignored_{$type}s" => wp_json_encode( $list ) ) );
                MainWP_DB::instance()->update_website_option( $site, "{$type}s_outdate_dismissed", wp_json_encode( $dismissed ) );
            }
        }
        $extension['ignored_wp_upgrades'] = wp_json_encode( array( 'ignored_versions' => array( '1.0', '2.0' ) ) );
        MainWP_DB_Common::instance()->update_user_extension( $extension );
        foreach ( $this->sites as $site ) {
            MainWP_DB::instance()->update_website_option( $site, 'ignored_wp_upgrades', $extension['ignored_wp_upgrades'] );
        }
    }

    /**
     * Vary MainWP rights and scope while retaining administrator capabilities.
     *
     * @param array      $action Action specification.
     * @param bool       $allowed Operation right.
     * @param array|null $sites Accessible sites, or null for unrestricted access.
     */
    private function rights( array $action, bool $allowed, ?array $sites = null ): void {
        $right = false !== strpos( $action[0], 'mainwp_trust_' ) ? 'trust_untrust_updates' : 'ignore_unignore_updates';
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $cap ) use ( $right, $allowed, $sites ) {
                if ( 'dashboard' === $type && $right === $cap ) {
                    return $allowed;
                }
                return 'site' === $type && null !== $sites ? in_array( (int) $cap, $sites, true ) : $can;
            }
        );
        $this->filter(
            'mainwp_currentuserallowedaccesssites',
            static function () use ( $sites ) {
                return null === $sites ? 'all' : $sites;
            }
        );
        // REST tests make every right pass for the rest of the process; this file must run before them.
        $this->assertFalse( defined( 'MAINWP_REST_API_DOING' ), 'Run this class before the REST tests.' );
        $this->assertTrue( current_user_can( 'manage_options' ) );
        $this->assertSame( $allowed, \mainwp_current_user_can( 'dashboard', $right ) );
        if ( null !== $sites ) {
            $this->assertTrue( \mainwp_current_user_can( 'site', $this->sites[0] ) );
            $this->assertFalse( \mainwp_current_user_can( 'site', $this->sites[1] ) );
        }
    }

    /**
     * Replace only test-owned callbacks.
     *
     * @param string   $hook Filter name.
     * @param callable $callback Callback.
     */
    private function filter( string $hook, callable $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 3 );
    }

    /**
     * Read global and per-site stores without applying the user's site scope.
     *
     * @return array Stored state.
     */
    private function snapshot(): array {
        global $wpdb;
        $columns = 'trusted_plugins, trusted_themes, ignored_plugins, ignored_themes, dismissed_plugins, dismissed_themes, ignored_wp_upgrades';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column names and table prefix are fixed test infrastructure.
        $global = $wpdb->get_row( "SELECT $columns FROM {$wpdb->prefix}mainwp_users WHERE userid = 0", ARRAY_A );
        $state  = array( 'global' => $global, 'sites' => array(), 'ajaxposts' => get_option( 'mainwp_ajaxposts' ) );
        foreach ( $this->sites as $site ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT ignored_plugins, ignored_themes FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $site ), ARRAY_A );
            $options = $wpdb->get_results( $wpdb->prepare( "SELECT name, value FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid = %d AND name IN ('ignored_wp_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed') ORDER BY name", $site ), ARRAY_A );
            foreach ( $options as $option ) {
                $row[ $option['name'] ] = $option['value'];
            }
            $state['sites'][ $site ] = $row;
        }
        return $state;
    }

    /**
     * Assert the intended store change and preservation of every other store.
     *
     * @param array $action Action specification.
     * @param array $before Initial state.
     * @param int   $site Target site.
     */
    private function assert_saved( array $action, array $before, int $site ): void {
        list( $ajax, $method, $type, $operation, $per_site ) = $action;
        $slug = 'plugin' === $type ? 'fixture/plugin.php' : 'fixture-theme';
        if ( 'core' === $type ) {
            $column = 'ignored_wp_upgrades';
            $value  = 'all' === $operation ? array() : array( 'ignored_versions' => array() );
        } elseif ( in_array( $operation, array( 'trust', 'untrust' ), true ) ) {
            $column = "trusted_{$type}s";
            $value  = 'trust' === $operation ? array( 'keep', $slug ) : array( 'keep' );
        } else {
            $abandoned = false !== strpos( $ajax, 'abandoned' ) || 'dismiss' === $operation;
            $column = $abandoned ? ( $per_site ? "{$type}s_outdate_dismissed" : "dismissed_{$type}s" ) : "ignored_{$type}s";
            $value  = 'all' === $operation ? array() : array( 'keep' => 'Keep' );
            if ( 'ignore' === $operation ) {
                $value[ $slug ] = array( 'Name' => 'Fixture', 'ignored_versions' => array( '1.0' ) );
            } elseif ( 'dismiss' === $operation ) {
                $value[ $slug ] = 'Fixture';
            }
        }
        if ( $per_site ) {
            $before['sites'][ $site ][ $column ] = wp_json_encode( $value );
        } else {
            $before['global'][ $column ] = wp_json_encode( $value );
        }
        $before['ajaxposts'][ $ajax ] = $_POST['dts'];
        $this->assertSame( $before, $this->snapshot() );
    }

    /**
     * Permission errors retain the existing JSON response shape.
     *
     * @param array $response Response.
     */
    private function assert_refused( array $response ): void {
        $this->assertSame( array( 'error' => false ), $response );
    }

    /**
     * Exercise real source with a valid AJAX nonce and captured termination.
     *
     * @param array        $action Action specification.
     * @param string|array $id Site ID, _ALL_, or another posted spelling.
     * @return array Decoded response.
     */
    private function ajax( array $action, $id ): array {
        list( $ajax, $method, $type, $operation ) = $action;
        $slug = 'plugin' === $type ? 'fixture/plugin.php' : 'fixture-theme';
        $_POST = array(
            'security'   => wp_create_nonce( $ajax ),
            'dts'        => wp_generate_uuid4(),
            'id'         => $id,
            'type'       => $type,
            'slug'       => 'all' === $operation || '_ALL_' === $id ? '_ALL_' : $slug,
            'slugs'      => array( $slug ),
            'do'         => $operation,
            'name'       => 'Fixture',
            'ignore_ver' => 'ignore' === $operation ? '1.0' : 'all_versions',
        );
        $_REQUEST = $_POST;
        $this->filter( 'wp_doing_ajax', '__return_true' );
        $this->filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        ob_start();
        $body = '';
        try {
            Update_Rights_Post_Handler::instance()->$method();
        } catch ( Update_Rights_Response_Stop $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
        }
        $response_body = $buffer . $body;
        // The existing permission helper echoes HTML before returning false to the JSON error field.
        if ( 0 === strpos( $response_body, '<div class="mainwp-permission-error">' ) ) {
            $response_body = substr( $response_body, strpos( $response_body, '</div>' ) + 6 );
        }
        $response = json_decode( $response_body, true );
        $this->assertIsArray( $response, 'Expected a JSON response; verify the nonce and AJAX context.' );
        return $response;
    }

    /**
     * AJAX termination callback.
     *
     * @return callable AJAX termination callback.
     */
    public function ajax_die_handler(): callable {
        return static function ( $message ) {
            throw new Update_Rights_Response_Stop( (string) $message );
        };
    }
}
