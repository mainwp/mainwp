<?php
/**
 * Native media access from Team Control bulk content editors.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

class Test_Bulk_Content_Media extends \WP_UnitTestCase {

    private $saved_globals = array();
    private $saved_hooks = array();
    private $saved_editor = array();
    private $administrator;
    private $grants = array();
    private $temporary_files = array();
    private $upload_directory;

    public function set_up() {
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';
        require_once ABSPATH . 'wp-includes/class-wp-editor.php';

        foreach ( array( '_GET', '_POST', '_REQUEST', '_FILES', '_SERVER', 'post', 'pagenow', 'plugin_page', 'current_screen', '_mainwp_default_post_to_edit', 'typenow', 'taxnow', 'current_user', 'user_ID', 'user_login', 'userdata', 'user_level', 'user_email', 'user_url', 'user_identity', 'authordata', 'wp_roles', 'wp_scripts', 'wp_styles', 'wp_actions', 'wp_filters', 'wp_current_filter', 'wp_admin_bar', 'can_compress_scripts', 'concatenate_scripts', 'compress_scripts', 'compress_css', 'user_can_richedit' ) as $name ) {
            $value = $GLOBALS[ $name ] ?? null;
            if ( is_object( $value ) ) {
                $value = clone $value;
                if ( in_array( $name, array( 'wp_scripts', 'wp_styles' ), true ) ) {
                    foreach ( $value->registered as $handle => $dependency ) {
                        $value->registered[ $handle ] = clone $dependency;
                    }
                }
            }
            $this->saved_globals[ $name ] = array( array_key_exists( $name, $GLOBALS ), $value );
        }
        foreach ( $GLOBALS['wp_filter'] as $name => $hook ) {
            $this->saved_hooks[ $name ] = clone $hook;
        }
        foreach ( ( new \ReflectionClass( \_WP_Editors::class ) )->getProperties( \ReflectionProperty::IS_STATIC ) as $property ) {
            $property->setAccessible( true );
            $this->saved_editor[ $property->getName() ] = $property->getValue();
        }

        $_GET = $_POST = $_REQUEST = $_FILES = array();
        $this->upload_directory = sys_get_temp_dir() . '/mainwp-bulk-media-' . wp_generate_uuid4();
        add_filter( 'upload_dir', array( $this, 'upload_directory' ) );
        $this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->administrator );
        update_option( 'mainwp_run_quick_setup', 'no' );
        add_role( 'mainwp_bulk_media_test', 'Bulk media staff', array( 'read' => true, 'level_10' => true ) );
        add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
        add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'block_transport' ), PHP_INT_MAX );
        add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ), PHP_INT_MAX );
        add_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ), PHP_INT_MAX );

        // Match admin-ajax.php registration without loading its terminating dispatcher.
        foreach ( array( 'query-attachments', 'get-attachment', 'upload-attachment', 'send-attachment-to-editor', 'save-attachment' ) as $action ) {
            add_action( 'wp_ajax_' . $action, 'wp_ajax_' . str_replace( '-', '_', $action ), 1 );
        }
    }

    public function tear_down() {
        foreach ( $this->temporary_files as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file );
            }
        }
        if ( is_dir( $this->upload_directory ) ) {
            rmdir( $this->upload_directory );
        }
        remove_role( 'mainwp_bulk_media_test' );
        foreach ( $this->saved_editor as $name => $value ) {
            $property = new \ReflectionProperty( \_WP_Editors::class, $name );
            $property->setAccessible( true );
            $property->setValue( null, $value );
        }
        $GLOBALS['wp_filter'] = $this->saved_hooks;
        parent::tear_down();
        foreach ( $this->saved_globals as $name => $state ) {
            if ( $state[0] ) {
                $GLOBALS[ $name ] = $state[1];
            } else {
                unset( $GLOBALS[ $name ] );
            }
        }
    }

    public function block_http() {
        return new \WP_Error( 'offline_bulk_media', 'HTTP disabled in bulk media tests.' );
    }

    public function block_transport() {
        throw new \RuntimeException( 'Unexpected Child transport in bulk media tests.' );
    }

    public function upload_directory( $uploads ) {
        $uploads['path'] = $uploads['basedir'] = $this->upload_directory;
        $uploads['url'] = $uploads['baseurl'] = 'https://example.org/wp-content/uploads';
        $uploads['subdir'] = '';
        $uploads['error'] = false;
        return $uploads;
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
            $role = $staff ? 'mainwp_bulk_media_test' : ( 'editor' === $actor ? 'editor' : 'subscriber' );
            wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
            if ( 'stale_level_10' === $actor ) {
                wp_get_current_user()->add_cap( 'level_10' );
            }
            if ( $staff ) {
                $this->assertSame( array( 'read' => true, 'level_10' => true ), get_role( $role )->capabilities );
                if ( in_array( $actor, array( 'staff_both', 'staff_posts' ), true ) ) {
                    $this->grants[] = 'manage_posts';
                }
                if ( in_array( $actor, array( 'staff_both', 'staff_pages' ), true ) ) {
                    $this->grants[] = 'manage_pages';
                }
                if ( 'staff_without_filter' !== $actor ) {
                    add_filter( 'mainwp_currentusercan', array( $this, 'team_control_right' ), 999, 3 );
                }
            }
        }
        return get_current_user_id();
    }

    private function create_parent( $type, $author ) {
        $actor = get_current_user_id();
        wp_set_current_user( $this->administrator );
        try {
            return self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft', 'post_author' => $author ) );
        } finally {
            wp_set_current_user( $actor );
        }
    }

    private function create_attachment( $author, $parent = 0 ) {
        $actor = get_current_user_id();
        wp_set_current_user( $this->administrator );
        try {
            return self::factory()->post->create( array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'text/plain',
                'post_author' => $author,
                'post_parent' => $parent,
                'post_title' => 'Bulk media fixture',
                'guid' => 'https://example.org/wp-content/uploads/bulk-media-fixture.txt',
                'meta_input' => array( '_wp_attached_file' => 'bulk-media-fixture.txt' ),
            ) );
        } finally {
            wp_set_current_user( $actor );
        }
    }

    private function editor_context( $type, $parent, $mode = 'Edit' ) {
        $page = ( 'bulkpost' === $type ? 'PostBulk' : 'PageBulk' ) . $mode;
        $GLOBALS['pagenow'] = 'admin.php';
        $GLOBALS['plugin_page'] = $page;
        $GLOBALS['post'] = get_post( $parent );
        $GLOBALS['typenow'] = $type;
        $_GET = $_REQUEST = array( 'page' => $page, 'post_id' => $parent );
        $_POST = array();
        set_current_screen( 'mainwp_page_' . $page );
    }

    private function ajax_context( $action, $request ) {
        $GLOBALS['pagenow'] = 'admin-ajax.php';
        unset( $GLOBALS['post'], $GLOBALS['plugin_page'], $GLOBALS['typenow'] );
        $_GET = array();
        $_POST = $_REQUEST = array_merge( array( 'action' => $action ), $request );
        set_current_screen( 'ajax' );
        add_filter( 'wp_doing_ajax', '__return_true' );
    }

    /** Capture core responses without printing media nonces or terminating PHPUnit. */
    private function dispatch_ajax( $action, $request ) {
        $this->ajax_context( $action, $request );
        $this->assertSame( 1, has_action( 'wp_ajax_' . $action, 'wp_ajax_' . str_replace( '-', '_', $action ) ) );
        $stopped = false;
        $message = '';
        // Core query-attachments sends pagination headers even in a CLI test process.
        $previous_handler = null;
        $previous_handler = set_error_handler( static function ( $severity, $warning, $file, $line ) use ( &$previous_handler ) {
            if ( E_WARNING === $severity && 0 === strpos( $warning, 'Cannot modify header information - headers already sent' ) ) {
                return true;
            }
            return is_callable( $previous_handler ) ? call_user_func( $previous_handler, $severity, $warning, $file, $line ) : false;
        }, E_WARNING );
        ob_start();
        try {
            do_action( 'wp_ajax_' . $action );
        } catch ( \WPDieException $error ) {
            $stopped = true;
            $message = $error->getMessage();
        } finally {
            $body = ob_get_clean();
            restore_error_handler();
        }
        $this->assertTrue( $stopped, 'The registered core media handler must terminate the response.' );
        return array( json_decode( $body, true ), $message );
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
            'editor' => array( true, true ),
            'stale_level_10' => array( false, false ),
            'anonymous' => array( false, false ),
        );
        $cases = array();
        foreach ( $actors as $actor => $allowed ) {
            foreach ( array( 'bulkpost', 'bulkpage' ) as $index => $type ) {
                $cases[ $actor . '/' . $type ] = array( $actor, $type, $allowed[ $index ] );
            }
        }
        return $cases;
    }

    /** @dataProvider permission_matrix */
    public function test_core_editor_media_buttons_follow_contextual_upload_capability( $actor, $type, $allowed ) {
        $author = $this->as_actor( $actor );
        $parent = $this->create_parent( $type, $author );
        foreach ( array( 'Add', 'Edit' ) as $mode ) {
            $this->editor_context( $type, $parent, $mode );
            $before = did_action( 'media_buttons' );
            ob_start();
            try {
                wp_editor( '', 'mwp2014media', array( 'tinymce' => false, 'quicktags' => false, 'media_buttons' => true ) );
            } finally {
                $html = ob_get_clean();
            }
            $this->assertSame( $allowed, current_user_can( 'upload_files' ), $actor . ' upload capability on ' . $type . ' ' . $mode );
            $this->assertSame( $allowed ? 1 : 0, did_action( 'media_buttons' ) - $before, $actor . ' native editor media buttons' );
            $this->assertSame( $allowed, false !== strpos( $html, 'insert-media add_media' ), $actor . ' rendered Add Media button' );
        }
    }

    /** @dataProvider permission_matrix */
    public function test_registered_attachment_query_requires_matching_grant( $actor, $type, $allowed ) {
        $author = $this->as_actor( $actor );
        $parent = $this->create_parent( $type, $author );
        $attachment = $this->create_attachment( $author, $parent );
        list( $json ) = $this->dispatch_ajax( 'query-attachments', array( 'post_id' => $parent, 'query' => array( 'post__in' => array( $attachment ), 'posts_per_page' => 20 ) ) );
        $this->assertIsArray( $json );
        $this->assertSame( $allowed, $json['success'], $actor . ' query-attachments on ' . $type );
        if ( $allowed ) {
            $this->assertSame( array( $attachment ), array_map( 'intval', array_column( $json['data'], 'id' ) ) );
        }
    }

    public static function media_contexts() {
        $cases = array();
        foreach ( array( 'staff_posts' => 'bulkpost', 'staff_pages' => 'bulkpage' ) as $actor => $type ) {
            foreach ( array( 'query-attachments', 'upload-attachment', 'get-attachment', 'send-attachment-to-editor' ) as $action ) {
                $cases[ $actor . '/' . $action ] = array( $actor, $type, 'admin-ajax.php', $action );
            }
            $cases[ $actor . '/async-upload.php' ] = array( $actor, $type, 'async-upload.php', 'upload-attachment' );
        }
        return $cases;
    }

    /** @dataProvider media_contexts */
    public function test_native_media_context_requires_own_editable_bulk_parent( $actor, $type, $script, $action ) {
        $author = $this->as_actor( $actor );
        $own = $this->create_parent( $type, $author );
        $foreign = $this->create_parent( $type, $this->administrator );
        $ordinary = $this->create_parent( 'post', $author );
        $opposite = $this->create_parent( 'bulkpost' === $type ? 'bulkpage' : 'bulkpost', $author );
        $attachment = $this->create_attachment( $author );
        foreach ( array( $own => true, $foreign => false, $ordinary => false, $opposite => false ) as $parent => $allowed ) {
            $this->ajax_context( $action, array( 'post_id' => $parent, 'id' => $attachment, 'attachment' => array( 'id' => $attachment ) ) );
            $GLOBALS['pagenow'] = $script;
            $this->assertSame( $allowed, current_user_can( 'upload_files' ), $actor . ' ' . $script . '/' . $action . ' parent ' . $parent );
        }
    }

    public static function invalid_parents() {
        return array(
            'missing' => array( null ),
            'zero' => array( 0 ),
            'string zero' => array( '0' ),
            'negative' => array( -1 ),
            'empty' => array( '' ),
            'text' => array( 'not-a-post' ),
            'array' => array( array( 1 ) ),
            'boolean' => array( true ),
            'fraction' => array( '1.5' ),
            'valid prefix with suffix' => array( 'own-suffix' ),
            'missing row' => array( PHP_INT_MAX ),
        );
    }

    /** @dataProvider invalid_parents */
    public function test_missing_or_malformed_parent_cannot_borrow_editor_context( $parent ) {
        $author = $this->as_actor( 'staff_both' );
        $own = $this->create_parent( 'bulkpost', $author );
        if ( 'own-suffix' === $parent ) {
            $parent = $own . 'junk';
        }
        $this->editor_context( 'bulkpost', $own );
        foreach ( array( 'query-attachments', 'upload-attachment' ) as $action ) {
            $request = null === $parent ? array() : array( 'post_id' => $parent );
            $this->ajax_context( $action, $request );
            // Stale globals must not supply a missing or invalid request parent.
            $GLOBALS['post'] = get_post( $own );
            $this->assertFalse( current_user_can( 'upload_files' ) );
        }
        list( $json ) = $this->dispatch_ajax( 'query-attachments', $request );
        $this->assertIsArray( $json );
        $this->assertFalse( $json['success'] );
        $this->editor_context( 'bulkpost', $own );
        unset( $GLOBALS['post'], $_GET['post_id'], $_REQUEST['post_id'] );
        if ( null !== $parent ) {
            $_GET['post_id'] = $_REQUEST['post_id'] = $parent;
        }
        $this->assertFalse( current_user_can( 'upload_files' ) );
    }

    public function test_editor_context_rejects_foreign_nonbulk_and_wrong_domain_parents() {
        $author = $this->as_actor( 'staff_both' );
        foreach ( array(
            array( 'bulkpost', $this->create_parent( 'bulkpost', $this->administrator ) ),
            array( 'bulkpage', $this->create_parent( 'bulkpage', $this->administrator ) ),
            array( 'bulkpost', $this->create_parent( 'post', $author ) ),
            array( 'bulkpost', $this->create_parent( 'bulkpage', $author ) ),
            array( 'bulkpage', $this->create_parent( 'bulkpost', $author ) ),
        ) as $case ) {
            $this->editor_context( $case[0], $case[1] );
            $this->assertFalse( current_user_can( 'upload_files' ) );
        }
    }

    public function test_media_library_and_forged_media_actions_do_not_grant_uploads() {
        $author = $this->as_actor( 'staff_both' );
        $parent = $this->create_parent( 'bulkpost', $author );
        foreach ( array( 'upload.php', 'media-new.php', 'media.php', 'media-upload.php', 'post.php', 'options-general.php', 'plugins.php', 'index.php' ) as $script ) {
            $this->editor_context( 'bulkpost', $parent );
            $GLOBALS['pagenow'] = $script;
            $_POST = $_REQUEST = array( 'action' => 'upload-attachment', 'post_id' => $parent, 'page' => 'PostBulkEdit' );
            $this->assertFalse( current_user_can( 'upload_files' ), $script . ' must ignore forged editor/media parameters' );
        }
        foreach ( array( 'save-attachment', 'delete-post', 'unrelated-action' ) as $action ) {
            $this->ajax_context( $action, array( 'post_id' => $parent ) );
            $this->assertFalse( current_user_can( 'upload_files' ), $action . ' must not borrow the bulk parent grant' );
        }
        $GLOBALS['pagenow'] = 'index.php';
        $_GET = $_POST = $_REQUEST = array();
        unset( $GLOBALS['post'], $GLOBALS['plugin_page'] );
        set_current_screen( 'dashboard' );
        $this->assertFalse( current_user_can( 'upload_files' ) );
    }

    public static function query_author_overrides() {
        return array(
            'unspecified' => array( null ),
            'foreign author' => array( 'foreign' ),
            'all authors' => array( 0 ),
            'negative author' => array( -1 ),
        );
    }

    /** @dataProvider query_author_overrides */
    public function test_actual_attachment_query_returns_only_own_uploads( $requested_author ) {
        $author = $this->as_actor( 'staff_both' );
        $parent = $this->create_parent( 'bulkpost', $author );
        $own = $this->create_attachment( $author, $parent );
        $own_unattached = $this->create_attachment( $author );
        $foreign_same_parent = $this->create_attachment( $this->administrator, $parent );
        $foreign_unattached = $this->create_attachment( $this->administrator );
        $query = array( 'post__in' => array( $own, $own_unattached, $foreign_same_parent, $foreign_unattached ), 'posts_per_page' => 20 );
        if ( null !== $requested_author ) {
            $query['author'] = 'foreign' === $requested_author ? $this->administrator : $requested_author;
        }
        list( $json ) = $this->dispatch_ajax( 'query-attachments', array( 'post_id' => $parent, 'query' => $query ) );
        $this->assertIsArray( $json );
        $this->assertTrue( $json['success'] );
        $actual = array_map( 'intval', array_column( $json['data'], 'id' ) );
        $expected = array( $own, $own_unattached );
        sort( $actual );
        sort( $expected );
        $this->assertSame( $expected, $actual, 'Other authors remain private even when their attachment shares the bulk parent.' );
    }

    public function test_parent_filter_does_not_expose_other_authors_attachments() {
        $author = $this->as_actor( 'staff_posts' );
        $parent = $this->create_parent( 'bulkpost', $author );
        $own = $this->create_attachment( $author, $parent );
        $foreign = $this->create_attachment( $this->administrator, $parent );
        list( $json ) = $this->dispatch_ajax( 'query-attachments', array( 'post_id' => $parent, 'query' => array( 'post_parent' => $parent, 'post__in' => array( $own, $foreign ), 'author' => $this->administrator ) ) );
        $this->assertIsArray( $json );
        $this->assertTrue( $json['success'] );
        $this->assertSame( array( $own ), array_map( 'intval', array_column( $json['data'], 'id' ) ) );
    }

    public function test_contextual_upload_does_not_grant_attachment_or_general_post_rights() {
        $author = $this->as_actor( 'staff_both' );
        $parent = $this->create_parent( 'bulkpost', $author );
        $own = $this->create_attachment( $author, $parent );
        $foreign = $this->create_attachment( $this->administrator, $parent );
        $this->editor_context( 'bulkpost', $parent );
        $this->assertTrue( current_user_can( 'upload_files' ) );
        foreach ( array( 'manage_options', 'unfiltered_upload', 'edit_others_posts', 'delete_others_posts', 'edit_posts', 'delete_posts' ) as $capability ) {
            $this->assertFalse( current_user_can( $capability ), $capability );
        }
        $this->assertFalse( current_user_can( 'edit_post', $own ) );
        $this->assertFalse( current_user_can( 'edit_post', $foreign ) );
        $this->assertFalse( current_user_can( 'read_post', $foreign ) );
        foreach ( array( $own, $foreign ) as $attachment ) {
            $this->assertFalse( current_user_can( 'delete_post', $attachment ) );
        }
        $GLOBALS['pagenow'] = 'upload.php';
        unset( $GLOBALS['post'], $GLOBALS['plugin_page'] );
        $_GET = $_POST = $_REQUEST = array();
        set_current_screen( 'upload' );
        $this->assertFalse( current_user_can( 'upload_files' ) );
        $this->assertFalse( current_user_can( 'edit_post', $own ) );
        $this->assertFalse( current_user_can( 'delete_post', $own ) );
        $this->assertFalse( current_user_can( 'edit_post', $foreign ) );
        $this->assertFalse( current_user_can( 'delete_post', $foreign ) );
    }

    public static function matching_media_grants() {
        return array(
            'posts only' => array( 'staff_posts', 'bulkpost' ),
            'pages only' => array( 'staff_pages', 'bulkpage' ),
            'both posts' => array( 'staff_both', 'bulkpost' ),
            'both pages' => array( 'staff_both', 'bulkpage' ),
        );
    }

    /** @dataProvider matching_media_grants */
    public function test_native_insert_into_post_accepts_own_upload_already_attached_to_authorized_bulk_parent( $actor, $type ) {
        $author = $this->as_actor( $actor );
        $parent = $this->create_parent( $type, $author );
        $attachment = $this->create_attachment( $author, $parent );
        $url = wp_get_attachment_url( $attachment );
        list( $json ) = $this->dispatch_ajax( 'send-attachment-to-editor', array(
            'post_id' => $parent,
            'nonce' => wp_create_nonce( 'media-send-to-editor' ),
            'attachment' => array( 'id' => $attachment, 'url' => $url, 'post_title' => 'Own upload' ),
        ) );
        $this->assertIsArray( $json );
        $this->assertTrue( $json['success'] );
        $this->assertStringContainsString( esc_url( $url ), $json['data'] );
        $this->assertSame( $parent, (int) get_post( $attachment )->post_parent );
        $this->assertSame( $author, (int) get_post( $attachment )->post_author );
        $this->assertFalse( current_user_can( 'edit_posts' ) );
        $this->assertFalse( current_user_can( 'edit_others_posts' ) );
        $this->assertFalse( current_user_can( 'edit_post', $attachment ) );
        $this->assertFalse( current_user_can( 'delete_post', $attachment ) );
        list( $json ) = $this->dispatch_ajax( 'get-attachment', array( 'post_id' => $parent, 'id' => $attachment ) );
        $this->assertIsArray( $json );
        $this->assertTrue( $json['success'] );
        $this->assertSame( $attachment, (int) $json['data']['id'] );
    }

    /** @dataProvider matching_media_grants */
    public function test_foreign_attachment_cannot_borrow_an_authorized_own_bulk_parent( $actor, $type ) {
        $author = $this->as_actor( $actor );
        $parent = $this->create_parent( $type, $author );
        foreach ( array( 0, $parent ) as $attachment_parent ) {
            $foreign = $this->create_attachment( $this->administrator, $attachment_parent );
            foreach ( array( 'get-attachment', 'send-attachment-to-editor' ) as $action ) {
                $request = array(
                    'post_id' => $parent,
                    'id' => $foreign,
                    'nonce' => wp_create_nonce( 'media-send-to-editor' ),
                    'attachment' => array( 'id' => $foreign, 'url' => wp_get_attachment_url( $foreign ), 'post_title' => 'Foreign upload' ),
                );
                $this->ajax_context( $action, $request );
                $this->assertFalse( current_user_can( 'read_post', $foreign ) );
                $this->assertFalse( current_user_can( 'edit_post', $foreign ) );
                $this->assertFalse( current_user_can( 'delete_post', $foreign ) );
                list( $json ) = $this->dispatch_ajax( $action, $request );
                $this->assertIsArray( $json );
                $this->assertFalse( $json['success'], $action . ' must refuse another author\'s attachment even with an own bulk parent.' );
                $this->assertArrayNotHasKey( 'data', $json );
                $this->assertSame( $attachment_parent, (int) get_post( $foreign )->post_parent );
            }
        }
    }

    public static function unsafe_attachment_parents() {
        return array( 'foreign bulk' => array( 'foreign' ), 'ordinary post' => array( 'ordinary' ) );
    }

    /** @dataProvider unsafe_attachment_parents */
    public function test_registered_upload_refuses_foreign_and_nonbulk_parents( $target ) {
        $author = $this->as_actor( 'staff_both' );
        $parent = $this->create_parent( 'foreign' === $target ? 'bulkpost' : 'post', 'foreign' === $target ? $this->administrator : $author );
        $_FILES['async-upload'] = array( 'name' => 'missing.txt', 'type' => 'text/plain', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE );
        list( $json ) = $this->dispatch_ajax( 'upload-attachment', array( 'post_id' => $parent, '_wpnonce' => wp_create_nonce( 'media-form' ) ) );
        $this->assertFalse( current_user_can( 'upload_files' ) );
        $this->assertIsArray( $json );
        $this->assertFalse( $json['success'] );
        $this->assertSame( __( 'Sorry, you are not allowed to upload files.' ), $json['data']['message'] );
        $this->assertSame( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $parent, 'fields' => 'ids' ) ) );
    }

    /** @dataProvider unsafe_attachment_parents */
    public function test_registered_attachment_save_cannot_reparent_uploads( $target ) {
        $author = $this->as_actor( 'staff_both' );
        $own_parent = $this->create_parent( 'bulkpost', $author );
        $target_parent = $this->create_parent( 'foreign' === $target ? 'bulkpost' : 'post', 'foreign' === $target ? $this->administrator : $author );
        foreach ( array( $author, $this->administrator ) as $attachment_author ) {
            $attachment = $this->create_attachment( $attachment_author, $own_parent );
            list( $json ) = $this->dispatch_ajax( 'save-attachment', array(
                'post_id' => $own_parent,
                'id' => $attachment,
                'nonce' => wp_create_nonce( 'update-post_' . $attachment ),
                'changes' => array( 'parent' => $target_parent, 'title' => 'Rejected media edit' ),
            ) );
            $this->assertSame( $own_parent, (int) get_post( $attachment )->post_parent );
            $this->assertSame( 'Bulk media fixture', get_post( $attachment )->post_title );
            $this->assertTrue( null === $json || false === $json['success'] );
        }
    }

    /** @dataProvider unsafe_attachment_parents */
    public function test_send_to_editor_cannot_attach_an_upload_to_an_unsafe_parent( $target ) {
        $author = $this->as_actor( 'staff_both' );
        $parent = $this->create_parent( 'foreign' === $target ? 'bulkpost' : 'post', 'foreign' === $target ? $this->administrator : $author );
        $attachment = $this->create_attachment( $author );
        $this->dispatch_ajax( 'send-attachment-to-editor', array(
            'post_id' => $parent,
            'nonce' => wp_create_nonce( 'media-send-to-editor' ),
            'attachment' => array( 'id' => $attachment, 'post_title' => 'Own upload' ),
        ) );
        $this->assertSame( 0, (int) get_post( $attachment )->post_parent );
    }

    public function test_granted_upload_request_reaches_core_file_validation() {
        $author = $this->as_actor( 'staff_posts' );
        $parent = $this->create_parent( 'bulkpost', $author );
        $_FILES['async-upload'] = array( 'name' => 'missing.txt', 'type' => 'text/plain', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE );
        list( $json ) = $this->dispatch_ajax( 'upload-attachment', array( 'post_id' => $parent, '_wpnonce' => wp_create_nonce( 'media-form' ) ) );
        $this->assertIsArray( $json );
        $this->assertFalse( $json['success'] );
        $this->assertSame( __( 'No file was uploaded.' ), $json['data']['message'] );
    }

    public function test_administrator_and_editor_keep_native_own_attachment_rights() {
        foreach ( array( 'administrator', 'editor' ) as $actor ) {
            $author = $this->as_actor( $actor );
            $parent = $this->create_parent( 'bulkpost', $author );
            $own = $this->create_attachment( $author, $parent );
            $other_author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
            $foreign = $this->create_attachment( $other_author, $parent );
            $this->ajax_context( 'query-attachments', array( 'post_id' => $parent ) );
            $this->assertTrue( current_user_can( 'upload_files' ) );
            $this->assertTrue( current_user_can( 'edit_post', $own ) );
            $this->assertTrue( current_user_can( 'delete_post', $own ) );
            list( $json ) = $this->dispatch_ajax( 'query-attachments', array( 'post_id' => $parent, 'query' => array( 'post__in' => array( $own, $foreign ) ) ) );
            $this->assertIsArray( $json );
            $this->assertTrue( $json['success'] );
            $ids = array_map( 'intval', array_column( $json['data'], 'id' ) );
            $this->assertContains( $own, $ids );
            $this->assertContains( $foreign, $ids );
        }
    }

    public function test_granted_upload_preserves_core_image_type_validation() {
        $author = $this->as_actor( 'staff_pages' );
        $parent = $this->create_parent( 'bulkpage', $author );
        $file = wp_tempnam( 'mwp2014-invalid-image.txt' );
        $this->temporary_files[] = $file;
        file_put_contents( $file, 'This is a text file.' );
        $_FILES['async-upload'] = array( 'name' => 'invalid-image.txt', 'type' => 'text/plain', 'tmp_name' => $file, 'size' => filesize( $file ), 'error' => UPLOAD_ERR_OK );
        list( $json ) = $this->dispatch_ajax( 'upload-attachment', array(
            'post_id' => $parent,
            '_wpnonce' => wp_create_nonce( 'media-form' ),
            'post_data' => array( 'post_type' => 'bulkpage', 'context' => 'custom-header' ),
        ) );
        $this->assertIsArray( $json );
        $this->assertFalse( $json['success'] );
        $this->assertSame( __( 'The uploaded file is not a valid image. Please try again.' ), $json['data']['message'] );
        $this->assertSame( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $parent, 'fields' => 'ids' ) ) );
    }

    public static function invalid_media_nonces() {
        return array(
            'upload missing' => array( 'upload-attachment', false ),
            'upload invalid' => array( 'upload-attachment', true ),
            'editor missing' => array( 'send-attachment-to-editor', false ),
            'editor invalid' => array( 'send-attachment-to-editor', true ),
        );
    }

    /** @dataProvider invalid_media_nonces */
    public function test_valid_grant_does_not_bypass_core_media_nonces( $action, $invalid ) {
        $author = $this->as_actor( 'staff_both' );
        $parent = $this->create_parent( 'bulkpost', $author );
        $attachment = $this->create_attachment( $author );
        $request = array( 'post_id' => $parent, 'attachment' => array( 'id' => $attachment ) );
        if ( $invalid ) {
            $request[ 'upload-attachment' === $action ? '_wpnonce' : 'nonce' ] = 'invalid-media-fixture';
        }
        $this->ajax_context( $action, $request );
        $this->assertTrue( current_user_can( 'upload_files' ), 'This request must hold the contextual grant before nonce verification.' );
        list( $json, $message ) = $this->dispatch_ajax( $action, $request );
        $this->assertNull( $json );
        $this->assertSame( '-1', $message );
        $this->assertSame( 0, (int) get_post( $attachment )->post_parent );
    }
    /** @dataProvider matching_media_grants */
    public function test_add_screen_uses_its_real_generated_own_auto_draft_as_media_parent( $actor, $type ) {
        $author = $this->as_actor( $actor );
        $this->editor_context( $type, 0, 'Add' );
        unset( $_GET['post_id'], $_REQUEST['post_id'], $GLOBALS['_mainwp_default_post_to_edit'] );
        $this->assertFalse( current_user_can( 'upload_files' ), 'A page slug alone grants no media access.' );
        $page_class = 'bulkpost' === $type ? '\MainWP\Dashboard\MainWP_Post' : '\MainWP\Dashboard\MainWP_Page';
        $page_class::on_load_add_edit();
        $draft = $GLOBALS['_mainwp_default_post_to_edit'];
        $this->assertInstanceOf( \WP_Post::class, $draft );
        $this->assertSame( 'auto-draft', $draft->post_status );
        $this->assertSame( $type, $draft->post_type );
        $this->assertSame( $author, (int) $draft->post_author );
        $this->assertTrue( current_user_can( 'upload_files' ) );
        $this->assertSame( $draft->ID, (int) $GLOBALS['post']->ID );
        $foreign = $this->create_parent( $type, $this->administrator );
        $GLOBALS['_mainwp_default_post_to_edit'] = get_post( $foreign );
        $this->assertFalse( current_user_can( 'upload_files' ) );
    }

    public function test_public_attachment_read_rights_are_unchanged_outside_authorized_bulk_media() {
        foreach ( array( 'staff_posts', 'staff_neither' ) as $actor ) {
            $author = $this->as_actor( $actor );
            $foreign = $this->create_attachment( $this->administrator );
            $GLOBALS['pagenow'] = 'index.php';
            $_GET = $_POST = $_REQUEST = array();
            $this->assertTrue( current_user_can( 'read_post', $foreign ), 'Core keeps public attachment reads on unrelated screens.' );
            if ( 'staff_neither' === $actor ) {
                $parent = $this->create_parent( 'bulkpost', $author );
                $this->ajax_context( 'get-attachment', array( 'post_id' => $parent, 'id' => $foreign ) );
                $this->assertTrue( current_user_can( 'read_post', $foreign ), 'No matching grant means the bulk media exception is inactive.' );
                list( $json ) = $this->dispatch_ajax( 'get-attachment', array( 'post_id' => $parent, 'id' => $foreign ) );
                $this->assertFalse( $json['success'] );
            }
        }
    }

    public static function combined_native_staff_roles() {
        return array( 'editor with TC' => array( 'editor', true ), 'editor without TC' => array( 'editor', false ), 'author with TC' => array( 'author', true ), 'author without TC' => array( 'author', false ) );
    }

    /** @dataProvider combined_native_staff_roles */
    public function test_combined_native_staff_roles_keep_existing_media_authority( $role, $team_control ) {
        $author = $this->as_actor( 'staff_posts' );
        wp_get_current_user()->add_role( $role );
        if ( ! $team_control ) { remove_filter( 'mainwp_currentusercan', array( $this, 'team_control_right' ), 999 ); }
        $this->assertTrue( wp_get_current_user()->allcaps['upload_files'] );
        $parent = $this->create_parent( 'bulkpost', $author );
        $foreign = $this->create_attachment( $this->administrator );
        $this->editor_context( 'bulkpost', $parent );
        $this->assertTrue( current_user_can( 'read_post', $foreign ), 'Bulk context must preserve native public attachment reads.' );
        list( $json ) = $this->dispatch_ajax( 'query-attachments', array( 'query' => array( 'post__in' => array( $foreign ), 'author' => $this->administrator ) ) );
        $this->assertTrue( $json['success'] );
        $this->assertSame( array( $foreign ), array_map( 'intval', array_column( $json['data'], 'id' ) ) );
        list( $json ) = $this->dispatch_ajax( 'get-attachment', array( 'id' => $foreign ) );
        $this->assertTrue( $json['success'] );
        $this->assertSame( $foreign, (int) $json['data']['id'] );
    }

    /** @dataProvider matching_media_grants */
    public function test_bulk_editor_enqueues_parent_adapter_for_featured_attachment_hydration( $actor, $type ) {
        $author = $this->as_actor( $actor );
        $parent = $this->create_parent( $type, $author );
        $attachment = $this->create_attachment( $author, $parent );
        update_post_meta( $parent, '_thumbnail_id', $attachment );
        $this->editor_context( $type, $parent );
        \MainWP\Dashboard\MainWP_Post::on_load_bulkpost( $parent );
        $inline = wp_scripts()->get_data( 'media-models', 'after' );
        $this->assertIsArray( $inline, 'Authorized bulk editors must receive the parent adapter.' );
        $script = implode( "\n", $inline );
        $this->assertStringContainsString( 'method==="read"', $script );
        $this->assertStringContainsString( 'post_id:parentId', $script );
        $this->assertStringContainsString( 'sync.call(this,method,model,options)', $script );
        $this->assertStringContainsString( ')(wp.media,' . $parent . ');', $script );
        $payload = array( 'action' => 'get-attachment', 'id' => $attachment, 'post_id' => $parent );
        list( $json ) = $this->dispatch_ajax( 'get-attachment', $payload );
        $this->assertTrue( $json['success'] );
        $this->assertSame( $attachment, (int) $json['data']['id'] );
        $this->assertFalse( current_user_can( 'edit_post', $attachment ) );
        $this->assertFalse( current_user_can( 'delete_post', $attachment ) );
        remove_filter( 'wp_doing_ajax', '__return_true' );
        $GLOBALS['pagenow'] = 'index.php';
        $_GET = $_POST = $_REQUEST = array();
        wp_scripts()->add_data( 'media-models', 'after', array() );
        \MainWP\Dashboard\MainWP_Post::on_load_bulkpost( $parent );
        $this->assertEmpty( wp_scripts()->get_data( 'media-models', 'after' ), 'Unrelated screens emit no scoped adapter.' );
        wp_get_current_user()->add_role( 'author' );
        $this->editor_context( $type, $parent );
        \MainWP\Dashboard\MainWP_Post::on_load_bulkpost( $parent );
        $this->assertEmpty( wp_scripts()->get_data( 'media-models', 'after' ), 'Native media authority needs no scoped adapter.' );
    }

}
