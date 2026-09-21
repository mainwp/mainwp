<?php
/**
 * MainWP  Site Actions Widget
 *
 * Displays the Site Actions Info.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\Module\Log\Log_Manager;
use MainWP\Dashboard\Module\Log\Log_Events_List_Table;
use MainWP\Dashboard\Module\Log\Log_DB_Helper;
use MainWP\Dashboard\Module\Log\Log_Events_Filter_Segment;


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Site_Actions
 *
 * Displays the Site Actions.
 */
class MainWP_Site_Actions { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Private static variable to hold the single instance of the events table.
     *
     * @static
     *
     * @var mixed Default null
     */
    private $list_events_table = null;


    /**
     * Private static variable to hold the table type value.
     *
     * @var mixed Default null
     */
    private $table_id_prefix = 'widget-overview';

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Site_Actions
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
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render()
     *
     * @return mixed render_site_info()
     */
    public static function render() {
        $current_wpid = MainWP_System_Utility::get_current_wpid();
        $website      = null;
        $params       = array( 'context' => 'operations' );

        $params['limit'] = apply_filters( 'mainwp_widget_site_actions_limit_number', 50 );
        if ( $current_wpid ) {
            $params  = array(
                'wpid'    => $current_wpid,
                'context' => 'individual',
            );
            $website = MainWP_DB::instance()->get_website_by_id( $current_wpid );
        } elseif ( isset( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $params    = array(
                'client_id' => $client_id,
                'context'   => 'client',
            );
        }
        static::instance()->render_info( $params, $website );
    }

    /**
     * Render Sites actions Info.
     *
     * @param array  $params Events params.
     * @param object $website Sites info.
     */
    private function render_info( $params, $website ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $params ) ) {
            $params = array();
        }
        $this->load_events_list_table();
        ?>

        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="fourteen wide column">
                    <h2 class="ui header handle-drag">
                        <?php
                        /**
                         * Filter: mainwp_non_mainwp_changes_widget_title
                         *
                         * Filters the Site info widget title text.
                         *
                         * @param object $website Object containing the child site info.
                         *
                         * @since 4.1
                         */
                        echo esc_html( apply_filters( 'mainwp_non_mainwp_changes_widget_title', esc_html__( 'Network Activity', 'mainwp' ), $website ) );
                        ?>
                        <div class="sub header"><?php esc_html_e( 'The most recent changes made to your Child Sites.', 'mainwp' ); ?></div>
                    </h2>
                </div>
                <div class="two wide column right aligned">
                    <div id="widget-sites-changes-dropdown-selector" class="ui dropdown top right tiny pointing not-auto-init mainwp-dropdown-tab">
                        <i class="vertical ellipsis icon"></i>
                        <div class="menu">
                            <a href="javascript:void(0)" class="item" data-value="wp-admin"><?php esc_html_e( 'Non-MainWP Changes', 'mainwp' ); ?></a>
                            <a href="javascript:void(0)" class="item" data-value="dashboard"><?php esc_html_e( 'Dashboard Changes', 'mainwp' ); ?></a>
                            <a href="javascript:void(0)" class="item" data-value=""><?php esc_html_e( 'Show All', 'mainwp' ); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <input type="hidden" id="mainwp-widget-filter-current-site-id" value="<?php echo isset( $params['wpid'] ) ? intval( $params['wpid'] ) : 0; ?>" />
        <input type="hidden" id="mainwp-widget-filter-current-client-id" value="<?php echo isset( $params['client_id'] ) ? intval( $params['client_id'] ) : 0; ?>" />
        <input type="hidden" id="mainwp-widget-filter-events-limit" value="<?php echo isset( $params['limit'] ) ? intval( $params['limit'] ) : 50; ?>" />
        <input type="hidden" id="mainwp-widget-filter-events-context" value="<?php echo isset( $params['context'] ) ? esc_attr( $params['context'] ) : ''; ?>" />

