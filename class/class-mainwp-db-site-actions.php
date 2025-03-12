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
     * Method update_action.
     *
     * Create or update action.
     *
     * @param int $action_id action id.
     *
     * @return bool
     */
    public function get_wp_action_by_id( $action_id ) {
        if ( empty( $action_id ) ) {
            return false;
        }
        return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_actions' ) . ' WHERE action_id = %d ', $action_id ) );
    }


    /**
     * Method get_non_mainwp_action_by_id.
     *
     * @param int $log_id action id.
     *
     * @return bool
     */
    public function get_non_mainwp_action_by_id( $log_id ) {
        if ( empty( $log_id ) ) {
            return false;
        }
        return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_logs' ) . ' WHERE log_id = %d ', $log_id ) ); //phpcs:ignore -- ok.
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
     * @param mixed $obj Format data.
     *
     * @return mixed $result result.
     */
    public function get_wp_actions( $params = array(), $obj = OBJECT) { //phpcs:ignore -- NOSONAR - complex.
        return $this->get_none_mainwp_actions_log( $params, $obj );
    }

    /**
     * Method get_none_mainwp_actions_log.
     *
     * Get wp actions.
     *
     * @param bool  $legacy_params params.
     * @param mixed $obj Format data.
     *
     * @return mixed $result result.
     */
    public function get_none_mainwp_actions_log( $legacy_params = array(), $obj = OBJECT ) { //phpcs:ignore -- NOSONAR - complex.

        $action_id    = isset( $legacy_params['action_id'] ) ? intval( $legacy_params['action_id'] ) : 0;
        $site_id      = isset( $legacy_params['wpid'] ) ? $legacy_params['wpid'] : 0;
        $object_id    = isset( $legacy_params['object_id'] ) ? $this->escape( $legacy_params['object_id'] ) : '';
        $where_extra  = isset( $legacy_params['where_extra'] ) ? $legacy_params['where_extra'] : ''; // compatible.
        $dism         = ! empty( $legacy_params['dismiss'] ) ? 1 : 0;
        $check_access = isset( $legacy_params['check_access'] ) ? $legacy_params['check_access'] : true;
        $search_str   = isset( $legacy_params['search'] ) ? $this->escape( trim( $legacy_params['search'] ) ) : null;

        $order_by    = isset( $legacy_params['order_by'] ) && ! empty( $legacy_params['order_by'] ) ? $legacy_params['order_by'] : 'created ';
        $offset      = isset( $legacy_params['offset'] ) ? intval( $legacy_params['offset'] ) : 0;
        $rowcount    = isset( $legacy_params['rowcount'] ) ? intval( $legacy_params['rowcount'] ) : false;
        $total_count = isset( $legacy_params['total_count'] ) && $legacy_params['total_count'] ? true : false;

        $limit = isset( $legacy_params['limit'] ) ? intval( $legacy_params['limit'] ) : false;

        $order = 'DESC';
        if ( isset( $legacy_params['order'] ) && 'ASC' === strtoupper( $legacy_params['order'] ) ) {
            $order = 'ASC';
        }

        $per_page = false !== $rowcount ? absint( $rowcount ) : 9999999999;

        $compatible_args = array(
            'search'           => $search_str,
            'start'            => $offset,
            'records_per_page' => $per_page,
            'order'            => $order,
            'orderby'          => $order_by,
            'count_only'       => $total_count ? true : false,
            'recent_number'    => $limit,
            'where_extra'      => $where_extra,
            'log_id'           => $action_id,
            'wpid'             => $site_id,
            'object_id'        => $object_id,
            'dismiss'          => $dism,
            'check_access'     => $check_access,
            'nonemainwp'       => true,
        );

        $query_log = new Log_Query();

        $results = $query_log->query( $compatible_args );
        if ( is_array( $results ) && isset( $results['items'] ) ) {
            return $results['items'];
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
