<?php
/**
 * MainWP Manage Screenshots.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Screenshots
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Screenshots { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * MainWP_Manage_Screenshots constructor.
     *
     * Run each time the class is called.
     * Add action to generate tabletop.
     */
    public function __construct() {
        add_action( 'mainwp_managesites_tabletop', array( &$this, 'generate_tabletop' ) );
    }

    /**
     * Method get_instance().
     */
    public static function get_instance() {
        return new self();
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
     * Render manage sites table top.
     */
    public function render_manage_sites_table_top() {
        $filters_row_style = 'display:none';

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $selected_status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
        $selected_group  = isset( $_REQUEST['g'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) : '';
        $selected_client = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
        $is_not          = isset( $_REQUEST['isnot'] ) && ( 'yes' === $_REQUEST['isnot'] ) ? true : false;

        $reset_filter = isset( $_REQUEST['reset'] ) && ( 'yes' === $_REQUEST['reset'] ) ? true : false;

        if ( ! isset( $_REQUEST['g'] ) && ! $reset_filter ) {
            $selected_status = get_user_option( 'mainwp_screenshots_filter_status', '' );
            $selected_group  = get_user_option( 'mainwp_screenshots_filter_group', '' );
            $selected_client = get_user_option( 'mainwp_screenshots_filter_client', '' );
            $is_not          = get_user_option( 'mainwp_screenshots_filter_is_not', '' );
        }
        // phpcs:enable

        if ( ! empty( $selected_status ) || ! empty( $selected_group ) || ! empty( $selected_client ) ) {
            $filters_row_style = 'display:flex';
        }

        ?>
        <div class="ui stackable three column grid">

            <div class="row ui mini form">
                <div class="middle aligned column">
                    <input type="text" id="mainwp-screenshots-sites-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>">
                </div>
                <div class="middle aligned column"></div>
                <div class="right aligned middle aligned column">
                    <?php MainWP_Manage_Sites_List_Table::render_page_navigation_left_items(); ?>
                </div>
            </div>

            <div class="row ui mini form manage-sites-screenshots-filter-top" id="mainwp-sites-filters-row" style="<?php echo esc_attr( $filters_row_style ); ?>">
                <div class="thirteen wide middle aligned column ui grid">
                <?php esc_html_e( 'Filter sites: ', 'mainwp' ); ?>
                    <div class="ui selection dropdown seg_is_not" id="mainwp_is_not_site">
                            <input type="hidden" value="<?php echo $is_not ? 'yes' : ''; ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'Is', 'mainwp' ); ?></div>
                            <div class="menu">
                                <div class="item" data-value=""><?php esc_html_e( 'Is', 'mainwp' ); ?></div>
                                <div class="item" data-value="yes"><?php esc_html_e( 'Is not', 'mainwp' ); ?></div>
                            </div>
                        </div>
                        <div id="mainwp-filter-sites-group" class="ui multiple selection dropdown seg_site_tags">
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
                        <div class="ui selection dropdown seg_site_status" id="mainwp-filter-sites-status">
                            <input type="hidden" value="<?php echo esc_html( $selected_status ); ?>">
                            <div class="default text"><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                            <i class="dropdown icon"></i>
                            <div class="menu">
                                <div class="item" data-value="all" ><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                                <div class="item" data-value="connected"><?php esc_html_e( 'Connected', 'mainwp' ); ?></div>
                                <div class="item" data-value="disconnected"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></div>
                                <div class="item" data-value="update"><?php esc_html_e( 'Available update', 'mainwp' ); ?></div>
                                <div class="item" data-value="sitehealthnotgood"><?php esc_html_e( 'Site Health Not Good', 'mainwp' ); ?></div>
                                <div class="item" data-value="phpver7"><?php esc_html_e( 'PHP Ver < 7.0', 'mainwp' ); ?></div>
                                <div class="item" data-value="phpver8"><?php esc_html_e( 'PHP Ver < 8.0', 'mainwp' ); ?></div>
                                <div class="item" data-value="suspended"><?php esc_html_e( 'Suspended', 'mainwp' ); ?></div>
                            </div>
                        </div>
                        <div id="mainwp-filter-clients" class="ui selection multiple dropdown seg_site_clients">
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
                        <button onclick="mainwp_screenshots_sites_filter()" class="ui tiny green button"><?php esc_html_e( 'Filter Sites', 'mainwp' ); ?></button>
                        <button onclick="mainwp_screenshots_sites_reset_filters()" class="ui tiny button"><?php esc_html_e( 'Reset Filters', 'mainwp' ); ?></button>
                </div>
                <?php
                MainWP_Manage_Sites_Filter_Segment::get_instance()->render_filters_segment();
                ?>
            </div>
        </div>
        <script type="text/javascript">
                mainwp_screenshots_sites_filter = function() {
                    let group = jQuery( "#mainwp-filter-sites-group" ).dropdown( "get value" );
                    let status = jQuery( "#mainwp-filter-sites-status" ).dropdown( "get value" );
                    let isNot = jQuery("#mainwp_is_not_site").dropdown("get value");
                    let client = jQuery("#mainwp-filter-clients").dropdown("get value");
                    let params = '';
                    params += '&g=' + group;
                    params += '&client=' + client;
                    if ( status != '' ) {
                        params += '&status=' + status;
                    }
                    if ( 'yes' == isNot ){
                        params += '&isnot=yes';
                    }
                    console.log(params);
                    window.location = 'admin.php?page=managesites' + params;
                    return false;
                };

                mainwp_screenshots_sites_reset_filters = function() {
                    window.location = 'admin.php?page=managesites&reset=yes'
                    return false;
                };

                jQuery( document ).on( 'keyup', '#mainwp-screenshots-sites-filter', function () {
                    let filter = jQuery(this).val().toLowerCase();
                    let siteItems =  jQuery('#mainwp-sites-previews').find( '.card' );
                    for ( let i = 0; i < siteItems.length; i++ ) {
                        let currentElement = jQuery( siteItems[i] );
                        let valueurl = jQuery(currentElement).attr('site-url').toLowerCase();
                        let valuename = currentElement.find('.ui.header').text().toLowerCase();
                        if ( valueurl.indexOf( filter ) > -1 || valuename.indexOf( filter ) > -1 ) {
                            currentElement.show();
                        } else {
                            currentElement.hide();
                        }
                    }
                } );

                jQuery('#mainwp-sites-previews .image img').visibility({
                    type       : 'image',
                    transition : 'fade in',
                    duration   : 1000
                });

        </script>
        <?php
        MainWP_UI::render_modal_save_segment();
    }

    /**
     * Method render_all_sites()
     *
     * Render Screenshots.
     */
    public static function render_all_sites() { // phpcs:ignore -- NOSONAR - comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        /**
         * Sites Page header
         *
         * Renders the tabs on the Sites screen.
         *
         * @since Unknown
         */
        do_action( 'mainwp_pageheader_sites', 'managesites' );

        $websites = static::prepare_items();

        MainWP_DB::data_seek( $websites, 0 );

        $userExtension = MainWP_DB_Common::instance()->get_user_extension();

        $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        $nonce = wp_create_nonce( 'viewmode' );

        ?>

        <div id="mainwp-screenshots-sites" class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-grid-view-mode-info-message' ) ) : ?>
            <div class="ui info message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-grid-view-mode-info-message"></i>
                <div><?php printf( esc_html__( 'In the Grid mode, sites options are limited in comparison to the %sTable mode%s.', 'mainwp' ), '<a href="admin.php?page=managesites&viewmode=table&modenonce=' . esc_html( $nonce ) . '">', '</a>' ); ?></div>
            </div>
            <?php endif; ?>
        <?php
        /**
         * Filter: mainwp_cards_per_row
         *
         * Filters the number of cards per row in MainWP Screenshots page.
         *
         * @since 4.1.8
         */
        $cards_per_row = apply_filters( 'mainwp_cards_per_row', 'five' );
        ?>
        <div id="mainwp-sites-previews">
            <div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
                <div class="ui <?php echo esc_attr( $cards_per_row ); ?> mainwp-cards cards" >
                    <?php
                    while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                        $hasSyncErrors = ( '' !== $website->sync_errors );
                        $suspendedSite = ( '0' !== $website->suspended );

                        $status_color   = '';
                        $status_icon    = '';
                        $status_tooltip = '';

                        if ( $hasSyncErrors ) {
                            $status_color   = 'red';
                            $status_icon    = 'unlink';
                            $status_tooltip = esc_html__( 'Disconnected', 'mainwp' );
                        } elseif ( $suspendedSite ) {
                            $status_color   = 'yellow';
                            $status_icon    = 'pause';
                            $status_tooltip = esc_html__( 'Suspended', 'mainwp' );
                        } else {
                            $status_color   = 'green';
                            $status_icon    = 'check';
                            $status_tooltip = esc_html__( 'Connected', 'mainwp' );
                        }

                        $total_wp_upgrades     = 0;
                        $total_plugin_upgrades = 0;
                        $total_theme_upgrades  = 0;

                        $wp_upgrades           = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
                        $ignored_core_upgrades = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

                        if ( $website->is_ignoreCoreUpdates || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
                            $wp_upgrades = array();
                        }

                        if ( is_array( $wp_upgrades ) && ! empty( $wp_upgrades ) ) {
                            ++$total_wp_upgrades;
                        }

                        $plugin_upgrades = json_decode( $website->plugin_upgrades, true );

                        if ( $website->is_ignorePluginUpdates ) {
                            $plugin_upgrades = array();
                        }

                        $theme_upgrades = json_decode( $website->theme_upgrades, true );

                        if ( $website->is_ignoreThemeUpdates ) {
                            $theme_upgrades = array();
                        }

                        $decodedPremiumUpgrades = ! empty( $website->premium_upgrades ) ? json_decode( $website->premium_upgrades, true ) : array();

                        if ( is_array( $decodedPremiumUpgrades ) ) {
                            foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                                $premiumUpgrade['premium'] = true;

                                if ( 'plugin' === $premiumUpgrade['type'] ) {
                                    if ( ! is_array( $plugin_upgrades ) ) {
                                        $plugin_upgrades = array();
                                    }
                                    if ( ! $website->is_ignorePluginUpdates ) {
                                        $plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
                                    }
                                } elseif ( 'theme' === $premiumUpgrade['type'] ) {
                                    if ( ! is_array( $theme_upgrades ) ) {
                                        $theme_upgrades = array();
                                    }
                                    if ( ! $website->is_ignoreThemeUpdates ) {
                                        $theme_upgrades[ $crrSlug ] = $premiumUpgrade;
                                    }
                                }
                            }
                        }

                        if ( is_array( $plugin_upgrades ) ) {

                            $ignored_plugins = json_decode( $website->ignored_plugins, true );
                            if ( is_array( $ignored_plugins ) ) {
                                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

                            }

                            $ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
                            if ( is_array( $ignored_plugins ) ) {
                                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

                            }

                            $total_plugin_upgrades += count( $plugin_upgrades );
                        }

                        if ( is_array( $theme_upgrades ) ) {

                            $ignored_themes = json_decode( $website->ignored_themes, true );
                            if ( is_array( $ignored_themes ) ) {
                                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                            }

                            $ignored_themes = json_decode( $userExtension->ignored_themes, true );
                            if ( is_array( $ignored_themes ) ) {
                                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                            }

                            $total_theme_upgrades += count( $theme_upgrades );
                        }

                        $total_updates = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

                        $client_image = '';
                        if ( $website->client_id > 0 ) {
                            $client       = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $website->client_id, true );
                            $client_image = MainWP_Client_Handler::get_client_avatar( $client );
                        } else {
                            $client_image = '<i class="user circle grey big icon"></i>';
                        }

                        $website_info = MainWP_DB::instance()->get_website_option( $website, 'site_info' );
                        $website_info = ! empty( $website_info ) ? json_decode( $website_info, true ) : array();

                        ?>

                        <div class="card" site-url="<?php echo esc_url( $website->url ); ?>">
                            <div class="image" data-tooltip="<?php echo esc_attr( $status_tooltip ); ?>" data-position="top center" data-inverted="">
                                <img alt="<?php esc_attr_e( 'Website preview', 'mainwp' ); ?>" data-src="//s0.wordpress.com/mshots/v1/<?php echo esc_html( rawurlencode( $website->url ) ); ?>?w=900">
                            </div>
                            <div class="ui <?php echo esc_attr( $status_color ); ?> corner label">
                                <i class="<?php echo esc_attr( $status_icon ); ?> icon"></i>
                            </div>
                            <div class="content">
                                <h2 class="ui small header">
                                    <a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to WP Admin', 'mainwp' ); ?>" data-position="top left" data-inverted=""><i class="sign in icon"></i></a> <a href="admin.php?page=managesites&dashboard=<?php echo intval( $website->id ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a>
                                    <br/><?php MainWP_Utility::get_site_index_option_icon( $website_info['site_public'] ); ?> <span class="ui small text"><a href="<?php echo esc_url( $website->url ); ?>" class="ui grey text" target="_blank"><?php echo esc_url( $website->url ); ?></a></span>
                                </h2>
                                <?php if ( isset( $website->wpgroups ) && '' !== $website->wpgroups ) : ?>
                                <div><?php echo MainWP_System_Utility::get_site_tags( (array) $website ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="extra content">
                                <a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" class="ui grey text">
                                    <?php echo $client_image; //phpcs:ignore -- NOSONAR - ok.?> <?php echo esc_html( $website->client_name ); ?>
                                </a>
                            </div>

                            <div class="extra content">
                                <?php if ( $hasSyncErrors ) : ?>
                                    <a class="ui mini green basic icon button mainwp_site_card_reconnect" site-id="<?php echo intval( $website->id ); ?>" href="#"><i class="sync alternate icon"></i></a>
                                <?php else : ?>
                                    <a href="javascript:void(0)" class="ui mini green icon button mainwp-sync-this-site" site-id="<?php echo intval( $website->id ); ?>"><i class="sync alternate icon"></i></a>
                                <?php endif; ?>
                                <a href="admin.php?page=managesites&id=<?php echo intval( $website->id ); ?>"class="ui mini grey icon button"><i class="cog icon"></i></a>
                                <a data-tooltip="<?php echo ! empty( $website->dtsSync ) ? esc_attr__( 'Last sync: ', 'mainwp' ) . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website->dtsSync ) ) : ''; ?> " data-position="left center" data-inverted="" class="ui mini grey button" href="admin.php?page=managesites&updateid=<?php echo intval( $website->id ); //phpcs:ignore -- NOSONAR -ok. ?>">
                                    <i class="sync alternate icon"></i> <?php echo intval( $total_updates ); ?>
                                </a>
                                <span class="right floated"><?php MainWP_Utility::get_language_code_as_flag( $website_info['site_lang'] ); ?></span>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery('#mainwp-sites-previews .image img').visibility( {
                type       : 'image',
                transition : 'fade in',
                duration   : 1000
            });

            mainwp_manage_sites_screen_options = function () {
                jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( {
                    allowMultiple: true,
                    onHide: function () {
                        //ok.
                    }
                } ).modal( 'show' );

                jQuery( '#manage-sites-screen-options-form' ).submit( function() {
                    jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( 'hide' );
                } );
                return false;
            };

        </script>
        <?php
        MainWP_DB::free_result( $websites );
        static::render_screen_options();
        /**
         * Sites Page Footer
         *
         * Renders the footer on the Sites screen.
         *
         * @since Unknown
         */
        do_action( 'mainwp_pagefooter_sites', 'managesites' );
    }

    /**
     * Method render_screen_options()
     *
     * Render Page Settings Modal.
     */
    public static function render_screen_options() {
        $siteViewMode = MainWP_Utility::get_siteview_mode();
        $is_demo      = MainWP_Demo_Handle::is_demo_mode();
        ?>
        <div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
            <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <form method="POST" action="" id="manage-sites-screen-options-form" name="manage_sites_screen_options_form">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'SreenshotsSitesScrOptions' ) ); ?>" />
                    <div class="ui grid field">
                        <label class="top aligned six wide column" tabindex="0"><?php esc_html_e( 'Sites view mode', 'mainwp' ); ?></label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Sites view mode.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                            <div class="ui info message">
                                <div><strong><?php echo esc_html__( 'Sites view mode is an experimental feature.', 'mainwp' ); ?></strong></div>
                                <div><?php echo esc_html__( 'In the Grid mode, sites options are limited in comparison to the Table mode.', 'mainwp' ); ?></div>
                                <div><?php echo esc_html__( 'Grid mode queries WordPress.com servers to capture a screenshot of your site the same way comments show you a preview of URLs.', 'mainwp' ); ?></div>
                            </div>
                            <select name="mainwp_sitesviewmode" id="mainwp_sitesviewmode" class="ui dropdown">
                                <option value="table" <?php echo 'table' === $siteViewMode ? 'selected' : ''; ?>><?php esc_html_e( 'Table', 'mainwp' ); ?></option>
                                <option value="grid" <?php echo 'grid' === $siteViewMode ? 'selected' : ''; ?>><?php esc_html_e( 'Grid', 'mainwp' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Export child sites to CSV file', 'mainwp' ); ?></label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click this button to export all connected sites to a CSV file.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><a href="admin.php?page=MainWPTools&doExportSites=yes&_wpnonce=<?php echo esc_html( wp_create_nonce( 'export_sites' ) ); ?>" class="ui button green basic"><?php esc_html_e( 'Export Child Sites', 'mainwp' ); ?></a></div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Disconnect all child sites', 'mainwp' ); ?></label>
                        <div class="ten wide column" id="mainwp-disconnect-sites-tool" data-tooltip="<?php esc_attr_e( 'This will function will break the connection and leave the MainWP Child plugin active.', 'mainwp' ); ?>" data-variation="inverted" data-position="top left">
                            <?php
                            if ( $is_demo ) {
                                    MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="#" disabled="disabled" class="ui button green basic disabled">' . esc_html__( 'Disconnect Websites.', 'mainwp' ) . '</a>' );
                            } else {
                                ?>
                                <a href="admin.php?page=MainWPTools&disconnectSites=yes&_wpnonce=<?php echo esc_html( wp_create_nonce( 'disconnect_sites' ) ); ?>" onclick="mainwp_tool_disconnect_sites(); return false;"  class="ui button green basic"><?php esc_html_e( 'Disconnect Websites.', 'mainwp' ); ?></a>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="ui hidden divider"></div>
                    <div class="ui hidden divider"></div>
                </div>
                <div class="actions">
                    <div class="ui two columns grid">
                        <div class="left aligned column">
                            <span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated columns.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-managersites-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                        </div>
                        <div class="ui right aligned column">
                    <input type="submit" class="ui green button" name="btnSubmit" id="submit-managersites-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                </div>
                    </div>
                </div>
                <input type="hidden" name="reset_managersites_columns_order" value="0">
            </form>
        </div>
        <div class="ui small modal" id="mainwp-manage-sites-site-preview-screen-options-modal">
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <span><?php esc_html_e( 'Would you like to turn on home screen previews? This function queries WordPress.com servers to capture a screenshot of your site the same way comments shows you preview of URLs.', 'mainwp' ); ?>
            </div>
            <div class="actions">
                <div class="ui ok button"><?php esc_html_e( 'Yes', 'mainwp' ); ?></div>
                <div class="ui cancel button"><?php esc_html_e( 'No', 'mainwp' ); ?></div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery('#reset-managersites-settings').on( 'click', function () {
                    mainwp_confirm(__( 'Are you sure.' ), function(){
                        jQuery('#mainwp_sitesviewmode').dropdown( 'set selected', 'grid' );
                        jQuery('#submit-managersites-settings').click();
                    }, false, false, true );
                    return false;
                });
            } );
        </script>
        <?php
    }

    /**
     * Prepare the items to be listed.
     */
    public static function prepare_items() { // phpcs:ignore -- NOSONAR - comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $orderby = 'wp.url';

        $perPage = 9999;
        $start   = 0;

        $reset_filter = isset( $_REQUEST['reset'] ) && ( 'yes' === $_REQUEST['reset'] ) ? true : false; //phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $get_saved_state = ! isset( $_REQUEST['g'] ) && ! isset( $_REQUEST['status'] ) && ! isset( $_REQUEST['client'] ) && ! $reset_filter;
        $get_all         = ( isset( $_REQUEST['status'] ) && 'all' === $_REQUEST['status'] ) && empty( $_REQUEST['g'] ) && empty( $_REQUEST['client'] ) ? true : false;
        $is_not          = ( isset( $_REQUEST['isnot'] ) && 'yes' === $_REQUEST['isnot'] ) ? true : false;

        if ( $reset_filter ) {
            $get_all = true;
        }

        $site_status = '';

        if ( ! isset( $_REQUEST['status'] ) ) {
            if ( $get_saved_state ) {
                $site_status = get_user_option( 'mainwp_screenshots_filter_status' );
            } else {
                MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_status', '' );
            }
        } else {
            MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_status', sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) );
            MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_is_not', $is_not );
            $site_status = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );
        }

        if ( $get_all ) {
            MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_group', '' );
            MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_client', '' );
        }

        $group_ids  = false;
        $client_ids = false;

        if ( ! $get_all ) {
            if ( ! isset( $_REQUEST['g'] ) ) {
                if ( $get_saved_state ) {
                    $group_ids = get_user_option( 'mainwp_screenshots_filter_group' );
                } else {
                    MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_group', '' );
                }
            } else {
                MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_group', sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) );
                $group_ids = sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ); // may be multi groups.
            }

            if ( ! isset( $_REQUEST['client'] ) ) {
                if ( $get_saved_state ) {
                    $client_ids = get_user_option( 'mainwp_screenshots_filter_client' );
                } else {
                    MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_client', '' );
                }
            } else {
                MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_client', sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) );
                $client_ids = sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ); // may be multi groups.
            }
        }

        $where = null;

        if ( '' !== $site_status && 'all' !== $site_status ) {
            if ( 'connected' === $site_status ) {
                $where = 'wp_sync.sync_errors = ""';
                if ( $is_not ) {
                    $where = 'wp_sync.sync_errors != ""';
                }
            } elseif ( 'disconnected' === $site_status ) {
                $where = 'wp_sync.sync_errors != ""';
                if ( $is_not ) {
                    $where = 'wp_sync.sync_errors = ""';
                }
            } elseif ( 'update' === $site_status ) {
                $available_update_ids = MainWP_Common_Functions::instance()->get_available_update_siteids();
                if ( empty( $available_update_ids ) ) {
                    $where = 'wp.id = -1';
                    if ( $is_not ) {
                        $where = 'wp.id != -1';
                    }
                } else {
                    $where = 'wp.id IN (' . implode( ',', $available_update_ids ) . ') ';
                    if ( $is_not ) {
                        $where = 'wp.id NOT IN (' . implode( ',', $available_update_ids ) . ') ';
                    }
                }
            } elseif ( 'sitehealthnotgood' === $site_status ) {
                $where = ' wp_sync.health_status = 1 ';
                if ( $is_not ) {
                    $where = 'wp_sync.health_status = 0';
                }
            } elseif ( 'phpver7' === $site_status ) {
                $where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) < INET_ATON("7.0.0.0") ';
                if ( $is_not ) {
                    $where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) >= INET_ATON("7.0.0.0") ';
                }
            } elseif ( 'phpver8' === $site_status ) {
                $where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) < INET_ATON("8.0.0.0") ';
                if ( $is_not ) {
                    $where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) >= INET_ATON("8.0.0.0") ';
                }
            } elseif ( 'suspended' === $site_status ) {
                $where = 'wp.suspended = 1';
                if ( $is_not ) {
                    $where = 'wp.suspended = 0';
                }
            }
        }
        // phpcs:enable

        $params = array(
            'selectgroups' => true,
            'orderby'      => $orderby,
            'offset'       => $start,
            'rowcount'     => $perPage,
        );

        $params['isnot'] = $is_not;

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
            $params['group_id'] = $qry_group_ids;
        }

        $qry_client_ids = array();
        if ( ! empty( $client_ids ) ) {
            $client_ids = explode( ',', $client_ids ); // convert to array.
            // to fix query deleted client.
            $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
            foreach ( $clients as $cl ) {
                if ( in_array( $cl->client_id, $client_ids ) ) {
                    $qry_client_ids[] = $cl->client_id;
                }
            }
            // to fix.
            if ( in_array( 'noclients', $client_ids ) ) {
                $qry_client_ids[] = 'noclients';
            }
        }

        if ( ! empty( $qry_client_ids ) ) {
            $params['client_id'] = $qry_client_ids;
        }

        if ( ! empty( $where ) ) {
            $params['extra_where'] = $where;
        }

        $params['extra_view'] = array(
            'wp_upgrades',
            'ignored_wp_upgrades',
        );

        return MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $params ) );
    }
}
