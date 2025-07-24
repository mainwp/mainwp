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
                static::$last_log_created = time();
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

            $item_created = round( (float) $data['created_on'], 4 ); // to fix float comparing issue.

            if ( $item_created <= $sync_last_created ) {
                continue;
            }

            // to fix issue of last sync value.
            if ( $new_last_created < $item_created ) {
                $new_last_created = $item_created;
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
        if ( ! empty( $type_id ) ) {
            $context = isset( static::get_changes_logs_types( $type_id )['context'] ) ? static::get_changes_logs_types( $type_id )['context'] : '';
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
        } elseif ( isset( static::get_changes_logs_types( $log_type_id )['desc'] ) ) {
                return static::get_changes_logs_types( $log_type_id )['desc'];
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
            1460 => array(
                __( '%action% automatic update', 'mainwp' ),
            ),
        );
        return isset( $defaults[ $type_id ] ) ? $defaults[ $type_id ][0] : '';
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
            1200 => array(
                'type_id'     => 1200,
                'desc'        => esc_html__( 'Created a new post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'created',
            ),
            1205 => array(
                'type_id'     => 1205,
                'desc'        => esc_html__( 'Published a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'published',
            ),
            1210 => array(
                'type_id'     => 1210,
                'desc'        => esc_html__( 'Modified a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1215 => array(
                'type_id'     => 1215,
                'desc'        => esc_html__( 'Permanently deleted a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'deleted',
            ),
            1220 => array(
                'type_id'     => 1220,
                'desc'        => esc_html__( 'Permanently deleted a page', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'deleted',
            ),
            1225 => array(
                'type_id'     => 1225,
                'desc'        => esc_html__( 'Moved a post to trash', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'deleted',
            ),
            1230 => array(
                'type_id'     => 1230,
                'desc'        => esc_html__( 'Restored a post from trash', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'restored',
            ),
            1235 => array(
                'type_id'     => 1235,
                'desc'        => esc_html__( 'Changed the category of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1240 => array(
                'type_id'     => 1240,
                'desc'        => esc_html__( 'Changed the URL of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1245 => array(
                'type_id'     => 1245,
                'desc'        => esc_html__( 'Changed the author of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1250 => array(
                'type_id'     => 1250,
                'desc'        => esc_html__( 'Changed the status of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1255 => array(
                'type_id'     => 1255,
                'desc'        => esc_html__( 'Changed the parent of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1260 => array(
                'type_id'     => 1260,
                'desc'        => esc_html__( 'Changed the template of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1265 => array(
                'type_id'     => 1265,
                'desc'        => esc_html__( 'Set a post as Sticky', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1270 => array(
                'type_id'     => 1270,
                'desc'        => esc_html__( 'Removed post from Sticky', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1275 => array(
                'type_id'     => 1275,
                'desc'        => esc_html__( 'Created a custom field in a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1280 => array(
                'type_id'     => 1280,
                'desc'        => esc_html__( 'Submitted post for review', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1285 => array(
                'type_id'     => 1285,
                'desc'        => esc_html__( 'Scheduled a post for publishing', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1290 => array(
                'type_id'     => 1290,
                'desc'        => esc_html__( 'User changed the visibility of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1295 => array(
                'type_id'     => 1295,
                'desc'        => esc_html__( 'Changed the date of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1300 => array(
                'type_id'     => 1300,
                'desc'        => esc_html__( 'Modified the content of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1305 => array(
                'type_id'     => 1305,
                'desc'        => esc_html__( 'Changed title of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1310 => array(
                'type_id'     => 1310,
                'desc'        => esc_html__( 'Opened a post in editor', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'opened',
            ),
            1315 => array(
                'type_id'     => 1315,
                'desc'        => esc_html__( 'Viewed a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'viewed',
            ),
            1320 => array(
                'type_id'     => 1320,
                'desc'        => esc_html__( 'Enabled / disabled comments in a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'enabled',
            ),
            1325 => array(
                'type_id'     => 1325,
                'desc'        => esc_html__( 'Enabled / disabled trackbacks in a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'enabled',
            ),
            1330 => array(
                'type_id'     => 1330,
                'desc'        => esc_html__( 'Updated the excerpt of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            // xxxx, // Added / changed / removed a post’s excerpt ???.
            1335 => array(
                'type_id'     => 1335,
                'desc'        => esc_html__( 'Updated the feature image of a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1340 => array(
                'type_id'     => 1340,
                'desc'        => esc_html__( 'Taken over a post from another user', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            // Custom field.
            1345 => array(
                'type_id'     => 1345,
                'desc'        => esc_html__( 'Added a relationship in an ACF custom field', 'mainwp-child' ),
                'context'     => 'custom-field',
                'action_name' => 'modified',
            ),
            1350 => array(
                'type_id'     => 1350,
                'desc'        => esc_html__( 'Removed a relationship from an ACF custom field', 'mainwp-child' ),
                'context'     => 'custom-field',
                'action_name' => 'modified',
            ),
            1355 => array(
                'type_id'     => 1355,
                'desc'        => esc_html__( 'Changed the value of a custom field', 'mainwp-child' ),
                'context'     => 'custom-field',
                'action_name' => 'modified',
            ),
            1360 => array(
                'type_id'     => 1360,
                'desc'        => esc_html__( 'Deleted a custom field', 'mainwp-child' ),
                'context'     => 'custom-field',
                'action_name' => 'deleted',
            ),
            1365 => array(
                'type_id'     => 1365,
                'desc'        => esc_html__( 'Renamed a custom field', 'mainwp-child' ),
                'context'     => 'custom-field',
                'action_name' => 'renamed',
            ),
            // Category.
            1370 => array(
                'type_id'     => 1370,
                'desc'        => esc_html__( 'Created a new category', 'mainwp-child' ),
                'context'     => 'category',
                'action_name' => 'created',
            ),
            1375 => array(
                'type_id'     => 1375,
                'desc'        => esc_html__( 'Deleted a category', 'mainwp-child' ),
                'context'     => 'category',
                'action_name' => 'deleted',
            ),
            1380 => array(
                'type_id'     => 1380,
                'desc'        => esc_html__( 'Changed the parent of a category', 'mainwp-child' ),
                'context'     => 'category',
                'action_name' => 'modified',
            ),
            1385 => array(
                'type_id'     => 1385,
                'desc'        => esc_html__( 'Renamed a category', 'mainwp-child' ),
                'context'     => 'category',
                'action_name' => 'renamed',
            ),
            1390 => array(
                'type_id'     => 1390,
                'desc'        => esc_html__( 'Changed slug of a category', 'mainwp-child' ),
                'context'     => 'category',
                'action_name' => 'modified',
            ),
            // Tag.
            1395 => array(
                'type_id'     => 1395,
                'desc'        => esc_html__( 'Added tag(s) to a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1400 => array(
                'type_id'     => 1400,
                'desc'        => esc_html__( 'Removed tag(s) from a post', 'mainwp-child' ),
                'context'     => 'post',
                'action_name' => 'modified',
            ),
            1405 => array(
                'type_id'     => 1405,
                'desc'        => esc_html__( 'Renamed tag', 'mainwp-child' ),
                'context'     => 'tag',
                'action_name' => 'renamed',
            ),
            1410 => array(
                'type_id'     => 1410,
                'desc'        => esc_html__( 'Changed the slug of a tag', 'mainwp-child' ),
                'context'     => 'tag',
                'action_name' => 'modified',
            ),
            1415 => array(
                'type_id'     => 1415,
                'desc'        => esc_html__( 'Changed the description of a tag', 'mainwp-child' ),
                'context'     => 'tag',
                'action_name' => 'modified',
            ),
            // File.
            1420 => array(
                'type_id'     => 1420,
                'desc'        => esc_html__( 'Uploaded a file', 'mainwp-child' ),
                'context'     => 'file',
                'action_name' => 'uploaded',
            ),
            1425 => array(
                'type_id'     => 1425,
                'desc'        => esc_html__( 'Deleted a file', 'mainwp-child' ),
                'context'     => 'file',
                'action_name' => 'deleted',
            ),
            // Widget.
            1430 => array(
                'type_id'     => 1430,
                'desc'        => esc_html__( 'Added a new widget', 'mainwp-child' ),
                'context'     => 'widget',
                'action_name' => 'added',
            ),
            1435 => array(
                'type_id'     => 1435,
                'desc'        => esc_html__( 'Modified a widget', 'mainwp-child' ),
                'context'     => 'widget',
                'action_name' => 'modified',
            ),
            1440 => array(
                'type_id'     => 1440,
                'desc'        => esc_html__( 'Deleted a widget', 'mainwp-child' ),
                'context'     => 'widget',
                'action_name' => 'deleted',
            ),
            1445 => array(
                'type_id'     => 1445,
                'desc'        => esc_html__( 'Moved a widget in between sections', 'mainwp-child' ),
                'context'     => 'widget',
                'action_name' => 'modified',
            ),
            1450 => array(
                'type_id'     => 1450,
                'desc'        => esc_html__( 'Changed the position of a widget in a section', 'mainwp-child' ),
                'context'     => 'widget',
                'action_name' => 'modified',
            ),
            // Plugin.
            1455 => array(
                'type_id'     => 1455,
                'desc'        => esc_html__( 'Modified a file with the plugin editor', 'mainwp-child' ),
                'context'     => 'file',
                'action_name' => 'modified',
            ),
            1460 => array(
                'type_id'     => 1460,
                'desc'        => esc_html__( 'The automatic updates setting for a plugin was changed.', 'mainwp-child' ),
                'context'     => 'plugin',
                'action_name' => 'enabled',
            ),
            1461 => array( // Added: for recent activated plugins query.
                'type_id'     => 1461,
                'desc'        => esc_html__( 'Installed plugin is activated.', 'mainwp-child' ),
                'context'     => 'plugin',
                'action_name' => 'activated',
            ),
            // Theme.
            1465 => array(
                'type_id'     => 1465,
                'desc'        => esc_html__( 'Modified a file with the theme editor', 'mainwp-child' ),
                'context'     => 'file',
                'action_name' => 'modified',
            ),
            1470 => array(
                'type_id'     => 1470,
                'desc'        => esc_html__( 'The automatic updates setting for a theme was changed.', 'mainwp-child' ),
                'contex'      => 'theme',
                'action_name' => 'enabled',
            ),
            // Menu.
            1475 => array(
                'type_id'     => 1475,
                'desc'        => esc_html__( 'Created a menu', 'mainwp-child' ),
                'context'     => 'menu',
                'action_name' => 'created',
            ),
            1480 => array(
                'type_id'     => 1480,
                'desc'        => esc_html__( 'Added item(s) to a menu', 'mainwp-child' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            1485 => array(
                'type_id'     => 1485,
                'desc'        => esc_html__( 'Removed item(s) from a menu', 'mainwp-child' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            // Menu.
            1490 => array(
                'type_id'     => 1490,
                'desc'        => esc_html__( 'Deleted a menu', 'mainwp-child' ),
                'context'     => 'menu',
                'action_name' => 'deleted',
            ),
            1495 => array(
                'type_id'     => 1495,
                'desc'        => esc_html__( 'Changed the settings of a menu', 'mainwp-child' ),
                'contex'      => 'menu',
                'action_name' => 'enabled',
            ),
            1500 => array(
                'type_id'     => 1500,
                'desc'        => esc_html__( 'Modified the item(s) in a menu', 'mainwp-child' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            1505 => array(
                'type_id'     => 1505,
                'desc'        => esc_html__( 'Renamed a menu', 'mainwp-child' ),
                'context'     => 'menu',
                'action_name' => 'renamed',
            ),
            1510 => array(
                'type_id'     => 1510,
                'desc'        => esc_html__( 'Changed the order of the objects in a menu.', 'mainwp-child' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            1515 => array(
                'type_id'     => 1515,
                'desc'        => esc_html__( 'Moved an item as a sub-item in a menu', 'mainwp-child' ),
                'context'     => 'menu',
                'action_name' => 'modified',
            ),
            // Comment.
            1520 => array(
                'type_id'     => 1520,
                'desc'        => esc_html__( 'Approved a comment', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'approved',
            ),
            1525 => array(
                'type_id'     => 1525,
                'desc'        => esc_html__( 'Unapproved a comment', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'unapproved',
            ),
            1530 => array(
                'type_id'     => 1530,
                'desc'        => esc_html__( 'Replied to a comment', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'created',
            ),
            1535 => array(
                'type_id'     => 1535,
                'desc'        => esc_html__( 'Edited a comment', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'modified',
            ),
            1540 => array(
                'type_id'     => 1540,
                'desc'        => esc_html__( 'Marked a comment as spam', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'unapproved',
            ),
            1545 => array(
                'type_id'     => 1545,
                'desc'        => esc_html__( 'Marked a comment as not spam', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'approved',
            ),
            1550 => array(
                'type_id'     => 1550,
                'desc'        => esc_html__( 'Moved a comment to trash', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'deleted',
            ),
            1555 => array(
                'type_id'     => 1555,
                'desc'        => esc_html__( 'Restored a comment from the trash', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'restored',
            ),
            1560 => array(
                'type_id'     => 1560,
                'desc'        => esc_html__( 'Permanently deleted a comment', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'deleted',
            ),
            1565 => array(
                'type_id'     => 1565,
                'desc'        => esc_html__( 'Posted a comment', 'mainwp-child' ),
                'context'     => 'comment',
                'action_name' => 'created',
            ),
            // User.
            1570 => array(
                'type_id'     => 1570,
                'desc'        => esc_html__( 'Successfully logged in', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'login',
            ),
            1575 => array(
                'type_id'     => 1575,
                'desc'        => esc_html__( 'Successfully logged out', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'logout',
            ),
            1580 => array(
                'type_id'     => 1580,
                'desc'        => esc_html__( 'Successful log in but other sessions exist for user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'login',
            ),
            1585 => array(
                'type_id'     => 1585,
                'desc'        => esc_html__( 'Logged out all other sessions with same user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'logout',
            ),
            1590 => array( // ??? 11007 - Terminated a user session.
                'type_id'     => 1590,
                'desc'        => esc_html__( 'Terminated a user session', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'logout',
            ),
            1595 => array(
                'type_id'     => 1595,
                'desc'        => esc_html__( 'Switched to another user', 'mainwp-child' ),
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
            1605 => array(
                'type_id'     => 1605,
                'desc'        => esc_html__( 'A new user was created', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'created',
            ),
            1610 => array(
                'type_id'     => 1610,
                'desc'        => esc_html__( 'User created a new user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'created',
            ),
            1615 => array(
                'type_id'     => 1615,
                'desc'        => esc_html__( 'Change the role of a user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1620 => array( // for BBPress users.
                'type_id'     => 1620,
                'desc'        => esc_html__( 'Change the role of a user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1625 => array(
                'type_id'     => 1625,
                'desc'        => esc_html__( 'Changed the password', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1630 => array(
                'type_id'     => 1630,
                'desc'        => esc_html__( 'Changed the password of a user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1635 => array(
                'type_id'     => 1635,
                'desc'        => esc_html__( 'Changed the email address', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1640 => array(
                'type_id'     => 1640,
                'desc'        => esc_html__( 'Changed the email address of a user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1645 => array(
                'type_id'     => 1645,
                'desc'        => esc_html__( 'Deleted a user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'deleted',
            ),
            1650 => array(
                'type_id'     => 1650,
                'desc'        => esc_html__( 'Granted super admin privileges to a user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1655 => array(
                'type_id'     => 1655,
                'desc'        => esc_html__( 'Revoked super admin privileges from a user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1660 => array(
                'type_id'     => 1660,
                'desc'        => esc_html__( 'Added a network user to a site', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'created',
            ),
            1665 => array(
                'type_id'     => 1665,
                'desc'        => esc_html__( 'Removed a network user from a site', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1670 => array(
                'type_id'     => 1670,
                'desc'        => esc_html__( 'Created a new network user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1675 => array(
                'type_id'     => 1675,
                'desc'        => esc_html__( 'User has been activated on the network', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'activated',
            ),
            1680 => array(
                'type_id'     => 1680,
                'desc'        => esc_html__( 'Opened the profile page of a user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'opened',
            ),
            1685 => array(
                'type_id'     => 1685,
                'desc'        => esc_html__( 'Changed a custom field value in user profile', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1690 => array(
                'type_id'     => 1690,
                'desc'        => esc_html__( 'Created a custom field in a user profile', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1695 => array(
                'type_id'     => 1695,
                'desc'        => esc_html__( 'Changed the first name (of a user)', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1700 => array(
                'type_id'     => 1700,
                'desc'        => esc_html__( 'Changed the last name (of a user)', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1705 => array(
                'type_id'     => 1705,
                'desc'        => esc_html__( 'Changed the nickname (of a user)', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1710 => array(
                'type_id'     => 1710,
                'desc'        => esc_html__( 'Changed the display name (of a user)', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1715 => array(
                'type_id'     => 1715,
                'desc'        => esc_html__( 'Changed the website URL of the user', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'modified',
            ),
            1720 => array(
                'type_id'     => 1720,
                'desc'        => esc_html__( 'User added / removed application password from own profile', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'added',
            ),
            1725 => array(
                'type_id'     => 1725,
                'desc'        => esc_html__( 'User added / removed application password from another user’s profile', 'mainwp-child' ),
                'context'     => 'user',
                'action_name' => 'added',
            ),
            1730 => array(
                'type_id'     => 1730,
                'desc'        => esc_html__( 'User revoked all application passwords from own profile', 'mainwp-child' ),
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
            1740 => array(
                'type_id'     => 1740,
                'desc'        => esc_html__( 'Plugin created database table(s)', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'created',
            ),
            1745 => array(
                'type_id'     => 1745,
                'desc'        => esc_html__( 'Plugin modified the structure of database table(s)', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'modified',
            ),
            1750 => array(
                'type_id'     => 1750,
                'desc'        => esc_html__( 'Plugin deleted database table(s)', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'deleted',
            ),
            1755 => array(
                'type_id'     => 1755,
                'desc'        => esc_html__( 'Theme created database table(s)', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'created',
            ),
            1760 => array(
                'type_id'     => 1760,
                'desc'        => esc_html__( 'Theme modified the structure of table(s) in the database', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'modified',
            ),
            1765 => array(
                'type_id'     => 1765,
                'desc'        => esc_html__( 'Theme deleted database table(s)', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'deleted',
            ),
            1770 => array(
                'type_id'     => 1770,
                'desc'        => esc_html__( 'Unknown component created database table(s)', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'created',
            ),
            1775 => array(
                'type_id'     => 1775,
                'desc'        => esc_html__( 'Unknown component modified the structure of table(s )in the database', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'modified',
            ),
            1780 => array(
                'type_id'     => 1780,
                'desc'        => esc_html__( 'Unknown component deleted database table(s)', 'mainwp-child' ),
                'context'     => 'database',
                'action_name' => 'deleted',
            ),
            // System setting.
            1785 => array(
                'type_id'     => 1785,
                'desc'        => esc_html__( 'Changed the option anyone can register', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            1790 => array(
                'type_id'     => 1790,
                'desc'        => esc_html__( 'Changed the new user default role', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1795 => array(
                'type_id'     => 1795,
                'desc'        => esc_html__( 'Changed the WordPress administrator notification email address', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1800 => array(
                'type_id'     => 1800,
                'desc'        => esc_html__( 'Changed the WordPress permalinks', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1805 => array(
                'type_id'     => 1805,
                'desc'        => esc_html__( 'Changed the setting: Discourage search engines from indexing this site', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            1810 => array(
                'type_id'     => 1810,
                'desc'        => esc_html__( 'Enabled / disabled comments on the website', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            1815 => array(
                'type_id'     => 1815,
                'desc'        => esc_html__( 'Changed the setting: Comment author must fill out name and email', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            1820 => array(
                'type_id'     => 1820,
                'desc'        => esc_html__( 'Changed the setting: Users must be logged in and registered to comment', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            1825 => array(
                'type_id'     => 1825,
                'desc'        => esc_html__( 'Changed the setting: Automatically close comments after a number of days', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            1830 => array(
                'type_id'     => 1830,
                'desc'        => esc_html__( 'Changed the value of the setting: Automatically close comments after a number of days.', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1835 => array(
                'type_id'     => 1835,
                'desc'        => esc_html__( 'Changed the setting: Comments must be manually approved', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            1840 => array(
                'type_id'     => 1840,
                'desc'        => esc_html__( 'Changed the setting: Author must have previously approved comments for the comments to appear', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'enabled',
            ),
            1845 => array(
                'type_id'     => 1845,
                'desc'        => esc_html__( 'Changed the minimum number of links that a comment must have to be held in the queue', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1850 => array(
                'type_id'     => 1850,
                'desc'        => esc_html__( 'Modified the list of keywords for comments moderation', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1855 => array(
                'type_id'     => 1855,
                'desc'        => esc_html__( 'Modified the list of keywords for comments blacklisting', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1860 => array(
                'type_id'     => 1860,
                'desc'        => esc_html__( 'Changed the WordPress address (URL)', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1865 => array(
                'type_id'     => 1865,
                'desc'        => esc_html__( 'Changed the site address (URL)', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1870 => array(
                'type_id'     => 1870,
                'desc'        => esc_html__( 'Changed the “Your homepage displays” WordPress setting', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1875 => array(
                'type_id'     => 1875,
                'desc'        => esc_html__( 'Changed the homepage in the WordPress setting', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1880 => array(
                'type_id'     => 1880,
                'desc'        => esc_html__( 'Changed the posts page in the WordPress settings', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1885 => array(
                'type_id'     => 1885,
                'desc'        => esc_html__( 'Changed the Timezone in the WordPress settings', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1890 => array(
                'type_id'     => 1890,
                'desc'        => esc_html__( 'Changed the Date format in the WordPress settings', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1895 => array(
                'type_id'     => 1895,
                'desc'        => esc_html__( 'Changed the Time format in the WordPress settings', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1900 => array(
                'type_id'     => 1900,
                'desc'        => esc_html__( 'User changed the WordPress automatic update settings', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1905 => array(
                'type_id'     => 1905,
                'desc'        => esc_html__( 'Changed the site language', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1910 => array(
                'type_id'     => 1910,
                'desc'        => esc_html__( 'Changed the site title', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            1915 => array(
                'type_id'     => 1915,
                'desc'        => esc_html__( 'Added site icon', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'added',
            ),
            1920 => array(
                'type_id'     => 1920,
                'desc'        => esc_html__( 'Changed site icon', 'mainwp-child' ),
                'context'     => 'system-setting',
                'action_name' => 'modified',
            ),
            // WordPress Cron.
            1925 => array(
                'type_id'     => 1925,
                'desc'        => esc_html__( 'New one time task (cron job) created', 'mainwp-child' ),
                'context'     => 'cron-job',
                'action_name' => 'created',
            ),
            1930 => array(
                'type_id'     => 1930,
                'desc'        => esc_html__( 'New recurring task (cron job) created', 'mainwp-child' ),
                'context'     => 'cron-job',
                'action_name' => 'created',
            ),
            1935 => array(
                'type_id'     => 1935,
                'desc'        => esc_html__( 'New recurring task (cron job) created', 'mainwp-child' ),
                'context'     => 'cron-job',
                'action_name' => 'created',
            ),
            1940 => array(
                'type_id'     => 1940,
                'desc'        => esc_html__( 'One time task (cron job) executed', 'mainwp-child' ),
                'context'     => 'cron-job',
                'action_name' => 'executed',
            ),
            1945 => array(
                'type_id'     => 1945,
                'desc'        => esc_html__( 'Recurring task (cron job) executed', 'mainwp-child' ),
                'context'     => 'cron-job',
                'action_name' => 'executed',
            ),
            1950 => array(
                'type_id'     => 1950,
                'desc'        => esc_html__( 'Deleted one-time task (cron job)', 'mainwp-child' ),
                'context'     => 'cron-job',
                'action_name' => 'deleted',
            ),
            1955 => array(
                'type_id'     => 1955,
                'desc'        => esc_html__( 'Deleted recurring task (cron job)', 'mainwp-child' ),
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
