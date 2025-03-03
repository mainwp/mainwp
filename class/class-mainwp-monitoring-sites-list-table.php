<?php
/**
 * Monitoring Sites List Table.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Monitoring_Sites_List_Table
 *
 * @package MainWP\Dashboard
 *
 * MainWP sites monitoring list.
 *
 * @devtodo The only variables that seam to be used are $column_headers.
 *
 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_List_Table
 */
class MainWP_Monitoring_Sites_List_Table extends MainWP_Manage_Sites_List_Table { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Protected variable to hold columns headers
     *
     * @var array
     */
    protected $column_headers;

    /**
     * Instance variable.
     *
     * @var null Instance variable.
     */
    private static $instance = null;


    /**
     * Global settings variable.
     *
     * @var null Instance variable.
     */
    private $global_settings = null;

    /**
     * Public variable.
     *
     * @var bool
     */
    public $site_health_disabled = false;

    /**
     * Create instance.
     *
     * @return mixed $instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Monitoring_Sites_List_Table constructor.
     *
     * Run each time the class is called.
     * Add action to generate tabletop.
     */
    public function __construct() {
        add_action( 'mainwp_managesites_tabletop', array( &$this, 'generate_tabletop' ) );
        if ( null === $this->global_settings ) {
            $this->global_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        }
        $this->site_health_disabled = get_option( 'mainwp_disableSitesHealthMonitoring', 1 ) ? true : false;  // disabled by default.
    }

    /**
     * Get the default primary column name.
     *
     * @return string Child site name.
     */
    protected function get_default_primary_column_name() {
        return 'site';
    }


    /**
     * Set the column names.
     *
     * @param mixed  $item        MainWP site table item.
     * @param string $column_name Column name to use.
     *
     * @return string Column name.
     */
    public function column_default( $item, $column_name ) { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        /**
         * Filter: mainwp_monitoring_sitestable_item
         *
         * Filters the Monitoring Sites table column items. Allows user to create new column item.
         *
         * @param array $item Array containing child site data.
         *
         * @since 4.1
         */
        $item = apply_filters( 'mainwp_monitoring_sitestable_item', $item, $item );

        switch ( $column_name ) {
            case 'status':
            case 'favicon':
            case 'site':
            case 'login':
            case 'url':
            case 'status_code':
            case 'last_check':
            case 'site_health':
            case 'preview':
            case 'last24_status':
            case 'site_actions':
                return '';
            default:
                return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
        }
    }

    /**
     * Get sortable columns.
     *
     * @return array $sortable_columns Array of sortable column names.
     */
    public function get_sortable_columns() {
        return array(
            'site'        => array( 'site', false ),
            'url'         => array( 'url', false ),
            'status_code' => array( 'status_code', false ),
            'last_check'  => array( 'last_check', false ),
            'site_health' => array( 'site_health', false ),
            'client_name' => array( 'client_name', false ),
            'type'        => array( 'type', false ),
            'interval'    => array( 'interval', false ),
        );
    }

    /**
     * Get default columns.
     *
     * @return array Array of default column names.
     */
    public function get_default_columns() {
        $columns = array(
            'cb'            => '<input type="checkbox" />',
            'status'        => esc_html__( 'Status', 'mainwp' ),
            'site'          => esc_html__( 'Monitor', 'mainwp' ),
            'type'          => esc_html__( 'Monitor Type', 'mainwp' ),
            'interval'      => esc_html__( 'Check Frequency', 'mainwp' ),
            'status_code'   => esc_html__( 'Status code', 'mainwp' ),
            'site_health'   => esc_html__( 'Site Health', 'mainwp' ),
            'last24_status' => esc_html__( 'Last 24h Status', 'mainwp' ),
            'last_check'    => esc_html__( 'Last Check', 'mainwp' ),
        );

        if ( $this->site_health_disabled ) {
            unset( $columns['site_health'] );
        }

        return $columns;
    }

    /**
     * Method get_columns()
     *
     * Combine all columns.
     *
     * @param  bool $sub_rows sub rows.
     *
     * @return array $columns Array of column names.
     */
    public function get_columns( $sub_rows = false ) {

        $columns = $this->get_default_columns( $sub_rows );

        if ( ! $sub_rows ) {
            /**
             * Filter: mainwp_monitoring_sitestable_getcolumns
             *
             * Filters the Monitoring Sites table columns. Allows user to create a new column.
             *
             * @param array $columns Array containing table columns.
             *
             * @since 4.1
             */
            $columns = apply_filters( 'mainwp_monitoring_sitestable_getcolumns', $columns, $columns );
        }
        $columns['site_actions'] = '';
        return $columns;
    }

    /**
     * Instantiate Columns.
     *
     * @return array $init_cols
     */
    public function get_columns_init() {
        $cols      = $this->get_columns();
        $init_cols = array();
        foreach ( $cols as $key => $val ) {
            $init_cols[] = array( 'data' => esc_html( $key ) );
        }
        return $init_cols;
    }

    /**
     * Get column defines.
     *
     * @return array $defines Array of defines.
     */
    public function get_columns_defines() {
        $defines   = array();
        $defines[] = array(
            'targets'   => 'no-sort',
            'orderable' => false,
        );
        $defines[] = array(
            'targets'   => 'manage-cb-column',
            'className' => 'check-column collapsing',
        );
        $defines[] = array(
            'targets'   => 'manage-site-column',
            'className' => 'column-site-bulk mainwp-site-cell',
        );
        $defines[] = array(
            'targets'   => 'manage-url-column',
            'className' => 'mainwp-url-cell',
        );
        $defines[] = array(
            'targets'   => array( 'manage-login-column', 'manage-last_check-column', 'manage-last24_status-column', 'manage-status_code-column', 'manage-site_actions-column', 'extra-column', 'manage-client_name-column' ),
            'className' => 'collapsing',
        );

        $defines[] = array(
            'targets'   => array( 'manage-status_code-column' ),
            'className' => 'center aligned collapsing',
        );

        $defines[] = array(
            'targets'   => 'manage-site_actions-column',
            'className' => 'parent-site-actions-column',
        );

        $defines[] = array(
            'targets'   => array( 'manage-last24_status-column' ),
            'className' => 'dt-right',
        );

        $defines[] = array(
            'targets'   => array( 'manage-type-column' ),
            'className' => 'collapsing',
        );

        $defines[] = array(
            'targets'   => array( 'manage-interval-column' ),
            'className' => 'collapsing',
        );

        $defines[] = array(
            'targets'   => array( 'manage-status-column' ),
            'className' => 'collapsing center aligned',
        );
        return $defines;
    }

    /**
     * Method generate_tabletop()
     *
     * Run the render_manage_sites_table_top menthod.
     */
    public function generate_tabletop() {
        $this->render_manage_sites_table_top();
    }

    /**
     * Create bulk actions drop down.
     *
     * @return array $actions Return actions through the mainwp_monitoringsites_bulk_actions filter.
     */
    public function get_bulk_actions() {

        $actions = array(
            'checknow' => esc_html__( 'Check Now', 'mainwp' ),
            'sync'     => esc_html__( 'Sync Data', 'mainwp' ),
        );

        /**
         * Filter: mainwp_monitoringsites_bulk_actions
         *
         * Filters bulk actions on the Monitoring Sites page. Allows user to hook in new actions or remove default ones.
         *
         * @since 4.1
         */
        return apply_filters( 'mainwp_monitoringsites_bulk_actions', $actions );
    }

