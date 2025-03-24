<?php
/**
 * Queries the database for logs records.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class - Log_Query
 */
class Log_Query {
    /**
     * Hold the number of records found
     *
     * @var int
     */
    public $found_records = 0;

    /**
     * Query records
     *
     * @param array $args Arguments to filter the records by.
     *
     * @return array Logs Records
     */
    public function query( $args ) { //phpcs:ignore -- NOSONAR - complex method.
        global $wpdb;

        // To support none mainwp actions.
        $log_id       = isset( $args['log_id'] ) ? intval( $args['log_id'] ) : 0;
        $site_id      = isset( $args['wpid'] ) ? $args['wpid'] : 0; // int or array of int site ids.
        $object_id    = isset( $args['object_id'] ) ? sanitize_text_field( $args['object_id'] ) : '';
        $where_extra  = ''; // compatible.
        $check_access = isset( $args['check_access'] ) ? $args['check_access'] : true;
        $view         = isset( $args['view'] ) ? sanitize_text_field( $args['view'] ) : '';

        $join  = '';
        $where = '';

        $count_only = ! empty( $args['count_only'] ) ? true : false;

        if ( ! empty( $args['search'] ) ) {
            $search_str = MainWP_DB::instance()->escape( $args['search'] );
            // for searching.
            if ( ! empty( $search_str ) ) {
                $search_str = trim( $search_str );
                // prepare search value for searching.
                if ( ! empty( $search_str ) ) {
                    $where_search  = ' AND (  wp.name LIKE  "%' . $search_str . '%" OR lg.action LIKE  "%' . $search_str . '%" OR sub_lg.action_display LIKE  "%' . $search_str . '%" OR lg.log_id LIKE  "%' . $search_str . '%" OR lg.user_id LIKE "%' . $search_str . '%" ';
                    $where_search .= ' OR lg.item LIKE  "%' . $search_str . '%" ';
                    if ( 'events_list' === $view ) {
                        $where_search .= ' OR sub_lg.source LIKE  "%' . $search_str . '%" ';
                    }
                    $where_search .= ') ';
                    $where        .= $where_search;
                }
            }
        }

        if ( isset( $args['dismiss'] ) ) {
            $where .= ' AND lg.dismiss = ' . ( ! empty( $args['dismiss'] ) ? 1 : 0 ) . ' ';
        }

        if ( ! empty( $args['groups_ids'] ) && is_array( $args['groups_ids'] ) ) {
            $array_groups_ids = MainWP_Utility::array_numeric_filter( $args['groups_ids'] );
            if ( ! empty( $array_groups_ids ) ) {
                $groups_sites      = MainWP_DB_Client::instance()->get_websites_by_group_ids( $array_groups_ids );
                $array_website_ids = array();
                if ( $groups_sites ) {
                    foreach ( $groups_sites as $website ) {
                        $array_website_ids[] = $website->id;
                    }
                }
                unset( $groups_sites );
                if ( ! empty( $array_website_ids ) ) {
                    $where .= " AND lg.site_id IN ('" . implode( "','", $array_website_ids ) . "') ";
                } else {
                    $where .= ' AND false ';
                }
            }
        }

        if ( ! empty( $args['client_ids'] ) && is_array( $args['client_ids'] ) ) {
            $array_clients_ids = MainWP_Utility::array_numeric_filter( $args['client_ids'] );
            if ( ! empty( $array_clients_ids ) ) {
                $client_sites      = MainWP_DB_Client::instance()->get_websites_by_client_ids( $array_clients_ids );
                $array_website_ids = array();
                if ( $client_sites ) {
                    foreach ( $client_sites as $website ) {
                        $array_website_ids[] = $website->id;
                    }
                }
                unset( $client_sites );
                if ( ! empty( $array_website_ids ) ) {
                    $where .= " AND lg.site_id IN ('" . implode("','",$array_website_ids) . "') "; // phpcs:ignore -- ok.
                } else {
                    $where .= ' AND false ';
                }
            }
        }

        if ( ! empty( $args['user_ids'] ) && is_array( $args['user_ids'] ) ) {
            $array_users_ids = MainWP_Utility::array_numeric_filter( $args['user_ids'] );
            if ( ! empty( $array_users_ids ) ) {
                $where .= " AND lg.user_id IN ('" . implode("','",$array_users_ids) . "') "; // phpcs:ignore -- ok.
            }
        }

        if ( ! empty( $args['timestart'] ) && ! empty( $args['timestop'] ) ) {
            $where .= $wpdb->prepare( ' AND `lg`.`created` >= %d AND `lg`.`created` <= %d', $args['timestart'], $args['timestop'] );
        }

        if ( ! empty( $args['sources_conds'] ) ) {
            if ( 'wp-admin-only' === $args['sources_conds'] ) {
                $where .= ' AND `lg`.`connector` = "non-mainwp-changes" ';
            } elseif ( 'dashboard-only' === $args['sources_conds'] ) {
                $where .= ' AND `lg`.`connector` != "non-mainwp-changes" ';
            }
        }

        if ( ! empty( $args['sites_ids'] ) ) {
            $where_site_ids = implode( ',', array_filter( array_map( 'intval', (array) $args['sites_ids'] ) ) );
            if ( ! empty( $where_site_ids ) ) {
                $where .= ' AND lg.site_id IN ( ' . $where_site_ids . ' ) ';
            }
        }

        if ( ! empty( $args['events'] ) ) {
            $events_list = array_map(
                function ( $value ) {
                    return MainWP_DB::instance()->escape( $value );
                },
                (array) $args['events']
            );
            $events_list = array_filter( $events_list );
            if ( ! empty( $events_list ) ) {
                $where .= ' AND lg.action IN ( "' . implode( '","', $events_list ) . '" ) ';
            }
        }

        /**
         * PARSE PAGINATION PARAMS
         */
        $limits   = '';
        $start    = absint( $args['start'] );
        $per_page = absint( $args['records_per_page'] );

        if ( $per_page > 0 ) {
            $limits = "LIMIT {$start}, {$per_page}";
        }

        // Show the recent records first by default.
        $order = 'DESC';
        if ( 'ASC' === strtoupper( $args['order'] ) ) {
            $order = 'ASC';
        }

        /**
         * PARSE ORDER PARAMS
         */
        $orderable = array( 'site_id', 'name', 'url', 'user_id', 'item', 'created', 'connector', 'context', 'action', 'event', 'duration', 'state' );

        // Default to sorting by.
        $orderby = 'lg.created';

        if ( in_array( $args['orderby'], $orderable, true ) ) {
            if ( in_array( $args['orderby'], array( 'name', 'url' ) ) ) {
                $orderby = sprintf( '%s.%s', 'meta_view', $args['orderby'] );
            } elseif ( 'event' === $args['orderby'] ) {
                $orderby = sprintf( '%s.%s', 'lg', 'action' );
            } else {
                $orderby = sprintf( '%s.%s', 'lg', $args['orderby'] );
            }
        }

        if ( 'source' === $args['orderby'] ) {
            $orderby = " ORDER BY
            CASE
            WHEN connector = 'non-mainwp-changes' THEN 2
            ELSE 1
            END " . $order;
        } elseif ( 'log_object' === $args['orderby'] ) {
            $orderby = sprintf( 'ORDER BY %s %s', $orderby, $order );
        } else {
            $orderby = sprintf( 'ORDER BY %s %s', $orderby, $order );
        }

        $where_actions = '';

        if ( ! empty( $log_id ) ) {
            $where_actions .= ' AND lg.log_id = ' . $log_id;
        } else {
            $sql_and = '';
            if ( ! empty( $site_id ) ) {
                if ( is_array( $site_id ) ) {
                    $site_ids = array_map( 'intval', $site_id );
                    $site_ids = array_filter( $site_ids );
                    if ( ! empty( $site_ids ) ) {
                        $site_ids       = implode( ',', $site_ids );
                        $sql_and        = ' AND ';
                        $where_actions .= $sql_and . ' lg.site_id IN ( ' . $site_ids . ' )';
                    }
                } elseif ( is_numeric( $site_id ) ) {
                    $sql_and        = ' AND ';
                    $where_actions .= $sql_and . ' lg.site_id = ' . intval( $site_id );
                }
            }
            if ( ! empty( $object_id ) ) {
                if ( empty( $sql_and ) ) {
                    $sql_and = ' AND ';
                }
                $where_actions .= $sql_and . ' lg.object_id = "' . $object_id . '" ';
            }
        }

        if ( $check_access && 'api-view' !== $view ) {
            $where_actions .= MainWP_DB::instance()->get_sql_where_allow_access_sites( 'wp' );
        }

        $where_dismiss = ! empty( $args['dismiss'] ) ? ' AND dismiss = 1 ' : ' AND dismiss = 0 ';

        $where .= $where_actions . $where_extra . $where_dismiss;

        /**
         * PARSE FIELDS PARAMETER
         */
        $selects   = array();
        $selects[] = 'lg.*';

        if ( 'api-view' !== $view ) {
            $selects[] = 'wp.url as url';
            $selects[] = 'wp.name as log_site_name';
        }

        $selects[] = 'meta_view.*';

        $select = implode( ', ', $selects );

        if ( 'api-view' !== $view ) {
            $join = ' LEFT JOIN ' . $wpdb->mainwp_tbl_wp . ' wp ON lg.site_id = wp.id ';
        }

        $join .= ' LEFT JOIN ' . $this->get_log_meta_view() . ' meta_view ON lg.log_id = meta_view.view_log_id ';

        if ( 'events_list' === $view ) {
            $join .= ' LEFT JOIN ' . $this->get_sub_query_view() . ' sub_lg ON lg.log_id = sub_lg.sub_log_id ';
        }

        $recent_where = '';
        $recent_query = '';
        // list recent events.
        if ( ! empty( $args['recent_number'] ) ) {
            $recent_limits = ' LIMIT ' . intval( $args['recent_number'] );

            $recent_query = "SELECT MAX( lg.created )
            FROM $wpdb->mainwp_tbl_logs as lg
            {$join}
            WHERE `lg`.`connector` != 'compact' AND lg.dismiss = 0 ORDER BY lg.created DESC {$recent_limits}";

            $recent_created = $wpdb->get_var( $recent_query ); //phpcs:ignore -- NOSONAR - ok.

            $recent_where = ' AND lg.created <= ' . (int) $recent_created;
        }

        /**
         * BUILD THE FINAL QUERY
         */
        $query = "SELECT {$select}
        FROM $wpdb->mainwp_tbl_logs as lg
        {$join}
        WHERE `lg`.`connector` != 'compact' {$where} {$recent_where}
        {$orderby}
        {$limits}";

        // Build result count query.
        $count_query = "SELECT COUNT(*)
        FROM $wpdb->mainwp_tbl_logs as lg
        {$join}
        WHERE `lg`.`connector` != 'compact' {$where} {$recent_where}";

        if ( $count_only ) {
            return array(
                'count' => absint( $wpdb->get_var( $count_query ) ),  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );
        }

        if ( ! empty( $args['dev_log_query'] ) ) {
            //phpcs:disable Squiz.PHP.CommentedOutCode.Found,WordPress.PHP.DevelopmentFunctions
            error_log( print_r( $args, true ) );
            error_log( $recent_query );
            error_log( $query );
            error_log( $count_query );
            //phpcs:enable Squiz.PHP.CommentedOutCode.Found,WordPress.PHP.DevelopmentFunctions
        }

        /**
         * QUERY THE DATABASE FOR RESULTS
         */
        return array(
            'items' => $wpdb->get_results( $query ), // phpcs:ignore -- ok.
            'count' => absint( $wpdb->get_var( $count_query ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    /**
     * Get logs meta database table view.
     *
     * @return string logs meta view.
     */
    public function get_log_meta_view() {
        global $wpdb;
        $view  = '(SELECT intlog.log_id AS view_log_id, ';
        $view .= '(SELECT meta_name.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' meta_name WHERE  meta_name.meta_log_id = intlog.log_id AND meta_name.meta_key = "name" LIMIT 1) AS meta_name, ';
        $view .= '(SELECT user_meta_json.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' user_meta_json WHERE  user_meta_json.meta_log_id = intlog.log_id AND user_meta_json.meta_key = "user_meta_json" LIMIT 1) AS user_meta_json, ';
        $view .= '(SELECT usermeta.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' usermeta WHERE  usermeta.meta_log_id = intlog.log_id AND usermeta.meta_key = "user_meta" LIMIT 1) AS usermeta, '; // compatible user_meta data.
        $view .= '(SELECT extra_info.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' extra_info WHERE  extra_info.meta_log_id = intlog.log_id AND extra_info.meta_key = "extra_info" LIMIT 1) AS extra_info ';
        $view .= ' FROM ' . $wpdb->mainwp_tbl_logs . ' intlog)';
        return $view;
    }

