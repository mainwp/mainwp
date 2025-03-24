<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use MainWP\Dashboard\Module\Log\Log_Query;

/**
 * Class MainWP_DB_Site_Actions
 *
 * @package MainWP\Dashboard
 */
class MainWP_DB_Site_Actions extends MainWP_DB { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_DB_Site_Actions
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        parent::__construct();
        add_action( 'mainwp_delete_site', array( $this, 'hook_delete_site' ), 10, 3 );
    }

    /**
     * Method hook_db_install_tables()
     *
     * Get the query to install db tables.
     *
     * @param array  $sql input filter.
     * @param string $currentVersion Current db Version.
     * @param string $charset_collate charset collate.
     *
     * @return array $sql queries.
     */
    public function hook_db_install_tables( $sql, $currentVersion, $charset_collate ) {
        $tbl = 'CREATE TABLE ' . $this->table_name( 'wp_actions' ) . ' (
    action_id int(11) NOT NULL auto_increment,
    wpid int(11) NOT NULL,
    object_id varchar(20) NOT NULL,
    context varchar(20) NOT NULL,
    action varchar(100) NOT NULL,
    action_user text NOT NULL DEFAULT "",
    created int(11) NOT NULL DEFAULT 0,
    meta_data text NOT NULL DEFAULT "",
    dismiss tinyint(1) NOT NULL DEFAULT 0,
    summary varchar(255) NOT NULL default ""';
        if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.89', '<=' ) ) {
            $tbl .= ',
        PRIMARY KEY (action_id)';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        return $sql;
    }


    /**
     * Method hook_delete_site()
     *
     * Installs the new DB.
     *
     * @param mixed $site site object.
     *
     * @return bool result.
     */
    public function hook_delete_site( $site ) {
        if ( empty( $site ) ) {
            return false;
        }
        return $this->delete_action_by( 'wpid', $site->id );
    }

    /**
     * Method update_action_by_id.
     *
     * Create or update action.
     *
     * @param int   $action_id action id.
     * @param array $data action data.
     *
     * @return bool
     */
    public function update_action_by_id( $action_id, $data ) {
        if ( empty( $action_id ) ) {
            return false;
        }
        return $this->wpdb->update( $this->table_name( 'wp_actions' ), $data, array( 'action_id' => intval( $action_id ) ) );
    }

    /**
     * Method delete_action_by.
     *
     * Delete action by.
     *
     * @param string $by By what.
     * @param mixed  $value By value.
     * @return bool Results.
     */
    public function delete_action_by( $by = 'action_id', $value = null ) {
        if ( empty( $value ) ) {
            return false;
        }
        if ( 'action_id' === $by ) {
            if ( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_actions' ) . ' WHERE action_id=%d ', $value ) ) ) {
                return true;
            }
        } elseif ( 'wpid' === $by ) {
            if ( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_actions' ) . ' WHERE wpid=%d ', $value ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Method delete_all_actions.
     *
     * Deletes all actions.
     *
     * @return bool Results.
     */
    public function delete_all_actions() {
        return $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_actions' ) ) );
    }

    /**
     * Method get_wp_actions.
     *
     * Get wp actions.
     *
     * @param bool  $params params.
     * @param mixed $site Site data.
     *
     * @return mixed $result result.
     */
    public function get_wp_actions( $params = array(), $site = false ) { //phpcs:ignore -- NOSONAR - complex.
        return $this->get_none_mainwp_actions_log_for_rest_api( $params, $site );
    }

    /**
     * Method get_none_mainwp_actions_log_for_rest_api.
     *
     * Get wp actions.
     *
     * @param bool  $legacy_params params.
     * @param mixed $site Site data.
     *
     * @return mixed $result result.
     */
    public function get_none_mainwp_actions_log_for_rest_api( $legacy_params = array(), $site = false ) { //phpcs:ignore -- NOSONAR - complex.

        $action_id    = isset( $legacy_params['action_id'] ) ? intval( $legacy_params['action_id'] ) : 0;
        $site_id      = isset( $legacy_params['wpid'] ) ? $legacy_params['wpid'] : 0;
        $object_id    = isset( $legacy_params['object_id'] ) ? $this->escape( $legacy_params['object_id'] ) : '';
        $where_extra  = isset( $legacy_params['where_extra'] ) ? $legacy_params['where_extra'] : ''; // compatible.
        $dism         = ! empty( $legacy_params['dismiss'] ) ? 1 : 0;
        $check_access = isset( $legacy_params['check_access'] ) ? $legacy_params['check_access'] : true;

        $order_by    = isset( $legacy_params['order_by'] ) && ! empty( $legacy_params['order_by'] ) ? $legacy_params['order_by'] : 'created ';
        $offset      = ! empty( $legacy_params['offset'] ) ? (int) ( $legacy_params['offset'] ) : 0;
        $per_page    = ! empty( $legacy_params['rowcount'] ) ? (int) ( $legacy_params['rowcount'] ) : 0;
        $total_count = isset( $legacy_params['total_count'] ) && $legacy_params['total_count'] ? true : false;

        $limit = ! empty( $legacy_params['limit'] ) ? intval( $legacy_params['limit'] ) : false;

        $order = 'DESC';
        if ( isset( $legacy_params['order'] ) && 'ASC' === strtoupper( $legacy_params['order'] ) ) {
            $order = 'ASC';
        }

        $compatible_args = array(
            'start'            => $offset,
            'records_per_page' => $per_page,
            'order'            => $order,
            'orderby'          => $order_by,
            'count_only'       => $total_count ? true : false,
            'limit'            => $limit,
            'where_extra'      => $where_extra,
            'log_id'           => $action_id,
            'wpid'             => $site_id,
            'object_id'        => $object_id,
            'dismiss'          => $dism,
            'check_access'     => $check_access,
        );

        if ( ! empty( $legacy_params['source'] ) ) {
            $sources_conds = '';
            if ( 'wpadmin' === $legacy_params['source'] ) {
                $sources_conds = 'wp-admin-only';
            } elseif ( 'dashboard' === $legacy_params['source'] ) {
                $sources_conds = 'dashboard-only';
            } elseif ( 'all' === $legacy_params['source'] ) {
                $sources_conds = '';
            } else {
                $compatible_args['wpid'] = -1; // will return empty.
            }

            $compatible_args['sources_conds'] = $sources_conds;
        } else {
            $compatible_args['sources_conds'] = 'wp-admin-only';
        }

        $compatible_args['view'] = 'api-view';

        $query_log = new Log_Query();

        $results = $query_log->query( $compatible_args );

        if ( is_array( $results ) && isset( $results['items'] ) ) {
            $items = array();
            foreach ( $results['items'] as $item ) {
                $item->log_site_name = $site->name;
                $item->url           = $site->url;
                $items[]             = $item;
            }
            return $items;
        } elseif ( $total_count ) {
            if ( isset( $results['count'] ) ) {
                return $results['count'];
            } else {
                return 0;
            }
        }
        return array();
    }
}
