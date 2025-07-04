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
     * Compatible method.
     *
     * @return mixed
     */
    public function dismiss_all_changes() {
        return $this->wpdb->update( $this->table_name( 'wp_logs' ), array( 'dismiss' => 1 ), array( 'dismiss' => 0 ) );
    }

    /**
     * Method archive_sites_changes().
     *
     * @param int   $before_timestamp Archive sites changes created before time.
     * @param int   $by_limit By limit.
     * @param mixed $dismiss Dismiss: false|0|1.
     *
     * @return mixed
     */
    public function archive_sites_changes( $before_timestamp = 0, $by_limit = 0, $dismiss = false ) {

        $where = '';
        $order = '';
        if ( ! empty( $before_timestamp ) ) {
            $where .= ' AND created < ' . (int) $before_timestamp;
        }

        if ( ! empty( $by_limit ) ) {
            $order .= ' ORDER BY created ASC LIMIT ' . (int) $by_limit;
        }

        if ( false !== $dismiss ) {
            $where .= ' AND dismiss = ' . intval( $dismiss );
        }

        $logs = $this->wpdb->get_results(  'SELECT * FROM ' . $this->table_name('wp_logs') . ' WHERE 1 ' . $where . $order , ARRAY_A ); //phpcs:ignore -- NOSONAR -ok.
        if ( $logs ) {
            foreach ( $logs as $log ) {
                $this->archive_log( $log );
            }
        }
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
        $sql   = 'SELECT lo.log_id, lo.site_id, lo.user_id, lo.user_login, lo.connector, wp.name, me.meta_log_id, me.meta_key, me.meta_value '
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
                        if ( 'non-mainwp-changes' === $item->connector ) { // child site users ID, 0 is child site system user.
                            if ( ! empty( isset( $info['user_id'] ) ) && ! empty( $info['user_login'] ) ) {
                                $act_user = $info['user_login'];
                            } else {
                                // to compatible.
                                $act_user = $info['action_user'];
                            }

                            if ( 'wp_cron' === $act_user ) {
                                $act_user = __( 'during WP Cron', 'mainwp' );
                            }

                            $logs_users[ $item->log_id ] = array(
                                'id'                => $item->user_id,
                                'site_id'           => $item->site_id,
                                'login'             => $act_user,
                                'nicename'          => $info['display_name'],
                                'source'            => ! empty( $item->name ) ? $item->name : '', // site name.
                                'is_dashboard_user' => 0,
                            );
                        } else { // dashboard users.
                            // to prevent add double dashboard users in the users selection.
                            if ( in_array( $item->user_id, $dash_users ) ) {
                                continue;
                            }

                            $user_login = '';

                            if ( ! empty( $item->user_login ) ) {
                                $user_login = $item->user_login;
                            } elseif ( ! empty( $info['user_login'] ) ) { // compatible user login value.
                                $user_login = $info['user_login'];
                            }

                            $dash_users[] = $item->user_id;

                            $nicename = $user_login;
                            if ( empty( $nicename ) ) {
                                if ( ! empty( $info['agent'] ) ) {
                                    $nicename = $info['agent'];
                                    if ( 'wp_cron' === $nicename ) {
                                        $nicename = __( 'during WP Cron', 'mainwp' );
                                    }
                                } else {
                                    $nicename = 'N/A';
                                }
                            }
                            $logs_users[ $item->log_id ] = array(
                                'id'                => (int) $item->user_id,
                                'site_id'           => $item->site_id,
                                'login'             => $user_login,
                                'nicename'          => $nicename,
                                'source'            => 'dashboard',
                                'is_dashboard_user' => 1,
                            );
                        }
                    }
                }
            }
            return $logs_users;
        }

        return array();
    }

    /**
     * Method get_logs_db_stats().
     *
     * @return array DB stats.
     */
    public function get_logs_db_stats() {
        $sql_meta       = 'SELECT meta_key, COUNT(*) AS total
            FROM ' . $this->table_name( 'wp_logs_meta' ) . '
            GROUP BY meta_key
            ORDER BY total DESC';
        $sql_total      = 'SELECT COUNT(*) AS total FROM ' . $this->table_name( 'wp_logs' );
        $sql_meta_total = 'SELECT COUNT(*) AS total FROM ' . $this->table_name( 'wp_logs_meta' );
        return array(
            'logs_count'   => $this->wpdb->get_var( $sql_total ), //phpcs:ignore --NOSONAR -ok.
            'logs_meta_count'   => $this->wpdb->get_var( $sql_meta_total ), //phpcs:ignore --NOSONAR -ok.
            'logs_meta_db_info' => $this->wpdb->get_results( $sql_meta ), //phpcs:ignore --NOSONAR -ok.
        );
    }

    /**
     * Method archive_log().
     *
     * @param array $data Log data to archive.
     *
     * @return mixed
     */
    public function archive_log( $data ) {
        if ( empty( $data ) || ! is_array( $data ) || empty( $data['log_id'] ) ) {
            return false;
        }

        $log_id = $data['log_id'];

        $log = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name('wp_logs') . ' WHERE log_id = %d', $log_id ), ARRAY_A ); //phpcs:ignore -- NOSONAR -ok.
        if ( $log ) {
            $log['archived_at'] = time();
            $this->wpdb->insert( $this->table_name( 'wp_logs_archive' ), $log );
            $log_mt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name('wp_logs_meta') . ' WHERE meta_log_id = %d', $log_id ), ARRAY_A ); //phpcs:ignore -- NOSONAR -ok.

            if ( $log_mt ) {
                foreach ( $log_mt as $mt ) {
                    $this->wpdb->insert( $this->table_name( 'wp_logs_meta_archive' ), $mt );
                }
            }

            $this->wpdb->delete(
                $this->table_name( 'wp_logs' ),
                array(
                    'log_id' => $log_id,
                )
            );

            $this->wpdb->delete(
                $this->table_name( 'wp_logs_meta' ),
                array(
                    'meta_log_id' => $log_id,
                )
            );

            return $log;
        }
        return false;
    }


    /**
     * Returns the most recent non-mainwp-changes stored for the given site.
     *
     * @param integer $site_id - The ID of the site to retrieve the most recent event.
     * @param integer $limit - Limit number of result.
     *
     * @return array
     *
     * @since 5.5
     */
    public function get_latest_non_mainwp_changes_logs_by_siteid( $site_id, $limit = 1 ): array {
        return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_logs' ) . " WHERE site_id=%d AND `connector` = 'non-mainwp-changes' AND log_type_id = 0 ORDER BY created DESC LIMIT %d", $site_id, $limit ) ); //phpcs:ignore -- ok.
    }


    /**
     * Returns the most recent non-mainwp-changes changes logs stored for the given site.
     *
     * @param integer $site_id - The ID of the site to retrieve the most recent event.
     * @param integer $limit - Limit number of result.
     *
     * @return array
     *
     * @since 5.5
     */
    public function get_latest_changes_logs_by_siteid( $site_id, $limit = 1 ): array {
        return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_logs' ) . " WHERE site_id=%d AND `connector` = 'non-mainwp-changes' AND log_type_id IS NOT NULL AND log_type_id != 0 ORDER BY created DESC LIMIT %d", $site_id, $limit ) ); //phpcs:ignore -- ok.
    }

    /**
     * Get db size.
     *
     * @return string Return current db size.
     */
    public function count_legacy_dismissed() {
        return $this->wpdb->get_var( 'SELECT count(*) FROM ' . $this->table_name( 'wp_logs' ) . ' WHERE dismiss = 1 ' );
    }

    /**
     * Get db size.
     *
     * @return string Return current db size.
     */
    public function get_db_size() {
        $size = get_transient( 'mainwp_module_log_transient_db_logs_size' );
        if ( false !== $size ) {
            return $size;
        }

        global $wpdb;
        $sql = $wpdb->prepare(
            'SELECT
        ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE
        TABLE_SCHEMA = %s
        AND (
        table_name = %s
        OR table_name = %s
        OR table_name = %s
        OR table_name = %s
        )',
            $wpdb->dbname,
            $wpdb->mainwp_tbl_logs,
            $wpdb->mainwp_tbl_logs_meta,
            $this->table_name( 'wp_logs_archive' ),
            $this->table_name( 'wp_logs_meta_archive' )
        );

        $dbsize_mb = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared SQL.

        set_transient( 'mainwp_module_log_transient_db_logs_size', $dbsize_mb, 15 * MINUTE_IN_SECONDS );

        return $dbsize_mb;
    }

    /**
     * Method truncate_archive_tables().
     */
    public function truncate_archive_tables() {
        $this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_name( 'wp_logs_archive' ) );
        $this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_name( 'wp_logs_meta_archive' ) );
    }
}
