<?php
/**
 * MainWP Database Monitors
 *
 * This file handles all interactions with the Monitors DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB_Uptime_Monitoring
 *
 * @package MainWP\Dashboard
 */
class MainWP_DB_Uptime_Monitoring extends MainWP_DB { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * @return MainWP_DB_Common
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method get_db_schema()
     *
     * @param  array  $sql
     * @param  string $currentVersion
     * @return void
     */
    public function get_db_schema( &$sql,  $currentVersion ) { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $charset_collate = $this->wpdb->get_charset_collate();

        $tbl = 'CREATE TABLE ' . $this->table_name( 'monitors' ) . " (
monitor_id int(11) NOT NULL auto_increment,
wpid int(11) NOT NULL,
`active` tinyint(1) DEFAULT -1,
`type` varchar(20) NOT NULL DEFAULT '',
`keyword` varchar(255) NOT NULL DEFAULT '',
`suburl` varchar(255) NOT NULL DEFAULT '',
`issub` tinyint(1) DEFAULT 0,
`timeout` smallint NOT NULL DEFAULT -1,
`interval` int(11) NOT NULL DEFAULT -1,
`retry_interval` int(11) NOT NULL DEFAULT 1,
`up_status_codes` text NOT NULL DEFAULT '',
`last_status` tinyint(1) DEFAULT 99,
 `lasttime_check` int(11) NOT NULL,
`retries` tinyint(1) DEFAULT 0,
`maxretries` tinyint(1) DEFAULT -1,
`maxredirects` tinyint(1) DEFAULT 2,
`method` varchar(20) NOT NULL DEFAULT '',
`dts_interval_lasttime` int(11) NOT NULL DEFAULT 0,
`dts_auto_monitoring_time` int(11) NOT NULL DEFAULT 0,
`dts_auto_monitoring_start` int(11) NOT NULL DEFAULT 0,
`dts_auto_monitoring_retry_time` int(11) NOT NULL DEFAULT 0,
KEY idx_wpid (wpid)";
        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.0.41', '<' ) ) {
            $tbl .= ',
    PRIMARY KEY (monitor_id) ';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->table_name( 'monitor_heartbeat' ) . ' (
    heartbeat_id int(11) NOT NULL auto_increment,
    monitor_id int(11) NOT NULL,
    `msg` text NOT NULL,
    `importance` tinyint(1) NOT NULL DEFAULT 0,
    `status` smallint NOT NULL DEFAULT 3,
    `time` DATETIME NOT NULL,
    `ping_ms` int DEFAULT 0,
    `duration` int DEFAULT 0,
    `down_count` tinyint(1) NOT NULL DEFAULT 0,
    `http_code` smallint NOT NULL DEFAULT 0,
    KEY idx_monitor_id (monitor_id),
    KEY idx_monitor_time (`time`)';

        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.0.41', '<' ) ) {
            $tbl .= ',
        PRIMARY KEY (heartbeat_id) ';
        }
        $tbl .= ') ' . $charset_collate;

        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->table_name( 'monitor_stat_hourly' ) . ' (
    stat_hourly_id int(11) NOT NULL auto_increment,
    monitor_id int(11) NOT NULL,
    `up` int DEFAULT 0,
    `down` int DEFAULT 0,
    `ping_avg` int DEFAULT 0,
    `ping_min` int DEFAULT 0,
    `ping_max` int DEFAULT 0,
    `timestamp` int DEFAULT 0,
    KEY idx_monitor_id (monitor_id),
    KEY idx_hourly_timestamp (`timestamp`)';

        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.0.42', '<' ) ) {
            $tbl .= ',
        PRIMARY KEY (stat_hourly_id) ';
        }
        $tbl .= ') ' . $charset_collate;

        $sql[] = $tbl;

