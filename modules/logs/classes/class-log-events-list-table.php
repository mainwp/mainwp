<?php
/**
 * Manage Logs List Table.
 *
 * @package     MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Updates_Helper;

/**
 * Class Log_Events_List_Table
 *
 * @package MainWP\Dashboard
 */
class Log_Events_List_Table { //phpcs:ignore -- NOSONAR - complex.

    /**
     * Holds instance of manager object
     *
     * @var Log_Manager
     */
    public $manager;


    /**
     * Public variable to hold Items information.
     *
     * @var array
     */
    public $items;

    /**
     * Public variable to hold Previous Items information.
     *
     * @var array
     */
    public $items_prev;


    /**
     * Public variable to hold type value.
     *
     * @var array
     */
    public $table_id_prefix = null;


    /**
     * Public variable to hold total items number.
     *
     * @var integer
     */
    public $total_items;

    /**
     * Protected variable to hold columns headers
     *
     * @var array
     */
    protected $column_headers;

    /**
     * Class constructor.
     *
     * Run each time the class is called.
     *
     * @param Log_Manager $manager Instance of manager object.
     * @param Strings     $type Events table type: manage_events|widget_overview|widget_insight.
     */
    public function __construct( $manager, $type = '' ) {
        $this->manager         = $manager;
        $this->table_id_prefix = $type;
    }

    /**
     * Get the default primary column name.
     *
     * @return string Child Site name.
     */
    protected function get_default_primary_column_name() {
        return 'name';
    }

    // @NO_SONAR_START@ - duplicated issue.
    /**
     * Get sortable columns.
     *
     * @return array $sortable_columns Array of sortable column names.
     */
    public function get_sortable_columns() {
        return array(
            'event'         => array( 'event', false ),
            'log_object'    => array( 'log_object', false ),
            'created'       => array( 'created', false ),
            'log_site_name' => array( 'name', false ),
            'user_id'       => array( 'user_id', false ),
            'source'        => array( 'source', false ),
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
            'event'         => esc_html__( 'Event', 'mainwp' ),
            'log_object'    => esc_html__( 'Object', 'mainwp' ),
            'created'       => esc_html__( 'Date', 'mainwp' ),
            'log_site_name' => esc_html__( 'Website', 'mainwp' ),
            'user_id'       => esc_html__( 'User', 'mainwp' ),
            'source'        => esc_html__( 'Source', 'mainwp' ),
            'col_action'    => '',
        );

        if ( 'manage-events' !== $this->table_id_prefix ) {
            unset( $columns['source'] );
        }

        return $columns;
    }

    /**
     * Method get_columns()
     *
     * Combine all columns.
     *
     * @return array $columns Array of column names.
     */
    public function get_columns() {
        return $this->get_default_columns();
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
     * @return array $defines
     */
    public function get_columns_defines() {
        $defines   = array();
        $defines[] = array(
            'targets'   => 'no-sort',
            'orderable' => false,
        );
        $defines[] = array(
            'targets'   => 'manage-cb-column',
            'className' => 'check-column',
        );
        $defines[] = array(
            'targets'   => 'manage-created-column',
            'className' => 'mainwp-created-cell',
        );
        $defines[] = array(
            'targets'   => 'manage-site-column',
            'className' => 'column-site-bulk mainwp-site-cell',
        );
        $defines[] = array(
            'targets'   => 'manage-user-column',
            'className' => 'mainwp-user-cell',
        );
        $defines[] = array(
            'targets'   => array( 'manage-col_action-column' ),
            'className' => 'collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-log_object-column' ),
            'className' => 'mainwp-sites-changes-object-cell',
        );

        return $defines;
    }
    // @NO_SONAR_END@  .

