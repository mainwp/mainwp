<?php
/**
 * MainWP Database Controller
 *
 * This file handles all interactions with the DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_DB
 */
class MainWP_Auto_Updates_DB extends MainWP_DB { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, Generic.Metrics.CyclomaticComplexity -- This is the only way to achieve desired results, pull request solutions appreciated.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Create public static instance.
     *
     * @static
     *
     * @return MainWP_DB
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        static::$instance->test_connection();

        return static::$instance;
    }


    /**
     * Get child sites check updates.
     *
     * @param int  $limit Query limit.
     * @param int  $lasttime_start Lasttime start automatic update.
     * @param bool $connected Requires sites connected.
     * @param bool $not_suspended Requires sites unsuspended.
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_websites_check_updates( $limit, $lasttime_start, $connected = false, $not_suspended = false ) {
        $where = '';
        if ( true === $connected ) {
            $where .= ' wp_sync.sync_errors = "" AND';
        }
        if ( true === $not_suspended ) {
            $where .= ' wp.suspended = 0 AND';
        }
        $sql = 'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid JOIN ' . $this->get_option_view( array( 'favi_icon' ) ) . ' wp_optionview ON wp.id = wp_optionview.wpid WHERE ' . $where . ' ( wp_sync.dtsAutomaticSyncStart = 0 OR  wp_sync.dtsAutomaticSyncStart < ' . intval( $lasttime_start ) . ' ) ORDER BY wp_sync.dtsAutomaticSyncStart ASC LIMIT ' . $limit;
        return $this->wpdb->get_results( $sql, OBJECT );
    }


    /**
     * Get child sites to start updates.
     *
     * @param bool $connected Requires sites connected.
     * @param bool $not_suspended Requires sites unsuspended.
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_websites_to_start_updates( $connected = false, $not_suspended = false ) {

        $where = '';
        if ( true === $connected ) {
            $where .= ' wp_sync.sync_errors = "" AND';
        }
        if ( true === $not_suspended ) {
            $where .= ' wp.suspended = 0 AND';
        }

        $where  = rtrim( $where, 'AND' );
        $params = array(
            'view'  => 'updates_view',
            'where' => $where,
        );

        return MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params ) );
    }


    /**
     * Get child sites to continue updates.
     *
     * @param int  $limit limit sites to continue updates.
     * @param int  $lasttime_start Lasttime start automatic update.
     * @param bool $connected Requires sites connected.
     * @param bool $not_suspended Requires sites unsuspended.
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_websites_to_continue_updates( $limit, $lasttime_start, $connected = false, $not_suspended = false ) {

        if ( empty( $lasttime_start ) ) {
            return false;
        }

        $where = ' ( wp_sync.dtsAutomaticSyncStart > 0 AND wp_sync.dtsAutomaticSync < wp_sync.dtsAutomaticSyncStart AND wp_sync.dtsAutomaticSyncStart <= ' . intval( $lasttime_start ) . ' ) AND'; // less than to sure that exactly trigger start updates timestamp.
        if ( true === $connected ) {
            $where .= ' wp_sync.sync_errors = "" AND';
        }
        if ( true === $not_suspended ) {
            $where .= ' wp.suspended = 0 AND';
        }

        $where = rtrim( $where, 'AND' );

        $params = array(
            'view'    => 'updates_view',
            'where'   => $where,
            'limit'   => $limit,
            'orderby' => ' wp_sync.dtsAutomaticSync ASC ', // to fix.
        );

        $sql = MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params );

        $results = MainWP_DB::instance()->query( $sql );

        $websites = array();
        while ( $results && $website = static::fetch_object( $results ) ) {
            $websites[] = $website;
        }

        if ( $results ) {
            static::free_result( $results );
        }

        return $websites;
    }


    /**
     * Get child sites to start updates.
     *
     * @param int $limit limit sites to continue updates.
     * @param int $lasttime_start Lasttime start automatic update.
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_websites_to_continue_individual_updates( $limit = 4, $lasttime_start = false ) {

        if ( empty( $lasttime_start ) ) {
            return false;
        }

        $where  = ' wp_sync.sync_errors = "" AND ';
        $where .= ' wp.suspended = 0 AND ';
        $where .= '  wp_optionview.batch_individual_queue_time >= ' . intval( $lasttime_start ) . ' ';

        $params = array(
            'view'          => 'updates_view',
            'where'         => $where,
            'limit'         => $limit,
            'others_fields' => array( 'batch_individual_queue_time' ),
        );

        $results = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params ) );

        $websites = array();
        while ( $results && $website = static::fetch_object( $results ) ) {
            $websites[] = $website;
        }

        if ( $results ) {
            static::free_result( $results );
        }

        return $websites;
    }


    /**
     * Get websites check updates count.
     *
     * @param int $lasttime_start Lasttime start automatic update.
     *
     * @return int Child sites update count.
     */
    public function get_websites_check_updates_count( $lasttime_start ) {
        $where = $this->get_sql_where_allow_access_sites( 'wp' );

        return $this->wpdb->get_var( 'SELECT count(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE ( wp_sync.dtsAutomaticSyncStart = 0 OR wp_sync.dtsAutomaticSyncStart < ' . intval( $lasttime_start ) . ')' . $where );
    }

    /**
     * Get child site count where date & time Session sync is smaller then start.
     *
     * @param int $lasttime_start Last time start automatic.
     *
     * @return int Returned child site count.
     */
    public function get_websites_count_where_dts_automatic_sync_smaller_then_start( $lasttime_start ) {
        return $this->wpdb->get_var( 'SELECT count(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE wp.suspended = 0 AND ( ( wp_sync.dtsAutomaticSync < wp_sync.dtsAutomaticSyncStart AND wp_sync.dtsAutomaticSyncStart > ' . intval( $lasttime_start ) . ') OR (wp_sync.dtsAutomaticSyncStart = 0) ) ' );
    }

    /**
     * Get child site last automatic sync date & time.
     *
     * @return string Date and time of last automatic sync.
     */
    public function get_websites_last_automatic_sync() {
        return $this->wpdb->get_var( 'SELECT MAX(wp_sync.dtsAutomaticSync) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid' );
    }
}