    /**
     * Render manage sites table top.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_for_manage_sites()
     */
    public function render_manage_sites_table_top() {
        $items_bulk = $this->get_bulk_actions();

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $selected_status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
        $selected_group  = isset( $_REQUEST['g'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) : '';
        $selected_client = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
        // phpcs:enable

        if ( empty( $selected_status ) && empty( $selected_group ) && empty( $selected_client ) ) {
            $selected_status = get_option( 'mainwp_monitoringsites_filter_status' );
            $selected_group  = get_option( 'mainwp_monitoringsites_filter_group' );
            $selected_client = get_option( 'mainwp_monitoringsites_filter_client' );
        }

        ?>
        <div class="ui grid">
            <div class="equal width row ui mini form">
            <div class="middle aligned column">
                    <div id="mainwp-uptime-monitoring-bulk-actions-menu" class="ui selection dropdown">
                        <div class="default text"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></div>
                        <i class="dropdown icon"></i>
                        <div class="menu">
                            <?php
                            foreach ( $items_bulk as $value => $title ) {
                                if ( 'seperator_' === substr( $value, 0, 10 ) ) {
                                    ?>
                                    <div class="ui divider"></div>
                                    <?php
                                } else {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $title ); ?></div>
                                    <?php
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <button class="ui tiny basic button" id="mainwp-do-monitors-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
                </div>
                <div class="right aligned middle aligned column">
                        <?php esc_html_e( 'Filter sites: ', 'mainwp' ); ?>
                        <div id="mainwp-filter-sites-group" multiple="" class="ui selection multiple dropdown">
                            <input type="hidden" value="<?php echo esc_html( $selected_group ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All tags', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                $groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
                                foreach ( $groups as $group ) {
                                    ?>
                                    <div class="item" data-value="<?php echo intval( $group->id ); ?>"><?php echo esc_html( stripslashes( $group->name ) ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="nogroups"><?php esc_html_e( 'No Tags', 'mainwp' ); ?></div>
                            </div>
                        </div>
                        <div class="ui selection dropdown" id="mainwp-filter-sites-status">
                            <div class="default text"><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                            <i class="dropdown icon"></i>
                            <div class="menu">
                                <div class="item" data-value="all" ><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                                <div class="item" data-value="online"><?php esc_html_e( 'Online', 'mainwp' ); ?></div>
                                <div class="item" data-value="offline"><?php esc_html_e( 'Offline', 'mainwp' ); ?></div>
                                <div class="item" data-value="undefined"><?php esc_html_e( 'Undefined', 'mainwp' ); ?></div>
                        </div>
                        </div>
                        <div id="mainwp-filter-clients" class="ui selection multiple dropdown">
                            <input type="hidden" value="<?php echo esc_html( $selected_client ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All clients', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
                                foreach ( $clients as $client ) {
                                    ?>
                                    <div class="item" data-value="<?php echo intval( $client->client_id ); ?>"><?php echo esc_html( stripslashes( $client->name ) ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="noclients"><?php esc_html_e( 'No Client', 'mainwp' ); ?></div>
                            </div>
                        </div>
                        <button onclick="mainwp_manage_monitor_sites_filter()" class="ui tiny basic button"><?php esc_html_e( 'Filter Sites', 'mainwp' ); ?></button>
                    </div>
        </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                <?php if ( '' !== $selected_status ) { ?>
                jQuery( '#mainwp-filter-sites-status' ).dropdown( "set selected", "<?php echo esc_js( $selected_status ); ?>" );
                <?php } ?>
            } );
        </script>
        <?php
    }


    /**
     * Prepair the items to be listed.
     *
     * @param bool $optimize true|false Whether or not to optimize.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_search_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::num_rows()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function prepare_items( $optimize = true ) { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $orderby = 'wp.url';

        $req_orderby = null;
        $req_order   = null;

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( $optimize ) {

            if ( isset( $_REQUEST['order'] ) ) {
                $order_values = MainWP_Utility::instance()->get_table_orders( $_REQUEST );
                $req_orderby  = $order_values['orderby'];
                $req_order    = $order_values['order'];
            }
            if ( isset( $req_orderby ) ) {
                if ( 'site' === $req_orderby ) {
                    $orderby = 'wp.name ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'url' === $req_orderby ) {
                    $orderby = 'wp.url ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'status' === $req_orderby ) {
                    $orderby = 'CASE true
                                WHEN (offline_check_result = 1)
                                    THEN 1
                                WHEN (offline_check_result <> 1)
                                    THEN 2
                                END ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'status_code' === $req_orderby ) {
                    $orderby = 'wp.http_response_code ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'last_check' === $req_orderby ) {
                    $orderby = 'wp.offline_checks_last ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'site_health' === $req_orderby ) {
                    $orderby = 'wp_sync.health_value ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'client_name' === $req_orderby ) {
                    $orderby = 'client_name ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'type' === $req_orderby ) {
                    $orderby = ' mo.type ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'interval' === $req_orderby ) {
                    $orderby = ' mo.interval ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                }
            }
        }

        if ( ! $optimize ) {
            $perPage = 9999;
            $start   = 0;
        } else {
            $perPage = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : 25;
            if ( -1 === (int) $perPage ) {
                $perPage = 9999;
            }
            $start = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;
        }

        $search = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';

        $get_saved_state = empty( $search ) && ! isset( $_REQUEST['g'] ) && ! isset( $_REQUEST['status'] ) && ! isset( $_REQUEST['client'] );
        $get_all         = ( '' === $search ) && ( isset( $_REQUEST['status'] ) && 'all' === $_REQUEST['status'] ) && ( isset( $_REQUEST['g'] ) && -1 === (int) $_REQUEST['g'] ) && empty( $_REQUEST['client'] ) ? true : false;

        $group_ids   = false;
        $client_ids  = false;
        $site_status = '';

        if ( ! isset( $_REQUEST['status'] ) ) {
            if ( $get_saved_state ) {
                $site_status = get_option( 'mainwp_monitoringsites_filter_status' );
            } else {
                MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_status', '' );
            }
        } else {
            $site_status = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );
            MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_status', $site_status );
        }

        if ( $get_all ) {
            MainWP_Utility::update_user_option( 'mainwp_monitoringsites_filter_group', '' );
            MainWP_Utility::update_user_option( 'mainwp_monitoringsites_filter_client', '' );
        }

        if ( ! $get_all ) {
            if ( ! isset( $_REQUEST['g'] ) ) {
                if ( $get_saved_state ) {
                    $group_ids = get_option( 'mainwp_monitoringsites_filter_group' );
                } else {
                    MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_group', '' );
                }
            } else {
                MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_group', sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) );
                $group_ids = sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ); // may be multi groups.
            }

            if ( ! isset( $_REQUEST['client'] ) ) {
                if ( $get_saved_state ) {
                    $client_ids = get_user_option( 'mainwp_monitoringsites_filter_client' );
                } else {
                    MainWP_Utility::update_user_option( 'mainwp_monitoringsites_filter_client', '' );
                }
            } else {
                MainWP_Utility::update_user_option( 'mainwp_monitoringsites_filter_client', sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) );
                $client_ids = sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ); // may be multi groups.
            }
        }
        // phpcs:enable

        $where = null;

        if ( '' !== $site_status && 'all' !== $site_status ) {
            if ( 'online' === $site_status ) {
                $where .= ' wp.offline_check_result = 1';
            } elseif ( 'undefined' === $site_status ) {
                $where .= ' wp.http_response_code = ""';
            } elseif ( 'offline' === $site_status ) {
                $where .= ' wp.offline_check_result <> "" AND wp.offline_check_result <> 1'; // 1 - online, -1 offline.
            }
        }

        $total_params = array( 'count_only' => true );
        if ( $get_all ) {
            $params = array(
                'selectgroups' => true,
                'orderby'      => $orderby,
                'offset'       => $start,
                'rowcount'     => $perPage,
            );
        } else {
            $total_params['search'] = $search;
            $params                 = array(
                'selectgroups' => true,
                'orderby'      => $orderby,
                'offset'       => $start,
                'rowcount'     => $perPage,
                'search'       => $search,
            );

            $qry_group_ids = array();
            if ( ! empty( $group_ids ) ) {
                $group_ids = explode( ',', $group_ids ); // convert to array.
                // to fix query deleted groups.
                $groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
                foreach ( $groups as $gr ) {
                    if ( in_array( $gr->id, $group_ids ) ) {
                        $qry_group_ids[] = $gr->id;
                    }
                }
                // to fix.
                if ( in_array( 'nogroups', $group_ids ) ) {
                    $qry_group_ids[] = 'nogroups';
                }
            }

            if ( ! empty( $qry_group_ids ) ) {
                $total_params['group_id'] = $qry_group_ids;
                $params['group_id']       = $qry_group_ids;
            }

            $qry_client_ids = array();
            if ( ! empty( $client_ids ) ) {
                $client_ids = explode( ',', $client_ids ); // convert to array.
                // to fix query deleted client.
                $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
                if ( $clients ) {
                    foreach ( $clients as $client ) {
                        if ( in_array( $client->client_id, $client_ids ) ) {
                            $qry_client_ids[] = $client->client_id;
                        }
                    }
                }
                // to fix.
                if ( in_array( 'noclients', $client_ids ) ) {
                    $qry_client_ids[] = 'noclients';
                }
            }

            if ( ! empty( $qry_client_ids ) ) {
                $total_params['client_id'] = $qry_client_ids;
                $params['client_id']       = $qry_client_ids;
            }

            if ( ! empty( $where ) ) {
                $total_params['extra_where'] = $where;
                $params['extra_where']       = $where;
            }
        }

        $params['view'] = 'monitor_view';

        $total_params['view'] = 'monitor_view';

        $total_websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $total_params ) );
        $totalRecords   = ( $total_websites ? MainWP_DB::num_rows( $total_websites ) : 0 );
        if ( $total_websites ) {
            MainWP_DB::free_result( $total_websites );
        }

        $extra_view           = apply_filters( 'mainwp_monitoring_sitestable_prepare_extra_view', array( 'favi_icon', 'health_site_status' ) );
        $extra_view           = array_unique( $extra_view );
        $params['extra_view'] = $extra_view;
        $websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $params ) );

        $site_ids = array();
        while ( $websites && ( $site = MainWP_DB::fetch_object( $websites ) ) ) {
            $site_ids[] = $site->id;
        }

        /**
         * Action: mainwp_monitoring_sitestable_prepared_items
         *
         * Fires before the Monitoring Sites table itemes are prepared.
         *
         * @param object $websites Object containing child sites data.
         * @param array  $site_ids Array containing IDs of all child sites.
         *
         * @since 4.1
         */
        do_action( 'mainwp_monitoring_sitestable_prepared_items', $websites, $site_ids );

        MainWP_DB::data_seek( $websites, 0 );

        $this->items       = $websites;
        $this->total_items = $totalRecords;
    }

    /**
     * Display the table.
     *
     * @param bool $optimize true|false Whether or not to optimize.
     */
    public function display( $optimize = true ) {

        $sites_per_page = get_option( 'mainwp_default_monitoring_sites_per_page', 25 );

        $sites_per_page = intval( $sites_per_page );

        $pages_length = array(
            25  => '25',
            10  => '10',
            50  => '50',
            100 => '100',
            300 => '300',
        );

        $pages_length = $pages_length + array( $sites_per_page => $sites_per_page );
        ksort( $pages_length );

        if ( isset( $pages_length[-1] ) ) {
            unset( $pages_length[-1] );
        }

        $pagelength_val   = implode( ',', array_keys( $pages_length ) );
        $pagelength_title = implode( ',', array_values( $pages_length ) );

        ?>
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-monitoring-info-message' ) ) : ?>
            <div class="ui info message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-monitoring-info-message"></i>
                <div><?php esc_html_e( 'The Uptime Monitoring feature runs directly on your server, which means it utilizes your server\'s resources to perform checks. A high frequency checks could place additional load on your server, especially for resource-intensive environments.', 'mainwp' ); ?></div><br/>
                <div><?php esc_html_e( 'We recommend setting a check interval that balances your uptime monitoring needs with your server\'s performance. For most sites, a 5-minute or 10-minute interval provides reliable monitoring without unnecessary strain.', 'mainwp' ); ?></div><br/>
                <div><?php printf( esc_html__( 'If you need further guidance, feel free to visit our %1$sKnowledge Base%2$s or reach out to support.', 'mainwp' ), '<a href="https://mainwp.com/kb/sites-monitoring/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?></div>
            </div>
        <?php endif; ?>
        <table id="mainwp-manage-sites-table" style="width:100%" class="ui single line selectable unstackable table mainwp-with-preview-table mainwp-manage-wpsites-table">
            <thead>
            <tr>
                <?php $this->print_column_headers( $optimize, true ); ?>
            </tr>
            </thead>
            <?php if ( ! $optimize ) { ?>
            <tbody id="mainwp-manage-sites-body-table">
                <?php $this->display_rows_or_placeholder(); ?>
            </tbody>
            <?php } ?>
            <tfoot>
                <tr>
                    <?php $this->print_column_headers( $optimize, false ); ?>
                </tr>
            </tfoot>
    </table>
    <div id="mainwp-loading-sites" style="display: none;">
    <div class="ui active inverted dimmer">
    <div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading ...', 'mainwp' ); ?></div>
    </div>
    </div>

        <?php

        $count = MainWP_DB::instance()->get_websites_count( null, true );

        $table_features = array(
            'searching'     => 'true',
            'paging'        => 'true',
            'pagingType'    => 'full_numbers',
            'info'          => 'true',
            'colReorder'    => '{columns:":not(.check-column):not(:last-child)"}',
            'stateSave'     => 'true',
            'stateDuration' => '0',
            'order'         => '[]',
            'scrollX'       => 'true',
            'responsive'    => 'true',
        );

        /**
         * Filter: mainwp_monitoring_table_features
         *
         * Filter the Monitoring table features.
         *
         * @since 4.1
         */
        $table_features = apply_filters( 'mainwp_monitoring_table_features', $table_features );
        ?>
    <script type="text/javascript">

                let _init_uptime_status_bar_popup_tooltip = function( wrapper ){
                    let popWrapper = '';
                    if( typeof wrapper === 'undefined' ){
                        popWrapper = jQuery('#mainwp-manage-sites-table .mainwp-html-popup');
                    } else {
                        popWrapper = jQuery(wrapper).find('.mainwp-html-popup');
                    }
                    if( popWrapper !== '' ) {
                        jQuery(popWrapper).each(
                            function(){
                                mainwp_init_html_popup(this);
                            }
                        );
                    }
                }


            jQuery( document ).ready( function( $ ) {
                mainwp_manage_sites_screen_options = function () {
                    jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( {
                        allowMultiple: true,
                        onHide: function () {
                            let val = jQuery( '#mainwp_default_monitoring_sites_per_page' ).val();
                            let saved = jQuery( '#mainwp_default_monitoring_sites_per_page' ).attr( 'saved-value' );
                            if ( saved != val ) {
                                jQuery( '#mainwp-manage-sites-table' ).DataTable().page.len( val );
                                jQuery( '#mainwp-manage-sites-table' ).DataTable().state.save();
                            }
                        }
                    } ).modal( 'show' );

                    jQuery( '#manage-sites-screen-options-form' ).submit( function() {
                        if ( jQuery('input[name=reset_monitoringsites_columns_order]').attr('value') == 1 ) {
                            $manage_sites_table.colReorder.reset();
                            jQuery( '.uptime-monitors-sub-pages-table' ).each(function(){
                                jQuery(this).DataTable().colReorder.reset();
                            });
                        }
                        jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( 'hide' );
                    } );
                    return false;
                };

                let responsive = <?php echo esc_js( $table_features['responsive'] ); ?>;
                if( jQuery( window ).width() > 1140 ) {
                    responsive = false;
                }

                try {
                    $manage_sites_table = jQuery( '#mainwp-manage-sites-table' ).on( 'processing.dt', function ( e, settings, processing ) {
                        jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
                        if (!processing) {
                            let tb = jQuery( '#mainwp-manage-sites-table' );
                            tb.find( 'th[cell-cls]' ).each( function(){
                                let ceIdx = this.cellIndex;
                                let cls = jQuery( this ).attr( 'cell-cls' );
                                jQuery( '#mainwp-manage-sites-table tr' ).each(function(){
                                    jQuery(this).find( 'td:eq(' + ceIdx + ')' ).addClass(cls);
                                } );
                            } );
                            $( '#mainwp-manage-sites-table .ui.dropdown' ).dropdown();
                            $( '#mainwp-manage-sites-table .ui.checkbox' ).checkbox();
                        }

                    } ).DataTable( {
                        "ajax": {
                            "url": ajaxurl,
                            "type": "POST",
                            "data":  function ( d ) {
                                return $.extend( {}, d, mainwp_secure_data( {
                                    action: 'mainwp_monitoring_sites_display_rows',
                                    status: jQuery("#mainwp-filter-sites-status").dropdown("get value"),
                                    g: jQuery("#mainwp-filter-sites-group").dropdown("get value"),
                                    client: jQuery("#mainwp-filter-clients").dropdown("get value")
                                } )
                            );
                            },
                            "dataSrc": function ( json ) {
                                for ( let i=0, ien=json.data.length ; i < ien ; i++ ) {
                                    json.data[i].syncError = json.rowsInfo[i].syncError ? json.rowsInfo[i].syncError : false;
                                    json.data[i].rowClass = json.rowsInfo[i].rowClass;
                                    json.data[i].siteID = json.rowsInfo[i].siteID;
                                    json.data[i].siteUrl = json.rowsInfo[i].siteUrl;
                                    json.data[i].monitorID = json.rowsInfo[i].monitorID;
                                    json.data[i].niceurl = json.rowsInfo[i].niceurl;
                                    json.data[i].urlpage = json.rowsInfo[i].urlpage;
                                    json.data[i].childRows = json.childRows[i];
                                }
                                return json.data;
                            },
                        },
                        "responsive" : responsive,
                        "searching" : <?php echo esc_js( $table_features['searching'] ); ?>,
                        "paging" : <?php echo esc_js( $table_features['paging'] ); ?>,
                        "pagingType" : "<?php echo esc_js( $table_features['pagingType'] ); ?>",
                        "info" : <?php echo esc_js( $table_features['info'] ); ?>,
                        "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                        "stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
                        "stateDuration" : <?php echo esc_js( $table_features['stateDuration'] ); ?>,
                        "order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
                        "scrollX" : <?php echo esc_js( $table_features['scrollX'] ); ?>,
                        "lengthMenu" : [ [<?php echo esc_js( $pagelength_val ); ?>, -1 ], [<?php echo esc_js( $pagelength_title ); ?>, "All"] ],
                        serverSide: true,
                        "pageLength": <?php echo intval( $sites_per_page ); ?>,
                        "columnDefs": <?php echo wp_json_encode( $this->get_columns_defines() ); ?>,
                        "columns": <?php echo wp_json_encode( $this->get_columns_init() ); ?>,
                        "drawCallback": function( settings ) {
                            this.api().tables().body().to$().attr( 'id', 'mainwp-manage-sites-body-table' );
                            // mainwp_datatable_fix_menu_overflow( '#mainwp-manage-sites-table' );
                            _init_uptime_status_bar_popup_tooltip();
                        },
                        rowCallback: function (row, data) {
                            jQuery( row ).addClass(data.rowClass);
                            jQuery( row ).attr( 'site-url', data.siteUrl );
                            jQuery( row ).attr( 'siteid', data.siteID );
                            jQuery( row ).attr( 'id', "child-site-" + data.siteID );
                            jQuery( row ).attr( 'itemid', data.monitorID );
                            jQuery( row ).attr( 'niceurl', data.niceurl );
                            jQuery( row ).attr( 'urlpage', data.urlpage );

                            if(data.childRows != '' ){
                                let tdrow = this.api().tables().row(row).child(data.childRows);
                                tdrow.show();
                                _init_uptime_monitoring_table_sub_rows(jQuery(tdrow.child()[0]));
                            }
                            if ( data.syncError ) {
                                jQuery( row ).find( 'td.column-site-bulk' ).addClass( 'site-sync-error' );
                            };
                        },
                        'select': {
                            items: 'row',
                            style: 'multi+shift',
                            selector: 'tr>td:not(.not-selectable)'
                        }
                    }).on('select', function (e, dt, type, indexes) {
                        if( 'row' == type ){
                            dt.rows(indexes)
                            .nodes()
                            .to$().find('td.check-column .ui.checkbox' ).checkbox('set checked');
                        }
                    }).on('deselect', function (e, dt, type, indexes) {
                        if( 'row' == type ){
                            dt.rows(indexes)
                            .nodes()
                            .to$().find('td.check-column .ui.checkbox' ).checkbox('set unchecked');
                        }
                    }).on( 'columns-reordered', function () {
                        console.log('columns-reordered');
                        setTimeout(() => {
                            $( '#mainwp-manage-sites-table .ui.dropdown' ).dropdown();
                            $( '#mainwp-manage-sites-table .ui.checkbox' ).checkbox();
                            // mainwp_datatable_fix_menu_overflow( '#mainwp-manage-sites-table' );
                        }, 1000 );
                        _init_uptime_status_bar_popup_tooltip();
                    } );

                    _init_uptime_status_bar_popup_tooltip();

                } catch(err) {
                    // to fix js error.
                }


                _init_uptime_monitoring_table_sub_rows = function(tblWrapper){
                    let tblSelector = jQuery(tblWrapper).find( '.uptime-monitors-sub-pages-table' );
                    try {
                        $manage_sub_pages_table = jQuery(tblSelector).DataTable( {
                            "responsive" : responsive,
                            paging: false,
                            searching:false,
                            info: false,
                            order:[[2, 'desc']],
                            "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                            "stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
                            "stateDuration" : <?php echo esc_js( $table_features['stateDuration'] ); ?>,
                            "columnDefs": [
                                {
                                    "targets": 'no-sort',
                                    "orderable": false
                                },
                                {
                                    "targets": 'manage-site-column',
                                    "type": 'natural-nohtml'
                                },
                                <?php do_action( 'mainwp_manage_sites_table_columns_defs' ); ?>
                            ],
                            "drawCallback": function( settings ) {
                                jQuery(tblSelector).find('.ui.dropdown' ).dropdown();
                                jQuery(tblSelector).find('.ui.checkbox' ).checkbox();
                                _init_manage_monitors_screen_settings(tblSelector);
                                _init_uptime_status_bar_popup_tooltip(jQuery(tblSelector));
                            },
                            'select': {
                                items: 'row',
                                style: 'multi+shift',
                                selector: 'tr>td:not(.not-selectable)'
                            }
                        } ).on('select', function (e, dt, type, indexes) {
                            if( 'row' == type ){
                                dt.rows(indexes)
                                .nodes()
                                .to$().find('td.check-column .ui.checkbox' ).checkbox('set checked');
                            }
                        }).on('deselect', function (e, dt, type, indexes) {
                            if( 'row' == type ){
                                dt.rows(indexes)
                                .nodes()
                                .to$().find('td.check-column .ui.checkbox' ).checkbox('set unchecked');
                            }
                        }).on( 'columns-reordered', function () {
                            console.log('sub pages columns-reordered');
                            setTimeout(() => {
                                $( tblSelector + ' .ui.dropdown' ).dropdown();
                                $( tblSelector + ' .ui.checkbox' ).checkbox();
                            }, 1000 );
                            _init_uptime_status_bar_popup_tooltip(jQuery(tblSelector));
                        } );
                        _init_uptime_status_bar_popup_tooltip(jQuery(tblSelector));
                    } catch(err) {
                        // to fix js error.
                    }
                }


                _init_manage_monitors_screen_settings = function(tblSelector) {
                    let $sub_pages_table = jQuery(tblSelector).DataTable();
                    jQuery( '#mainwp-manage-sites-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
                        let col_id = jQuery( this ).attr( 'id' );
                        col_id = col_id.replace( "mainwp_show_column_", "" );
                        try {
                            $sub_pages_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
                        } catch(err) {
                            // to fix js error.
                        }
                    } );
                }

                _init_manage_sites_screen = function() {
                        <?php
                        if ( empty( $count ) ) {
                            ?>
                            jQuery( '#mainwp-manage-sites-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
                                let col_id = jQuery( this ).attr( 'id' );
                                col_id = col_id.replace( "mainwp_show_column_", "" );
                                try {
                                    $manage_sites_table.column( '#' + col_id ).visible( false );
                                    jQuery( '.uptime-monitors-sub-pages-table' ).each(function(){
                                        let $sub_pages_table = jQuery(this).DataTable();
                                        $sub_pages_table.column( '#' + col_id ).visible( false );
                                    });
                                } catch(err) {
                                    // to fix js error.
                                }
                            } );

                            //default columns: Site, Open Admin, URL, Updates, Site Health, Status Code and Actions.
                            let cols = ['site','status','site_actions'];
                            jQuery.each( cols, function ( index, value ) {
                                try {
                                    $manage_sites_table.column( '#' + value ).visible( true );
                                    jQuery( '.uptime-monitors-sub-pages-table' ).each(function(){
                                        let $sub_pages_table = jQuery(this).DataTable();
                                        $sub_pages_table.column( '#' + value ).visible( true );
                                    });
                                } catch(err) {
                                    // to fix js error.
                                }
                            } );
                            <?php
                        } else {
                            ?>
                            jQuery( '#mainwp-manage-sites-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
                                let col_id = jQuery( this ).attr( 'id' );
                                col_id = col_id.replace( "mainwp_show_column_", "" );
                                try {
                                    $manage_sites_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
                                } catch(err) {
                                    // to fix js error.
                                }
                            } );
                    <?php } ?>
                    };

                    _init_manage_sites_screen();

                } );

                mainwp_manage_monitor_sites_filter = function() {
                    <?php if ( ! $optimize ) { ?>
                        let group = jQuery( "#mainwp-filter-sites-group" ).dropdown( "get value" );
                        let status = jQuery( "#mainwp-filter-sites-status" ).dropdown( "get value" );
                        let client = jQuery("#mainwp-filter-clients").dropdown("get value");

                        let params = '';
                        params += '&g=' + group;
                        params += '&client=' + client;
                        if ( status != '' )
                            params += '&status=' + status;

                        window.location = 'admin.php?page=MonitoringSites' + params;
                        return false;
                    <?php } else { ?>
                        try {
                            $manage_sites_table.ajax.reload();
                        } catch(err) {
                            // to fix js error.
                        }
                    <?php } ?>
                };
                </script>
        <?php
    }

    /**
     * Echo the column headers.
     *
     * @param bool $optimize true|false Whether or not to optimise.
     * @param bool $top true|false.
     * @param bool $sub_rows true|false.
     * @param int  $parent_site_id site parent id.
     */
    public function print_column_headers( $optimize = false, $top = true, $sub_rows = false, $parent_site_id = false ) { //phpcs:ignore -- NOSONAR - complex.

        list( $columns, $sortable ) = $this->get_column_info( $sub_rows );

        if ( ! empty( $columns['cb'] ) ) {
            if ( $sub_rows ) {
                $columns['cb'] = '<div class="ui checkbox"><input class="sub-pages-checkbox ' . ( $top ? 'cb-select-all-parent-top' : 'cb-select-all-parent-bottom' ) . '" type="checkbox" cb-parent-selector=".monitors-sub-pages-table-body-' . ( false !== $parent_site_id ? intval( $parent_site_id ) : 0 ) . '"  /></div>';
            } else {
                $columns['cb'] = '<div class="ui checkbox"><input id="' . ( $top ? 'cb-select-all-top' : 'cb-select-all-bottom' ) . '" type="checkbox" /></div>';
            }
        }

        $def_columns = $this->get_default_columns( $sub_rows );

        $def_columns['site_actions'] = '';

        foreach ( $columns as $column_key => $column_display_name ) {

            $class = array( 'manage-' . $column_key . '-column' );
            $attr  = '';
            if ( ! isset( $def_columns[ $column_key ] ) ) {
                $class[] = 'extra-column';
                if ( $optimize ) {
                    $attr = 'cell-cls="' . esc_html( "collapsing $column_key column-$column_key" ) . '"';
                }
            }

            if ( 'cb' === $column_key ) {
                $class[] = 'check-column no-sort collapsing dt-orderable-none';
            }

            if ( 'status' === $column_key ) {
                $class[] = 'collapsing';
            }

            if ( ! isset( $sortable[ $column_key ] ) ) {
                $class[] = 'no-sort';
            }

            $tag = 'th';
            $id  = "id='$column_key'";

            if ( ! empty( $class ) ) {
                $class = "class='" . join( ' ', $class ) . "'";
            }

            echo "<$tag $id $class $attr>$column_display_name</$tag>"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    /**
     * Get column info.
     *
     * @param   bool $sub_rows sub rows.
     * @return mixed
     */
    protected function get_column_info( $sub_rows = false ) {

        if ( isset( $this->column_headers ) && is_array( $this->column_headers ) ) {
            $column_headers = array( array(), array(), array(), $this->get_default_primary_column_name() );
            foreach ( $this->column_headers as $key => $value ) {
                $column_headers[ $key ] = $value;
            }

            return $column_headers;
        }

        $columns = $this->get_columns( $sub_rows );

        $sortable_columns = $this->get_sortable_columns();

        $_sortable = $sortable_columns;

        $sortable = array();
        foreach ( $_sortable as $id => $data ) {
            if ( empty( $data ) ) {
                continue;
            }

            $data = (array) $data;
            if ( ! isset( $data[1] ) ) {
                $data[1] = false;
            }

            $sortable[ $id ] = $data;
        }

        $primary              = $this->get_default_primary_column_name();
        $this->column_headers = array( $columns, $sortable, $primary );

        return $this->column_headers;
    }


    /**
     * Single row.
     *
     * @param mixed $monitor_subpage Object containing the site info.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize_file_name()
     */
    public function single_row( $monitor_subpage ) {
        $classes = $this->get_groups_classes( $monitor_subpage );
        $classes = trim( $classes );

        $classes = ' class="child-site mainwp-child-site-' . intval( $monitor_subpage['wpid'] ) . ' ' . esc_html( $classes ) . '"';

        $check_url = empty( $monitor_subpage['issub'] ) ? $monitor_subpage['url'] : $monitor_subpage['url'] . $monitor_subpage['suburl'];
        echo '<tr id="monitor-sub-page-' . intval( $monitor_subpage['wpid'] ) . '"' . $classes . ' siteid="' . intval( $monitor_subpage['wpid'] ) . '" urlpage="' . esc_url( $check_url ) . '" itemid="' . intval( $monitor_subpage['monitor_id'] ) . '"  niceurl="' . esc_attr( MainWP_Utility::get_nice_url( $check_url ) ) . '">'; //phpcs:ignore -- NOSONAR - safe.
        $this->single_row_columns( $monitor_subpage );
        echo '</tr>';
    }


    /**
     * Columns for a single row.
     *
     * @param object $website     Object containing the site info.
     * @param array  $good_health  Deprecated.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_favico_url()
     * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_http_codes()
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    protected function single_row_columns( $website, $good_health = false ) { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        unset( $good_health ); // compatible params, unuse.
        $monitor_subpage = $website;

        list( $columns ) = $this->get_column_info();

        $glo_active = 0;
        if ( isset( $this->global_settings['active'] ) ) {
            $glo_active = 1 === (int) $this->global_settings['active'] ? 1 : 0;
        }

        $last24_time = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( time() - DAY_IN_SECONDS );

        $uptime_status = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_monitor_stat_hourly_by( $monitor_subpage['monitor_id'], 'last24', $last24_time );

        $disabled = ( ! $glo_active && ( -1 === (int) $monitor_subpage['active'] ) ) || 0 === (int) $monitor_subpage['active'] ? true : false;

        foreach ( $columns as $column_name => $column_display_name ) {

            $classes    = "collapsing center aligned $column_name column-$column_name";
            $attributes = "class='$classes'";

            ?>
            <?php if ( 'cb' === $column_name ) { ?>
                <td class="check-column no-sort">
                    <div class="ui checkbox <?php echo ! empty( $monitor_subpage['monitor_id'] ) ? 'cb-uptime-monitor' : ''; ?>">
                        <input type="checkbox" value="<?php echo intval( $monitor_subpage['id'] ); ?>" name=""/>
                    </div>
                </td>
                <?php
            } elseif ( 'status' === $column_name ) {
                ?>
                <td class="manage-type-column">
                <?php
                $this->render_uptime_status( $monitor_subpage['last_status'], false, $disabled );
                ?>
                </td>
                <?php
            } elseif ( 'site' === $column_name ) {
                ?>
                <td class="mainwp-site-cell">
                    <?php
                    $this->column_site( $monitor_subpage );
                    ?>
                </td>
                <?php
            } elseif ( 'last_check' === $column_name ) {
                ?>
                <td class="collapsing">
                    <?php
                    if ( ! $disabled ) {
                        $this->column_last_check( $monitor_subpage, true );
                    }
                    ?>
                </td>
                <?php
            } elseif ( 'site_health' === $column_name ) {
                ?>
                <td class="collapsing">
                </td>
                <?php
            } elseif ( 'type' === $column_name ) {
                ?>
                <td class="manage-type-column">
                    <?php
                    if ( ! $disabled ) {
                        $mo_type = MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'type', $monitor_subpage['type'], $this->global_settings, 'useglobal', 'http' );
                        $this->column_type( $mo_type );
                    }
                    ?>
                </td>
            <?php } elseif ( 'interval' === $column_name ) { ?>
                <td class="manage-interval-column collapsing">
                    <?php
                    if ( ! $disabled ) {
                        $mo_interval = MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'interval', (int) $monitor_subpage['interval'], $this->global_settings, -1, 60 );
                        echo '<i class="clock outline icon"></i> ' . ( -1 === (int) $mo_interval ? esc_html__( 'Use global setting', 'mainwp' ) : intval( $mo_interval ) . esc_html__( ' minutes', 'mainwp' ) );
                    }
                    ?>
                </td>
                <?php
            } elseif ( 'status_code' === $column_name ) {
                ?>
                <td class="center aligned collapsing">
                <?php
                if ( ! $disabled ) {
                    echo esc_html( $website['http_response_code'] );
                }
                ?>
                </td>
                <?php
            } elseif ( 'last24_status' === $column_name ) {
                ?>
                <td class="dt-right collapsing">
                <?php
                if ( ! $disabled ) {
                    $this->render_last24_uptime_status( $uptime_status, $last24_time );
                }
                ?>
                </td>
                <?php
            } elseif ( 'site_actions' === $column_name ) {
                ?>
                    <td class="collapsing">
                        <div class="ui right pointing dropdown" style="z-index:999;">
                            <i class="ellipsis vertical icon"></i>
                            <div class="menu" siteid="<?php echo intval( $monitor_subpage['id'] ); ?>" itemid="<?php echo intval( $monitor_subpage['monitor_id'] ); ?>">
                                <a class="managemonitors_uptime_checknow item" href="#"><?php esc_html_e( 'Check Now', 'mainwp' ); ?></a>
                            </div>
                        </div>
                    </td>
                <?php
            } else {
                echo "<td $attributes>"; // phpcs:ignore WordPress.Security.EscapeOutput
                echo $this->column_default( $monitor_subpage, $column_name ); // phpcs:ignore WordPress.Security.EscapeOutput
                echo '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
        }
    }

    /**
     * Get table rows.
     *
     * Optimize for shared hosting or big networks.
     *
     * @return array Table rows HTML.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_favico_url()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_http_codes()
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    public function ajax_get_datatable_rows() { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $all_rows   = array();
        $info_rows  = array();
        $child_rows = array();

        if ( $this->items ) {

            $glo_active = 0;
            if ( isset( $this->global_settings['active'] ) ) {
                $glo_active = 1 === (int) $this->global_settings['active'] ? 1 : 0;
            }

            foreach ( $this->items as $website ) {
                $rw_classes = 'child-site mainwp-child-site-' . intval( $website['id'] ) . ' ';

                $health_status = ! empty( $website['health_site_status'] ) ? json_decode( $website['health_site_status'], true ) : array();

                $hstatus     = MainWP_Utility::get_site_health( $health_status );
                $hval        = $hstatus['val'];
                $critical    = $hstatus['critical'];
                $good_health = false;

                if ( 80 <= $hval && empty( $critical ) ) {
                    $good_health = true;
                }

                if ( $good_health ) {
                    $h_color = 'green';
                    $h_text  = esc_html__( 'Good', 'mainwp' );
                } else {
                    $h_color = 'orange';
                    $h_text  = esc_html__( 'Should be improved', 'mainwp' );
                }

                $check_url = ! $website['issub'] ? $website['url'] : $website['url'] . $website['suburl'];

                $info_item = array(
                    'rowClass'  => esc_html( $rw_classes ),
                    'siteID'    => $website['id'],
                    'siteUrl'   => $website['url'],
                    'syncError' => ( '' !== $website['sync_errors'] ? true : false ),
                    'monitorID' => $website['monitor_id'],
                    'niceurl'   => MainWP_Utility::get_nice_url( $check_url ),
                    'urlpage'   => $check_url,
                );

                $columns = $this->get_columns();

                $cols_data = array();

                $last24_time = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( time() - DAY_IN_SECONDS );

                $uptime_status = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_monitor_stat_hourly_by( $website['monitor_id'], 'last24', $last24_time );

                $disabled = ( ! $glo_active && ( -1 === (int) $website['active'] ) ) || 0 === (int) $website['active'] ? true : false;

                foreach ( $columns as $column_name => $column_display_name ) {
                    ob_start();
                    ?>
                    <?php if ( 'cb' === $column_name ) : ?>
                        <div class="ui checkbox <?php echo ! empty( $website['monitor_id'] ) ? 'cb-uptime-monitor' : ''; ?>"><input type="checkbox" value="<?php echo intval( $website['id'] ); ?>" /></div>
                        <?php
                    elseif ( 'status' === $column_name ) :
                        ?>
                        <?php $this->render_uptime_status( false, $website['offline_check_result'], $disabled ); ?>
                        <?php
                    elseif ( 'site' === $column_name ) :
                        $this->column_site( $website );
                    elseif ( 'type' === $column_name ) :
                        if ( ! $disabled ) {
                            $mo_type = MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'type', $website['type'], $this->global_settings, 'useglobal', 'http' );
                            $this->column_type( $mo_type );
                        }
                        ?>
                    <?php elseif ( 'interval' === $column_name ) : ?>
                        <?php
                        if ( ! $disabled ) {
                            $mo_interval = MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'interval', (int) $website['interval'], $this->global_settings, -1, 60 );
                            echo '<i class="clock outline icon"></i> ' . ( -1 === (int) $mo_interval ? esc_html__( 'Use global setting', 'mainwp' ) : intval( $mo_interval ) . esc_html__( ' minutes', 'mainwp' ) );
                        }
                        ?>
                    <?php elseif ( 'status_code' === $column_name ) : ?>
                        <?php
                        if ( ! $disabled ) {
                            echo esc_html( $website['http_response_code'] );
                        }
                        ?>
                    <?php elseif ( 'last24_status' === $column_name ) : ?>
                        <?php
                        if ( ! $disabled ) {
                            $this->render_last24_uptime_status( $uptime_status, $last24_time );
                        }
                        ?>
                        <?php
                    elseif ( 'last_check' === $column_name ) :
                        if ( ! $disabled ) {
                            $this->column_last_check( $website );
                        }
                        ?>
                        <?php
                    elseif ( 'site_health' === $column_name ) :
                        if ( ! $disabled ) :
                            ?>
                        <span><a class="open_newwindow_wpadmin" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website['id'] ); ?>&location=<?php echo esc_attr( base64_encode( 'site-health.php' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>&_opennonce=<?php echo esc_attr( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank"><span class="ui <?php echo esc_html( $h_color ); ?> empty circular label"></span></a> <?php echo esc_html( $h_text ); ?></span>
                            <?php
                        endif;
                    elseif ( 'site_actions' === $column_name ) :
                        ?>
                        <div class="ui right pointing dropdown"  style="z-index:999;">
                            <i class="ellipsis vertical icon"></i>
                            <div class="menu" siteid="<?php echo intval( $website['id'] ); ?>">
                                <a class="managesites_checknow item" href="#"><?php esc_html_e( 'Check Now', 'mainwp' ); ?></a>
                                <?php if ( empty( $website['sync_errors'] ) ) : ?>
                                <a class="managesites_syncdata item" href="#"><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
                                <?php endif; ?>
                                <?php if ( \mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) : ?>
                                <a class="item" href="admin.php?page=managesites&dashboard=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else : ?>
                                <?php echo $this->column_default( $website, $column_name ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php endif; ?>
                    <?php
                    $cols_data[ $column_name ] = ob_get_clean();
                }
                $all_rows[]  = $cols_data;
                $info_rows[] = $info_item;

                $sub_pages    = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_sub_pages( array( 'wpid' => $website['id'] ) );
                $child_rows[] = ! empty( $sub_pages ) ? $this->get_monitors_table_child_rows( $website['id'], $sub_pages ) : '';
            }
        }
        return array(
            'data'            => $all_rows,
            'recordsTotal'    => $this->total_items,
            'recordsFiltered' => $this->total_items,
            'rowsInfo'        => $info_rows,
            'childRows'       => $child_rows,
        );
    }

    /**
     * Method column_site.
     *
     * @param  mixed $website website.
     *
     * @return void
     */
    public function column_site( $website ) {
        ?>
        <?php if ( ! \mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
            <i class="sign in icon"></i>
        <?php else : ?>
            <a href="<?php MainWP_Site_Open::get_open_site_url( $website['id'] ); ?>" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
        <?php endif; ?>
        <?php if ( empty( $website['issub'] ) ) { // primary monitor. ?>
            <a href="<?php echo 'admin.php?page=managesites&dashboard=' . intval( $website['id'] ); ?>"><?php echo esc_html( stripslashes( $website['name'] ) ); ?></a><i class="ui active inline loader tiny" style="display:none"></i><span id="site-status-<?php echo esc_attr( $website['id'] ); ?>" class="status hidden"></span>
            <br/>
            <span class="ui small text"><a href="<?php echo esc_url( $website['url'] ); ?>" class="mainwp-may-hide-referrer open_site_url" target="_blank"><?php echo esc_html( MainWP_Utility::get_nice_url( $website['url'] ) ); ?></a></span>
            <?php
        } else {
            $subpage = $website['url'] . $website['suburl'];
            ?>
            <a href="admin.php?page=managesites&monitor_wpid=<?php echo intval( $website['id'] ); ?>&monitor_id=<?php echo intval( $website['monitor_id'] ); ?>"><?php echo esc_html( $subpage ); ?></a><i class="ui active inline loader tiny" style="display:none"></i><span id="site-status-<?php echo esc_attr( $website['id'] ); ?>" class="status hidden"></span>
            <br/>
            <span class="ui small text"><a href="<?php echo esc_url( $subpage ); ?>" class="mainwp-may-hide-referrer open_site_url" target="_blank"><?php echo esc_html( MainWP_Utility::get_nice_url( $subpage ) ); ?></a></span>
        <?php } ?>
        <?php
    }

    /**
     * Method column_type
     *
     * @param  string $mo_type mointor type.
     *
     * @return void
     */
    public function column_type( $mo_type ) {
        $mo_type_text = '';
        if ( 'http' === $mo_type ) {
            $mo_type_text = esc_html__( 'HTTP(s)', 'mainwp' );
        } elseif ( 'ping' === $mo_type ) {
            $mo_type_text = esc_html__( 'Ping', 'mainwp' );
        } elseif ( 'keyword' === $mo_type ) {
            $mo_type_text = esc_html__( 'Keyword', 'mainwp' );

        }
        echo '<span class="ui mini basic label">' . esc_html( $mo_type_text ) . '</span>';
    }

    /**
     * Method column_last_check
     *
     * @param  mixed $monitor monitor.
     * @param  bool  $sub_page sub page.
     *
     * @return void
     */
    public function column_last_check( $monitor, $sub_page = false ) {
        if ( $sub_page ) {
            $last_time = $monitor['lasttime_check'];
        } else {
            $last_time = $monitor['offline_checks_last'];
        }
        if ( ! empty( $last_time ) ) {
            echo '<span data-tooltip="' . esc_attr( MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $last_time ) ) ) . '" data-inverted="" data-position="left center">' . esc_html( MainWP_Utility::time_elapsed_string( $last_time ) ) . '</span>';
        } else {
            echo esc_html__( 'Not checked yet.', 'mainwp' );
        }
    }


    /**
     * Renders uptime status.
     *
     * @param int  $uptime_status uptime status.
     * @param int  $compatible_offline uptime status.
     * @param bool $mo_disabled Disabled monitor or not.
     *
     * @return void
     */
    public function render_uptime_status( $uptime_status = false, $compatible_offline = false, $mo_disabled = false ) {

        if ( $mo_disabled ) {
            echo '<span class="ui big circular icon grey looping transition label"><i class="stop circle outline icon"></i></span>';
            return;
        }

        if ( false !== $compatible_offline ) {
            $uptime_status = 99; // pendding.
            if ( -1 === (int) $compatible_offline ) {
                $uptime_status = 0; // to compatible value 'offline_check_result' with 'last_status'.
            } elseif ( 1 === (int) $compatible_offline ) {
                $uptime_status = 1;
            }
        }

        if ( 1 === (int) $uptime_status ) {
            echo '<span class="ui big circular icon green looping pulsating transition label"><i class="chevron up icon"></i></span>';
        } elseif ( 0 === (int) $uptime_status ) {
            echo '<span class="ui big circular icon red looping pulsating transition label"><i class="chevron down icon"></i></span>';
        } else {
            echo '<span class="ui big circular icon grey looping pulsating transition label"><i class="circle outline icon"></i></span>';
        }
    }

    /**
     * Render last24 uptime status.
     *
     * @param  array $data data.
     * @param  int   $last24_starttime last24 start time.
     * @return void
     */
    public function render_last24_uptime_status( $data, $last24_starttime ) { //phpcs:ignore -- NOSONAR - complexity.

        if ( empty( $data ) || ! is_array( $data ) ) {
            $data = array();
        }

        $uptime_data = array();

        foreach ( $data as $value ) {
            $uptime_data[ $value['timestamp'] ] = $value;
        }

        $hourly_key = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( $last24_starttime );

        $empty = array(
            'up'       => 0,
            'down'     => 0,
            'ping_avg' => 0,
            'ping_min' => 0,
            'ping_max' => 0,
        );

        $total_up   = 0;
        $total_down = 0;
        for ( $i = 0; $i < 24; $i++ ) {
            if ( isset( $uptime_data[ $hourly_key ] ) ) {
                $up_stats = $uptime_data[ $hourly_key ];
                $color    = $up_stats['up'] ? 'green' : 'red';
                $text     = $up_stats['up'] ? __( 'Up', 'mainwp' ) : __( 'Down', 'mainwp' );
            } else {
                $up_stats = $empty;
                $color    = 'gray';
                $text     = __( 'Pending', 'mainwp' );
            }
            $total_up   += $up_stats['up'];
            $total_down += $up_stats['down'];

            $local_time = MainWP_Utility::get_timestamp( $hourly_key );
            $time_from  = MainWP_Utility::format_timestamp( $local_time ) . ' ' . MainWP_Utility::format_time( $local_time + HOUR_IN_SECONDS );
            ?>
            <span class="mainwp-html-popup" data-inverted="" data-position="top center" html-popup-content="<?php echo esc_html( $time_from ) . '<br/><strong>' . esc_html( $text ) . ' ' . ( $up_stats['up'] ? (int) ( $up_stats['up'] * 100 / ( $up_stats['up'] + $up_stats['down'] ) ) . '%' : '' ); ?></strong>">
                <label class="mainwp-uptime-status-indicator ui label <?php echo esc_attr( $color ); ?>"></label>
            </span>
            <?php
            $hourly_key = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( $hourly_key + HOUR_IN_SECONDS );
        }
        ?>
        <?php
        echo '<br/><span class="ui small text">' . ( $total_up ? esc_html__( 'Up', 'mainwp' ) . ' ' . (int) ( $total_up * 100 / ( $total_up + $total_down ) ) . ' %' : esc_html__( 'Down', 'mainwp' ) ) . '</span>';
    }




    /**
     * Get monitors table child rows.
     *
     * @param  int   $site_id the site id.
     * @param  array $sub_pages sub page.
     * @return string format html content.
     */
    public function get_monitors_table_child_rows( $site_id, $sub_pages ) {
        ob_start();
        ?>
        <table id="monitors-sub-pages-table-<?php echo intval( $site_id ); ?>" style="width:100%;" class="ui single line selectable unstackable table mainwp-manage-updates-table uptime-monitors-sub-pages-table">
            <thead class="mainwp-768-hide">
                <tr>
                <?php $this->print_column_headers( false, true, true, $site_id ); ?>
                </tr>
            </thead>
            <tbody class="uptime-monitoring-sub-pages-monitors-body monitors-sub-pages-table-body-<?php echo intval( $site_id ); ?>">
            <?php
            foreach ( $sub_pages as $mo_page ) :
                $this->single_row( $mo_page );
            endforeach;
            ?>
            </tbody>
        </table>
        <?php

        return ob_get_clean();
    }
}