    /**
     * Returns the column content for the provided item and column.
     *
     * @param object $item         Record data.
     * @param string $column_name  Column name.
     * @return string $out Output.
     */
    public function column_default( $item, $column_name ) { //phpcs:ignore -- NOSONAR -complex.
        $out = '';

        $record = new Log_Record( $item );

        $escaped = false;
        switch ( $column_name ) {
            case 'cb':
                $out     = '<div class="ui checkbox">
                <input type="checkbox" value="' . (int) $record->log_id . '" name="" aria-label="' . esc_attr__( 'Select the change.', 'mainwp' ) . '"/>
                </div>';
                $escaped = true;
                break;
            case 'event':
                $act_label = $this->get_action_title( $record->action, 'action', true );
                $out       = $act_label;
                $escaped   = true;
                break;
            case 'log_object':
                $event_title = $this->parse_event_title( $record );
                $out         = $event_title;
                $escaped     = true;
                break;
            case 'created':
                $date_string = sprintf(
                    '<time datetime="%s" class="relative-time record-created">%s</time>',
                    mainwp_module_log_get_iso_8601_extended_date( $record->created ),
                    MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $record->created ) )
                );
                $out         = $date_string;
                $escaped     = true;
                break;
            case 'log_site_name':
                $out     = ! empty( $record->log_site_name ) ? '<a href="admin.php?page=managesites&dashboard=' . intval( $record->site_id ) . '">' . esc_html( $record->log_site_name ) . '</a>' : 'N/A';
                $escaped = true;
                break;
            case 'user_id':
                $user = new Log_Author( $record->user_id, $record->user_meta );
                $out  = $user->get_display_name();
                if ( empty( $out ) ) {
                    $out = $user->get_agent_label( $user->get_agent() );
                }
                $escaped = true;
                break;
            case 'source':
                if ( ! empty( $record->source ) ) {
                    $out = $record->source; // sub query field.
                } else {
                    $out = 'non-mainwp-changes' === $record->connector ? 'WP Admin' : 'Dashboard';
                }
                break;
            case 'col_action':
                ob_start();
                ?>
                <div action-id="<?php echo intval( $record->log_id ); ?>">
                    <a class="ui mini green button insights-actions-row-dismiss" href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Dismiss the change.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a>
                </div>
                <?php
                $out = ob_get_clean();
                break;
            default:
        }

        if ( empty( $out ) ) {
            return '';
        }

        if ( ! $escaped ) {
            $allowed_tags         = wp_kses_allowed_html( 'post' );
            $allowed_tags['time'] = array(
                'datetime' => true,
                'class'    => true,
            );

            $allowed_tags['img']['srcset'] = true;

            return wp_kses( $out, $allowed_tags );
        } else {
            return $out; //phpcs:ignore -- escaped.
        }
    }


    /**
     * Returns the label for a status.
     *
     * @param object $record record item.
     *
     * @return string
     */
    public function get_state_title( $record ) {

        $state = esc_html__( 'Undefined', 'mainwp' );
        if ( is_null( $record->state ) ) {
            $state = 'N/A';
        } elseif ( '0' === $record->state ) {
            $state = esc_html__( 'Failed', 'mainwp' );
        } elseif ( '1' === $record->state ) {
            $state = esc_html__( 'Success', 'mainwp' );
        }
        return $state;
    }

    /**
     * Returns the label for a connector term.
     *
     * @param string $act  Action type.
     * @param string $type  Connector term.
     * @param bool   $coloring Coloring term.
     * @return string
     */
    public function get_action_title( $act, $type, $coloring = false ) {

        if ( ! isset( $this->manager->connectors->term_labels[ 'logs_' . $type ][ $act ] ) ) {
            $title = $act;
        } else {
            $title = $this->manager->connectors->term_labels[ 'logs_' . $type ][ $act ];
        }

        $title = is_string( $title ) ? ucfirst( $title ) : $title;
        if ( $coloring ) {
            $format_title = '<span class="ui medium text">%s</span>';
            if ( 'deactivate' === $act || 'deleted' === $act || 'delete' === $act ) {
                $format_title = '<span class="ui medium red text">%s</span>';
            } elseif ( 'activate' === $act || 'updated' === $act || 'update' === $act ) {
                $format_title = '<span class="ui medium blue text">%s</span>';
            } elseif ( 'install' === $act || 'created' === $act || 'added' === $act || 'sync' === $act ) {
                $format_title = '<span class="ui medium green text">%s</span>';
            }
            $title = sprintf( $format_title, esc_html( $title ) );
        }
        return $title;
    }

    /**
     * Parse event title.
     *
     * @param object $record  Log record object.
     * @return string
     */
    public function parse_event_title( $record ) {
        $extra_meta = ! empty( $record->extra_meta ) ? json_decode( $record->extra_meta, true ) : array();
        if ( ! is_array( $extra_meta ) ) {
            $extra_meta = array();
        }
        $title    = '';
        $roll_msg = '';
        if ( 'posts' === $record->connector || 'users' === $record->connector || 'client' === $record->connector || 'installer' === $record->connector ) {
            $title = $record->item;
            if ( 'installer' === $record->connector && ! empty( $extra_meta['rollback_info'] ) ) {
                $roll_msg = MainWP_Updates_Helper::get_roll_msg( $extra_meta['rollback_info'], true ) . ' ';
            }
        } elseif ( 'site' === $record->connector ) {
            $title = esc_html__( 'Website', 'mainwp' );
        } elseif ( 'non-mainwp-changes' === $record->connector ) {
            $title = esc_html( $record->item );
        } elseif ( isset( $extra_meta['name'] ) ) {
            $title = $extra_meta['name'];
            if ( 'installer' === $record->connector && ! empty( $extra_meta['rollback_info'] ) ) {
                $roll_msg = MainWP_Updates_Helper::get_roll_msg( $extra_meta['rollback_info'], true ) . ' ';
            }
        }
        $title = $roll_msg . esc_html( $title ) . $this->get_context_title( $record->context, $record->connector );
        return $title;
    }

    /**
     * Returns the label for a context.
     *
     * @param string $context Log context.
     * @param string $connector Log connector.
     * @return string
     */
    public function get_context_title( $context, $connector ) {
        $title = '';

        if ( 'plugin' === $context ) {
            $title = esc_html__( 'Plugin', 'mainwp' );
        } elseif ( 'theme' === $context ) {
            $title = esc_html__( 'Theme', 'mainwp' );
        } elseif ( 'translation' === $context ) {
            $title = esc_html__( 'Translation', 'mainwp' );
        } elseif ( 'core' === $context ) {
            $title = esc_html__( 'WP Core WordPress', 'mainwp' );
        } elseif ( 'posts' === $connector ) {
            if ( 'post' === $context ) {
                $title = esc_html__( 'Post', 'mainwp' );
            } elseif ( 'page' === $context ) {
                $title = esc_html__( 'Page', 'mainwp' );
            } else {
                $title = esc_html__( 'Custom Post', 'mainwp' );
            }
        } elseif ( 'clients' === $context ) {
            $title = esc_html__( 'Client', 'mainwp' );
        } elseif ( 'users' === $context ) {
            $title = esc_html__( 'User', 'mainwp' );
        }

        if ( ! empty( $title ) ) {
            $title = ' ' . $title;
        }

        return $title;
    }

    /**
     * Html output if no Child Sites are connected.
     */
    public function no_items() {
        ?>
        <div class="ui center aligned segment">
            <i class="globe massive icon"></i>
            <div class="ui header">
                <?php esc_html_e( 'No records found.', 'mainwp' ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Method has_items().
     *
     * Verify if items exist.
     */
    public function has_items() {
        return ! empty( $this->items );
    }

    /**
     * Get last query found rows
     *
     * @return integer
     */
    public function get_total_found_rows() {
        return $this->total_items;
    }

    /**
     * Prepare the items to be listed.
     *
     * @param bool  $with_prev_data To get previous data.
     * @param array $insights_filters Required: Insights filters.
     */
    public function prepare_items( $with_prev_data, $insights_filters ) { //phpcs:ignore -- NOSONAR - complex method.

        $req_orderby = '';
        $req_order   = null;

        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_REQUEST['order'] ) ) {
            $order_values = MainWP_Utility::instance()->get_table_orders( $_REQUEST );
            $req_orderby  = $order_values['orderby'];
            $req_order    = $order_values['order'];
        }

        $filter_dtsstart   = '';
        $filter_dtsstop    = '';
        $array_clients_ids = array();
        $array_groups_ids  = array();
        $array_users_ids   = array();

        $sources_conds     = '';
        $array_sites_ids   = array();
        $array_events_list = array();

        extract( $insights_filters ); //phpcs:ignore -- ok.

        if ( ! empty( $filter_dtsstart ) && ! empty( $filter_dtsstop ) && ! is_numeric( $filter_dtsstart ) && ! is_numeric( $filter_dtsstop ) ) { // after extract.
            // to fix string of date.
            $filter_dtsstart = gmdate( 'Y-m-d', strtotime( $filter_dtsstart ) );
            $filter_dtsstop  = gmdate( 'Y-m-d', strtotime( $filter_dtsstop ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification

         // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $perPage = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : false;
        if ( -1 === (int) $perPage || empty( $perPage ) ) {
            $perPage = 9999999;
        }
        $start = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;

        $search = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';

        $recent_number = isset( $_REQUEST['recent_number'] ) ? intval( $_REQUEST['recent_number'] ) : 0;

        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $args = array(
            'order'         => ( 'asc' === $req_order ) ? 'asc' : 'desc',
            'orderby'       => $req_orderby,
            'start'         => $start,
            'recent_number' => $recent_number,
            'search'        => $search,
            'groups_ids'    => $array_groups_ids,
            'client_ids'    => $array_clients_ids,
            'user_ids'      => $array_users_ids,
            'timestart'     => ! empty( $filter_dtsstart ) ? strtotime( $filter_dtsstart . ' 00:00:00' ) : '',
            'timestop'      => ! empty( $filter_dtsstop ) ? strtotime( $filter_dtsstop . ' 23:59:59' ) : '',
            'dismiss'       => 0,
            'view'          => 'events_list',
            'wpid'          => ! empty( $insights_filters['wpid'] ) ? $insights_filters['wpid'] : 0, // int or array of site ids.
            'sources_conds' => $sources_conds,
            'sites_ids'     => $array_sites_ids,
            'events'        => $array_events_list,
        );

        $args['records_per_page'] = $perPage;
        $args['dev_log_query']    = 0; // 1 for dev logs.

        $this->items       = $this->manager->db->get_records( $args );
        $this->total_items = $this->manager->db->get_found_records_count(); // get this value for recent events request only.

        $this->items_prev = array();
        if ( $with_prev_data && ! empty( $args['timestart'] ) && ! empty( $args['timestop'] ) && $args['timestart'] < $args['timestop'] ) {
            $timestart_prev    = $args['timestart'] - ( $args['timestop'] - $args['timestart'] );
            $timestop_prev     = $args['timestart'];
            $args['timestart'] = $timestart_prev;
            $args['timestop']  = $timestop_prev;
            $this->items_prev  = $this->manager->db->get_records( $args );
        }
    }


    /**
     * Display the table.
     */
    public function display() {

        $sites_per_page = get_option( 'mainwp_default_manage_insights_events_per_page', 25 );

        $sites_per_page = intval( $sites_per_page );

        $pages_length = array(
            25  => '25',
            10  => '10',
            50  => '50',
            100 => '100',
            300 => '300',
        );

        if ( ! isset( $pages_length[ $sites_per_page ] ) ) {
            $pages_length = $pages_length + array( $sites_per_page => $sites_per_page );
        }

        ksort( $pages_length );

        if ( isset( $pages_length[-1] ) ) {
            unset( $pages_length[-1] );
        }

        // @since version 5.4.1.
        $pages_length = apply_filters( 'mainwp_site_changes_table_pages_length', $pages_length, $this->table_id_prefix );

        $pagelength_val   = implode( ',', array_keys( $pages_length ) );
        $pagelength_title = implode( ',', array_values( $pages_length ) );

        $ajaxaction = 'mainwp_module_log_manage_events_display_rows';
        if ( 'widget-insight' === $this->table_id_prefix ) {
            $ajaxaction = 'mainwp_module_log_widget_insights_display_rows';
        } elseif ( 'widget-overview' === $this->table_id_prefix ) {
            $ajaxaction = 'mainwp_module_log_widget_events_overview_display_rows';
        }

        $events_tbl_id = 'mainwp-module-log-records-table';
        if ( ! empty( $this->table_id_prefix ) ) {
            $events_tbl_id .= '-' . esc_attr( $this->table_id_prefix );
        }
        if ( 'manage-events' === $this->table_id_prefix ) {
            ?>
        <div class="ui segment">
            <?php
        }
        ?>
        <table id="<?php echo esc_attr( $events_tbl_id ); ?>" style="width:100%" class="ui single line <?php echo 'manage-events' === $this->table_id_prefix ? 'selectable' : ''; ?> unstackable table mainwp-with-preview-table">
            <thead>
                <tr><?php $this->print_column_headers( true ); ?></tr>
            </thead>
            <tfoot>
                <tr><?php $this->print_column_headers( false ); ?></tr>
            </tfoot>
        </table>
        <?php

        if ( 'manage-events' === $this->table_id_prefix ) {
            ?>
            </div>
            <?php
        }

        $count = $this->get_total_found_rows();
        if ( empty( $count ) ) {
            ?>
        <div id="sites-table-count-empty" style="display: none;">
            <?php $this->no_items(); ?>
        </div>
            <?php
        }
        ?>
    <div id="mainwp-loading-sites" style="display: none;">
    <div class="ui active inverted dimmer">
    <div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading ...', 'mainwp' ); ?></div>
    </div>
    </div>
        <?php
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
            'fixedColumns'  => '',
        );

        // Fix for widget state save overview table.
        if ( 'widget-overview' === $this->table_id_prefix ) {
            $table_features['stateSave'] = 'false';
        }

        ?>

    <script type="text/javascript">
            var responsive = <?php echo esc_js( $table_features['responsive'] ); ?>;
            if( jQuery( window ).width() > 1140 ) {
                responsive = false;
            }
            var $module_log_table = null;
            jQuery( document ).ready( function( $ ) {
                    const manage_tbl_id = '#<?php echo esc_js( $events_tbl_id ); ?>';
                    const ajax_action = '<?php echo esc_js( $ajaxaction ); ?>';

                    let widgetViewSource = '';

                    if( jQuery('#widget-sites-changes-dropdown-selector').length ){
                        widgetViewSource = mainwp_ui_state_load('sites-changes-widget');
                        widgetViewSource = ['wp-admin', 'dashboard', '' ].includes(widgetViewSource) ? widgetViewSource : 'wp-admin';
                    }

                    try {
                        //jQuery( '#mainwp-sites-table-loader' ).hide();
                        $module_log_table = jQuery( manage_tbl_id ).on( 'processing.dt', function ( e, settings, processing ) {
                            jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
                            if (!processing) {
                                let tb = jQuery( manage_tbl_id );
                                tb.find( 'th[cell-cls]' ).each( function(){
                                    let ceIdx = this.cellIndex;
                                    let cls = jQuery( this ).attr( 'cell-cls' );
                                    jQuery( manage_tbl_id + ' tr' ).each(function(){
                                        jQuery(this).find( 'td:eq(' + ceIdx + ')' ).addClass(cls);
                                    } );
                                } );
                                $( manage_tbl_id + ' .ui.dropdown' ).dropdown();
                                $( manage_tbl_id + ' .ui.checkbox' ).checkbox();
                            }
                        } ).DataTable( {
                            "ajax": {
                                "url": ajaxurl,
                                "type": "POST",
                                "data":  function ( d ) {
                                    let data = mainwp_secure_data( {
                                        action: ajax_action,
                                        range: $( '#mainwp-module-log-filter-ranges').length ? $( '#mainwp-module-log-filter-ranges').dropdown('get value') : '',
                                        group: $( '#mainwp-module-log-filter-groups').length ? $( '#mainwp-module-log-filter-groups').dropdown('get value') : '',
                                        client: $( '#mainwp-module-log-filter-clients').length ? $( '#mainwp-module-log-filter-clients').dropdown('get value') : 0,
                                        user: $( '#mainwp-module-log-filter-users').length ? $( '#mainwp-module-log-filter-users').dropdown('get value') : 0,
                                        dtsstart: $('#mainwp-module-log-filter-dtsstart input[type=text]').length ? $('#mainwp-module-log-filter-dtsstart input[type=text]').val() : '',
                                        dtsstop: $('#mainwp-module-log-filter-dtsstop input[type=text]').length ? $('#mainwp-module-log-filter-dtsstop input[type=text]').val() : '',
                                        current_client_id: $( '#mainwp-widget-filter-current-client-id').length ? $( '#mainwp-widget-filter-current-client-id').val() : 0,
                                        current_site_id: $( '#mainwp-widget-filter-current-site-id').length ? $( '#mainwp-widget-filter-current-site-id').val() : 0,
                                    } );

                                    if('mainwp_module_log_manage_events_display_rows' === ajax_action ){
                                        data.source =  $( '#mainwp-module-log-filter-source').length ? $( '#mainwp-module-log-filter-source').dropdown('get value') : '';
                                        data.sites =  $( '#mainwp-module-log-filter-sites').length ? $( '#mainwp-module-log-filter-sites').dropdown('get value') : '';
                                        data.events =  $( '#mainwp-module-log-filter-events').length ? $( '#mainwp-module-log-filter-events').dropdown('get value') : '';
                                    } else {
                                        if( 'mainwp_module_log_widget_events_overview_display_rows' === ajax_action ){
                                            data.source = widgetViewSource;
                                        } else if( 'mainwp_module_log_widget_insights_display_rows' === ajax_action ) {
                                            // set recent number for none-manage-events table.
                                            data.recent_number =  $( '#mainwp-widget-filter-events-limit').length ? $( '#mainwp-widget-filter-events-limit').val() : 100;
                                        }
                                    }

                                    if('mainwp_module_log_manage_events_display_rows' === ajax_action ){
                                        if( ( data.source == '' || data.source == 'allsource' ) &&
                                            ( data.events == '' || data.events == 'allevents' ) &&
                                            ( data.sites == '' || data.sites == 'allsites' ) &&
                                            ( data.group == '' || data.group == 'alltags' ) &&
                                            ( data.client == '' || data.client == 'allclients' ) &&
                                            ( data.user == '' || data.user == 'allusers' ) &&
                                            data.range == 'thismonth' &&
                                            data.current_client_id == '' &&
                                            data.current_site_id == ''
                                        ){
                                            jQuery('#mainwp-module-log-filters-row').fadeOut(300);
                                        } else{
                                            jQuery('#mainwp-module-log-filters-row').fadeIn(300);
                                        }
                                    }

                                    return $.extend( {}, d, data );
                                },
                                "dataSrc": function ( json ) {
                                    for ( let i=0, ien=json.data.length ; i < ien ; i++ ) {
                                        json.data[i].rowClass = json.rowsInfo[i].rowClass;
                                        json.data[i].log_id = json.rowsInfo[i].log_id;
                                        json.data[i].created_sort = json.rowsInfo[i].created;
                                        json.data[i].state_sort = json.rowsInfo[i].state;
                                    }
                                    return json.data;
                                }
                            },
                            "responsive": responsive,
                            "searching" : <?php echo esc_js( $table_features['searching'] ); ?>,
                            "paging" : <?php echo esc_js( $table_features['paging'] ); ?>,
                            "pagingType" : "<?php echo esc_js( $table_features['pagingType'] ); ?>",
                            "info" : <?php echo esc_js( $table_features['info'] ); ?>,
                            "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                            "scrollX" : <?php echo esc_js( $table_features['scrollX'] ); ?>,
                            "stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
                            "stateDuration" : <?php echo esc_js( $table_features['stateDuration'] ); ?>,
                            "order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
                            "fixedColumns" : <?php echo ! empty( $table_features['fixedColumns'] ) ? esc_js( $table_features['fixedColumns'] ) : '""'; ?>,
                            "lengthMenu" : [ [<?php echo esc_js( $pagelength_val ); ?>], [<?php echo esc_js( $pagelength_title ); ?>] ],
                            "serverSide": true,
                            "pageLength": <?php echo intval( $sites_per_page ); ?>,
                            "columnDefs": <?php echo wp_json_encode( $this->get_columns_defines() ); ?>,
                            "columns": <?php echo wp_json_encode( $this->get_columns_init() ); ?>,
                            "language": {
                                "emptyTable": "<?php esc_html_e( 'No events found.', 'mainwp' ); ?>"
                            },
                            "drawCallback": function( settings ) {
                                this.api().tables().body().to$().attr( 'id', 'mainwp-module-log-records-body-table' );
                                mainwp_datatable_fix_menu_overflow(manage_tbl_id);
                                if ( typeof mainwp_preview_init_event !== "undefined" ) {
                                    mainwp_preview_init_event();
                                }
                                //jQuery( '#mainwp-sites-table-loader' ).hide();
                                if ( jQuery('#mainwp-module-log-records-body-table td.dt-empty').length > 0 && jQuery('#sites-table-count-empty').length ){
                                    jQuery('#mainwp-module-log-records-body-table td.dt-empty').html(jQuery('#sites-table-count-empty').html());
                                }
                                if( 'manage-events' === '<?php echo esc_js( $this->table_id_prefix ); ?>' ){
                                    setTimeout(() => {
                                        jQuery(manage_tbl_id + ' .ui.checkbox').checkbox();
                                        mainwp_datatable_fix_menu_overflow(manage_tbl_id);
                                        mainwp_table_check_columns_init(manage_tbl_id);
                                    }, 1000);
                                }
                            },
                            "initComplete": function( settings, json ) {
                            },
                            rowCallback: function (row, data) {
                                jQuery( row ).addClass(data.rowClass);
                                jQuery( row ).attr( 'id', "log-row-" + data.log_id );
                                jQuery( row ).attr( 'log-id',  data.log_id );
                                jQuery( row ).find('.mainwp-date-cell').attr('data-sort', data.created_sort );
                                jQuery( row ).find('.mainwp-state-cell').attr('data-sort', data.state_sort );
                            }
                            <?php
                            if ( 'manage-events' === $this->table_id_prefix ) {
                                echo ",select: {
                                    items: 'row',
                                    style: 'multi+shift',
                                    selector: 'tr>td.check-column'
                                }"; // phpcs:ignore -- NOSONAR -- ok.
                            }
                            ?>
                        })

                        if( 'manage-events' === '<?php echo esc_js( $this->table_id_prefix ); ?>' ){
                            $module_log_table.on('select', function (e, dt, type, indexes) {
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
                            });
                        }
                    } catch(err) {
                        // to fix js error.
                        console.log(err);
                    }

                    let $sitesChangesSelect = jQuery( '#widget-sites-changes-dropdown-selector' ).dropdown( {
                        onChange: function( value ) {
                            mainwp_ui_state_save('sites-changes-widget', value);
                            if(widgetViewSource !== value ){
                                widgetViewSource = value;
                                $module_log_table.ajax.reload();
                            }
                        }
                    } ).dropdown("set selected", widgetViewSource);

                    mainwp_module_log_overview_content_filter = function() {
                        if(jQuery( '#mainwp-common-filter-segments-model-name').length && 'manage-events' === jQuery( '#mainwp-common-filter-segments-model-name').val() ){
                            mainwp_module_log_manage_events_filter();
                            return;
                        }
                        let range = jQuery( '#mainwp-module-log-filter-ranges').dropdown('get value');
                        let group = jQuery( '#mainwp-module-log-filter-groups').dropdown('get value');
                        let client = jQuery( '#mainwp-module-log-filter-clients').dropdown('get value');
                        let user = jQuery( '#mainwp-module-log-filter-users').dropdown('get value');
                        let dtsstart = jQuery('#mainwp-module-log-filter-dtsstart input[type=text]').val();
                        let dtsstop = jQuery('#mainwp-module-log-filter-dtsstop input[type=text]').val();
                        let params = '';
                        params += '&range=' + encodeURIComponent( range );
                        params += '&group=' + encodeURIComponent( group );
                        params += '&client=' + encodeURIComponent( client );
                        params += '&user=' + encodeURIComponent( user );
                        params += '&dtsstart=' + encodeURIComponent( dtsstart );
                        params += '&dtsstop=' + encodeURIComponent( dtsstop );
                        params += '&_insights_opennonce=' + mainwpParams._wpnonce;
                        window.location = 'admin.php?page=InsightsOverview' + params;
                        return false;
                    };
                    mainwp_module_log_overview_content_reset_filters = function(resetObj) {
                        try {
                            jQuery( '#mainwp-module-log-filter-ranges').dropdown('set selected', 'thismonth');
                            jQuery( '#mainwp-module-log-filter-groups').dropdown('clear');
                            jQuery( '#mainwp-module-log-filter-clients').dropdown('clear');
                            jQuery( '#mainwp-module-log-filter-users').dropdown('clear');
                            jQuery('#mainwp-module-log-filter-dtsstart input[type=text]').val('');
                            jQuery('#mainwp-module-log-filter-dtsstop input[type=text]').val('');
                            jQuery(resetObj).attr('disabled', 'disabled');
                            mainwp_module_log_overview_content_filter();
                        } catch(err) {
                            // to fix js error.
                            console.log(err);
                        }
                    };
                    _init_manage_events_screen();
            } );

            _init_manage_events_screen = function() {
                jQuery( '#mainwp-manage-events-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
                    let check_id = jQuery( this ).attr( 'id' );
                    col_id = check_id.replace( "mainwp_show_column_", "" );
                    try {
                        $module_log_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
                    } catch(err) {
                        // to fix js error.
                    }
                } );
            };

            mainwp_manage_events_screen_options = function () {
                jQuery( '#mainwp-manage-events-screen-options-modal' ).modal( {
                    allowMultiple: true,
                    onHide: function () {
                        //ok.
                    }
                } ).modal( 'show' );

                jQuery( '#manage-events-screen-options-form' ).submit( function() {
                    if ( jQuery('input[name=reset_manage_events_columns_order]').attr('value') == 1 ) {
                        $module_log_table.colReorder.reset();
                    }
                    jQuery( '#mainwp-manage-events-screen-options-modal' ).modal( 'hide' );
                } );
                return false;
            };

        </script>
        <?php
    }

    /**
     * Get the column count.
     *
     * @return int Column Count.
     */
    public function get_column_count() {
        list( $columns ) = $this->get_column_info();
        return count( $columns );
    }

    /**
     * Echo the column headers.
     *
     * @param  bool $top Top header.
     * @return void
     */
    public function print_column_headers( $top ) {
        list( $columns, $sortable ) = $this->get_column_info();

        $def_columns                 = $this->get_default_columns();
        $def_columns['site_actions'] = '';

        if ( ! empty( $columns['cb'] ) ) {
            $columns['cb'] = '<div class="ui checkbox"><input id="' . ( $top ? 'cb-select-all-top' : 'cb-select-all-bottom' ) . '" type="checkbox" aria-label="Select all clients." /></div>';
        }

        foreach ( $columns as $column_event_key => $column_display_name ) {

            $class = array( 'manage-' . $column_event_key . '-column' );
            $attr  = '';
            if ( ! isset( $def_columns[ $column_event_key ] ) ) {
                $class[]  = 'extra-column';
                    $attr = 'cell-cls="' . esc_html( "collapsing $column_event_key column-$column_event_key" ) . '"';
            }

            if ( ! isset( $sortable[ $column_event_key ] ) ) {
                $class[] = 'no-sort';
            }

            if ( 'cb' === $column_event_key ) {
                $class[] = 'check-column';
                $class[] = 'collapsing';
            }

            $tag = 'th';
            $id  = "id='$column_event_key'";

            if ( ! empty( $class ) ) {
                $class = "class='" . join( ' ', $class ) . "'";
            }

            echo "<$tag $id $class $attr>$column_display_name</$tag>"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    /**
     * Get column info.
     */
    protected function get_column_info() {

        if ( isset( $this->column_headers ) && is_array( $this->column_headers ) ) {
            $column_headers = array( array(), array(), array(), $this->get_default_primary_column_name() );
            foreach ( $this->column_headers as $key => $value ) {
                $column_headers[ $key ] = $value;
            }

            return $column_headers;
        }

        $columns = $this->get_columns();

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
     * Get table rows.
     *
     * Optimize for shared hosting or big networks.
     *
     * @return array Rows html.
     */
    public function ajax_get_datatable_rows() {

        $all_rows  = array();
        $info_rows = array();

        $columns = $this->get_columns();

        if ( $this->items ) {
            foreach ( $this->items as $log ) {
                $rw_classes = 'log-item mainwp-log-item-' . intval( $log->log_id );

                $info_item = array(
                    'rowClass' => esc_html( $rw_classes ),
                    'log_id'   => $log->log_id,
                    'site_id'  => ! empty( $log->site_id ) ? $log->site_id : 0,
                    'created'  => $log->created,
                    'state'    => is_null( $log->state ) ? - 1 : $log->state,
                );

                $cols_data = array();

                foreach ( $columns as $column_name => $column_display_name ) {
                    ob_start();
                    echo $this->column_default( $log, $column_name ); // phpcs:ignore WordPress.Security.EscapeOutput
                    $cols_data[ $column_name ] = ob_get_clean();
                }
                $all_rows[]  = $cols_data;
                $info_rows[] = $info_item;
            }
        }
        return array(
            'data'            => $all_rows,
            'recordsTotal'    => $this->total_items,
            'recordsFiltered' => $this->total_items,
            'rowsInfo'        => $info_rows,
        );
    }
}
