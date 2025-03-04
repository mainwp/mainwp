<?php
/**
 * MainWP Connection Status
 *
 * Build the MainWP Overview page Connection Status Widget.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Connection_Status
 *
 * Build the Connection Status Widget.
 */
class MainWP_Connection_Status { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
        static::render_sites();
    }

    /**
     * Method render_sites()
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
    public static function render_sites() { // phpcs:ignore -- NOSONAR - current complexity required to achieve desired results. Pull request solutions appreciated.

        $sql      = MainWP_DB::instance()->get_sql_websites_for_current_user();
        $websites = MainWP_DB::instance()->query( $sql );

        $count_connected    = 0;
        $count_disconnected = 0;

        // Loop 3 times.
        $SYNCERRORS = 0;
        $UP         = 1;
        $ALL        = 2;

        $html_online_sites = '';
        $html_other_sites  = '';
        $html_all_sites    = '';

        $disconnect_site_ids = array();

        for ( $j = 0; $j < 3; $j++ ) {
            MainWP_DB::data_seek( $websites, 0 );
            while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                if ( empty( $website ) ) {
                    continue;
                }

                $hasSyncErrors = ( '' !== $website->sync_errors );
                $isUp          = ! $hasSyncErrors;

                if ( $j !== $ALL ) {
                    if ( $j === $SYNCERRORS && ! $hasSyncErrors ) {
                        continue;
                    }
                    if ( $j === $UP && ! $isUp ) {
                        continue;
                    }
                }

                $lastSyncTime = ! empty( $website->dtsSync ) ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website->dtsSync ) ) : '';

                ob_start();

                if ( $j === $ALL ) {
                    static::render_all_item( $website, $lastSyncTime, $hasSyncErrors );
                } elseif ( $j === $UP ) {
                    static::render_up_item( $website, $lastSyncTime );
                } else {
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

                if ( $j === $ALL ) {
                    $html_all_sites .= $output;
                } elseif ( $j === $UP ) {
                    $html_online_sites .= $output;
                    ++$count_connected;
                } elseif ( ! in_array( $website->id, $disconnect_site_ids ) ) {
                    $disconnect_site_ids[] = $website->id;
                    $html_other_sites     .= $output;
                    ++$count_disconnected;
                }
            }
        }

        MainWP_DB::free_result( $websites );

        static::render_status( $count_connected, $count_disconnected );

        static::render_details( $html_all_sites, $html_online_sites, $html_other_sites );

        MainWP_UI::render_modal_reconnect();
    }

    /**
     * The renders the MainWP Overview page Connection Status Widget Header and Drop down Box.
     *
     * @param int $count_connected    Number of connected sites.
     * @param int $count_disconnected Number of disconnected sites.
     */
    public static function render_status( $count_connected, $count_disconnected ) {
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
                            <a class="item" data-tab="all-sites" data-value="all-sites" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></a>
                            <a class="item" data-tab="connected" data-value="connected" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all connected child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Connected', 'mainwp' ); ?></a>
                            <a class="item" data-tab="disconnected" data-value="disconnected" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all disconnected child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></a>
                            <a class="item" data-tab="no-sites" data-value="no-sites" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Hide the child sites list', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Hide Details', 'mainwp' ); ?></a>
                        </div>
                    </div>
                </div>


                <script type="text/javascript">
                    jQuery( document ).ready( function () {
                        jQuery( '#widget-connect-status-dropdown-selector' ).dropdown( {
                            onChange: function( val ) {
                                if ( typeof( Storage ) !== 'undefined' ) {
                                    localStorage.setItem( 'lsWidgetConnectStatusDropdownVal', val );
                                }
                            }
                        } );
                        if ( typeof( Storage ) !== "undefined" ) {
                            if ( val = localStorage.getItem( 'lsWidgetConnectStatusDropdownVal' ) ) {
                                jQuery( '#widget-connect-status-dropdown-selector' ).dropdown( 'set selected',val );
                                jQuery( '#widget-connect-status-dropdown-selector' ).closest( '.mainwp-widget' ).find( 'div[data-tab="' + val + '"]' ).addClass( 'active' );
                            }
                        }
                    } );
                </script>

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
    }

    /**
     * Render connection status summary section.
     *
     * @param int $count_connected    Connected Count.
     * @param int $count_disconnected Disconnected Count.
     */
    public static function render_multi_status( $count_connected, $count_disconnected ) {
        $count_total = $count_connected + $count_disconnected;
        ?>
        <div class="ui mainwp-cards small cards">
            <div class="ui card">
                <div class="content">
                    <div class="header">
                        <span class="ui large text"><i class="ui een check icon"></i> <?php echo esc_html( MainWP_Utility::short_number_format( $count_connected ) ); ?></span>
                    </div>
                    <div class="meta">
                        <div class="ui tiny progress mainwp-site-status-progress" id="" data-total="<?php echo esc_attr( $count_total ); ?>" data-value="<?php echo esc_attr( $count_connected ); ?>">
                            <div class="green bar"></div>
                        </div>
                    </div>
                    <div class="description"><strong><?php esc_html_e( 'Connected Sites', 'mainwp' ); ?></strong></div>
                </div>
            </div>
            <div class="ui card">
                <div class="content">
                    <div class="header">
                        <span class="ui large text"><i class="ui unlink icon"></i> <?php echo esc_html( MainWP_Utility::short_number_format( $count_disconnected ) ); ?></span>
                    </div>
                    <div class="meta">
                        <div class="ui tiny progress mainwp-site-status-progress" id="" data-total="<?php echo esc_attr( $count_total ); ?>" data-value="<?php echo esc_attr( $count_disconnected ); ?>">
                            <div class="red bar"></div>
                        </div>
                    </div>
                    <div class="description"><strong><?php esc_html_e( 'Disconnected Sites', 'mainwp' ); ?></strong></div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery('.mainwp-site-status-progress').progress();
        </script>
        <?php
    }

    /**
     * Render See All Sites List.
     *
     * @param mixed $html_all_sites All sites html.
     * @param mixed $html_online_sites Online sites html.
     * @param mixed $html_other_sites Other sites html.
     */
    public static function render_details( $html_all_sites, $html_online_sites, $html_other_sites ) {
        ?>
        <div class="mainwp-scrolly-overflow">
            <div class="ui tab" data-tab="all-sites">
                <?php
                /**
                 * Action: mainwp_connection_status_before_all_sites_list
                 *
                 * Fires before the list of all sites in the connection status widgets
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_connection_status_before_all_sites_list' )
                ?>
                <div class="ui middle aligned divided list">
                    <?php echo $html_all_sites; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <?php
                /**
                 * Action: mainwp_connection_status_after_all_sites_list
                 *
                 * Fires after the list of all sites in the connection status widgets
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_connection_status_after_all_sites_list' )
                ?>
            </div>

            <div class="ui tab" data-tab="connected">
                <?php
                /**
                 * Action: mainwp_connection_status_before_connected_sites_list
                 *
                 * Fires before the list of connected sites in the connection status widgets
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_connection_status_before_connected_sites_list' )
                ?>
                <div class="ui middle aligned divided list">
                    <?php echo $html_online_sites; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <?php
                /**
                 * Action: mainwp_connection_status_after_connected_sites_list
                 *
                 * Fires after the list of connected sites in the connection status widgets
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_connection_status_after_connected_sites_list' )
                ?>
            </div>

            <div class="ui tab" data-tab="disconnected">
                <?php
                /**
                 * Action: mainwp_connection_status_before_disconnected_sites_list
                 *
                 * Fires before the list of disconnected sites in the connection status widgets
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_connection_status_before_disconnected_sites_list' )
                ?>
                <div class="ui middle aligned divided list">
                    <?php echo $html_other_sites; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <?php
                /**
                 * Action: mainwp_connection_status_after_disconnected_sites_list
                 *
                 * Fires after the list of disconnected sites in the connection status widgets
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_connection_status_after_disconnected_sites_list' )
                ?>
            </div>
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
                        <a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank" class="item"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
                        <?php endif; ?>
                        <a href="<?php echo esc_html( $website->url ); ?>" class="item" target="_blank"><?php esc_html_e( 'Visit Site', 'mainwp' ); ?></a>
                    </div>
                </div>
            </div>
            <?php if ( ! $hasSyncErrors ) : ?>
            <a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank"><i class="sign in alternate icon"></i></a>
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
                        <a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank" class="item"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
                        <a href="<?php echo esc_html( $website->url ); ?>" class="item" target="_blank"><?php esc_html_e( 'Visit Site', 'mainwp' ); ?></a>
                    </div>
                </div>
            </div>
            <a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank"><i class="sign in alternate icon"></i></a>
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
