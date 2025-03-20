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
 * @uses \MainWP\Dashboard\MainWP_DB_Base
 */
class MainWP_DB extends MainWP_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Private static variable to hold the single instance.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $general_options = null;

    /**
     * Possible options.
     *
     * @var array $possible_options
     */
    private static $possible_options = array(
        'plugin_upgrades',
        'theme_upgrades',
        'premium_upgrades',
        'plugins',
        'themes',
        'dtsSync',
        'version',
        'sync_errors',
        'ignored_plugins',
        'wp_upgrades',
        'site_info',
        'client',
        'signature_algo',
        'verify_method',
        'pubkey',
    );

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
     * Get wp_options database table view.
     *
     * @param array  $fields Extra option fields.
     * @param string $view_query view query.
     *
     * @return array wp_options view.
     */
    public function get_option_view( $fields = array(), $view_query = 'default' ) {

        if ( ! is_array( $fields ) ) {
            $fields = array();
        }

        $view = '(SELECT intwp.id AS wpid ';

        $included_opts = array();

        if ( empty( $fields ) || 'default' === $view_query || 'manage_site' === $view_query ) {
            $view                 .= ',(SELECT recent_comments.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_comments WHERE  recent_comments.wpid = intwp.id AND recent_comments.name = "recent_comments" LIMIT 1) AS recent_comments,
                    (SELECT recent_posts.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_posts WHERE  recent_posts.wpid = intwp.id AND recent_posts.name = "recent_posts" LIMIT 1) AS recent_posts,
                    (SELECT recent_pages.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_pages WHERE  recent_pages.wpid = intwp.id AND recent_pages.name = "recent_pages" LIMIT 1) AS recent_pages,
                    (SELECT phpversion.value FROM ' . $this->table_name( 'wp_options' ) . ' phpversion WHERE  phpversion.wpid = intwp.id AND phpversion.name = "phpversion" LIMIT 1) AS phpversion,
                    (SELECT added_timestamp.value FROM ' . $this->table_name( 'wp_options' ) . ' added_timestamp WHERE  added_timestamp.wpid = intwp.id AND added_timestamp.name = "added_timestamp" LIMIT 1) AS added_timestamp,
                    (SELECT wp_upgrades.value FROM ' . $this->table_name( 'wp_options' ) . ' wp_upgrades WHERE  wp_upgrades.wpid = intwp.id AND wp_upgrades.name = "wp_upgrades" LIMIT 1) AS wp_upgrades ';
                    $included_opts = array( 'recent_comments', 'recent_posts', 'recent_pages', 'phpversion', 'added_timestamp', 'wp_upgrades' );
        }

        if ( ! in_array( 'signature_algo', $fields ) ) {
            $fields[] = 'signature_algo';
        }

        if ( ! in_array( 'verify_method', $fields ) ) {
            $fields[] = 'verify_method';
        }

        if ( ! in_array( 'cust_site_icon_info', $fields, true ) ) {
            $fields[] = 'cust_site_icon_info';
        }

        if ( is_array( $fields ) ) {
            foreach ( $fields as $field ) {
                if ( empty( $field ) ) {
                    continue;
                }
                if ( in_array( $field, $included_opts ) ) {
                    continue;
                }
                $view .= ', ';
                $view .= '(SELECT ' . $this->escape( $field ) . '.value FROM ' . $this->table_name( 'wp_options' ) . ' ' . $this->escape( $field ) . ' WHERE  ' . $this->escape( $field ) . '.wpid = intwp.id AND ' . $this->escape( $field ) . '.name = "' . $this->escape( $field ) . '" LIMIT 1) AS ' . $this->escape( $field );
            }
        }

        $view .= ' FROM ' . $this->table_name( 'wp' ) . ' intwp)';

        return $view;
    }



    /**
     * Get SQL to get child sites for current user.
     *
     * @since 5.2.
     * @param array $params   other params.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_sql_websites_for_current_user_by_params( $params = array() ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $params ) ) {
            $params = array();
        }
        $view         = isset( $params['view'] ) ? $params['view'] : 'default';
        $with_clients = isset( $params['with_clients'] ) && $params['with_clients'] ? true : false;

        // legacy support.
        $selectgroups  = isset( $params['with_tags'] ) && $params['with_tags'] ? true : false;
        $orderBy       = isset( $params['orderby'] ) ? $params['orderby'] : 'wp.url';
        $offset        = isset( $params['offset'] ) ? intval( $params['offset'] ) : false;
        $rowcount      = isset( $params['rowcount'] ) && $params['rowcount'] ? true : false;
        $extraWhere    = isset( $params['where'] ) ? $params['where'] : null; // NOTE: without 'AND' at begining and ending of 'where'.
        $for_manager   = isset( $params['for_manager'] ) && $params['for_manager'] ? true : false;
        $others_fields = isset( $params['others_fields'] ) && is_array( $params['others_fields'] ) ? $params['others_fields'] : array( 'favi_icon' );
        $is_staging    = isset( $params['is_staging'] ) && in_array( $params['is_staging'], array( 'yes', 'no' ) ) ? $params['is_staging'] : 'no';
        $limit         = isset( $params['limit'] ) ? intval( $params['limit'] ) : '';

        $s        = isset( $params['s'] ) ? $params['s'] : '';
        $exclude  = isset( $params['exclude'] ) ? wp_parse_id_list( $params['exclude'] ) : array();
        $include  = isset( $params['include'] ) ? wp_parse_id_list( $params['include'] ) : array();
        $status   = isset( $params['status'] ) ? wp_parse_list( $params['status'] ) : array();
        $page     = isset( $params['page'] ) ? intval( $params['page'] ) : false;
        $per_page = isset( $params['per_page'] ) ? intval( $params['per_page'] ) : false;

        $where = '';

        if ( ! empty( $extraWhere ) ) {
            $where .= ' AND ' . $extraWhere;
        }

        if ( ! $for_manager ) {
            $where .= $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
        }

        $connected_sql = '';

        if ( is_array( $params ) && isset( $params['connected'] ) && 'yes' === $params['connected'] ) {
            $connected_sql = ' AND wp_sync.sync_errors = "" ';
        } elseif ( is_array( $params ) && isset( $params['connected'] ) && 'no' === $params['connected'] ) {
            $connected_sql = '  AND wp_sync.sync_errors <> "" ';
        }

        if ( ! empty( $s ) ) {
            $where .= ' AND ( wp.id LIKE "%' . $this->escape( $s ) . '%" OR wp.name LIKE "%' . $this->escape( $s ) . '%" OR wp.url LIKE "%' . $this->escape( $s ) . '%" ) ';
        }

        if ( ! empty( $exclude ) ) {
            $where .= ' AND  wp.id NOT IN (' . implode( ',', $exclude ) . ') ';
        }

        if ( ! empty( $include ) ) {
            $where .= ' AND  wp.id IN (' . implode( ',', $include ) . ') ';
        }

        // any, connected, disconnected, suspended, available_update.
        if ( ! empty( $status ) && is_array( $status ) && ! in_array( 'any', $status ) ) {
            $status_conds = array();
            if ( in_array( 'available_update', $status ) ) {
                $available_sql = " ( wp.plugin_upgrades <> '' &&  wp.plugin_upgrades <> '[]' ) OR (  wp.theme_upgrades <> '' &&  wp.theme_upgrades <> '[]'  ) OR (  wp.translation_upgrades <> '' &&  wp.translation_upgrades <> '[]' ) OR ( wp.premium_upgrades <> '' &&  wp.premium_upgrades <> '[]' ) ";
                $results       = $this->wpdb->get_results( 'SELECT wpid FROM ' . $this->table_name( 'wp_options' ) . "  WHERE name = 'wp_upgrades' AND value <> '' AND value <> '[]' " );
                if ( $results ) {
                    $wp_ids = array();
                    foreach ( $results as $item ) {
                        if ( ! empty( $item->wpid ) ) {
                            $wp_ids[] = $item->wpid;
                        }
                    }
                    $wp_ids = ! empty( $wp_ids ) ? array_unique( $wp_ids ) : array();
                    if ( ! empty( $wp_ids ) ) {
                        $available_sql .= ' OR wp.id IN ( ' . implode( ',', $wp_ids ) . ' )';
                    }
                }
                $status_conds[] = ' ( ' . $available_sql . ') ';
            }

            if ( in_array( 'connected', $status ) ) {
                $status_conds[] = ' ( wp_sync.sync_errors == "" ) ';
            }
            if ( in_array( 'disconnected', $status ) ) {
                $status_conds[] = " wp_sync.sync_errors <> '' ";
            }

            if ( in_array( 'suspended', $status ) ) {
                $status_conds[] = ' wp.suspended = 1 ';
            }

            if ( ! empty( $status_conds ) ) {
                $where .= ' AND ( ' . implode( ' OR ', $status_conds ) . ' ) ';
            }
        }

        if ( ! empty( $page ) && ! empty( $per_page ) ) {
            $limit = ( $page - 1 ) * $per_page . ',' . $per_page;
        }

        if ( 'wp.url' === $orderBy ) {
            $orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')";
        }

        $select_clients = '';
        $join_clients   = '';

        if ( $with_clients ) {
            $select_clients = ', wpclient.name as client_name ';
            $join_clients   = ' LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
        }

        $base_fields = array(
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
            'wp_sync.sync_errors',
            'wp.backup_before_upgrade',
            'wp.userid',
            'wp.plugins',
            'wp.themes',
            'wp.offline_check_result', // 1 - online, -1 offline.
        );

        $select = ' wp.*,wp_sync.* ';
        if ( 'base_view' === $view ) {
            $select = implode( ',', $base_fields );
        } elseif ( 'updates_view' === $view ) {
            $updates_fields = array(
                'wp.plugin_upgrades',
                'wp.theme_upgrades',
                'wp.translation_upgrades',
                'wp.premium_upgrades',
                'wp.ignored_themes',
                'wp.ignored_plugins',
            );
            $select         = implode( ',', array_merge( $updates_fields, $base_fields ) );
        }

        $select .= ',wp_optionview.* '; // to fix bug.

        // wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
        if ( $selectgroups ) {
            $qry = 'SELECT ' . $select . ', GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors,
            ' . $select_clients . '
            FROM ' . $this->table_name( 'wp' ) . ' wp
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
            ' . $join_clients . '
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view_by( $view, $others_fields ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $connected_sql . '
            GROUP BY wp.id, wp_sync.sync_id
            ORDER BY ' . $orderBy;
        } else {
            $qry = 'SELECT ' . $select .
            $select_clients . '
            FROM ' . $this->table_name( 'wp' ) . ' wp
            ' . $join_clients . '
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view_by( $view, $others_fields ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $connected_sql . '
            GROUP BY wp.id, wp_sync.sync_id
            ORDER BY ' . $orderBy;
        }

        if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
            $qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
        } elseif ( false !== $rowcount ) {
            $qry .= ' LIMIT ' . $rowcount;
        } elseif ( ! empty( $limit ) ) {
            $qry .= ' LIMIT ' . $limit;
        } else {
            // load all sites so check to support limit sites loading.
            $limit_sites = ! empty( $params['limit_sites'] ) ? intval( $params['limit_sites'] ) : 0;
            if ( ! empty( $limit_sites ) ) {
                $current_page = (int) get_option( 'mainwp_manage_updates_limit_current_page', 0 );
                $current_page = $current_page > 0 ? $current_page - 1 : 0;
                $start        = $current_page * $limit_sites;
                $qry         .= ' LIMIT ' . intval( $start ) . ', ' . intval( $limit_sites );
            }
        }

        return $qry;
    }

    /**
     * Get wp_options database table view.
     *
     * @param array $view Option view.
     * @param array $other_fields Extra option fields.
     *
     * @return array wp_options view.
     */
    public function get_option_view_by( $view = '', $other_fields = array() ) {

        $default = array(
            'recent_comments',
            'recent_posts',
            'recent_pages',
            'phpversion',
            'added_timestamp',
            'wp_upgrades',
        );

        if ( 'updates_view' === $view ) {
            $fields = array(
                'wp_upgrades',
                'ignored_wp_upgrades',
            );
        } elseif ( in_array( $view, array( 'simple_view', 'base_view', 'monitor_view', 'ping_view', 'uptime_notification' ) ) ) {
            $fields = array();
            if ( 'monitor_view' === $view ) {
                $fields[] = 'health_site_status';
            }
        } else {
            $fields = $default;
        }

        if ( is_array( $other_fields ) && ! empty( $other_fields ) ) {
            $fields = array_unique( array_merge( $fields, $other_fields ) );
        }

        $view_query = '(SELECT intwp.id AS wpid ';

        if ( ! in_array( 'signature_algo', $fields ) ) {
            $fields[] = 'signature_algo';
        }

        if ( ! in_array( 'verify_method', $fields ) ) {
            $fields[] = 'verify_method';
        }

        foreach ( $fields as $field ) {

            if ( empty( $field ) ) {
                continue;
            }

            $view_query .= ', ';
            $view_query .= '(SELECT ' . $this->escape( $field ) . '.value FROM ' . $this->table_name( 'wp_options' ) . ' ' . $this->escape( $field ) . ' WHERE  ' . $this->escape( $field ) . '.wpid = intwp.id AND ' . $this->escape( $field ) . '.name = "' . $this->escape( $field ) . '" LIMIT 1) AS ' . $this->escape( $field );
        }

        $view_query .= ' FROM ' . $this->table_name( 'wp' ) . ' intwp)';

        return $view_query;
    }

    /**
     * Method get_select_groups_belong().
     *
     * @return string sql.
     */
    public function get_select_groups_belong() {
        return ', ( SELECT GROUP_CONCAT(grbl.name ORDER BY grbl.name SEPARATOR ",")
        FROM ' . $this->table_name( 'wp_group' ) . ' wpgrbl
        JOIN ' . $this->table_name( 'group' ) . ' grbl ON grbl.id = wpgrbl.groupid WHERE wpgrbl.wpid = wp.id )  as wpgroups_belong,
        ( SELECT GROUP_CONCAT(grbl.id ORDER BY grbl.name SEPARATOR ",") FROM ' . $this->table_name( 'wp_group' ) . ' wpgrbl
        JOIN ' . $this->table_name( 'group' ) . ' grbl ON grbl.id = wpgrbl.groupid WHERE wpgrbl.wpid = wp.id ) as wpgroupids_belong,
        ( SELECT GROUP_CONCAT(grbl.color ORDER BY grbl.name SEPARATOR ",") FROM ' . $this->table_name( 'wp_group' ) . ' wpgrbl
        JOIN ' . $this->table_name( 'group' ) . ' grbl ON grbl.id = wpgrbl.groupid WHERE wpgrbl.wpid = wp.id ) as wpgroupcolors_belong ';
    }

    /**
     * Get connected child sites.
     *
     * @param array $sites_ids Websites ids - option field.
     *
     * @return array $connected_sites Array of connected sites.
     */
    public function get_connected_websites( $sites_ids = false ) {
        $where = $this->get_sql_where_allow_access_sites( 'wp' );

        $sql = 'SELECT wp.*,wp_sync.*
                FROM ' . $this->table_name( 'wp' ) . ' wp
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync
                ON wp.id = wp_sync.wpid
                WHERE (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors = "") ' .
                $where;

        $websites        = $this->wpdb->get_results( $sql );
        $connected_sites = array();
        if ( $websites ) {
            foreach ( $websites as $website ) {

                if ( ! empty( $sites_ids ) && ! in_array( $website->id, $sites_ids ) ) {
                    continue;
                }

                $connected_sites[] = array(
                    'id'   => $website->id,
                    'name' => $website->name,
                    'url'  => $website->url,
                );
            }
        }
        return $connected_sites;
    }

    /**
     * Get disconnected child sites.
     *
     * @param array $sites_ids Websites ids - option field.
     *
     * @return array $disc_sites Array of disonnected sites.
     */
    public function get_disconnected_websites( $sites_ids = false ) {
        $where = $this->get_sql_where_allow_access_sites( 'wp' );

        $sql = 'SELECT wp.*,wp_sync.*
                FROM ' . $this->table_name( 'wp' ) . ' wp
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync
                ON wp.id = wp_sync.wpid
                WHERE (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "") ' .
                $where;

        $websites   = $this->wpdb->get_results( $sql );
        $disc_sites = array();
        if ( $websites ) {
            foreach ( $websites as $website ) {

                if ( ! empty( $sites_ids ) && ! in_array( $website->id, $sites_ids ) ) {
                    continue;
                }

                $disc_sites[] = array(
                    'id'   => $website->id,
                    'name' => $website->name,
                    'url'  => $website->url,
                );
            }
        }
        return $disc_sites;
    }

    /**
     * Get child site count.
     *
     * @param null $userId Current user ID.
     * @param bool $all_access Check if user has access to all sites.
     *
     * @return int Child site count.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
     */
    public function get_websites_count( $userId = null, $all_access = false ) {
        static $total_sites;
        if ( null !== $total_sites ) { // NOSONAR -- static value.
            return $total_sites;
        }
        if ( ( null === $userId ) && MainWP_System::instance()->is_multi_user() ) {

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            $userId = $current_user->ID;
        }
        $where = ( null === $userId ? '' : ' wp.userid = ' . $userId );
        if ( ! $all_access ) {
            $where .= $this->get_sql_where_allow_access_sites( 'wp' );
        }
        $qry = 'SELECT COUNT(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp WHERE 1 ' . $where;

        $total       = $this->wpdb->get_var( $qry );
        $total_sites = $total;// NOSONAR -- static value.
        return $total;
    }


    /**
     * Get child sites stats count.
     *
     * @param array $params Params.
     */
    public function get_websites_stats_count( $params = array() ) {
        if ( ! is_array( $params ) ) {
            $params = array();
        }

        if ( isset( $params['all_access'] ) ) {
            $all_access = ! empty( $params['all_access'] ) ? true : false;
        } else {
            $all_access = true;
        }

        $where = '';
        if ( ! $all_access ) {
            $where .= $this->get_sql_where_allow_access_sites( 'wp' );
        }

        $select_stats = ' ( SELECT COUNT(wp.id) as count_all ';
        if ( ! empty( $params['count_disconnected'] ) ) {
            $select_stats .= ',( SELECT COUNT(wp_disconnected.id) FROM ' . $this->table_name( 'wp' ) . ' wp_disconnected LEFT JOIN ' . $this->table_name( 'wp_sync' ) . ' as wp_sync ';
            $select_stats .= ' ON wp_disconnected.id = wp_sync.wpid WHERE wp_sync.sync_errors != "" ) as count_disconnected  ';
        }
        if ( ! empty( $params['count_suspended'] ) ) {
            $select_stats .= ',( SELECT COUNT(wp_suspended.id) FROM ' . $this->table_name( 'wp' ) . ' wp_suspended WHERE wp_suspended.suspended = 1 ) as count_suspended ';
        }
        $qry  = 'SELECT * FROM ' . $select_stats;
        $qry .= ' FROM ' . $this->table_name( 'wp' ) . ' wp ' . $where . ' ) as wp_stats ';

        return $this->wpdb->get_row( $qry, ARRAY_A ); //phpcs:ignore -- ok.
    }

    /**
     * Get Child site wp_options database table.
     *
     * @param array $website Child Site array.
     * @param mixed $option  Child Site wp_options table name.
     * @param mixed $default_value  default value.
     * @param mixed $json_format Is json format value.
     *
     * @return string|null Database query result (as string), or null on failure.
     */
    public function get_website_option( $website, $option, $default_value = null, $json_format = false ) { //phpcs:ignore -- NOSONAR - complex.

        if ( is_array( $website ) ) {
            if ( isset( $website[ $option ] ) ) {
                $value = $website[ $option ];
                if ( true === $json_format ) {
                    $value = ! empty( $value ) ? json_decode( $value, true ) : array();
                    return is_array( $value ) ? $value : array();
                } else {
                    return $value;
                }
            }
            $site_id = $website['id'];
        } elseif ( is_object( $website ) ) {
            if ( property_exists( $website, $option ) ) {
                $value = $website->{$option};
                if ( true === $json_format ) {
                    $value = ! empty( $value ) ? json_decode( $value, true ) : array();
                    return is_array( $value ) ? $value : array();
                } else {
                    return $value;
                }
            }
            $site_id = $website->id;
        } elseif ( is_numeric( $website ) ) { // to support $site_id = 0, for global options.
            $site_id = $website;
        } else {
            return false;
        }

        $value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $site_id ) );

        if ( null === $value && null !== $default_value ) {
            return $default_value;
        }

        if ( true === $json_format ) {
            $value = ! empty( $value ) ? json_decode( $value, true ) : array();
            return is_array( $value ) ? $value : array();
        } else {
            return $value;
        }
    }

    /**
     * Get Child site wp_options json value.
     *
     * @since 5.1.1
     *
     * @param array $website Child Site array.
     * @param mixed $option  Child Site wp_options table name.
     * @param mixed $default_value  default value.
     *
     * @return string|null Database query result (as string), or null on failure.
     */
    public function get_json_website_option( $website, $option, $default_value = null ) {
        return $this->get_website_option( $website, $option, $default_value, true );
    }

    /**
     * Get child site options.
     *
     * @param array $website Child site.
     * @param mixed $options Child site options name.
     *
     * @return string|null Database query result (as string), or null on failure.
     */
    public function get_website_options_array( &$website, $options ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $options ) || empty( $options ) ) {
            return array();
        }

        if ( is_array( $website ) ) {
            $site_id = $website['id'];
        } elseif ( is_object( $website ) ) {
            $site_id = $website->id;
        } elseif ( is_numeric( $website ) ) { // to support $site_id = 0 for global options.
            $site_id = $website;
        } else {
            return array();
        }

        $arr_options = array();
        $get_options = array();

        foreach ( $options as $option ) {
            if ( is_array( $website ) ) {
                if ( isset( $website[ $option ] ) ) {
                    $arr_options[ $option ] = $website[ $option ];
                } else {
                    $get_options[] = $option;
                }
            } elseif ( is_object( $website ) ) {
                if ( property_exists( $website, $option ) ) {
                    $arr_options[ $option ] = $website->{$option};
                } else {
                    $get_options[] = $option;
                }
            } else {
                $get_options[] = $option;
            }
        }

        if ( empty( $get_options ) ) {
            return $arr_options; // all options.
        }

        $options_name = implode( "','", $get_options );
        $options_name = "'" . $options_name . "'";

        $options_db = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name, value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name IN (' . $options_name . ')', $site_id ) );

        $fill_options = array(
            'primary_lasttime_backup',
        );

        foreach ( (array) $options_db as $o ) {
            $arr_options[ $o->name ] = $o->value;
            if ( in_array( $o->name, $fill_options ) ) {
                if ( is_array( $website ) ) {
                    if ( ! isset( $website[ $o->name ] ) ) {
                        $website[ $o->name ] = $o->value;
                    }
                } elseif ( is_object( $website ) ) {
                    if ( ! property_exists( $website, $o->name ) ) {
                        $website->{$o->name} = $o->value;
                    }
                }
            }
        }
        return $arr_options;
    }

    /**
     * Update child site options.
     *
     * @param object $website Child site object.
     * @param mixed  $option  Option to update.
     * @param mixed  $value   Value to update with.
     */
    public function update_website_option( $website, $option, $value ) {

        if ( is_numeric( $website ) ) {
            $site_id = intval( $website );
        } else {
            $site_id = $website->id;
        }

        $rslt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $site_id ) );
        if ( empty( $rslt ) ) {
            $this->wpdb->insert(
                $this->table_name( 'wp_options' ),
                array(
                    'wpid'  => $site_id,
                    'name'  => $option,
                    'value' => $value,
                )
            );
        } else {
            $this->wpdb->update(
                $this->table_name( 'wp_options' ),
                array( 'value' => $value ),
                array(
                    'wpid' => $site_id,
                    'name' => $option,
                )
            );
        }
    }


    /**
     * Remove child site options.
     *
     * @param object $website Child site object.
     * @param mixed  $options  Option to update.
     */
    public function remove_website_option( $website, $options ) {

        if ( empty( $options ) ) {
            return;
        }

        if ( is_numeric( $website ) ) {
            $site_id = intval( $website );
        } else {
            $site_id = $website->id;
        }

        if ( ! is_array( $options ) ) {
            $options = (array) $options;
        }

        foreach ( $options as $opt ) {
            $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid=%d AND name=%s ', $site_id, $opt ) );

        }
    }


    /**
     * Get general Child site option.
     *
     * @param mixed $option  Child Site option name.
     *
     * @return string|null Database query result (as string), or null on failure.
     */
    private function get_general_website_option( $option ) {

        if ( null !== static::$general_options ) {
            if ( isset( static::$general_options[ $option ] ) ) {
                return static::$general_options[ $option ];
            }
        } else {
            static::$general_options[] = array();
        }

        $val = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', 0 ) );

        static::$general_options[ $option ] = $val;
        return $val;
    }

    /**
     * Get child site options.
     *
     * @param mixed $options Child site options name.
     *
     * @return string|null Database query result (as string), or null on failure.
     */
    public function get_general_options_array( $options ) {

        if ( ! is_array( $options ) || empty( $options ) ) {
            return array();
        }

        $return_options = array();
        if ( null !== static::$general_options ) {
            foreach ( static::$general_options as $opt => $val ) {
                if ( in_array( $opt, $options ) ) {
                    $return_options[ $opt ] = $val;
                }
            }
        } else {
            static::$general_options[] = array();
        }

        $diff_options = array();
        foreach ( $options as $opt ) {
            if ( ! isset( $return_options[ $opt ] ) ) {
                $diff_options[] = $opt;
            }
        }

        $options_name = implode( "','", $diff_options );
        $options_name = "'" . $options_name . "'";

        $options_db = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name, value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name IN (' . $options_name . ')', 0 ) );

        foreach ( (array) $options_db as $o ) {
            $return_options[ $o->name ]          = $o->value;
            static::$general_options[ $o->name ] = $o->value;
        }
        return $return_options;
    }

    /**
     * Update general site options.
     *
     * @param mixed  $option  Option to update.
     * @param mixed  $value   Value to update with.
     * @param string $type_value  Type values: single|array.
     */
    public function update_general_option( $option, $value, $type_value = 'single' ) {

        if ( 'array' === $type_value ) {
            if ( empty( $value ) ) {
                $value = array();
            } elseif ( ! is_array( $value ) ) {
                return false;
            }
            $value = wp_json_encode( $value );
        }

        if ( null === static::$general_options ) {
            static::$general_options[] = array();
        }
        static::$general_options[ $option ] = $value;

        $rslt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', 0 ) );

        if ( empty( $rslt ) ) {
            $this->wpdb->insert(
                $this->table_name( 'wp_options' ),
                array(
                    'wpid'  => 0,
                    'name'  => $option,
                    'value' => $value,
                )
            );
        } else {
            $this->wpdb->update(
                $this->table_name( 'wp_options' ),
                array( 'value' => $value ),
                array(
                    'wpid' => 0,
                    'name' => $option,
                )
            );
        }
        return true;
    }

    /**
     * Get general Child site option.
     *
     * @param mixed  $opt  Child Site option name.
     * @param string $type_value  Type values: single|array.
     *
     * @return string|null Database query result (as string), or null on failure.
     */
    public function get_general_option( $opt, $type_value = 'single' ) {
        if ( 'single' === $type_value ) {
            return $this->get_general_website_option( $opt );
        } elseif ( 'array' === $type_value ) {
            $json_value = $this->get_general_website_option( $opt );
            if ( empty( $json_value ) ) {
                return array();
            }
            return json_decode( $json_value, true );
        }
        return false;
    }

    /**
     * Get child sites by user ID.
     *
     * @param int    $userid       User ID.
     * @param bool   $selectgroups Selected groups.
     * @param null   $search_site  Site search field value.
     * @param string $orderBy      Order list by. Default: URL.
     *
     * @return array|object|null Database query results or null on failer.
     */
    public function get_websites_by_user_id( $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url' ) {
        return $this->get_results_result( $this->get_sql_websites_by_user_id( $userid, $selectgroups, $search_site, $orderBy ) );
    }

    /**
     * Get child sites.
     *
     * @return string SQL string.
     */
    public function get_sql_websites() {
        $where = $this->get_sql_where_allow_access_sites( 'wp' );

        return 'SELECT wp.*,wp_sync.*,wp_optionview.*
                FROM ' . $this->table_name( 'wp' ) . ' wp
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE 1 ' . $where;
    }

    /**
     * Get child sites by user id via SQL.
     *
     * @param int    $userid       Given user ID.
     * @param bool   $selectgroups Selected groups. Default: false.
     * @param null   $search_site  Site search field value. Default: null.
     * @param string $orderBy      Order list by. Default: URL.
     * @param bool   $offset       Query offset. Default: false.
     * @param bool   $rowcount     Row count. Default: falese.
     *
     * @return object|null Return database query or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function get_sql_websites_by_user_id( $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url', $offset = false, $rowcount = false ) {
        if ( MainWP_Utility::ctype_digit( $userid ) ) {
            $where = '';
            if ( null !== $search_site ) {
                $search_site = trim( $search_site );
                $where       = ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
            }

            $where .= $this->get_sql_where_allow_access_sites( 'wp' );

            if ( $selectgroups ) {
                $qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors
                FROM ' . $this->table_name( 'wp' ) . ' wp
                LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.userid = ' . $userid . "
                $where
                GROUP BY wp.id, wp_sync.sync_id
                ORDER BY " . $orderBy;
            } else {
                $qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*
                FROM ' . $this->table_name( 'wp' ) . ' wp
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.userid = ' . $userid . "
                $where
                ORDER BY " . $orderBy;
            }

            if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
                $qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
            } elseif ( false !== $rowcount ) {
                $qry .= ' LIMIT ' . $rowcount;
            }

            return $qry;
        }

        return null;
    }

    /**
     * Get SQL to get child sites for current user.
     *
     * @param bool   $selectgroups Selected groups. Default: false.
     * @param null   $search_site  Site search field value. Default: null.
     * @param string $orderBy      Order list by. Default: URL.
     * @param bool   $offset       Query offset. Default: false.
     * @param bool   $rowcount     Row count. Default: false.
     * @param null   $extraWhere   Extra WHERE. Default: null.
     * @param bool   $for_manager  For role manager. Default: false.
     * @param mixed  $extra_view   Extra view. Default favi_icon.
     * @param string $is_staging   yes|no Is child site a staging site.
     * @param array  $params   other params.
     *
     * @return object|null Database query results or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
     */
    public function get_sql_websites_for_current_user( // phpcs:ignore -- NOSONAR - complex.
        $selectgroups = false,
        $search_site = null,
        $orderBy = 'wp.url',
        $offset = false,
        $rowcount = false,
        $extraWhere = null,
        $for_manager = false,
        $extra_view = array( 'favi_icon' ),
        $is_staging = 'no',
        $params = array()
    ) {

        $where = '';
        if ( MainWP_System::instance()->is_multi_user() ) {

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            $where .= ' AND wp.userid = ' . $current_user->ID . ' ';
        }

        if ( null !== $search_site ) {
            $search_site = trim( $search_site );
            $where      .= ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
        }

        if ( ! empty( $extraWhere ) ) {
            $where .= ' AND ' . $extraWhere . ' ';
        }

        if ( ! $for_manager ) {
            $where .= $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
        }

        $connected_sql = '';

        if ( is_array( $params ) && isset( $params['connected'] ) && 'yes' === $params['connected'] ) {
            $connected_sql = ' AND wp_sync.sync_errors = "" ';
        } elseif ( is_array( $params ) && isset( $params['connected'] ) && 'no' === $params['connected'] ) {
            $connected_sql = '  AND wp_sync.sync_errors <> "" ';
        }

        $limit = '';
        if ( $params && is_array( $params ) ) {
            $s        = isset( $params['s'] ) ? $params['s'] : '';
            $exclude  = isset( $params['exclude'] ) ? wp_parse_id_list( $params['exclude'] ) : array();
            $include  = isset( $params['include'] ) ? wp_parse_id_list( $params['include'] ) : array();
            $status   = isset( $params['status'] ) ? wp_parse_list( $params['status'] ) : array();
            $page     = isset( $params['page'] ) ? intval( $params['page'] ) : false;
            $per_page = isset( $params['per_page'] ) ? intval( $params['per_page'] ) : false;

            if ( ! empty( $s ) ) {
                $where .= ' AND ( wp.id LIKE "%' . $this->escape( $s ) . '%" OR wp.name LIKE "%' . $this->escape( $s ) . '%" OR wp.url LIKE "%' . $this->escape( $s ) . '%" ) ';
            }

            if ( ! empty( $exclude ) ) {
                $where .= ' AND  wp.id NOT IN (' . implode( ',', $exclude ) . ') ';
            }

            if ( ! empty( $include ) ) {
                $where .= ' AND  wp.id IN (' . implode( ',', $include ) . ') ';
            }

            // any, connected, disconnected, suspended, available_update.
            if ( ! empty( $status ) && is_array( $status ) && ! in_array( 'any', $status ) ) {
                $status_conds = array();
                if ( in_array( 'available_update', $status ) ) {
                    $available_sql = " ( wp.plugin_upgrades <> '' &&  wp.plugin_upgrades <> '[]' ) OR (  wp.theme_upgrades <> '' &&  wp.theme_upgrades <> '[]'  ) OR (  wp.translation_upgrades <> '' &&  wp.translation_upgrades <> '[]' ) OR ( wp.premium_upgrades <> '' &&  wp.premium_upgrades <> '[]' ) ";
                    $results       = $this->wpdb->get_results( 'SELECT wpid FROM ' . $this->table_name( 'wp_options' ) . "  WHERE name = 'wp_upgrades' AND value <> '' AND value <> '[]' " );
                    if ( $results ) {
                        $wp_ids = array();
                        foreach ( $results as $item ) {
                            if ( ! empty( $item->wpid ) ) {
                                $wp_ids[] = $item->wpid;
                            }
                        }
                        $wp_ids = ! empty( $wp_ids ) ? array_unique( $wp_ids ) : array();
                        if ( ! empty( $wp_ids ) ) {
                            $available_sql .= ' OR wp.id IN ( ' . implode( ',', $wp_ids ) . ' )';
                        }
                    }
                    $status_conds[] = ' ( ' . $available_sql . ') ';
                }

                if ( in_array( 'connected', $status ) ) {
                    $status_conds[] = ' ( wp_sync.sync_errors == "" ) ';
                }
                if ( in_array( 'disconnected', $status ) ) {
                    $status_conds[] = " wp_sync.sync_errors <> '' ";
                }

                if ( in_array( 'suspended', $status ) ) {
                    $status_conds[] = ' wp.suspended = 1 ';
                }

                if ( ! empty( $status_conds ) ) {
                    $where .= ' AND ( ' . implode( ' OR ', $status_conds ) . ' ) ';
                }
            }

            if ( ! empty( $page ) && ! empty( $per_page ) ) {
                $limit = ( $page - 1 ) * $per_page . ',' . $per_page;
            }
        }

        if ( 'wp.url' === $orderBy ) {
            $orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')";
        }

        // wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
        if ( $selectgroups ) {
            $qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors,
            wpclient.name as client_name
            FROM ' . $this->table_name( 'wp' ) . ' wp
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
            LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $connected_sql . '
            GROUP BY wp.id, wp_sync.sync_id
            ORDER BY ' . $orderBy;
        } else {
            $qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, wpclient.name as client_name
            FROM ' . $this->table_name( 'wp' ) . ' wp
            LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $connected_sql . '
            GROUP BY wp.id, wp_sync.sync_id
            ORDER BY ' . $orderBy;
        }

        if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
            $qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
        } elseif ( false !== $rowcount ) {
            $qry .= ' LIMIT ' . $rowcount;
        } elseif ( ! empty( $limit ) ) {
            $qry .= ' LIMIT ' . $limit;
        } else {
            // load all sites so check to support limit sites loading.
            $limit_sites = ! empty( $params['limit_sites'] ) ? intval( $params['limit_sites'] ) : 0;
            if ( ! empty( $limit_sites ) ) {
                $current_page = (int) get_option( 'mainwp_manage_updates_limit_current_page', 0 );
                $current_page = $current_page > 0 ? $current_page - 1 : 0;
                $start        = $current_page * $limit_sites;
                $qry         .= ' LIMIT ' . intval( $start ) . ', ' . intval( $limit_sites );
            }
        }

        return $qry;
    }



    /**
     * Get SQL to get wp child sites for current user.
     *
     * @since 4.3
     *
     * @param array $params params .
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_sql_wp_for_current_user( $params = array() ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $selectgroups = ! empty( $params['select_groups'] ) ? true : false;
        $search_site  = isset( $params['search_site'] ) && ! empty( $params['search_site'] ) ? $params['search_site'] : null;
        $orderBy      = isset( $params['order_by'] ) && ! empty( $params['order_by'] ) ? $params['order_by'] : 'wp.url';
        $offset       = isset( $params['offset'] ) ? $params['offset'] : false;
        $rowcount     = isset( $params['row_count'] ) ? $params['row_count'] : false;
        $for_manager  = isset( $params['for_manager'] ) ? $params['for_manager'] : false;
        $extraWhere   = isset( $params['extra_where'] ) && ! empty( $params['extra_where'] ) ? $params['extra_where'] : null;
        $extra_view   = isset( $params['extra_view'] ) && is_array( $params['extra_view'] ) && ! empty( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
        $extra_join   = isset( $params['extra_join'] ) ? $params['extra_join'] : '';

        $extra_select_wp_fields  = isset( $params['extra_select_wp_fields'] ) && is_array( $params['extra_select_wp_fields'] ) && ! empty( $params['extra_select_wp_fields'] ) ? $params['extra_select_wp_fields'] : array();
        $extra_select_sql_fields = isset( $params['extra_select_sql_fields'] ) && ! empty( $params['extra_select_sql_fields'] ) ? $params['extra_select_sql_fields'] : '';

        $is_staging = isset( $params['is_staging'] ) && 'yes' === $params['is_staging'] ? 'yes' : 'no';
        $count_only = isset( $params['count_only'] ) && $params['count_only'] ? true : false;

        $where = '';

        if ( null !== $search_site ) {
            $search_site = trim( $search_site );
            $where      .= ' AND (wp.name LIKE "%' . $this->escape( $search_site ) . '%" OR wp.url LIKE  "%' . $this->escape( $search_site ) . '%") ';
        }

        if ( null !== $extraWhere ) {
            $where .= ' AND ' . $extraWhere;
        }

        if ( ! $for_manager ) {
            $where .= $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
        }

        if ( 'wp.url' === $orderBy ) {
            $orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')";
        }

        $select_wp_fields = $this->get_sql_select_wp_valid_fields( $extra_select_wp_fields );

        if ( ! empty( $extra_select_sql_fields ) ) {
            $extra_select_sql_fields = ',' . $extra_select_sql_fields;
        }

        // wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
        if ( $selectgroups ) {
            if ( $count_only ) {
                $select = ' COUNT(DISTINCT(wp.id)) ';
            } else {
                $select = $select_wp_fields . '
                ' . $extra_select_sql_fields . '
                ,wp_sync.sync_errors,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors, wpclient.name as client_name ';
            }
            $qry = 'SELECT ' . $select . '
            FROM ' . $this->table_name( 'wp' ) . ' wp
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
            LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid ' .
            $extra_join . '
            WHERE 1 ' . $where;
            if ( ! $count_only ) {
                $qry .= ' GROUP BY wp.id, wp_sync.sync_id';
            }
            $qry .= ' ORDER BY ' . $orderBy;
        } else {
            if ( $count_only ) {
                $select = ' COUNT(DISTINCT(wp.id)) ';
            } else {
                $select = $select_wp_fields . '
                ' . $extra_select_sql_fields . '
                ,wp_sync.sync_errors,wp_optionview.*, wpclient.name as client_name ';
            }
            $qry = 'SELECT ' . $select . '
            FROM ' . $this->table_name( 'wp' ) . ' wp
            LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid ' .
            $extra_join . '
            WHERE 1 ' . $where;
            if ( ! $count_only ) {
                $qry .= ' GROUP BY wp.id, wp_sync.sync_id';
            }
            $qry .= ' ORDER BY ' . $orderBy;
        }

        if ( ! $count_only ) {
            if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
                $qry .= ' LIMIT ' . intval( $offset ) . ', ' . intval( $rowcount );
            } elseif ( false !== $rowcount ) {
                $qry .= ' LIMIT ' . intval( $rowcount );
            }
        }
        return $qry;
    }
    /**
     * Get SQL select websites fields.
     *
     * @since 4.3
     *
     * @param array $other_fields extra select wp fields .
     *
     * @return string sql string.
     */
    public function get_sql_select_wp_valid_fields( $other_fields = array() ) {

        $allow_other_fields = array(
            'offline_checks_last',
            'offline_check_result', // 1 - online, -1 offline.
            'http_response_code',
            'disable_health_check',
            'health_threshold',
            'note',
            'statsUpdate',
            'directories',
            'plugin_upgrades',
            'theme_upgrades',
            'translation_upgrades',
            'premium_upgrades',
            'securityIssues',
            'themes',
            'ignored_themes',
            'plugins',
            'ignored_plugins',
            'users',
            'categories',
            'pluginDir',
            'automatic_update',
            'backup_before_upgrade',
            'mainwpdir',
            'is_ignoreCoreUpdates',
            'is_ignorePluginUpdates',
            'is_ignoreThemeUpdates',
            'verify_certificate',
            'force_use_ipv4',
            'ssl_version',
            'http_user',
            'http_pass',
            'wpe',
            'is_staging',
            'client_id',
        );

        $default_fields = array( 'id', 'url', 'name', 'adminname', 'verify_certificate', 'ssl_version', 'http_user', 'http_pass', 'suspended' );

        $select = ' ';

        foreach ( $default_fields as $field ) {
            $select .= 'wp.' . $this->escape( $field ) . ',';
        }
        foreach ( $other_fields as $field ) {
            if ( ! in_array( $field, $allow_other_fields ) ) {
                continue;
            }
            if ( in_array( $field, $default_fields ) ) {
                continue;
            }
            $select .= 'wp.' . $this->escape( $field ) . ',';
        }
        $select = rtrim( $select, ',' );
        return $select;
    }

    /**
     * Get child sites for current user.
     *
     * @param array $params to get sites. Default: array().
     *
     * @return array Results or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
     */
    public function get_websites_for_current_user( $params = array() ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $selectgroups = isset( $params['selectgroups'] ) ? $params['selectgroups'] : false;
        $search_site  = isset( $params['search_site'] ) ? $params['search_site'] : null;
        $orderBy      = isset( $params['order_by'] ) ? $params['order_by'] : 'wp.url';
        $offset       = isset( $params['offset'] ) ? $params['offset'] : false;
        $rowcount     = isset( $params['rowcount'] ) ? $params['rowcount'] : false;
        $extraWhere   = isset( $params['where'] ) ? $params['where'] : null;
        $extra_view   = isset( $params['extra_view'] ) && is_array( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
        $is_staging   = isset( $params['is_staging'] ) ? $params['is_staging'] : 'no';
        $full_data    = isset( $params['full_data'] ) && $params['full_data'] && ( 'no' !== $params['full_data'] ) ? true : false;
        $select_data  = isset( $params['select_data'] ) && is_array( $params['select_data'] ) ? $params['select_data'] : false;
        $format       = isset( $params['format'] ) ? $params['format'] : '';
        $clients      = isset( $params['client'] ) ? $params['client'] : '';
        $fields       = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();

        $for_manager = false;

        $urlsWhere = '';

        if ( isset( $params['urls'] ) && ! empty( $params['urls'] ) ) {
            $urls = explode( ';', $params['urls'] );
            foreach ( $urls as $url ) {
                $url = str_replace( array( 'https://www.', 'http://www.', 'https://', 'http://', 'www.' ), array( '', '', '', '', '' ), $url );
                if ( '/' !== substr( $url, - 1 ) ) {
                    $url .= '/';
                }
                $urlsWhere .= '"' . $this->escape( $url ) . '", ';
            }
            $urlsWhere = rtrim( $urlsWhere, ', ' );
        }

        if ( ! empty( $urlsWhere ) ) {
            $urlsWhere = " ( replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '') IN ( " . $urlsWhere . ') ) ';

            if ( empty( $extraWhere ) ) {
                $extraWhere = $urlsWhere;
            } else {
                $extraWhere = $extraWhere . ' AND ' . $urlsWhere;
            }
        }

        $clientWhere = '';
        if ( ! empty( $clients ) ) {
            $clients = explode( ';', $clients );
            foreach ( $clients as $client ) {
                if ( is_numeric( $client ) ) {
                    $clientWhere .= intval( $client ) . ', ';
                }
            }
            $clientWhere = rtrim( $clientWhere, ', ' );
        }

        if ( ! empty( $clientWhere ) ) {
            $clientWhere = ' ( wp.client_id IN ( ' . $clientWhere . ') ) ';
            if ( empty( $extraWhere ) ) {
                $extraWhere = $clientWhere;
            } else {
                $extraWhere = $extraWhere . ' AND ' . $clientWhere;
            }
        }

        $args = array(
            's'        => isset( $params['s'] ) ? $params['s'] : '',
            'exclude'  => isset( $params['exclude'] ) && ! empty( $params['exclude'] ) ? wp_parse_id_list( $params['exclude'] ) : array(),
            'include'  => isset( $params['include'] ) && ! empty( $params['include'] ) ? wp_parse_id_list( $params['include'] ) : array(),
            'status'   => isset( $params['status'] ) && ! empty( $params['status'] ) ? wp_parse_list( $params['status'] ) : '',
            'page'     => isset( $params['paged'] ) ? intval( $params['paged'] ) : false,
            'per_page' => isset( $params['items_per_page'] ) ? intval( $params['items_per_page'] ) : false,
        );

        $data = array( 'id', 'url', 'name', 'client_id' );

        if ( $full_data ) {
            $data = array(
                'id',
                'url',
                'name',
                'offline_checks_last',
                'offline_check_result', // 1 - online, -1 offline.
                'http_response_code',
                'disable_health_check',
                'health_threshold',
                'note',
                'dbsize',
                'plugin_upgrades',
                'theme_upgrades',
                'translation_upgrades',
                'securityIssues',
                'themes',
                'plugins',
                'automatic_update',
                'sync_errors',
                'dtsAutomaticSync',
                'dtsAutomaticSyncStart',
                'dtsSync',
                'dtsSyncStart',
                'last_post_gmt',
                'health_value',
                'phpversion',
                'wp_upgrades',
                'security_stats',
                'client_id',
                'adminname',
                'privkey',
                'http_user',
                'http_pass',
                'ssl_version',
                'signature_algo',
                'verify_method',
                'verify_certificate',
                'suspended',
            );

            if ( ! in_array( 'security_stats', $extra_view ) ) {
                $extra_view[] = 'security_stats';
            }
        }

        if ( ! empty( $select_data ) && is_array( $select_data ) ) {
            $data = $select_data;
        }

        if ( $selectgroups ) {
            $data[] = 'wpgroups';
            $data[] = 'wpgroupids';
        }

        if ( ! empty( $fields ) ) {
            $data = array_unique( array_merge( $fields, $data ) ); // to prevent difference fields name.
        }

        $dbwebsites = array();

        $sql      = $this->get_sql_websites_for_current_user( $selectgroups, $search_site, $orderBy, $offset, $rowcount, $extraWhere, $for_manager, $extra_view, $is_staging, $args );
        $websites = $this->query( $sql );

        while ( $websites && ( $website = static::fetch_object( $websites ) ) ) {

            $obj_data = MainWP_Utility::map_site( $website, $data );

            if ( $full_data ) {
                $sum_upgrades = 0;
                if ( '' !== $obj_data->plugin_upgrades ) {
                    $plugin_upgrades = json_decode( $obj_data->plugin_upgrades, true );
                    if ( is_array( $plugin_upgrades ) ) {
                        $sum_upgrades += count( $plugin_upgrades );
                    }
                }

                if ( '' !== $obj_data->theme_upgrades ) {
                    $theme_upgrades = json_decode( $obj_data->theme_upgrades, true );
                    if ( is_array( $theme_upgrades ) ) {
                        $sum_upgrades += count( $theme_upgrades );
                    }
                }

                if ( '' !== $obj_data->wp_upgrades ) {
                    $wp_upgrades = json_decode( $obj_data->wp_upgrades, true );
                    if ( is_array( $wp_upgrades ) ) {
                        $sum_upgrades += count( $wp_upgrades );
                    }
                }
                $obj_data->sum_of_upgrades = $sum_upgrades;
            }

            if ( 'array' === $format ) {
                $dbwebsites[] = $obj_data;
            } else {
                $dbwebsites[ $website->id ] = $obj_data;
            }
        }
        static::free_result( $websites );
        return $dbwebsites;
    }

    /**
     * Get the child sites the current user has searched for.
     *
     * @param array $params Query parameters.
     *
     * @return boolean|null $qry Database query results or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
     */
    public function get_sql_search_websites_for_current_user( $params ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $view           = isset( $params['view'] ) ? $params['view'] : 'default'; // must be default to compatible with get_option_view().
        $selectgroups   = isset( $params['selectgroups'] ) && $params['selectgroups'] ? true : false;
        $search_site    = isset( $params['search'] ) ? $this->escape( trim( $params['search'] ) ) : null;
        $orderBy        = isset( $params['orderby'] ) ? $params['orderby'] : 'wp.url';
        $offset         = isset( $params['offset'] ) ? intval( $params['offset'] ) : false;
        $rowcount       = isset( $params['rowcount'] ) ? intval( $params['rowcount'] ) : false;
        $extraWhere     = isset( $params['extra_where'] ) ? $params['extra_where'] : null; // without AND prefix.
        $for_manager    = isset( $params['for_manager'] ) && $params['for_manager'] ? true : false;
        $extra_view     = isset( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
        $is_staging     = isset( $params['is_staging'] ) && 'yes' === $params['is_staging'] ? 'yes' : 'no';
        $is_count       = isset( $params['count_only'] ) && $params['count_only'] ? true : false;
        $group_ids      = isset( $params['group_id'] ) && ! empty( $params['group_id'] ) ? $params['group_id'] : array();
        $client_ids     = isset( $params['client_id'] ) && ! empty( $params['client_id'] ) ? $params['client_id'] : array();
        $is_not         = isset( $params['isnot'] ) && ! empty( $params['isnot'] ) ? true : false;
        $selected_sites = isset( $params['selected_sites'] ) ? $params['selected_sites'] : array();

        if ( ! is_array( $group_ids ) ) {
            $group_ids = array();
        }

        // valid group ids.
        $group_ids = array_filter(
            $group_ids,
            function ( $e ) {
                if ( 'nogroups' === $e ) {
                    return true;
                }
                return ( is_numeric( $e ) && 0 < $e ) ? true : false;
            }
        );

        if ( ! is_array( $client_ids ) ) {
            $client_ids = array();
        }

        // valid group ids.
        $client_ids = array_filter(
            $client_ids,
            function ( $e ) {
                if ( 'noclients' === $e ) {
                    return true;
                }
                return is_numeric( $e ) && ! empty( $e ) ? true : false; // to valid client ids.
            }
        );

        if ( $selectgroups ) {
            $staging_group = get_option( 'mainwp_stagingsites_group_id' );
            if ( $staging_group && in_array( $staging_group, $group_ids ) ) {
                if ( empty( $group_ids ) ) {
                    $is_staging = 'yes';
                } else {
                    $is_staging = 'nocheckstaging';
                }
            }
        }

        if ( ! is_array( $selected_sites ) ) {
            $selected_sites = array();
        }
        $selected_sites = MainWP_Utility::array_numeric_filter( $selected_sites );

        $where = '';
        if ( MainWP_System::instance()->is_multi_user() ) {

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            $where .= ' AND wp.userid = ' . $current_user->ID . ' ';
        }

        if ( ! empty( $selected_sites ) ) {
            $where .= ' AND wp.id IN (' . implode( ',', $selected_sites ) . ') ';
        }

        // for searching.
        if ( null !== $search_site && '' !== $search_site ) {
            $where .= ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
        }

        if ( null !== $extraWhere ) {
            $where .= ' AND ' . $extraWhere;
        }

        $staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php' );
        if ( ! $staging_enabled ) {
            $is_staging = 'no';
        }

        if ( ! $for_manager ) {
            $where .= $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
        }

        if ( $is_count ) {
            $orderBy = '';
        } elseif ( 'wp.url' === $orderBy ) {
            $orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')";
        }

        if ( ! empty( $orderBy ) ) {
            $orderBy = ' ORDER BY ' . $orderBy;
        }

        $join_group  = '';
        $where_group = '';

        if ( in_array( 'nogroups', $group_ids ) ) {
            $join_group = ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
            $group_ids  = array_filter(
                $group_ids,
                function ( $e ) {
                    return 'nogroups' !== $e;
                }
            );
            if ( ! empty( $group_ids ) ) {
                $groups = implode( ',', $group_ids );
                if ( $is_not ) {
                    $where_group = ' AND wpgroup.groupid IS NOT NULL AND wpgroup.groupid NOT IN (' . $groups . ') ';
                    // to fix.
                    $sub_select_is_not = ' SELECT wp.id FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . $groups . ') ';
                    $where_group      .= ' AND wp.id NOT IN ( ' . $sub_select_is_not . ' ) ';
                } else {
                    $where_group = ' AND ( wpgroup.groupid IS NULL OR wpgroup.groupid IN (' . $groups . ') ) ';
                }
            } elseif ( $is_not ) {
                    $where_group = ' AND wpgroup.groupid IS NOT NULL ';
            } else {
                $where_group = ' AND wpgroup.groupid IS NULL ';
            }
        } elseif ( $group_ids ) {
            $groups = implode( ',', $group_ids );
            if ( $is_not ) {
                $join_group  = ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
                $where_group = ' AND ( wpgroup.groupid NOT IN (' . $groups . ') OR wpgroup.groupid IS NULL ) ';
                // to fix.
                $sub_select_is_not = ' SELECT wp.id FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . $groups . ') ';
                $where_group      .= ' AND wp.id NOT IN ( ' . $sub_select_is_not . ' ) ';
            } else {
                $join_group  = ' JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
                $where_group = ' AND wpgroup.groupid IN (' . $groups . ') ';
            }
        }

        $select_groups_belong = '';

        if ( ! $is_count && $group_ids ) {
            $select_groups_belong = $this->get_select_groups_belong();
        }

        $join_client  = '';
        $where_client = '';

        if ( in_array( 'noclients', $client_ids ) ) {
            $join_client = ' LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
            $client_ids  = array_filter(
                $client_ids,
                function ( $e ) {
                    return 'noclients' !== $e;
                }
            );
            if ( ! empty( $client_ids ) ) {
                $clients = implode( ',', $client_ids );
                if ( $is_not ) {
                    $where_client = ' AND wpclient.client_id IS NOT NULL AND wp.client_id NOT IN (' . $clients . ') ';
                } else {
                    $where_client = ' AND wpclient.client_id IN (' . $clients . ') ';
                }
            } elseif ( $is_not ) {
                    $where_client = ' AND wpclient.client_id IS NOT NULL ';
            } else {
                $where_client = ' AND wpclient.client_id IS NULL ';
            }
        } elseif ( $client_ids && ! empty( $client_ids ) ) {
            $clients = implode( ',', $client_ids );
            if ( $is_not ) {
                $join_client  = ' LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
                $where_client = ' AND ( wpclient.client_id NOT IN (' . $clients . ') OR wpclient.client_id IS NULL ) ';
            } else {
                $join_client  = ' JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
                $where_client = ' AND wpclient.client_id IN (' . $clients . ') ';
            }
        }

        if ( '' === $join_client ) {
            $join_client = ' LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
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

        $join_monitors = '';

        $select_fields = array(
            'wp.*',
            'wp_sync.*',
        );

        if ( 'light_view' === $view ) {
            $select_fields = $light_fields;
        } elseif ( 'monitor_view' === $view ) {
            $select_fields   = $light_fields;
            $select_fields[] = 'mo.*';
            $join_monitors   = ' LEFT JOIN ' . $this->table_name( 'monitors' ) . ' mo ON wp.id = mo.wpid AND mo.issub = 0  ';
        } elseif ( 'manage_site' === $view ) {
            $select_fields[] = 'mo.monitor_id';
            $join_monitors   = ' LEFT JOIN ' . $this->table_name( 'monitors' ) . ' mo ON wp.id = mo.wpid AND mo.issub = 0  ';
        }

        $select = implode( ',', $select_fields );

        // wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
        if ( $selectgroups ) {

            if ( empty( $join_group ) ) {
                $join_group = ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
            }

            $qry = 'SELECT ' . $select . ', wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors, wpclient.name as client_name ' .
            $select_groups_belong . ' FROM ' . $this->table_name( 'wp' ) . ' wp ' .
            $join_client . ' ' .
            $join_group .
            $join_monitors . '
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgroup.groupid = gr.id

            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view, $view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $where_group . $where_client . '
            GROUP BY wp.id, wp_sync.sync_id ' .
            $orderBy;
        } else {
            $qry = 'SELECT ' . $select . ', wp_optionview.*, wpclient.name as client_name ' .
            $select_groups_belong . ' FROM ' . $this->table_name( 'wp' ) . ' wp ' .
            $join_group . ' ' .
            $join_client .
            $join_monitors . '
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view, $view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $where_group . $where_client . '
            GROUP BY wp.id, wp_sync.sync_id ' .
            $orderBy;
        }

        if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
            $qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
        } elseif ( false !== $rowcount ) {
            $qry .= ' LIMIT ' . $rowcount;
        }

        if ( ! empty( $params['dev_log_query'] ) ) {
            error_log( $qry ); //phpcs:ignore -- NOSONAR - for dev.
        }

        return $qry;
    }

    /**
     * Get child sites where allowed access via SQL.
     *
     * @param string $site_table_alias Child site table alias.
     * @param string $is_staging       yes|no Is child site a staging site.
     *
     * @return boolean|null $_where Database query results or null on failure.
     */
    public function get_sql_where_allow_access_sites( $site_table_alias = '', $is_staging = 'no' ) { // phpcs:ignore -- NOSONAR - complex.

        if ( empty( $site_table_alias ) ) {
            $site_table_alias = $this->table_name( 'wp' );
        }

        // check to filter the staging sites.
        $where_staging = ' AND ' . $site_table_alias . '.is_staging = 0 ';
        if ( 'no' === $is_staging ) {
            $where_staging = ' AND ' . $site_table_alias . '.is_staging = 0 ';
        } elseif ( 'yes' === $is_staging ) {
            $where_staging = ' AND ' . $site_table_alias . '.is_staging = 1 ';
        } elseif ( 'nocheckstaging' === $is_staging ) {
            $where_staging = '';
        }
        // end staging filter.

        $_where = $where_staging;
        // To fix bug run from cron job.
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return $_where;
        }

        // To fix bug run from wp cli.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return $_where;
        }

        // Run from Rest Api.
        if ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) {
            return $_where;
        }

        /**
         * Filter: mainwp_currentuserallowedaccesssites
         *
         * Filters allowed sites for the current user.
         *
         * @since Unknown
         */
        $allowed_sites = apply_filters( 'mainwp_currentuserallowedaccesssites', 'all' );

        if ( 'all' === $allowed_sites ) {
            return $_where;
        }

        if ( is_array( $allowed_sites ) && ! empty( $allowed_sites ) ) {
            // valid group ids.
            $allowed_sites = array_filter(
                $allowed_sites,
                function ( $e ) {
                    return is_numeric( $e ) ? true : false;
                }
            );
            $_where       .= ' AND ' . $site_table_alias . '.id IN (' . implode( ',', $allowed_sites ) . ') ';
        } else {
            $_where .= ' AND 0 ';
        }

        return $_where;
    }

    /**
     * Get groupd where allowed access via SQL.
     *
     * @param string $group_table_alias Child site table alias.
     * @param string $with_staging      yes|no Is child site a staging site.
     *
     * @return boolean|null $_where Database query results or null on failer.
     */
    public function get_sql_where_allow_groups( $group_table_alias = '', $with_staging = 'no' ) { // phpcs:ignore -- NOSONAR - complex.

        if ( empty( $group_table_alias ) ) {
            $group_table_alias = $this->table_name( 'group' );
        }

        // check to filter the staging group.
        $where_staging_group = '';
        $staging_group       = get_option( 'mainwp_stagingsites_group_id' );
        if ( $staging_group ) {
            $where_staging_group = ' AND ' . $group_table_alias . '.id <> ' . $staging_group . ' ';
            if ( 'yes' === $with_staging ) {
                $where_staging_group = '';
            }
        }

        // end staging filter.
        $_where = $where_staging_group;

        // To fix bug run from cron job.
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return $_where;
        }

        // Run from wp cli.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return $_where;
        }

        // Run from Rest Api.
        if ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) {
            return $_where;
        }

        /**
         * Filter: mainwp_currentuserallowedaccessgroups
         *
         * Filters allowed groups for the current user.
         *
         * @since Unknown
         */
        $allowed_groups = apply_filters( 'mainwp_currentuserallowedaccessgroups', 'all' );

        if ( 'all' === $allowed_groups ) {
            return $_where;
        }

        if ( is_array( $allowed_groups ) && ! empty( $allowed_groups ) ) {

            // valid group ids.
            $allowed_groups = array_filter(
                $allowed_groups,
                function ( $e ) {
                    return is_numeric( $e ) ? true : false;
                }
            );

            return ' AND ' . $group_table_alias . '.id IN (' . implode( ',', $allowed_groups ) . ') ' . $_where;
        } else {
            return ' AND 0 ';
        }
    }


    /**
     * Get child site by id and params.
     *
     * @param int    $id           Child site ID.
     * @param array  $params params.
     * @param string $obj OBJECT|ARRAY_A.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_website_by_id_params( $id, $params = array(), $obj = OBJECT ) {
        return $this->get_row_result( $this->get_sql_website_by_params( $id, $params ), $obj );
    }

    /**
     * Get sql child site by id and params.
     *
     * @param int   $id           Child site ID.
     * @param array $params params.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_sql_website_by_params( $id, $params = array() ) {

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $select_groups = ! empty( $params['select_groups'] ) ? true : false;

        $view        = ! empty( $params['view'] ) ? $params['view'] : 'simple_view';
        $view_fields = isset( $params['view_fields'] ) ? $params['view_fields'] : array();

        if ( is_string( $view_fields ) ) {
            $view_fields = (array) $view_fields;
        } elseif ( ! is_array( $view_fields ) ) {
            $view_fields = array();
        }

        if ( MainWP_Utility::ctype_digit( $id ) ) {
            $where = $this->get_sql_where_allow_access_sites( 'wp', 'nocheckstaging' );
            if ( $select_groups ) {
                return 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors
                FROM ' . $this->table_name( 'wp' ) . ' wp
                LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view_by( $view, $view_fields ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.id = ' . $id . $where . '
                GROUP BY wp.id, wp_sync.sync_id';
            }

            return 'SELECT wp.*,wp_sync.*,wp_optionview.*
                    FROM ' . $this->table_name( 'wp' ) . ' wp
                    JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                    JOIN ' . $this->get_option_view_by( $view, $view_fields ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                    WHERE id = ' . $id . $where;
        }
        return null;
    }

    /**
     * Get child site by id.
     *
     * @param int   $id           Child site ID.
     * @param array $selectGroups Select groups.
     * @param array $extra_view       Get extra option fields.
     * @param int   $obj OBJECT|ARRAY_A.
     *
     * @return object|null Database query results or null on failure.
     */
    public function get_website_by_id( $id, $selectGroups = false, $extra_view = array(), $obj = OBJECT ) {
        return $this->get_row_result( $this->get_sql_website_by_id( $id, $selectGroups, $extra_view ), $obj );
    }

    /**
     * Get child site by id via SQL.
     *
     * @param int   $id           Child site ID.
     * @param bool  $selectGroups Selected groups.
     * @param mixed $extra_view   Extra view value.
     *
     * @return object|null Database query result or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function get_sql_website_by_id( $id, $selectGroups = false, $extra_view = array() ) {

        if ( ! is_array( $extra_view ) || empty( $extra_view ) ) {
            $extra_view = array( 'favi_icon', 'site_info' );
        }

        if ( MainWP_Utility::ctype_digit( $id ) ) {
            $where = $this->get_sql_where_allow_access_sites( 'wp', 'nocheckstaging' );
            if ( $selectGroups ) {
                return 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors
                FROM ' . $this->table_name( 'wp' ) . ' wp
                LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.id = ' . $id . $where . '
                GROUP BY wp.id, wp_sync.sync_id';
            }

            return 'SELECT wp.*,wp_sync.*,wp_optionview.*
                    FROM ' . $this->table_name( 'wp' ) . ' wp
                    JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                    JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                    WHERE id = ' . $id . $where;
        }

        return null;
    }

    /**
     * Method get_websites_by_ids()
     *
     * Get child sites by child site IDs.
     *
     * @param array $ids Child site IDs.
     * @param int   $userId User ID.
     *
     * @return object|null Database query result or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
     */
    public function get_websites_by_ids( $ids, $userId = null ) {
        if ( ( null === $userId ) && MainWP_System::instance()->is_multi_user() ) {

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            $userId = $current_user->ID;
        }

        // valid group ids.
        $ids = array_filter(
            $ids,
            function ( $e ) {
                return ( is_numeric( $e ) && 0 < $e ) ? true : false;
            }
        );

        $where = $this->get_sql_where_allow_access_sites();

        return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' . ( null !== $userId ? ' AND userid = ' . intval( $userId ) : '' ) . $where, OBJECT );
    }

    /**
     * Get child sites by groups IDs.
     *
     * @param array $ids    Groups IDs.
     * @param int   $userId User ID.
     * @param array $fields array fields .
     *
     * @return object|null Database query result or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
     */
    public function get_websites_by_group_ids( $ids, $userId = null, $fields = array() ) {
        if ( empty( $ids ) ) {
            return array();
        }
        if ( ( null === $userId ) && MainWP_System::instance()->is_multi_user() ) {

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            $userId = $current_user->ID;
        }

        // valid group ids.
        $group_ids = array_filter(
            $ids,
            function ( $e ) {
                return is_numeric( $e ) ? true : false;
            }
        );

        $select = '*';
        if ( ! empty( $fields ) && is_array( $fields ) ) {
            $fields = array_filter( array_map( 'trim', $fields ) );
            if ( $fields ) {
                $select = '';
                foreach ( $fields as $field ) {
                    $select .= $this->escape( $field ) . ',';
                }
                $select = rtrim( $select, ',' );
            }
        }
        return $this->wpdb->get_results( 'SELECT ' . $select . ' FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . implode( ',', $group_ids ) . ') ' . ( null !== $userId ? ' AND wp.userid = ' . intval( $userId ) : '' ), OBJECT );
    }

    /**
     * Get child sites by group ID.
     *
     * @param int $id Group ID.
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_websites_by_group_id( $id ) {
        return $this->get_results_result( $this->get_sql_websites_by_group_id( $id ) );
    }

    /**
     * Get child sites by group id via SQL.
     *
     * @param int    $id           Group ID.
     * @param bool   $selectgroups Selected groups. Default: false.
     * @param string $orderBy      Order list by. Default: URL.
     * @param bool   $offset       Query offset. Default: false.
     * @param bool   $rowcount     Row count. Default: falese.
     * @param null   $where        SQL WHERE value.
     * @param null   $search_site  Site search field value. Default: null.
     * @param array  $others  Others params.
     *
     * @return object|null Return database query or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function get_sql_websites_by_group_id( // phpcs:ignore -- NOSONAR - complex.
        $id,
        $selectgroups = false,
        $orderBy = 'wp.url',
        $offset = false,
        $rowcount = false,
        $where = null,
        $search_site = null,
        $others = array()
    ) {

        $is_staging = 'no';
        if ( $selectgroups ) {
            $staging_group = get_option( 'mainwp_stagingsites_group_id' );
            if ( $staging_group && $id === $staging_group ) {
                $is_staging = 'yes';
            }
        }

        $where_search = '';
        if ( ! empty( $search_site ) ) {
            $search_site   = trim( $search_site );
            $where_search .= ' AND (wp.name LIKE "%' . $this->escape( $search_site ) . '%" OR wp.url LIKE  "%' . $this->escape( $search_site ) . '%") ';
        }

        $extra_view = is_array( $others ) && isset( $others['extra_view'] ) && is_array( $others['extra_view'] ) && ! empty( $others['extra_view'] ) ? $others['extra_view'] : array( 'site_info' );

        if ( MainWP_Utility::ctype_digit( $id ) ) {
            $where_allowed = $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
            if ( $selectgroups ) {
                $qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, GROUP_CONCAT(gr.color ORDER BY gr.name SEPARATOR ",") as wpgroups_colors
                 FROM ' . $this->table_name( 'wp' ) . ' wp
                 JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                 LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                 LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                 JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                 JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                 WHERE wpgroup.groupid = ' . $id . ' ' .
                ( empty( $where ) ? '' : ' AND ' . $where ) . $where_allowed . $where_search . '
                 GROUP BY wp.id, wp_sync.sync_id
                 ORDER BY ' . $orderBy;
            } else {
                $qry = 'SELECT wp.*,wp_optionview.*, wp_sync.* FROM ' . $this->table_name( 'wp' ) . ' wp
                        JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                        JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                        JOIN ' . $this->get_option_view( $extra_view, 'group' ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                        WHERE wpgroup.groupid = ' . $id . ' ' . $where_allowed . $where_search .
                ( empty( $where ) ? '' : ' AND ' . $where ) . ' ORDER BY ' . $orderBy;
            }
            if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
                $qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
            } elseif ( false !== $rowcount ) {
                $qry .= ' LIMIT ' . $rowcount;
            }

            return $qry;
        }

        return null;
    }

    /**
     * Get child sites by group name.
     *
     * @param int    $userid Current user ID.
     * @param string $groupname Group name.
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_websites_by_group_name( $userid, $groupname ) {
        return $this->get_results_result( $this->get_sql_websites_by_group_name( $groupname, $userid ) );
    }

    /**
     * Get child sites by group name.
     *
     * @param string $groupname Group name.
     * @param int    $userid    Current user ID.
     *
     * @return object|null Database query result or null on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
     */
    public function get_sql_websites_by_group_name( $groupname, $userid = null ) {
        if ( ( null === $userid ) && MainWP_System::instance()->is_multi_user() ) {

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            $userid = $current_user->ID;
        }

        $sql = 'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp
                INNER JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                JOIN ' . $this->table_name( 'group' ) . ' g ON wpgroup.groupid = g.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE g.name="' . $this->escape( $groupname ) . '"';
        if ( null !== $userid ) {
            $sql .= ' AND g.userid = "' . intval( $userid ) . '"';
        }

        return $sql;
    }

    /**
     * Get child site IP address.
     *
     * @param int $wpid Child site ID.
     *
     * @return string|null Child site IP address or null on failure.
     */
    public function get_wp_ip( $wpid ) {
        return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ip FROM ' . $this->table_name( 'request_log' ) . ' WHERE wpid = %d', $wpid ) );
    }

    /**
     * Add website to the MainWP Dashboard.
     *
     * @param int    $userid Current user ID.
     * @param string $name Child site name.
     * @param string $url Child site URL.
     * @param string $admin Child site administrator username.
     * @param string $pubkey OpenSSL public key.
     * @param string $privkey OpenSSL private key.
     * @param array  $params Other params.
     *
     * @return int|false Child site ID or false on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function add_website( // phpcs:ignore -- NOSONAR - complex.
        $userid,
        $name,
        $url,
        $admin,
        $pubkey,
        $privkey,
        $params = array()
    ) {

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $groupids          = isset( $params['groupids'] ) ? $params['groupids'] : array();
        $groupnames        = isset( $params['groupnames'] ) ? $params['groupnames'] : array();
        $verifyCertificate = isset( $params['verifyCertificate'] ) ? (int) $params['verifyCertificate'] : 2;
        $uniqueId          = isset( $params['uniqueId'] ) ? $params['uniqueId'] : '';
        $http_user         = isset( $params['http_user'] ) ? $params['http_user'] : null;
        $http_pass         = isset( $params['http_pass'] ) ? $params['http_pass'] : null;
        $sslVersion        = isset( $params['sslVersion'] ) ? $params['sslVersion'] : 0;
        $wpe               = isset( $params['wpe'] ) ? $params['wpe'] : 0;
        $isStaging         = isset( $params['isStaging'] ) ? $params['isStaging'] : 0;

        if ( MainWP_Utility::ctype_digit( $userid ) ) {
            if ( '/' !== substr( $url, - 1 ) ) {
                $url .= '/';
            }

            $en_pk_data = MainWP_Encrypt_Data_Lib::instance()->encrypt_privkey( base64_decode( $privkey ) ); // phpcs:ignore -- NOSONAR - base64_encode trust.
            $en_privkey = isset( $en_pk_data['en_data'] ) ? $en_pk_data['en_data'] : '';

            $values = array(
                'userid'                => $userid,
                'adminname'             => $this->escape( $admin ),
                'name'                  => $this->escape( wp_strip_all_tags( $name ) ),
                'url'                   => $this->escape( $url ),
                'pubkey'                => $this->escape( $pubkey ),
                'privkey'               => $this->escape( base64_encode( $en_privkey ) ), // phpcs:ignore -- NOSONAR - trust.
                'siteurl'               => '',
                'ga_id'                 => '',
                'gas_id'                => 0,
                'offline_checks_last'   => 0,
                'offline_check_result'  => 0,
                'note'                  => '',
                'statsUpdate'           => 0,
                'directories'           => '',
                'plugin_upgrades'       => '',
                'theme_upgrades'        => '',
                'translation_upgrades'  => '',
                'securityIssues'        => '',
                'premium_upgrades'      => '',
                'themes'                => '',
                'ignored_themes'        => '',
                'plugins'               => '',
                'ignored_plugins'       => '',
                'users'                 => '',
                'categories'            => '',
                'pluginDir'             => '',
                'automatic_update'      => 0,
                'backup_before_upgrade' => 2,
                'verify_certificate'    => intval( $verifyCertificate ),
                'ssl_version'           => $sslVersion,
                'uniqueId'              => $uniqueId,
                'mainwpdir'             => 0,
                'http_user'             => $http_user,
                'http_pass'             => $http_pass,
                'wpe'                   => $wpe,
                'is_staging'            => $isStaging,
            );

            $syncValues = array(
                'dtsSync'               => 0,
                'dtsSyncStart'          => 0,
                'dtsAutomaticSync'      => 0,
                'dtsAutomaticSyncStart' => 0,
                'totalsize'             => 0,
                'extauth'               => '',
                'sync_errors'           => '',
            );
            if ( $this->wpdb->insert( $this->table_name( 'wp' ), $values ) ) {
                $websiteid = $this->wpdb->insert_id;
                MainWP_Encrypt_Data_Lib::instance()->encrypt_save_keys( $websiteid, $en_pk_data );
                $syncValues['wpid'] = $websiteid;
                $this->wpdb->insert( $this->table_name( 'wp_sync' ), $syncValues );
                $this->wpdb->insert(
                    $this->table_name( 'wp_settings_backup' ),
                    array(
                        'wpid'          => $websiteid,
                        'archiveFormat' => 'global',
                    )
                );

                foreach ( $groupnames as $groupname ) {
                    if ( $this->wpdb->insert(
                        $this->table_name( 'group' ),
                        array(
                            'userid' => $userid,
                            'name'   => $this->escape( htmlspecialchars( $groupname ) ),
                        )
                    )
                    ) {
                        $groupids[] = $this->wpdb->insert_id;
                    }
                }
                // add groupids.
                foreach ( $groupids as $groupid ) {
                    $this->wpdb->insert(
                        $this->table_name( 'wp_group' ),
                        array(
                            'wpid'    => $websiteid,
                            'groupid' => $groupid,
                        )
                    );
                }

                return $websiteid;
            }
        }

        return false;
    }

    /**
     * Remove child site from the MainWP Dashboard.
     *
     * @param int $websiteid Child site ID.
     *
     * @return int|boolean Return child site ID that was removed or false on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function remove_website( $websiteid ) {
        if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
            $nr = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp' ) . ' WHERE id=%d', $websiteid ) );
            $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_group' ) . ' WHERE wpid=%d', $websiteid ) );
            $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_sync' ) . ' WHERE wpid=%d', $websiteid ) );
            $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid=%d', $websiteid ) );
            MainWP_Encrypt_Data_Lib::remove_key_file( $websiteid );
            MainWP_DB_Uptime_Monitoring::instance()->delete_monitor( array( 'wpid' => $websiteid ) );
            return $nr;
        }

        return false;
    }

    /**
     * Update child site db values.
     *
     * @param int   $websiteid Child site ID.
     * @param array $fields    Database fields to update.
     *
     * @return int|boolean The number of rows updated, or false on error.
     */
    public function update_website_values( $websiteid, $fields ) {
        if ( ! empty( $fields ) ) {
            // Lock the data stream to prevent other processes from updating at the same time.
            $sql = $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp' ) . ' WHERE id = %d FOR UPDATE',
                $websiteid
            );
            $this->wpdb->get_row( $sql );

            return $this->wpdb->update( $this->table_name( 'wp' ), $fields, array( 'id' => $websiteid ) );
        }

        return false;
    }

    /**
     * Update child site sync values.
     *
     * @param int   $websiteid Child site ID.
     * @param array $fields    Database fields to update.
     *
     * @return int|boolean The number of rows updated, or false on error.
     */
    public function update_website_sync_values( $websiteid, $fields ) {
        if ( ! empty( $fields ) ) {
            return $this->wpdb->update( $this->table_name( 'wp_sync' ), $fields, array( 'wpid' => $websiteid ) );
        }

        return false;
    }

    /**
     * Update child site.
     *
     * @param int    $websiteid Website ID.
     * @param string $url Child site URL.
     * @param int    $userid Current user ID.
     * @param string $name Child site name.
     * @param string $siteadmin Child site administrator username.
     * @param array  $groupids Group IDs.
     * @param array  $groupnames Group Names.
     * @param string $pluginDir Plugin directory.
     * @param mixed  $maximumFileDescriptorsOverride Overwrite the Maximum File Descriptors option.
     * @param mixed  $maximumFileDescriptorsAuto Auto set the Maximum File Descriptors option.
     * @param mixed  $maximumFileDescriptors Set the Maximum File Descriptors option.
     * @param int    $verifyCertificate Whether or not to verify SSL Certificate.
     * @param mixed  $archiveFormat Backup archive formate.
     * @param string $uniqueId Unique security ID.
     * @param string $http_user HTTP Basic Authentication username.
     * @param string $http_pass HTTP Basic Authentication password.
     * @param int    $sslVersion SSL Version.
     * @param bool   $disableHealthChecking Disable Site health threshold.
     * @param int    $healthThreshold Site health threshold.
     * @param string $backup_method Primary backup method.
     *
     * @return boolean ture on success or false on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_website( // phpcs:ignore -- NOSONAR - complex.
        $websiteid,
        $url,
        $userid,
        $name,
        $siteadmin,
        $groupids,
        $groupnames,
        $pluginDir,
        $maximumFileDescriptorsOverride,
        $maximumFileDescriptorsAuto,
        $maximumFileDescriptors,
        $verifyCertificate = 1,
        $archiveFormat = 'global',
        $uniqueId = '',
        $http_user = null,
        $http_pass = null,
        $sslVersion = 0,
        $disableHealthChecking = 1,
        $healthThreshold = 0,
        $backup_method = 'global'
    ) {

        $wpe = 0; // going to update when sync.

        if ( MainWP_Utility::ctype_digit( $websiteid ) && MainWP_Utility::ctype_digit( $userid ) ) {
            $website = $this->get_website_by_id( $websiteid );
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                // update admin.
                $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET url="' . $this->escape( $url ) . '", name="' . $this->escape( wp_strip_all_tags( $name ) ) . '", adminname="' . $this->escape( $siteadmin ) . '",pluginDir="' . $this->escape( $pluginDir ) . '", verify_certificate="' . intval( $verifyCertificate ) . '", ssl_version="' . intval( $sslVersion ) . '", wpe="' . intval( $wpe ) . '", uniqueId="' . $this->escape( $uniqueId ) . '", http_user="' . $this->escape( $http_user ) . '", http_pass="' . $this->escape( $http_pass ) . '", disable_health_check="' . $this->escape( $disableHealthChecking ) . '", health_threshold="' . $this->escape( $healthThreshold ) . '", primary_backup_method="' . $this->escape( $backup_method ) . '" WHERE id=%d', $websiteid ) );
                $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp_settings_backup' ) . ' SET archiveFormat = "' . $this->escape( $archiveFormat ) . '" WHERE wpid=%d', $websiteid ) );

                if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
                    $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET maximumFileDescriptorsOverride = ' . ( $maximumFileDescriptorsOverride ? 1 : 0 ) . ',maximumFileDescriptorsAuto= ' . ( $maximumFileDescriptorsAuto ? 1 : 0 ) . ',maximumFileDescriptors = ' . $maximumFileDescriptors . ' WHERE id=%d', $websiteid ) );
                }

                // remove groups.
                $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_group' ) . ' WHERE wpid=%d', $websiteid ) );
                // Remove GA stats.
                $showErrors = $this->wpdb->hide_errors();

                /**
                 * Action: mainwp_ga_delete_site
                 *
                 * Fires upon site removal process in order to delete Google Analytics data.
                 *
                 * @param int $websiteid Child site ID.
                 *
                 * @since Unknown
                 */
                do_action( 'mainwp_ga_delete_site', $websiteid );

                if ( $showErrors ) {
                    $this->wpdb->show_errors();
                }
                // add groups with groupnames.
                foreach ( $groupnames as $groupname ) {
                    if ( $this->wpdb->insert(
                        $this->table_name( 'group' ),
                        array(
                            'userid' => $userid,
                            'name'   => $this->escape( $groupname ),
                        )
                    )
                    ) {
                        $groupids[] = $this->wpdb->insert_id;
                    }
                }
                // add groupids.
                foreach ( $groupids as $groupid ) {
                    $this->wpdb->insert(
                        $this->table_name( 'wp_group' ),
                        array(
                            'wpid'    => $websiteid,
                            'groupid' => $groupid,
                        )
                    );
                }

                return true;
            }
        }

        return false;
    }


    /**
     * Get website update stats via SQL.
     *
     * @return object|null Database query result of null on failure.
     */
    public function get_websites_stats_update_sql() {
        $where = $this->get_sql_where_allow_access_sites( 'wp' );
        return 'SELECT wp.*,wp_sync.sync_errors FROM ' . $this->table_name( 'wp' ) . ' wp  JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE (wp.statsUpdate = 0 OR ' . time() . ' - wp.statsUpdate >= ' . ( 60 * 60 * 24 ) . ')' . $where . ' ORDER BY wp.statsUpdate ASC';
    }

    /**
     * Update child site statistics.
     *
     * Update whether or not a child site has been updated.
     *
     * @param mixed $websiteid Child site ID.
     * @param mixed $statsUpdated Child site Update status.
     *
     * @return (int|boolean) Number of rows effected in update or false on failure.
     */
    public function update_website_stats( $websiteid, $statsUpdated ) {
        return $this->wpdb->update(
            $this->table_name( 'wp' ),
            array( 'statsUpdate' => $statsUpdated ),
            array( 'id' => $websiteid )
        );
    }

    /**
     * Get child site by url.
     *
     * @param string $url Child site URL.
     *
     * @return object|null Database query result or null on failure.
     */
    public function get_websites_by_url( $url ) {
        if ( '/' !== substr( $url, - 1 ) ) {
            $url .= '/';
        }
        $results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE wp.url = %s ', $this->escape( $url ) ), OBJECT );
        if ( $results ) {
            return $results;
        }

        if ( stristr( $url, '/www.' ) ) {
            // remove www if it's there!
            $url = str_replace( '/www.', '/', $url );
        } else {
            // add www if it's not there!
            $url = str_replace( 'https://', 'https://www.', $url );
            $url = str_replace( 'http://', 'http://www.', $url );
        }

        $results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid  WHERE wp.url = %s ', $this->escape( $url ) ), OBJECT );
        if ( $results ) {
            return $results;
        }

        $url = str_replace( array( 'https://www.', 'http://www.', 'https://', 'http://', 'www.' ), array( '', '', '', '', '' ), $url );

        return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . " wp_sync ON wp.id = wp_sync.wpid WHERE  replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')  = %s ", $this->escape( $url ) ), OBJECT );
    }

    /**
     * Method get_websites_to_notice_health_threshold()
     *
     * Get websites to notice site health.
     *
     * @param int $globalThreshold Global site health threshold.
     */
    public function get_websites_to_notice_health_threshold( $globalThreshold ) {

        $where      = $this->get_sql_where_allow_access_sites( 'wp' );
        $extra_view = array( 'monitoring_notification_emails', 'settings_notification_emails' );

        if ( 80 >= $globalThreshold ) { // actual is 80.
            // should-be-improved site health.
            $where_global_threshold = '( wp.health_threshold = 0 AND wp_sync.health_value < 80 )';
        } else {
            // good site health.
            $where_global_threshold = '( wp.health_threshold = 0 AND wp_sync.health_value >= 80 )';
        }

        $where_site_threshold  = ' ( wp.health_threshold = 80 AND wp_sync.health_value < 80 ) '; // should-be-improved site health.
        $where_site_threshold .= ' OR ( wp.health_threshold = 100 AND wp_sync.health_value >= 80 ) '; // good site health.

        return $this->wpdb->get_results(
            'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE wp.disable_health_check <> 1 AND wp.offline_check_result = 1 AND ( ' . $where_global_threshold . ' OR' . $where_site_threshold . ' ) AND wp_sync.health_site_noticed = 0 ' .
            $where,
            OBJECT
        );
    }

    /**
     * Get websites offline status.
     *
     * @return array Sites with offline status.
     */
    public function get_websites_http_check_status() {
        $where      = $this->get_sql_where_allow_access_sites( 'wp' );
        $extra_view = array( 'settings_notification_emails' );

        return $this->wpdb->get_results(
            'SELECT wp.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE wp.disable_status_check <> 1 AND wp.offline_check_result = -1' . // offline checked status.
            $where,
            OBJECT
        );
    }

    /**
     * Get DB Sites.
     *
     * @since 4.6
     *
     * @param mixed $params params.
     *
     * @return array $dbwebsites.
     */
    public function get_db_sites( $params = array() ) { // phpcs:ignore -- NOSONAR - complex.

        $dbwebsites = array();

        $data_fields   = MainWP_System_Utility::get_default_map_site_fields();
        $data_fields[] = 'verify_certificate';
        $data_fields[] = 'client_id';

        $fields        = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
        $sites         = isset( $params['sites'] ) && is_array( $params['sites'] ) ? $params['sites'] : array();
        $groups        = isset( $params['groups'] ) && is_array( $params['groups'] ) ? $params['groups'] : array();
        $clients       = isset( $params['clients'] ) && is_array( $params['clients'] ) ? $params['clients'] : array();
        $schema_fields = isset( $params['schema_fields'] ) && is_array( $params['schema_fields'] ) ? $params['schema_fields'] : array(); // since 5.2.
        $selectgroups  = isset( $params['selectgroups'] ) && ! empty( $params['selectgroups'] ) ? true : false; // since 5.2.

        if ( ! empty( $schema_fields ) ) { // since 5.2.
            foreach ( $schema_fields as $field_name ) {
                if ( ! in_array( $field_name, $data_fields ) ) {
                    $data_fields[] = $field_name;
                }
            }
        } elseif ( is_array( $fields ) ) {
            foreach ( $fields as $field_indx => $field_name ) {

                $get_field = $field_name;
                if ( is_numeric( $get_field ) || is_bool( $get_field ) ) { // to compatible fix.
                    $get_field = $field_indx;
                }

                if ( in_array( $get_field, static::$possible_options ) && ! in_array( $get_field, $data_fields ) ) {
                    $data_fields[] = $get_field;
                }
            }
        }

        if ( ! empty( $sites ) ) {
            foreach ( $sites as $v ) {
                if ( MainWP_Utility::ctype_digit( $v ) ) {
                    $website = static::instance()->get_website_by_id( $v, $selectgroups );
                    if ( empty( $website ) ) {
                        continue;
                    }
                    $dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
                }
            }
        }

        if ( ! empty( $groups ) ) {
            foreach ( $groups as $v ) {
                if ( MainWP_Utility::ctype_digit( $v ) ) {
                    $websites = static::instance()->query( static::instance()->get_sql_websites_by_group_id( $v, $selectgroups ) );
                    while ( $websites && ( $website = static::fetch_object( $websites ) ) ) {
                        $dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
                    }
                    static::free_result( $websites );
                }
            }
        }

        $params       = array(
            'full_data'    => true,
            'selectgroups' => $selectgroups,
        );
        $client_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $clients, $params );
        if ( $client_sites ) {
            foreach ( $client_sites as $website ) {
                $dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
            }
        }
        return $dbwebsites;
    }

    /**
     * Get Sites.
     *
     * @param int   $websiteid The id of the child site you wish to retrieve.
     * @param bool  $for_manager Check Team Control.
     * @param array $others Array of others.
     *
     * @return array $output Array of content to output.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     */
    public function get_sites( $websiteid = null, $for_manager = false, $others = array() ) { // phpcs:ignore -- NOSONAR - not quite complex function.

        if ( ! is_array( $others ) ) {
            $others = array();
        }

        $search_site = null;
        $orderBy     = 'wp.url';
        $offset      = false;
        $rowcount    = false;
        $extraWhere  = null;

        if ( isset( $websiteid ) && ( null !== $websiteid ) ) {
            $website = static::instance()->get_website_by_id( $websiteid );

            if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
                return false;
            }

            if ( ! \mainwp_current_user_can( 'site', $websiteid ) ) {
                return false;
            }

            return array(
                array(
                    'id'          => $websiteid,
                    'url'         => MainWP_Utility::get_nice_url( $website->url, true ),
                    'name'        => $website->name,
                    'totalsize'   => $website->totalsize,
                    'sync_errors' => $website->sync_errors,
                ),
            );
        } else {
            if ( isset( $others['orderby'] ) ) {
                if ( 'site' === $others['orderby'] ) {
                    $orderBy = 'wp.name ' . ( 'asc' === $others['order'] ? 'asc' : 'desc' );
                } elseif ( 'url' === $others['orderby'] ) {
                    $orderBy = 'wp.url ' . ( 'asc' === $others['order'] ? 'asc' : 'desc' );
                }
            }
            if ( isset( $others['search'] ) ) {
                $search_site = trim( $others['search'] );
            }

            if ( is_array( $others ) && isset( $others['plugins_slug'] ) ) {
                $slugs      = explode( ',', $others['plugins_slug'] );
                $extraWhere = '';
                foreach ( $slugs as $slug ) {
                    $slug        = wp_json_encode( $slug );
                    $slug        = trim( $slug, '"' );
                    $slug        = str_replace( '\\', '.', $slug );
                    $extraWhere .= ' wp.plugins REGEXP "' . $slug . '" OR';
                }
                $extraWhere = trim( rtrim( $extraWhere, 'OR' ) );

                if ( '' === $extraWhere ) {
                    $extraWhere = null;
                } else {
                    $extraWhere = '(' . $extraWhere . ')';
                }
            }
        }

        $totalRecords = '';

        if ( isset( $others['per_page'] ) && ! empty( $others['per_page'] ) ) {
            $sql            = static::instance()->get_sql_websites_for_current_user( false, $search_site, $orderBy, false, false, $extraWhere, $for_manager );
            $websites_total = static::instance()->query( $sql );
            $totalRecords   = ( $websites_total ? static::num_rows( $websites_total ) : 0 );

            if ( $websites_total ) {
                static::free_result( $websites_total );
            }

            $rowcount = absint( $others['per_page'] );
            $pagenum  = isset( $others['paged'] ) ? absint( $others['paged'] ) : 0;
            if ( $pagenum > $totalRecords ) {
                $pagenum = $totalRecords;
            }
            $pagenum = max( 1, $pagenum );
            $offset  = ( $pagenum - 1 ) * $rowcount;

        }

        $sql      = static::instance()->get_sql_websites_for_current_user( false, $search_site, $orderBy, $offset, $rowcount, $extraWhere, $for_manager );
        $websites = static::instance()->query( $sql );

        $output = array();
        while ( $websites && ( $website = static::fetch_object( $websites ) ) ) {
            $re = array(
                'id'          => $website->id,
                'url'         => MainWP_Utility::get_nice_url( $website->url, true ),
                'name'        => $website->name,
                'totalsize'   => $website->totalsize,
                'sync_errors' => $website->sync_errors,
                'client_id'   => $website->client_id,
            );

            if ( 0 < $totalRecords ) {
                $re['totalRecords'] = $totalRecords;
                $totalRecords       = 0;
            }

            $output[] = $re;
        }
        static::free_result( $websites );

        return $output;
    }

    /**
     * Method get_lookup_items().
     *
     * Get bulk lookup items to reduce number of db queries.
     *
     * @param string $item_name lookup item name.
     * @param int    $item_id lookup item id.
     * @param string $obj_name loockup object name.
     *
     * @return mixed Result
     */
    public function get_lookup_items( $item_name, $item_id, $obj_name ) {
        return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'lookup_item_objects' ) . ' WHERE item_name=%s AND item_id = %d AND object_name = %s', $item_name, $item_id, $obj_name ) ); //phpcs:ignore -- ok.
    }

    /**
     * Method insert_lookup_item().
     *
     * Insert lookup item, need checks existed before to prevent double values.
     *
     * @param string $item_name item name.
     * @param int    $item_id item id.
     * @param string $obj_name object name.
     * @param int    $obj_id object id.
     *
     * @return mixed Result
     */
    public function insert_lookup_item( $item_name, $item_id, $obj_name, $obj_id ) {
        if ( empty( $item_name ) || empty( $item_id ) || empty( $obj_name ) || empty( $obj_id ) ) {
            return false;
        }
        $data = array(
            'item_name'   => 'cost',
            'item_id'     => $item_id,
            'object_name' => $obj_name,
            'object_id'   => $obj_id,
        );
        $this->wpdb->insert( $this->table_name( 'lookup_item_objects' ), $data );
        return $this->wpdb->insert_id; // must return lookup id.
    }

    /**
     * Method delete_lookup_items().
     *
     * Delete bulk lookup items by lookup ids or object names with item id and item name, to reduce number of db queries.
     *
     * @param string $by Delete by.
     * @param array  $params params.
     *
     * @return mixed Result
     */
    public function delete_lookup_items( $by = 'lookup_id', $params = array() ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $params ) ) {
            return false;
        }

        $lookup_ids = isset( $params['lookup_ids'] ) ? $params['lookup_ids'] : null;
        $item_id    = isset( $params['item_id'] ) ? $params['item_id'] : null;
        $object_id  = isset( $params['object_id'] ) ? $params['object_id'] : null;
        $item_name  = isset( $params['item_name'] ) ? $params['item_name'] : null;
        $obj_names  = isset( $params['object_names'] ) ? $params['object_names'] : null;

        if ( 'object_name' === $by ) {
            if ( empty( $item_id ) || empty( $item_name ) ) {
                return false;
            }

            $obj_names = $this->escape_array( $obj_names );
            if ( ! empty( $obj_names ) ) {
                $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'lookup_item_objects' ) . ' WHERE item_name = %s AND item_id = %d AND object_name IN ("' . implode( '","', $obj_names ) . '") ', $item_name, $item_id ) );  //phpcs:ignore -- ok.
                return true;
            }
        } elseif ( 'object_id' === $by ) {
            if ( empty( $object_id ) || empty( $item_name ) || empty( $obj_names ) ) {
                return false;
            }

            $obj_names = $this->escape_array( $obj_names );
            if ( ! empty( $obj_names ) ) {
                $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'lookup_item_objects' ) . ' WHERE item_name = %s AND object_id = %d AND object_name IN ("' . implode( '","', $obj_names ) . '") ', $item_name, $object_id ) );  //phpcs:ignore -- ok.
                return true;
            }
        } elseif ( 'lookup_id' === $by ) {
            if ( empty( $lookup_ids ) ) {
                return false;
            }
            if ( is_numeric( $lookup_ids ) ) {
                $lookup_ids = array( $lookup_ids );
            } elseif ( is_array( $lookup_ids ) ) {
                $lookup_ids = MainWP_Utility::array_numeric_filter( $lookup_ids );
            } else {
                return false;
            }
            $this->wpdb->query( 'DELETE FROM ' . $this->table_name( 'lookup_item_objects' ) . ' WHERE lookup_id IN (' . implode( ',', $lookup_ids ) . ') ' );  //phpcs:ignore -- ok.
            return true;
        }
        return false;
    }


    /**
     * Return the user data for the given consumer_key.
     *
     * @param string $consumer_key Consumer key.
     * @param string $consumer_secret Secret key.
     * @param string $scope scope.
     * @param string $description description.
     * @param int    $enabled 1 or 0.
     * @param array  $others others.
     *
     * @return array
     */
    public function insert_rest_api_key( $consumer_key, $consumer_secret, $scope, $description, $enabled, $others = array() ) {
        global $current_user;

        if ( $current_user ) {
            $user_id = $current_user->ID;
        }

        if ( empty( $user_id ) ) {
            return false;
        }

        if ( ! is_array( $others ) ) {
            $others = array();
        }

        $pass = isset( $others['key_pass'] ) ? $others['key_pass'] : '';
        $type = isset( $others['key_type'] ) ? intval( $others['key_type'] ) : 0;

        // Created API keys.
        $permissions = in_array( $scope, array( 'read', 'write', 'read_write' ), true ) ? sanitize_text_field( $scope ) : 'read';
        $this->wpdb->insert(
            $this->table_name( 'api_keys' ),
            array(
                'user_id'         => $user_id,
                'description'     => $description,
                'permissions'     => $permissions,
                'consumer_key'    => mainwp_api_hash( $consumer_key ),
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => substr( $consumer_key, -7 ),
                'enabled'         => $enabled,
                'key_pass'        => $pass,
                'key_type'        => $type,
            ),
            array(
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%d',
            ),
        );

            return array(
                'key_id'          => $this->wpdb->insert_id,
                'user_id'         => $user_id,
                'consumer_key'    => $consumer_key,
                'consumer_secret' => $consumer_secret,
                'key_permissions' => $permissions,
            );
    }

    /**
     * Update rest api key.
     *
     * @param int    $key_id Consumer key.
     * @param string $scope scope.
     * @param string $description description.
     * @param int    $enabled Enabled.
     *
     * @return array
     */
    public function update_rest_api_key( $key_id, $scope, $description, $enabled = 1 ) {
        $permissions = in_array( $scope, array( 'read', 'write', 'read_write' ), true ) ? sanitize_text_field( $scope ) : 'read';
        return $this->wpdb->update(
            $this->table_name( 'api_keys' ),
            array(
                'description' => $description,
                'permissions' => $permissions,
                'enabled'     => $enabled ? 1 : 0,
            ),
            array(
                'key_id' => $key_id,
            )
        );
    }


    /**
     * Method is_existed_enabled_rest_key().
     *
     * @return bool result.
     */
    public function is_existed_enabled_rest_key() {
        $enabled = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'api_keys' ) . ' WHERE enabled = 1 LIMIT 1' );
        return $enabled ? true : false;
    }

    /**
     * Method get_rest_api_key_by().
     *
     * @param int $id To get key.
     *
     * @return array
     */
    public function get_rest_api_key_by( $id ) {
        return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'api_keys' ) . ' WHERE key_id = %d ', $id ) );
    }

    /**
     * Method remove_rest_api_key().
     *
     * @param string $id to delete.
     *
     * @return array
     */
    public function remove_rest_api_key( $id ) {
        return $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'api_keys' ) . ' WHERE key_id = %s', $id ) );
    }

    /**
     * Method get_rest_api_keys().
     *
     * @return array
     */
    public function get_rest_api_keys() {
        return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'api_keys' ) . ' ORDER BY key_id DESC' );
    }


    /**
     * Update regular process.
     *
     * @param  array $data process data.
     * @return mixed
     */
    public function update_regular_process( $data ) {
        if ( is_array( $data ) && isset( $data['type'] ) && isset( $data['process_slug'] ) ) {
            if ( isset( $data['process_id'] ) ) {
                $process_id = $data['process_id'];
                unset( $data['process_id'] );
                return $this->wpdb->update( $this->table_name( 'schedule_processes' ), $data, array( 'process_id' => $process_id ) );
            } else {
                return $this->wpdb->insert( $this->table_name( 'schedule_processes' ), $data );
            }
        }
        return false;
    }

    /**
     * Delete regular process.
     *
     * @param  int    $process_id Process id.
     * @param  int    $item_id Item id.
     * @param  string $pro_type Process type.
     * @param  string $pro_slug Process slug.
     *
     * @return mixed
     */
    public function delete_regular_process( $process_id = false, $item_id = false, $pro_type = false, $pro_slug = false ) {

        if ( is_numeric( $process_id ) && ! empty( $process_id ) ) {
            return $this->wpdb->delete(
                $this->table_name( 'schedule_processes' ),
                array(
                    'process_id' => $process_id,
                )
            );
        } elseif ( ! empty( $pro_type ) || ! empty( $pro_slug ) ) {

            $data = array();

            if ( ! empty( $pro_type ) ) {
                $data['type'] = $pro_type;
            }

            if ( ! empty( $pro_slug ) ) {
                $data['process_slug'] = $pro_slug;
            }

            if ( ! empty( $item_id ) ) {
                $data['item_id'] = $item_id;
            }
            // Bulk delete.
            return $this->wpdb->delete( $this->table_name( 'schedule_processes' ), $data );
        }
        return false;
    }

    /**
     * Method get_regular_process_by_item_id_type_slug
     *
     * @param  integer $item_id item id.
     * @param  string  $type type.
     * @param  string  $process_slug process slug.
     *
     * @return mixed  result
     */
    public function get_regular_process_by_item_id_type_slug( $item_id, $type, $process_slug ) {
        return $this->wpdb->get_row( $this->wpdb->prepare( ' SELECT pr.* FROM ' . $this->table_name( 'schedule_processes' ) . ' pr WHERE pr.item_id = %d AND pr.type = %s AND pr.process_slug = %s', $item_id, $type, $process_slug ) );
    }

    /**
     * Method log_system_query
     *
     * @param  array  $params params.
     * @param  string $sql query.
     * @return void
     */
    public function log_system_query( $params, $sql ) {
        if ( is_array( $params ) && ! empty( $params['dev_log_query'] ) && ! empty( $sql ) ) {
            error_log( $sql ); //phpcs:ignore -- NOSONAR - for dev.
            do_action( 'mainwp_log_system_query', $params, $sql );
        }
    }
}
