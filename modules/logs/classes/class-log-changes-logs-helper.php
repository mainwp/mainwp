<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;

/**
 * Class Log_Changes_logs_Helper
 *
 * @package MainWP\Dashboard
 */
class Log_Changes_logs_Helper {


    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Holds the array with the event types
     *
     * @var array
     */
    private static $event_action_types = array();

    /**
     * Holds the last log created time.
     *
     * @var array
     */
    private static $last_log_created;

    /**
     * Return the single instance of the class.
     *
     * @return mixed $instance The single instance of the class.
     */
    public static function instance() {
        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Plugin constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
    }

    /**
     * Method get_sync_changes_logs_last_created().
     *
     * Sync site changes logs data.
     *
     * @param int $site_id site id.
     *
     * @return bool
     */
    public function get_sync_changes_logs_last_created( $site_id ) {

        if ( null === static::$last_log_created ) {
            $site_opts = MainWP_DB::instance()->get_website_options_array( $site_id, array( 'changes_logs_sync_last_created' ) );

            if ( ! is_array( $site_opts ) ) {
                $site_opts = array();
            }

            static::$last_log_created = isset( $site_opts['changes_logs_sync_last_created'] ) ? $site_opts['changes_logs_sync_last_created'] : 0;

            // to sure.
            if ( empty( static::$last_log_created ) ) {
                $last_item = Log_DB_Helper::instance()->get_latest_changes_logs_by_siteid( $site_id );
                if ( $last_item ) {
                    $last_item                = $last_item[0];
                    static::$last_log_created = $last_item->created;
                }
            }
        }

        return static::$last_log_created;
    }