    /**
     * Method get_sub_query to support seaching in events table.
     *
     * @return string sub query view.
     */
    public function get_sub_query_view() {
        global $wpdb;
        $view  = ' (SELECT sub_tbl.log_id AS sub_log_id, ';
        $view .= ' CASE WHEN sub_tbl.connector = "non-mainwp-changes" THEN "WP Admin" ';
        $view .= ' ELSE "Dashboard" ';
        $view .= ' END AS source, ';
        // to support searching on events column.
        $view .= " CASE
            WHEN sub_tbl.action = 'sync' THEN '" . MainWP_DB::instance()->escape( esc_html__( 'Sync Data', 'mainwp' ) ) . "'
            WHEN sub_tbl.action = 'activate' THEN '" . MainWP_DB::instance()->escape( esc_html__( 'Activated', 'mainwp' ) ) . "'
            WHEN sub_tbl.action = 'deactivate' THEN '" . MainWP_DB::instance()->escape( esc_html__( 'Deactivated', 'mainwp' ) ) . "'
            WHEN sub_tbl.action = 'install' THEN '" . MainWP_DB::instance()->escape( esc_html__( 'Installed', 'mainwp' ) ) . "'
            WHEN sub_tbl.action = 'updated' THEN '" . MainWP_DB::instance()->escape( esc_html__( 'Updated', 'mainwp' ) ) . "'
            WHEN sub_tbl.action = 'delete' THEN '" . MainWP_DB::instance()->escape( esc_html__( 'Deleted', 'mainwp' ) ) . "'
            WHEN sub_tbl.action = 'suspend' THEN '" . MainWP_DB::instance()->escape( esc_html__( 'Suspended', 'mainwp' ) ) . "'
            ELSE sub_tbl.action
        END AS action_display ";
        $view .= ' FROM ' . $wpdb->mainwp_tbl_logs . ' sub_tbl) ';
        return $view;
    }
}
