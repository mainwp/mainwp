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
 * Class Log_Changes_Logs_Helper
 *
 * @package MainWP\Dashboard
 */
class Log_Changes_Logs_Helper {


    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

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
        $created_success   = false;

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

            $action = isset( $data['action_name'] ) ? $this->map_changes_action( $data['action_name'] ) : '';

            $context = '';

            if ( isset( $data['context'] ) ) {
                $context = $this->map_change_logs_context( $data['context'] );
            } elseif ( isset( $data['log_type_id'] ) ) {
                $context = $this->map_change_logs_context( '', $data['log_type_id'] );
            }

            $duration = isset( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : 0; // sanitize_text_field for seconds.
            $created  = isset( $data['created_on'] ) ? (float) ( $data['created_on'] ) : microtime( true );

            $record_mapping = array(
                'site_id'     => $site_id,
                'user_id'     => $user_id,
                'user_login'  => $user_login,
                'created'     => $created,
                'context'     => $context,
                'action'      => $action,
                'state'       => 1,
                'duration'    => $duration,
                'meta'        => $meta,
                'log_type_id' => $type_id,
            );

            $sum = 'wordpress' !== $record_mapping['context'] ? esc_html( ucfirst( rtrim( $record_mapping['context'], 's' ) ) ) : 'WordPress'; //phpcs:ignore -- wordpress text.
            $record_mapping['item'] = $sum;
            $inserted_id            = apply_filters( 'mainwp_sync_site_log_changes_logs', false, $website, $record_mapping );
            if ( $inserted_id ) {
                $created_success = true;
            }
        }

