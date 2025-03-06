<?php
/**
 * MainWP Client Overview Sites Widget
 *
 * Displays the Client Info.
 *
 * @package MainWP/MainWP_Client_Overview_Sites
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Overview_Sites
 *
 * Displays the Client info.
 */
class MainWP_Client_Overview_Sites { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.


    /**
     * Public variable to hold Items information.
     *
     * @var array
     */
    public $items;

    /**
     * Public variable to hold Total Items information.
     *
     * @var array
     */
    public $total_items;

    /**
     * Protected variable to hold columns headers
     *
     * @var array
     */
    protected $column_headers;


    /**
     * The single instance of the class
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Protected variable to hold User extension.
     *
     * @var mixed Default null.
     */
    protected $userExtension = null;

    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Check if there is a session,
     * if there isn't one create it.
     *
     *  @return static::singlton Overview Page Session.
     *
     * @uses \MainWP\Dashboard\MainWP_Overview
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Method render()
     *
     * @return mixed render_site_info()
     */
    public static function render() {
        $client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( empty( $client_id ) ) {
            return;
        }
        static::instance()->render_websites( $client_id );
    }


    /**
     * Render client overview Info.
     *
     * @param object $client_id Client ID.
     */
    public function render_websites( $client_id ) {

        $client_info = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id, ARRAY_A );

        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_clients_overview_websites_widget_title
                 *
                 * Filters the Site info widget title text.
                 *
                 * @param object $client_info Object containing the child site info.
                 *
                 * @since 4.1
                 */
                echo esc_html( apply_filters( 'mainwp_clients_overview_websites_widget_title', esc_html__( 'Sites', 'mainwp' ), $client_info ) );
                ?>
                <div class="sub header"><?php echo esc_html__( 'Websites that belong to the client.', 'mainwp' ); ?></div>
            </h2>
        </div>
            <div class="mainwp-widget-client-card mainwp-scrolly-overflow">
                <?php
                /**
                 * Actoin: mainwp_clients_overview_websites_widget_top
                 *
                 * Fires at the top of the Site Info widget on the Individual site overview page.
                 *
                 * @param object $client_info Object containing the child site info.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_clients_overview_websites_widget_top', $client_info );
                ?>
                <?php
                if ( $client_info ) {
                    $this->prepare_items( $client_id );
                    ?>
                    <table id="mainwp-manage-sites-monitor-table" style="width:100%" class="ui unstackable table mainwp-with-preview-table">
                        <thead>
                            <tr><?php $this->print_column_headers( true ); ?></tr>
                            </thead>
                            <tbody id="mainwp-manage-sites-body-table">
                                <?php $this->display_rows_or_placeholder(); ?>
                            </tbody>
                        </table>
                <?php } ?>
                <?php
                /**
                 * Action: mainwp_clients_overview_websites_widget_bottom
                 *
                 * Fires at the bottom of the Site Info widget on the Individual site overview page.
                 *
                 * @param object $client_info Object containing the child site info.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_clients_overview_websites_widget_bottom', $client_info );
                ?>
            </div>
            <div class="ui grid mainwp-widget-footer">
                <div class="column">
                    <a href="admin.php?page=managesites&client=<?php echo intval( $client_id ); ?>" title="" class="ui mini basic button"><?php echo esc_html__( 'Manage Client Sites', 'mainwp' ); ?></a>
                </div>
            </div>

            <?php
            // @NO_SONAR_START@ - duplicated issue.

                $sites_per_page = get_option( 'mainwp_default_sites_per_page', 25 );

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

                $table_features = array(
                    'searching'     => 'true',
                    'paging'        => 'true',
                    'info'          => 'false',
                    'colReorder'    => '{ columns:":not(.manage-status-column):not(.manage-site_actions-column)"} ',
                    'stateSave'     => 'true',
                    'stateDuration' => '0',
                    'order'         => '[]',
                    'scrollX'       => 'true',
                    'responsive'    => 'true',
                );

                ?>

            <script type="text/javascript">
                jQuery( document ).ready( function( $ ) {
                    let responsive = true;
                    if( jQuery( window ).width() > 1140 ) {
                        responsive = false;
                    }
                    try {
                        $manage_sites_table = jQuery( '#mainwp-manage-sites-monitor-table' ).DataTable( {
                            "searching" : <?php echo esc_js( $table_features['searching'] ); ?>,
                            "responsive": responsive,
                            "paging" : <?php echo esc_js( $table_features['paging'] ); ?>,
                            "info" : <?php echo esc_js( $table_features['info'] ); ?>,
                            "layout": {
                                "bottom": 'paging',
                                "bottomStart": null,
                                "bottomEnd": null
                            },
                            "scrollX" : <?php echo esc_js( $table_features['scrollX'] ); ?>,
                            "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                            "stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
                            "stateDuration" : <?php echo esc_js( $table_features['stateDuration'] ); ?>,
                            "order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
                            "lengthMenu" : [ [<?php echo esc_js( $pagelength_val ); ?>, -1 ], [<?php echo esc_js( $pagelength_title ); ?>, "All" ] ],
                            "columnDefs": <?php echo wp_json_encode( $this->get_columns_defines() ); ?>,
                            "pageLength": <?php echo intval( $sites_per_page ); ?>,
                            "initComplete": function( settings, json ) {
                            },
                            "language": {
                                "emptyTable": "<?php esc_html_e( 'No websites found.', 'mainwp' ); ?>"
                            },
                            "drawCallback": function( settings ) {
                                if ( jQuery('#mainwp-manage-sites-body-table td.dt-empty').length > 0 && jQuery('#sites-table-count-empty').length ){
                                    jQuery('#mainwp-manage-sites-body-table td.dt-empty').html(jQuery('#sites-table-count-empty').html());
                                }
                            }
                        } ).on( 'columns-reordered', function () {
                            console.log('columns-reordered');
                            setTimeout(() => {
                                $( '#mainwp-manage-sites-monitor-table .ui.dropdown' ).dropdown();
                                $( '#mainwp-manage-sites-monitor-table .ui.checkbox' ).checkbox();
                                mainwp_datatable_fix_menu_overflow( '#mainwp-manage-sites-monitor-table' );
                            }, 1000 );
                        } );
                    } catch(err) {
                        // to fix js error.
                        console.log(err);
                    }
                    mainwp_datatable_fix_menu_overflow( '#mainwp-manage-sites-monitor-table' );
                });
            </script>
            <?php
            // @NO_SONAR_END@  .
    }


    /**
     * Prepare the items to be listed.
     *
     * @param int $client_id  client id.
     */
    public function prepare_items( $client_id ) {

        if ( null === $this->userExtension ) {
            $this->userExtension = MainWP_DB_Common::instance()->get_user_extension();
        }

        $total_params = array(
            'count_only'   => true,
            'selectgroups' => true,
            'orderby'      => 'wp.url',
            'offset'       => 0,
            'rowcount'     => 9999,
            'client_id'    => array( $client_id ),
        );

        $params = array(
            'selectgroups' => true,
            'orderby'      => 'wp.url',
            'offset'       => 0,
            'rowcount'     => 9999,
            'client_id'    => array( $client_id ),
        );

        $totalRecords = 0;
        $websites     = false;

        if ( $client_id ) {
            $total_websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $total_params ) );
            $totalRecords   = ( $total_websites ? MainWP_DB::num_rows( $total_websites ) : 0 );
            $websites       = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $params ) );
        }

        $this->items       = $websites;
        $this->total_items = $totalRecords;
    }

    /**
     * Echo the column headers.
     */
    public function print_column_headers() {

        list( $columns ) = $this->get_column_info();

        $sortable = array(
            'site'        => array( 'site', false ),
            'update'      => array( 'update', false ),
            'client_name' => array( 'client_name', false ),
        );

        $def_columns                 = $columns;
        $def_columns['site_actions'] = '';

        foreach ( $columns as $column_overview_key => $column_display_name ) {

            $class = array( 'manage-' . $column_overview_key . '-column' );
            $attr  = '';
            if ( ! isset( $def_columns[ $column_overview_key ] ) ) {
                $class[] = 'extra-column';
            }

            if ( ! isset( $sortable[ $column_overview_key ] ) ) {
                $class[] = 'no-sort';
            }

            $tag = 'th';
            $id  = "id='$column_overview_key'";

            if ( ! empty( $class ) ) {
                $class = "class='" . join( ' ', $class ) . "'";
            }

            echo "<$tag $id $class $attr>$column_display_name</$tag>"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }


    /**
     * Return empty table place holders.
     */
    public function display_rows_or_placeholder() {
        if ( $this->has_items() ) {
            $this->display_rows();
        }
    }



    /**
     * Fetch single row item.
     *
     * @return mixed Single Row Item.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::is_result()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_array()
     */
    public function display_rows() {
        if ( MainWP_DB::is_result( $this->items ) ) {
            while ( $this->items && ( $item = MainWP_DB::fetch_array( $this->items ) ) ) {
                $this->single_row( $item );
            }
        }
    }

    /**
     * Single Row.
     *
     * @param mixed $website Child Site.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize_file_name()
     */
    public function single_row( $website ) {
        $classes       = '';
        $hasSyncErrors = ( '' !== $website['sync_errors'] );
        $classes       = ' class="child-site mainwp-child-site-' . intval( $website['id'] ) . ' ' . ( $hasSyncErrors ? 'error' : '' ) . ' ' . $classes . '"';
        echo '<tr id="child-site-' . intval( $website['id'] ) . '"' . $classes . ' siteid="' . intval( $website['id'] ) . '" site-url="' . esc_url( $website['url'] ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
        $this->single_row_columns( $website );
        echo '</tr>';
    }

    /**
     * Get default columns.
     *
     * @return array Array of default column names.
     */
    public function get_default_columns() {
        return array(
            'status' => '',
            'site'   => esc_html__( 'Site', 'mainwp' ),
            'update' => esc_html__( 'Updates', 'mainwp' ),
        );
    }

    /**
     * Method get_columns()
     *
     * Combine all columns.
     *
     * @return array $columns Array of column names.
     */
    public function get_columns() {

        $columns = $this->get_default_columns();

        $columns['site_actions'] = '';

        return $columns;
    }

    /**
     * Get column info.
     */
    protected function get_column_info() {

        if ( isset( $this->column_headers ) && is_array( $this->column_headers ) ) {
            $column_headers = array( array(), array(), array() );
            foreach ( $this->column_headers as $key => $value ) {
                $column_headers[ $key ] = $value;
            }

            return $column_headers;
        }

        $columns = $this->get_columns();

        $this->column_headers = array( $columns );

        return $this->column_headers;
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
            'targets'   => array( 'manage-status-column' ),
            'className' => 'collapsing center aligned',
        );
        $defines[] = array(
            'targets'   => 'manage-site-column',
            'className' => 'column-site-bulk mainwp-site-cell',
        );
        $defines[] = array(
            'targets'   => array( 'manage-update-column' ),
            'className' => 'collapsing center aligned',
        );
        $defines[] = array(
            'targets'   => array( 'manage-site_actions-column' ),
            'className' => 'collapsing right aligned',
        );
        return $defines;
    }

    /**
     * Columns for a single row.
     *
     * @param mixed $website Child Site.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_favico_url()
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_options_array()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_site_health()
     * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_http_codes()
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    protected function single_row_columns( $website ) { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $userExtension       = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();

        // @NO_SONAR_START@ - duplicated issue.
        $total_wp_upgrades     = 0;
        $total_plugin_upgrades = 0;
        $total_theme_upgrades  = 0;

        $site_options          = MainWP_DB::instance()->get_website_options_array( $website, array( 'wp_upgrades', 'ignored_wp_upgrades', 'premium_upgrades', 'primary_lasttime_backup' ) );
        $wp_upgrades           = isset( $site_options['wp_upgrades'] ) ? json_decode( $site_options['wp_upgrades'], true ) : array();
        $ignored_core_upgrades = isset( $site_options['ignored_wp_upgrades'] ) ? json_decode( $site_options['ignored_wp_upgrades'], true ) : array();

        if ( $website['is_ignoreCoreUpdates'] || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
            $wp_upgrades = array();
        }

        if ( is_array( $wp_upgrades ) && ! empty( $wp_upgrades ) ) {
            ++$total_wp_upgrades;
        }

        $plugin_upgrades = json_decode( $website['plugin_upgrades'], true );

        if ( $website['is_ignorePluginUpdates'] ) {
            $plugin_upgrades = array();
        }

        $theme_upgrades = json_decode( $website['theme_upgrades'], true );

        if ( $website['is_ignoreThemeUpdates'] ) {
            $theme_upgrades = array();
        }

        $decodedPremiumUpgrades = isset( $site_options['premium_upgrades'] ) ? json_decode( $site_options['premium_upgrades'], true ) : array();
        if ( is_array( $decodedPremiumUpgrades ) ) {
            foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                $premiumUpgrade['premium'] = true;

                if ( 'plugin' === $premiumUpgrade['type'] ) {
                    if ( ! is_array( $plugin_upgrades ) ) {
                        $plugin_upgrades = array();
                    }
                    if ( ! $website['is_ignorePluginUpdates'] ) {
                        $plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
                    }
                } elseif ( 'theme' === $premiumUpgrade['type'] ) {
                    if ( ! is_array( $theme_upgrades ) ) {
                        $theme_upgrades = array();
                    }
                    if ( ! $website['is_ignoreThemeUpdates'] ) {
                        $theme_upgrades[ $crrSlug ] = $premiumUpgrade;
                    }
                }
            }
        }

        if ( is_array( $plugin_upgrades ) ) {

            $ignored_plugins = json_decode( $website['ignored_plugins'], true );
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

            }

            $ignored_plugins = json_decode( $this->userExtension->ignored_plugins, true );
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
            }

            $total_plugin_upgrades += count( $plugin_upgrades );
        }

        if ( is_array( $theme_upgrades ) ) {

            $ignored_themes = json_decode( $website['ignored_themes'], true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }

            $ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }

            $total_theme_upgrades += count( $theme_upgrades );
        }

        $total_updates = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

        if ( 5 < $total_updates ) {
            $a_color = 'red';
        } elseif ( 0 < $total_updates && 5 >= $total_updates ) {
            $a_color = 'yellow';
        } else {
            $a_color = 'green';
        }

        $hasSyncErrors = ( '' !== $website['sync_errors'] );

        // @NO_SONAR_END@  .

        if ( $hasSyncErrors ) {
            $a_color = '';
        }

        list( $columns ) = $this->get_column_info();

        foreach ( $columns as $column_name => $column_display_name ) {
            ?>

            <?php if ( 'status' === $column_name ) { ?>
                <td class="center aligned collapsing">
                    <?php if ( $hasSyncErrors ) : ?>
                        <a class="mainwp_site_reconnect" href="#"><i class="red large times icon"></i></a>
                    <?php else : ?>
                        <a class="managesites_syncdata" href="#">
                            <?php echo '1' === $website['suspended'] ? '<i class="yellow pause large icon"></i>' : '<i class="green large check icon"></i>'; ?>
                        </a>
                    <?php endif; ?>
                </td>
                <?php
            } elseif ( 'site' === $column_name ) {
                $cls_site = '';
                if ( ! empty( $website['sync_errors'] ) ) {
                    $cls_site = 'site-sync-error';
                }
                ?>
                <td class="column-site-bulk mainwp-site-cell all <?php echo esc_html( $cls_site ); ?>">
                    <?php if ( ! \mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
                        <i class="sign in icon"></i>
                    <?php else : ?>
                        <a href="<?php MainWP_Site_Open::get_open_site_url( $website['id'] ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>" data-position="right center" data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
                    <?php endif; ?>
                    <a href="<?php echo 'admin.php?page=managesites&dashboard=' . intval( $website['id'] ); ?>" >
                        <?php echo esc_html( stripslashes( $website['name'] ) ); ?>
                    </a>
                    <div>
                        <span class="ui small text">
                            <a href="<?php echo esc_url( $website['url'] ); ?>" class="mainwp-may-hide-referrer open_site_url ui grey text" target="_blank">
                                <?php echo esc_html( MainWP_Utility::get_nice_url( $website['url'] ) ); ?>
                            </a>
                        </span>
                    </div>
                </td>
            <?php } elseif ( 'update' === $column_name ) { ?>
            <td>
                <a class="ui mini basic grey button" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>">
                    <i class="sync alternate icon"></i> <?php echo intval( $total_updates ); ?>
                </a>
            </td>
                <?php
            } elseif ( 'site_actions' === $column_name ) {
                // @NO_SONAR_START@ - duplicated issue.
                ?>
                    <td class="collapsing not-selectable">
                        <div class="ui right pointing dropdown" style="z-index: 99;">
                            <i class="ellipsis vertical icon"></i>
                            <div class="menu" siteid="<?php echo intval( $website['id'] ); ?>">
                    <?php if ( '' !== $website['sync_errors'] ) : ?>
                            <a class="mainwp_site_reconnect item" href="#"><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
                            <?php else : ?>
                            <a class="managesites_syncdata item" href="#"><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
                            <?php endif; ?>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) : ?>
                            <a class="item" href="admin.php?page=managesites&dashboard=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
                            <?php endif; ?>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) : ?>
                            <a class="item" href="admin.php?page=managesites&id=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Settings', 'mainwp' ); ?></a>
                            <?php endif; ?>
                            </div>
                        </div>
                    </td>
                <?php
                // @NO_SONAR_END@  .
            }
        }
    }


    /**
     * Set the column names.
     *
     * @param mixed  $item MainWP Sitetable Item.
     * @param string $column_name Column name to use.
     *
     * @return string Column Name.
     */
    public function column_default( $item, $column_name ) { // phpcs:ignore -- NOSONAR - comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        switch ( $column_name ) {
            case 'status':
            case 'site':
            case 'login':
            case 'update':
            case 'site_actions':
                return '';
            default:
                return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
        }
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
     * Clear Items.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::is_result()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public function clear_items() {
        if ( MainWP_DB::is_result( $this->items ) ) {
            MainWP_DB::free_result( $this->items );
        }
    }
}