        <div id="mainwp-widget-site-actions" class="mainwp-scrolly-overflow">
            <?php
            /**
             * Actoin: mainwp_non_mainwp_changes_widget_top
             *
             * Fires at the top of the Site Info widget on the Individual site overview page.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_non_mainwp_changes_widget_top', $website );
            ?>
            <?php
            /**
             * Action: mainwp_non_mainwp_changes_table_top
             *
             * Fires at the top of the Site Info table in Site Info widget on the Individual site overview page.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_non_mainwp_changes_table_top', $website );
            ?>
            <div id="mainwp-non-mainwp-changes-feed">
                <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
                <?php
                $context  = ! empty( $params['context'] ) ? $params['context'] : '';
                $clientid = ! empty( $params['client_id'] ) ? $params['client_id'] : '';

                $optimize_tbl     = apply_filters( 'mainwp_widget_sites_changes_optimize_view', false );
                $insights_filters = $this->get_widget_insights_filters( $context, ( $website ? $website->id : 0 ), $clientid, true );
                static::render_logs_overview_top( $insights_filters, $optimize_tbl, $website, $clientid, $context );

                MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' );
                $this->list_events_table->display();
                ?>
            </div>
            <?php
            /**
             * Action: mainwp_non_mainwp_changes_table_bottom
             *
             * Fires at the bottom of the Site Info table in Site Info widget on the Individual site overview page.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_non_mainwp_changes_table_bottom', $website );
            ?>
        </div>

        <div class="mainwp-widget-footer">
            <div class="ui two columns stackable grid">
                <div class="left aligned middle aligned column">
                </div>
                <div class="right aligned middle aligned column">
                    <a href="admin.php?page=InsightsManage" class="ui mini basic button"><?php esc_html_e( 'View All Changes', 'mainwp' ); ?></a>
                </div>
            </div>
        </div>
        <?php
        /**
         * Action: mainwp_non_mainwp_changes_widget_bottom
         *
         * Fires at the bottom of the Site Info widget on the Individual site overview page.
         *
         * @param object $website Object containing the child site info.
         *
         * @since 4.0
         */
        do_action( 'mainwp_non_mainwp_changes_widget_bottom', $website );
    }

    /**
     * Render Manage Tasks Table Top.
     *
     * @param array       $insights_filters Insights filters.
     * @param bool        $optimize Optimize table view or not.
     * @param object|null $website Website data.
     * @param int         $clientid Client id.
     * @param string      $context Widget context: 'individual', 'client', 'operations'.
     */
    public static function render_logs_overview_top( $insights_filters, $optimize = false, $website = null, $clientid = null, $context = '' ) { //phpcs:ignore -- NOSONAR - complex.
        $manager = Log_Manager::instance();

        $filter_user_ids             = '';
        $array_usersfilter_sites_ids = array();

        $filter_sites  = '';
        $filter_events = '';

        $array_sites_ids   = array();
        $array_events_list = array();

        extract( $insights_filters ); //phpcs:ignore -- ok.

        $default_filter = false;
        // extracted values.
        if ( empty( $filter_user_ids ) && empty( $array_usersfilter_sites_ids ) && empty( $array_sites_ids ) && empty( $array_events_list ) ) {
            $default_filter = true;
        }

        $client_sites_ids = array();
        if ( 'client' === $context ) {
            $client_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $clientid );
            if ( is_array( $client_sites ) && ! empty( $client_sites ) ) {
                $client_sites_ids = array_map(
                    static function ( $site ) {
                        return (int) $site->id;
                    },
                    $client_sites
                );
            }
        }

        ?>
        <div id="mainwp-module-log-overview-sub-header">
            <div class="ui stackable grid">
                <div class="eight wide middle aligned column">
                </div>
                <div class="eight wide right aligned middle aligned column">
                    <a href="#" class="ui mini basic button" id="mainwp-sites-changes-filter-toggle-button">
                        <i class="filter icon"></i> <?php esc_html_e( 'Show Filters', 'mainwp' ); ?>
                    </a>
                </div>
            </div>

        <div id="mainwp-module-log-filters-row" style="display:none">
            <div class="ui divider"></div>
                <div class="ui stackable compact grid mini form">
                    <?php
                    if ( ! $optimize ) {
                        $websites_ids = array();

                        if ( $website ) {
                            $websites_ids = array( (int) $website->id );
                        } elseif ( 'client' === $context && ! empty( $clientid ) ) {
                            $websites_ids = $client_sites_ids;
                        }

                        $users = ( 'client' === $context && empty( $websites_ids ) )
                            ? array()
                            : $manager->admin->get_all_users( $websites_ids );

                        if ( ! empty( $filter_user_ids ) ) {
                            if ( empty( $users ) ) {
                                $filter_user_ids = '';
                            } else {
                                $users_ids      = explode( ',', $filter_user_ids );
                                $valid_user_ids = array_map(
                                    static function ( $user ) {
                                        if ( ! empty( $user['is_dashboard_user'] ) ) {
                                            return (int) $user['id'] . '-xxx-1';
                                        }
                                        return (int) $user['id'] . '-' . (int) $user['site_id'] . '-0';
                                    },
                                    $users
                                );

                                $filter_user_ids = array_filter(
                                    $users_ids,
                                    function ( $id ) use ( $valid_user_ids ) {
                                        return in_array( $id, $valid_user_ids, true );
                                    }
                                );

                                if ( ! empty( $filter_user_ids ) ) {
                                    $filter_user_ids = implode( ',', $filter_user_ids );
                                } else {
                                    $filter_user_ids = '';
                                }
                            }
                        }

                        ?>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-users" class="ui selection multiple fluid dropdown seg_users">
                            <input type="hidden" value="<?php echo esc_attr( $filter_user_ids ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All users', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php

                                foreach ( $users as $item ) {
                                    $filter_users = intval( $item['id'] );
                                    if ( ! empty( $item['is_dashboard_user'] ) ) {
                                        $filter_users .= '-xxx-1'; // Dashboard users are independent of sites.
                                    } else {
                                        $filter_users .= '-' . (int) $item['site_id'] . '-0';
                                    }
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $filter_users ); ?>"><?php echo ( ! empty( $item['login'] ) ? esc_html( $item['login'] ) : esc_html( $item['nicename'] ) ) . ' (' . esc_html( $item['source'] ) . ')'; ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="allusers"><?php esc_html_e( 'All users', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                    <?php
                    // add filters: filter_events, filter_sites.
                    ?>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-events" class="ui selection multiple fluid dropdown seg_events">
                                <input type="hidden" value="<?php echo esc_attr( $filter_events ); ?>">
                                <i class="dropdown icon"></i>
                                <div class="default text"><?php esc_html_e( 'All Events', 'mainwp' ); ?></div>
                                <div class="menu">
                                    <?php
                                    $manager = Log_Manager::instance();
                                    $events  = $manager->connectors->term_labels['logs_action'];
                                    if ( ! is_array( $events ) ) {
                                        $events = array();
                                    }

                                    $seen_event_labels = array();
                                    foreach ( $events as $eve_name => $eve_title ) {
                                        $clean_title = stripslashes( $eve_title );
                                        if ( isset( $seen_event_labels[ $clean_title ] ) ) {
                                            continue;
                                        }
                                        $seen_event_labels[ $clean_title ] = true;
                                        ?>
                                        <div class="item" data-value="<?php echo esc_attr( $eve_name ); ?>"><?php echo esc_html( $clean_title ); ?></div>
                                        <?php
                                    }
                                    ?>
                                    <div class="item" data-value="allevents"><?php esc_html_e( 'All Events', 'mainwp' ); ?></div>
                                </div>
                        </div>
                    </div>
                    <?php
                    if ( empty( $website ) ) {
                        $client_sites_empty = false;
                        $wpsite_fields      = array( 'id', 'name' );
                        $params             = array( 'select_wp_fields' => $wpsite_fields );
                        if ( 'client' === $context ) {
                            if ( empty( $client_sites_ids ) ) {
                                $client_sites_empty = true;
                            } else {
                                $params['include'] = $client_sites_ids;
                            }
                        }

                        $sites_list = array();
                        if ( $client_sites_empty ) {
                            $filter_sites = '';
                        } else {
                            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params ) );
                            if ( $websites ) {
                                while ( $website = MainWP_DB::fetch_object( $websites ) ) {
                                    $sites_list[ $website->id ] = $website->name;
                                }
                                MainWP_DB::free_result( $websites );
                            }

                            if ( ! empty( $filter_sites ) ) {
                                $site_ids       = array_map( 'intval', explode( ',', $filter_sites ) );
                                $filtered_sites = array_filter(
                                    $site_ids,
                                    function ( $id ) use ( $sites_list ) {
                                        return isset( $sites_list[ $id ] );
                                    }
                                );
                                if ( ! empty( $filtered_sites ) ) {
                                    $filter_sites = implode( ',', $filtered_sites );
                                } else {
                                    $filter_sites = '';
                                }
                            }
                        }
                        ?>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-sites" class="ui selection multiple fluid dropdown seg_sites">
                            <input type="hidden" value="<?php echo esc_attr( $filter_sites ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All Websites', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                if ( $sites_list ) {
                                    foreach ( $sites_list as $siteid => $name ) {
                                        ?>
                                        <div class="item" data-value="<?php echo esc_attr( $siteid ); ?>"><?php echo esc_html( MainWP_Utility::get_nice_url( stripslashes( $name ) ) ); ?></div>
                                        <?php
                                    }
                                }
                                ?>
                                <div class="item" data-value="allsites"><?php esc_html_e( 'All Websites', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                    <div class="three wide middle aligned right aligned column">
                        <a onclick="mainwp_module_log_manage_events_filter()" href="#" class="ui mini green basic button"><i class="filter icon"></i> <?php esc_html_e( 'Filter', 'mainwp' ); ?></a>
                        <a onclick="mainwp_module_log_manage_events_reset_filters(this)" href="#" class="ui mini basic button" <?php echo $default_filter ? 'disabled="disabled"' : ''; ?>><i class="times icon"></i> <?php esc_html_e( 'Reset', 'mainwp' ); ?></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        MainWP_UI::render_modal_save_segment( 'manage-events' );

        ?>
        <script type="text/javascript">
            jQuery( document ).ready( function( $ ) {
                mainwp_module_log_manage_events_filter = function() {
                    let current_site_id = $( '#mainwp-widget-filter-current-site-id').length ? $( '#mainwp-widget-filter-current-site-id').val() : 0;
                    let current_client_id = $( '#mainwp-widget-filter-current-client-id').length ? $( '#mainwp-widget-filter-current-client-id').val() : 0;

                    if(current_site_id === '' || current_site_id === '0'){
                        current_site_id = 0;
                    }

                    if(current_client_id === '' || current_client_id === '0'){
                        current_client_id = 0;
                    }

                    let user = jQuery( '#mainwp-module-log-filter-users').dropdown('get value');
                    let sites = jQuery( '#mainwp-module-log-filter-sites').length ? jQuery( '#mainwp-module-log-filter-sites').dropdown('get value') : '';
                    let events = jQuery( '#mainwp-module-log-filter-events').dropdown('get value');

                    let params = '';
                    params += '&user=' + encodeURIComponent( user );
                    params += '&sites=' + encodeURIComponent( sites );
                    params += '&events=' + encodeURIComponent( events );

                    params += '&_widget_actions_opennonce=' + mainwpParams._wpnonce;


                    if( current_site_id > 0){
                        window.location = 'admin.php?page=managesites&dashboard=' + current_site_id + params;
                    } else {
                        if(current_client_id > 0){
                            window.location = 'admin.php?page=ManageClients&client_id=' + current_client_id + params;
                        } else {
                            window.location = 'admin.php?page=mainwp_tab' + params;
                        }
                    }

                    return false;
                };

                mainwp_module_log_manage_events_reset_filters = function(resetObj) {
                    try {
                        jQuery( '#mainwp-module-log-filter-users').dropdown('clear');
                        jQuery( '#mainwp-module-log-filter-sites').dropdown('clear');
                        jQuery( '#mainwp-module-log-filter-events').dropdown('clear');

                        jQuery(resetObj).attr('disabled', 'disabled');
                        mainwp_module_log_manage_events_filter();
                    } catch(err) {
                        // to fix js error.
                        console.log(err);
                    }
                };

            });
        </script>

        <?php
    }

    /**
     * Method get_widget_insights_filters()
     *
     * Get insights filters.
     *
     * @param string $context Context filter.
     * @param int    $site_id Site id.
     * @param int    $client_id Client id.
     * @param bool   $save_filter To save filter.
     * @param bool   $force_request_filter Whether to force filters from the request.
     */
    public function get_widget_insights_filters( $context, $site_id = 0, $client_id = 0, $save_filter = false, $force_request_filter = false ) { //phpcs:ignore -- NOSONAR - complex method.

        $filters = array( 'user', 'sites', 'events' );

        $get_saved = true;

        if ( isset( $_GET['_widget_actions_opennonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_widget_actions_opennonce'] ), 'mainwp-admin-nonce' ) ) { //phpcs:ignore -- NOSONAR -ok.
            foreach ( $filters as $filter ) {
                if ( isset( $_REQUEST[ $filter ] ) ) { //phpcs:ignore -- safe.
                    $get_saved = false;
                    break;
                }
            }
        }

        $filter_user_ids = '';

        $filter_sites  = '';
        $filter_events = '';

        $array_usersfilter_sites_ids = array();
        $array_sites_ids             = array();

        $array_events_list = array();

        $update_filter = false;

        if ( $get_saved && ! $force_request_filter ) {
            $filters_saved = $this->get_or_set_widget_saved_filters( $context, null, $site_id, $client_id );
            if ( ! is_array( $filters_saved ) ) {
                $filters_saved = array();
            }
            $filter_user_ids = isset( $filters_saved['user_ids'] ) && ! empty( $filters_saved['user_ids'] ) ? $filters_saved['user_ids'] : '';

            $filter_sites  = isset( $filters_saved['sites'] ) && ! empty( $filters_saved['sites'] ) ? $filters_saved['sites'] : '';
            $filter_events = isset( $filters_saved['events'] ) && ! empty( $filters_saved['events'] ) ? $filters_saved['events'] : '';

        } else {
            // phpcs:disable WordPress.Security.NonceVerification
            $filter_user_ids = isset( $_REQUEST['user'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['user'] ) ) : '';
            $filter_sites    = isset( $_REQUEST['sites'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sites'] ) ) : '';
            $filter_events   = isset( $_REQUEST['events'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['events'] ) ) : '';

            // phpcs:enable WordPress.Security.NonceVerification
            $update_filter = true;
        }

        if ( ! empty( $filter_user_ids ) ) {
            $array_usersfilter_sites_ids = explode( ',', $filter_user_ids ); // convert to array.
            if ( in_array( 'allusers', $array_usersfilter_sites_ids, true ) ) {
                $array_usersfilter_sites_ids = false;
                $filter_user_ids             = '';
            } else {
                // to valid and fix filters.
                $users_filters = array();
                foreach ( $array_usersfilter_sites_ids as $users_filter ) {
                    if ( 2 === substr_count( $users_filter, '-' ) ) {
                        $users_filters[] = $users_filter;
                    }
                }
                $array_usersfilter_sites_ids = $users_filters;
                $filter_user_ids             = implode( ',', $users_filters );
            }
        }

        if ( ! empty( $filter_sites ) ) {
            $array_sites_ids = explode( ',', $filter_sites ); // convert to array.
            if ( in_array( 'allsites', $array_sites_ids, true ) ) {
                $array_sites_ids = false;
                $filter_sites    = '';
            } else {
                $array_sites_ids = MainWP_Utility::array_numeric_filter( $array_sites_ids );
                $filter_sites    = implode( ',', $array_sites_ids );
            }
        }

        if ( ! empty( $filter_events ) ) {
            $array_events_list = explode( ',', $filter_events ); // convert to array.
            if ( in_array( 'allevents', $array_events_list, true ) ) {
                $filter_events     = '';
                $array_events_list = false;
            } else {
                if ( in_array( 'updated', $array_events_list, true ) && ! in_array( 'update', $array_events_list, true ) ) {
                    $array_events_list[] = 'update';
                } elseif ( in_array( 'update', $array_events_list, true ) && ! in_array( 'updated', $array_events_list, true ) ) {
                    $array_events_list[] = 'updated';
                }
                if ( in_array( 'deleted', $array_events_list, true ) && ! in_array( 'delete', $array_events_list, true ) ) {
                    $array_events_list[] = 'delete';
                } elseif ( in_array( 'delete', $array_events_list, true ) && ! in_array( 'deleted', $array_events_list, true ) ) {
                    $array_events_list[] = 'deleted';
                }
            }
        }

        if ( $save_filter && $update_filter ) {
            $this->get_or_set_widget_saved_filters(
                $context,
                array(
                    'user_ids' => $filter_user_ids,
                    'sites'    => $filter_sites,
                    'events'   => $filter_events,
                ),
                $site_id,
                $client_id
            );
        }

        $filters_params = compact(
            'filter_user_ids',
            'filter_sites',
            'filter_events',
        );

        return array_merge(
            $filters_params,
            compact(
                'array_usersfilter_sites_ids',
                'array_sites_ids',
                'array_events_list',
            )
        );
    }

    /**
     * Get or save the user's saved widget action log filters.
     *
     * @param string     $context  The widget context. Accepts 'individual', 'client', or 'operations'.
     * @param array|null $filters  Filters to save. Pass null to retrieve the saved filters.
     * @param int        $site_id  The site ID used for the 'individual' context.
     * @param int        $client_id The client ID used for the 'client' context.
     *
     * @return mixed|false The saved filters when retrieving or saving, or false when the context is invalid.
     */
    public function get_or_set_widget_saved_filters( $context, $filters = null, $site_id = 0, $client_id = 0 ) {
        if ( ! in_array( $context, array( 'individual', 'client', 'operations' ), true ) ) {
            return false;
        }

        $option = 'mainwp_widget_actions_logs_' . $context . '_filters_saved';

        if ( 'individual' === $context ) {
            $option .= '_' . intval( $site_id );
        } elseif ( 'client' === $context ) {
            $option .= '_' . intval( $client_id );
        }

        if ( null === $filters ) {
            return get_user_option( $option );
        } else {
            return MainWP_Utility::update_user_option( $option, $filters );
        }
    }


    /**
     * Method load_sites_table()
     *
     * Load sites table.
     */
    public function load_events_list_table() {
        $manager                 = Log_Manager::instance();
        $this->list_events_table = new Log_Events_List_Table( $manager, $this->table_id_prefix );
    }
}
