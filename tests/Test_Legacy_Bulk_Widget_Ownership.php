<?php
/**
 * Ownership boundaries in legacy bulk forms and connection rows.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connection_Status;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Hooks;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Transport and exit capture belong to this fixture.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Match the existing test file naming convention.
// phpcs:disable WordPress.Security.NonceVerification -- Requests exercise the production nonce checks.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fixture rows live only in the isolated test database.

/** Escape legacy exits without changing request guards. */
class Legacy_Bulk_Response_Stop extends \Error {}

/** Intercept only the Child transport after production target selection. */
class Legacy_Bulk_Transport {
    /**
     * Recorded Child commands and targets.
     *
     * @var array
     */
    public static $requests = array();

    /**
     * Return a successful fixture Child response for every selected target.
     *
     * @param array  $sites Selected sites.
     * @param string $command Child command.
     * @param array  $params Child payload.
     * @param mixed  $handler Response handler.
     * @param object $output Response collection.
     */
    public static function fetch_urls_authed( &$sites, $command, $params, $handler, &$output ) {
        self::$requests[] = array(
            'command' => $command,
            'ids'     => array_map( 'intval', array_keys( $sites ) ),
        );
        foreach ( $sites as $site ) {
            $output->ok[ $site->id ]       = 1;
            $output->added_id[ $site->id ] = 123;
            $output->link[ $site->id ]     = 'https://fixture.example/post/123';
        }
    }
}

/** Execute the real selection, nonce, SQL and response paths. */
class Test_Legacy_Bulk_Widget_Ownership extends \WP_UnitTestCase {
    use Site_Fixture;

    /**
     * Current owner.
     *
     * @var int
     */
    private $owner;
    /**
     * Own site.
     *
     * @var int
     */
    private $own_site;
    /**
     * Other owner's site.
     *
     * @var int
     */
    private $foreign_site;
    /**
     * Shared group.
     *
     * @var int
     */
    private $group;
    /**
     * Shared client.
     *
     * @var int
     */
    private $client;