    /**
     * Method sync_changes_logs().
     *
     * Sync site changes logs data.
     *
     * @param int    $site_id site id.
     * @param array  $sync_changes action data.
     * @param object $website website data.
     *
     * @return bool
     */
    public function sync_changes_logs( $site_id, $sync_changes, $website ) { // phpcs:ignore -- NOSONAR - complex.

        if ( empty( $sync_changes ) || ! is_array( $sync_changes ) ) {
            return false;
        }

        $sync_last_created = $this->get_sync_changes_logs_last_created( $site_id );
        $new_last_created  = 0;

        foreach ( $sync_changes as $data ) {

            if ( ! is_array( $data ) || empty( $data['created_on'] ) || empty( $data['log_type_id'] ) ) {
                continue;
            }

            // to fix issue of last sync value.
            if ( $new_last_created < $data['created_on'] ) {
                $new_last_created = $data['created_on'];
            }

            if ( $sync_last_created > $data['created_on'] ) {
                continue;
            }

            $enable_log_type = apply_filters( 'mainwp_module_log_enable_insert_log_type', true, $data );
            if ( ! $enable_log_type ) {
                continue;
            }

            $type_id    = intval( $data['log_type_id'] );
            $user_id    = ! empty( $data['user_id'] ) ? sanitize_text_field( $data['user_id'] ) : 0;
            $user_login = ! empty( $data['username'] ) ? sanitize_text_field( $data['username'] ) : '';

            if ( empty( $user_login ) ) {
                continue;
            }

            $meta_data = isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ? $data['meta_data'] : array();
            $user_meta = isset( $meta_data['user_meta'] ) && is_array( $meta_data['user_meta'] ) ? $meta_data['user_meta'] : array();

            $meta                   = array();
            $meta['user_meta_json'] = wp_json_encode( $user_meta );

            $action   = isset( $data['event_type'] ) ? $this->map_changes_action( $data['event_type'] ) : '';
            $context  = isset( $data['log_type_id'] ) ? $this->map_change_logs_context( $data['log_type_id'] ) : '';
            $duration = isset( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : 0; // sanitize_text_field for seconds.
            $created  = isset( $data['created_on'] ) ? (float) ( $data['created_on'] ) : microtime( true );

            $record_mapping = array(
                'site_id'    => $site_id,
                'user_id'    => $user_id,
                'user_login' => $user_login,
                'created'    => $created,
                'context'    => $context,
                'action'     => $action,
                'state'      => 1,
                'duration'   => $duration,
                'meta'       => $meta,
            );

            if ( null !== $type_id ) {
                $record_mapping['log_type_id'] = $type_id;
            }

            $sum = 'wordpress' !== $record_mapping['context'] ? esc_html( ucfirst( rtrim( $record_mapping['context'], 's' ) ) ) : 'WordPress'; //phpcs:ignore -- wordpress text.
            if ( 'wordpress' === $record_mapping['context'] ) {
                $sum = 'WordPress';
            }
            $record_mapping['item'] = $sum;
            do_action( 'mainwp_sync_site_log_changes_logs', $website, $record_mapping );
        }

        if ( $new_last_created ) {
            MainWP_DB::instance()->update_website_option( $site_id, 'changes_logs_sync_last_created', $new_last_created );
        }

        return true;
    }

    /**
     * Method map_changes_action.
     *
     * @param string $event Logs event.
     *
     * @return string Dashboard logs actions to store in db.
     */
    public function map_changes_action( $event ) {

        $map_actions = array(
            'installed'   => 'install',
            'deleted'     => 'delete',
            'activated'   => 'activate',
            'deactivated' => 'deactivate',
        );

        return isset( $map_actions[ $event ] ) ? $map_actions[ $event ] : $event;
    }

    /**
     * Method map_change_logs_context()
     *
     * @param string $type Logs type code.
     *
     * @return string Dashboard logs context to store in db.
     */
    public function map_change_logs_context( $type ) {
        $context = isset( $this->get_changes_logs_types( $type )['object'] ) ? $this->get_changes_logs_types( $type )['object'] : '';
        $context = 'cron-job' === $context ? 'cron' : $context;
        return \apply_filters( 'mainwp_module_log_changes_logs_mapping_contexts', $context, $type );
    }

    /**
     * Get custom event title.
     *
     * @param int   $log_type_id    - Event type id.
     * @param array $data    - Event data.
     *
     * @return string
     */
    public static function get_log_title( $log_type_id, $data = array() ) {
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        $title = static::get_changes_events_title_default( $log_type_id );
        if ( ! empty( $title ) ) {
            if ( isset( $data['action'] ) ) {
                $title = str_replace( '%action%', $data['action'], $title );
            }
            return $title;
        }
        return '';
    }


    /**
     * Method get_changes_events_title_default().
     *
     * @param string $type_id Type of logs info.
     *
     * @return array data.
     */
    public static function get_changes_events_title_default( $type_id ) {
        $defaults = array(
            15028 => array(
                __( '%action% automatic update', 'mainwp' ),
            ),
        );
        return isset( $defaults[ $type_id ] ) ? $defaults[ $type_id ][0] : '';
    }


    /**
     * Loads changes logs groups.
     *
     * @return array
     */
    public static function get_groups_changes_logs() {
        return array(
            array(
                'context' => 'post',
                'title'   => esc_html__( 'Post', 'mainwp' ),
            ),
            array(
                'context' => 'custom-field',
                'title'   => esc_html__( 'Custom field', 'mainwp' ),
            ),
            array(
                'context' => 'category',
                'title'   => esc_html__( 'Categories', 'mainwp' ),
            ),
            array(
                'context' => 'tag',
                'title'   => esc_html__( 'Tag', 'mainwp' ),
            ),
            array(
                'context' => 'file',
                'title'   => esc_html__( 'File', 'mainwp' ),
            ),
            array(
                'context' => 'widget',
                'title'   => esc_html__( 'Widget', 'mainwp' ),
            ),
            array(
                'context' => 'plugin',
                'title'   => esc_html__( 'Plugin', 'mainwp' ),
            ),
            array(
                'context' => 'theme',
                'title'   => esc_html__( 'Theme', 'mainwp' ),
            ),
            array(
                'context' => 'menu',
                'title'   => esc_html__( 'Menu', 'mainwp' ),
            ),
            array(
                'context' => 'comment',
                'title'   => esc_html__( 'Comment', 'mainwp' ),
            ),
            array(
                'context' => 'user',
                'title'   => esc_html__( 'User', 'mainwp' ),
            ),
            array(
                'context' => 'database',
                'title'   => esc_html__( 'Database', 'mainwp' ),
            ),
            array(
                'context' => 'cron-job',
                'title'   => esc_html__( 'WordPress Cron', 'mainwp' ),
            ),
            array(
                'context' => 'system-setting',
                'title'   => esc_html__( 'System setting', 'mainwp' ),
            ),
        );
    }

    /**
     * Method get_changes_logs_types().
     *
     * @return array data.
     */
    public static function get_changes_logs_types() { //phpcs:ignore -- NOSONAR - long function.
        return array(
            // Post.
            12000 => array(
                'type_id'    => 12000,
                'desc'       => esc_html__( 'Created a new post', 'mainwp' ),
                'message'    => esc_html__( 'Created the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'created',
            ),
            12001 => array(
                'type_id'    => 12001,
                'desc'       => esc_html__( 'Published a post', 'mainwp' ),
                'message'    => esc_html__( 'Published the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'published',
            ),
            12002 => array(
                'type_id'    => 12002,
                'desc'       => esc_html__( 'Modified a post', 'mainwp' ),
                'message'    => esc_html__( 'Modified the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12008 => array(
                'type_id'    => 12008,
                'desc'       => esc_html__( 'Permanently deleted a post', 'mainwp' ),
                'message'    => esc_html__( 'Permanently deleted the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'deleted',
            ),
            12012 => array(
                'type_id'    => 12012,
                'desc'       => esc_html__( 'Moved a post to trash', 'mainwp' ),
                'message'    => esc_html__( 'Moved the post %PostTitle% to trash.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'deleted',
            ),
            12014 => array(
                'type_id'    => 12014,
                'desc'       => esc_html__( 'Restored a post from trash', 'mainwp' ),
                'message'    => esc_html__( 'Restored the post %PostTitle% from trash.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'restored',
            ),
            12016 => array(
                'type_id'  => 12016,
                'desc'     => esc_html__( 'Changed the category of a post', 'mainwp' ),
                'message'  => esc_html__( 'Changed the category(ies) of the post %PostTitle%.', 'mainwp' ),
                'meta_log' => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'New category(ies)', 'mainwp' ) => '%NewCategories%',
                    esc_html__( 'Previous category(ies)', 'mainwp' ) => '%OldCategories%',
                ),
                'object'   => 'post',
                'message'  => 'modified',
            ),
            12017 => array(
                'type_id'    => 12017,
                'desc'       => esc_html__( 'Changed the URL of a post', 'mainwp' ),
                'message'    => esc_html__( 'Changed the URL of the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )      => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )    => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' )  => '%PostStatus%',
                    esc_html__( 'Previous URL', 'mainwp' ) => '%OldUrl%',
                    esc_html__( 'New URL', 'mainwp' )      => '%NewUrl%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12019 => array(
                'type_id'    => 12019,
                'desc'       => esc_html__( 'Changed the author of a post', 'mainwp' ),
                'message'    => esc_html__( 'Changed the author of the post %PostTitle% to %NewAuthor%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Previous author', 'mainwp' ) => '%OldAuthor%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12021 => array(
                'type_id'    => 12021,
                'desc'       => esc_html__( 'Changed the status of a post', 'mainwp' ),
                'message'    => esc_html__( 'Changed the status of the post %PostTitle% to %NewStatus%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )   => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' ) => '%PostType%',
                    esc_html__( 'Previous status', 'mainwp' ) => '%OldStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12047 => array(
                'type_id'    => 12047,
                'desc'       => esc_html__( 'Changed the parent of a post', 'mainwp' ),
                'tite'       => esc_html__( 'Changed the parent of the post %PostTitle% to %NewParentName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Previous parent', 'mainwp' ) => '%OldParentName%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12048 => array(
                'type_id'    => 12048,
                'desc'       => esc_html__( 'Changed the template of a post', 'mainwp' ),
                'message'    => esc_html__( 'Changed the template of the post %PostTitle% to %NewTemplate%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Previous template', 'mainwp' ) => '%OldTemplate%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12049 => array(
                'type_id'    => 12049,
                'desc'       => esc_html__( 'Set a post as Sticky', 'mainwp' ),
                'message'    => esc_html__( 'Set the post %PostTitle% as sticky.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12050 => array(
                'type_id'    => 12050,
                'desc'       => esc_html__( 'Removed post from Sticky', 'mainwp' ),
                'message'    => esc_html__( 'Removed the post %PostTitle% from sticky.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12053 => array(
                'type_id'    => 12053,
                'desc'       => esc_html__( 'Created a custom field in a post', 'mainwp' ),
                'message'    => esc_html__( 'Created the new custom field %MetaKey% in the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Custom field value', 'mainwp' ) => '%MetaValue%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12073 => array(
                'type_id'    => 12073,
                'desc'       => esc_html__( 'Submitted post for review', 'mainwp' ),
                'message'    => esc_html__( 'Submitted the post %PostTitle% for review.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12074 => array(
                'type_id'    => 12074,
                'desc'       => esc_html__( 'Scheduled a post for publishing', 'mainwp' ),
                'message'    => esc_html__( 'Scheduled the post %PostTitle% to be published on %PublishingDate%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12025 => array(
                'type_id'    => 12025,
                'desc'       => esc_html__( 'User changed the visibility of a post', 'mainwp' ),
                'message'    => esc_html__( 'Changed the visibility of the post %PostTitle% to %NewVisibility%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Previous visibility status', 'mainwp' ) => '%OldVisibility%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12027 => array(
                'type_id'    => 12027,
                'disc'       => esc_html__( 'Changed the date of a post', 'mainwp' ),
                'message'    => esc_html__( 'Changed the date of the post %PostTitle% to %NewDate%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Previous date', 'mainwp' ) => '%OldDate%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12065 => array(
                'type_id'    => 12065,
                'desc'       => esc_html__( 'Modified the content of a post', 'mainwp' ),
                'message'    => esc_html__( 'Modified the content of the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12086 => array(
                'type_id'    => 12086,
                'desc'       => esc_html__( 'Changed title of a post', 'mainwp' ),
                'message'    => esc_html__( 'Changed the title of the post %OldTitle% to %NewTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12100 => array(
                'type_id'    => 12100,
                'desc'       => esc_html__( 'Opened a post in editor', 'mainwp' ),
                'message'    => esc_html__( 'Opened the post %PostTitle% in the editor.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'opened',
            ),
            12101 => array(
                'type_id'    => 12101,
                'desc'       => esc_html__( 'Viewed a post', 'mainwp' ),
                'message'    => esc_html__( 'Viewed the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'viewed',
            ),
            12111 => array(
                'type_id'    => 12111,
                'desc'       => esc_html__( 'Enabled / disabled comments in a post', 'mainwp' ),
                'message'    => esc_html__( 'Comments in the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'enabled',
            ),
            12112 => array(
                'type_id'    => 12112,
                'desc'       => esc_html__( 'Enabled / disabled trackbacks in a post', 'mainwp' ),
                'message'    => esc_html__( 'Pingbacks and Trackbacks in the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'enabled',
            ),
            12129 => array(
                'type_id'    => 12129,
                'desc'       => esc_html__( 'Updated the excerpt of a post', 'mainwp' ),
                'message'    => esc_html__( 'The excerpt of the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Previous excerpt entry', 'mainwp' ) => '%old_post_excerpt%',
                    esc_html__( 'New excerpt entry', 'mainwp' ) => '%post_excerpt%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            // xxxx, // Added / changed / removed a post’s excerpt ???.
            12130 => array(
                'type_id'    => 12130,
                'desc'       => esc_html__( 'Updated the feature image of a post', 'mainwp' ),
                'message'    => esc_html__( 'The featured image of the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Previous image', 'mainwp' ) => '%previous_image%',
                    esc_html__( 'New image', 'mainwp' )   => '%new_image%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12133 => array(
                'type_id'    => 12133,
                'desc'       => esc_html__( 'Taken over a post from another user', 'mainwp' ),
                'message'    => esc_html__( 'Has taken over the post %PostTitle% from %user%', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            // Custom field.
            12131 => array(
                'type_id'    => 12131,
                'desc'       => esc_html__( 'Added a relationship in an ACF custom field', 'mainwp' ),
                'message'    => esc_html__( 'Added relationships to the custom field %MetaKey% in the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'New relationships', 'mainwp' ) => '%Relationships%',
                ),
                'object'     => 'custom-field',
                'event_type' => 'modified',
            ),
            12132 => array(
                'type_id'    => 12132,
                'desc'       => esc_html__( 'Removed a relationship from an ACF custom field', 'mainwp' ),
                'message'    => esc_html__( 'Removed relationships from the custom field %MetaKey% in the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Removed relationships', 'mainwp' ) => '%Relationships%',
                ),
                'object'     => 'custom-field',
                'event_type' => 'modified',
            ),
            12054 => array(
                'type_id'    => 12054,
                'desc'       => esc_html__( 'Changed the value of a custom field', 'mainwp' ),
                'message'    => esc_html__( 'Modified the value of the custom field %MetaKey% in the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Previous custom field value', 'mainwp' ) => '%MetaValueOld%',
                    esc_html__( 'New custom field value', 'mainwp' ) => '%MetaValueNew%',
                ),
                'object'     => 'custom-field',
                'event_type' => 'modified',
            ),
            12055 => array(
                'type_id'    => 12055,
                'desc'       => esc_html__( 'Deleted a custom field', 'mainwp' ),
                'message'    => esc_html__( 'Deleted the custom field %MetaKey% from the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'custom-field',
                'event_type' => 'deleted',
            ),
            12062 => array(
                'type_id'    => 12062,
                'desc'       => esc_html__( 'Renamed a custom field', 'mainwp' ),
                'message'    => esc_html__( 'Renamed the custom field %MetaKeyOld% on post %PostTitle% to %MetaKeyNew%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post', 'mainwp' )        => '%PostTitle%',
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                ),
                'object'     => 'custom-field',
                'event_type' => 'renamed',
            ),
            // Category.
            12023 => array(
                'type_id'    => 12023,
                'desc'       => esc_html__( 'Created a new category', 'mainwp' ),
                'message'    => esc_html__( 'Created the category %CategoryName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Slug', 'mainwp' ) => 'Slug',
                ),
                'object'     => 'category',
                'event_type' => 'created',
            ),
            12024 => array(
                'type_id'    => 12024,
                'desc'       => esc_html__( 'Deleted a category', 'mainwp' ),
                'message'    => esc_html__( 'Deleted the category %CategoryName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Slug', 'mainwp' ) => 'Slug',
                ),
                'object'     => 'category',
                'event_type' => 'deleted',
            ),
            12052 => array(
                'type_id'    => 12052,
                'desc'       => esc_html__( 'Changed the parent of a category', 'mainwp' ),
                'message'    => esc_html__( 'Changed the parent of the category %CategoryName% to %NewParent%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Slug', 'mainwp' ) => '%Slug%',
                    esc_html__( 'Previous parent', 'mainwp' ) => '%OldParent%',
                ),
                'object'     => 'category',
                'event_type' => 'modified',
            ),
            12127 => array(
                'type_id'    => 12127,
                'desc'       => esc_html__( 'Renamed a category', 'mainwp' ),
                'message'    => esc_html__( 'Renamed the category %old_name% to %new_name%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Slug', 'mainwp' ) => '%slug%',
                ),
                'object'     => 'category',
                'event_type' => 'renamed',
            ),
            12128 => array(
                'type_id'    => 12128,
                'desc'       => esc_html__( 'Renamed a category', 'mainwp' ),
                'message'    => esc_html__( 'Changed the slug of the category %CategoryName% to %new_slug%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous slug', 'mainwp' ) => '%old_slug%',
                ),
                'object'     => 'category',
                'event_type' => 'modified',
            ),
            // Tag.
            12119 => array(
                'type_id'    => 12119,
                'desc'       => esc_html__( 'Added tag(s) to a post', 'mainwp' ),
                'message'    => esc_html__( 'Added tag(s) to the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'ID', 'mainwp' )           => '%PostID%',
                    esc_html__( 'Type', 'mainwp' )         => '%PostType%',
                    esc_html__( 'Status', 'mainwp' )       => '%PostStatus%',
                    esc_html__( 'Added tag(s)', 'mainwp' ) => '%tag%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12120 => array(
                'type_id'    => 12120,
                'desc'       => esc_html__( 'Removed tag(s) from a post', 'mainwp' ),
                'message'    => esc_html__( 'Removed tag(s) from the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Removed tag(s)', 'mainwp' ) => '%tag%',
                ),
                'object'     => 'post',
                'event_type' => 'modified',
            ),
            12123 => array(
                'type_id'    => 12123,
                'desc'       => esc_html__( 'Renamed tag', 'mainwp' ),
                'message'    => esc_html__( 'Renamed the tag %old_name% to %new_name%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Slug', 'mainwp' ) => '%Slug%',
                ),
                'object'     => 'tag',
                'event_type' => 'renamed',
            ),
            12124 => array(
                'type_id'    => 12124,
                'desc'       => esc_html__( 'Changed the slug of a tag', 'mainwp' ),
                'message'    => esc_html__( 'Changed the slug of the tag %tag% to %new_slug%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous slug', 'mainwp' ) => '%old_slug%',
                ),
                'object'     => 'tag',
                'event_type' => 'modified',
            ),
            12125 => array(
                'type_id'    => 12125,
                'desc'       => esc_html__( 'Changed the description of a tag', 'mainwp' ),
                'message'    => esc_html__( 'Changed the description of the tag %tag%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Slug', 'mainwp' ) => '%Slug%',
                    esc_html__( 'Previous description', 'mainwp' ) => '%old_desc%',
                    esc_html__( 'New description', 'mainwp' ) => '%new_desc%',
                ),
                'object'     => 'tag',
                'event_type' => 'modified',
            ),
            // File.
            12010 => array(
                'type_id'    => 12010,
                'desc'       => esc_html__( 'Uploaded a file', 'mainwp' ),
                'message'    => esc_html__( 'Uploaded a file called %FileName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Directory', 'mainwp' ) => '%FilePath%',
                ),
                'object'     => 'file',
                'event_type' => 'uploaded',
            ),
            12011 => array(
                'type_id'    => 12011,
                'desc'       => esc_html__( 'Deleted a file', 'mainwp' ),
                'message'    => esc_html__( 'Deleted the file %FileName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Directory', 'mainwp' ) => '%FilePath%',
                ),
                'object'     => 'file',
                'event_type' => 'deleted',
            ),
            // Widget.
            12042 => array(
                'type_id'    => 12042,
                'desc'       => esc_html__( 'Added a new widget', 'mainwp' ),
                'message'    => esc_html__( 'Added a new %WidgetName% widget in %Sidebar%.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'widget',
                'event_type' => 'added',
            ),
            12043 => array(
                'type_id'    => 12043,
                'desc'       => esc_html__( 'Modified a widget', 'mainwp' ),
                'message'    => esc_html__( 'Modified the %WidgetName% widget in %Sidebar%.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'widget',
                'event_type' => 'modified',
            ),
            12044 => array(
                'type_id'    => 12044,
                'desc'       => esc_html__( 'Deleted a widget', 'mainwp' ),
                'message'    => esc_html__( 'Deleted the %WidgetName% widget from %Sidebar%.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'widget',
                'event_type' => 'deleted',
            ),
            12045 => array(
                'type_id'    => 12045,
                'desc'       => esc_html__( 'Moved a widget in between sections', 'mainwp' ),
                'message'    => esc_html__( 'Moved the %WidgetName% widget.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'From', 'mainwp' ) => '%OldSidebar%',
                    esc_html__( 'To', 'mainwp' )   => '%NewSidebar%',
                ),
                'object'     => 'widget',
                'event_type' => 'modified',
            ),
            12071 => array(
                'type_id'    => 12071,
                'desc'       => esc_html__( 'Changed the position of a widget in a section', 'mainwp' ),
                'message'    => esc_html__( 'Changed the position of the %WidgetName% widget in %Sidebar%.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'widget',
                'event_type' => 'modified',
            ),
            // Plugin.
            12051 => array(
                'type_id'    => 12051,
                'desc'       => esc_html__( 'Modified a file with the plugin editor', 'mainwp' ),
                'message'    => esc_html__( 'Modified the file %File% with the plugin editor.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'file',
                'event_type' => 'modified',
            ),
            15028 => array(
                'type_id'    => 15028,
                'desc'       => esc_html__( 'The automatic updates setting for a plugin was changed.', 'mainwp' ),
                'message'    => esc_html__( 'Changed the Automatic updates setting for the plugin %name%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Install location', 'mainwp' )     => '%install_directory%',
                ),
                'object'     => 'plugin',
                'event_type' => 'enabled',
            ),
            // Theme.
            12046 => array(
                'type_id'    => 12046,
                'desc'       => esc_html__( 'Modified a file with the theme editor', 'mainwp' ),
                'message'    => esc_html__( 'Modified the file %Theme%/%File% with the theme editor.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'file',
                'event_type' => 'modified',
            ),
            15029 => array(
                'type_id'    => 15029,
                'desc'       => esc_html__( 'The automatic updates setting for a theme was changed.', 'mainwp' ),
                'message'    => esc_html__( 'Changed the Automatic updates setting for the theme %name%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Install location', 'mainwp' )     => '%install_directory%',
                ),
                'contex'     => 'theme',
                'event_type' => 'enabled',
            ),
            // Menu.
            12078 => array(
                'type_id'    => 12078,
                'desc'       => esc_html__( 'Created a menu', 'mainwp' ),
                'message'    => esc_html__( 'New menu called %MenuName%.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'menu',
                'event_type' => 'created',
            ),
            12079 => array(
                'type_id'    => 12079,
                'desc'       => esc_html__( 'Added item(s) to a menu', 'mainwp' ),
                'message'    => esc_html__( 'Added the item %ContentName% to the menu %MenuName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Item type', 'mainwp' ) => '%ContentType%',
                ),
                'object'     => 'menu',
                'event_type' => 'modified',
            ),
            12080 => array(
                'type_id'    => 12080,
                'desc'       => esc_html__( 'Removed item(s) from a menu', 'mainwp' ),
                'message'    => esc_html__( 'Removed the item %ContentName% from the menu %MenuName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Item type', 'mainwp' ) => '%ContentType%',
                ),
                'object'     => 'menu',
                'event_type' => 'modified',
            ),
            // Menu.
            12081 => array(
                'type_id'    => 12081,
                'desc'       => esc_html__( 'Deleted a menu', 'mainwp' ),
                'message'    => esc_html__( 'Deleted the menu %MenuName%.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'menu',
                'event_type' => 'deleted',
            ),
            12082 => array(
                'type_id'    => 12082,
                'desc'       => esc_html__( 'Changed the settings of a menu', 'mainwp' ),
                'message'    => esc_html__( 'The setting %MenuSetting% in the menu %MenuName%.', 'mainwp' ),
                'meta_log'   => array(),
                'contex'     => 'menu',
                'event_type' => 'enabled',
            ),
            12083 => array(
                'type_id'    => 12083,
                'desc'       => esc_html__( 'Modified the item(s) in a menu', 'mainwp' ),
                'message'    => esc_html__( 'Modified the item %ContentName% in the menu %MenuName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Item type', 'mainwp' ) => '%ContentType%',
                ),
                'object'     => 'menu',
                'event_type' => 'modified',
            ),
            12084 => array(
                'type_id'    => 12084,
                'desc'       => esc_html__( 'Renamed a menu', 'mainwp' ),
                'message'    => esc_html__( 'Renamed the menu %OldMenuName% to %MenuName%.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'menu',
                'event_type' => 'renamed',
            ),
            12085 => array(
                'type_id'    => 12085,
                'desc'       => esc_html__( 'Changed the order of the objects in a menu.', 'mainwp' ),
                'message'    => esc_html__( 'Changed the order of the items in the menu %MenuName%.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'menu',
                'event_type' => 'modified',
            ),
            12089 => array(
                'type_id'    => 12089,
                'desc'       => esc_html__( 'Moved an item as a sub-item in a menu', 'mainwp' ),
                'message'    => esc_html__( 'Moved items as sub-items in the menu %MenuName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Moved item', 'mainwp' ) => '%ItemName%',
                    esc_html__( 'as a sub-item of', 'mainwp' ) => '%ParentName%',
                ),
                'object'     => 'menu',
                'event_type' => 'modified',
            ),
            // Comment.
            12090 => array(
                'type_id'    => 12090,
                'desc'       => esc_html__( 'Approved a comment', 'mainwp' ),
                'message'    => esc_html__( 'Approved the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'approved',
            ),
            12091 => array(
                'type_id'    => 12091,
                'desc'       => esc_html__( 'Unapproved a comment', 'mainwp' ),
                'message'    => esc_html__( 'Unapproved the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'unapproved',
            ),
            12092 => array(
                'type_id'    => 12092,
                'desc'       => esc_html__( 'Replied to a comment', 'mainwp' ),
                'message'    => esc_html__( 'Replied to the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'created',
            ),
            12093 => array(
                'type_id'    => 12093,
                'desc'       => esc_html__( 'Edited a comment', 'mainwp' ),
                'message'    => esc_html__( 'Edited the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'modified',
            ),
            12094 => array(
                'type_id'    => 12094,
                'desc'       => esc_html__( 'Marked a comment as spam', 'mainwp' ),
                'message'    => esc_html__( 'Marked the comment posted by %Author% on the post %PostTitle% as spam.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'unapproved',
            ),
            12095 => array(
                'type_id'    => 12095,
                'desc'       => esc_html__( 'Marked a comment as not spam', 'mainwp' ),
                'message'    => esc_html__( 'Marked the comment posted by %Author% on the post %PostTitle% as not spam.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'approved',
            ),
            12096 => array(
                'type_id'    => 12096,
                'desc'       => esc_html__( 'Moved a comment to trash', 'mainwp' ),
                'message'    => esc_html__( 'Moved the comment posted by %Author% on the post %PostTitle% to trash.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'deleted',
            ),
            12097 => array(
                'type_id'    => 12097,
                'desc'       => esc_html__( 'Restored a comment from the trash', 'mainwp' ),
                'message'    => esc_html__( 'Restored the comment posted by %Author% on the post %PostTitle% from trash.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'restored',
            ),
            12098 => array(
                'type_id'    => 12098,
                'desc'       => esc_html__( 'Permanently deleted a comment', 'mainwp' ),
                'message'    => esc_html__( 'Permanently deleted the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'deleted',
            ),
            12099 => array(
                'type_id'    => 12099,
                'desc'       => esc_html__( 'Posted a comment', 'mainwp' ),
                'message'    => esc_html__( 'Posted a comment on the post %PostTitle%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                    esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                    esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                    esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                ),
                'object'     => 'comment',
                'event_type' => 'created',
            ),
            // User.
            11000 => array(
                'type_id'    => 11000,
                'desc'       => esc_html__( 'Successfully logged in', 'mainwp' ),
                'message'    => esc_html__( 'User logged in.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'user',
                'event_type' => 'login',
            ),
            11001 => array(
                'type_id'    => 11001,
                'desc'       => esc_html__( 'Successfully logged out', 'mainwp' ),
                'message'    => esc_html__( 'User logged out.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'user',
                'event_type' => 'logout',
            ),
            11005 => array(
                'type_id'    => 11005,
                'desc'       => esc_html__( 'Successful log in but other sessions exist for user', 'mainwp' ),
                'message'    => esc_html__( 'User logged in however there are other session(s) already for this user.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'IP address(es)', 'mainwp' ) => '%IPAddress%',
                ),
                'object'     => 'user',
                'event_type' => 'login',
            ),
            11006 => array(
                'type_id'    => 11006,
                'desc'       => esc_html__( 'Logged out all other sessions with same user', 'mainwp' ),
                'message'    => esc_html__( 'Logged out all other sessions with the same user.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'user',
                'event_type' => 'logout',
            ),
            11009 => array( // ??? 1007 - Terminated a user session.
                'type_id'    => 11009,
                'desc'       => esc_html__( 'Terminated a user session', 'mainwp' ),
                'message'    => esc_html__( 'The plugin terminated an idle session for the user %username%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%TargetUserRole%',
                    esc_html__( 'Session ID', 'mainwp' ) => '%SessionID%',
                ),
                'object'     => 'user',
                'event_type' => 'logout',
            ),
            11008 => array(
                'type_id'    => 11008,
                'desc'       => esc_html__( 'Switched to another user', 'mainwp' ),
                'message'    => esc_html__( 'Switched the session to being logged in as %TargetUserName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' ) => '%TargetUserRole%',
                ),
                'object'     => 'user',
                'event_type' => 'login',
            ),
            11010 => array(
                'type_id'    => 11010,
                'desc'       => esc_html__( 'User requested a password reset', 'mainwp' ),
                'message'    => esc_html__( 'User requested a password reset. This does not mean that the password was changed.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'user',
                'event_type' => 'submitted',
            ),
            14000 => array(
                'type_id'    => 14000,
                'desc'       => esc_html__( 'A new user was created', 'mainwp' ),
                'message'    => __( 'A new user %NewUserData->Username% is created via registration.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'User', 'mainwp' )  => '%NewUserData->Username%',
                    esc_html__( 'Email', 'mainwp' ) => '%NewUserData->Email%',
                ),
                'object'     => 'user',
                'event_type' => 'created',
            ),
            14001 => array(
                'type_id'    => 14001,
                'desc'       => esc_html__( 'User created a new user', 'mainwp' ),
                'message'    => __( 'Created the new user: %NewUserData->Username%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%NewUserData->Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%NewUserData->FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%NewUserData->LastName%',
                    esc_html__( 'Email', 'mainwp' )      => '%NewUserData->Email%',
                ),
                'object'     => 'user',
                'event_type' => 'created',
            ),
            14002 => array(
                'type_id'    => 14002,
                'desc'       => esc_html__( 'Change the role of a user', 'mainwp' ),
                'message'    => esc_html__( 'Changed the role of user %TargetUsername% to %NewRole%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous role', 'mainwp' ) => '%OldRole%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14003 => array(
                'type_id'    => 14003,
                'desc'       => esc_html__( 'Changed the password', 'mainwp' ),
                'message'    => esc_html__( 'Changed the password.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%TargetUserData->Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%TargetUserData->FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%TargetUserData->LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14004 => array(
                'type_id'    => 14004,
                'desc'       => esc_html__( 'Changed the password of a user', 'mainwp' ),
                'message'    => __( 'Changed the password of the user %TargetUserData->Username%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%TargetUserData->Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%TargetUserData->FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%TargetUserData->LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14005 => array(
                'type_id'    => 14005,
                'desc'       => esc_html__( 'Changed the email address', 'mainwp' ),
                'message'    => esc_html__( 'Changed the email address to %NewEmail%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                    esc_html__( 'Previous email address', 'mainwp' ) => '%OldEmail%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14006 => array(
                'type_id'    => 14006,
                'desc'       => esc_html__( 'Changed the email address of a user', 'mainwp' ),
                'message'    => esc_html__( 'Changed the email address of the user %TargetUsername% to %NewEmail%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                    esc_html__( 'Previous email address', 'mainwp' ) => '%OldEmail%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14007 => array(
                'type_id'    => 14007,
                'desc'       => esc_html__( 'Deleted a user', 'mainwp' ),
                'message'    => __( 'Deleted the user %TargetUserData->Username%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%TargetUserData->Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%NewUserData->FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%NewUserData->LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'deleted',
            ),
            14008 => array(
                'type_id'    => 14008,
                'desc'       => esc_html__( 'Granted super admin privileges to a user', 'mainwp' ),
                'message'    => esc_html__( 'Granted Super Admin privileges to the user %TargetUsername%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14009 => array(
                'type_id'    => 14009,
                'desc'       => esc_html__( 'Revoked super admin privileges from a user', 'mainwp' ),
                'message'    => esc_html__( 'Revoked Super Admin privileges from %TargetUsername%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14012 => array(
                'type_id'    => 14012,
                'desc'       => esc_html__( 'Added a network user to a site', 'mainwp' ),
                'message'    => __( 'Created the new network user %NewUserData->Username%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'First name', 'mainwp' ) => '%NewUserData->FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%NewUserData->LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'created',
            ),
            14011 => array(
                'type_id'    => 14011,
                'desc'       => esc_html__( 'Removed a network user from a site', 'mainwp' ),
                'message'    => esc_html__( 'Removed user %TargetUsername% from the site %SiteName%', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Site role', 'mainwp' )  => '%TargetUserRole%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14010 => array(
                'type_id'    => 14010,
                'desc'       => esc_html__( 'Created a new network user', 'mainwp' ),
                'message'    => esc_html__( 'Added user %TargetUsername% to the site %SiteName%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%TargetUserRole%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14013 => array(
                'type_id'    => 14013,
                'desc'       => esc_html__( 'User has been activated on the network', 'mainwp' ),
                'message'    => __( 'User %NewUserData->Username% has been activated.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%NewUserData->Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%NewUserData->FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%NewUserData->LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'activated',
            ),
            14014 => array(
                'type_id'    => 14014,
                'desc'       => esc_html__( 'Opened the profile page of a user', 'mainwp' ),
                'message'    => esc_html__( 'Opened the profile page of user %TargetUsername%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'opened',
            ),
            14015 => array(
                'type_id'    => 14015,
                'desc'       => esc_html__( 'Changed a custom field value in user profile', 'mainwp' ),
                'message'    => esc_html__( 'Changed the value of the custom field %custom_field_name% in the user profile %TargetUsername%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                    esc_html__( 'Previous value', 'mainwp' ) => '%old_value%',
                    esc_html__( 'New value', 'mainwp' )  => '%new_value%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14016 => array(
                'type_id'    => 14016,
                'desc'       => esc_html__( 'Created a custom field in a user profile', 'mainwp' ),
                'message'    => esc_html__( 'Created the custom field %custom_field_name% in the user profile %TargetUsername%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                    esc_html__( 'Custom field value', 'mainwp' ) => '%new_value%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14017 => array(
                'type_id'    => 14017,
                'desc'       => esc_html__( 'Changed the first name (of a user)', 'mainwp' ),
                'message'    => esc_html__( 'Changed the first name of the user %TargetUsername% to %new_firstname%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )      => '%Roles%',
                    esc_html__( 'Previous name', 'mainwp' ) => '%old_firstname%',
                    esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14018 => array(
                'type_id'    => 14018,
                'desc'       => esc_html__( 'Changed the last name (of a user)', 'mainwp' ),
                'message'    => esc_html__( 'Changed the last name of the user %TargetUsername% to %new_lastname%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Previous last name', 'mainwp' ) => '%old_lastname%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14019 => array(
                'type_id'    => 14019,
                'desc'       => esc_html__( 'Changed the nickname (of a user)', 'mainwp' ),
                'message'    => esc_html__( 'Changed the nickname of the user %TargetUsername% to %new_nickname%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                    esc_html__( 'Previous nickname', 'mainwp' ) => '%old_nickname%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14020 => array(
                'type_id'    => 14020,
                'desc'       => esc_html__( 'Changed the display name (of a user)', 'mainwp' ),
                'message'    => esc_html__( 'Changed the display name of the user %TargetUsername% to %new_displayname%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                    esc_html__( 'Previous display name', 'mainwp' ) => '%old_displayname%',
                ),
                'object'     => 'user',
                'event_type' => 'modified',
            ),
            14021 => array(
                'type_id'    => 14021,
                'desc'       => esc_html__( 'Changed the website URL of the user', 'mainwp' ),
                'message'    => esc_html__( 'Changed the website URL of the user %TargetUsername% to %new_url%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%Roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                    esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                    esc_html__( 'Previous website URL', 'mainwp' ) => '%old_url%',

                ),
                'event_type' => 'user',
                'event_type' => 'modified',
            ),
            14025 => array(
                'type_id'    => 14025,
                'desc'       => esc_html__( 'User added / removed application password from own profile', 'mainwp' ),
                'message'    => esc_html__( 'The application password %friendly_name%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%firstname%',
                    esc_html__( 'Last name', 'mainwp' )  => '%lastname%',
                ),
                'object'     => 'user',
                'event_type' => 'added',
            ),
            14026 => array(
                'type_id'    => 14026,
                'desc'       => esc_html__( 'User added / removed application password from another user’s profile', 'mainwp' ),
                'message'    => esc_html__( 'The application password %friendly_name% for the user %login%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%firstname%',
                    esc_html__( 'Last name', 'mainwp' )  => '%lastname%',
                ),
                'object'     => 'user',
                'event_type' => 'added',
            ),
            14028 => array(
                'type_id'    => 14028,
                'desc'       => esc_html__( 'User revoked all application passwords from own profile', 'mainwp' ),
                'message'    => esc_html__( 'All application passwords from the user %login%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%firstname%',
                    esc_html__( 'Last name', 'mainwp' )  => '%lastname%',
                ),
                'object'     => 'user',
                'event_type' => 'revoked',
            ),
            14027 => array(
                'type_id'    => 14027,
                'desc'       => esc_html__( 'User revoked all application passwords from another user’s profile', 'mainwp' ),
                'message'    => esc_html__( 'All application passwords.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Role', 'mainwp' )       => '%roles%',
                    esc_html__( 'First name', 'mainwp' ) => '%firstname%',
                    esc_html__( 'Last name', 'mainwp' )  => '%lastname%',
                ),
                'object'     => 'user',
                'event_type' => 'revoked',
            ),
            // Database.
            15010 => array(
                'type_id'    => 15010,
                'desc'       => esc_html__( 'Plugin created database table(s)', 'mainwp' ),
                'message'    => __( 'The plugin %Plugin->Name% created this table in the database.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'created',
            ),
            15011 => array(
                'type_id'    => 15011,
                'desc'       => esc_html__( 'Plugin modified the structure of database table(s)', 'mainwp' ),
                'message'    => __( 'The plugin %Plugin->Name% modified the structure of a database table.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'modified',
            ),
            15012 => array(
                'type_id'    => 15012,
                'desc'       => esc_html__( 'Plugin deleted database table(s)', 'mainwp' ),
                'message'    => __( 'The plugin %Plugin->Name% deleted this table from the database.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'deleted',
            ),
            15013 => array(
                'type_id'    => 15013,
                'desc'       => esc_html__( 'Theme created database table(s)', 'mainwp' ),
                'message'    => __( 'The theme %Theme->Name% created this tables in the database.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'created',
            ),
            15014 => array(
                'type_id'    => 15014,
                'desc'       => esc_html__( 'Theme modified the structure of table(s) in the database', 'mainwp' ),
                'message'    => __( 'The theme %Theme->Name% modified the structure of this database table', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'modified',
            ),
            15015 => array(
                'type_id'    => 15015,
                'desc'       => esc_html__( 'Theme deleted database table(s)', 'mainwp' ),
                'message'    => __( 'The theme %Theme->Name% deleted this table from the database.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Tables', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'deleted',
            ),
            15016 => array(
                'type_id'    => 15016,
                'desc'       => esc_html__( 'Unknown component created database table(s)', 'mainwp' ),
                'message'    => esc_html__( 'An unknown component created these tables in the database.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Tables', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'created',
            ),
            15017 => array(
                'type_id'    => 15017,
                'desc'       => esc_html__( 'Unknown component modified the structure of table(s )in the database', 'mainwp' ),
                'message'    => esc_html__( 'An unknown component modified the structure of these database tables.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Tables', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'modified',
            ),
            15018 => array(
                'type_id'    => 15018,
                'desc'       => esc_html__( 'Unknown component deleted database table(s)', 'mainwp' ),
                'message'    => esc_html__( 'An unknown component deleted these tables from the database.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Tables', 'mainwp' ) => '%TableNames%',
                ),
                'object'     => 'database',
                'event_type' => 'deleted',
            ),
            // System setting.
            16001 => array(
                'type_id'    => 16001,
                'desc'       => esc_html__( 'Changed the option anyone can register', 'mainwp' ),
                'message'    => __( 'The <strong>Membership</strong> setting <strong>Anyone can register</strong>.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'enabled',
            ),
            16002 => array(
                'type_id'    => 16002,
                'desc'       => esc_html__( 'Changed the new user default role', 'mainwp' ),
                'message'    => __( 'Changed the <strong>New user default role</strong> WordPress setting.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous role', 'mainwp' ) => '%OldRole%',
                    esc_html__( 'New role', 'mainwp' ) => '%NewRole%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16003 => array(
                'type_id'    => 16003,
                'desc'       => esc_html__( 'Changed the WordPress administrator notification email address', 'mainwp' ),
                'message'    => __( 'Change the <strong>Administrator email address</strong> in the WordPress settings.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous address', 'mainwp' ) => '%OldEmail%',
                    esc_html__( 'New address', 'mainwp' ) => '%NewEmail%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16005 => array(
                'type_id'    => 16005,
                'desc'       => esc_html__( 'Changed the WordPress permalinks', 'mainwp' ),
                'message'    => __( 'Changed the <strong>WordPress permalinks</strong>.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous permalinks', 'mainwp' ) => '%OldPattern%',
                    esc_html__( 'New permalinks', 'mainwp' )      => '%NewPattern%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16008 => array(
                'type_id'    => 16008,
                'desc'       => esc_html__( 'Changed the setting: Discourage search engines from indexing this site', 'mainwp' ),
                'message'    => __( 'Changed the status of the WordPress setting <strong>Search engine visibility</strong> (Discourage search engines from indexing this site)', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'enabled',
            ),
            16009 => array(
                'type_id'    => 16009,
                'desc'       => esc_html__( 'Enabled / disabled comments on the website', 'mainwp' ),
                'message'    => __( 'Changed the status of the WordPress setting <strong>Allow people to submit comments on new posts</strong>.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'enabled',
            ),
            16010 => array(
                'type_id'    => 16010,
                'desc'       => esc_html__( 'Changed the setting: Comment author must fill out name and email', 'mainwp' ),
                'message'    => __( 'Changed the status of the WordPress setting <strong>.Comment author must fill out name and email</strong>.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'enabled',
            ),
            16011 => array(
                'type_id'    => 16011,
                'desc'       => esc_html__( 'Changed the setting: Users must be logged in and registered to comment', 'mainwp' ),
                'message'    => __( 'Changed the status of the WordPress setting <strong>Users must be registered and logged in to comment</strong>.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'enabled',
            ),
            16012 => array(
                'type_id'    => 16012,
                'desc'       => esc_html__( 'Changed the setting: Automatically close comments after a number of days', 'mainwp' ),
                'message'    => __( 'Changed the status of the WordPress setting <strong>Automatically close comments after %Value% days</strong>.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'enabled',
            ),
            16013 => array(
                'type_id'    => 16013,
                'desc'       => esc_html__( 'Changed the value of the setting: Automatically close comments after a number of days.', 'mainwp' ),
                'message'    => __( 'Changed the value of the WordPress setting <strong>Automatically close comments after a number of days</strong> to %NewValue%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous value', 'mainwp' ) => '%OldValue%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16014 => array(
                'type_id'    => 16014,
                'desc'       => esc_html__( 'Changed the setting: Comments must be manually approved', 'mainwp' ),
                'message'    => __( 'Changed the value of the WordPress setting <strong>Comments must be manualy approved</strong>.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'enabled',
            ),
            16015 => array(
                'type_id'    => 16015,
                'desc'       => esc_html__( 'Changed the setting: Author must have previously approved comments for the comments to appear', 'mainwp' ),
                'message'    => __( 'Changed the value of the WordPress setting <strong>Comment author must have a previously approved comment</strong>.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'enabled',
            ),
            16016 => array(
                'type_id'    => 16016,
                'desc'       => esc_html__( 'Changed the minimum number of links that a comment must have to be held in the queue', 'mainwp' ),
                'message'    => __( 'Changed the value of the WordPress setting <strong>Hold a comment in the queue if it contains links</strong> to %NewValue% links.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous value', 'mainwp' ) => '%OldValue%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16017 => array(
                'type_id'    => 16017,
                'desc'       => esc_html__( 'Modified the list of keywords for comments moderation', 'mainwp' ),
                'message'    => esc_html__( 'Modified the list of keywords for comments moderation in WordPress.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16018 => array(
                'type_id'    => 16018,
                'desc'       => esc_html__( 'Modified the list of keywords for comments blacklisting', 'mainwp' ),
                'message'    => __( 'Modified the list of <strong>Disallowed comment keys</strong> (keywords) for comments blacklisting in WordPress.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16024 => array(
                'type_id'    => 16024,
                'desc'       => esc_html__( 'Changed the WordPress address (URL)', 'mainwp' ),
                'message'    => __( 'Changed the <strong>WordPress address (URL)</strong> to %new_url%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous URL', 'mainwp' ) => '%old_url%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16025 => array(
                'type_id'    => 16025,
                'desc'       => esc_html__( 'Changed the site address (URL)', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Site address (URL)</strong> to %new_url%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous URL', 'mainwp' ) => '%old_url%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16035 => array(
                'type_id'    => 16035,
                'desc'       => esc_html__( 'Changed the “Your homepage displays” WordPress setting', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Your homepage displays</strong> WordPress setting to %new_homepage%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous setting', 'mainwp' ) => '%old_homepage%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16036 => array(
                'type_id'    => 16036,
                'desc'       => esc_html__( 'Changed the homepage in the WordPress setting', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Homepage</strong> in the WordPress settings to %new_page%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous page', 'mainwp' ) => '%old_page%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16037 => array(
                'type_id'    => 16037,
                'desc'       => esc_html__( 'Changed the posts page in the WordPress settings', 'mainwp' ),
                'message'    => __( 'Changed the <strong> Posts</strong>  page in the WordPress settings to %new_page%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous page', 'mainwp' ) => '%old_page%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16040 => array(
                'type_id'    => 16040,
                'desc'       => esc_html__( 'Changed the Timezone in the WordPress settings', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Timezone</strong> in the WordPress settings to %new_timezone%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous timezone', 'mainwp' ) => '%old_timezone%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16041 => array(
                'type_id'    => 16041,
                'desc'       => esc_html__( 'Changed the Date format in the WordPress settings', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Date format</strong> in the WordPress settings to %new_date_format%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous format', 'mainwp' ) => '%old_date_format%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16042 => array(
                'type_id'    => 16042,
                'desc'       => esc_html__( 'Changed the Time format in the WordPress settings', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Time format</strong> in the WordPress settings to %new_time_format%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous format', 'mainwp' ) => '%old_time_format%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16044 => array(
                'type_id'    => 16044,
                'desc'       => esc_html__( 'User changed the WordPress automatic update settings', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Automatic updates</strong> setting.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'New setting status', 'mainwp' ) => '%updates_status%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16045 => array(
                'type_id'    => 16045,
                'desc'       => esc_html__( 'Changed the site language', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Site Language</strong> to %new_value%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous setting', 'mainwp' ) => '%previous_value%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16059 => array(
                'type_id'    => 16059,
                'desc'       => esc_html__( 'Changed the site title', 'mainwp' ),
                'message'    => __( 'Changed the <strong>Site Title</strong> to %new_value%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Previous setting', 'mainwp' ) => '%previous_value%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            16063 => array(
                'type_id'    => 16063,
                'desc'       => esc_html__( 'Added site icon', 'mainwp' ),
                'message'    => __( 'Added a new website Site Icon %filename%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'New directory', 'mainwp' ) => '%new_path%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'added',
            ),
            16064 => array(
                'type_id'    => 16064,
                'desc'       => esc_html__( 'Changed site icon', 'mainwp' ),
                'message'    => __( 'Changed the Site Icon from %old_filename% to %filename%.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Old directory', 'mainwp' ) => '%old_path%',
                    esc_html__( 'New directory', 'mainwp' ) => '%new_path%',
                ),
                'object'     => 'system-setting',
                'event_type' => 'modified',
            ),
            // WordPress Cron.
            16066 => array(
                'type_id'    => 16066,
                'desc'       => esc_html__( 'New one time task (cron job) created', 'mainwp' ),
                'message'    => __( 'A new one-time task called %task_name% has been scheduled.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'The task is scheduled to run on', 'mainwp' ) => '%timestamp%',
                ),
                'object'     => 'cron-job',
                'event_type' => 'created',
            ),
            16067 => array(
                'type_id'    => 16067,
                'desc'       => esc_html__( 'New recurring task (cron job) created', 'mainwp' ),
                'message'    => __( 'A new recurring task (cron job) called %task_name% has been created.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Task\'s first run: ', 'mainwp' ) => '%timestamp%',
                    esc_html__( 'Task\'s interval: ', 'mainwp' ) => '%display_name%',
                ),
                'object'     => 'cron-job',
                'event_type' => 'created',
            ),
            16068 => array(
                'type_id'    => 16067,
                'desc'       => esc_html__( 'New recurring task (cron job) created', 'mainwp' ),
                'message'    => __( 'A new recurring task (cron job) called %task_name% has been created.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Task\'s first run: ', 'mainwp' ) => '%timestamp%',
                    esc_html__( 'Task\'s interval: ', 'mainwp' ) => '%display_name%',
                ),
                'object'     => 'cron-job',
                'event_type' => 'created',
            ),
            16069 => array(
                'type_id'    => 16069,
                'desc'       => esc_html__( 'One time task (cron job) executed', 'mainwp' ),
                'message'    => __( 'The one-time task called %task_name% has been executed.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Task\'s schedule was: ', 'mainwp' ) => '%timestamp%',
                ),
                'object'     => 'cron-job',
                'event_type' => 'executed',
            ),
            16070 => array(
                'type_id'    => 16070,
                'desc'       => esc_html__( 'Recurring task (cron job) executed', 'mainwp' ),
                'message'    => __( ' The recurring task (cron job) called %task_name% has been executed.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Task\'s schedule was: ', 'mainwp' ) => '%display_name%',
                ),
                'object'     => 'cron-job',
                'event_type' => 'executed',
            ),
            16071 => array(
                'type_id'    => 16071,
                'desc'       => esc_html__( 'Deleted one-time task (cron job)', 'mainwp' ),
                'message'    => __( 'The one-time task  (cron job) called %task_name% has been deleted.', 'mainwp' ),
                'meta_log'   => array(),
                'object'     => 'cron-job',
                'event_type' => 'deleted',
            ),
            16072 => array(
                'type_id'    => 16072,
                'desc'       => esc_html__( 'Deleted recurring task (cron job)', 'mainwp' ),
                'message'    => __( 'The recurring task (cron job) called %task_name% has been deleted.', 'mainwp' ),
                'meta_log'   => array(
                    esc_html__( 'Task\'s schedule was: ', 'mainwp' ) => '%display_name%',
                ),
                'object'     => 'cron-job',
                'event_type' => 'deleted',
            ),
        );
    }
}
