<?php
/**
 * MainWP Database Logs.
 *
 * This file handles all interactions with the Client DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;

/**
 * Class Log_DB
 *
 * @package MainWP\Dashboard
 */
class Log_DB_Helper extends MainWP_DB {

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

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
     * Method get_log_by_id().
     *
     * @param int $log_id Log id.
     *
     * @return object|false log.
     */
    public function get_log_by_id( $log_id ) {
        if ( empty( $log_id ) ) {
            return false;
        }
        return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_logs' ) . ' WHERE log_id = %d ', $log_id ) ); //phpcs:ignore -- ok.
    }

    /**
     * Method update_log().
     *
     * @param array $data Log data.
     *
     * @return mixed
     */
    public function update_log( $data ) {
        if ( empty( $data ) || ! is_array( $data ) || empty( $data['log_id'] ) ) {
            return false;
        }

        $log_id = $data['log_id'];
        unset( $data['log_id'] );

        return $this->wpdb->update( $this->table_name( 'wp_logs' ), $data, array( 'log_id' => $log_id ) );
    }

    /**
     * Method remove_logs_by().
     *
     * @param int $site_id Site ID.
     *
     * @return void.
     */
    public function remove_logs_by( $site_id ) { //phpcs:ignore -- NOSONAR -complex.
        //phpcs:disable
        $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE lo, me
                FROM ' . $this->table_name( 'wp_logs' ) . ' lo
                JOIN ' . $this->table_name( 'wp_logs_meta' ) . ' me ON lo.log_id = me.meta_log_id
                WHERE lo.site_id  = %d',
                $site_id
            )
        );
        //phpcs:enable
    }

    /**
     * Method dismiss_all_changes().
     *
     * Handle dismiss all sites changes.
     *
     * @return mixed
     */
    public function dismiss_all_changes() {
        return $this->wpdb->update( $this->table_name( 'wp_logs' ), array( 'dismiss' => 1 ), array( 'dismiss' => 0 ) );
    }

    /**
     * Method count_events().
     *
     * @return mixed Count events.
     */
    public function count_events() {
        return $this->wpdb->get_var( 'SELECT count(*) FROM ' . $this->table_name( 'wp_logs' ) . ' WHERE dismiss = 0 ' ); //phpcs:ignore -- ok.
    }


    /**
     * Method get_logs_users().
     *
     * @return array Users list.
     */
    public function get_logs_users() { //phpcs:ignore -- NOSONAR -complex.
        $where = MainWP_DB::instance()->get_sql_where_allow_access_sites( 'wp' );
        $sql   = 'SELECT lo.log_id, lo.site_id, lo.user_id, lo.connector, wp.name, me.meta_log_id, me.meta_key, me.meta_value '
        . ', CASE
                WHEN connector != "non-mainwp-changes" THEN "dashboard"
                ELSE "wpadmin"
            END AS log_source '
        . ' FROM ' . $this->table_name( 'wp_logs' ) . ' lo '
        . ' LEFT JOIN ' . $this->table_name( 'wp' ) . ' wp ON lo.site_id = wp.id '
        . ' LEFT JOIN ' . $this->table_name( 'wp_logs_meta' ) . ' me ON lo.log_id = me.meta_log_id '
        . ' WHERE me.meta_key = "user_meta_json" '
        . $where
        . ' GROUP BY site_id, user_id, log_source ';

        $users_sites_logs = $this->wpdb->get_results( $sql ); //phpcs:ignore -- ok.
        if ( $users_sites_logs ) {
            $logs_users = array();
            $dash_users = array();
            foreach ( $users_sites_logs as $item ) {
                if ( ! empty( $item->site_id ) && ! empty( $item->name ) && ! empty( $item->meta_value ) ) {
                    $info = json_decode( $item->meta_value, true );
                    if ( is_array( $info ) ) {
                        if ( ! empty( $info['wp_user_id'] ) ) { // child site users.
                            $logs_users[ $item->log_id ] = array(
                                'id'         => $item->user_id,
                                'site_id'    => $item->site_id,
                                'login'      => $info['action_user'],
                                'nicename'   => $info['display_name'],
                                'source'     => ! empty( $item->name ) ? $item->name : '',
                                'wp_user_id' => $info['wp_user_id'],
                            );
                        } elseif ( isset( $info['user_login'] ) ) { // dashboard users.
                            // to prevent add double dashboard users in the users selection.
                            if ( in_array( $item->user_id, $dash_users ) ) {
                                continue;
                            }
                            $dash_users[] = $item->user_id;
                            $nicename     = ! empty( $info['user_login'] ) ? $info['user_login'] : '';
                            if ( empty( $nicename ) ) {
                                if ( ! empty( $info['agent'] ) ) {
                                    $nicename = $info['agent'];
                                } else {
                                    $nicename = 'N/A';
                                }
                            }
                            $logs_users[ $item->log_id ] = array(
                                'id'       => (int) $item->user_id,
                                'site_id'  => $item->site_id,
                                'login'    => $info['user_login'],
                                'nicename' => $nicename,
                                'source'   => 'dashboard',
                            );
                        }
                    }
                }
            }
            return $logs_users;
        }

        return array();
    }
}