    /** Capture exits and replace only the outbound transport in source copies. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'MainWP_Post_Page_Handler'           => 'Legacy_Ownership_Post_Handler',
            'MainWP_Bulk_Update_Admin_Passwords' => 'Legacy_Ownership_Admin_Passwords',
            'MainWP_Connect'                     => 'Legacy_Bulk_Transport',
        );
        foreach ( array( 'pages/page-mainwp-post-page-handler.php', 'pages/page-mainwp-bulk-update-admin-passwords.php' ) as $file ) {
            $code = '';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local production source for exit capture.
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] ? strtr( $token[1], $names ) : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            $code = str_replace( 'namespace MainWP\\Dashboard;', 'namespace MainWP\\Dashboard; use MainWP\\Dashboard\\Tests\\Legacy_Bulk_Transport;', $code );
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Execute current source with capturable exits and no real Child transport.
        }
    }

    /** Two administrators each own one connected site. */
    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $other       = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->owner );
        $this->own_site     = $this->create_test_site(
            array(
                'userid' => $this->owner,
                'name'   => 'Owned fixture site',
            )
        );
        $this->foreign_site = $this->create_test_site(
            array(
                'userid' => $other,
                'name'   => 'Foreign fixture site',
            )
        );
        $wpdb->insert(
            $wpdb->prefix . 'mainwp_group',
            array(
                'userid' => $this->owner,
                'name'   => 'Ownership fixture',
            )
        );
        $this->group  = (int) $wpdb->insert_id;
        $this->client = 321;
        foreach ( array( $this->own_site, $this->foreign_site ) as $site ) {
            $wpdb->insert(
                $wpdb->prefix . 'mainwp_wp_group',
                array(
                    'wpid'    => $site,
                    'groupid' => $this->group,
                )
            );
            $wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'client_id' => $this->client ), array( 'id' => $site ) );
        }
        add_filter( 'mainwp_is_single_user', '__return_false' );
        Legacy_Bulk_Transport::$requests = array();
        $_POST                           = array();
        $_GET                            = array();
        $_REQUEST                        = array();
    }

    /** Restore request and ownership context. */
    public function tear_down(): void {
        remove_filter( 'mainwp_is_single_user', '__return_false' );
        $_POST    = array();
        $_GET     = array();
        $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * Capture the response without bypassing production authorization.
     *
     * @param callable $callback Handler.
     * @return string Response.
     */
    private function response( callable $callback ): string {
        $die = static function () {
            return static function ( $message = '' ) {
                throw new Legacy_Bulk_Response_Stop( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact response for assertions.
            };
        };
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', $die );
        ob_start();
        $message = '';
        try {
            $callback();
        } catch ( Legacy_Bulk_Response_Stop $stop ) {
            $message = $stop->getMessage();
        } finally {
            $body = ob_get_clean();
            remove_filter( 'wp_doing_ajax', '__return_true' );
            remove_filter( 'wp_die_ajax_handler', $die );
        }
        return $body . $message;
    }

    /**
     * Build a real nonce-checked request.
     *
     * @param string $action Nonce action.
     * @param array  $data Form data.
     */
    private function request( string $action, array $data ): void {
        $_POST    = array_merge( $data, array( 'security' => wp_create_nonce( $action ) ) );
        $_REQUEST = $_POST;
    }

    /**
     * Make a bulk post with supported target metadata.
     *
     * @param string $selection Selection type.
     * @return int Post ID.
     */
    private function bulk_post( string $selection ): int {
        $id = self::factory()->post->create( array( 'post_title' => 'Ownership fixture post' ) );
        update_post_meta( $id, '_selected_by', $selection );
        update_post_meta( $id, '_selected_sites', array( (string) $this->own_site, (string) $this->foreign_site ) );
        update_post_meta( $id, '_selected_groups', array( (string) $this->group ) );
        update_post_meta( $id, '_selected_clients', array( (string) $this->client ) );
        update_post_meta( $id, '_bulkpost_do_not_del', 'yes' );
        return $id;
    }

    /** AJAX ignores a foreign target while allowed cases still send newpost. */
    public function test_post_ajax_ownership(): void {
        $id = $this->bulk_post( 'site' );
        $this->request(
            'mainwp_post_postingbulk',
            array(
                'post_id' => $id,
                'site_id' => $this->foreign_site,
            )
        );
        $body = $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Post_Handler', 'ajax_posting_posts' ) );
        $this->assertSame( array(), Legacy_Bulk_Transport::$requests );
        $this->assertIsArray( json_decode( $body, true ) );
        $_POST['site_id'] = $this->own_site;
        $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Post_Handler', 'ajax_posting_posts' ) );
        $this->assertSame( array( $this->own_site ), Legacy_Bulk_Transport::$requests[0]['ids'] );
        remove_filter( 'mainwp_is_single_user', '__return_false' );
        $_POST['site_id'] = $this->foreign_site;
        $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Post_Handler', 'ajax_posting_posts' ) );
        $this->assertSame( array( $this->foreign_site ), Legacy_Bulk_Transport::$requests[1]['ids'] );
    }

    /** Owned AJAX targets and the single-user default still publish. */
    public function test_post_ajax_allowed_controls(): void {
        $id = $this->bulk_post( 'site' );
        $this->request(
            'mainwp_post_postingbulk',
            array(
                'post_id' => $id,
                'site_id' => $this->own_site,
            )
        );
        $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Post_Handler', 'ajax_posting_posts' ) );
        $this->assertSame( array( $this->own_site ), Legacy_Bulk_Transport::$requests[0]['ids'] );
        remove_filter( 'mainwp_is_single_user', '__return_false' );
        $_POST['site_id'] = $this->foreign_site;
        $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Post_Handler', 'ajax_posting_posts' ) );
        $this->assertSame( array( $this->foreign_site ), Legacy_Bulk_Transport::$requests[1]['ids'] );
    }

    /**
     * Supported bulk selections.
     *
     * @return array Selection cases.
     */
    public static function selections(): array {
        return array(
            'site'   => array( 'site' ),
            'group'  => array( 'group' ),
            'client' => array( 'client' ),
        );
    }

    /**
     * Every UI selection keeps owned sites and skips foreign targets.
     *
     * @dataProvider selections
     * @param string $selection Selection type.
     */
    public function test_post_form_selections( string $selection ): void {
        $id   = $this->bulk_post( $selection );
        $_GET = array(
            'id'            => $id,
            'posting_nonce' => wp_create_nonce( 'posting_nonce_' . $id ),
        );
        $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Post_Handler', 'posting_bulk' ) );
        $this->assertSame( array( $this->own_site ), Legacy_Bulk_Transport::$requests[0]['ids'], $selection );
    }

    /** Single-user form posting keeps every selected target. */
    public function test_single_user_post_form(): void {
        remove_filter( 'mainwp_is_single_user', '__return_false' );
        $id   = $this->bulk_post( 'group' );
        $_GET = array(
            'id'            => $id,
            'posting_nonce' => wp_create_nonce( 'posting_nonce_' . $id ),
        );
        $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Post_Handler', 'posting_bulk' ) );
        $this->assertEqualsCanonicalizing( array( $this->own_site, $this->foreign_site ), Legacy_Bulk_Transport::$requests[0]['ids'] );
    }

    /** The preparation UI also omits foreign targets. */
    public function test_post_preparation_ownership(): void {
        $id   = $this->bulk_post( 'group' );
        $_GET = array(
            'id'            => $id,
            'posting_nonce' => wp_create_nonce( 'posting_nonce_' . $id ),
        );
        add_filter( 'mainwp_posts_posting_bulk_sites', '__return_true' );
        try {
            $body = $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Post_Handler', 'posting_bulk' ) );
        } finally {
            remove_filter( 'mainwp_posts_posting_bulk_sites', '__return_true' );
        }
        $this->assertStringContainsString( 'Owned fixture site', $body );
        $this->assertStringNotContainsString( 'Foreign fixture site', $body );
        $this->assertSame( array(), Legacy_Bulk_Transport::$requests );
    }

    /** The two-argument shared posting path preserves userless extension use. */
    public function test_userless_posting_keeps_its_targets(): void {
        $id = $this->bulk_post( 'site' );
        wp_set_current_user( 0 );
        $this->response(
            static function () use ( $id ) {
                \MainWP\Dashboard\Legacy_Ownership_Post_Handler::posting_posts( $id, 'posting' );
            }
        );
        $this->assertEqualsCanonicalizing( array( $this->own_site, $this->foreign_site ), Legacy_Bulk_Transport::$requests[0]['ids'] );
    }

    /** The real public posting action retains its userless dispatch. */
    public function test_userless_public_posting_action(): void {
        new MainWP_Hooks();
        $id    = $this->bulk_post( 'site' );
        $_POST = array(
            'post_id' => $id,
            'site_id' => $this->foreign_site,
        );
        wp_set_current_user( 0 );
        $sent    = array();
        $capture = static function ( $params, $site ) use ( &$sent ) {
            $sent[] = (int) $site->id;
            throw new Legacy_Bulk_Response_Stop( 'transport captured' );
        };
        add_filter( 'mainwp_pre_posting_posts', $capture, 10, 2 );
        try {
            $this->response(
                static function () {
                    do_action( 'mainwp_manage_posts_bulk_posting' );
                }
            );
        } finally {
            remove_filter( 'mainwp_pre_posting_posts', $capture, 10 );
        }
        $this->assertSame( array( $this->foreign_site ), $sent );
    }

    /**
     * The actual password form skips foreign targets in every selection.
     *
     * @dataProvider selections
     * @param string $selection Selection type.
     */
    public function test_password_form_selections( string $selection ): void {
        $this->password_form( $selection, array( $this->own_site, $this->foreign_site ) );
        $this->assertSame( array( $this->own_site ), Legacy_Bulk_Transport::$requests[0]['ids'], $selection );
        $this->assertSame( 'newadminpassword', Legacy_Bulk_Transport::$requests[0]['command'] );
    }

    /** A missing target is skipped without dereferencing null. */
    public function test_password_missing_target(): void {
        $this->password_form( 'site', array( $this->own_site, 999999 ) );
        $this->assertSame( array( $this->own_site ), Legacy_Bulk_Transport::$requests[0]['ids'] );
    }

    /** Owned multi-user and foreign single-user password changes still work. */
    public function test_password_allowed_controls(): void {
        $this->password_form( 'site', array( $this->own_site ) );
        $this->assertSame( array( $this->own_site ), Legacy_Bulk_Transport::$requests[0]['ids'] );
        remove_filter( 'mainwp_is_single_user', '__return_false' );
        Legacy_Bulk_Transport::$requests = array();
        $this->password_form( 'site', array( $this->foreign_site ) );
        $this->assertSame( array( $this->foreign_site ), Legacy_Bulk_Transport::$requests[0]['ids'] );
    }

    /** An entirely foreign password selection sends nothing and still renders. */
    public function test_password_all_targets_skipped(): void {
        $this->password_form( 'site', array( $this->foreign_site ) );
        $this->assertSame( array(), Legacy_Bulk_Transport::$requests );
    }

    /**
     * Submit the nonce-checked password form.
     *
     * @param string $selection Selection type.
     * @param array  $sites Selected site IDs.
     */
    private function password_form( string $selection, array $sites ): void {
        $this->request(
            'mainwp_updateadminpassword',
            array(
                'bulk_updateadminpassword' => '1',
                'select_by'                => $selection,
                'selected_sites'           => array_map( 'strval', $sites ),
                'selected_groups'          => array( (string) $this->group ),
                'selected_clients'         => array( (string) $this->client ),
                'password'                 => 'fixture-only-not-a-real-child-password',
            )
        );
        $this->response( array( '\\MainWP\\Dashboard\\Legacy_Ownership_Admin_Passwords', 'render' ) );
    }

    /** Rows and counts use identical ownership restrictions for every tab. */
    public function test_connection_ajax_rows_and_counts(): void {
        foreach ( array( 'all-sites', 'connected', 'disconnected' ) as $tab ) {
            $this->request( 'mainwp_widgets_connections_status_details_display_rows', array( 'current_tab' => $tab ) );
            $body     = $this->response( array( MainWP_Connection_Status::instance(), 'ajax_display_rows' ) );
            $data     = json_decode( $body, true );
            $expected = 'disconnected' === $tab ? 0 : 1;
            $this->assertSame( $expected, $data['recordsTotal'], $tab );
            $this->assertSame( $expected, $data['recordsFiltered'], $tab );
            $this->assertStringNotContainsString( 'Foreign fixture site', $body );
            if ( $expected ) {
                $this->assertSame( $this->own_site, (int) $data['rowsInfo'][0]['site_id'] );
            }
        }
        remove_filter( 'mainwp_is_single_user', '__return_false' );
        $_POST['current_tab'] = 'all-sites';
        $data                 = json_decode( $this->response( array( MainWP_Connection_Status::instance(), 'ajax_display_rows' ) ), true );
        $this->assertSame( 2, $data['recordsTotal'] );
    }

    /** Headline totals exclude another owner's disconnected site. */
    public function test_connection_headline_counts(): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'mainwp_wp_sync', array( 'sync_errors' => 'fixture disconnected' ), array( 'wpid' => $this->foreign_site ) );
        $body = $this->response( array( MainWP_Connection_Status::class, 'render_widget_content' ) );
        $this->assertMatchesRegularExpression( '/een check icon"><\/i>\s+1<\/span>/', $body );
        $this->assertMatchesRegularExpression( '/unlink icon"><\/i>\s+0<\/span>/', $body );
        remove_filter( 'mainwp_is_single_user', '__return_false' );
        $body = $this->response( array( MainWP_Connection_Status::class, 'render_widget_content' ) );
        $this->assertMatchesRegularExpression( '/unlink icon"><\/i>\s+1<\/span>/', $body );
    }

    /** The extension-facing SQL helper keeps its existing userless behavior. */
    public function test_shared_query_and_extension_hook_stay_unfiltered(): void {
        global $wpdb;
        new MainWP_Hooks();
        $this->assertNotFalse( has_filter( 'mainwp_db_get_sql_websites_for_current_user' ) );
        foreach ( array( $this->owner, 0 ) as $user ) {
            wp_set_current_user( $user );
            $params = array(
                'select_wp_fields' => array( 'id' ),
                'view'             => 'custom_view',
                'others_fields'    => array( 'verify_method' ),
            );
            $sql    = MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params );
            $this->assertCount( 2, $wpdb->get_results( $sql ), $wpdb->last_error . ' ' . $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL comes from the production query builder.
            $sql = apply_filters( 'mainwp_db_get_sql_websites_for_current_user', false, $params );
            $this->assertCount( 2, $wpdb->get_results( $sql ), $wpdb->last_error . ' ' . $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL comes from the production query builder.
        }
    }
}