        add_action( 'mainwp_db_after_update', array( $this, 'update_db_data' ), 10, 2 );
    }

    /**
     * update_db_data action.
     *
     * @param string $current_version.
     *
     * @return object|null
     */
    public function update_db_data( $current_version ) {
        $suppress = $this->wpdb->suppress_errors();
        $this->update_db_90041( $current_version );
        $this->update_db_90043( $current_version );
        $this->wpdb->suppress_errors( $suppress );
    }

    /**
     * update_db_data action.
     *
     * @param string $current_version.
     *
     * @return object|null
     */
    public function update_db_90041( $current_version ) {

        $update_ver = '9.0.0.41';

        if ( ! empty( $current_version ) && version_compare( $current_version, $update_ver, '<' ) ) {
            // To compatible with basic monitoring settings.
            $disableSitesMonitoring = (int) get_option( 'mainwp_disableSitesChecking' );
            $frequencySitesChecking = (int) get_option( 'mainwp_frequencySitesChecking', 60 );
            $ignored_codes          = get_option( 'mainwp_ignore_HTTP_response_status' );

            $interval_values = MainWP_Uptime_Monitoring_Edit::get_interval_values( false );
            if ( ! isset( $interval_values[ $frequencySitesChecking ] ) ) {
                $frequencySitesChecking = 60;
            }
            $global_settings             = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
            $global_settings['active']   = $disableSitesMonitoring ? 0 : 1;
            $global_settings['interval'] = $frequencySitesChecking;

            if ( ! empty( $ignored_codes ) ) {
                $global_settings['up_status_codes'] = $ignored_codes;
            }

            $legacy_enabled_monitors = $this->wpdb->get_results( $this->get_legacy_sql_websites_enabled_check_status( true ) );

            if ( $legacy_enabled_monitors ) {
                foreach ( $legacy_enabled_monitors as $mo ) {
                    $this->update_wp_monitor(
                        array(
                            'wpid'            => $mo->id,
                            'active'          => $disableSitesMonitoring ? 1 : -1, // if global setting is disabled, it will be 1 - individual active, else it will be -1 use global setting - active.
                            'interval'        => $mo->status_check_interval > 0 ? $mo->status_check_interval : -1, // 0 then -1, use global setting.
                            'timeout'         => -1,
                            'method'          => 'useglobal',
                            'type'            => 'useglobal',
                            'up_status_codes' => 'useglobal',
                        )
                    ); // use global setting.
                }
            }

            $legacy_disabled_monitors = $this->wpdb->get_results( $this->get_legacy_sql_websites_enabled_check_status( false ) );

            if ( $legacy_disabled_monitors ) {
                if ( ! $disableSitesMonitoring ) {
                    $this->update_db_legacy_first_enable_monitoring_create_monitors( $legacy_disabled_monitors, 0 );
                } else {
                    $global_settings['first_enable_update'] = 1;
                }
            }

            MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings( $global_settings );

            delete_option( 'mainwp_frequencySitesChecking' );
            delete_option( 'mainwp_disableSitesChecking' );
            delete_option( 'mainwp_ignore_HTTP_response_status' );

            $delColumns = array( 'status_check_interval' );

            foreach ( $delColumns as $column ) {
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN ' . $column );
            }
        }
    }

    /**
     * update_db_data action.
     *
     * @param string $current_version.
     *
     * @return object|null
     */
    public function update_db_90043( $current_version ) {
        $update_ver  = '9.0.0.43';
        $update_ver2 = '9.0.0.41';
        if ( ! empty( $current_version ) && version_compare( $current_version, $update_ver, '<' ) && version_compare( $current_version, $update_ver2, '>=' ) ) {
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' CHANGE up_statuscodes_json up_status_codes text NOT NULL DEFAULT ""' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * update_db_legacy_first_enable_monitoring_create_monitors
     *
     * @param  mixed $disabled_monitors
     * @param  mixed $active
     * @return void
     */
    public function update_db_legacy_first_enable_monitoring_create_monitors( $disabled_monitors = null, $active = null ) {

        if ( null === $disabled_monitors ) {
            $disabled_monitors = $this->wpdb->get_results( $this->get_legacy_sql_websites_enabled_check_status( false ) );
        }

        if ( is_array( $disabled_monitors ) ) {
            if ( null === $active ) {
                $act = - 1; // create and enable monitors -  -1: use global active setting.
            } else {
                $act = $active ? -1 : 0; // create and set active/deactive monitors - -1: use global active setting, 0: individual disable.
            }

            foreach ( $disabled_monitors as $mo ) {
                $this->update_wp_monitor(
                    array(
                        'wpid'            => $mo->id,
                        'active'          => $act,
                        'interval'        => $mo->status_check_interval > 0 ? $mo->status_check_interval : -1, // 0 then -1, use global setting.
                        'timeout'         => -1,
                        'method'          => 'useglobal',
                        'type'            => 'useglobal',
                        'up_status_codes' => 'useglobal',
                        'issub'           => 0,
                    )
                );
            }
        }
    }


    /**
     * Get legacy sql websites by check status.
     *
     * @param  bool $enabled enabled or not.
     * @return string SQL string.
     */
    public function get_legacy_sql_websites_enabled_check_status( $enabled ) {
        return 'SELECT wp.id
        FROM ' . $this->table_name( 'wp' ) . ' wp
        WHERE wp.disable_status_check = ' . ( $enabled ? 0 : 1 ); // 0 - enabled, 1 - not enabled.
    }


    /**
     * get_monitor_by
     *
     * @param  int|false $site_id
     * @param  string    $by
     * @param  mixed     $value
     * @param  array     $params
     * @param  int       $obj OBJECT|ARRAY_A
     * @return object|null
     */
    public function get_monitor_by( $site_id, $by, $value, $params = array(), $obj = OBJECT ) {

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        if ( 'monitor_id' !== $by && empty( $site_id ) ) { // get by monitor_id, site id may be empty.
            return false;
        }

        if ( ! empty( $site_id ) ) {
            $params['wpid'] = $site_id;
        }

        if ( in_array( $by, array( 'suburl', 'monitor_id', 'issub' ), true ) ) {
            $params[ $by ] = $value;
        } else {
            return false;
        }

        if ( empty( $params['view'] ) ) {
            $params['view'] = 'monitor_view';
        }

        return $this->get_row_result( $this->get_sql_monitor( $params ), $obj );
    }


    /**
     * Get moniyor's sub page.
     *
     * @param  array $params
     * @return mixed result
     */
    public function get_monitor_sub_pages( $params ) {

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $params['issub'] = 1;

        if ( empty( $params['view'] ) ) {
            $params['view'] = 'monitor_view';
        }

        $sql = $this->get_sql_monitor( $params );

        $this->log_system_query( $params, $sql );

        return $this->wpdb->get_results( $sql, ARRAY_A );
    }


    /**
     * Get monitors.
     *
     * @param array $params params.
     * @param int   $obj OBJECT|ARRAY_A.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_monitors( $params = array(), $obj = OBJECT ) {
        if ( empty( $params['view'] ) ) {
            $params['view'] = 'monitor_view';
        }
        return $this->wpdb->get_results( $this->get_sql_monitor( $params ), $obj );
    }

    /**
     * Get sites monitors to check.
     *
     * @param array $params params.
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_monitors_to_check_uptime( $params = array() ) {

        $params = apply_filters( 'mainwp_uptime_monitoring_get_monitors_to_check_params', $params );

        $local_timestamp  = mainwp_get_timestamp();
        $lasttime_counter = isset( $params['main_counter_lasttime'] ) ? intval( $params['main_counter_lasttime'] ) : 0;
        $glo_settings     = isset( $params['global_settings'] ) && is_array( $params['global_settings'] ) ? $params['global_settings'] : array();

        $limit        = isset( $glo_settings['limit'] ) ? intval( $glo_settings['limit'] ) : 10;
        $glo_interval = isset( $glo_settings['interval'] ) ? intval( $glo_settings['interval'] ) : 60; // mins.

        if ( empty( $glo_interval ) ) {
            $glo_interval = 60;
        }

        if ( $limit < 1 || $limit > 20 ) {
            $limit = 10;
        }

        $glo_active = 1;
        if ( isset( $glo_settings['active'] ) ) {
            $glo_active = 1 === (int) $glo_settings['active'] ? 1 : 0;
        }

        $glo_maxretries = isset( $glo_settings['maxretries'] ) ? (int) $glo_settings['maxretries'] : 0;

        if ( $glo_maxretries < 0 || $glo_maxretries > 5 ) {
            $glo_maxretries = 0;
        }

        $not_suspended = true;

        if ( isset( $params['not_suspended'] ) ) {
            $not_suspended = $params['not_suspended'] ? true : false;
        }

        $where = '';
        if ( true === $not_suspended ) {
            $where .= ' AND wp.suspended = 0 ';
        }

        $_params = array(
            'view' => 'ping_view',
        );

        $and_active = ' AND ( mo.active = 1 OR ( mo.active = -1 AND 1 = ' . $glo_active . ') ) ';

        // interval = 0, use global settings.
        $and_interval_run = ' ( mo.interval != -1 AND mo.dts_interval_lasttime + mo.interval * 60 < ' . intval( $local_timestamp ) . ' ) OR ( mo.interval = -1 AND mo.dts_interval_lasttime + ' . intval( $glo_interval ) . ' * 60 < ' . intval( $local_timestamp ) . ') ';
        $and_interval_run = ' ( ' . $and_interval_run . ' )';

        $and_main_round_run = ' ( mo.dts_auto_monitoring_start = 0  OR (  mo.dts_auto_monitoring_start < ' . intval( $lasttime_counter ) . ' ) ) '; // To ensure the check request is not completed.

        $and_retry_run  = '  ( ( mo.maxretries != 0 AND mo.maxretries != -1 AND mo.retries < mo.maxretries ) OR ( mo.maxretries = -1 AND 0 != ' . intval( $glo_maxretries ) . ' AND mo.retries < ' . intval( $glo_maxretries ) . ' ) ) AND '; // in case maxretries >= 1, validate the maximum allowed retries.
        $and_retry_run .= ' ( mo.dts_auto_monitoring_retry_time != 0 AND ( mo.dts_auto_monitoring_retry_time + mo.retry_interval * 60 <= ' . intval( $local_timestamp ) . ' ) ) '; // if retry is set, do it after retry_interval mins.
        $and_retry_run  = ' ( ' . $and_retry_run . ' )';

        $where .= $and_active . ' AND ( ' . $and_interval_run . ' OR ' . $and_main_round_run . ' OR ' . $and_retry_run . ' ) ';

        $_params['limit']        = $limit;
        $_params['custom_where'] = $where;
        $_params['order_by']     = ' mo.dts_auto_monitoring_time ASC ';

        $sql = $this->get_sql_monitor( $_params );

        $this->log_system_query( $params, $sql );

        return $this->wpdb->get_results( $sql, OBJECT );
    }

    /**
     * Count individual enabled sites monitors.
     *
     * @return int
     */
    public function count_monitors_individual_active_enabled() {
        return $this->wpdb->get_var( 'SELECT count(*) FROM ' . $this->table_name( 'monitors' ) . ' WHERE active = 1 ' );
    }

    /**
     * count_busy_main_round_check
     *
     * @param  array $global
     * @return int
     */
    public function count_busy_main_round_check( $global, $lasttime_counter ) {

        if ( ! is_array( $global ) ) {
            $global = array();
        }

        $glo_active = 1;
        if ( is_array( $global ) && isset( $global['active'] ) ) {
            $glo_active = 1 === (int) $global['active'] ? 1 : 0;
        }

        $where_round_run  = ' AND ( mo.active = 1 OR ( mo.active = -1 AND 1 = ' . intval( $glo_active ) . ') ) '; // make sure active monitor.
        $where_round_run .= ' AND ( mo.dts_auto_monitoring_time < mo.dts_auto_monitoring_start ) '; // make sure check uptime running, busy.
        $where_round_run .= ' AND ( mo.dts_auto_monitoring_start < ' . intval( $lasttime_counter ) . ' ) '; // make sure current main round check running.

        $sql = 'SELECT count(*) FROM ' . $this->table_name( 'monitors' ) . ' mo WHERE 1 ' . $where_round_run;

        return $this->wpdb->get_var( $sql );
    }


    /**
     * Get uptime notifcation to send.
     *
     * @param  int $last_starttime
     * @param  int $limit
     * @return mixed
     */
    public function get_uptime_notification_to_start_send( $last_starttime, $limit = 50 ) {

        $sql = $this->wpdb->prepare(
            ' SELECT pro.process_id, mo.* FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'schedule_processes' ) . ' pro ON mo.monitor_id = pro.item_id ' .
            " WHERE ( pro.type = 'monitor' AND pro.process_slug = 'uptime_notification' AND pro.dts_process_start < %d" .
            " AND pro.dts_process_stop < pro.dts_process_start AND pro.status = 'active' ) " .
            ' OR pro.type IS NULL ' .
            ' ORDER BY pro.dts_process_start ASC LIMIT %d ',
            $last_starttime,
            $limit
        );

        return $this->wpdb->get_results( $sql );
    }

    /**
     * Get uptime notifcation to continue send.
     *
     * @param  array $params
     * @return mixed result
     */
    public function get_uptime_notification_to_continue_send( $params ) {
        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $params['view']         = 'uptime_notification';
        $params['custom_where'] = " AND ( ( pro.type = 'monitor' AND pro.process_slug = 'uptime_notification' AND pro.status = 'active' )  OR pro.type IS NULL ) ";
        $params['extra_view']   = array( 'monitoring_notification_emails', 'settings_notification_emails', 'site_info' );

        $sql = $this->get_sql_monitor( $params );

        $this->log_system_query( $params, $sql );

        return $this->wpdb->get_results( $sql );
    }

    /**
     * Update site monitor.
     *
     * @param array $data data.
     *
     * @return object|null Database query results or null on failure.
     */
    public function update_wp_monitor( $data ) {
        if ( ! is_array( $data ) ) {
            return false;
        }
        $allowed_methods = MainWP_Uptime_Monitoring_Edit::get_allowed_methods();

        if ( isset( $data['method'] ) ) {
            $data['method'] = strtolower( $data['method'] );
            if ( empty( $data['method'] ) || ! isset( $allowed_methods[ $data['method'] ] ) ) {
                $data['method'] = 'get';
            }
        }

        if ( isset( $data['monitor_id'] ) ) {
            $id = $data['monitor_id'];
            unset( $data['monitor_id'] );
            $this->wpdb->update(
                $this->table_name( 'monitors' ),
                $data,
                array( 'monitor_id' => $id )
            );
                return $id;
        } else {
            if ( empty( $data['method'] ) || ! isset( $allowed_methods[ $data['method'] ] ) ) {
                $data['method'] = 'get';
            }

            $data['issub'] = ! empty( $data['suburl'] ) ? 1 : 0;

            $this->wpdb->insert( $this->table_name( 'monitors' ), $data );
            return $this->wpdb->insert_id;
        }
    }

    /**
     * Get child site monitor by id via SQL.
     *
     * @param array $params params .
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_sql_monitor( $params ) {

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $view = ! empty( $params['view'] ) ? $params['view'] : 'default';

        $site_id   = isset( $params['wpid'] ) ? intval( $params['wpid'] ) : false;
        $monitorid = isset( $params['monitor_id'] ) ? intval( $params['monitor_id'] ) : false;
        $sub_url   = isset( $params['suburl'] ) ? $params['suburl'] : false;
        $is_sub    = isset( $params['issub'] ) ? intval( $params['issub'] ) : false;

        $limit         = ! empty( $params['limit'] ) ? $params['limit'] : 0;
        $with_clients  = isset( $params['with_clients'] ) && $params['with_clients'] ? true : false;
        $select_grps   = isset( $params['selectgroups'] ) && $params['selectgroups'] ? true : false;
        $is_staging    = isset( $params['is_staging'] ) && in_array( $params['is_staging'], array( 'yes', 'no' ) ) ? $params['is_staging'] : 'no';
        $others_fields = isset( $params['others_fields'] ) && is_array( $params['others_fields'] ) ? $params['others_fields'] : array( 'favi_icon' );
        $for_manager   = isset( $params['for_manager'] ) && $params['for_manager'] ? true : false;
        $custom_where  = ! empty( $params['custom_where'] ) ? $params['custom_where'] : ''; // requires: custom_where validated.
        $order_by      = isset( $params['order_by'] ) && ! empty( $params['order_by'] ) ? $params['order_by'] : '';

        $select_clients = '';
        $join_clients   = '';

        if ( $with_clients ) {
            $select_clients = ', wpclient.name as client_name ';
            $join_clients   = ' LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
        }

        $where = '';

        if ( false !== $site_id ) {
            $where .= ' AND wp.id = ' . intval( $site_id );
        }

        if ( false !== $monitorid ) {
            $where .= ' AND  mo.monitor_id = ' . intval( $monitorid );
        }

        if ( false !== $sub_url ) {
            $where .= $this->wpdb->prepare( ' AND mo.suburl = %s', $sub_url );
        }

        if ( false !== $is_sub ) {
            $where .= ' AND mo.issub = ' . intval( $is_sub ); // If $is_sub is 0, it indicates the main monitor.
        }

        if ( ! $for_manager ) {
            $where .= $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
        }

        $light_fields = array(
            'wp.id',
            'wp.url',
            'wp.name',
            'wp.client_id',
            'wp.verify_certificate',
            'wp.http_user',
            'wp.http_pass',
            'wp.ssl_version',
            'wp.adminname',
            'wp.privkey',
            'wp.pubkey',
            'wp.wpe',
            'wp.is_staging',
            'wp.pubkey',
            'wp.force_use_ipv4',
            'wp.siteurl',
            'wp.suspended',
            'wp.mainwpdir',
            'wp.is_ignoreCoreUpdates',
            'wp.is_ignorePluginUpdates',
            'wp.is_ignoreThemeUpdates',
            'wp.backup_before_upgrade',
            'wp.userid',
            'wp_sync.sync_errors',
        );

        $legacy_status_fields = array(
            'wp.offline_check_result', // 1 - online, -1 offline.
            'wp.http_response_code',
            'wp.http_code_noticed',
            'wp.offline_checks_last',
        );

        $light_fields = array_merge( $light_fields, $legacy_status_fields );

        $base_fields = array(
            $light_fields,
            array(
                'wp.plugins',
                'wp.themes',
            ),
        );

        $default_fields = array(
            'wp.*',
            'wp_sync.*',
        );

        if ( 'ping_view' === $view || 'monitor_view' === $view ) {
            $select_fields = $light_fields;
        } elseif ( 'base_view' === $view ) {
            $select_fields = $base_fields;
        } elseif ( 'uptime_notification' === $view ) {
            $select_fields = $light_fields;
        } else {
            $select_fields = $default_fields;
        }

        $select_fields[] = 'mo.*';
        $select_fields[] = 'wp_optionview.*';

        $select = implode( ',', $select_fields );

        $select_groups = '';
        $join_groups   = '';

        // wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
        if ( $select_grps ) {
            $select_groups = ', GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors, ';
            $join_groups   = '
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id';
        }

        $join_monitors = 'LEFT JOIN ' . $this->table_name( 'monitors' ) . ' mo ON wp.id = mo.wpid';
        $join_process  = '';

        if ( 'uptime_notification' === $view ) {
            $join_process = ' LEFT JOIN ' . $this->table_name( 'schedule_processes' ) . ' pro ON mo.monitor_id = pro.item_id ';
        }

        if ( ! empty( $params['count_only'] ) ) {
            $select = ' count(*) ';
        } else {
            $select = $select . $select_clients . $select_groups;
        }

        if ( empty( $order_by ) ) {
            $order_by = '  mo.monitor_id DESC';
        }

        $limit_str = '';
        if ( ! empty( $limit ) ) {
            $limit_str = ' LIMIT ' . intval( $limit );
        }

        $qry = 'SELECT ' . $select . '
            FROM ' . $this->table_name( 'wp' ) . ' wp
            ' . $join_clients . '
            ' . $join_groups . '
            ' . $join_monitors . '
            ' . $join_process . '
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view_by( $view, $others_fields ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $custom_where . '
            GROUP BY mo.monitor_id ORDER BY ' . $order_by . $limit_str;

        $this->log_system_query( $params, $qry );

        return $qry;
    }


    /**
     * Delete monitor and uptime data.
     *
     * @param array $params data.
     *
     * @return bool success|failed.
     */
    public function delete_monitor( $params ) {

        if ( ! is_array( $params ) ) {
            return false;
        }

        if ( ! empty( $params['monitor_id'] ) ) {
            $sql = $this->wpdb->prepare( 'SELECT monitor_id FROM ' . $this->table_name( 'monitors' ) . ' WHERE monitor_id=%d', $params['monitor_id'] );
        } elseif ( ! empty( $params['wpid'] ) ) {
            $sql = $this->wpdb->prepare( 'SELECT monitor_id FROM ' . $this->table_name( 'monitors' ) . ' WHERE wpid=%d AND issub = 0 ', $params['wpid'] ); // get primary monitor.
        }

        $monitor_id = 0;

        if ( ! empty( $sql ) ) {
            $monitor_id = $this->wpdb->get_var( $sql );
        }

        if ( empty( $monitor_id ) ) {
            return false;
        }

        if ( $monitor_id && $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'monitors' ) . ' WHERE monitor_id=%d', $monitor_id ) ) ) {
            $this->delete_heartbeat( $monitor_id );
            $this->delete_stats( $monitor_id );
            return true;
        }

        return false;
    }

    /**
     * delete_heartbeat by monitor id.
     *
     * @param  int $monitorid
     * @return bool
     */
    public function delete_heartbeat( $monitorid ) {
        if ( empty( $monitorid ) ) {
            return false;
        }

        if ( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' WHERE monitor_id=%d', $monitorid ) ) ) {
            return true;
        }

        return false;
    }

    /**
     * Update heartbeat monitor.
     *
     * @param array $data data.
     *
     * @return int|bool Database query results or null on failure.
     */
    public function update_heartbeat( $data ) {

        if ( ! is_array( $data ) ) {
            return false;
        }

        if ( isset( $data['heartbeat_id'] ) ) {
            $id = $data['heartbeat_id'];
            unset( $data['heartbeat_id'] );
            $this->wpdb->update(
                $this->table_name( 'monitor_heartbeat' ),
                $data,
                array( 'heartbeat_id' => $id )
            );
            return $id;
        } else {
            $this->wpdb->insert( $this->table_name( 'monitor_heartbeat' ), $data );
            return $this->wpdb->insert_id;
        }
    }


    /**
     * Get last monitor heartbeat.
     *
     * @param int $monitor_id.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_previous_monitor_heartbeat( $monitor_id ) {
        $sql = $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' WHERE monitor_id = %d ORDER BY time DESC LIMIT 1', $monitor_id );
        return $this->wpdb->get_row( $sql );
    }


    /**
     * Get last site's heartbeat.
     *
     * @param int  $siteid.
     * @param bool $include_suburl.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_last_site_heartbeat( $siteid, $include_suburl = true ) {

        if ( $include_suburl ) {
            $where = ' AND ( mo.issub = 1 OR mo.issub = 0 ) ';  // primary and sub url monitor.
        } else {
            $where = ' AND mo.issub = 0'; // primary monitor.
        }

        $sql = $this->wpdb->prepare(
            'SELECT he.* FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d ' . $where . ' ORDER BY he.time DESC LIMIT 1',
            $siteid
        );
        return $this->wpdb->get_row( $sql );
    }

    /**
     * Count up down monitors.
     *
     * @param int   $siteid.
     * @param array $days_num.
     *
     * @return array data.
     */
    public function get_count_up_down_monitors() {
        $sql = ' SELECT ' .
        ' ( SELECT count(*) FROM ' . $this->table_name( 'monitors' ) . ' up WHERE  up.last_status = 1 ) AS count_up, ' .
        ' ( SELECT count(*) FROM ' . $this->table_name( 'monitors' ) . ' down WHERE  down.last_status = 0 ) AS count_down ' .
        ' FROM ' . $this->table_name( 'monitors' ) . ' mo LIMIT 1';

        return $this->wpdb->get_row( $sql, ARRAY_A );
    }


    /**
     * Count last site's incidents.
     *
     * @param int   $siteid.
     * @param array $days_num.
     *
     * @return array data.
     */
    public function get_site_count_last_incidents( $siteid, $days_num ) {

        if ( empty( $days_num ) ) {
            $days_num = 1;
        }

        $sql = $this->wpdb->prepare(
            'SELECT ' .
            ' ( SELECT count(*) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he2 WHERE ( he2.time > NOW() - INTERVAL ' . intval( $days_num ) . ' DAY ) AND he2.monitor_id = he.monitor_id AND he2.status = 0 AND he2.importance = 1 ) AS count ' .
            ' FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d LIMIT 1',
            $siteid
        );

        return $this->wpdb->get_row( $sql, ARRAY_A );
    }


    /**
     * Get last site's incidents stats.
     *
     * @param int $siteid.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_last_site_incidents_stats( $siteid ) {
        $sql = $this->wpdb->prepare(
            'SELECT ' .
            ' ( SELECT count(*) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he24 WHERE ( he24.time >= NOW() - INTERVAL 1 DAY ) AND he24.monitor_id = he.monitor_id AND he24.status = 0 AND he.importance = 1 ) AS total24,' .
            ' ( SELECT count(*) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he7 WHERE ( he7.time >= NOW() - INTERVAL 7 DAY ) AND he7.monitor_id = he.monitor_id AND he7.status = 0 AND he.importance = 1 ) AS total7,' .
            ' ( SELECT count(*) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he30 WHERE ( he30.time >= NOW() - INTERVAL 30 DAY ) AND he30.monitor_id = he.monitor_id AND he30.status = 0 AND he.importance = 1 ) AS total30 ' .
            ' FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d LIMIT 1',
            $siteid
        );
        return $this->wpdb->get_row( $sql, ARRAY_A );
    }


    /**
     * Get site's incidents stats.
     *
     * @param int   $siteid.
     * @param array $params.
     *
     * @return array data.
     */
    public function get_site_incidents_stats( $siteid, $params = array() ) {

        $start = isset( $params['start'] ) ? $params['start'] . ' 00:00:00' : gmdate( 'Y-m-d 00:00:00', time() - 7 * DAY_IN_SECONDS );
        $end   = isset( $params['end'] ) ? $params['end'] . ' 23:59:59' : gmdate( 'Y-m-d 23:59:59', time() );

        $sql = $this->wpdb->prepare(
            'SELECT ' .
            ' ( SELECT count(*) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he WHERE he.time > "' . $this->escape( $start ) . '" AND he.time <= "' . $this->escape( $end ) . '" AND he.monitor_id = he.monitor_id AND he.status = 0 ) AS count_incidents' .
            ' FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d LIMIT 1',
            $siteid
        );

        $results = $this->wpdb->get_row( $sql, ARRAY_A );

        return array(
            'start'           => $start,
            'end'             => $end,
            'count_incidents' => is_array( $results ) && isset( $results['count_incidents'] ) ? $results['count_incidents'] : 0,
        );
    }


    /**
     * Get last site's uptime ratios stats.
     *
     * @param int $siteid.
     * @param int $days_num.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_last_site_uptime_ratios_values( $siteid, $days_num ) {

        if ( empty( $days_num ) ) {
            $days_num = 1;
        }

        $sql = $this->wpdb->prepare(
            'SELECT ' .
            ' ( SELECT SUM(he.duration) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he WHERE ( he.time >= NOW() - INTERVAL ' . intval( $days_num ) . ' DAY ) AND he.monitor_id = he.monitor_id AND he.status = 1 ) AS up_value,' .
            ' ( SELECT SUM(he.duration) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he WHERE ( he.time >= NOW() - INTERVAL ' . intval( $days_num ) . ' DAY ) AND he.monitor_id = he.monitor_id AND ( he.status = 0 OR he.status = 1 )  ) AS total_value ' .
            ' FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d LIMIT 1',
            $siteid
        );
        return $this->wpdb->get_row( $sql, ARRAY_A );
    }

    /**
     * Get last site's uptime ratios stats.
     *
     * @param int $siteid.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_last_site_uptime_ratios_stats( $siteid ) {
        $sql = $this->wpdb->prepare(
            'SELECT ' .
            ' ( SELECT SUM(he24.duration) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he24 WHERE ( he24.time >= NOW() - INTERVAL 1 DAY ) AND he24.monitor_id = he.monitor_id AND he24.status = 1 ) AS up24,' .
            ' ( SELECT SUM(he24.duration) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he24 WHERE ( he24.time >= NOW() - INTERVAL 1 DAY ) AND he24.monitor_id = he.monitor_id  AND ( he24.status = 0 OR he24.status = 1 )  ) AS total24,' .
            ' ( SELECT SUM(he7.duration)  FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he7 WHERE ( he7.time >= NOW() - INTERVAL 7 DAY ) AND he7.monitor_id = he.monitor_id AND he7.status = 1 ) AS up7,' .
            ' ( SELECT SUM(he7.duration)  FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he7 WHERE ( he7.time >= NOW() - INTERVAL 7 DAY ) AND he7.monitor_id = he.monitor_id AND ( he7.status = 0 OR he7.status = 1 ) ) AS total7,' .
            ' ( SELECT SUM(he30.duration) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he30 WHERE ( he30.time >= NOW() - INTERVAL 30 DAY ) AND he30.monitor_id = he.monitor_id AND he30.status = 1 ) AS up30, ' .
            ' ( SELECT SUM(he30.duration) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' he30 WHERE ( he30.time >= NOW() - INTERVAL 30 DAY ) AND he30.monitor_id = he.monitor_id AND ( he30.status = 0 OR he30.status = 1 ) ) AS total30 ' .
            ' FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d LIMIT 1',
            $siteid
        );
        return $this->wpdb->get_row( $sql, ARRAY_A );
    }



    /**
     * Get site's reports uptime ratios.
     *
     * @param int   $siteid.
     * @param array $params. 'start' and 'end' date format: Y-m-d.
     *
     *  'period_days' : array(
     *       'uptimeratiosall' => 365, // Last 365 days.
     *       'uptimeratios7'   => 7,
     *       'uptimeratios15'  => 15,
     *       'uptimeratios30'  => 30,
     *       'uptimeratios45'  => 45,
     *      'uptimeratios60'  => 60,
     * )
     *
     * @return array data.
     */
    public function get_site_uptime_ratios_reports_data( $siteid, $params = array() ) {

        $period_days = isset( $params['period_days'] ) ? $params['period_days'] : array();
        if ( empty( $period_days ) || empty( $params['start'] ) || empty( $params['end'] ) ) {
            return array();
        }

        $start_time = strtotime( $params['start'] . ' 00:00:00' );

        $period_date = array();

        foreach ( $period_days as $period => $days ) {
            $period_date[ $period ] = array(
                'start' => gmdate( 'Y-m-d 00:00:00', $start_time - $days * DAY_IN_SECONDS ),
                'end'   => $params['end'] . ' 23:59:59',
            );
        }

        $sql_sub = '';

        foreach ( $period_date as $period => $date ) {
            $as_up    = $period;
            $as_total = 'total' . $period;
            $_sql     = ' ( SELECT SUM(' . $as_up . '.duration) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' ' . $as_up . ' WHERE  ' . $as_up . '.time > "' . $this->escape( $date['start'] ) . '" AND ' . $as_up . '.time <= "' . $this->escape( $date['end'] ) . '" AND ' . $as_up . '.monitor_id = he.monitor_id AND ' . $as_up . '.status = 1 ) AS ' . $as_up . ',';
            $_sql    .= ' ( SELECT SUM(' . $as_total . '.duration) FROM ' . $this->table_name( 'monitor_heartbeat' ) . ' ' . $as_total . ' WHERE  ' . $as_total . '.time > "' . $this->escape( $date['start'] ) . '" AND ' . $as_total . '.time <= "' . $this->escape( $date['end'] ) . '" AND ' . $as_total . '.monitor_id = he.monitor_id AND ( ' . $as_total . '.status = 1 OR ' . $as_total . '.status = 0 ) ) AS ' . $as_total . ',';
            $sql_sub .= $_sql;

        }

        $sql_sub = rtrim( $sql_sub, ',' );

        $sql = $this->wpdb->prepare(
            'SELECT ' .
            $sql_sub .
            ' FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d LIMIT 1',
            $siteid
        );

        $result = $this->wpdb->get_row( $sql, ARRAY_A );

        if ( ! is_array( $result ) ) {
            $result = array();
        }

        $data = array();

        foreach ( $period_days as $period => $number ) {
            $as_up           = $period;
            $as_total        = 'total' . $period;
            $data[ $period ] = isset( $result[ $as_total ] ) && ! empty( $result[ $as_total ] ) ? number_format( $result[ $as_up ] * 100 / $result[ $as_total ], 6 ) : 'N/A'; // prevent divided by zero.
        }

        return $data;
    }


    /**
     * Get monitor response time stats.
     *
     * @param int   $siteid.
     * @param array $params.
     * array 'start' and 'end' format : 'Y-m-d'.
     *
     * @return array Database query results or empty.
     */
    public function get_db_site_response_time_stats_data( $siteid, $params = array() ) {

        if ( empty( $params['start'] ) || empty( $params['end'] ) ) {
            return array();
        }

        $group_time = ! empty( $params['group_time_by'] ) ? strtolower( $params['group_time_by'] ) : 'date';

        $start_ts = strtotime( $params['start'] );
        $end_ts   = strtotime( $params['end'] );

        // Ensure that the date is in the correct format.
        $start = gmdate( 'Y-m-d 00:00:00', $start_ts );
        $end   = gmdate( 'Y-m-d 23:59:59', $end_ts );

        $sum_total = ', SUM(he.ping_ms) as resp_total_ms ';

        if ( 'hour' === $group_time ) {
            $group_by = ' GROUP BY HOUR(he.time) ';
        } elseif ( 'get_all' === $group_time ) {
            $group_by  = '';
            $sum_total = ', he.ping_ms as resp_total_ms '; // required.
        } else {
            $group_by = ' GROUP BY DATE( he.time ) ';
        }

        $where = '';
        if ( isset( $params['issub'] ) && 0 === (int) $params['issub'] ) {
            $where = ' AND mo.issub = 0 '; // primary.
        }

        $sql = $this->wpdb->prepare(
            'SELECT he.time as resp_time ' . $sum_total . '  FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d ' . $where . ' AND he.time > "' . $this->escape( $start ) . '" AND he.time <= "' . $this->escape( $end ) . '" ' .
            $group_by .
            ' ORDER BY resp_time ASC ',
            $siteid
        );

        $data = $this->wpdb->get_results( $sql, ARRAY_A );

        $sql_stats = $this->wpdb->prepare(
            'SELECT AVG( he.ping_ms ) AS avg_time_ms, MIN( he.ping_ms ) as min_time_ms, MAX( he.ping_ms ) as max_time_ms FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d AND he.time > "' . $this->escape( $start ) . '" AND he.time <= "' . $this->escape( $end ) . '"',
            $siteid
        );

        $resp_data = $this->wpdb->get_row( $sql_stats, ARRAY_A );

        return array(
            'resp_time_list'  => $data, // resp time list.
            'start'           => $start,
            'end'             => $end,
            'resp_stats_data' => $resp_data, // resp time stats.
        );
    }


    /**
     * Get monitoring events stats.
     *
     * @param int   $siteid.
     * @param array $params.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_site_monitoring_events_stats( $siteid, $params = array() ) {

        $start = isset( $params['start'] ) ? $params['start'] . ' 00:00:00' : gmdate( 'Y-m-d 00:00:00', time() - 7 * DAY_IN_SECONDS );
        $end   = isset( $params['end'] ) ? $params['end'] . ' 23:59:59' : gmdate( 'Y-m-d 23:59:59', time() );

        $sql = $this->wpdb->prepare(
            'SELECT mo.active,mo.type,mo.keyword,mo.suburl,mo.interval,he.msg,he.status,he.time,he.ping_ms,he.http_code FROM ' . $this->table_name( 'monitors' ) . ' mo ' .
            ' LEFT JOIN ' . $this->table_name( 'monitor_heartbeat' ) . ' he ' .
            ' ON mo.monitor_id = he.monitor_id ' .
            ' WHERE mo.wpid = %d AND he.time > "' . $this->escape( $start ) . '" AND he.time <= "' . $this->escape( $end ) . '" ' .
            ' AND he.importance = 1 ',
            $siteid
        );

        return $this->wpdb->get_results( $sql, ARRAY_A );
    }


    /**
     * Get monitor uptime hourly stats by.
     *
     * @param  int    $monitor_id
     * @param  string $by
     * @param  mixed  $value
     * @param  int    $obj
     * @return mixed
     */
    public function get_uptime_monitor_stat_hourly_by( $monitor_id, $by, $value = false, $obj = ARRAY_A ) {
        if ( 'timestamp' === $by ) {
            if ( empty( $value ) ) {
                return false;
            }
            $sql = $this->wpdb->prepare( 'SELECT stho.* FROM ' . $this->table_name( 'monitor_stat_hourly' ) . ' stho WHERE stho.monitor_id = %d AND stho.timestamp = %d', $monitor_id, $value );
            return $this->wpdb->get_row( $sql, $obj );
        } elseif ( 'last24' === $by ) {
            if ( empty( $value ) ) {
                return false;
            }
            $sql = $this->wpdb->prepare( 'SELECT stho.* FROM ' . $this->table_name( 'monitor_stat_hourly' ) . ' stho WHERE stho.monitor_id = %d AND stho.timestamp >= %d  ORDER BY stho.timestamp ASC ', $monitor_id, $value );
            return $this->wpdb->get_results( $sql, $obj );
        }
        return false;
    }


    /**
     * remove_outdated_hourly_uptime_stats
     *
     * @param  int $days
     * @return void
     */
    public function remove_outdated_hourly_uptime_stats( $days = 30 ) {
        $time = time() - $days * DAY_IN_SECONDS;
        $this->wpdb->query( $this->wpdb->prepare( 'DELETE  FROM ' . $this->table_name( 'monitor_stat_hourly' ) . ' WHERE timestamp < %d', $time ) );
    }


    /**
     * Update uptime stat hourly.
     *
     * @param array $data data.
     *
     * @return int|bool Database query results or null on failure.
     */
    public function update_site_uptime_stat_hourly( $data ) {

        if ( ! is_array( $data ) ) {
            return false;
        }

        if ( isset( $data['stat_hourly_id'] ) ) {
            $id = $data['stat_hourly_id'];
            unset( $data['stat_hourly_id'] );
            $this->wpdb->update(
                $this->table_name( 'monitor_stat_hourly' ),
                $data,
                array( 'stat_hourly_id' => $id )
            );
            return $id;
        } else {
            $this->wpdb->insert( $this->table_name( 'monitor_stat_hourly' ), $data );
            return $this->wpdb->insert_id;
        }
    }


    /**
     * Delete stats by monitor id.
     *
     * @param  int $monitorid
     * @return bool
     */
    public function delete_stats( $monitorid ) {
        if ( empty( $monitorid ) ) {
            return false;
        }

        if ( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'monitor_stat_hourly' ) . ' WHERE monitor_id=%d', $monitorid ) ) ) {
            return true;
        }

        return false;
    }
}