        if ( $new_last_created && $created_success ) {
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
     * @param string $context Log context|object.
     * @param int    $type_id Logs type code.
     *
     * @return string Dashboard logs context to store in db.
     */
    public function map_change_logs_context( $context, $type_id = 0 ) {
        if ( empty( $context ) && ! empty( $type_id ) ) {
            $context = isset( $this->get_changes_logs_types( $type_id )['context'] ) ? $this->get_changes_logs_types( $type_id )['context'] : '';
        }
        $context = 'cron-job' === $context ? 'cron' : $context;
        return \apply_filters( 'mainwp_module_log_changes_logs_mapping_contexts', $context, $type_id );
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
        } elseif ( in_array( $log_type_id, array( 1596 ) ) ) {
            if ( isset( static::get_changes_logs_types( $log_type_id )['desc'] ) ) {
                return static::get_changes_logs_types( $log_type_id )['desc'];
            }
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
     * @param int $type_id Logs type code.
     *
     * @return array data.
     */
    public static function get_changes_logs_types( $type_id = null ) { //phpcs:ignore -- NOSONAR - long function.
        $defaults = array(
            // Post.
            12000 => array(
                'type_id'     => 12000,
                'desc'        => esc_html__( 'Created a new post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'created',
            ),
            12001 => array(
                'type_id'     => 12001,
                'desc'        => esc_html__( 'Published a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'published',
            ),
            12002 => array(
                'type_id'     => 12002,
                'desc'        => esc_html__( 'Modified a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12008 => array(
                'type_id'     => 12008,
                'desc'        => esc_html__( 'Permanently deleted a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'deleted',
            ),
            12012 => array(
                'type_id'     => 12012,
                'desc'        => esc_html__( 'Moved a post to trash', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'deleted',
            ),
            12014 => array(
                'type_id'     => 12014,
                'desc'        => esc_html__( 'Restored a post from trash', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'restored',
            ),
            12016 => array(
                'type_id'     => 12016,
                'desc'        => esc_html__( 'Changed the category of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12017 => array(
                'type_id'     => 12017,
                'desc'        => esc_html__( 'Changed the URL of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12019 => array(
                'type_id'     => 12019,
                'desc'        => esc_html__( 'Changed the author of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12021 => array(
                'type_id'     => 12021,
                'desc'        => esc_html__( 'Changed the status of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12047 => array(
                'type_id'     => 12047,
                'desc'        => esc_html__( 'Changed the parent of a post', 'mainwp' ),
                'tite'        => esc_html__( 'Changed the parent of the post %PostTitle% to %NewParentName%.', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12048 => array(
                'type_id'     => 12048,
                'desc'        => esc_html__( 'Changed the template of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12049 => array(
                'type_id'     => 12049,
                'desc'        => esc_html__( 'Set a post as Sticky', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12050 => array(
                'type_id'     => 12050,
                'desc'        => esc_html__( 'Removed post from Sticky', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12053 => array(
                'type_id'     => 12053,
                'desc'        => esc_html__( 'Created a custom field in a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12073 => array(
                'type_id'     => 12073,
                'desc'        => esc_html__( 'Submitted post for review', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12074 => array(
                'type_id'     => 12074,
                'desc'        => esc_html__( 'Scheduled a post for publishing', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12025 => array(
                'type_id'     => 12025,
                'desc'        => esc_html__( 'User changed the visibility of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12027 => array(
                'type_id'     => 12027,
                'disc'        => esc_html__( 'Changed the date of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12065 => array(
                'type_id'     => 12065,
                'desc'        => esc_html__( 'Modified the content of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12086 => array(
                'type_id'     => 12086,
                'desc'        => esc_html__( 'Changed title of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12100 => array(
                'type_id'     => 12100,
                'desc'        => esc_html__( 'Opened a post in editor', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'opened',
            ),
            12101 => array(
                'type_id'     => 12101,
                'desc'        => esc_html__( 'Viewed a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'viewed',
            ),
            12111 => array(
                'type_id'     => 12111,
                'desc'        => esc_html__( 'Enabled / disabled comments in a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'enabled',
            ),
            12112 => array(
                'type_id'     => 12112,
                'desc'        => esc_html__( 'Enabled / disabled trackbacks in a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'enabled',
            ),
            12129 => array(
                'type_id'     => 12129,
                'desc'        => esc_html__( 'Updated the excerpt of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            // xxxx, // Added / changed / removed a post’s excerpt ???.
            12130 => array(
                'type_id'     => 12130,
                'desc'        => esc_html__( 'Updated the feature image of a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12133 => array(
                'type_id'     => 12133,
                'desc'        => esc_html__( 'Taken over a post from another user', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            // Custom field.
            12131 => array(
                'type_id'     => 12131,
                'desc'        => esc_html__( 'Added a relationship in an ACF custom field', 'mainwp' ),
                'context'     => 'custom-field',
                'action_name' => 'modified',
            ),
            12132 => array(
                'type_id'     => 12132,
                'desc'        => esc_html__( 'Removed a relationship from an ACF custom field', 'mainwp' ),
                'context'     => 'custom-field',
                'action_name' => 'modified',
            ),
            12054 => array(
                'type_id'     => 12054,
                'desc'        => esc_html__( 'Changed the value of a custom field', 'mainwp' ),
                'context'     => 'custom-field',
                'action_name' => 'modified',
            ),
            12055 => array(
                'type_id'     => 12055,
                'desc'        => esc_html__( 'Deleted a custom field', 'mainwp' ),
                'context'     => 'custom-field',
                'action_name' => 'deleted',
            ),
            12062 => array(
                'type_id'     => 12062,
                'desc'        => esc_html__( 'Renamed a custom field', 'mainwp' ),
                'context'     => 'custom-field',
                'action_name' => 'renamed',
            ),
            // Category.
            12023 => array(
                'type_id'     => 12023,
                'desc'        => esc_html__( 'Created a new category', 'mainwp' ),
                'context'     => 'category',
                'action_name' => 'created',
            ),
            12024 => array(
                'type_id'     => 12024,
                'desc'        => esc_html__( 'Deleted a category', 'mainwp' ),
                'context'     => 'category',
                'action_name' => 'deleted',
            ),
            12052 => array(
                'type_id'     => 12052,
                'desc'        => esc_html__( 'Changed the parent of a category', 'mainwp' ),
                'context'     => 'category',
                'action_name' => 'modified',
            ),
            12127 => array(
                'type_id'     => 12127,
                'desc'        => esc_html__( 'Renamed a category', 'mainwp' ),
                'context'     => 'category',
                'action_name' => 'renamed',
            ),
            12128 => array(
                'type_id'     => 12128,
                'desc'        => esc_html__( 'Renamed a category', 'mainwp' ),
                'context'     => 'category',
                'action_name' => 'modified',
            ),
            // Tag.
            12119 => array(
                'type_id'     => 12119,
                'desc'        => esc_html__( 'Added tag(s) to a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12120 => array(
                'type_id'     => 12120,
                'desc'        => esc_html__( 'Removed tag(s) from a post', 'mainwp' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            12123 => array(
                'type_id'     => 12123,
                'desc'        => esc_html__( 'Renamed tag', 'mainwp' ),
                'context'     => 'tag',
                'action_name' => 'renamed',
            ),
            12124 => array(
                'type_id'     => 12124,
                'desc'        => esc_html__( 'Changed the slug of a tag', 'mainwp' ),
                'context'     => 'tag',
                'action_name' => 'modified',
            ),
            12125 => array(
                'type_id'     => 12125,
                'desc'        => esc_html__( 'Changed the description of a tag', 'mainwp' ),
                'context'     => 'tag',
                'action_name' => 'modified',
            ),
            // File.
            12010 => array(
                'type_id'     => 12010,
                'desc'        => esc_html__( 'Uploaded a file', 'mainwp' ),
                'context'     => 'file',
                'action_name' => 'uploaded',
            ),
            12011 => array(
                'type_id'     => 12011,
                'desc'        => esc_html__( 'Deleted a file', 'mainwp' ),
                'context'     => 'file',
                'action_name' => 'deleted',
            ),
            // Widget.
            12042 => array(
                'type_id'     => 12042,
                'desc'        => esc_html__( 'Added a new widget', 'mainwp' ),
                'context'     => 'widget',
                'action_name' => 'added',
            ),
            12043 => array(
                'type_id'     => 12043,
                'desc'        => esc_html__( 'Modified a widget', 'mainwp' ),
                'context'     => 'widget',
                'action_name' => 'modified',
            ),
            12044 => array(
                'type_id'     => 12044,
                'desc'        => esc_html__( 'Deleted a widget', 'mainwp' ),
                'context'     => 'widget',
                'action_name' => 'deleted',
            ),
            12045 => array(
                'type_id'     => 12045,
                'desc'        => esc_html__( 'Moved a widget in between sections', 'mainwp' ),
                'context'     => 'widget',
                'action_name' => 'modified',
            ),
            12071 => array(
                'type_id'     => 12071,
                'desc'        => esc_html__( 'Changed the position of a widget in a section', 'mainwp' ),
                'context'     => 'widget',
                'action_name' => 'modified',
            ),
            // Plugin.
            12051 => array(
                'type_id'     => 12051,
                'desc'        => esc_html__( 'Modified a file with the plugin editor', 'mainwp' ),
                'context'     => 'file',
                'action_name' => 'modified',
            ),
            15028 => array(
                'type_id'     => 15028,
                'desc'        => esc_html__( 'The automatic updates setting for a plugin was changed.', 'mainwp' ),
                'context'     => 'plugin',
                'action_name' => 'enabled',
            ),
            // Theme.
            12046 => array(
                'type_id'     => 12046,
                'desc'        => esc_html__( 'Modified a file with the theme editor', 'mainwp' ),
                'context'     => 'file',
                'action_name' => 'modified',
            ),
            15029 => array(
                'type_id'     => 15029,
                'desc'        => esc_html__( 'The automatic updates setting for a theme was changed.', 'mainwp' ),
                'contex'      => 'theme',
                'action_name' => 'enabled',
            ),
            // Menu.
            12078 => array(
                'type_id'     => 12078,
                'desc'        => esc_html__( 'Created a menu', 'mainwp' ),
                'context'     => 'menu',
                'action_name' => 'created',
            ),
            12079 => array(
                'type_id'     => 12079,
                'desc'        => esc_html__( 'Added item(s) to a menu', 'mainwp' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            12080 => array(
                'type_id'     => 12080,
                'desc'        => esc_html__( 'Removed item(s) from a menu', 'mainwp' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            // Menu.
            12081 => array(
                'type_id'     => 12081,
                'desc'        => esc_html__( 'Deleted a menu', 'mainwp' ),
                'context'     => 'menu',
                'action_name' => 'deleted',
            ),
            12082 => array(
                'type_id'     => 12082,
                'desc'        => esc_html__( 'Changed the settings of a menu', 'mainwp' ),
                'contex'      => 'menu',
                'action_name' => 'enabled',
            ),
            12083 => array(
                'type_id'     => 12083,
                'desc'        => esc_html__( 'Modified the item(s) in a menu', 'mainwp' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            12084 => array(
                'type_id'     => 12084,
                'desc'        => esc_html__( 'Renamed a menu', 'mainwp' ),
                'context'     => 'menu',
                'action_name' => 'renamed',
            ),
            12085 => array(
                'type_id'     => 12085,
                'desc'        => esc_html__( 'Changed the order of the objects in a menu.', 'mainwp' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            12089 => array(
                'type_id'     => 12089,
                'desc'        => esc_html__( 'Moved an item as a sub-item in a menu', 'mainwp' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            // Comment.
            12090 => array(
                'type_id'     => 12090,
                'desc'        => esc_html__( 'Approved a comment', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'approved',
            ),
            12091 => array(
                'type_id'     => 12091,
                'desc'        => esc_html__( 'Unapproved a comment', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'unapproved',
            ),
            12092 => array(
                'type_id'     => 12092,
                'desc'        => esc_html__( 'Replied to a comment', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'created',
            ),
            12093 => array(
                'type_id'     => 12093,
                'desc'        => esc_html__( 'Edited a comment', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'modified',
            ),
            12094 => array(
                'type_id'     => 12094,
                'desc'        => esc_html__( 'Marked a comment as spam', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'unapproved',
            ),
            12095 => array(
                'type_id'     => 12095,
                'desc'        => esc_html__( 'Marked a comment as not spam', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'approved',
            ),
            12096 => array(
                'type_id'     => 12096,
                'desc'        => esc_html__( 'Moved a comment to trash', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'deleted',
            ),
            12097 => array(
                'type_id'     => 12097,
                'desc'        => esc_html__( 'Restored a comment from the trash', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'restored',
            ),
            12098 => array(
                'type_id'     => 12098,
                'desc'        => esc_html__( 'Permanently deleted a comment', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'deleted',
            ),
            12099 => array(
                'type_id'     => 12099,
                'desc'        => esc_html__( 'Posted a comment', 'mainwp' ),
                'context'     => 'comment',
                'action_name' => 'created',
            ),
            // User.
            11000 => array(
                'type_id'     => 11000,
                'desc'        => esc_html__( 'Successfully logged in', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'login',
            ),
            11001 => array(
                'type_id'     => 11001,
                'desc'        => esc_html__( 'Successfully logged out', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'logout',
            ),
            11005 => array(
                'type_id'     => 11005,
                'desc'        => esc_html__( 'Successful log in but other sessions exist for user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'login',
            ),
            11006 => array(
                'type_id'     => 11006,
                'desc'        => esc_html__( 'Logged out all other sessions with same user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'logout',
            ),
            11009 => array( // ??? 1007 - Terminated a user session.
                'type_id'     => 11009,
                'desc'        => esc_html__( 'Terminated a user session', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'logout',
            ),
            11008 => array(
                'type_id'     => 11008,
                'desc'        => esc_html__( 'Switched to another user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'login',
            ),
            1596 => array(
                'type_id'     => 1596,
                'desc'        => esc_html__( 'User login from dashboard', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'login',
            ),
            1600 => array(
                'type_id'     => 1600,
                'desc'        => esc_html__( 'User requested a password reset', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'submitted',
            ),
            14000 => array(
                'type_id'     => 14000,
                'desc'        => esc_html__( 'A new user was created', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'created',
            ),
            14001 => array(
                'type_id'     => 14001,
                'desc'        => esc_html__( 'User created a new user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'created',
            ),
            14002 => array(
                'type_id'     => 14002,
                'desc'        => esc_html__( 'Change the role of a user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14003 => array(
                'type_id'     => 14003,
                'desc'        => esc_html__( 'Changed the password', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14004 => array(
                'type_id'     => 14004,
                'desc'        => esc_html__( 'Changed the password of a user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14005 => array(
                'type_id'     => 14005,
                'desc'        => esc_html__( 'Changed the email address', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14006 => array(
                'type_id'     => 14006,
                'desc'        => esc_html__( 'Changed the email address of a user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14007 => array(
                'type_id'     => 14007,
                'desc'        => esc_html__( 'Deleted a user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'deleted',
            ),
            14008 => array(
                'type_id'     => 14008,
                'desc'        => esc_html__( 'Granted super admin privileges to a user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14009 => array(
                'type_id'     => 14009,
                'desc'        => esc_html__( 'Revoked super admin privileges from a user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14012 => array(
                'type_id'     => 14012,
                'desc'        => esc_html__( 'Added a network user to a site', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'created',
            ),
            14011 => array(
                'type_id'     => 14011,
                'desc'        => esc_html__( 'Removed a network user from a site', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14010 => array(
                'type_id'     => 14010,
                'desc'        => esc_html__( 'Created a new network user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14013 => array(
                'type_id'     => 14013,
                'desc'        => esc_html__( 'User has been activated on the network', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'activated',
            ),
            14014 => array(
                'type_id'     => 14014,
                'desc'        => esc_html__( 'Opened the profile page of a user', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'opened',
            ),
            14015 => array(
                'type_id'     => 14015,
                'desc'        => esc_html__( 'Changed a custom field value in user profile', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14016 => array(
                'type_id'     => 14016,
                'desc'        => esc_html__( 'Created a custom field in a user profile', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14017 => array(
                'type_id'     => 14017,
                'desc'        => esc_html__( 'Changed the first name (of a user)', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14018 => array(
                'type_id'     => 14018,
                'desc'        => esc_html__( 'Changed the last name (of a user)', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14019 => array(
                'type_id'     => 14019,
                'desc'        => esc_html__( 'Changed the nickname (of a user)', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14020 => array(
                'type_id'     => 14020,
                'desc'        => esc_html__( 'Changed the display name (of a user)', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            14021 => array(
                'type_id'     => 14021,
                'desc'        => esc_html__( 'Changed the website URL of the user', 'mainwp' ),
                'action_name' => 'user',
                'action_name' => 'modified',
            ),
            14025 => array(
                'type_id'     => 14025,
                'desc'        => esc_html__( 'User added / removed application password from own profile', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'added',
            ),
            14026 => array(
                'type_id'     => 14026,
                'desc'        => esc_html__( 'User added / removed application password from another user’s profile', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'added',
            ),
            14028 => array(
                'type_id'     => 14028,
                'desc'        => esc_html__( 'User revoked all application passwords from own profile', 'mainwp' ),
                'context'     => 'user',
                'action_name' => 'revoked',
            ),
            1585 => array(
                'type_id'     => 1735,
                'desc'        => esc_html__( 'User revoked all application passwords from another user’s profile', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'revoked',
            ),
            // Database.
            15010 => array(
                'type_id'     => 15010,
                'desc'        => esc_html__( 'Plugin created database table(s)', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'created',
            ),
            15011 => array(
                'type_id'     => 15011,
                'desc'        => esc_html__( 'Plugin modified the structure of database table(s)', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'modified',
            ),
            15012 => array(
                'type_id'     => 15012,
                'desc'        => esc_html__( 'Plugin deleted database table(s)', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'deleted',
            ),
            15013 => array(
                'type_id'     => 15013,
                'desc'        => esc_html__( 'Theme created database table(s)', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'created',
            ),
            15014 => array(
                'type_id'     => 15014,
                'desc'        => esc_html__( 'Theme modified the structure of table(s) in the database', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'modified',
            ),
            15015 => array(
                'type_id'     => 15015,
                'desc'        => esc_html__( 'Theme deleted database table(s)', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'deleted',
            ),
            15016 => array(
                'type_id'     => 15016,
                'desc'        => esc_html__( 'Unknown component created database table(s)', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'created',
            ),
            15017 => array(
                'type_id'     => 15017,
                'desc'        => esc_html__( 'Unknown component modified the structure of table(s )in the database', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'modified',
            ),
            15018 => array(
                'type_id'     => 15018,
                'desc'        => esc_html__( 'Unknown component deleted database table(s)', 'mainwp' ),
                'context'     => 'database',
                'action_name' => 'deleted',
            ),
            // System setting.
            16001 => array(
                'type_id'     => 16001,
                'desc'        => esc_html__( 'Changed the option anyone can register', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            16002 => array(
                'type_id'     => 16002,
                'desc'        => esc_html__( 'Changed the new user default role', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16003 => array(
                'type_id'     => 16003,
                'desc'        => esc_html__( 'Changed the WordPress administrator notification email address', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16005 => array(
                'type_id'     => 16005,
                'desc'        => esc_html__( 'Changed the WordPress permalinks', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16008 => array(
                'type_id'     => 16008,
                'desc'        => esc_html__( 'Changed the setting: Discourage search engines from indexing this site', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            16009 => array(
                'type_id'     => 16009,
                'desc'        => esc_html__( 'Enabled / disabled comments on the website', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            16010 => array(
                'type_id'     => 16010,
                'desc'        => esc_html__( 'Changed the setting: Comment author must fill out name and email', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            16011 => array(
                'type_id'     => 16011,
                'desc'        => esc_html__( 'Changed the setting: Users must be logged in and registered to comment', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            16012 => array(
                'type_id'     => 16012,
                'desc'        => esc_html__( 'Changed the setting: Automatically close comments after a number of days', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            16013 => array(
                'type_id'     => 16013,
                'desc'        => esc_html__( 'Changed the value of the setting: Automatically close comments after a number of days.', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16014 => array(
                'type_id'     => 16014,
                'desc'        => esc_html__( 'Changed the setting: Comments must be manually approved', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            16015 => array(
                'type_id'     => 16015,
                'desc'        => esc_html__( 'Changed the setting: Author must have previously approved comments for the comments to appear', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            16016 => array(
                'type_id'     => 16016,
                'desc'        => esc_html__( 'Changed the minimum number of links that a comment must have to be held in the queue', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16017 => array(
                'type_id'     => 16017,
                'desc'        => esc_html__( 'Modified the list of keywords for comments moderation', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16018 => array(
                'type_id'     => 16018,
                'desc'        => esc_html__( 'Modified the list of keywords for comments blacklisting', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16024 => array(
                'type_id'     => 16024,
                'desc'        => esc_html__( 'Changed the WordPress address (URL)', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16025 => array(
                'type_id'     => 16025,
                'desc'        => esc_html__( 'Changed the site address (URL)', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16035 => array(
                'type_id'     => 16035,
                'desc'        => esc_html__( 'Changed the “Your homepage displays” WordPress setting', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16036 => array(
                'type_id'     => 16036,
                'desc'        => esc_html__( 'Changed the homepage in the WordPress setting', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16037 => array(
                'type_id'     => 16037,
                'desc'        => esc_html__( 'Changed the posts page in the WordPress settings', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16040 => array(
                'type_id'     => 16040,
                'desc'        => esc_html__( 'Changed the Timezone in the WordPress settings', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16041 => array(
                'type_id'     => 16041,
                'desc'        => esc_html__( 'Changed the Date format in the WordPress settings', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16042 => array(
                'type_id'     => 16042,
                'desc'        => esc_html__( 'Changed the Time format in the WordPress settings', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16044 => array(
                'type_id'     => 16044,
                'desc'        => esc_html__( 'User changed the WordPress automatic update settings', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16045 => array(
                'type_id'     => 16045,
                'desc'        => esc_html__( 'Changed the site language', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16059 => array(
                'type_id'     => 16059,
                'desc'        => esc_html__( 'Changed the site title', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            16063 => array(
                'type_id'     => 16063,
                'desc'        => esc_html__( 'Added site icon', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'added',
            ),
            16064 => array(
                'type_id'     => 16064,
                'desc'        => esc_html__( 'Changed site icon', 'mainwp' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            // WordPress Cron.
            16066 => array(
                'type_id'     => 16066,
                'desc'        => esc_html__( 'New one time task (cron job) created', 'mainwp' ),
                'context'     => 'cron-job',
                'action_name' => 'created',
            ),
            16067 => array(
                'type_id'     => 16067,
                'desc'        => esc_html__( 'New recurring task (cron job) created', 'mainwp' ),
                'context'     => 'cron-job',
                'action_name' => 'created',
            ),
            16068 => array(
                'type_id'     => 16067,
                'desc'        => esc_html__( 'New recurring task (cron job) created', 'mainwp' ),
                'context'     => 'cron-job',
                'action_name' => 'created',
            ),
            16069 => array(
                'type_id'     => 16069,
                'desc'        => esc_html__( 'One time task (cron job) executed', 'mainwp' ),
                'context'     => 'cron-job',
                'action_name' => 'executed',
            ),
            16070 => array(
                'type_id'     => 16070,
                'desc'        => esc_html__( 'Recurring task (cron job) executed', 'mainwp' ),
                'context'     => 'cron-job',
                'action_name' => 'executed',
            ),
            16071 => array(
                'type_id'     => 16071,
                'desc'        => esc_html__( 'Deleted one-time task (cron job)', 'mainwp' ),
                'context'     => 'cron-job',
                'action_name' => 'deleted',
            ),
            16072 => array(
                'type_id'     => 16072,
                'desc'        => esc_html__( 'Deleted recurring task (cron job)', 'mainwp' ),
                'context'     => 'cron-job',
                'action_name' => 'deleted',
            ),
        );

        if ( null !== $type_id ) {
            return is_scalar( $type_id ) && isset( $defaults[ $type_id ] ) ? $defaults[ $type_id ] : array();
        }
        return $defaults;
    }
}
