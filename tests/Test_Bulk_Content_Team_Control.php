<?php
/**
 * Bulk content access for Team Control staff and WordPress administrators.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Page;
use MainWP\Dashboard\MainWP_Post;
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_Bulk_Post;
use MainWP\Dashboard\MainWP_DB;

/** Stop redirects after the real admin-post handler has saved the draft. */
class Bulk_Content_Redirect_Stop extends \Error {}

/** Batch transport has no short-circuit filter; capture it before cURL. */
class Bulk_Content_Test_Connect {
    public static $batches = array();
    public static $search_rows = array();
    public static function fetch_urls_authed( &$websites, $what, $params, $handler, &$output, $page = null, $others = array() ) {
        self::$batches[] = array( 'sites' => array_keys( $websites ), 'what' => $what, 'params' => $params );
        foreach ( $websites as $website ) {
            call_user_func_array( $handler, array( '<mainwp>' . base64_encode( wp_json_encode( in_array( $what, array( 'get_all_posts', 'get_all_pages' ), true ) ? self::$search_rows : array() ) ) . '</mainwp>', $website, &$output ) );
        }
    }
    public static function fetch_url_authed( ...$args ) {
        return \MainWP\Dashboard\MainWP_Connect::fetch_url_authed( ...$args );
    }
}

/** Use in-memory session data without starting HTTP headers in PHPUnit. */
class Bulk_Content_Test_Cache extends \MainWP\Dashboard\MainWP_Cache {
    public static function init_session() {}
}

class Test_Bulk_Content_Team_Control extends \WP_UnitTestCase {

    use Site_Fixture;

    /** Replace exits only in test copies; keep registration and request bodies. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array( 'MainWP_Post_Base_Handler', 'MainWP_Post_Handler', 'MainWP_Post_Page_Handler', 'MainWP_Recent_Posts', 'MainWP_Post', 'MainWP_Page' );
        $map = array( 'MainWP_Connect' => 'Bulk_Content_Test_Connect', 'MainWP_Cache' => 'Bulk_Content_Test_Cache' );
        foreach ( $names as $name ) {
            $map[ $name ] = 'Bulk_Content_Exit_' . $name;
        }
        foreach ( array( 'class/class-mainwp-post-base-handler.php', 'class/class-mainwp-post-handler.php', 'pages/page-mainwp-post-page-handler.php', 'widgets/widget-mainwp-recent-posts.php', 'pages/page-mainwp-post.php', 'pages/page-mainwp-page.php' ) as $file ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                $code .= is_array( $token ) ? ( T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $map[ $token[1] ] ) ? $map[ $token[1] ] : $token[1] ) ) : $token;
            }
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Intercept legacy exits without changing production.
        }
        class_alias( Bulk_Content_Test_Connect::class, 'MainWP\\Dashboard\\Bulk_Content_Test_Connect' );
        class_alias( Bulk_Content_Test_Cache::class, 'MainWP\\Dashboard\\Bulk_Content_Test_Cache' );
    }


    private $saved_globals = array();
    private $saved_hooks = array();
    private $saved_subpages = array();
    private $saved_nonces = array();
    private $grants = array();
    private $administrator;
    private $redirected = false;
    private $saved_defer_js;
    private $transports = array();
    private $remote_type = 'post';
    private $remote_locks = 0;
    private $listing_core_query = false;
    private $listing_override = false;
    private $listing_response;
    private $allowed_sites = array();

    public function set_up() {
        parent::set_up();
        Bulk_Content_Test_Connect::$batches = array();
        Bulk_Content_Test_Connect::$search_rows = array();
        foreach ( array( 'menu', 'submenu', '_wp_menu_nopriv', '_wp_submenu_nopriv', '_registered_pages', '_parent_pages', 'admin_page_hooks', 'plugin_page', 'pagenow', 'parent_file', 'submenu_file', 'current_screen', 'mainwp_leftmenu', 'mainwp_sub_leftmenu', '_mainwp_menu_active_slugs', '_mainwp_disable_menus_items', '_mainwp_default_post_to_edit', '_SESSION', 'post', 'wp_post_types', '_post_type_meta_capabilities', 'wp_actions', 'wp_filters', 'wp_current_filter', 'mainwpUseExternalPrimaryBackupsMethod', 'wp_scripts', 'wp_styles', 'wp_admin_bar', '_wp_admin_css_colors', 'typenow', '_wp_real_parent_file', 'hook_suffix', 'post_type', 'post_type_object', 'wp_query', 'wp_the_query', 'avail_post_stati' ) as $name ) {
            $this->saved_globals[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
            if ( is_object( $this->saved_globals[ $name ][1] ) ) {
                $this->saved_globals[ $name ][1] = clone $this->saved_globals[ $name ][1];
            }
        }
        foreach ( $GLOBALS['wp_filter'] as $name => $hook ) {
            $this->saved_hooks[ $name ] = clone $hook;
        }
        $this->saved_subpages = array( MainWP_Post::$subPages, MainWP_Page::$subPages, MainWP_Page::$load_page );
        foreach ( array( 'security_names', 'security_nonces' ) as $name ) {
            $property = new \ReflectionProperty( \MainWP\Dashboard\MainWP_Post_Base_Handler::class, $name );
            $property->setAccessible( true );
            $this->saved_nonces[ $name ] = $property->getValue();
        }
        $property = new \ReflectionProperty( MainWP_System::class, 'defer_js_handle' );
        $property->setAccessible( true );
        $this->saved_defer_js = $property->getValue();
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        $_GET = $_POST = $_REQUEST = array();
        $this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->administrator );
        update_option( 'mainwp_run_quick_setup', 'no' );
        add_role( 'mainwp_bulk_content_test', 'Bulk content staff', array( 'read' => true, 'level_10' => true ) );
        add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
        add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'block_transport' ), PHP_INT_MAX );
        add_filter( 'mainwp_getsubpages_post', array( $this, 'extension_subpage' ) );
        add_filter( 'mainwp_getsubpages_page', array( $this, 'extension_subpage' ) );
    }

    public function tear_down() {
        remove_role( 'mainwp_bulk_content_test' );
        MainWP_Post::$subPages = $this->saved_subpages[0];
        MainWP_Page::$subPages = $this->saved_subpages[1];
        MainWP_Page::$load_page = $this->saved_subpages[2];
        foreach ( $this->saved_nonces as $name => $value ) {
            $property = new \ReflectionProperty( \MainWP\Dashboard\MainWP_Post_Base_Handler::class, $name );
            $property->setAccessible( true );
            $property->setValue( null, $value );
        }
        $property = new \ReflectionProperty( MainWP_System::class, 'defer_js_handle' );
        $property->setAccessible( true );
        $property->setValue( null, $this->saved_defer_js );
        $GLOBALS['wp_filter'] = $this->saved_hooks;
        foreach ( $this->saved_globals as $name => $value ) {
            if ( $value[0] ) {
                $GLOBALS[ $name ] = $value[1];
            } else {
                unset( $GLOBALS[ $name ] );
            }
        }
        $_GET = $_POST = $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function block_http() {
        return new \WP_Error( 'offline_bulk_content', 'HTTP disabled in bulk content tests.' );
    }

    public function block_transport() {
        throw new \RuntimeException( 'Unexpected Child transport in bulk content access tests.' );
    }

    public function extension_subpage( $pages ) {
        $pages[] = array( 'slug' => 'TeamControlFixture', 'title' => 'Extension content', 'callback' => '__return_null' );
        return $pages;
    }

    public function team_control_right( $allowed, $group, $right ) {
        if ( ! \MainWP\Dashboard\MainWP_System_Utility::is_team_control_staff() ) {
            return $allowed;
        }
        return 'dashboard' === $group && in_array( $right, $this->grants, true );
    }

    private function as_actor( $actor ) {
        $this->grants = array();
        remove_filter( 'mainwp_currentusercan', array( $this, 'team_control_right' ), 999 );
        if ( 'administrator' === $actor ) {
            wp_set_current_user( $this->administrator );
        } elseif ( 'anonymous' === $actor ) {
            wp_set_current_user( 0 );
        } else {
            $staff = 0 === strpos( $actor, 'staff_' );
            $role = $staff ? 'mainwp_bulk_content_test' : ( 'editor' === $actor ? 'editor' : 'subscriber' );
            wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
            if ( 'stale_level_10' === $actor ) {
                wp_get_current_user()->add_cap( 'level_10' );
            }
            if ( $staff ) {
                $this->assertSame( array( 'read' => true, 'level_10' => true ), get_role( $role )->capabilities );
                if ( 'staff_both' === $actor || 'staff_posts' === $actor ) {
                    $this->grants[] = 'manage_posts';
                }
                if ( 'staff_both' === $actor || 'staff_pages' === $actor ) {
                    $this->grants[] = 'manage_pages';
                }
                if ( 'staff_without_filter' !== $actor ) {
                    add_filter( 'mainwp_currentusercan', array( $this, 'team_control_right' ), 999, 3 );
                }
            }
            $this->assertFalse( current_user_can( 'manage_options' ) );
        }
        set_current_screen( 'dashboard' );
    }

    public static function permission_matrix() {
        $actors = array(
            'administrator' => array( true, true ),
            'staff_both' => array( true, true ),
            'staff_posts' => array( true, false ),
            'staff_pages' => array( false, true ),
            'staff_neither' => array( false, false ),
            'staff_without_filter' => array( false, false ),
            'subscriber' => array( false, false ),
            'editor' => array( false, false ),
            'stale_level_10' => array( false, false ),
            'anonymous' => array( false, false ),
        );
        $cases = array();
        foreach ( $actors as $actor => $allowed ) {
            $cases[ $actor . '/bulkpost' ] = array( $actor, 'bulkpost', $allowed[0] );
            $cases[ $actor . '/bulkpage' ] = array( $actor, 'bulkpage', $allowed[1] );
        }
        return $cases;
    }

    private function register_menus() {
        foreach ( array( 'menu', 'submenu', '_wp_menu_nopriv', '_wp_submenu_nopriv', '_registered_pages', '_parent_pages', 'admin_page_hooks', 'mainwp_leftmenu', 'mainwp_sub_leftmenu', '_mainwp_menu_active_slugs' ) as $name ) {
            $GLOBALS[ $name ] = array();
        }
        $GLOBALS['pagenow'] = 'admin.php';
        unset( $GLOBALS['plugin_page'] );
        do_action( 'admin_menu' );
    }

    private function menu_access( $slug ) {
        $GLOBALS['plugin_page'] = $slug;
        $GLOBALS['parent_file'] = 'mainwp_tab';
        return user_can_access_admin_page();
    }

    /** @dataProvider permission_matrix */
    public function test_registered_bulk_routes_follow_matching_grant( $actor, $type, $allowed ) {
        $this->as_actor( $actor );
        $this->register_menus();
        $routes = 'bulkpost' === $type ? array( 'PostBulkManage', 'PostBulkAdd', 'PostBulkEdit', 'PostingBulkPost', 'PostTeamControlFixture' ) : array( 'PageBulkManage', 'PageBulkAdd', 'PageBulkEdit', 'PostingBulkPage', 'PageTeamControlFixture' );
        foreach ( $routes as $route ) {
            $this->assertSame( $allowed, $this->menu_access( $route ), $actor . ' access to ' . $route );
            $rows = array_filter( $GLOBALS['submenu']['mainwp_tab'] ?? array(), static function ( $row ) use ( $route ) { return $route === $row[2]; } );
            $this->assertSame( $allowed, ! empty( $rows ), $actor . ' submenu visibility for ' . $route );
        }
    }

