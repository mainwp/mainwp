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
        global $wpdb;
        
        $cache_identifier = array(
            'limit'         => absint( $limit ),
            'lasttime_start' => absint( $lasttime_start ),
            'connected'     => (bool) $connected,
            'not_suspended' => (bool) $not_suspended,
        );
        $cache_key = 'mainwp_auto_updates_check_' . md5( wp_json_encode( $cache_identifier ) ); // NOSONAR - MD5 for cache key only.
        $cached    = wp_cache_get( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
        
        $where = '';
        if ( true === $connected ) {
            $where .= ' wp_sync.sync_errors = "" AND';
        }
        if ( true === $not_suspended ) {
            $where .= ' wp.suspended = 0 AND';
        }
        
        $sql = $wpdb->prepare(
            'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid JOIN ' . $this->get_option_view( array( 'favi_icon' ) ) . ' wp_optionview ON wp.id = wp_optionview.wpid WHERE ' . $where . ' ( wp_sync.dtsAutomaticSyncStart = 0 OR  wp_sync.dtsAutomaticSyncStart < %d ) ORDER BY wp_sync.dtsAutomaticSyncStart ASC LIMIT %d',
            $lasttime_start,
            $limit
        );
        
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $sql is prepared by $wpdb->prepare() with parameterized values; direct query required for complex joins.
        $result = $wpdb->get_results( $sql, OBJECT );
        wp_cache_set( $cache_key, $result, '', HOUR_IN_SECONDS );
        
        return $result;
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
        global $wpdb;
        
        $cache_identifier = array( 'lasttime_start' => absint( $lasttime_start ) );
        $cache_key        = 'mainwp_auto_updates_count_' . md5( wp_json_encode( $cache_identifier ) ); // NOSONAR - MD5 for cache key only.
        $cached           = wp_cache_get( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
        
        $where = $this->get_sql_where_allow_access_sites( 'wp' );

        $sql = $wpdb->prepare(
            'SELECT count(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE ( wp_sync.dtsAutomaticSyncStart = 0 OR wp_sync.dtsAutomaticSyncStart < %d)' . $where,
            $lasttime_start
        );
        
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $sql is prepared by $wpdb->prepare() with parameterized values; direct query required for complex joins.
        $result = $wpdb->get_var( $sql );
        wp_cache_set( $cache_key, $result, '', HOUR_IN_SECONDS );
        
        return $result;
    }

    /**
     * Get child site count where date & time Session sync is smaller then start.
     *
     * @param int $lasttime_start Last time start automatic.
     *
     * @return int Returned child site count.
     */
    public function get_websites_count_where_dts_automatic_sync_smaller_then_start( $lasttime_start ) {
        global $wpdb;
        
        $cache_identifier = array( 'lasttime_start' => absint( $lasttime_start ) );
        $cache_key        = 'mainwp_auto_updates_sync_smaller_' . md5( wp_json_encode( $cache_identifier ) ); // NOSONAR - MD5 for cache key only.
        $cached           = wp_cache_get( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
        
        $sql = $wpdb->prepare(
            'SELECT count(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE wp.suspended = 0 AND ( ( wp_sync.dtsAutomaticSync < wp_sync.dtsAutomaticSyncStart AND wp_sync.dtsAutomaticSyncStart > %d) OR (wp_sync.dtsAutomaticSyncStart = 0) ) ',
            $lasttime_start
        );
        
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $sql is prepared by $wpdb->prepare() with parameterized values; direct query required for complex joins.
        $result = $wpdb->get_var( $sql );
        wp_cache_set( $cache_key, $result, '', HOUR_IN_SECONDS );
        
        return $result;
    }

    /**
     * Get child site last automatic sync date & time.
     *
     * @return string Date and time of last automatic sync.
     */
    public function get_websites_last_automatic_sync() {
        global $wpdb;
        
        $cache_key = 'mainwp_auto_updates_last_sync';
        $cached    = wp_cache_get( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
        
        $sql = 'SELECT MAX(wp_sync.dtsAutomaticSync) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid';
        
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table names come from safe $this->table_name() methods only, no user input in this query; direct query required for complex joins.
        $result = $wpdb->get_var( $sql );
        wp_cache_set( $cache_key, $result, '', HOUR_IN_SECONDS );
        
        return $result;
    }
}
