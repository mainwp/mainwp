<?php
/**
 * MainWP Bulk Post Class
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Class MainWP_Bulk_Post
 *
 * @package MainWP\Dashboard
 */
class MainWP_Bulk_Post { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * MainWP_Bulk_Post constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_post_mainwp_editpost', array( &$this, 'handle_edit_bulkpost' ) );
        add_action( 'save_post', array( &$this, 'save_bulkpost' ) );
        add_action( 'save_post', array( &$this, 'save_bulkpage' ) );
        add_action( 'init', array( &$this, 'create_post_type' ) );
        add_filter( 'post_updated_messages', array( &$this, 'post_updated_messages' ) );
        add_filter( 'user_has_cap', array( $this, 'bulk_user_has_cap' ), 10, 4 );
        add_filter( 'map_meta_cap', array( $this, 'deny_foreign_media_read' ), 10, 4 );
        add_action( 'admin_init', array( $this, 'guard_media_request' ), 0 );
        foreach ( array( 'edit.php', 'post.php', 'post-new.php' ) as $screen ) {
            add_action( 'load-' . $screen, array( $this, 'guard_native_bulk_screen' ), 0 );
        }
        add_filter( 'ajax_query_attachments_args', array( $this, 'media_query' ), PHP_INT_MAX );
        foreach ( static::media_actions() as $action ) {
            add_action( 'wp_ajax_' . $action, array( $this, 'guard_media_request' ), 0 );
        }
        foreach ( array( 'mainwp_posts_search', 'mainwp_pages_search', 'mainwp_post_get_edit', 'mainwp_post_postingbulk', 'mainwp_post_addmeta', 'mainwp_get_categories', 'mainwp_get_sites_of_groups', 'mainwp_post_unpublish', 'mainwp_post_publish', 'mainwp_post_trash', 'mainwp_post_delete', 'mainwp_post_restore', 'mainwp_post_approve', 'mainwp_page_unpublish', 'mainwp_page_publish', 'mainwp_page_trash', 'mainwp_page_delete', 'mainwp_page_restore' ) as $action ) {
            add_action( 'wp_ajax_' . $action, array( $this, 'guard_ajax_request' ), 0 );
        }
    }


    /**
     * Check the explicit grant for one bulk content type.
     *
     * @param string $type Bulk post type.
     * @return bool
     */
    public static function can_access( $type ) {
        if ( ! is_string( $type ) || ! in_array( $type, array( 'bulkpost', 'bulkpage' ), true ) || ! get_current_user_id() ) {
            return false;
        }
        return current_user_can( 'manage_options' ) || (
            MainWP_System_Utility::is_team_control_staff() &&
            apply_filters( 'mainwp_currentusercan', false, 'dashboard', 'bulkpost' === $type ? 'manage_posts' : 'manage_pages' )
        );
    }

    /**
     * Authorize a stored draft, including its imported source site.
     *
     * @param int    $post_id Local post ID.
     * @param string $type Optional expected bulk type.
     * @return bool
     */
    public static function can_access_post( $post_id, $type = '' ) {
        $post = get_post( $post_id );
        if ( ! $post || ( '' !== $type && $type !== $post->post_type ) || ! static::can_access( $post->post_type ) ) {
            return false;
        }
        $site_id = get_post_meta( $post->ID, '_mainwp_edit_post_site_id', true );
        if ( current_user_can( 'manage_options' ) || empty( $site_id ) ) {
            return true;
        }
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        return MainWP_System_Utility::can_edit_website( $website );
    }

    /**
     * Supply only the dedicated primitives WordPress requires for bulk content.
     *
     * @param array    $allcaps User capabilities.
     * @param array    $caps Required primitives.
     * @param array    $args Original capability and object arguments.
     * @param \WP_User $user Evaluated user.
     * @return array
     */
    public function bulk_user_has_cap( $allcaps, $caps, $args, $user ) {
        $bulk_caps = array(
            'mainwp_manage_bulk_posts' => 'bulkpost',
            'mainwp_manage_bulk_pages' => 'bulkpage',
        );
        foreach ( $bulk_caps as $cap => $type ) {
            if ( ! in_array( $cap, $caps, true ) ) {
                continue;
            }
            $allowed = ! empty( $user->ID ) && user_can( $user, 'manage_options' );
            if ( ! $allowed && get_current_user_id() === (int) $user->ID ) {
                $allowed = static::can_access( $type );
                if ( $allowed && isset( $args[2] ) ) {
                    $object_id = $args[2];
                    if ( in_array( $args[0], array( 'edit_comment', 'edit_comment_meta', 'add_comment_meta', 'delete_comment_meta' ), true ) ) {
                        $comment   = get_comment( $object_id );
                        $object_id = $comment ? $comment->comment_post_ID : 0;
                    }
                    $parent_id = wp_is_post_revision( $object_id );
                    $post      = get_post( $parent_id ? $parent_id : $object_id );
                    if ( $post && in_array( $post->post_type, array( 'bulkpost', 'bulkpage' ), true ) ) {
                        $allowed = static::can_access_post( $post->ID, $type );
                    }
                }
            }
            $allcaps[ $cap ] = $allowed;
        }
        if ( in_array( 'upload_files', $caps, true ) && get_current_user_id() === (int) $user->ID && empty( $allcaps['upload_files'] ) && static::media_context( true ) ) {
            $allcaps['upload_files'] = true;
        }
        return $allcaps;
    }

    /** Keep native bulk CPT screens restricted to administrators. */
    public function guard_native_bulk_screen() {
        global $pagenow;
        if ( ! in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php' ), true ) || current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- This load hook only refuses native screens.
        if ( 'load-post.php' === current_filter() ) {
            $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : ( isset( $_POST['post_ID'] ) ? (int) $_POST['post_ID'] : 0 );
            $type    = $post_id ? get_post_type( $post_id ) : false;
        } else {
            $type = isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
        }
        if ( 'load-edit.php' === current_filter() ) {
            $screen = get_current_screen();
            if ( $screen && in_array( $screen->post_type, array( 'bulkpost', 'bulkpage' ), true ) ) {
                $type = $screen->post_type;
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification
        if ( in_array( $type, array( 'bulkpost', 'bulkpage' ), true ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), '', array( 'response' => 403 ) );
        }
    }

    /**
     * Check an upload and its existing bulk parent without granting attachment capabilities.
     *
     * @param int $attachment_id Upload ID.
     * @return bool
     */
    private static function can_access_media_attachment( $attachment_id ) {
        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type || get_current_user_id() !== (int) $attachment->post_author ) {
            return false;
        }
        if ( ! $attachment->post_parent ) {
            return true;
        }
        $parent = get_post( $attachment->post_parent );
        return $parent && get_current_user_id() === (int) $parent->post_author && static::can_access_post( $parent->ID );
    }

    /**
     * Match native media requests to an editable bulk parent owned by this staff user.
     *
     * @param bool $for_upload Whether the check needs upload_files.
     * @return bool
     */
    private static function media_context( $for_upload = false ) {
        // phpcs:disable WordPress.Security.NonceVerification -- Core media handlers retain their nonce checks.
        global $pagenow;
        if ( ! MainWP_System_Utility::is_team_control_staff() ) {
            return false;
        }
        $page    = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        $screens = array(
            'postbulkadd'  => 'bulkpost',
            'postbulkedit' => 'bulkpost',
            'pagebulkadd'  => 'bulkpage',
            'pagebulkedit' => 'bulkpage',
        );
        if ( 'admin.php' === $pagenow && isset( $screens[ $page ] ) && ! wp_doing_ajax() ) {
            if ( ! static::can_access( $screens[ $page ] ) ) {
                return false;
            }
            if ( in_array( $page, array( 'postbulkadd', 'pagebulkadd' ), true ) && ! isset( $_GET['post_id'] ) ) {
                global $_mainwp_default_post_to_edit;
                $parent_id = $_mainwp_default_post_to_edit instanceof \WP_Post && 'auto-draft' === $_mainwp_default_post_to_edit->post_status ? $_mainwp_default_post_to_edit->ID : 0;
            } else {
                $parent_id = isset( $_GET['post_id'] ) && ( is_int( $_GET['post_id'] ) || is_string( $_GET['post_id'] ) ) && ctype_digit( (string) $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Strict decimal validation precedes absint.
            }
            $type = $screens[ $page ];
        } else {
            $action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
            if ( 'async-upload.php' !== $pagenow && ( ! wp_doing_ajax() || ! in_array( $action, static::media_actions(), true ) ) ) {
                return false;
            }
            if ( $for_upload && 'async-upload.php' !== $pagenow && ! in_array( $action, array( 'upload-attachment', 'query-attachments', 'get-attachment', 'send-attachment-to-editor', 'send-link-to-editor' ), true ) ) {
                return false;
            }
            $parent_id = isset( $_REQUEST['post_id'] ) && ( is_int( $_REQUEST['post_id'] ) || is_string( $_REQUEST['post_id'] ) ) && ctype_digit( (string) $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Strict decimal validation precedes absint.
            $type      = '';
        }
        $post = $parent_id ? get_post( $parent_id ) : null;
        return $post && get_current_user_id() === (int) $post->post_author && static::can_access_post( $parent_id, $type ) && current_user_can( 'edit_post', $parent_id );
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Carry the authorized parent on core attachment reads in this bulk editor.
     *
     * @param int $post_id Bulk parent ID.
     */
    public static function enqueue_media_parent( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || get_current_user_id() !== (int) $post->post_author || ! empty( wp_get_current_user()->allcaps['upload_files'] ) || ! static::media_context() || ! static::can_access_post( $post_id ) ) {
            return;
        }
        $script = '(function(media,parentId){var sync=media.model.Attachment.prototype.sync;media.model.Attachment.prototype.sync=function(method,model,options){if(method==="read"){options=options||{};options.data=Object.assign({},options.data||{},{post_id:parentId});}return sync.call(this,method,model,options);};})(wp.media,' . (int) $post_id . ');';
        wp_add_inline_script( 'media-models', $script );
    }

    /**
     * List native editor actions carrying post_id.
     *
     * @return array
     */
    private static function media_actions() {
        return array( 'upload-attachment', 'query-attachments', 'get-attachment', 'save-attachment', 'save-attachment-compat', 'save-attachment-order', 'send-attachment-to-editor', 'send-link-to-editor', 'get-post-thumbnail-html', 'set-post-thumbnail' );
    }

    /** Require the same parent boundary even for media actions without upload_files checks. */
    public function guard_media_request() {
        global $pagenow;
        if ( 'admin_init' === current_filter() && 'async-upload.php' !== $pagenow ) {
            return;
        }
        if ( ! empty( wp_get_current_user()->allcaps['upload_files'] ) || current_user_can( 'manage_options' ) || ! MainWP_System_Utility::is_team_control_staff() ) {
            return;
        }
        if ( ! static::media_context() ) {
            if ( 'wp_ajax_upload-attachment' === current_filter() && ! current_user_can( 'upload_files' ) ) {
                return; // Core retains its nonce and upload-permission response.
            }
            wp_send_json_error();
        }
        // phpcs:disable WordPress.Security.NonceVerification -- Core validates the action's nonce after this guard.
        $attachment_ids = array();
        if ( isset( $_REQUEST['id'] ) ) {
            $attachment_ids[] = absint( $_REQUEST['id'] );
        }
        if ( isset( $_POST['attachment']['id'] ) ) {
            $attachment_ids[] = absint( $_POST['attachment']['id'] );
        }
        if ( isset( $_REQUEST['attachment_id'] ) ) {
            $attachment_ids[] = absint( $_REQUEST['attachment_id'] );
        }
        if ( isset( $_REQUEST['attachments'] ) && is_array( $_REQUEST['attachments'] ) ) {
            $attachment_ids = array_merge( $attachment_ids, array_map( 'absint', array_keys( wp_unslash( $_REQUEST['attachments'] ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only numeric attachment keys are used.
        }
        if ( isset( $_REQUEST['thumbnail_id'] ) && 0 < (int) $_REQUEST['thumbnail_id'] ) {
            $attachment_ids[] = absint( $_REQUEST['thumbnail_id'] );
        }
        // phpcs:enable WordPress.Security.NonceVerification
        foreach ( $attachment_ids as $id ) {
            if ( ! static::can_access_media_attachment( $id ) ) {
                wp_send_json_error();
            }
        }
    }

    /**
     * Refuse foreign attachment reads that core would inherit from a shared draft.
     *
     * @param array  $caps Required capabilities.
     * @param string $cap Requested capability.
     * @param int    $user_id Evaluated user.
     * @param array  $args Object arguments.
     * @return array
     */
    public function deny_foreign_media_read( $caps, $cap, $user_id, $args ) {
        if ( 'read_post' === $cap && get_current_user_id() === (int) $user_id && isset( $args[0] ) && empty( wp_get_current_user()->allcaps['upload_files'] ) && MainWP_System_Utility::is_team_control_staff() && ! current_user_can( 'manage_options' ) ) {
            $post = get_post( $args[0] );
            if ( $post && 'attachment' === $post->post_type && get_current_user_id() !== (int) $post->post_author && static::media_context() ) {
                $caps[] = 'do_not_allow';
            }
        }
        return $caps;
    }

    /**
     * Keep the bulk editor's library limited to the current staff user's uploads.
     *
     * @param array $query Core attachment query.
     * @return array
     */
    public function media_query( $query ) {
        if ( empty( wp_get_current_user()->allcaps['upload_files'] ) && ! current_user_can( 'manage_options' ) && MainWP_System_Utility::is_team_control_staff() ) {
            $query['author'] = get_current_user_id();
        }
        return $query;
    }

    /** Authorize the content action before its existing nonce-protected callback. */
    public function guard_ajax_request() {
        // phpcs:disable WordPress.Security.NonceVerification -- Existing callbacks verify their action-specific nonces.
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        $action = substr( current_filter(), strlen( 'wp_ajax_' ) );
        if ( 'mainwp_post_addmeta' === $action ) {
            $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
            if ( ! isset( $_POST['metakeyselect'] ) && ! isset( $_POST['metakeyinput'] ) ) {
                $mid     = isset( $_POST['delete_meta'] ) && 'yes' === $_POST['delete_meta'] ? ( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ) : ( isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ? absint( key( wp_unslash( $_POST['meta'] ) ) ) : 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the numeric metadata key is used.
                $meta    = get_metadata_by_mid( 'post', $mid );
                $post_id = $meta ? $meta->post_id : 0;
            }
            $allowed = static::can_access_post( $post_id );
        } elseif ( 'mainwp_post_postingbulk' === $action ) {
            $allowed = static::can_access_post( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );
            if ( $allowed && ! current_user_can( 'manage_options' ) ) {
                $website = isset( $_POST['site_id'] ) ? MainWP_DB::instance()->get_website_by_id( absint( $_POST['site_id'] ) ) : null;
                $allowed = MainWP_System_Utility::can_edit_website( $website );
            }
        } elseif ( in_array( $action, array( 'mainwp_get_categories', 'mainwp_get_sites_of_groups' ), true ) ) {
            $allowed = static::can_access( 'bulkpost' ) || static::can_access( 'bulkpage' );
        } elseif ( 'mainwp_post_get_edit' === $action ) {
            $type    = isset( $_POST['postType'] ) && is_string( $_POST['postType'] ) ? sanitize_key( $_POST['postType'] ) : '';
            $allowed = '' !== $type && static::can_access( 'page' === $type ? 'bulkpage' : 'bulkpost' );
        } else {
            $allowed = static::can_access( 0 === strpos( $action, 'mainwp_page' ) ? 'bulkpage' : 'bulkpost' );
            if ( 'mainwp_posts_search' === $action ) {
                $search_type = isset( $_POST['post_type'] ) && is_string( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post';
                $search_type = sanitize_key( $search_type );
                $post_id     = isset( $_POST['postId'] ) ? sanitize_text_field( wp_unslash( $_POST['postId'] ) ) : '';
                $author_id   = isset( $_POST['userId'] ) ? sanitize_text_field( wp_unslash( $_POST['userId'] ) ) : '';
                if ( 'page' === $search_type || ( 'any' === $search_type && ( '' !== $post_id || '' !== $author_id ) ) ) {
                    $allowed = $allowed && static::can_access( 'bulkpage' );
                }
            }
        }
        if ( ! $allowed ) {
            wp_die( -1 );
        }
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Intersect unscoped group membership with the existing scoped site lookup.
     *
     * @param array $websites Group members.
     * @return array
     */
    public static function scope_group_sites( $websites ) {
        return array_filter(
            (array) $websites,
            static function ( $website ) {
                $scoped_site = MainWP_DB::instance()->get_website_by_id( $website->id );
                return MainWP_System_Utility::can_edit_website( $scoped_site );
            }
        );
    }

    /**
     * Authorize a remote object without invoking Child's lock-writing edit reader.
     *
     * @param object $website Scoped Child site.
     * @param int    $post_id Exact remote ID.
     * @param string $type Requested remote domain or custom post type.
     * @return bool
     */
    public static function can_access_remote_post( $website, $post_id, $type ) {
        if ( ! is_string( $type ) || '' === $type || ! static::can_access( 'page' === $type ? 'bulkpage' : 'bulkpost' ) ) {
            return false;
        }
        $rows = MainWP_Connect::fetch_url_authed(
            $website,
            'get_all_posts',
            array(
                'post_type'  => 'page' === $type ? 'page' : 'any',
                'postId'     => $post_id,
                'maxRecords' => 1,
                'status'     => 'any,trash,inherit,auto-draft',
            )
        );
        if ( is_array( $rows ) ) {
            unset( $rows['fetch_url_output'] );
        }
        if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! isset( $rows[0] ) || ! is_array( $rows[0] ) ) {
            return false;
        }
        $row = $rows[0];
        if ( ! isset( $row['id'], $row['post_type'] ) || ( ! is_int( $row['id'] ) && ! is_string( $row['id'] ) ) || ! ctype_digit( (string) $row['id'] ) || (int) $post_id !== (int) $row['id'] || ! is_string( $row['post_type'] ) ) {
            return false;
        }
        $actual = $row['post_type'];
        if ( 'page' === $type ) {
            return 'page' === $actual;
        }
        return '' !== $actual && sanitize_key( $actual ) === $actual && ! in_array( $actual, array( 'any', 'page', 'attachment', 'revision', 'bulkpost', 'bulkpage', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true ) && ( 'post' === $type || $type === $actual );
    }

    /**
     * Read the actual remote type from get_edit's post reader.
     *
     * The page reader labels every ID as page, including posts.
     *
     * @param array  $information Child response.
     * @param string $type Expected remote domain, including custom post types.
     * @return bool
     */
    public static function remote_type_matches( $information, $type ) {
        $encoded = isset( $information['my_post']['new_post'] ) ? $information['my_post']['new_post'] : null;
        $post    = is_string( $encoded ) ? json_decode( base64_decode( $encoded, true ), true ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Existing Child response encoding.
        $actual  = is_array( $post ) && isset( $post['post_type'] ) ? $post['post_type'] : null;
        if ( ! is_string( $actual ) || '' === $actual || ! isset( $information['status'] ) || 'SUCCESS' !== $information['status'] ) {
            return false;
        }
        if ( 'page' === $type ) {
            return 'page' === $actual && static::can_access( 'bulkpage' );
        }
        return ! in_array( $actual, array( 'page', 'attachment', 'revision', 'bulkpost', 'bulkpage' ), true ) && ( 'post' === $type || $type === $actual ) && static::can_access( 'bulkpost' );
    }

    /**
     * Method get_instance().
     */
    public static function get_instance() {
        return new self();
    }

    /**
     * Method handle_edit_bulkpost()
     *
     * Handle bulk post edit process.
     */
    public function handle_edit_bulkpost() {

        $post_id = 0;
        if ( isset( $_POST['post_ID'] ) ) {
            $post_id = (int) $_POST['post_ID'];
        }

        if ( $post_id && isset( $_POST['select_sites_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['select_sites_nonce'] ), 'select_sites_' . $post_id ) ) {
            check_admin_referer( 'update-post_' . $post_id );
            if ( ! current_user_can( 'manage_options' ) && ! static::can_access_post( $post_id ) ) {
                wp_die( -1 );
            }
            if ( empty( wp_get_current_user()->allcaps['upload_files'] ) && ! current_user_can( 'manage_options' ) && isset( $_POST['_thumbnail_id'] ) && 0 < (int) $_POST['_thumbnail_id'] && (int) get_post_meta( $post_id, '_thumbnail_id', true ) !== (int) $_POST['_thumbnail_id'] ) {
                $post = get_post( $post_id );
                if ( ! $post || get_current_user_id() !== (int) $post->post_author || ! static::can_access_media_attachment( (int) $_POST['_thumbnail_id'] ) ) {
                    wp_die( -1 );
                }
            }
            edit_post();

            $location = admin_url( 'admin.php?page=PostBulkEdit&post_id=' . $post_id . '&message=1' );

            /**
             * Filter: redirect_post_location
             *
             * Filters the location for the Edit process.
             *
             * @param int $post_id Post ID.
             *
             * @since Unknown
             */
            $location = apply_filters( 'redirect_post_location', $location, $post_id );
            wp_safe_redirect( $location );
            exit();
        }
    }

    /**
     * Method redirect_edit_bulkpost().
     *
     * Redirect to edit post.
     *
     * @param string $location Target URL.
     * @param int    $post_id Post ID number.
     *
     * @return string $location Target URL.
     */
    public function redirect_edit_bulkpost( $location, $post_id ) {
        unset( $location );
        if ( $post_id ) {
            $loc = admin_url( 'admin.php?page=PostBulkEdit&post_id=' . intval( $post_id ) );
        } else {
            $loc = admin_url( 'admin.php?page=PostBulkAdd' );
        }

        return $loc;
    }

    /**
     * Method redirect_edit_bulkpage().
     *
     * Redirect to edit page.
     *
     * @param string $location Target URL.
     * @param int    $post_id Page ID number.
     *
     * @return string $location Target URL.
     */
    public function redirect_edit_bulkpage( $location, $post_id ) {
        unset( $location );
        if ( $post_id ) {
            $loc = admin_url( 'admin.php?page=PageBulkEdit&post_id=' . intval( $post_id ) );
        } else {
            $loc = admin_url( 'admin.php?page=PageBulkAdd' );
        }

        return $loc;
    }


    /**
     * Method save_bulkpost().
     *
     * Save page (Bulk post custom post type).
     *
     * @param int $post_id Post ID.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Meta_Boxes::select_sites_handle()
     * @uses \MainWP\Dashboard\MainWP_Meta_Boxes::add_categories_handle()
     * @uses \MainWP\Dashboard\MainWP_Meta_Boxes::add_slug_handle()
     * @uses \MainWP\Dashboard\MainWP_Post_Page_Handler::add_sticky_handle()
     */
    public function save_bulkpost( $post_id ) {
        $_post = get_post( $post_id );

        if ( ! $_post || 'bulkpost' !== $_post->post_type || ! static::can_access_post( $post_id, 'bulkpost' ) ) {
            return;
        }

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'update-post_' . $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['post_type'] ) || ( 'bulkpost' !== $_POST['post_type'] ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
            return;
        }

        $pid = (int) MainWP_System::instance()->metaboxes->select_sites_handle( $post_id, 'bulkpost' );
        MainWP_System::instance()->metaboxes->add_categories_handle( $post_id, 'bulkpost' );
        MainWP_System::instance()->metaboxes->add_tags_handle( $post_id, 'bulkpost' );
        MainWP_System::instance()->metaboxes->add_slug_handle( $post_id, 'bulkpost' );
        MainWP_Post_Page_Handler::add_sticky_handle( $post_id );
        MainWP_Post_Page_Handler::add_status_handle( $post_id );

        /**
         * Action: mainwp_save_bulkpost
         *
         * Fires when saving the bulk post.
         *
         * @param int $post_id Post ID.
         *
         * @since Unknown
         */
        do_action( 'mainwp_save_bulkpost', $post_id );

        if ( $pid === $post_id ) {
            add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpost' ), 10, 2 );
        } else {
            /**
             * Action: mainwp_before_redirect_posting_bulkpost
             *
             * Fires before redirection to posting 'bulk post' page after post submission.
             *
             * @param object $_post Object containing post data.
             *
             * @since 4.0
             */
            do_action( 'mainwp_before_redirect_posting_bulkpost', $_post );
            wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPost&id=' . $post_id . '&hideall=1&posting_nonce=' . wp_create_nonce( 'posting_nonce_' . $post_id ) );
            die();
        }
    }

    /**
     * Method save_bulkpage().
     *
     * Save page (Bulk page custom post type).
     *
     * @param int $post_id Page ID.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_System::$metaboxes
     * @uses \MainWP\Dashboard\MainWP_Page::add_status_handle()
     */
    public function save_bulkpage( $post_id ) {

        $_post = get_post( $post_id );

        if ( ! $_post || 'bulkpage' !== $_post->post_type || ! static::can_access_post( $post_id, 'bulkpage' ) ) {
            return;
        }

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'update-post_' . $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['post_type'] ) || ( 'bulkpage' !== $_POST['post_type'] ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
            return;
        }

        $pid = (int) MainWP_System::instance()->metaboxes->select_sites_handle( $post_id, 'bulkpage' );
        MainWP_System::instance()->metaboxes->add_slug_handle( $post_id, 'bulkpage' );
        MainWP_Post_Page_Handler::add_status_handle( $post_id );

        /**
         * Action: mainwp_save_bulkpage
         *
         * Fires when saving the bulk page.
         *
         * @param int $post_id Post ID.
         *
         * @since Unknown
         */
        do_action( 'mainwp_save_bulkpage', $post_id );

        if ( $pid === $post_id ) {
            add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpage' ), 10, 2 );
        } else {
            /**
             * Action: mainwp_before_redirect_posting_bulkpage
             *
             * Fires before redirection to posting 'bulk page' page after post submission.
             *
             * @param object $_post Object containing post data.
             *
             * @since 4.0
             */
            do_action( 'mainwp_before_redirect_posting_bulkpage', $_post );
            wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&posting_nonce=' . wp_create_nonce( 'posting_nonce_' . $post_id ) . '&id=' . $post_id . '&hideall=1' );
            die();
        }
    }

    /**
     * Method create_post_type()
     *
     * Register "Bulkpost" and "Bulkpage" custom post types.
     */
    public function create_post_type() {

        $queryable = true;
        if ( function_exists( 'is_plugin_active' ) ) {
            $queryable = is_plugin_active( 'mainwp-post-plus-extension/mainwp-post-plus-extension.php' );
        }

        $labels = array(
            'name'               => _x( 'Bulkpost', 'bulkpost', 'mainwp' ),
            'singular_name'      => _x( 'Bulkpost', 'bulkpost', 'mainwp' ),
            'add_new'            => _x( 'Add New', 'bulkpost', 'mainwp' ),
            'add_new_item'       => _x( 'Add New Bulkpost', 'bulkpost', 'mainwp' ),
            'edit_item'          => _x( 'Edit Bulkpost', 'bulkpost', 'mainwp' ),
            'new_item'           => _x( 'New Bulkpost', 'bulkpost', 'mainwp' ),
            'view_item'          => _x( 'View Bulkpost', 'bulkpost', 'mainwp' ),
            'search_items'       => _x( 'Search Bulkpost', 'bulkpost', 'mainwp' ),
            'not_found'          => _x( 'No bulkpost found', 'bulkpost', 'mainwp' ),
            'not_found_in_trash' => _x( 'No bulkpost found in Trash', 'bulkpost', 'mainwp' ),
            'parent_item_colon'  => _x( 'Parent Bulkpost:', 'bulkpost', 'mainwp' ),
            'menu_name'          => _x( 'Bulkpost', 'bulkpost', 'mainwp' ),
        );

        $args = array(
            'labels'              => $labels,
            'hierarchical'        => false,
            'description'         => __( 'description...', 'mainwp' ),
            'supports'            => array(
                'title',
                'editor',
                'excerpt',
                'thumbnail',
                'custom-fields',
                'comments',
                'revisions',
            ),
            'public'              => true,
            'show_ui'             => true,
            'show_in_nav_menus'   => false,
            'publicly_queryable'  => $queryable,
            'exclude_from_search' => true,
            'has_archive'         => false,
            'query_var'           => false,
            'can_export'          => false,
            'rewrite'             => false,
            'capabilities'        => $this->get_bulk_capabilities( 'mainwp_manage_bulk_posts' ),
        );

        register_post_type( 'bulkpost', $args );

        $labels = array(
            'name'               => _x( 'Bulkpage', 'bulkpage', 'mainwp' ),
            'singular_name'      => _x( 'Bulkpage', 'bulkpage', 'mainwp' ),
            'add_new'            => _x( 'Add New', 'bulkpage', 'mainwp' ),
            'add_new_item'       => _x( 'Add New Bulkpage', 'bulkpage', 'mainwp' ),
            'edit_item'          => _x( 'Edit Bulkpage', 'bulkpage', 'mainwp' ),
            'new_item'           => _x( 'New Bulkpage', 'bulkpage', 'mainwp' ),
            'view_item'          => _x( 'View Bulkpage', 'bulkpage', 'mainwp' ),
            'search_items'       => _x( 'Search Bulkpage', 'bulkpage', 'mainwp' ),
            'not_found'          => _x( 'No bulkpage found', 'bulkpage', 'mainwp' ),
            'not_found_in_trash' => _x( 'No bulkpage found in Trash', 'bulkpage', 'mainwp' ),
            'parent_item_colon'  => _x( 'Parent Bulkpage:', 'bulkpage', 'mainwp' ),
            'menu_name'          => _x( 'Bulkpage', 'bulkpage', 'mainwp' ),
        );

        $args = array(
            'labels'              => $labels,
            'hierarchical'        => false,
            'description'         => __( 'description...', 'mainwp' ),
            'supports'            => array(
                'title',
                'editor',
                'excerpt',
                'thumbnail',
                'custom-fields',
                'comments',
                'revisions',
            ),
            'public'              => true,
            'show_ui'             => true,
            'show_in_nav_menus'   => false,
            'publicly_queryable'  => $queryable,
            'exclude_from_search' => true,
            'has_archive'         => false,
            'query_var'           => false,
            'can_export'          => false,
            'rewrite'             => false,
            'capabilities'        => $this->get_bulk_capabilities( 'mainwp_manage_bulk_pages' ),
        );

        register_post_type( 'bulkpage', $args );

        do_action( 'mainwp_register_post_type' );
    }

    /**
     * Get capabilities for one MainWP bulk content type.
     *
     * @param string $capability Dedicated primitive.
     *
     * @return array
     */
    private function get_bulk_capabilities( $capability ) {
        return array(
            'edit_post'              => $capability,
            'read_post'              => $capability,
            'delete_post'            => $capability,
            'edit_posts'             => $capability,
            'edit_others_posts'      => $capability,
            'delete_posts'           => $capability,
            'publish_posts'          => $capability,
            'read_private_posts'     => $capability,
            'read'                   => $capability,
            'delete_private_posts'   => $capability,
            'delete_published_posts' => $capability,
            'delete_others_posts'    => $capability,
            'edit_private_posts'     => $capability,
            'edit_published_posts'   => $capability,
            'create_posts'           => $capability,
        );
    }

    /**
     * Method post_updated_messages()
     *
     * Render post updated message.
     *
     * @param string $messages Message to display.
     *
     * @return string $messages.
     */
    public function post_updated_messages( $messages ) {
        $messages['post'][98] = esc_html__( 'WordPress SEO values have been saved.', 'mainwp' );
        $messages['post'][99] = esc_html__( 'You have to select the sites you wish to publish to.', 'mainwp' );

        return $messages;
    }
}