    /** @dataProvider permission_matrix */
    public function test_registered_post_type_capabilities_follow_matching_grant( $actor, $type, $allowed ) {
        $this->as_actor( $actor );
        $this->assertTrue( did_action( 'init' ) > 0, 'The plugin must register CPTs through init.' );
        $post_type = get_post_type_object( $type );
        $this->assertNotNull( $post_type );
        foreach ( array( 'create_posts', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts' ) as $capability ) {
            $this->assertSame( $allowed, current_user_can( $post_type->cap->$capability ), $actor . ' ' . $type . ' ' . $capability );
        }
    }

    /** @dataProvider permission_matrix */
    public function test_existing_bulk_content_meta_capabilities_follow_matching_grant( $actor, $type, $allowed ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_author' => $this->administrator ) );
        $this->as_actor( $actor );
        foreach ( array( 'edit_post', 'read_post', 'delete_post' ) as $capability ) {
            $this->assertSame( $allowed, current_user_can( $capability, $post ), $actor . ' ' . $capability . ' for existing ' . $type );
        }
    }

    /** @dataProvider permission_matrix */
    public function test_core_edit_post_updates_only_permitted_bulk_type( $actor, $type, $allowed ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_title' => 'Original bulk draft', 'post_author' => $this->administrator ) );
        $this->as_actor( $actor );
        $denied = false;
        try {
            edit_post( array( 'post_ID' => $post, 'post_title' => 'Updated bulk draft', 'post_status' => 'draft', 'user_ID' => get_current_user_id() ) );
        } catch ( \WPDieException $error ) {
            $denied = true;
        }
        $this->assertSame( ! $allowed, $denied, $actor . ' core edit_post refusal for ' . $type );
        $this->assertSame( $allowed ? 'Updated bulk draft' : 'Original bulk draft', get_post( $post )->post_title );
    }

    /** @dataProvider permission_matrix */
    public function test_staff_bulk_access_does_not_grant_wordpress_administration( $actor, $type, $allowed ) {
        $this->as_actor( $actor );
        $this->assertSame( 'administrator' === $actor, current_user_can( 'manage_options' ) );
        $this->assertSame( 'administrator' === $actor, current_user_can( 'activate_plugins' ) );
        $this->register_menus();
        // Use the same core menu gates as wp-admin/menu.php.
        add_menu_page( 'WordPress settings', 'WordPress settings', 'manage_options', 'options-general.php', '__return_null' );
        add_menu_page( 'WordPress plugins', 'WordPress plugins', 'activate_plugins', 'plugins.php', '__return_null' );
        add_submenu_page( 'options-general.php', 'General', 'General', 'manage_options', 'options-general.php', '__return_null' );
        add_submenu_page( 'plugins.php', 'Installed plugins', 'Installed plugins', 'activate_plugins', 'plugins.php', '__return_null' );
        foreach ( array( 'options-general.php', 'plugins.php' ) as $page ) {
            $GLOBALS['pagenow'] = $page;
            unset( $GLOBALS['plugin_page'], $GLOBALS['parent_file'] );
            $this->assertSame( 'administrator' === $actor, user_can_access_admin_page(), $actor . ' access to ' . $page );
        }
    }

    public function stop_redirect( $location ) {
        $this->redirected = true;
        throw new Bulk_Content_Redirect_Stop();
    }

    /** @dataProvider permission_matrix */
    public function test_registered_admin_post_edit_preserves_type_permission( $actor, $type, $allowed ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_title' => 'Original handler draft', 'post_author' => $this->administrator ) );
        $this->as_actor( $actor );
        $_POST = $_REQUEST = array(
            'post_ID' => $post,
            'post_type' => $type,
            'post_title' => 'Updated handler draft',
            'post_status' => 'draft',
            'user_ID' => get_current_user_id(),
            'select_sites_nonce' => wp_create_nonce( 'select_sites_' . $post ),
            '_wpnonce' => wp_create_nonce( 'update-post_' . $post ),
        );
        $this->redirected = false;
        add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
        try {
            do_action( 'admin_post_mainwp_editpost' );
        } catch ( Bulk_Content_Redirect_Stop $error ) {
            // Stop after the handler's write, before its legacy exit.
        } catch ( \WPDieException $error ) {
            $this->assertFalse( $allowed, $actor . ' must be able to save ' . $type );
        }
        $this->assertSame( $allowed ? 'Updated handler draft' : 'Original handler draft', get_post( $post )->post_title );
        $this->assertSame( $allowed, $this->redirected );
    }

    public static function invalid_edit_nonces() {
        $cases = array();
        foreach ( array( 'administrator', 'staff_both' ) as $actor ) {
            foreach ( array( 'bulkpost', 'bulkpage' ) as $type ) {
                foreach ( array( 'missing_select', 'invalid_select', 'missing_update', 'invalid_update' ) as $nonce ) {
                    $cases[ $actor . '/' . $type . '/' . $nonce ] = array( $actor, $type, $nonce );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider invalid_edit_nonces */
    public function test_registered_admin_post_edit_requires_both_nonces( $actor, $type, $nonce ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_title' => 'Unchanged nonce draft', 'post_status' => 'draft' ) );
        $this->as_actor( $actor );
        $_POST = array(
            'post_ID' => $post,
            'post_type' => $type,
            'post_title' => 'Rejected edit',
            'post_status' => 'draft',
            'select_sites_nonce' => wp_create_nonce( 'select_sites_' . $post ),
            '_wpnonce' => wp_create_nonce( 'update-post_' . $post ),
        );
        $key = false !== strpos( $nonce, 'select' ) ? 'select_sites_nonce' : '_wpnonce';
        if ( 0 === strpos( $nonce, 'missing' ) ) {
            unset( $_POST[ $key ] );
        } else {
            $_POST[ $key ] = 'invalid-fixture';
        }
        $_REQUEST = $_POST;
        $this->redirected = false;
        add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
        try {
            do_action( 'admin_post_mainwp_editpost' );
        } catch ( \WPDieException $error ) {
            $this->assertStringContainsString( 'link you followed has expired', $error->getMessage() );
        }
        $this->assertSame( 'Unchanged nonce draft', get_post( $post )->post_title );
        $this->assertFalse( $this->redirected );
    }

    public static function ajax_meta_matrix() {
        return array_filter( self::permission_matrix(), static function ( $case ) { return ! in_array( $case[0], array( 'anonymous', 'subscriber', 'editor' ), true ); } );
    }

    /** @dataProvider ajax_meta_matrix */
    public function test_admin_init_registered_meta_ajax_enforces_bulk_type( $actor, $type, $allowed ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_author' => $this->administrator ) );
        $this->as_actor( $actor );
        // Core update checks are unrelated external services; restore them with the other hooks.
        foreach ( array( '_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes', 'send_frame_options_header', 'send_referrer_policy_header', 'wp_admin_headers' ) as $callback ) {
            remove_action( 'admin_init', $callback );
        }
        do_action( 'admin_init' );
        $this->assertNotFalse( has_action( 'wp_ajax_mainwp_post_addmeta' ), 'admin_init must register the real metadata handler.' );
        $_POST = $_REQUEST = array(
            'post_id' => $post,
            'security' => wp_create_nonce( 'mainwp_post_addmeta' ),
            'metakeyinput' => 'bulk_fixture_field',
            'metavalue' => 'Bulk field value',
        );
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
        ob_start();
        try {
            do_action( 'wp_ajax_mainwp_post_addmeta' );
        } catch ( \WPDieException $error ) {
            if ( ! $allowed ) {
                $this->assertSame( '-1', $error->getMessage() );
            }
        } finally {
            $response = ob_get_clean();
        }
        $this->assertSame( $allowed ? 'Bulk field value' : '', get_post_meta( $post, 'bulk_fixture_field', true ), $actor . ' metadata write to ' . $type );
        if ( $allowed ) {
            $json = json_decode( $response, true );
            $this->assertIsArray( $json );
            $this->assertArrayHasKey( 'result', $json );
        }
    }

    private function register_requests() {
        foreach ( array( '_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes', 'send_frame_options_header', 'send_referrer_policy_header', 'wp_admin_headers' ) as $callback ) {
            remove_action( 'admin_init', $callback );
        }
        do_action( 'admin_init' );
        // The real registration chooses which callbacks exist; test copies intercept exits.
        foreach ( $GLOBALS['wp_filter'] as $name => $hook ) {
            if ( 0 !== strpos( $name, 'wp_ajax_mainwp_' ) ) { continue; }
            foreach ( $hook->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $entry ) {
                    $callback = $entry['function'];
                    if ( ! is_array( $callback ) ) { continue; }
                    $class = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
                    if ( ! in_array( $class, array( 'MainWP\\Dashboard\\MainWP_Post_Handler', 'MainWP\\Dashboard\\MainWP_Post_Page_Handler' ), true ) ) { continue; }
                    $clone = str_replace( 'MainWP\\Dashboard\\', 'MainWP\\Dashboard\\Bulk_Content_Exit_', $class );
                    remove_action( $name, $callback, $priority );
                    add_action( $name, array( is_object( $callback[0] ) ? $clone::instance() : $clone, $callback[1] ), $priority, $entry['accepted_args'] );
                }
            }
        }
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
    }

    private function request( $action, $payload = array(), $nonce = 'valid' ) {
        $_POST = $_REQUEST = array_merge( $payload, array( 'action' => $action ) );
        if ( 'missing' !== $nonce ) {
            $_POST['security'] = $_REQUEST['security'] = 'valid' === $nonce ? wp_create_nonce( $action ) : 'invalid';
        }
        ob_start();
        try {
            do_action( 'wp_ajax_' . $action );
        } catch ( \WPDieException $error ) {
            $message = $error->getMessage();
        } finally {
            $response = ob_get_clean();
        }
        return array( 'body' => $response, 'die' => $message ?? null );
    }

    public function site_scope() {
        return $this->allowed_sites;
    }

    private function sites() {
        $visible = $this->create_test_site( array( 'name' => 'Visible content site' ) );
        $hidden = $this->create_test_site( array( 'name' => 'Hidden content site' ) );
        $this->allowed_sites = array( $visible );
        add_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'site_scope' ), 999 );
        return array( $visible, $hidden );
    }

    public function remote_response( $pre, $website, $what, $params ) {
        $this->transports[] = array( 'site' => (int) $website->id, 'what' => $what, 'params' => $params );
        if ( 'get_all_posts' === $what || 'get_all_pages' === $what ) {
            if ( $this->listing_core_query ) {
                return array_map( static function ( $post ) { return array( 'id' => $post->ID, 'post_type' => $post->post_type ); }, get_posts( array( 'post_type' => $params['post_type'], 'post_status' => explode( ',', $params['status'] ), 'post__in' => array( $params['postId'] ), 'numberposts' => $params['maxRecords'] ) ) );
            }
            return $this->listing_override ? $this->listing_response : array( array( 'id' => $params['postId'], 'post_type' => $this->remote_type ) );
        }
        if ( 'post_action' === $what && 'get_edit' === ( $params['action'] ?? '' ) ) {
            ++$this->remote_locks;
            // The page reader labels all IDs as page; the post reader reports stored type.
            $actual = 'page' === ( $params['post_type'] ?? '' ) ? 'page' : $this->remote_type;
            return array( 'status' => 'SUCCESS', 'my_post' => array(
                'new_post' => base64_encode( wp_json_encode( array( 'edit_id' => $params['id'], 'post_type' => $actual, 'post_title' => 'Remote bulk fixture', 'post_content' => '', 'post_status' => 'draft', 'post_name' => 'remote-fixture' ) ) ),
                'post_custom' => base64_encode( '{}' ), 'post_category' => '', 'post_featured_image' => '', 'post_gallery_images' => '', 'child_upload_dir' => base64_encode( '{}' ),
            ) );
        }
        return array( 'status' => 'SUCCESS' );
    }

    private function fake_remote() {
        remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'block_transport' ), PHP_INT_MAX );
        add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'remote_response' ), PHP_INT_MAX, 4 );
    }

    public static function remote_actions() {
        $cases = array();
        foreach ( array( 'post', 'page' ) as $domain ) {
            foreach ( array( 'publish', 'unpublish', 'trash', 'delete', 'restore' ) as $action ) {
                foreach ( array( 'staff_both', 'staff_posts', 'staff_pages', 'staff_neither', 'staff_without_filter', 'stale_level_10', 'subscriber', 'editor', 'anonymous', 'administrator' ) as $actor ) {
                    $allowed = 'administrator' === $actor || 'staff_both' === $actor || ( 'staff_posts' === $actor && 'post' === $domain ) || ( 'staff_pages' === $actor && 'page' === $domain );
                    $cases[ $actor . '/' . $domain . '/' . $action ] = array( $actor, $domain, $action, $allowed );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider remote_actions */
    public function test_registered_remote_mutation_matches_grant( $actor, $domain, $action, $allowed ) {
        $this->as_actor( $actor );
        list( $site ) = $this->sites();
        $this->fake_remote();
        $this->remote_type = $domain;
        $this->register_requests();
        $this->request( 'mainwp_' . $domain . '_' . $action, array( 'websiteId' => $site, 'postId' => 321 ) );
        $mutations = array_filter( $this->transports, static function ( $call ) { return 'post_action' === $call['what'] && 'get_edit' !== ( $call['params']['action'] ?? '' ); } );
        $this->assertCount( $allowed ? 1 : 0, $mutations, $actor . ' remote ' . $domain . ' ' . $action );
    }

    public static function wrong_remote_domains() {
        return array(
            'posts endpoint with page ID' => array( 'staff_posts', 'post', 'page', false ),
            'pages endpoint with post ID' => array( 'staff_pages', 'page', 'post', false ),
            'both grants still respect posts endpoint' => array( 'staff_both', 'post', 'page', false ),
            'both grants still respect pages endpoint' => array( 'staff_both', 'page', 'post', false ),
            'custom post uses posts grant' => array( 'staff_posts', 'post', 'product', true ),
            'custom post cannot use pages grant' => array( 'staff_pages', 'page', 'product', false ),
        );
    }

    /** @dataProvider wrong_remote_domains */
    public function test_remote_mutation_checks_returned_domain_before_mutation( $actor, $domain, $actual, $allowed ) {
        $this->as_actor( $actor );
        list( $site ) = $this->sites();
        $this->fake_remote();
        $this->remote_type = $actual;
        $this->register_requests();
        $this->request( 'mainwp_' . $domain . '_trash', array( 'websiteId' => $site, 'postId' => 321 ) );
        $mutations = array_filter( $this->transports, static function ( $call ) { return 'post_action' === $call['what'] && 'get_edit' !== ( $call['params']['action'] ?? '' ); } );
        $this->assertCount( $allowed ? 1 : 0, $mutations );
        $this->assertSame( array( $site ), array_values( array_unique( array_column( $this->transports, 'site' ) ) ) );
    }

    public static function nonce_actions() {
        $cases = array();
        foreach ( array( 'mainwp_post_publish', 'mainwp_page_trash', 'mainwp_posts_search', 'mainwp_pages_search', 'mainwp_get_categories', 'mainwp_get_sites_of_groups', 'mainwp_post_get_edit', 'mainwp_post_postingbulk', 'mainwp_post_addmeta' ) as $action ) {
            foreach ( array( 'missing', 'invalid' ) as $nonce ) {
                $cases[ $action . '/' . $nonce ] = array( $action, $nonce );
            }
        }
        return $cases;
    }

    /** @dataProvider nonce_actions */
    public function test_registered_requests_keep_nonce_checks( $action, $nonce ) {
        $this->as_actor( 'staff_both' );
        list( $site ) = $this->sites();
        $post = self::factory()->post->create( array( 'post_type' => 'bulkpost', 'post_title' => 'Nonce draft', 'post_author' => get_current_user_id() ) );
        $this->fake_remote();
        $this->register_requests();
        $result = $this->request( $action, array( 'post_id' => $post, 'postId' => 321, 'websiteId' => $site, 'site_id' => $site, 'postType' => 'post', 'metakeyinput' => 'nonce_field', 'metavalue' => 'Denied', 'sites' => array( $site ) ), $nonce );
        $this->assertEmpty( $this->transports );
        $this->assertEmpty( Bulk_Content_Test_Connect::$batches );
        $this->assertSame( '', get_post_meta( $post, 'nonce_field', true ) );
        $this->assertStringContainsString( 'Insecure request', $result['die'] ?? $result['body'] );
    }

    public static function group_requests() {
        return array(
            'mixed site IDs' => array( 'mainwp_get_sites_of_groups', false ),
            'hidden site IDs' => array( 'mainwp_get_sites_of_groups', true ),
            'mixed categories' => array( 'mainwp_get_categories', false ),
            'hidden categories' => array( 'mainwp_get_categories', true ),
        );
    }

    /** @dataProvider group_requests */
    public function test_group_expansion_excludes_hidden_sites( $action, $hidden_only ) {
        global $wpdb;
        $this->as_actor( 'staff_both' );
        list( $visible, $hidden ) = $this->sites();
        foreach ( array( $visible => 'Visible category', $hidden => 'Hidden category' ) as $id => $name ) {
            $wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'categories' => wp_json_encode( array( $name ) ) ), array( 'id' => $id ) );
        }
        $group = 12345;
        foreach ( $hidden_only ? array( $hidden ) : array( $visible, $hidden ) as $id ) {
            $wpdb->insert( $wpdb->prefix . 'mainwp_wp_group', array( 'wpid' => $id, 'groupid' => $group ) );
        }
        $this->register_requests();
        $response = $this->request( $action, array( 'groups' => 'mainwp_get_categories' === $action ? (string) $group : array( $group ) ) );
        $body = $response['die'] ?: $response['body'];
        if ( 'mainwp_get_sites_of_groups' === $action ) {
            $this->assertSame( $hidden_only ? array() : array( (string) $visible ), array_map( 'strval', json_decode( $body, true ) ) );
        } else {
            $this->assertStringNotContainsString( 'Hidden category', $body );
            if ( ! $hidden_only ) { $this->assertStringContainsString( 'Visible category', $body ); }
        }
    }

    public static function imported_draft_paths() {
        $cases = array();
        foreach ( array( 'bulkpost', 'bulkpage' ) as $type ) {
            foreach ( array( 'read_post', 'edit_post', 'delete_post', 'render', 'admin_post', 'metadata', 'posting' ) as $path ) {
                $cases[ $type . '/' . $path ] = array( $type, $path );
            }
        }
        return $cases;
    }

    /** @dataProvider imported_draft_paths */
    public function test_hidden_imported_draft_is_not_read_edited_or_posted( $type, $path ) {
        $this->as_actor( 'staff_both' );
        list( $site, $hidden ) = $this->sites();
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_title' => 'Hidden imported source', 'post_author' => get_current_user_id() ) );
        update_post_meta( $post, '_mainwp_edit_post_site_id', $hidden );
        if ( in_array( $path, array( 'read_post', 'edit_post', 'delete_post' ), true ) ) {
            $this->assertFalse( current_user_can( $path, $post ) );
            return;
        }
        $this->register_requests();
        ob_start();
        try {
            if ( 'render' === $path ) {
                MainWP_Post::render_bulkpost( $post, $type );
            } elseif ( 'metadata' === $path ) {
                $this->request( 'mainwp_post_addmeta', array( 'post_id' => $post, 'metakeyinput' => 'hidden_field', 'metavalue' => 'Denied' ) );
            } elseif ( 'posting' === $path ) {
                $this->request( 'mainwp_post_postingbulk', array( 'post_id' => $post, 'site_id' => $site ) );
            } else {
                $_POST = $_REQUEST = array( 'post_ID' => $post, 'post_type' => $type, 'post_title' => 'Changed hidden draft', 'select_sites_nonce' => wp_create_nonce( 'select_sites_' . $post ), '_wpnonce' => wp_create_nonce( 'update-post_' . $post ) );
                add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
                do_action( 'admin_post_mainwp_editpost' );
            }
        } catch ( \WPDieException | Bulk_Content_Redirect_Stop $error ) {
            // Refusals may return or terminate; neither may expose or modify the record.
        } finally {
            $body = ob_get_clean();
        }
        $this->assertStringNotContainsString( 'Hidden imported source', $body );
        $this->assertSame( 'Hidden imported source', get_post( $post )->post_title );
        $this->assertSame( '', get_post_meta( $post, 'hidden_field', true ) );
        $this->assertEmpty( Bulk_Content_Test_Connect::$batches );
    }

    public static function posting_matrix() {
        return array_filter( self::permission_matrix(), static function ( $case ) { return 'administrator' !== $case[0]; } );
    }

    /** @dataProvider posting_matrix */
    public function test_shared_posting_uses_stored_bulk_type( $actor, $type, $allowed ) {
        $this->as_actor( $actor );
        list( $site ) = $this->sites();
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_title' => 'Posting draft', 'post_author' => get_current_user_id() ) );
        $this->fake_remote();
        $this->register_requests();
        $this->request( 'mainwp_post_postingbulk', array( 'post_id' => $post, 'postType' => 'bulkpost' === $type ? 'page' : 'post', 'site_id' => $site ) );
        $this->assertCount( $allowed ? 1 : 0, Bulk_Content_Test_Connect::$batches );
        if ( $allowed ) {
            $this->assertSame( array( $site ), array_map( 'intval', Bulk_Content_Test_Connect::$batches[0]['sites'] ) );
            $payload = json_decode( base64_decode( Bulk_Content_Test_Connect::$batches[0]['params']['new_post'] ), true );
            $this->assertSame( 'bulkpage' === $type ? 'page' : 'post', $payload['post_type'] ?? 'post' );
        }
    }

    public static function direct_posting_routes() {
        return array( 'post route with page draft' => array( 'bulkpage', 'post' ), 'page route with post draft' => array( 'bulkpost', 'page' ) );
    }

    /** @dataProvider direct_posting_routes */
    public function test_direct_posting_rejects_cross_domain_before_skip_delete( $type, $route ) {
        $this->as_actor( 'staff_both' );
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_author' => get_current_user_id() ) );
        update_post_meta( $post, '_mainwp_skip_posting', 'yes' );
        $_GET = array( 'id' => $post, 'posting_nonce' => wp_create_nonce( 'posting_nonce_' . $post ) );
        ob_start();
        try {
            if ( 'page' === $route ) { MainWP_Page::posting(); }
            else { \MainWP\Dashboard\MainWP_Post_Page_Handler::posting_bulk(); }
        } catch ( \Throwable $error ) {
            // Base may continue past the deletion into an unrelated undefined variable.
        } finally { ob_end_clean(); }
        $this->assertNotNull( get_post( $post ), 'The route must reject the stored type before deleting it.' );
    }

    public function test_checked_user_does_not_borrow_current_staff_or_admin_rights() {
        $this->as_actor( 'staff_posts' );
        $other = self::factory()->user->create( array( 'role' => 'mainwp_bulk_content_test' ) );
        $type = get_post_type_object( 'bulkpost' );
        $this->assertTrue( current_user_can( $type->cap->edit_posts ) );
        $this->assertFalse( user_can( $other, $type->cap->edit_posts ) );
        wp_set_current_user( $this->administrator );
        $this->assertFalse( user_can( $other, $type->cap->edit_posts ) );
    }

    public static function search_sites_matrix() {
        $cases = array();
        foreach ( array( 'post', 'page' ) as $domain ) {
            foreach ( array( 'staff_both', 'staff_posts', 'staff_pages', 'staff_neither', 'staff_without_filter', 'subscriber', 'editor', 'stale_level_10', 'anonymous' ) as $actor ) {
                foreach ( array( false, true ) as $hidden_only ) {
                    $allowed = 'staff_both' === $actor || ( 'staff_posts' === $actor && 'post' === $domain ) || ( 'staff_pages' === $actor && 'page' === $domain );
                    $cases[ $actor . '/' . $domain . '/' . ( $hidden_only ? 'hidden' : 'mixed' ) ] = array( $actor, $domain, $allowed, $hidden_only );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider search_sites_matrix */
    public function test_search_dispatches_only_allowed_sites( $actor, $domain, $allowed, $hidden_only ) {
        $this->as_actor( $actor );
        list( $visible, $hidden ) = $this->sites();
        $this->register_requests();
        $this->request( 'mainwp_' . $domain . 's_search', array( 'sites' => $hidden_only ? array( $hidden ) : array( $visible, $hidden ), 'table_content' => 1, 'post_type' => 'post' ) );
        $this->assertCount( $allowed && ! $hidden_only ? 1 : 0, Bulk_Content_Test_Connect::$batches );
        foreach ( Bulk_Content_Test_Connect::$batches as $batch ) {
            $this->assertSame( array( $visible ), array_map( 'intval', $batch['sites'] ) );
        }
    }

    /** @dataProvider permission_matrix */
    public function test_edit_fetch_checks_actual_domain_and_grant( $actor, $type, $allowed ) {
        $this->as_actor( $actor );
        list( $site ) = $this->sites();
        $this->fake_remote();
        $this->remote_type = 'bulkpage' === $type ? 'page' : 'post';
        $this->register_requests();
        $response = $this->request( 'mainwp_post_get_edit', array( 'postType' => $this->remote_type, 'postId' => 321, 'websiteId' => $site ) );
        $json = json_decode( $response['body'], true );
        $this->assertSame( $allowed, is_array( $json ) && isset( $json['id'] ) );
        if ( $allowed ) {
            $this->assertSame( $type, get_post_type( $json['id'] ) );
            $this->assertSame( $site, (int) get_post_meta( $json['id'], '_mainwp_edit_post_site_id', true ) );
        } else {
            $this->assertEmpty( $this->transports );
        }
    }

    /** @dataProvider wrong_remote_domains */
    public function test_edit_fetch_rejects_wrong_actual_domain( $actor, $requested, $actual, $allowed ) {
        $this->as_actor( $actor );
        list( $site ) = $this->sites();
        $this->fake_remote();
        $this->remote_type = $actual;
        $this->register_requests();
        $response = $this->request( 'mainwp_post_get_edit', array( 'postType' => $requested, 'postId' => 321, 'websiteId' => $site ) );
        $json = json_decode( $response['body'], true );
        $this->assertSame( $allowed, is_array( $json ) && isset( $json['id'] ) );
        if ( ! $allowed ) {
            $this->assertStringNotContainsString( 'Remote bulk fixture', $response['body'] );
        }
    }

    public static function hidden_delivery() {
        return array( 'post delivery' => array( 'bulkpost' ), 'page delivery' => array( 'bulkpage' ) );
    }

    /** @dataProvider hidden_delivery */
    public function test_hidden_destination_is_dropped_before_posting( $type ) {
        $this->as_actor( 'staff_both' );
        list( $visible, $hidden ) = $this->sites();
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_author' => get_current_user_id() ) );
        $this->register_requests();
        $error = null;
        try {
            $this->request( 'mainwp_post_postingbulk', array( 'post_id' => $post, 'site_id' => $hidden ) );
        } catch ( \Throwable $caught ) {
            $error = $caught;
        }
        $this->assertNull( $error, 'Hidden destinations must be refused or dropped without a PHP error.' );
        $this->assertEmpty( Bulk_Content_Test_Connect::$batches );
    }

    public static function metadata_owner_matrix() {
        $cases = array();
        foreach ( array( 'bulkpost', 'bulkpage' ) as $type ) {
            foreach ( array( 'staff_posts', 'staff_pages' ) as $actor ) {
                foreach ( array( 'update', 'delete' ) as $operation ) {
                    $cases[ $type . '/' . $actor . '/' . $operation ] = array( $type, $actor, $operation, ( 'staff_posts' === $actor ) === ( 'bulkpost' === $type ) );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider metadata_owner_matrix */
    public function test_metadata_domain_comes_from_actual_metadata_owner( $type, $actor, $operation, $allowed ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft' ) );
        $mid = add_post_meta( $post, 'owner_field', 'Original' );
        $this->as_actor( $actor );
        $this->register_requests();
        $payload = 'delete' === $operation ? array( 'id' => $mid, 'delete_meta' => 'yes', 'meta_nonce' => wp_create_nonce( 'delete-meta_' . $mid ) ) : array( 'meta' => array( $mid => array( 'key' => 'owner_field', 'value' => 'Updated' ) ) );
        // A caller-supplied unrelated post_id cannot override the metadata owner.
        $payload['post_id'] = self::factory()->post->create( array( 'post_type' => 'staff_posts' === $actor ? 'bulkpost' : 'bulkpage' ) );
        $this->request( 'mainwp_post_addmeta', $payload );
        $this->assertSame( $allowed ? ( 'delete' === $operation ? '' : 'Updated' ) : 'Original', get_post_meta( $post, 'owner_field', true ) );
    }

    public function test_denial_sentinels_and_other_required_caps_are_preserved() {
        $post = self::factory()->post->create( array( 'post_type' => 'bulkpost' ) );
        $this->as_actor( 'staff_posts' );
        add_filter( 'map_meta_cap', static function ( $caps, $cap ) {
            if ( in_array( 'mainwp_manage_bulk_posts', $caps, true ) ) { $caps[] = 'do_not_allow'; }
            return $caps;
        }, 999, 2 );
        $this->assertFalse( current_user_can( 'edit_post', $post ) );
        $this->assertFalse( current_user_can( 'edit_others_posts' ) );
        $this->assertFalse( current_user_can( 'delete_others_posts' ) );
    }

    public static function revision_matrix() {
        return array(
            'post grant' => array( 'staff_posts', 'bulkpost', false, true ),
            'page grant' => array( 'staff_pages', 'bulkpage', false, true ),
            'posts cannot edit page revisions' => array( 'staff_posts', 'bulkpage', false, false ),
            'pages cannot edit post revisions' => array( 'staff_pages', 'bulkpost', false, false ),
            'hidden post parent' => array( 'staff_posts', 'bulkpost', true, false ),
            'hidden page parent' => array( 'staff_pages', 'bulkpage', true, false ),
        );
    }

    /** @dataProvider revision_matrix */
    public function test_core_revision_edit_follows_bulk_parent_grant_and_site_scope( $actor, $type, $hidden, $allowed ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Original revision title', 'post_author' => $this->administrator ) );
        remove_action( 'wp_after_insert_post', 'wp_save_post_revision_on_insert' );
        remove_action( 'post_updated', 'wp_save_post_revision' );
        wp_update_post( array( 'ID' => $post, 'post_title' => 'New revision title' ) );
        $revision = wp_save_post_revision( $post );
        $this->assertGreaterThan( 0, $revision, 'Core must create a real bulk revision.' );
        $this->assertSame( $post, wp_is_post_revision( $revision ) );
        $this->as_actor( $actor );
        list( $visible, $hidden_site ) = $this->sites();
        if ( $hidden ) { update_post_meta( $post, '_mainwp_edit_post_site_id', $hidden_site ); }
        $this->assertSame( $allowed, current_user_can( 'edit_post', $revision ) );
        $this->assertFalse( current_user_can( 'delete_post', $revision ), 'Core do_not_allow for revision deletion stays intact.' );
    }

    public function test_extension_posting_without_current_user_keeps_existing_contract() {
        list( $site ) = $this->sites();
        $post = self::factory()->post->create( array( 'post_type' => 'bulkpost', 'post_status' => 'draft', 'post_author' => $this->administrator ) );
        update_post_meta( $post, '_selected_by', 'site' );
        update_post_meta( $post, '_selected_sites', array( $site ) );
        add_filter( 'mainwp_after_posting_delete_bulk_post', '__return_false' );
        wp_set_current_user( 0 );
        ob_start();
        try {
            \MainWP\Dashboard\Bulk_Content_Exit_MainWP_Post_Page_Handler::posting_posts( $post, 'posting' );
        } finally { ob_end_clean(); }
        $this->assertCount( 1, Bulk_Content_Test_Connect::$batches );
        $this->assertSame( array( $site ), array_map( 'intval', Bulk_Content_Test_Connect::$batches[0]['sites'] ) );
    }

    public static function read_only_type_probe_cases() {
        $bad = array(
            'wrong domain' => array( array( 'id' => 321, 'post_type' => 'page' ) ),
            'empty' => array(),
            'missing type' => array( array( 'id' => 321 ) ),
            'array type' => array( array( 'id' => 321, 'post_type' => array( 'post' ) ) ),
            'unsupported type' => array( array( 'id' => 321, 'post_type' => 'attachment' ) ),
            'wrong ID' => array( array( 'id' => 322, 'post_type' => 'post' ) ),
            'lossy ID' => array( array( 'id' => '321suffix', 'post_type' => 'post' ) ),
            'multiple rows' => array( array( 'id' => 321, 'post_type' => 'post' ), array( 'id' => 322, 'post_type' => 'post' ) ),
            'unknown metadata' => array( array( 'id' => 321, 'post_type' => 'post' ), 'unexpected' => 'Not transport metadata' ),
            'error' => array( 'error' => 'Listing unavailable' ),
        );
        $cases = array();
        foreach ( array( 'mainwp_post_get_edit', 'mainwp_post_trash' ) as $action ) {
            foreach ( $bad as $name => $response ) { $cases[ $action . '/' . $name ] = array( $action, $response ); }
        }
        return $cases;
    }

    /** @dataProvider read_only_type_probe_cases */
    public function test_read_only_type_probe_refuses_before_any_edit_lock_or_write( $action, $response ) {
        $this->as_actor( 'staff_posts' );
        list( $site ) = $this->sites();
        $this->fake_remote();
        $this->remote_type = 'page';
        $this->listing_override = true;
        $this->listing_response = $response;
        $this->register_requests();
        $this->request( $action, array( 'postType' => 'post', 'websiteId' => $site, 'postId' => 321 ) );
        $this->assertSame( 0, $this->remote_locks, 'A refused type probe must never reach the lock-writing get_edit reader.' );
        $this->assertCount( 1, $this->transports );
        $call = $this->transports[0];
        $this->assertSame( 'get_all_posts', $call['what'] );
        $this->assertSame( 321, $call['params']['postId'] );
        $this->assertSame( 1, $call['params']['maxRecords'] );
        $this->assertSame( array( 'any', 'trash', 'inherit', 'auto-draft' ), explode( ',', $call['params']['status'] ) );
        $this->assertEmpty( get_posts( array( 'post_type' => array( 'bulkpost', 'bulkpage' ), 'title' => 'Remote bulk fixture' ) ) );
    }

    public static function effective_search_types() {
        return array(
            'space page' => array( ' page ', '', '', false ),
            'HTML page' => array( '<b>page</b>', '', '', false ),
            'upper page' => array( 'PAGE', '', '', false ),
            'any exact ID' => array( 'any', '321', '', false ),
            'any author' => array( ' any ', '', '7', false ),
            'standard post ID' => array( 'post', '321', '', true ),
            'custom type ID' => array( 'product', '321', '', true ),
            'ordinary any search' => array( 'any', '', '', true ),
        );
    }

    /** @dataProvider effective_search_types */
    public function test_registered_search_authorizes_sanitized_type_and_wildcard_selectors( $type, $post_id, $author, $posts_allowed ) {
        $this->as_actor( 'staff_posts' );
        list( $site ) = $this->sites();
        update_option( 'active_plugins', array( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) );
        $this->register_requests();
        $request = array( 'post_type' => $type, 'postId' => $post_id, 'userId' => $author, 'sites' => array( $site ), 'table_content' => 1 );
        if ( ! $posts_allowed ) {
            Bulk_Content_Test_Connect::$search_rows = array( array( 'id' => 321, 'post_type' => 'page', 'title' => 'Hidden page search fixture', 'status' => 'trash', 'author' => 'Fixture', 'categories' => '', 'tags' => '', 'comment_count' => 0, 'dts' => '2026-10-26 00:00:00' ) );
        }
        $result = $this->request( 'mainwp_posts_search', $request );
        $this->assertCount( $posts_allowed ? 1 : 0, Bulk_Content_Test_Connect::$batches );
        $this->assertStringNotContainsString( 'Hidden page search fixture', $result['body'] );
        if ( ! $posts_allowed ) {
            $this->grants[] = 'manage_pages';
            Bulk_Content_Test_Connect::$batches = array();
            $this->request( 'mainwp_posts_search', $request );
            $this->assertCount( 1, Bulk_Content_Test_Connect::$batches, 'Both grants retain page and wildcard-selector searches.' );
        }
    }

    public static function listing_transport_metadata_cases() {
        return array( 'post edit' => array( 'post', 'mainwp_post_get_edit' ), 'page edit' => array( 'page', 'mainwp_post_get_edit' ), 'post mutation' => array( 'post', 'mainwp_post_trash' ), 'page mutation' => array( 'page', 'mainwp_page_trash' ) );
    }

    /** @dataProvider listing_transport_metadata_cases */
    public function test_listing_transport_metadata_preserves_one_valid_object( $type, $action ) {
        $this->as_actor( 'page' === $type ? 'staff_pages' : 'staff_posts' );
        list( $site ) = $this->sites();
        $this->fake_remote();
        $this->remote_type = $type;
        $this->listing_override = true;
        $this->listing_response = array( array( 'id' => 321, 'post_type' => $type ), 'fetch_url_output' => 'Fixture transport metadata' );
        $this->register_requests();
        $response = $this->request( $action, array( 'postType' => $type, 'postId' => 321, 'websiteId' => $site ) );
        $this->assertCount( 2, $this->transports, 'Known transport metadata must not count as another object.' );
        $this->assertSame( 'get_all_posts', $this->transports[0]['what'] );
        if ( 'mainwp_post_get_edit' === $action ) {
            $json = json_decode( $response['body'], true );
            $this->assertArrayHasKey( 'id', $json );
            $this->assertSame( 'page' === $type ? 'bulkpage' : 'bulkpost', get_post_type( $json['id'] ) );
        } else {
            $this->assertSame( 'trash', $this->transports[1]['params']['action'] );
            $this->assertSame( 0, $this->remote_locks );
        }
    }


    public static function run2_remote_types() {
        return array( 'block' => array( 'wp_block', false ), 'template' => array( 'wp_template', false ), 'navigation' => array( 'wp_navigation', false ), 'public custom' => array( 'run2_public', true ) );
    }

    /** @dataProvider run2_remote_types */
    public function test_run2_registered_edit_uses_searchable_child_type_boundary( $type, $allowed ) {
        register_post_type( 'run2_public', array( 'public' => true ) );
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Run2 private object marker' ) );
        $this->assertSame( $allowed, in_array( $post, get_posts( array( 'post_type' => 'any', 'post_status' => array( 'any', 'trash', 'inherit', 'auto-draft' ), 'post__in' => array( $post ), 'fields' => 'ids' ) ), true ), 'Real core query used by Child must exclude nonsearchable types.' );
        $this->as_actor( 'staff_posts' );
        list( $site ) = $this->sites();
        $this->fake_remote();
        $this->remote_type = $type;
        $this->listing_core_query = true;
        $this->register_requests();
        $result = $this->request( 'mainwp_post_get_edit', array( 'postType' => $type, 'postId' => $post, 'websiteId' => $site ) );
        $this->assertSame( $allowed ? 1 : 0, $this->remote_locks, 'Refused private objects must never reach get_edit or its lock write.' );
        $this->assertCount( $allowed ? 2 : 1, $this->transports );
        $this->assertSame( 'any', $this->transports[0]['params']['post_type'] );
        if ( ! $allowed ) { $this->assertStringNotContainsString( 'Run2 private object marker', $result['body'] ); }
    }

    public static function run2_native_screens() {
        $cases = array();
        foreach ( array( 'bulkpost', 'bulkpage', 'post' ) as $type ) {
            foreach ( array( 'edit.php', 'post.php', 'post-new.php' ) as $screen ) {
                foreach ( array( 'staff_both', 'administrator' ) as $actor ) {
                    $cases[ $type . '/' . $screen . '/' . $actor ] = array( $type, $screen, $actor );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider run2_native_screens */
    public function test_run2_registered_native_bulk_screens_deny_before_list_or_editor( $type, $screen, $actor ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_title' => 'Hidden native title', 'post_content' => 'Hidden content oracle' ) );
        $this->as_actor( $actor );
        list( $visible, $hidden ) = $this->sites();
        update_post_meta( $post, '_mainwp_edit_post_site_id', $hidden );
        $GLOBALS['pagenow'] = $screen;
        $_GET = array( 'post_type' => 'post.php' === $screen ? 'post' : $type, 'post' => $post, 's' => 'Hidden content oracle' );
        $_REQUEST = $_GET;
        set_current_screen( ( 'edit.php' === $screen ? 'edit-' : '' ) . $type );
        $denied = false;
        $response = null;
        add_filter( 'wp_die_handler', static function () use ( &$response ) { return static function ( $message, $title, $args ) use ( &$response ) { $response = $args['response']; throw new \WPDieException( $message ); }; } );
        try { do_action( 'load-' . $screen ); } catch ( \WPDieException $error ) { $denied = true; }
        $this->assertSame( 'administrator' !== $actor && 'post' !== $type, $denied );
        if ( $denied ) { $this->assertSame( 403, $response ); }
        if ( 'post' !== $type ) {
            delete_post_meta( $post, '_mainwp_edit_post_site_id' );
            $this->assertTrue( current_user_can( 'edit_post', $post ), 'MainWP editor/core capability remains available independently of native UI.' );
        }
    }

    public static function run2_object_capabilities() {
        $cases = array();
        foreach ( array( 'bulkpost', 'bulkpage' ) as $type ) {
            foreach ( array( 'publish_post', 'edit_comment' ) as $cap ) {
                foreach ( array( 'allowed', 'hidden', 'crossgrant' ) as $scope ) { $cases[ $type . '/' . $cap . '/' . $scope ] = array( $type, $cap, $scope ); }
            }
        }
        return $cases;
    }

    /** @dataProvider run2_object_capabilities */
    public function test_run2_mapped_object_capabilities_keep_stored_site_scope( $type, $cap, $scope ) {
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft' ) );
        $comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );
        if ( $comment === $post ) { $comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) ); }
        $this->assertNotSame( $post, $comment, 'Comment IDs must not be mistaken for post IDs.' );
        $this->as_actor( 'crossgrant' === $scope ? ( 'bulkpost' === $type ? 'staff_pages' : 'staff_posts' ) : 'staff_both' );
        list( $visible, $hidden ) = $this->sites();
        update_post_meta( $post, '_mainwp_edit_post_site_id', 'hidden' === $scope ? $hidden : $visible );
        $this->assertSame( 'allowed' === $scope, current_user_can( $cap, 'edit_comment' === $cap ? $comment : $post ) );
    }

    public static function run2_form_thumbnails() {
        $cases = array();
        foreach ( array( 'bulkpost', 'bulkpage' ) as $type ) {
            foreach ( array( 'own', 'foreign', 'foreign-parent', 'remove', 'unchanged', 'admin', 'native', 'invalid-nonce' ) as $mode ) { $cases[ $type . '/' . $mode ] = array( $type, $mode ); }
        }
        return $cases;
    }

    /** @dataProvider run2_form_thumbnails */
    public function test_run2_registered_form_keeps_upload_ownership_boundary( $type, $mode ) {
        $this->as_actor( 'admin' === $mode ? 'administrator' : 'staff_both' );
        if ( 'native' === $mode ) { wp_get_current_user()->add_role( 'editor' ); }
        $post = self::factory()->post->create( array( 'post_type' => $type, 'post_author' => get_current_user_id(), 'post_status' => 'draft', 'post_title' => 'Original thumbnail form' ) );
        $parent = 'foreign-parent' === $mode ? self::factory()->post->create( array( 'post_type' => $type, 'post_author' => $this->administrator ) ) : $post;
        $attachment = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg', 'post_parent' => $parent, 'post_author' => in_array( $mode, array( 'foreign', 'admin', 'native' ), true ) ? $this->administrator : get_current_user_id() ) );
        update_post_meta( $attachment, '_wp_attached_file', 'run2-fixture.jpg' );
        update_post_meta( $attachment, '_wp_attachment_metadata', array( 'width' => 40, 'height' => 40, 'file' => 'run2-fixture.jpg' ) );
        update_post_meta( $post, '_thumbnail_id', $attachment );
        $_POST = $_REQUEST = array( 'post_ID' => $post, 'post_type' => $type, 'post_status' => 'draft', 'post_title' => 'Saved thumbnail form', 'user_ID' => get_current_user_id(), 'select_sites_nonce' => wp_create_nonce( 'select_sites_' . $post ), '_wpnonce' => 'invalid-nonce' === $mode ? 'invalid' : wp_create_nonce( 'update-post_' . $post ) );
        if ( 'unchanged' !== $mode ) { $_POST['_thumbnail_id'] = $_REQUEST['_thumbnail_id'] = 'remove' === $mode ? -1 : $attachment; }
        if ( ! in_array( $mode, array( 'unchanged', 'remove' ), true ) ) { delete_post_meta( $post, '_thumbnail_id' ); }
        add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
        $denied = false;
        try { do_action( 'admin_post_mainwp_editpost' ); } catch ( Bulk_Content_Redirect_Stop $error ) {} catch ( \WPDieException $error ) { $denied = true; }
        $expected = in_array( $mode, array( 'foreign', 'foreign-parent', 'invalid-nonce' ), true );
        $this->assertSame( $expected, $denied );
        $this->assertSame( $expected ? 'Original thumbnail form' : 'Saved thumbnail form', get_post( $post )->post_title );
        $this->assertSame( in_array( $mode, array( 'own', 'admin', 'native', 'unchanged' ), true ) ? (string) $attachment : '', get_post_meta( $post, '_thumbnail_id', true ) );
        if ( 'native' !== $mode && 'admin' !== $mode ) { $this->assertFalse( current_user_can( 'edit_post', $attachment ) ); $this->assertFalse( current_user_can( 'delete_post', $attachment ) ); }
    }

    public static function run2_reused_native_hooks() {
        return array( array( 'admin.php' ), array( 'admin-post.php' ), array( 'admin-ajax.php' ) );
    }

    /** @dataProvider run2_reused_native_hooks */
    public function test_run2_reused_core_load_hooks_preserve_mainwp_editor_context( $screen ) {
        $this->as_actor( 'staff_both' );
        $post = self::factory()->post->create( array( 'post_type' => 'bulkpost', 'post_author' => get_current_user_id() ) );
        $GLOBALS['pagenow'] = $screen;
        $_GET = $_REQUEST = array( 'post' => $post, 'post_type' => 'bulkpost' );
        foreach ( array( 'edit.php', 'post.php', 'post-new.php' ) as $hook ) { do_action( 'load-' . $hook ); }
        $this->assertTrue( current_user_can( 'edit_post', $post ) );
    }

    public static function run2_native_request_types() {
        $cases = array();
        foreach ( array( 'bulkpost', 'bulkpage' ) as $type ) {
            foreach ( array( 'post-only', 'get-post-post-bulk', 'get-bulk-post-post', 'ordinary', 'mainwp' ) as $request ) {
                foreach ( array( 'staff_both', 'staff_both_editor', 'editor', 'administrator' ) as $actor ) {
                    $cases[ $type . '/' . $request . '/' . $actor ] = array( $type, $request, $actor );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider run2_native_request_types */
    public function test_run2_native_list_uses_actual_core_request_type( $type, $request, $actor ) {
        $hidden = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_author' => $this->administrator, 'post_title' => 'Run2 hidden bulk title', 'post_content' => 'run2-content-oracle' ) );
        $ordinary = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => $this->administrator, 'post_title' => 'Run2 ordinary private title', 'post_content' => 'run2-content-oracle' ) );
        $this->as_actor( 'staff_both_editor' === $actor ? 'staff_both' : $actor );
        if ( 'staff_both_editor' === $actor ) { wp_get_current_user()->add_role( 'editor' ); }
        list( $visible, $hidden_site ) = $this->sites();
        update_post_meta( $hidden, '_mainwp_edit_post_site_id', $hidden_site );
        $GLOBALS['pagenow'] = 'mainwp' === $request ? 'admin.php' : 'edit.php';
        $GLOBALS['hook_suffix'] = 'edit.php';
        $_GET = array( 's' => 'run2-content-oracle' );
        $_POST = array( 'post_type' => $type );
        if ( 'get-post-post-bulk' === $request ) { $_GET['post_type'] = 'post'; }
        if ( 'get-bulk-post-post' === $request ) { $_GET['post_type'] = $type; $_POST['post_type'] = 'post'; }
        if ( 'ordinary' === $request ) { $_GET['post_type'] = $_POST['post_type'] = 'post'; }
        $_REQUEST = array_merge( $_GET, $_POST ); // Core wp_magic_quotes rebuilds REQUEST with this precedence.
        $GLOBALS['typenow'] = $_REQUEST['post_type'];
        set_current_screen(); // Actual Core derives this screen from REQUEST before the load hook.
        $this->assertSame( $_REQUEST['post_type'], get_current_screen()->post_type );
        $denied = false;
        $response = null;
        $queried = false;
        $titles = array();
        add_filter( 'wp_die_handler', static function () use ( &$response ) { return static function ( $message, $title, $args ) use ( &$response ) { $response = $args['response']; throw new \WPDieException( $message ); }; } );
        add_action( 'load-edit.php', static function () use ( &$queried, &$titles ) {
            $screen = get_current_screen();
            $object = get_post_type_object( $screen->post_type );
            if ( ! current_user_can( $object->cap->edit_posts ) ) { return; }
            $queried = true;
            wp_edit_posts_query(); // Actual native list query uses GET, independently of the screen selector.
            $titles = wp_list_pluck( $GLOBALS['wp_query']->posts, 'post_title' );
        }, 1 );
        try { do_action( 'load-edit.php' ); } catch ( \WPDieException $error ) { $denied = true; }
        $expected = 'administrator' !== $actor && in_array( $request, array( 'post-only', 'get-post-post-bulk', 'get-bulk-post-post' ), true );
        $this->assertSame( $expected, $denied, 'Native list must gate the type Core resolved, including POST precedence.' );
        if ( $expected ) {
            $this->assertSame( 403, $response );
            $this->assertFalse( $queried, 'Deny before native status counts or list query.' );
            $this->assertEmpty( $titles );
        } elseif ( 'administrator' === $actor && in_array( $request, array( 'post-only', 'get-post-post-bulk' ), true ) ) {
            $this->assertTrue( $queried );
            $this->assertContains( 'Run2 ordinary private title', $titles, 'Fixture proves the Core GET/REQUEST split can expose ordinary drafts.' );
        }
    }

}
