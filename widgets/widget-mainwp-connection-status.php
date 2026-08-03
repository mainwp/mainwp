<?php
/**
 * MainWP Connection Status
 *
 * Build the MainWP Operations page Connection Status Widget.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Connection_Status
 *
 * Build the Connection Status Widget.
 */
class MainWP_Connection_Status { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Public variable to hold Items information.
     *
     * @var array
     */
    public $items;

    /**
     * Public variable to hold total items number.
     *
     * @var integer
     */
    public $total_items;

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
     * Return public static instance.
     *
     * @static
     * @return self
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }


    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class Name
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render()
     *
     * @return mixed render_sites()
     */
    public static function render() {
        static::render_widget_content();
    }

    /**
     * Method render_widget_content()
     *
     * Build the Connection Status Widget
     * Displays $SYNCERRORS|$UP|$ALL.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_search_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    public static function render_widget_content() { // phpcs:ignore -- NOSONAR - current complexity required to achieve desired results. Pull request solutions appreciated.

        $counts = MainWP_DB::instance()->get_sites_connections_status();

        if ( ! is_array( $counts ) ) {
            $counts = array();
        }

        $count_connected    = ! empty( $counts['connected_sites'] ) ? (int) $counts['connected_sites'] : 0;
        $count_disconnected = ! empty( $counts['disconnected_sites'] ) ? (int) $counts['disconnected_sites'] : 0;

        static::render_content( $count_connected, $count_disconnected );

        MainWP_UI::render_modal_reconnect();
    }

    /**
     * The renders the MainWP Operations page Connection Status Widget Header and Drop down Box.
     *
     * @param int $count_connected    Number of connected sites.
     * @param int $count_disconnected Number of disconnected sites.
     */
    public static function render_content( $count_connected, $count_disconnected ) {
        ?>
        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="twelve wide column">
                    <h2 class="ui header handle-drag">
                        <?php
                        /**
                         * Filter: mainwp_connection_status_widget_title
                         *
                         * Filters the Connection Status widget title text.
                         *
                         * @since 4.1
                         */
                        echo esc_html( apply_filters( 'mainwp_connection_status_widget_title', esc_html__( 'Connection Status', 'mainwp' ) ) );
                        ?>
                        <div class="sub header"><?php esc_html_e( 'Child sites connection status', 'mainwp' ); ?></div>
                    </h2>
                </div>

                <div class="four wide column right aligned">
                    <div id="widget-connect-status-dropdown-selector" class="ui dropdown top right tiny pointing not-auto-init mainwp-dropdown-tab">
                        <i class="vertical ellipsis icon"></i>
                        <div class="menu">
                            <a class="item" data-tab="all-sites" data-value="all-sites" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'View all child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></a>
                            <a class="item" data-tab="connected" data-value="connected" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'View all connected child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Connected', 'mainwp' ); ?></a>
                            <a class="item" data-tab="disconnected" data-value="disconnected" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'View all disconnected child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></a>
                            <a class="item" data-tab="no-sites" data-value="no-sites" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Hide the child sites list', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Hide Details', 'mainwp' ); ?></a>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <?php static::render_multi_status( $count_connected, $count_disconnected ); ?>
            </div>
        </div>

        <?php
        /**
         * Action: mainwp_connection_status_widget_top
         *
         * Fires at the top of the Connection Status widget.
         *
         * @since 4.1
         */
        do_action( 'mainwp_connection_status_widget_top' );
        ?>

        <div class="mainwp-scrolly-overflow" id="widget-connections-status-details">
            <?php
            foreach ( array( 'all-sites', 'connected', 'disconnected' ) as $tab ) {
                static::render_tab( $tab );
            }
            ?>
            <div class="ui tab" data-tab="no-sites"></div>
        </div>
        <div class="ui two column grid mainwp-widget-footer">
            <div class="left aligned middle aligned column">
            <?php
            /**
             * Action: mainwp_connection_status_widget_footer_left
             *
             * Fires in the left column of the Connection status widget
             *
             * @since 5.3
             */
            do_action( 'mainwp_connection_status_widget_footer_left' )
            ?>
            </div>
            <div class="right aligned middle aligned column">
            <?php
            /**
             * Action: mainwp_connection_status_widget_footer_right
             *
             * Fires in the right column of the Connection status widget
             *
             * @since 5.3
             */
            do_action( 'mainwp_connection_status_widget_footer_right' )
            ?>
            </div>
        </div>
        <script type="text/javascript">

            let mainwp_widgets_connections_status_get_table = function(tab){
                switch(tab){
                    case 'all-sites':
                        return $widget_connections_status_table_all_sites;
                    case 'connected':
                        return $widget_connections_status_table_connected;
                    case 'disconnected':
                        return $widget_connections_status_table_disconnected;
                    default:
                        return null;
                }
            };

            let mainwp_widgets_connections_status_tab_onchange = function( current_tab ){
                if( ! ['all-sites', 'connected', 'disconnected'].includes(current_tab) || jQuery('#widget-connections-status-details div[data-tab=' + current_tab +'][loaded-data="loaded"]').length > 0 ){
                    return;
                }
                let current_page = jQuery('#widget-connections-status-details div[data-tab=' + current_tab + ']').attr('current-page');
                mainwp_widgets_connections_status_get_table(current_tab).ajax.reload();
            }

            jQuery( document ).ready( function () {
                setTimeout(() => {
                    jQuery( '#widget-connect-status-dropdown-selector' ).dropdown( {
                        onChange: function( val ) {
                            if ( typeof( Storage ) !== 'undefined' ) {
                                localStorage.setItem( 'lsWidgetConnectStatusDropdownVal', val );
                                mainwp_widgets_connections_status_tab_onchange( val );
                            }
                        }
                    } );
                    jQuery( '#widget-connect-status-dropdown-selector .menu .item' ).tab();
                    if ( typeof( Storage ) !== "undefined" ) {
                        let val = localStorage.getItem( 'lsWidgetConnectStatusDropdownVal' );
                        if(!['all-sites', 'connected', 'disconnected', 'no-sites'].includes(val)) {
                            val = 'all-sites';
                        }
                        jQuery( '#widget-connect-status-dropdown-selector' ).dropdown( 'set selected',val );
                        jQuery( '#widget-connect-status-dropdown-selector' ).closest( '.mainwp-widget' ).find( 'div.ui.tab' ).removeClass( 'active');
                        jQuery( '#widget-connect-status-dropdown-selector' ).closest( '.mainwp-widget' ).find( 'div[data-tab="' + val + '"]' ).addClass( 'active' );
                        mainwp_widgets_connections_status_tab_onchange( val );
                    }
                }, 1000);
            } );
        </script>
        <?php
    }

    /**
     * The renders the connections status tab.
     *
     * @param string $tab The tab.
     */
    public static function render_tab( $tab ) {
        $sites_per_page = 25;
        $tbl_id         = 'widget-connections-status-details-table-' . $tab;

        if ( 'all-sites' === $tab ) {
            $hook_before = 'mainwp_connection_status_before_all_sites_list';
            $hook_after  = 'mainwp_connection_status_after_all_sites_list';
        } elseif ( 'connected' === $tab ) {
            $hook_before = 'mainwp_connection_status_before_connected_sites_list';
            $hook_after  = 'mainwp_connection_status_after_connected_sites_list';
        } else {
            $hook_before = 'mainwp_connection_status_before_disconnected_sites_list';
            $hook_after  = 'mainwp_connection_status_after_disconnected_sites_list';
        }

        ?>

        <div class="ui tab" data-tab="<?php echo esc_attr( $tab ); ?>" loaded-data="no" current-page="1">
            <div class="ui message widget-connections-status-message-zone" style="display:none;" ></div>

        <?php
        /**
         * Fires before the list of all sites in the connection status widgets
         *
         * @since 4.1
         */
        do_action( $hook_before );
        ?>
            <div class="widget-connections-status-details-table-list-container">
                <table id="<?php echo esc_attr( $tbl_id ); ?>" style="width:100%" class="ui single line unstackable table">
                    <thead>
                        <tr><?php static::print_column_headers(); ?></tr>
                    </thead>
                </table>
            </div>
        <?php
        /**
         * Fires after the list of all sites in the connection status widgets
         *
         * @since 4.1
         */
        do_action( $hook_after );
        ?>
        </div>
        <?php

        $table_features = array(
            'searching'     => 'false',
            'paging'        => 'true',
            'pagingType'    => 'full_numbers',
            'info'          => 'false',
            'colReorder'    => '{columns:":not(.check-column):not(:last-child)"}',
            'stateSave'     => 'false',
            'stateDuration' => '60 * 60 * 24 * 30',
            'order'         => '[]',
            'scrollX'       => 'true',
            'responsive'    => 'true',
            'fixedColumns'  => '',
            'searchDelay'   => 350,
            'numbers'       => 3,
        );

        $pages_length = array(
            25 => '25',
        );

        $pagelength_val   = implode( ',', array_keys( $pages_length ) );
        $pagelength_title = implode( ',', array_values( $pages_length ) );

        ?>
        <script type="text/javascript">
            let $widget_connections_status_table_<?php echo esc_js( str_replace( '-', '_', $tab ) ); ?> = null;
            jQuery( document ).ready( function ($) {
                let responsive = true;
                if( jQuery( window ).width() > 1140 ) {
                    responsive = false;
                }
                let manage_tbl_id = '#<?php echo esc_js( $tbl_id ); ?>';
                $widget_connections_status_table_<?php echo esc_js( str_replace( '-', '_', $tab ) ); ?> = jQuery( manage_tbl_id ).on( 'processing.dt', function ( e, settings, processing ) {
                    jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
                    if (!processing) {
                        $( manage_tbl_id + ' .ui.dropdown' ).dropdown();
                    }
                } ).DataTable( {
                    "ajax": {
                        "url": ajaxurl,
                        "type": "POST",
                        "data":  function ( d ) {
                            let data = mainwp_secure_data( {
                                action: 'mainwp_widgets_connections_status_details_display_rows',
                                current_tab: '<?php echo esc_js( $tab ); ?>',
                            } );
                            return $.extend( {}, d, data );
                        },
                        "dataSrc": function ( json ) {
                            for ( let i=0, ien=json.data.length ; i < ien ; i++ ) {
                                json.data[i].rowClass = json.rowsInfo[i].rowClass;
                                json.data[i].site_id = json.rowsInfo[i].site_id;
                            }
                            return json.data;
                        }
                    },
                    "layout": {
                        "topStart": null,
                        "bottomEnd": {
                            "paging": {
                                "numbers": <?php echo intval( $table_features['numbers'] ); ?>
                            }
                        }
                    },
                    'responsive': responsive,
                    'deferLoading': 0,
                    'searching' : <?php echo esc_js( $table_features['searching'] ); ?>,
                    "paging" : <?php echo esc_js( $table_features['paging'] ); ?>,
                    "pagingType" : "<?php echo esc_js( $table_features['pagingType'] ); ?>",
                    "info" : <?php echo esc_js( $table_features['info'] ); ?>,
                    "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                    "scrollX" : <?php echo esc_js( $table_features['scrollX'] ); ?>,
                    "stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
                    "order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
                    "fixedColumns" : <?php echo ! empty( $table_features['fixedColumns'] ) ? esc_js( $table_features['fixedColumns'] ) : '""'; ?>,
                    "lengthMenu" : [ [<?php echo esc_js( $pagelength_val ); ?>], [<?php echo esc_js( $pagelength_title ); ?>] ],
                    "serverSide": true,
                    "pageLength": <?php echo intval( $sites_per_page ); ?>,
                    "columnDefs": "[]",
                    "columns": [{"data":"status_details"}],
                    "language": {
                        "emptyTable": "<?php echo esc_html__( 'No sites found.', 'mainwp' ); ?>"
                    },
                    "drawCallback": function( settings ) {
                        setTimeout(() => {
                            jQuery( manage_tbl_id + ' .ui.dropdown').dropdown();
                            mainwp_datatable_fix_menu_overflow(manage_tbl_id, -60, -30);
                        }, 1000);
                    },
                    "initComplete": function( settings, json ) {
                    },
                    rowCallback: function (row, data) {
                        jQuery( row ).addClass(data.rowClass);
                    },
                    orderMulti: false,
                    searchDelay: <?php echo intval( $table_features['searchDelay'] ); ?>
                })
            } );
        </script>
        <?php
    }


    /**
     * Echo the column headers.
     *
     * @return void
     */
    public static function get_columns() {
        return array(
            'status_details' => esc_html__( 'Detail', 'mainwp' ),
        );
    }

    /**
     * Echo the column headers.
     *
     * @return void
     */
    public static function print_column_headers() {

        $columns = static::get_columns();

        foreach ( $columns as $column_event_key => $column_display_name ) {

            $class = array( 'manage-' . $column_event_key . '-column' );
            $attr  = '';

            $class[] = 'no-sort';

            $tag = 'th';
            $id  = "id='$column_event_key'";

            if ( ! empty( $class ) ) {
                $class = "class='" . join( ' ', $class ) . "'";
            }

            echo "<$tag $id $class $attr style=\"display:none;\">$column_display_name</$tag>"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    /**
     * The renders the connections status tab.
     *
     * @param string $current_tab The current tab.
     */
    public function prepare_items( $current_tab ) {

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! in_array( $current_tab, array( 'all-sites', 'connected', 'disconnected' ), true ) ) {
            wp_die( wp_json_encode( array( 'error' => __( 'The selected site\'s status is invalid. Please try again.', 'mainwp' ) ) ) );
        }

        $per_page = isset( $_POST['per_page'] ) ? intval( wp_unslash( $_POST['per_page'] ) ) : 25; // phpcs:ignore -- NOSONAR - ok.
        $page = isset( $_POST['page'] ) ? intval( wp_unslash( $_POST['page'] ) ) : 1; // phpcs:ignore -- NOSONAR - ok.

        $req_order = null;

        if ( isset( $_REQUEST['order'] ) ) {
            $order_values = MainWP_Utility::instance()->get_table_orders( $_REQUEST );
            $req_order    = $order_values['order'];
        }

        $perPage = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : 25;
        $start   = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;

        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $status = $current_tab;

        if ( 'all-sites' === $status ) {
            $status = 'any';
        }

        $wpsite_fields = array( 'id', 'name', 'url', 'adminname' );
        $sync_fields   = array( 'sync_errors', 'dtsSync' );

        $args = array(
            'select_wp_fields'   => $wpsite_fields,
            'select_sync_fields' => $sync_fields,
            'status'             => $status,
            'offset'             => $start,
            'rowcount'           => $perPage,
            'orderby'            => 'wp.id ' . ( 'asc' === $req_order ? 'asc' : 'desc' ),
            'view'               => 'custom_view',
            'others_fields'      => array(),
        );

        $results = MainWP_DB::instance()->query(
            MainWP_DB::instance()->get_sql_websites_for_current_user_by_params(
                apply_filters(
                    'mainwp_connection_status_widget_get_sites_query_params',
                    $args
                )
            )
        );

        $items = array();

        while ( $results && $website = MainWP_DB::fetch_object( $results ) ) {
            $items[] = $website;
        }
        MainWP_DB::free_result( $results );

        $args['count_sql'] = true;

        $total_sql = MainWP_DB::instance()->get_sql_websites_for_current_user_by_params(
            apply_filters(
                'mainwp_connection_status_widget_get_sites_query_params',
                $args
            )
        );

        $total_items = MainWP_DB::instance()->get_var_field( $total_sql );

        $this->items       = $items;
        $this->total_items = $total_items ? (int) $total_items : 0;
        unset( $items );
    }


    /**
     * Method ajax_display_rows()
     *
     * Display table rows.
     */
    public function ajax_display_rows() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_widgets_connections_status_details_display_rows' );
        $current_tab = isset( $_POST['current_tab'] ) ? sanitize_text_field( wp_unslash( $_POST['current_tab'] ) ) : ''; // phpcs:ignore -- NOSONAR - ok.
        $this->prepare_items( $current_tab );
        $output = $this->ajax_get_datatable_rows( $current_tab );
        wp_send_json( $output );
    }

    /**
     * Get table rows.
     *
     * @param string $current_tab The current tab.
     *
     * @return array Rows html.
     */
    public function ajax_get_datatable_rows( $current_tab ) {

        $all_rows  = array();
        $info_rows = array();

        $columns = static::get_columns();

        if ( $this->items ) {
            foreach ( $this->items as $site ) {
                $rw_classes = 'mainwp-connect-status-item-' . intval( $site->id );

                $info_item = array(
                    'rowClass' => esc_html( $rw_classes ),
                    'site_id'  => $site->id,
                );

                $cols_data = array();
                foreach ( $columns as $column_name => $column_display_name ) {
                    ob_start();
                    echo $this->column_default( $site, $column_name, $current_tab ); // phpcs:ignore WordPress.Security.EscapeOutput
                    $cols_data[ $column_name ] = ob_get_clean();
                }
                $all_rows[]  = $cols_data;
                $info_rows[] = $info_item;
            }
        }
        return array(
            'data'            => $all_rows,
            'recordsTotal'    => (int) $this->total_items,
            'recordsFiltered' => (int) $this->total_items,
            'rowsInfo'        => $info_rows,
        );
    }

    /**
     * Returns the column content for the provided item and column.
     *
     * @param object $website         Website data.
     * @param string $column_name  Column name.
     * @param string $current_tab The current tab.
     *
     * @return string $out Output.
     */
    public function column_default( $website, $column_name, $current_tab ) { //phpcs:ignore -- NOSONAR -complex.

        $status = $current_tab;

        if ( 'all-sites' === $current_tab ) {
            $status = 'any';
        }

        $SYNCERRORS = 'disconnected';
        $UP         = 'connected';
        $ALL        = 'any';

        $output = '';
        if ( 'status_details' === $column_name ) {
            $hasSyncErrors = ( '' !== $website->sync_errors );
            $lastSyncTime  = ! empty( $website->dtsSync ) ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website->dtsSync ) ) : '';

            ob_start();

            if ( $status === $ALL ) {
                static::render_all_item( $website, $lastSyncTime, $hasSyncErrors );
            } elseif ( $status === $UP ) {
                static::render_up_item( $website, $lastSyncTime );
            } elseif ( $status === $SYNCERRORS ) {
                static::render_down_item( $website, $lastSyncTime );
            }

            /**
             * Action: mainwp_connection_status_widget_bottom
             *
             * Fires at the bottom of the Connection Status widget.
             *
             * @since 4.1
             */
            do_action( 'mainwp_connection_status_widget_bottom' );

            $output = ob_get_clean();
        }

        if ( empty( $output ) ) {
            return '';
        }
        return $output; //phpcs:ignore -- escaped.
    }



    /**
     * Render connection status summary section.
     *
     * @param int $count_connected    Connected Count.
     * @param int $count_disconnected Disconnected Count.
     */
    public static function render_multi_status( $count_connected, $count_disconnected ) {
        ?>
        <div class="ui mainwp-cards small cards">
            <div class="ui card">
                <div class="content">
                    <div class="header">
                        <span class="ui large text"><i class="ui een check icon"></i> <?php echo esc_html( MainWP_Utility::short_number_format( $count_connected ) ); ?></span>
                    </div>
                    <div class="description"><strong><?php esc_html_e( 'Connected Sites', 'mainwp' ); ?></strong></div>
                </div>
            </div>
            <div class="ui card">
                <div class="content">
                    <div class="header">
                        <span class="ui large text"><i class="ui unlink icon"></i> <?php echo esc_html( MainWP_Utility::short_number_format( $count_disconnected ) ); ?></span>
                    </div>
                    <div class="description"><strong><?php esc_html_e( 'Disconnected Sites', 'mainwp' ); ?></strong></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render all items list.
     *
     * @param mixed $website Website Info.
     * @param mixed $lastSyncTime Last time the Child Site was synced to.
     * @param mixed $hasSyncErrors Collected errors.
     */
    public static function render_all_item( $website, $lastSyncTime, $hasSyncErrors ) {
        ?>
        <div class="item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( $website->name ) ); ?>">
            <div class="right floated">
                <div class="ui right pointing dropdown">
                    <i class="ellipsis vertical icon"></i>
                    <div class="menu">
                        <?php if ( $hasSyncErrors ) : ?>
                        <a href="javascript:void(0)" class="mainwp-updates-overview-reconnect-site item" adminuser="<?php echo esc_attr( $website->adminname ); ?>" siteid="<?php echo intval( $website->id ); ?>"><?php esc_html_e( 'Reconnect Site', 'mainwp' ); ?></a>
                        <?php else : ?>
                        <a href="javascript:void(0)" class="item" siteid="<?php echo intval( $website->id ); ?>" onClick="updatesoverview_wp_sync( '<?php echo intval( $website->id ); ?>' )"><?php esc_html_e( 'Sync Site', 'mainwp' ); ?></a>
                        <a href="<?php MainWP_Site_Open::get_open_site_admin_link( $website->id, true ); ?>" target="_blank" class="item"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
                        <?php endif; ?>
                        <a href="<?php echo esc_html( $website->url ); ?>" class="item" target="_blank"><?php esc_html_e( 'Visit Site', 'mainwp' ); ?></a>
                    </div>
                </div>
            </div>
            <?php if ( ! $hasSyncErrors ) : ?>
            <a href="<?php MainWP_Site_Open::get_open_site_admin_link( $website->id, true ); ?>" target="_blank"><i class="sign in alternate icon"></i></a>
            <?php endif; ?>
            <a href="
                <?php
                /**
                 * Filter: mainwp_connection_status_list_item_title_url
                 *
                 * Filters the Connection Status widget list item title URL.
                 *
                 * @since 4.1
                 */
                echo esc_url( apply_filters( 'mainwp_connection_status_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
                ?>
                ">
                <?php
                /**
                 * Filter: mainwp_connection_status_list_item_title
                 *
                 * Filters the Connection Status widget list item title text.
                 *
                 * @since 4.1
                 */
                echo esc_html( stripslashes( apply_filters( 'mainwp_connection_status_list_item_title', $website->name, $website ) ) );
                ?>
            </a>
            <?php if ( $hasSyncErrors ) : ?>
            <span class="ui mini red label"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></span>
            <?php endif; ?>
            <br/><span class="ui small text"><?php esc_html_e( 'Last synced: ', 'mainwp' ); ?> <?php echo esc_html( $lastSyncTime ); ?></span>
        </div>

        <?php
    }

    /**
     * Render Connected Sites List.
     *
     * @param object $website       Object containing the child site info.
     * @param string $lastSyncTime  Last time the Child Site was synced to.
     */
    public static function render_up_item( $website, $lastSyncTime ) {
        ?>
        <div class="item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( $website->name ) ); ?>">
            <div class="right floated">
                <div class="ui right pointing dropdown">
                    <i class="ellipsis vertical icon"></i>
                    <div class="menu">
                        <a href="javascript:void(0)" class="item" siteid="<?php echo intval( $website->id ); ?>" onClick="updatesoverview_wp_sync( '<?php echo intval( $website->id ); ?>' )"><?php esc_html_e( 'Sync Site', 'mainwp' ); ?></a>
                        <a href="<?php MainWP_Site_Open::get_open_site_admin_link( $website->id, true ); ?>" target="_blank" class="item"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
                        <a href="<?php echo esc_html( $website->url ); ?>" class="item" target="_blank"><?php esc_html_e( 'Visit Site', 'mainwp' ); ?></a>
                    </div>
                </div>
            </div>
            <a href="<?php MainWP_Site_Open::get_open_site_admin_link( $website->id, true ); ?>" target="_blank"><i class="sign in alternate icon"></i></a>
            <a href="
                <?php
                /**
                 * Filter: mainwp_connection_status_list_item_title_url
                 *
                 * Filters the Connection Status widget list item title URL.
                 *
                 * @since 4.1
                 */
                echo esc_url( apply_filters( 'mainwp_connection_status_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
                ?>
                ">
                <?php
                /**
                 * Filter: mainwp_connection_status_list_item_title
                 *
                 * Filters the Connection Status widget list item title text.
                 *
                 * @since 4.1
                 */
                echo esc_html( stripslashes( apply_filters( 'mainwp_connection_status_list_item_title', $website->name, $website ) ) );
                ?>
            </a>
            <br/><span class="ui small text"><?php esc_html_e( 'Last synced: ', 'mainwp' ); ?> <?php echo esc_html( $lastSyncTime ); ?></span>
        </div>

        <?php
    }

    /**
     * Render Disconected Sites List.
     *
     * @param object $website       Object containing the child site info.
     * @param string $lastSyncTime  Last time the Child Site was synced to.
     */
    public static function render_down_item( $website, $lastSyncTime ) {
        ?>
        <div class="red item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( $website->name ) ); ?>">
            <div class="right floated">
                <div class="ui right pointing dropdown">
                    <i class="ellipsis vertical icon"></i>
                    <div class="menu">
                        <a href="javascript:void(0)" class="mainwp-updates-overview-reconnect-site item" adminuser="<?php echo esc_attr( $website->adminname ); ?>" siteid="<?php echo intval( $website->id ); ?>"><?php esc_html_e( 'Reconnect Site', 'mainwp' ); ?></a>
                        <a href="<?php echo esc_html( $website->url ); ?>" class="item" target="_blank"><?php esc_html_e( 'Visit Site', 'mainwp' ); ?></a>
                    </div>
                </div>
            </div>
            <a href="
                <?php
                /**
                 * Filter: mainwp_connection_status_list_item_title_url
                 *
                 * Filters the Connection Status widget list item title URL.
                 *
                 * @since 4.1
                 */
                echo esc_url( apply_filters( 'mainwp_connection_status_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
                ?>
                ">
                <?php
                /**
                 * Filter: mainwp_connection_status_list_item_title
                 *
                 * Filters the Connection Status widget list item title text.
                 *
                 * @since 4.1
                 */
                echo esc_html( stripslashes( apply_filters( 'mainwp_connection_status_list_item_title', $website->name, $website ) ) );
                ?>
            </a>
            <span class="ui mini red label"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></span>
            <br/><span class="ui small text"><?php esc_html_e( 'Last synced: ', 'mainwp' ); ?> <?php echo esc_html( $lastSyncTime ); ?></span>
        </div>

        <?php
    }
}
