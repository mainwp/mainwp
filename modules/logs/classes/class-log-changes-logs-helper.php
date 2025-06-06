<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

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

        foreach ( $sync_changes as $data ) {

            if ( ! is_array( $data ) || empty( $data['created_on'] ) ) {
                continue;
            }

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
            $context  = isset( $data['log_type_id'] ) ? $this->map_changes_context( $data['log_type_id'] ) : '';
            $duration = isset( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : 0; // sanitize_text_field for seconds.
            $created  = isset( $data['created_on'] ) ? (float) ( $data['created_on'] ) : microtime( true );

            // To support searching user on meta.
            $meta['user_login'] = $user_login;

            $record_mapping = array(
                'site_id'  => $site_id,
                'user_id'  => $user_id,
                'created'  => $created,
                'context'  => $context,
                'action'   => $action,
                'state'    => 1,
                'duration' => $duration,
                'meta'     => $meta,
            );

            $sum = 'wordpress' !== $record_mapping['context'] ? esc_html( ucfirst( rtrim( $record_mapping['context'], 's' ) ) ) : 'WordPress'; //phpcs:ignore -- wordpress text.
            if ( 'wordpress' === $record_mapping['context'] ) {
                $sum = 'WordPress';
            }
            $record_mapping['item'] = $sum;
            do_action( 'mainwp_sync_site_log_changes_logs', $website, $record_mapping, $data );
        }

        return true;
    }

    /**
     * Get event type data array or optionally just value of a single type.
     *
     * @param string $type A type that the string is requested for (optional).
     *
     * @return array|string
     */
    public static function get_event_type_data( $type = '' ) {
        if ( empty( self::$event_action_types ) ) {
            self::$event_action_types = array(
                'login'        => esc_html__( 'Login', 'mainwp-child' ),
                'logout'       => esc_html__( 'Logout', 'mainwp-child' ),
                'installed'    => esc_html__( 'Installed', 'mainwp-child' ),
                'activated'    => esc_html__( 'Activated', 'mainwp-child' ),
                'deactivated'  => esc_html__( 'Deactivated', 'mainwp-child' ),
                'uninstalled'  => esc_html__( 'Uninstalled', 'mainwp-child' ),
                'updated'      => esc_html__( 'Updated', 'mainwp-child' ),
                'created'      => esc_html__( 'Created', 'mainwp-child' ),
                'modified'     => esc_html__( 'Modified', 'mainwp-child' ),
                'deleted'      => esc_html__( 'Deleted', 'mainwp-child' ),
                'published'    => esc_html__( 'Published', 'mainwp-child' ),
                'approved'     => esc_html__( 'Approved', 'mainwp-child' ),
                'unapproved'   => esc_html__( 'Unapproved', 'mainwp-child' ),
                'enabled'      => esc_html__( 'Enabled', 'mainwp-child' ),
                'disabled'     => esc_html__( 'Disabled', 'mainwp-child' ),
                'added'        => esc_html__( 'Added', 'mainwp-child' ),
                'failed-login' => esc_html__( 'Failed Login', 'mainwp-child' ),
                'blocked'      => esc_html__( 'Blocked', 'mainwp-child' ),
                'uploaded'     => esc_html__( 'Uploaded', 'mainwp-child' ),
                'restored'     => esc_html__( 'Restored', 'mainwp-child' ),
                'opened'       => esc_html__( 'Opened', 'mainwp-child' ),
                'viewed'       => esc_html__( 'Viewed', 'mainwp-child' ),
                'started'      => esc_html__( 'Started', 'mainwp-child' ),
                'stopped'      => esc_html__( 'Stopped', 'mainwp-child' ),
                'removed'      => esc_html__( 'Removed', 'mainwp-child' ),
                'unblocked'    => esc_html__( 'Unblocked', 'mainwp-child' ),
                'renamed'      => esc_html__( 'Renamed', 'mainwp-child' ),
                'duplicated'   => esc_html__( 'Duplicated', 'mainwp-child' ),
                'submitted'    => esc_html__( 'Submitted', 'mainwp-child' ),
                'revoked'      => esc_html__( 'Revoked', 'mainwp-child' ),
                'sent'         => esc_html__( 'Sent', 'mainwp-child' ),
                'executed'     => esc_html__( 'Executed', 'mainwp-child' ),
                'failed'       => esc_html__( 'Failed', 'mainwp-child' ),
            );
            // sort the types alphabetically.
            asort( self::$event_action_types );
            self::$event_action_types = apply_filters(
                'mainwp_module_log_changes_logs_event_type_data',
                self::$event_action_types
            );
        }
        return self::$event_action_types;
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
     * Method map_changes_context()
     *
     * @param string $type Logs type code.
     *
     * @return string Dashboard logs context to store in db.
     */
    public function map_changes_context( $type ) {

        $context = '';

        switch ( true ) {
            case in_array(
                $type,
                array(
                    // Post.
                    2000,
                    2001,
                    2002,
                    2008,
                    2012,
                    2014,
                    2016,
                    2017,
                    2019,
                    2021,
                    2047,
                    2048,
                    2049,
                    2050,
                    2053,
                    2073,
                    2074,
                    2025,
                    2027,
                    2065,
                    2086,
                    2100,
                    2101,
                    2111,
                    2112,
                    2119,
                    2120,
                    2129,
                    // xxxx, // Added / changed / removed a post’s excerpt ???.
                    2130,
                    9043,
                    2133,
                )
            ):
                $context = 'post';
                break;
            case in_array(
                $type,
                array(
                    // Custom field.
                    2131,
                    2132,
                    2054,
                    2055,
                    2062,
                )
            ):
                $context = 'post';
                break;
            case in_array(
                $type,
                array(
                    // Category.
                    2023,
                    2024,
                    2052,
                    2127,
                    2128,
                )
            ):
                $context = 'category';
                break;
            case in_array(
                $type,
                array(
                    // Tag.
                    2119,
                    2120,
                    2123,
                    2124,
                    2125,
                )
            ):
                $context = 'tags';
                break;
            case in_array(
                $type,
                array(
                    // File.
                    2010,
                    2011,
                )
            ):
                $context = 'file';
                break;
            case in_array(
                $type,
                array(
                    // Widget.
                    2042,
                    2043,
                    2044,
                    2045,
                    2071,
                )
            ):
                $context = 'widget';
                break;
            case in_array(
                $type,
                array(
                    // Plugin.
                    2051,
                    5028,
                )
            ):
                $context = 'plugin';
                break;
            case in_array(
                $type,
                array(
                    // Theme.
                    2046,
                    5029,
                )
            ):
                $context = 'theme';
                break;
            case ( in_array(
                $type,
                array(
                    // Menu.
                    2078,
                    2079,
                    2080,
                    // Menu.
                    2081,
                    2082,
                    2083,
                    2084,
                    2085,
                    2089,
                )
            ) ):
                $context = 'menu';
                break;
            case in_array(
                $type,
                array(
                    // Comment.
                    2090,
                    2091,
                    2092,
                    2093,
                    2094,
                    2095,
                    2096,
                    2097,
                    2098,
                    2099,
                )
            ):
                $context = 'comment';
                break;
            case in_array(
                $type,
                array(
                    // User.
                    1000,
                    1001,
                    1005,
                    1006,
                    1009, // ??? 1007 - Terminated a user session.
                    1008,
                    1010,
                    4000,
                    4001,
                    4002,
                    4003,
                    4004,
                    4005,
                    4006,
                    4007,
                    4008,
                    4009,
                    4012,
                    4011,
                    4010,
                    4013,
                    4014,
                    4015,
                    4016,
                    4017,
                    4018,
                    4019,
                    4020,
                    4021,
                    4025,
                    4026,
                    4028,
                    4027,
                )
            ):
                $context = 'users';
                break;
            case in_array(
                $type,
                array(
                    // Database.
                    5010,
                    5011,
                    5012,
                    5013,
                    5014,
                    5015,
                    5016,
                    5017,
                    5018,
                )
            ):
                $context = 'database';
                break;
            case in_array(
                $type,
                array(
                    // System setting.
                    6001,
                    6002,
                    6003,
                    6005,
                    6008,
                    6009,
                    6010,
                    6011,
                    6012,
                    6013,
                    6014,
                    6015,
                    6016,
                    6017,
                    6018,
                    6024,
                    6025,
                    6035,
                    6036,
                    6037,
                    6040,
                    6041,
                    6042,
                    6044,
                    6045,
                    6059,
                    6063,
                    6064,
                )
            ):
                $context = 'system';
                break;
            case in_array(
                $type,
                array(
                    // WordPress Cron.
                    6066,
                    6067,
                    6068,
                    6069,
                    6070,
                    6071,
                    6072,
                )
            ):
                $context = 'cron';
                break;
            default:
                $context = '';
                break;
        }
        return \apply_filters( 'mainwp_module_log_changes_logs_mapping_contexts', $context, $type );
    }
}
