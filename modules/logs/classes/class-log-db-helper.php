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
use MainWP\Dashboard\MainWP_Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     * Method get_sites_options().
     *
     * @param array $sites_ids Site Ids array.
     * @param array $opts Site options array.
     *
     * @return array
     */
    public function get_sites_options( $sites_ids, $opts = array() ) {

        $where_opts = implode(
            '" OR name ="',
            array_map(
                function ( $val ) {
                    return $this->escape( $val );
                },
                $opts
            )
        );

        if ( empty( $where_opts ) ) {
            return array();
        }

        $sql = sprintf(
            'SELECT name,value,wpid FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid IN ( %s ) AND ( name="' . $where_opts . '" )',
            implode( ',', array_unique( $sites_ids ) )
        );

        return $this->wpdb->get_results( $sql ); //phpcs:ignore -- ok.
    }

    /**
     * Method dismiss_all_changes().
     *
     * Handle dismiss all Network Activity data.
     *
     * Compatible method.
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
                            } elseif ( isset( $info['action_user'] ) ) { // to compatible.
                                $act_user = $info['action_user'];
                            } else {
                                $act_user = $item->user_login;
                            }

                            if ( 'wp_cron' === $act_user ) {
                                $act_user = __( 'during WP Cron', 'mainwp' );
                            }

                            $logs_users[ $item->log_id ] = array(
                                'id'                => $item->user_id,
                                'site_id'           => $item->site_id,
                                'login'             => $act_user,
                                'nicename'          => isset( $info['display_name'] ) ? $info['display_name'] : $item->user_login,
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
     * Method get_changes_logs_by().
     *
     * @param array $args Arguments.
     *
     * @return array DB stats.
     */
    public function get_changes_logs_by( $args ) { // phpcs:ignore -- NOSONAR - complex.

        $site_id = isset( $args['wpid'] ) ? $args['wpid'] : 0; // int or array of int site ids.

        $from_date   = ! empty( $args['from_date'] ) ? sanitize_text_field( $args['from_date'] ) : gmdate( 'Y-m-d' );
        $days_number = isset( $args['days_number'] ) ? intval( $args['days_number'] ) : 10;
        $slug_value  = isset( $args['slug'] ) ? sanitize_text_field( $args['slug'] ) : '';
        $name_value  = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : ''; // supports in case slug are empty.
        $type        = isset( $args['type'] ) ? sanitize_text_field( $args['type'] ) : '';

        $target_dt = ! empty( $args['target_date'] ) ? sanitize_text_field( $args['target_date'] ) : '';

        if ( ! in_array( $type, array( 'plugin', 'theme' ) ) ) {
            return array();
        }

        global $wpdb;

        if ( ! empty( $target_dt ) ) {

            $utc_start = MainWP_Utility::get_utc_timestamp_by_date( $target_dt );
            $utc_end   = MainWP_Utility::get_utc_timestamp_by_date( $target_dt, 1 );

            $utc_start_micro = (int) $utc_start * 1000000;
            $utc_end_micro   = (int) $utc_end * 1000000;

            $where_site   = '';
            $prepare_args = array( $type, $utc_start_micro, $utc_end_micro );

            if ( ! empty( $site_id ) ) {
                $where_site     = ' AND i.site_id = %d';
                $prepare_args[] = $site_id;
            }

            $query = '
                SELECT i.*
                FROM ' . $this->table_name( 'wp_logs' ) . ' i
                WHERE i.context = %s
                AND i.created >= %d
                AND i.created < %d
                ' . $where_site . '
                ORDER BY i.created ASC
            ';

            $sql = $wpdb->prepare(
                $query, //phpcs:ignore --ok.
                ...$prepare_args
            );

            $items = $wpdb->get_results( $sql); //phpcs:ignore --ok.

            $this->get_meta_items( $items );

            return array(
                'items'       => $items,
                'onward_time' => 0,
                'total'       => count( $items ),
                'more_date'   => '',
            );

        }

        if ( empty( $site_id ) || ( empty( $slug_value ) && empty( $name_value ) ) || empty( $days_number ) ) {
            return array();
        }

        $join_meta_cond = '';
        $extra_prepare  = array();

        if ( 'plugin' === $type && ! empty( $slug_value ) ) {
            $join_meta_cond  = ' AND ( m.meta_key = "slug" AND ( m.meta_value = %s OR m.meta_value = %s ) ) ';
            $extra_prepare[] = $slug_value;
            $extra_prepare[] = $slug_value . '/' . basename( $slug_value, '.php' ) . '.php';
        } elseif ( 'theme' === $type ) {
            $join_meta_cond  = ' AND ( ( m.meta_key = "slug" AND m.meta_value = %s ) OR ( m.meta_key = "name" AND m.meta_value = %s ) ) ';
            $extra_prepare[] = $slug_value;
            $extra_prepare[] = $name_value;
        } else {
            return array();
        }

        $onward = '
            SELECT i.*
            FROM ' . $this->table_name( 'wp_logs' ) . ' i
            LEFT JOIN ' . $this->table_name( 'wp_logs_meta' ) . ' m
            ON i.log_id = m.meta_log_id
            ' . $join_meta_cond . '
            WHERE i.site_id = %d
            AND i.context = %s
            ORDER BY i.created ASC
            LIMIT 1;
        ';

        $onward_params = array_merge( array( $site_id, $type ), $extra_prepare );

        $sql_onward = $wpdb->prepare(
            $onward, //phpcs:ignore --ok.
            ...$onward_params
        );

        $found = $wpdb->get_row( $sql_onward ); //phpcs:ignore --ok.

        $count = '
            SELECT count(*)
            FROM ' . $this->table_name( 'wp_logs' ) . ' i
            LEFT JOIN ' . $this->table_name( 'wp_logs_meta' ) . ' m
            ON i.log_id = m.meta_log_id
            ' . $join_meta_cond . '
            WHERE i.site_id = %d
            AND i.context = %s';

        $count_params = array_merge( array( $site_id, $type ), $extra_prepare );

        $sql_count = $wpdb->prepare(
            $count, //phpcs:ignore --ok.
            ...$count_params
        );

        $total_count = $wpdb->get_var( $sql_count ); //phpcs:ignore --ok.

        global $wpdb;

        $ctx = MainWP_Utility::get_time_context( $from_date );

        $day_micros    = $ctx['day_micros'];
        $offset_micro  = $ctx['offset_micro'];
        $from_date_utc = $ctx['from_date_utc'];

        $sql = "
        SELECT
            d.day_start,
            i.*,
            MAX(m.meta_value) as meta_value
        FROM (
            SELECT
                ((FLOOR((created + %d) / %d) * %d) - %d) AS day_start
            FROM {$this->table_name('wp_logs')}
            WHERE site_id = %d
            AND created < (UNIX_TIMESTAMP(%s) * 1000000)
            GROUP BY day_start
            ORDER BY day_start DESC
            LIMIT %d
        ) d
        JOIN {$this->table_name('wp_logs')} i
            ON i.created >= d.day_start
            AND i.created < d.day_start + %d
            AND i.site_id = %d
            AND i.context = %s
        LEFT JOIN {$this->table_name('wp_logs_meta')} m
            ON i.log_id = m.meta_log_id
            {$join_meta_cond}
        WHERE 1=1
        GROUP BY i.log_id, d.day_start
        ORDER BY d.day_start DESC, i.created DESC
        ;";

        $prepare_params = array(
            $offset_micro,
            $day_micros,
            $day_micros,
            $offset_micro,
            $site_id,
            $from_date_utc,
            $days_number,
            $day_micros,
            $site_id,
            $type,
        );

        $prepare_params = array_merge( $prepare_params, $extra_prepare );

        $query = $wpdb->prepare(
            $sql, //phpcs:ignore --ok.
            ...$prepare_params
        );

        $items = $wpdb->get_results( $query ); //phpcs:ignore --ok.

        if ( $items ) {
            $this->get_meta_items( $items );
        }

        if ( ! empty( $items ) && in_array( $type, array( 'plugin', 'theme' ) ) && ! empty( $slug_value ) ) {
            $items = array_filter(
                $items,
                function ( $item ) use ( $slug_value, $type, $name_value ) {
                    $item_slug = '';
                    $item_name = '';

                    if ( ! empty( $item->meta['slug'] ) ) {
                        $item_slug = $item->meta['slug'];
                    }

                    if ( ! empty( $item->meta['name'] ) ) {
                        $item_name = $item->meta['name'];
                    }

                    if ( empty( $item_slug ) && ! empty( $item->extra_info ) ) {
                        $extra_data = json_decode( $item->extra_info, true );
                        if ( is_array( $extra_data ) && ! empty( $extra_data['slug'] ) ) {
                            $item_slug = $extra_data['slug'];
                        }
                    }

                    if ( 'theme' === $type ) {
                        if ( ! empty( $slug_value ) && ! empty( $item_slug ) && $item_slug === $slug_value ) {
                            return true;
                        }
                        if ( ! empty( $name_value ) && ! empty( $item_name ) && $item_name === $name_value ) {
                            return true;
                        }
                        return false;
                    }

                    if ( empty( $item_slug ) ) {
                        return false;
                    }

                    if ( false !== strpos( $item_slug, '/' ) ) {
                        $parts           = explode( '/', $item_slug );
                        $normalized_item = $parts[0];
                    } else {
                        $normalized_item = basename( $item_slug, '.php' );
                    }

                    if ( false !== strpos( $slug_value, '/' ) ) {
                        $parts             = explode( '/', $slug_value );
                        $normalized_search = $parts[0];
                    } else {
                        $normalized_search = basename( $slug_value, '.php' );
                    }

                    return $normalized_item === $normalized_search;
                }
            );

            $items = array_values( $items );
        }

        $more_date = '';

        $first_created = 0;

        if ( ! empty( $items ) && ! empty( $found ) ) {
            $first_created = $found ? intval( $found->created / 1000000 ) : 0;
            $end           = end( $items );
            $end_created   = $end && ! empty( $end->created ) ? $end->created / 1000000 : 0;

            if ( $end_created && $first_created ) {
                $more_date = gmdate( 'Y-m-d', (int) $end_created );
                $more_date = gmdate( 'Y-m-d', (int) $first_created ) === $more_date ? '' : $more_date;
            }
        }

        if ( ! empty( $more_date ) ) {
            $more_date = gmdate( 'Y-m-d', strtotime( $more_date . ' -1 day' ) ); // previous day.
        }

        return array(
            'items'       => $items,
            'onward_time' => $first_created,
            'total'       => $total_count ? $total_count : 0,
            'more_date'   => $more_date,
        );
    }


    /**
     * Get meta data of logs.
     */
    public function get_meta_items( &$items ) { // phpcs:ignore -- NOSONAR - complex.

        global $wpdb;

        if ( ! is_array( $items ) || empty( $items ) ) {
            return;
        }

        $ids = array_map( 'absint', wp_list_pluck( $items, 'log_id' ) );

        $start_slice = 0;
        $max_slice   = 100;
        $count       = count( $ids );

        while ( $start_slice <= $count ) {
            $slice_ids    = array_slice( $ids, $start_slice, $max_slice );
            $start_slice += $max_slice;

            if ( ! empty( $slice_ids ) ) {

                $sql_meta = sprintf(
                    'SELECT * FROM ' . $this->table_name( 'wp_logs_meta' ) . ' WHERE meta_log_id IN ( %s )',
                    implode( ',', $slice_ids )
                );

                $meta_records = $wpdb->get_results( $sql_meta ); //phpcs:ignore -- ok.
                $ids_flip     = array_flip( $ids );

                if ( is_array( $meta_records ) ) {
                    foreach ( $meta_records as $meta_record ) {
                        if ( ! empty( $meta_record->meta_value ) ) {
                            if ( in_array( $meta_record->meta_key, array( 'user_meta_json', 'user_login', 'extra_info' ) ) ) {
                                $items[ $ids_flip[ $meta_record->meta_log_id ] ]->{$meta_record->meta_key} = $meta_record->meta_value;
                            } else {
                                if ( empty( $items[ $ids_flip[ $meta_record->meta_log_id ] ]->meta ) ) {
                                    $items[ $ids_flip[ $meta_record->meta_log_id ] ]->meta = array();
                                }
                                $items[ $ids_flip[ $meta_record->meta_log_id ] ]->meta[ $meta_record->meta_key ] = $meta_record->meta_value;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Get db size.
     *
     * @return string Return current db size.
     */
    public function count_legacy_dismissed() {
        return $this->wpdb->get_var( 'SELECT count(*) FROM ' . $this->table_name( 'wp_logs' ) . ' WHERE dismiss = 1 ' ); //phpcs:ignore -- ok.
    }

    /**
     * Get db size.
     *
     * @return string Return current db size.
     */
    public function get_db_size() {
        $cache_key   = 'db_logs_size';
        $cache_group = 'mainwp_module_log';

        $size = wp_cache_get( $cache_key, $cache_group );

        if ( false !== $size ) {
            return $size;
        }

        $size = get_transient( 'mainwp_module_log_transient_db_logs_size' );

        if ( false !== $size ) {
            wp_cache_set( $cache_key, $size, $cache_group, 15 * MINUTE_IN_SECONDS );
            return $size;
        }

        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT
            ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = %s
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

        $dbsize_mb = (float) $wpdb->get_var( $sql ); // phpcs:ignore

        set_transient( 'mainwp_module_log_transient_db_logs_size', $dbsize_mb, 15 * MINUTE_IN_SECONDS );
        wp_cache_set( $cache_key, $dbsize_mb, $cache_group, 15 * MINUTE_IN_SECONDS );

        return $dbsize_mb;
    }

    /**
     * Get child logs db synced size.
     *
     * @return string Return current db size.
     */
    public function get_child_logs_db_size() {

        $wp_table     = esc_sql( $this->table_name( 'wp' ) );
        $wp_opt_table = esc_sql( $this->table_name( 'wp_options' ) );

        return $this->wpdb->get_results(
            "
            SELECT wp.id,wp.url,wp.name, COALESCE(o.value, 0) AS dbsize_activitylogs
            FROM {$wp_table} wp
            LEFT JOIN {$wp_opt_table} o
            ON o.wpid = wp.id
            AND o.name = 'dbsize_activitylogs'
        "
        );
    }
}
