<?php
/**
 * MainWP UI.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_UI
 *
 * @package MainWP\Dashboard
 */
class MainWP_UI { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method select_sites_box()
     *
     * Select sites box.
     *
     * @deprecated 4.3 Use MainWP_UI_Select_Sites::select_sites_box().
     *
     * @param string  $type Input type, radio.
     * @param bool    $show_group Whether or not to show group, Default: true.
     * @param bool    $show_select_all Whether to show select all.
     * @param string  $class_style Default = ''.
     * @param string  $style Default = ''.
     * @param array   $selected_websites Selected Child Sites.
     * @param array   $selected_groups Selected Groups.
     * @param bool    $enableOfflineSites (bool) True, if offline sites is enabled. False if not.
     * @param integer $postId Post Meta ID.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::maybe_unserialyze()
     */
    public static function select_sites_box( $type = 'checkbox', $show_group = true, $show_select_all = true, $class_style = '', $style = '', &$selected_websites = array(), &$selected_groups = array(), $enableOfflineSites = false, $postId = 0 ) { // phpcs:ignore -- NOSONAR - compatible.

        if ( $postId ) {

            $sites_val         = get_post_meta( $postId, '_selected_sites', true );
            $selected_websites = MainWP_System_Utility::maybe_unserialyze( $sites_val );

            if ( empty( $selected_websites ) ) {
                $selected_websites = array();
            }

            $groups_val      = get_post_meta( $postId, '_selected_groups', true );
            $selected_groups = MainWP_System_Utility::maybe_unserialyze( $groups_val );

            if ( empty( $selected_groups ) ) {
                $selected_groups = array();
            }
        }

        if ( empty( $selected_websites ) && isset( $_GET['selected_sites'] ) && ! empty( $_GET['selected_sites'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            $selected_sites    = explode( '-', sanitize_text_field( wp_unslash( $_GET['selected_sites'] ) ) );// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            $selected_sites    = array_map( 'intval', $selected_sites );
            $selected_websites = array_filter( $selected_sites );
        }

        /**
         * Action: mainwp_before_seclect_sites
         *
         * Fires before the Select Sites box.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_seclect_sites' );
        ?>
        <div id="mainwp-select-sites" class="mainwp_select_sites_wrapper">
        <?php static::select_sites_box_body( $selected_websites, $selected_groups, $type, $show_group, $show_select_all, false, $enableOfflineSites, $postId ); ?>
    </div>
        <?php
        /**
         * Action: mainwp_after_seclect_sites
         *
         * Fires after the Select Sites box.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_seclect_sites' );
    }

    /**
     * Method select_sites_box_body()
     *
     * Select sites box Body.
     *
     * @deprecated 4.3 Use MainWP_UI_Select_Sites::select_sites_box_body().
     *
     * @param array  $selected_websites Child Site that are selected.
     * @param array  $selected_groups Group that are selected.
     * @param string $type Selector type.
     * @param bool   $show_group         Whether or not to show group, Default: true.
     * @param bool   $show_select_all    Whether or not to show select all, Default: true.
     * @param bool   $updateQty          Whether or not to update quantity, Default = false.
     * @param bool   $enableOfflineSites Whether or not to enable offline sites, Default: true.
     * @param int    $postId             Post ID.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     */
    public static function select_sites_box_body( &$selected_websites = array(), &$selected_groups = array(), $type = 'checkbox', $show_group = true, $show_select_all = true, $updateQty = false, $enableOfflineSites = false, $postId = 0 ) { // phpcs:ignore -- NOSONAR - compatible.
        unset( $updateQty );
        if ( 'all' !== $selected_websites && ! is_array( $selected_websites ) ) {
            $selected_websites = array();
        }

        if ( ! is_array( $selected_groups ) ) {
            $selected_groups = array();
        }

        $selectedby = 'site';
        if ( ! empty( $selected_groups ) ) {
            $selectedby = 'group';
        }

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.name' ) );
        $groups   = MainWP_DB_Common::instance()->get_not_empty_groups( null, $enableOfflineSites );

        // support staging extension.
        $staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php' );

        $edit_site_id = false;
        if ( $postId ) {
            $edit_site_id = get_post_meta( $postId, '_mainwp_edit_post_site_id', true );
            $edit_site_id = intval( $edit_site_id );
        }

        if ( $edit_site_id ) {
            $show_group = false;
        }
        // to fix layout with multi sites selector.
        $tab_id = wp_rand();

        static::render_select_sites_header( $tab_id, $staging_enabled, $selectedby, $show_group );
        ?>
        <div class="ui tab <?php echo 'site' === $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-sites-<?php echo esc_attr( $tab_id ); ?>" id="mainwp-select-sites">
        <?php
        static::render_select_sites( $websites, $type, $selected_websites, $enableOfflineSites, $edit_site_id, $show_select_all );
        ?>
        </div>
        <?php if ( $staging_enabled ) { ?>
        <div class="ui tab <?php echo 'staging' === $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-staging-sites-<?php echo esc_attr( $tab_id ); ?>">
            <?php
            static::render_select_sites_staging( $selected_websites, $edit_site_id, $type );
            ?>
        </div>
            <?php
        }
        if ( $show_group ) {
            ?>
            <div class="ui tab <?php echo 'group' === $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-groups-<?php echo esc_attr( $tab_id ); ?>" id="mainwp-select-groups">
            <?php
            static::render_select_sites_group( $groups, $selected_groups, $type );
            ?>
            </div>
            <?php
        }
        ?>
        <?php
    }

    /**
     * Method render_select_sites_header()
     *
     * Render selected sites header.
     *
     * @param int   $tab_id          Datatab ID.
     * @param bool  $staging_enabled True, if in the active plugins list. False, not in the list.
     * @param array $selectedby Selected by.
     * @param bool  $show_group         Whether or not to show group, Default: true.
     * @param bool  $show_client         Whether or not to show client, Default: true.
     *
     * @devtodo Move to view folder.
     */
    public static function render_select_sites_header( $tab_id, $staging_enabled, $selectedby, $show_group = true, $show_client = false ) {

        /**
         * Action: mainwp_before_select_sites_filters
         *
         * Fires before the Select Sites box filters.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_select_sites_filters' );
        ?>
        <div id="mainwp-select-sites-filters">
            <div class="ui mini fluid icon input">
                <input type="text" id="mainwp-select-sites-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>" <?php echo 'site' === $selectedby ? '' : 'style="display: none;"'; ?> />
                <i class="filter icon"></i>
            </div>
        </div>
        <?php
        /**
         * Action: mainwp_after_select_sites_filters
         *
         * Fires after the Select Sites box filters.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_select_sites_filters' );
        ?>
        <input type="hidden" name="select_by" id="select_by" value="<?php echo esc_attr( $selectedby ); ?>"/>
        <input type="hidden" id="select_sites_tab" value="<?php echo esc_attr( $selectedby ); ?>"/>
        <div id="mainwp-select-sites-header">
            <div class="ui secondary pointing centered fluid menu">
                <a class="item ui text tab <?php echo ( 'site' === $selectedby ) ? 'active' : ''; ?>" data-tab="mainwp-select-sites-<?php echo esc_attr( $tab_id ); ?>" select-by="site"><?php esc_html_e( 'Sites', 'mainwp' ); ?></a>
                <?php if ( $show_group ) : ?>
                <a class="item ui text tab <?php echo ( 'group' === $selectedby ) ? 'active' : ''; ?>" data-tab="mainwp-select-groups-<?php echo esc_attr( $tab_id ); ?>" select-by="group"><?php esc_html_e( 'Tags', 'mainwp' ); ?></a>
                <?php endif; ?>
                <?php if ( $show_client ) : ?>
                <a class="item ui text tab <?php echo ( 'client' === $selectedby ) ? 'active' : ''; ?>" data-tab="mainwp-select-clients-<?php echo esc_attr( $tab_id ); ?>" select-by="client"><?php esc_html_e( 'Clients', 'mainwp' ); ?></a>
                <?php endif; ?>
                <?php if ( $staging_enabled ) : ?>
                    <a class="item ui text tab" data-tab="mainwp-select-staging-sites-<?php echo esc_attr( $tab_id ); ?>" select-by="staging"><?php esc_html_e( 'Staging', 'mainwp' ); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_select_sites()
     *
     * @param object $websites Object containing child sites info.
     * @param string $type Selector type.
     * @param mixed  $selected_websites Selected Child Sites.
     * @param bool   $enableOfflineSites (bool) True, if offline sites is enabled. False if not.
     * @param mixed  $edit_site_id Child Site ID to edit.
     * @param bool   $show_select_all    Whether or not to show select all, Default: true.
     * @param mixed  $add_edit_client_id Show enable sites for client. Default: false, 0: add new client, 0 <: edit client.
     * @param bool   $show_select_all_disc    Whether or not to show select all disconnect sites, Default: false.
     *
     * @return void Render Select Sites html.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public static function render_select_sites( $websites, $type, $selected_websites, $enableOfflineSites, $edit_site_id, $show_select_all, $add_edit_client_id = false, $show_select_all_disc = false ) { // phpcs:ignore -- NOSONAR - complex.
        /**
         * Action: mainwp_before_select_sites_list
         *
         * Fires before the Select Sites list.
         *
         * @param object $websites Object containing child sites info.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_select_sites_list', $websites );
        $count_disc = 0;
        ?>
            <div id="mainwp-select-sites-body">
                <div class="ui relaxed selection list" id="mainwp-select-sites-list">
                    <?php if ( ! $websites ) : ?>
                        <div id="mainwp-select-sites-placeholder" class="ui segment">
                            <?php static::render_empty_element_placeholder( __( 'No sites connected.', 'mainwp' ) ); ?>
                        </div>
                        <?php
                        else :
                            while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                                $enable_site = true;
                                if ( false === $add_edit_client_id ) {
                                    $enable_site = true;
                                } elseif ( 0 === intval( $add_edit_client_id ) && false !== $add_edit_client_id ) {
                                    if ( 0 < intval( $website->client_id ) ) {
                                        $enable_site = false;
                                    }
                                } elseif ( is_numeric( $add_edit_client_id ) && intval( $add_edit_client_id ) > 0 ) {
                                    if ( 0 < intval( $website->client_id ) && intval( $website->client_id ) !== intval( $add_edit_client_id ) ) {
                                        $enable_site = false;
                                    }
                                }

                                $site_client_editing = ( $add_edit_client_id && $website->client_id && $add_edit_client_id === $website->client_id ) ? true : false;

                                $selected     = false;
                                $disconnected = false;
                                if ( ( empty( $website->sync_errors ) || $enableOfflineSites ) && ( ! MainWP_System_Utility::is_suspended_site( $website ) || $site_client_editing ) && $enable_site ) {
                                    $selected = ( 'all' === $selected_websites || in_array( $website->id, $selected_websites ) );
                                    $disabled = '';
                                    if ( $edit_site_id ) {
                                        if ( (int) $website->id === $edit_site_id ) {
                                            $selected = true;
                                        } else {
                                            $disabled = 'disabled="disabled"';
                                        }
                                    }

                                    if ( '' !== $website->sync_errors ) {
                                        $disconnected = true;
                                        ++$count_disc;
                                    }
                                    ?>
                                    <div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item ui <?php echo esc_html( $type ); ?> item <?php echo $selected ? 'selected_sites_item_checked' : ''; ?> <?php echo esc_html( $disconnected ? 'warning' : '' ); ?>">
                                        <input <?php echo esc_html( $disabled ); ?> type="<?php echo esc_html( $type ); ?>" name="<?php echo 'radio' === $type ? 'selected_sites' : 'selected_sites[]'; ?>" siteid="<?php echo intval( $website->id ); ?>" value="<?php echo intval( $website->id ); ?>" id="selected_sites_<?php echo intval( $website->id ); ?>" <?php echo $selected ? 'checked="true"' : ''; ?> />
                                        <label for="selected_sites_<?php echo intval( $website->id ); ?>">
                                            <?php echo esc_html( stripslashes( $website->name ) ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
                                        </label>
                                    </div>
                                    <?php
                                } else {
                                    ?>
                                <div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item item ui <?php echo esc_html( $type ); ?> <?php echo $selected ? 'selected_sites_item_checked' : ''; ?>">
                                    <input type="<?php echo esc_html( $type ); ?>" disabled="disabled" id="selected_sites_<?php echo intval( $website->id ); ?>"/>
                                    <label for="selected_sites_<?php echo intval( $website->id ); ?>">
                                        <?php echo esc_html( stripslashes( $website->name ) ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
                                    </label>
                                </div>
                                    <?php
                                }
                            }
                            MainWP_DB::free_result( $websites );
                    endif;
                        ?>
                </div>
            </div>
            <?php
            /**
             * Action: mainwp_after_select_sites_list
             *
             * Fires after the Select Sites list.
             *
             * @param object $websites Object containing child sites info.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_select_sites_list', $websites );
            ?>
        <?php
    }

    /**
     * Method render_select_sites_staging()
     *
     * Render selected staging sites.
     *
     * @param mixed  $selected_websites Selected Child Sites.
     * @param mixed  $edit_site_id Child Site ID to edit.
     * @param string $type Selector type.
     *
     * @return void Render selected staging sites html.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public static function render_select_sites_staging( $selected_websites, $edit_site_id, $type = 'checkbox' ) { //phpcs:ignore -- NOSONAR - complex.
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'favi_icon' ), 'yes' ) );
        ?>
        <div id="mainwp-select-sites-body">
            <div class="ui relaxed selection list" id="mainwp-select-staging-sites-list">
            <?php if ( ! $websites ) : ?>
                    <h2 class="ui icon header">
                        <i class="folder open outline icon"></i>
                        <div class="content"><?php esc_html_e( 'No staging websites have been found!', 'mainwp' ); ?></div>
                    </h2>
                    <?php
                    else :
                        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                            $selected = false;
                            if ( empty( $website->sync_errors ) && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
                                $selected = ( 'all' === $selected_websites || in_array( $website->id, $selected_websites ) );
                                $disabled = '';
                                if ( $edit_site_id && $website->id !== $edit_site_id ) {
                                    $disabled = 'disabled="disabled"';
                                }
                                ?>
                                <div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item ui <?php echo esc_html( $type ); ?> item <?php echo $selected ? 'selected_sites_item_checked' : ''; ?>">
                                    <input <?php echo esc_html( $disabled ); ?> type="<?php echo esc_html( $type ); ?>" name="<?php echo 'radio' === $type ? 'selected_sites' : 'selected_sites[]'; ?>" siteid="<?php echo intval( $website->id ); ?>" value="<?php echo intval( $website->id ); ?>" id="selected_sites_<?php echo intval( $website->id ); ?>" <?php echo $selected ? 'checked="true"' : ''; ?> />
                                    <label for="selected_sites_<?php echo intval( $website->id ); ?>">
                                        <?php echo esc_html( stripslashes( $website->name ) ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
                                    </label>
                                </div>
                                <?php
                            } else {
                                ?>
                                <div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item item ui <?php echo esc_html( $type ); ?> <?php echo $selected ? 'selected_sites_item_checked' : ''; ?>">
                                    <input type="<?php echo esc_html( $type ); ?>" disabled="disabled"/>
                                    <label for="selected_sites_<?php echo intval( $website->id ); ?>">
                                        <?php echo esc_html( stripslashes( $website->name ) ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
                                    </label>
                                </div>
                                <?php
                            }
                        }
                        MainWP_DB::free_result( $websites );
                    endif;
                    ?>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_select_sites_group()
     *
     * Render selected sites group.
     *
     * @param array  $groups Array of groups.
     * @param mixed  $selected_groups Selected groups.
     * @param string $type Selector type.
     *
     * @return void Render selected sites group html.
     */
    public static function render_select_sites_group( $groups, $selected_groups, $type = 'checkbox' ) {
        /**
         * Action: mainwp_before_select_groups_list
         *
         * Fires before the Select Groups list.
         *
         * @param object $groups Object containing groups info.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_select_groups_list', $groups );
        ?>
        <div id="mainwp-select-sites-body">
            <div class="ui relaxed selection list" id="mainwp-select-groups-list">
                <?php
                if ( empty( $groups ) ) {
                    ?>
                    <h2 class="ui icon header">
                        <i class="folder open outline icon"></i>
                        <div class="content"><?php esc_html_e( 'No Tags created!', 'mainwp' ); ?></div>
                        <div class="ui divider hidden"></div>
                        <a href="admin.php?page=ManageGroups" class="ui green button basic"><?php esc_html_e( 'Create Tags', 'mainwp' ); ?></a>
                    </h2>
                    <?php
                }
                foreach ( $groups as $group ) {
                    $selected = in_array( $group->id, $selected_groups );
                    ?>
                    <div class="mainwp_selected_groups_item ui item <?php echo esc_html( $type ); ?> <?php echo $selected ? 'selected_groups_item_checked' : ''; ?>">
                        <input type="<?php echo esc_html( $type ); ?>" name="<?php echo 'radio' === $type ? 'selected_groups' : 'selected_groups[]'; ?>" value="<?php echo esc_attr( $group->id ); ?>" id="selected_groups_<?php echo esc_attr( $group->id ); ?>" <?php echo $selected ? 'checked="true"' : ''; ?> />
                        <label for="selected_groups_<?php echo esc_attr( $group->id ); ?>">
                            <?php echo esc_html( stripslashes( $group->name ) ); ?>
                        </label>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
        /**
         * Action: mainwp_after_select_groups_list
         *
         * Fires after the Select Groups list.
         *
         * @param object $groups Object containing groups info.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_select_groups_list', $groups );
    }

    /**
     * Method render_top_header()
     *
     * Render top header.
     *
     * @param array $params Page parameters.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_Menu::render_left_menu()
     */
    public static function render_top_header( $params = array() ) { // phpcs:ignore -- NOSONAR - complex.

        $before_title = isset( $params['before_title'] ) ? $params['before_title'] . ' ' : '';
        $title        = isset( $params['title'] ) ? $params['title'] : '';

        $which = isset( $params['which'] ) ? $params['which'] : '';

        /**
         * Filter: mainwp_header_title
         *
         * Filter the MainWP page title in the header element.
         *
         * @since 4.0
         */
        $title = apply_filters( 'mainwp_header_title', $title );

        $show_menu = true;

        if ( isset( $params['show_menu'] ) ) {
            $show_menu = $params['show_menu'];
        }

        $title = $before_title . $title;

        /**
         * Filter: mainwp_header_left
         *
         * Filter the MainWP header element left side content.
         *
         * @since 4.0
         */
        $left = apply_filters( 'mainwp_header_left', $title, $params );

        $right = static::render_header_actions();

        /**
         * Filter: mainwp_header_right
         *
         * Filter the MainWP header element right side content.
         *
         * @since 4.0
         */
        $right = apply_filters( 'mainwp_header_right', $right );

        $sidebarPosition = get_user_option( 'mainwp_sidebarPosition' );
        if ( false === $sidebarPosition ) {
            $sidebarPosition = 1;
        }

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.name' ) );

        $count_sites = MainWP_DB::instance()->get_websites_count();

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( empty( $count_sites ) && ! isset( $_GET['do'] ) ) {
            static::render_modal_no_sites_note();
        }

        $siteViewMode = MainWP_Utility::get_siteview_mode();

        $page = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : '';

        $tour_id = '';
        if ( 'mainwp_tab' === $page ) {
            $tour_id = '13112';
        } elseif ( 'managesites' === $page ) {
            if ( isset( $_GET['do'] ) && 'new' === $_GET['do'] ) {
                $tour_id = '13210';
            } elseif ( isset( $_GET['do'] ) && 'bulknew' === $_GET['do'] ) {
                $tour_id = '60206';
            } elseif ( ! isset( $_GET['dashboard'] ) && ! isset( $_GET['id'] ) && ! isset( $_GET['updateid'] ) && ! isset( $_GET['emailsettingsid'] ) && ! isset( $_GET['scanid'] ) ) {
                if ( 'grid' === $siteViewMode ) {
                    $tour_id = '27217';
                } else {
                    $tour_id = '29331';
                }
            }
        } elseif ( 'MonitoringSites' === $page ) {
            $tour_id = '29003';
        } elseif ( 'ManageClients' === $page ) {
            if ( isset( $_GET['client_id'] ) ) {
                $tour_id = '28258';
            } else {
                $tour_id = '28240';
            }
        } elseif ( 'ClientAddNew' === $page ) {
            if ( isset( $_GET['client_id'] ) ) {
                $tour_id = '28962';
            } else {
                $tour_id = '28256';
            }
        } elseif ( 'ClientAddField' === $page ) {
            $tour_id = '28257';
        } elseif ( 'PluginsManage' === $page ) {
            $tour_id = '28510';
        } elseif ( 'ManageGroups' === $page ) {
            $tour_id = '27275';
        } elseif ( 'UpdatesManage' === $page ) {
            $tab = isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '';
            if ( 'plugins-updates' === $tab ) {
                $tour_id = '28259';
            } elseif ( 'themes-updates' === $tab ) {
                $tour_id = '28447';
            } elseif ( 'wordpress-updates' === $tab ) {
                $tour_id = '29005';
            } elseif ( 'translations-updates' === $tab ) {
                $tour_id = '29007';
            } elseif ( 'abandoned-plugins' === $tab ) {
                $tour_id = '29008';
            } elseif ( 'abandoned-themes' === $tab ) {
                $tour_id = '29009';
            } elseif ( 'plugin-db-updates' === $tab ) {
                $tour_id = '33161';
            } else {
                $tour_id = '28259';
            }
        } elseif ( 'PluginsInstall' === $page ) {
            $tour_id = '29011';
        } elseif ( 'PluginsAutoUpdate' === $page ) {
            $tour_id = '29015';
        } elseif ( 'PluginsIgnore' === $page ) {
            $tour_id = '29018';
        } elseif ( 'PluginsIgnoredAbandoned' === $page ) {
            $tour_id = '29329';
        } elseif ( 'ThemesManage' === $page ) {
            $tour_id = '28511';
        } elseif ( 'ThemesInstall' === $page ) {
            $tour_id = '29010';
        } elseif ( 'ThemesAutoUpdate' === $page ) {
            $tour_id = '29016';
        } elseif ( 'ThemesIgnore' === $page ) {
            $tour_id = '29019';
        } elseif ( 'ThemesIgnoredAbandoned' === $page ) {
            $tour_id = '29330';
        } elseif ( 'UserBulkManage' === $page ) {
            $tour_id = '28574';
        } elseif ( 'UserBulkAdd' === $page ) {
            $tour_id = '28575';
        } elseif ( 'BulkImportUsers' === $page ) {
            $tour_id = '28736';
        } elseif ( 'UpdateAdminPasswords' === $page ) {
            $tour_id = '28737';
        } elseif ( 'PostBulkManage' === $page ) {
            $tour_id = '28796';
        } elseif ( 'PostBulkAdd' === $page ) {
            $tour_id = '28799';
        } elseif ( 'PageBulkManage' === $page ) {
            $tour_id = '29045';
        } elseif ( 'PageBulkAdd' === $page ) {
            $tour_id = '29048';
        } elseif ( 'Extensions' === $page ) {
            $tour_id = '28800';
        } elseif ( 'Settings' === $page ) {
            $tour_id = '28883';
        } elseif ( 'SettingsAdvanced' === $page ) {
            $tour_id = '28886';
        } elseif ( 'SettingsEmail' === $page ) {
            $tour_id = '29054';
        } elseif ( 'MainWPTools' === $page ) {
            $tour_id = '29272';
        } elseif ( 'RESTAPI' === $page ) {
            $tour_id = '29273';
        } elseif ( 'ServerInformation' === $page ) {
            $tour_id = '28873';
        } elseif ( 'ServerInformationCron' === $page ) {
            $tour_id = '28874';
        } elseif ( 'ErrorLog' === $page ) {
            $tour_id = '28876';
        } elseif ( 'ActionLogs' === $page ) {
            $tour_id = '28877';
        } elseif ( 'Extensions-Mainwp-Jetpack-Protect-Extension' === $page ) {
            $tour_id = '31700';
        } elseif ( 'Extensions-Mainwp-Jetpack-Scan-Extension' === $page ) {
            $tour_id = '31694';
        } elseif ( 'Extensions-Termageddon-For-Mainwp' === $page ) {
            $tour_id = '32104';
        } elseif ( 'Extensions-Advanced-Uptime-Monitor-Extension' === $page ) {
            $tour_id = '32149';
        } elseif ( 'Extensions-Mainwp-Custom-Dashboard-Extension' === $page ) {
            $tour_id = '32150';
        } elseif ( 'Extensions-Mainwp-Updraftplus-Extension' === $page ) {
            $tour_id = '32151';
        } elseif ( 'Extensions-Mainwp-Sucuri-Extension' === $page ) {
            $tour_id = '32152';
        } elseif ( 'Extensions-Mainwp-Clean-And-Lock-Extension' === $page ) {
            $tour_id = '32153';
        } elseif ( 'Extensions-Mainwp-Woocommerce-Shortcuts-Extension' === $page ) {
            $tour_id = '32851';
        } elseif ( 'Extensions-Mainwp-Buddy-Extension' === $page ) {
            $tour_id = '33064';
        } elseif ( 'Extensions-Mainwp-Backwpup-Extension' === $page ) {
            $tour_id = '32923';
        } elseif ( 'Extensions-Mainwp-Ssl-Monitor-Extension' === $page ) {
            $tour_id = '33164';
        } elseif ( 'Extensions-Mainwp-Cache-Control-Extension' === $page ) {
            $tour_id = '33167';
        } elseif ( 'Extensions-Mainwp-Maintenance-Extension' === $page ) {
            $tour_id = '33301';
        } elseif ( 'Extensions-Mainwp-Domain-Monitor-Extension' === $page ) {
            $tour_id = '66031';
        } elseif ( 'Extensions-Mainwp-Favorites-Extension' === $page ) {
            $tour_id = '66035';
        } elseif ( 'Extensions-Mainwp-Regression-Testing-Extension' === $page ) {
            $tour_id = '66037';
        }
        // phpcs:enable
        ?>
        <div class="ui segment right sites sidebar" style="padding:0px" id="mainwp-sites-menu-sidebar">
            <div class="ui segment" style="margin-bottom:0px">
                <div class="ui header"><?php esc_html_e( 'Quick Site Shortcuts', 'mainwp' ); ?></div>
            </div>
            <div class="ui fitted divider"></div>
            <div class="ui segment" style="margin-bottom:0px">
                <div class="ui mini fluid icon input" <?php echo $websites ? '' : 'style="display: none;"'; ?> >
                    <input type="text" id="mainwp-sites-menu-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>" />
                    <i class="filter icon"></i>
                </div>
            </div>
            <div class="ui fluid vertical accordion menu" id="mainwp-sites-sidebar-menu" style="margin-top:0px;border-radius:0px;box-shadow:none;">
                <?php while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) { ?>
                    <div class="item mainwp-site-menu-item">
                        <div class="title">
                            <i class="dropdown icon"></i>
                            <label><?php echo esc_html( $website->name ); ?></label>
                        </div>
                        <div class="content">
                            <div class="ui hiden divider"></div>
                            <div class="ui fluid relaxed list">
                                <div class="item" >
                                    <i class="grid layout icon"></i>
                                    <a href="<?php echo 'admin.php?page=managesites&dashboard=' . intval( $website->id ); ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
                                </div>
                                <div class="item" >
                                    <i class="redo icon"></i>
                                    <a href="<?php echo 'admin.php?page=managesites&updateid=' . intval( $website->id ); ?>"><?php esc_html_e( 'Updates', 'mainwp' ); ?></a>
                                </div>
                                <div class="item">
                                    <i class="edit icon"></i>
                                    <a href="<?php echo 'admin.php?page=managesites&id=' . intval( $website->id ); ?>"><?php esc_html_e( 'Settings', 'mainwp' ); ?></a>
                                </div>
                                <div class="item">
                                    <i class="sync alt icon"></i>
                                    <a href="#" siteid="<?php echo intval( $website->id ); ?>" onClick="updatesoverview_wp_sync( '<?php echo intval( $website->id ); ?>' )"><?php esc_html_e( 'Sync Site', 'mainwp' ); ?></a>
                                </div>
                                <div class="item">
                                    <i class="shield icon"></i>
                                    <a href="<?php echo 'admin.php?page=managesites&scanid=' . intval( $website->id ); ?>"><?php esc_html_e( 'Site Hardening', 'mainwp' ); ?></a>
                                </div>
                                <div class="item">
                                    <i class="sign in icon"></i>
                                    <a target="_blank" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_attr( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
                                </div>
                                <div class="item">
                                    <i class="globe icon"></i>
                                    <a target="_blank" href="<?php echo esc_url( $website->url ); ?>"><?php esc_html_e( 'Visit Site', 'mainwp' ); ?></a>
                                </div>
                                <?php
                                /**
                                 * Action: mainwp_quick_sites_shortcut
                                 *
                                 * Adds a new shortcut item in the Quick Sites Shortcuts sidebar menu.
                                 *
                                 * @param array $website Array containing the child site data.
                                 *
                                 * Suggested HTML markup:
                                 *
                                 * <a class="item" href="your custom URL">
                                 *   <i class="your custom icon"></i>
                                 *   Your custom label  text
                                 * </a>
                                 *
                                 * @since 4.1
                                 */
                                do_action( 'mainwp_quick_sites_shortcut', $website );
                                ?>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
        <div class="ui segment right wide help sidebar" id="mainwp-documentation-sidebar">
            <div class="ui header"><?php esc_html_e( 'MainWP Guided Tours', 'mainwp' ); ?> <span class="ui blue mini label"><?php esc_html_e( 'BETA', 'mainwp' ); ?></span></div>
            <div class="ui hidden divider"></div>
            <div class="ui info message" style="display:block!important;">
                <?php printf( esc_html__( 'This feature is implemented using Javascript provided by Usetiful and is subject to the %1$sUsetiful Privacy Policy%2$s.', 'mainwp' ), '<a href="https://www.usetiful.com/privacy-policy" target="_blank">', '</a>' ); ?>
            </div>
            <div class="ui hidden divider"></div>
            <?php if ( 1 === (int) get_option( 'mainwp_enable_guided_tours', 0 ) ) : ?>
            <p><?php esc_html_e( 'MainWP guided tours are designed to provide information about all essential features on each MainWP Dashboard page.', 'mainwp' ); ?></p>
            <p><?php esc_html_e( 'Click the Start Page Tour button to start the guided tour for the current page.', 'mainwp' ); ?></p>
            <div class="ui hidden divider"></div>
            <button id="mainwp-start-page-tour-button" class="ui big green fluid basic button" tour-id="<?php echo esc_attr( $tour_id ); ?>"><?php esc_html_e( 'Start Page Tour', 'mainwp' ); ?></button>
                <?php if ( 'mainwp_tab' === $page ) : ?>
                <div class="ui hidden divider"></div>
                <button id="mainwp-interface-tour-button" class="ui big green fluid basic button"><?php esc_html_e( 'MainWP Interface Basics Tour', 'mainwp' ); ?></button>
            <?php endif; ?>
            <?php endif; ?>
            <div class="ui header"><?php esc_html_e( 'MainWP Knowledge Base', 'mainwp' ); ?></div>
            <div class="ui hidden divider"></div>
            <?php
            /**
             * Action: mainwp_help_sidebar_content
             *
             * Fires Help sidebar content
             *
             * @since 4.0
             */
            do_action( 'mainwp_help_sidebar_content' );
            ?>
            <div class="ui hidden divider"></div>
            <a href="https://mainwp.com/kb/" class="ui big green fluid button"><?php esc_html_e( 'Help Documentation', 'mainwp' ); // NOSONAR - noopener - open safe. ?></a>
            <div class="ui hidden divider"></div>
            <div id="mainwp-sticky-help-button" class="" style="position: absolute; bottom: 1em; left: 1em; right: 1em;">
                <a href="https://community.mainwp.com/" target="_blank" class="ui fluid button"><?php esc_html_e( 'Still Need Help?', 'mainwp' ); ?></a>
            </div>
        </div>
        <?php
        /**
         * Action: mainwp_before_mainwp_content_wrap
         *
         * Fires before the #mainwp-content-wrap element.
         *
         * @param array $websites Array containing the child site data.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_mainwp_content_wrap', $websites );
        global $wp_version;
        $fix_menu_overflow = 1;
        if ( version_compare( $wp_version, '5.5.3', '>' ) ) {
            $fix_menu_overflow = 2;
        }

        if ( 'page_clients_overview' !== $which ) {
            ?>

        <div class="ui modal" id="mainwp-overview-screen-options-modal">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="content ui form">
                <?php
                /**
                 * Action: mainwp_overview_screen_options_top
                 *
                 * Fires at the top of the Sceen Options modal on the Overview page.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_overview_screen_options_top' );
                ?>
                <form method="POST" action="" name="mainwp_overview_screen_options_form" id="mainwp-overview-screen-options-form">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_html( wp_create_nonce( 'MainWPScrOptions' ) ); ?>" />
                    <?php static::render_screen_options( false ); ?>
                    <?php
                    /**
                     * Action: mainwp_overview_screen_options_bottom
                     *
                     * Fires at the bottom of the Sceen Options modal on the Overview page.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_overview_screen_options_bottom' );
                    ?>
            </div>
            <div class="actions">
                <div class="ui two columns grid">
                    <div class="left aligned column">
                        <span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated widgets.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><input type="button" class="ui button" name="reset" id="reset-overview-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                    </div>
                    <div class="ui right aligned column">
                        <input type="submit" class="ui green button" id="submit-overview-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                    </div>
                </div>
            </div>

            <input type="hidden" name="reset_overview_settings" value="" />
            </form>
        </div>
        <?php } ?>
        <?php
        $wrap_class = isset( $params['wrap_class'] ) ? $params['wrap_class'] : '';
        ?>
        <?php
            /**
             * Action: mainwp_before_header
             *
             * Fires before the MainWP header element.
             *
             * @param array $websites Array containing the child site data.
             *
             * @since 4.0
             */
            do_action( 'mainwp_before_header', $websites );
        ?>
            <div id="mainwp-top-header">
                <div class="ui grid">
                    <div class="center aligned middle aligned column" style="width:72px!important;padding:0!important;">
                        <a href="
                            <?php
                            /**
                             * Filter: mainwp_menu_logo_href
                             *
                             * Filters the Logo link.
                             *
                             * @since 4.1.4
                             */
                            echo esc_url( apply_filters( 'mainwp_menu_logo_href', admin_url( 'admin.php?page=mainwp_tab' ) ) );
                            ?>
                            ">
                            <img src="
                            <?php
                            /**
                             * Filter: mainwp_menu_logo_src
                             *
                             * Filters the Logo src attribute.
                             *
                             * @since 4.1
                             */
                            echo esc_url( apply_filters( 'mainwp_menu_logo_src', MAINWP_PLUGIN_URL . 'assets/images/mainwp-icon.svg' ) );
                            ?>
                            " alt="
                            <?php
                            /**
                             * Filter: mainwp_menu_logo_alt
                             *
                             * Filters the Logo alt attribute.
                             *
                             * @since 4.1
                             */
                            echo esc_html( apply_filters( 'mainwp_menu_logo_alt', 'MainWP' ) );
                            ?>
                        " id="mainwp-navigation-icon" />
                        </a>
                    </div>
                    <div class="left aligned middle aligned column" style="width:calc( 50% - 72px )!important">
                        <h1 class="mainwp-page-title ui small header"><?php echo $left; // phpcs:ignore WordPress.Security.EscapeOutput ?></h1>
                    </div>
                    <div class="right aligned middle aligned column" style="width:50%!important">
                        <?php echo $right; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </div>
                </div>
            </div>
            <?php
            if ( $show_menu ) {
                MainWP_Menu::render_left_menu();
                MainWP_Menu::render_mobile_menu();
            }
            ?>
        <div class="mainwp-content-wrap <?php echo esc_attr( $wrap_class ); ?> <?php echo empty( $sidebarPosition ) ? 'mainwp-sidebar-left' : ''; ?>" menu-overflow="<?php echo intval( $fix_menu_overflow ); ?>">
            <?php if ( MainWP_Demo_Handle::is_demo_mode() ) : ?>
                <div class="ui segment" style="background-color:#1c1d1b!important;margin-bottom:0px;">
                    <div class="ui inverted accordion" id="mainwp_demo_mode_accordion" style="background-color:#1c1d1b;">
                        <div class="title">
                            <i class="dropdown icon"></i>
                            <?php if ( ! MainWP_Demo_Handle::is_instawp_site() ) { ?>
                            <strong style="color:#fff;font-size:16px;"><?php esc_html_e( 'You are in Demo Mode. Click here for more info or to disable it', 'mainwp' ); ?></strong>
                            <?php } else { ?>
                            <strong style="color:#fff;font-size:16px;"><?php esc_html_e( 'You are in Demo Mode. Click here for more info', 'mainwp' ); ?></strong>
                            <?php } ?>
                        </div>
                        <div class="content">
                        <br/>
                        <div class="ui stackable grid">
                            <div class="eleven wide middle aligned column">
                                <?php if ( ! MainWP_Demo_Handle::is_instawp_site() ) { ?>
                                <p style="color:#fff;font-size:16px;"><?php esc_html_e( 'Once you are ready to get started with MainWP, click the Disable Demo Mode & Remove Demo Content button to remove the demo content and start adding your own. ', 'mainwp' ); ?></p>
                                <?php } ?>
                                <p style="color:#fff;font-size:16px;"><strong><?php esc_html_e( 'The demo content serves as placeholder data to give you a feel for the MainWP Dashboard. Please note that because no real websites are connected in this demo, some functionality will be restricted. Features that require a connection to actual websites will be disabled for the duration of the demo.', 'mainwp' ); ?></strong></p>
                            </div>
                            <div class="five wide middle aligned column">
                                <?php if ( ! MainWP_Demo_Handle::is_instawp_site() ) { ?>
                                <div data-tooltip="<?php esc_attr_e( 'Delete the Demo content from your MainWP Dashboard and disable the Demo mode.', 'mainwp' ); ?>" data-inverted="" data-position="left center"><button class="ui big fluid button mainwp-remove-demo-data-button"><?php esc_html_e( 'Disable Demo Mode & Remove Demo Content', 'mainwp' ); ?></button></div>
                                <?php } else { ?>
                                <div data-tooltip="<?php esc_attr_e( 'Get started with MainWP.', 'mainwp' ); ?>" data-inverted="" data-position="left center"><a class="ui big fluid button" target="_blank" href="https://mainwp.com/install-mainwp/?utm_source=instawp-demo&utm_medium=banner&utm_campaign=download_black_banner&utm_id=instawp"><?php esc_html_e( 'Download MainWP', 'mainwp' ); // NOSONAR - noopener - open safe. ?></a></div>
                                <?php } ?>
                            </div>
                        </div>
                        <br/>
                    </div>
                    </div>
                </div>
                <script type="text/javascript">
                    jQuery( '#mainwp_demo_mode_accordion' ).accordion();
                </script>
            <?php endif; ?>


            <?php if ( 1 === (int) get_option( 'mainwp_enable_guided_tours', 0 ) ) : ?>
                <script type="text/javascript">
                    jQuery( document ).ready( function() {
                        jQuery( '#mainwp-start-page-tour-button' ).on( 'click', function() {
                            let tourId = jQuery( this ).attr( 'tour-id' );
                            jQuery( '#mainwp-documentation-sidebar' ).sidebar( 'toggle' );
                            if(tourId){
                                window.USETIFUL.tour.start( parseInt( tourId ) );
                            } else {
                                console.warn('Error: empty tour ID. Please set valid tour ID.');
                            }
                        } );
                        jQuery( '#mainwp-interface-tour-button' ).on( 'click', function() {
                            jQuery( '#mainwp-documentation-sidebar' ).sidebar( 'toggle' );
                            window.USETIFUL.tour.start( 13282 );
                        } );
                    } );
                </script>
            <?php endif; ?>

            <?php
            if ( isset( $_GET['message'] ) && ( 'qsw-import' === $_GET['message'] || 'enable-demo-mode' === $_GET['message'] ) ) : // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
                ?>
                <script type= 'text/javascript'>
                    let _count_retry         = 0;
                    _try_start_usetiful_tour = function () {
                        setTimeout(
                            function () {
                                try {
                                    window.USETIFUL.tour.start( parseInt( 40279 ) );
                                } catch ( e ) {
                                    if ( _count_retry < 10 ) {
                                        _count_retry++;
                                        console.log('retry:' + _count_retry);
                                        _try_start_usetiful_tour();
                                    }
                                }
                            },
                            1000
                        );
                    }
                    jQuery(document).ready(
                        function () {
                            _try_start_usetiful_tour();
                        }
                    );
                </script>
                <?php
                endif;
            ?>

            <script type="text/javascript">
            jQuery( document ).ready( function () {

                let pulse = jQuery( '#mainwp-screen-options-pulse-control' ).val();

                if (pulse == 1) {
                    jQuery('#mainwp-top-header .cog.icon').parent('.button').transition({
                        animation: 'pulse',
                        duration: 1000,
                        interval: 5500,
                        onStart: function () {
                            jQuery(this).removeClass('basic').addClass('green');
                        },
                        onComplete: function () {
                            jQuery(this).addClass('basic').removeClass('green');
                        }
                    });
                }

                jQuery( '#mainwp-jump-to-site-overview-dropdown' ).on( 'change', function() {
                    let site_id = jQuery( this ).val();
                    window.location.href = 'admin.php?page=managesites&dashboard=' + site_id;
                } );

                jQuery( '#mainwp-sites-menu-sidebar' ).prependTo( 'body' );
                jQuery( '#mainwp-documentation-sidebar' ).prependTo( 'body' );
                jQuery( 'body > div#wpwrap' ).addClass( 'pusher' );

                jQuery( '#mainwp-help-sidebar' ).on( 'click', function() {
                    jQuery( '.ui.help.sidebar' ).sidebar( {
                        transition: 'overlay'
                    } );
                    jQuery( '.ui.help.sidebar' ).sidebar( 'toggle' );
                    return false;
                } );
                jQuery( '#mainwp-sites-sidebar' ).on( 'click', function() {
                    jQuery( '.ui.sites.sidebar' ).sidebar( {
                        transition: 'overlay',
                        'onVisible': function() {
                            jQuery( '#mainwp-sites-menu-filter' ).focus();
                        }
                    } );
                    jQuery( '.ui.sites.sidebar' ).sidebar( 'toggle' );
                    return false;
                } );
                jQuery( '#mainwp-sites-sidebar-menu' ).accordion();
            } );
            </script>

            <?php static::render_help_modal(); ?>
            <?php
            /**
             * Action: mainwp_after_header
             *
             * Fires after the MainWP header element.
             *
             * @param array $websites Array containing the child site data.
             *
             * @since 4.0
             */
            do_action( 'mainwp_after_header', $websites );
            ?>
        <?php
    }


    /**
     * Method render_showhide_columns_settings()
     *
     * Render show/hide columns settings.
     *
     * @param array  $cols Columns.
     * @param array  $show_columns Show Columns.
     * @param string $what what.
     */
    public static function render_showhide_columns_settings( $cols, $show_columns, $what ) {
        ?>
        <div class="ui grid field">
            <label class="six wide column"><?php esc_html_e( 'Show columns', 'mainwp' ); ?></label>
            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select columns that you want to hide.', 'mainwp' ); ?> data-inverted="" data-position="top left">
                <ul>
                <?php
                foreach ( $cols as $name => $title ) {
                    $_selected = '';
                    if ( ! isset( $show_columns[ $name ] ) || 1 === (int) $show_columns[ $name ] ) {
                        $_selected = 'checked';
                    }
                    ?>
                    <li>
                        <div class="ui checkbox">
                            <input type="checkbox" id="mainwp_show_column_<?php echo esc_attr( $name ); ?>" name="mainwp_show_columns[]" <?php echo esc_html( $_selected ); ?> value="<?php echo esc_attr( $name ); ?>">
                            <label for="mainwp_show_column_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
                        </div>
                        <input type="hidden" name="mainwp_columns_name[]" value="<?php echo esc_attr( $name ); ?>">
                    </li>
                    <?php
                }
                ?>
                </ul>
            </div>

            </div>
            <?php

            $input_name = '';
            if ( 'post' === $what ) {
                $input_name = 'mainwp_manageposts_show_columns_settings';
            } elseif ( 'page' === $what ) {
                $input_name = 'mainwp_managepages_show_columns_settings';
            } elseif ( 'user' === $what ) {
                $input_name = 'mainwp_manageusers_show_columns_settings';
            }

            if ( ! empty( $input_name ) ) {
                ?>
                <input type="hidden" name="<?php echo esc_attr( $input_name ); ?>" value="1">
                <?php
            }
    }

    /**
     * Method render_second_top_header()
     *
     * Render second top header.
     *
     * @param string $which Current page.
     *
     * @return void Render second top header html.
     */
    public static function render_second_top_header( $which = '' ) {

        /**
         * Action: mainwp_before_subheader
         *
         * Fires before the MainWP sub-header element.
         *
         * @since 4.0
         */
        do_action( 'mainwp_before_subheader' );
        if ( has_action( 'mainwp_subheader_actions' ) || 'overview' === $which || 'managesites' === $which || 'monitoringsites' === $which ) {
            ?>
            <div class="mainwp-sub-header">
                <?php
                if ( 'overview' === $which ) {
                    ?>
                <div class="ui stackable grid">
                    <div class="column full">
                        <?php static::gen_groups_sites_selection(); ?>
                    </div>
                </div>
                    <?php
                }
                ?>
                    <?php if ( 'managesites' === $which || 'monitoringsites' === $which ) : ?>
                        <?php
                        /**
                         * Action: mainwp_managesites_tabletop
                         *
                         * Fires at the table top on the Manage Sites and Monitoring page.
                         *
                         * @since 4.0
                         */
                        do_action( 'mainwp_managesites_tabletop' );
                        ?>

                    <?php else : ?>
                        <?php
                        /**
                         * Action: mainwp_subheader_actions
                         *
                         * Fires at the subheader element to hook available actions.
                         *
                         * @since 4.0
                         */
                        do_action( 'mainwp_subheader_actions' );
                        ?>
                <?php endif; ?>
            </div>
            <?php
        }
            /**
             * Action: mainwp_after_subheader
             *
             * Fires after the MainWP sub-header element.
             *
             * @since 4.0
             */
            do_action( 'mainwp_after_subheader' );
    }

    /**
     * Method gen_groups_sites_selection()
     *
     * Generate group sites selection box.
     *
     * @return void Render group sites selection box.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_for_manage_sites()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_not_empty_groups()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public static function gen_groups_sites_selection() {
        $sql      = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
        $websites = MainWP_DB::instance()->query( $sql );
        $g        = isset( $_GET['g'] ) ? intval( $_GET['g'] ) : -1; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
        $s        = isset( $_GET['dashboard'] ) ? intval( $_GET['dashboard'] ) : -1; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="column full wide">
            <select id="mainwp_top_quick_jump_group" class="ui dropdown">
                <option value="" class="item"><?php esc_html_e( 'All Tags', 'mainwp' ); ?></option>
                <option <?php echo ( -1 === $g ) ? 'selected' : ''; ?> value="-1" class="item"><?php esc_html_e( 'All Tags', 'mainwp' ); ?></option>
                <?php
                $groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
                foreach ( $groups as $group ) {
                    ?>
                    <option class="item" <?php echo ( $g === (int) $group->id ) ? 'selected' : ''; ?> value="<?php echo esc_attr( $group->id ); ?>"><?php echo esc_html( stripslashes( $group->name ) ); ?></option>
                    <?php
                }
                ?>
            </select>
            <select class="ui dropdown" id="mainwp_top_quick_jump_page">
                <option value="" class="item"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></option>
                <option <?php echo ( -1 === $s ) ? 'selected' : ''; ?> value="-1" class="item" ><?php esc_html_e( 'All Sites', 'mainwp' ); ?></option>
                <?php
                while ( $websites && ( $website   = MainWP_DB::fetch_object( $websites ) ) ) {
                    ?>
                    <option value="<?php echo intval( $website->id ); ?>" <?php echo ( $s === (int) $website->id ) ? 'selected' : ''; ?> class="item" ><?php echo esc_html( stripslashes( $website->name ) ); ?></option>
                    <?php
                }
                ?>
            </select>
        </div>
        <script type="text/javascript">
            jQuery( document ).on( 'change', '#mainwp_top_quick_jump_group', function () {
                let jumpid = jQuery( this ).val();
                window.location = 'admin.php?page=managesites&g='  + jumpid;
            } );
            jQuery( document ).on( 'change', '#mainwp_top_quick_jump_page', function () {
                let jumpid = jQuery( this ).val();
                if ( jumpid == -1 )
                    window.location = 'admin.php?page=managesites&s='  + jumpid;
                else
                    window.location = 'admin.php?page=managesites&dashboard='  + jumpid;
            } );
        </script>
                <?php
                MainWP_DB::free_result( $websites );
    }

    /**
     * Method render_header_actions()
     *
     * Render header action buttons,
     * (Sync|Add|Options|Community|User|Updates).
     *
     * @return mixed $output Render header action buttons html.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_count()
     */
    public static function render_header_actions() { // phpcs:ignore -- NOSONAR - complex method.
        $sites_count   = MainWP_DB::instance()->get_websites_count();
        $sidebar_pages = array( 'ManageGroups', 'PostBulkManage', 'PostBulkAdd', 'PageBulkManage', 'PageBulkAdd', 'ThemesManage', 'ThemesInstall', 'ThemesAutoUpdate', 'PluginsManage', 'PluginsInstall', 'PluginsAutoUpdate', 'UserBulkManage', 'UserBulkAdd', 'UpdateAdminPasswords', 'Extensions' );
        $sidebar_pages = apply_filters( 'mainwp_sidbar_pages', $sidebar_pages ); // deprecated filter.
        $sidebar_pages = apply_filters( 'mainwp_sidebar_pages', $sidebar_pages );
        $current_user  = get_current_user_id();

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $page = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : '';
        ob_start();
        if ( isset( $_GET['dashboard'] ) || isset( $_GET['id'] ) || isset( $_GET['updateid'] ) || isset( $_GET['emailsettingsid'] ) || isset( $_GET['scanid'] ) ) :
            $id = 0;
            if ( isset( $_GET['dashboard'] ) ) {
                $id = intval( $_GET['dashboard'] );
            } elseif ( isset( $_GET['id'] ) ) {
                $id = intval( $_GET['id'] );
            } elseif ( isset( $_GET['updateid'] ) ) {
                $id = intval( $_GET['updateid'] );
            } elseif ( isset( $_GET['emailsettingsid'] ) ) {
                $id = intval( $_GET['emailsettingsid'] );
            } elseif ( isset( $_GET['scanid'] ) ) {
                $id = intval( $_GET['scanid'] );
            } elseif ( isset( $_GET['monitor_wpid'] ) ) {
                $id = intval( $_GET['monitor_wpid'] );
            }

            $website = MainWP_DB::instance()->get_website_by_id( $id );
            ?>
            <?php if ( $id && $website && '' !== $website->sync_errors ) : ?>
                <a href="#" class="mainwp-updates-overview-reconnect-site ui green icon button" adminuser="<?php echo esc_attr( $website->adminname ); ?>" siteid="<?php echo intval( $website->id ); ?>" data-position="bottom right" aria-label="Reconnect <?php echo esc_html( stripslashes( $website->name ) ); ?>" data-tooltip="Reconnect <?php echo esc_html( stripslashes( $website->name ) ); ?>" data-inverted=""><i class="undo alternate icon"></i></a>
            <?php else : ?>
                <a class="ui icon button green <?php echo 0 < $sites_count ? '' : 'disabled'; ?>" id="mainwp-sync-sites" data-tooltip="<?php esc_attr_e( 'Get fresh data from your child sites.', 'mainwp' ); ?>" data-inverted="" data-position="bottom right" aria-label="<?php esc_attr_e( 'Get fresh data from your child sites.', 'mainwp' ); ?>" >
                    <i class="sync alt icon"></i>
                </a>
            <?php endif; ?>
        <?php else : ?>
            <a class="ui icon button green <?php echo 0 < $sites_count ? '' : 'disabled'; ?> " id="mainwp-sync-sites" data-tooltip="<?php esc_attr_e( 'Click here to sync data now.', 'mainwp' ); ?>" data-inverted="" data-position="bottom right" aria-label="<?php esc_attr_e( 'Click here to sync data now.', 'mainwp' ); ?>">
                <i class="sync alternate icon"></i>
            </a>
        <?php endif; ?>

        <div class="ui icon top left pointing dropdown <?php echo empty( $sites_count ) ? 'green' : ''; ?> button" id="mainwp-add-new-buttons" aria-label="<?php esc_attr_e( 'Add new item to your MainWP Dashboard', 'mainwp' ); ?>">
            <i class="plus icon"></i>
            <div class="menu">
                <a class="item" href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php esc_html_e( 'Add Website', 'mainwp' ); ?></a>
                <a class="item" href="<?php echo esc_url( admin_url( 'admin.php?page=ClientAddNew' ) ); ?>"><?php esc_html_e( 'Add Client', 'mainwp' ); ?></a>
                <a class="item" href="<?php echo esc_url( admin_url( 'admin.php?page=CostTrackerAdd' ) ); ?>"><?php esc_html_e( 'Add Cost', 'mainwp' ); ?></a>
                <a class="item" href="<?php echo esc_url( admin_url( 'admin.php?page=PostBulkAdd' ) ); ?>"><?php esc_html_e( 'Create Post', 'mainwp' ); ?></a>
                <a class="item" href="<?php echo esc_url( admin_url( 'admin.php?page=PageBulkAdd' ) ); ?>"><?php esc_html_e( 'Create Page', 'mainwp' ); ?></a>
                <a class="item" href="<?php echo esc_url( admin_url( 'admin.php?page=PluginsInstall' ) ); ?>"><?php esc_html_e( 'Install Plugin', 'mainwp' ); ?></a>
                <a class="item" href="<?php echo esc_url( admin_url( 'admin.php?page=ThemesInstall' ) ); ?>"><?php esc_html_e( 'Install Theme', 'mainwp' ); ?></a>
                <a class="item" href="<?php echo esc_url( admin_url( 'admin.php?page=UserBulkAdd' ) ); ?>"><?php esc_html_e( ' Create User', 'mainwp' ); ?></a>
            </div>
        </div>

        <?php if ( ( 'mainwp_tab' === $page ) || isset( $_GET['dashboard'] ) || in_array( $page, $sidebar_pages ) ) : // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended ?>
        <a id="mainwp-screen-options-button" class="ui button icon" onclick="jQuery( '#mainwp-overview-screen-options-modal' ).modal({allowMultiple:true}).modal( 'show' ); return false;" data-inverted="" data-position="bottom right" href="#" aria-label="<?php esc_attr_e( 'Page Settings', 'mainwp' ); ?>" data-tooltip="<?php esc_html_e( 'Page Settings', 'mainwp' ); ?>">
            <i class="cog icon"></i>
        </a>
        <?php endif; ?>
        <?php
        // phpcs:enable
        /**
         * Filter: mainwp_header_actions_right
         *
         * Filters the MainWP header element actions.
         *
         * @since 4.0
         */
        $actions = apply_filters( 'mainwp_header_actions_right', '' );
        if ( ! empty( $actions ) ) {
            echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        ?>
        <a class="ui button icon" id="mainwp-sites-sidebar" aria-label="<?php esc_attr_e( 'Open sites shortcuts sidebar', 'mainwp' ); ?>" data-inverted="" data-position="bottom right" href="#" data-tooltip="<?php esc_attr_e( 'Quick sites shortcuts', 'mainwp' ); ?>">
            <i class="globe icon"></i>
        </a>
        <div id="mainwp-select-theme-button" class="ui button icon mainwp-selecte-theme-button" aria-label="<?php esc_attr_e( 'Select MainWP theme', 'mainwp' ); ?>" custom-theme="default" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_html_e( 'Select MainWP theme', 'mainwp' ); ?>">
            <i class="palette icon"></i>
        </div>

        <?php
        /**
         * After select theme actions.
         *
         * @since 4.5.2
         */
        do_action( 'mainwp_header_actions_after_select_themes' );
        $show_avar = get_option( 'show_avatars' );
        ?>
        <div class="ui top right pointing dropdown <?php echo $show_avar ? '' : 'icon button'; ?> mainwp-768-hide" id="mainwp-user-menu-button">
            <?php
            if ( $show_avar ) {
                echo get_avatar(
                    $current_user,
                    38,
                    'wavatar',
                    esc_html__( 'Settings', 'mainwp' ),
                    array(
                        'extra_attr' => 'style="width:38px!important;height:38px!important;"',
                        'class'      => 'ui small avatar image',
                    )
                );
            } else {
                ?>
                <i class="user icon"></i>
                <?php
            }
            ?>
            <div class="menu">
                <a class="item" id="mainwp-wp-admin-menu-item" href="<?php echo esc_url( admin_url( 'admin.php?page=Settings' ) ); ?>" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Go to MainWP Settings', 'mainwp' ); ?>">
                    <i class="cog grey icon"></i> <?php esc_html_e( 'MainWP Settings', 'mainwp' ); ?>
                </a>
                <a class="item" id="mainwp-wp-admin-menu-item" href="<?php echo esc_url( admin_url( 'admin.php?page=ServerInformation' ) ); ?>" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Go to MainWP Info', 'mainwp' ); ?>">
                    <i class="circle grey info icon"></i> <?php esc_html_e( 'System Info', 'mainwp' ); ?>
                </a>
                <a class="item" id="mainwp-wp-admin-menu-item" href="<?php echo esc_url( admin_url( 'admin.php?page=PluginPrivacy' ) ); ?>" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Read the MainWP plugin privacy policy.', 'mainwp' ); ?>">
                    <i class="file contract grey icon"></i> <?php esc_html_e( 'Privacy Policy', 'mainwp' ); ?>
                </a>
                <a id="mainwp-help-sidebar" class="item" data-inverted="" data-position="left center" href="#" target="_blank" data-tooltip="<?php esc_attr_e( 'Need help?', 'mainwp' ); ?>">
                    <i class="life ring grey icon"></i> <?php esc_html_e( 'Get Help', 'mainwp' ); ?>
                </a>
                <div class="ui divider"></div>
                <a class="item" id="mainwp-wp-admin-menu-item" href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Go to WP Admin', 'mainwp' ); ?>">
                    <i class="wordpress grey icon"></i> <?php //phpcs:ignore -- ignore wordpress icon. ?> <?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?>
                </a>
                <?php $all_updates = wp_get_update_data(); ?>
                <?php if ( is_array( $all_updates ) && isset( $all_updates['counts']['total'] ) && 0 < $all_updates['counts']['total'] ) : ?>
                <a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Your MainWP Dashboard sites needs your attention. Please check the available updates', 'mainwp' ); ?>" aria-label="<?php esc_attr_e( 'Your MainWP Dashboard sites needs your attention. Please check the available updates', 'mainwp' ); ?>" href="update-core.php">
                    <i class="exclamation triangle red icon"></i> <?php esc_html_e( 'Update Dashboard Site', 'mainwp' ); ?>
                </a>
                <?php endif; ?>
                <div class="ui divider"></div>
                <a class="item" id="mainwp-community-button" data-inverted="" data-position="left center" href="https://community.mainwp.com/" target="_blank" data-tooltip="<?php esc_attr_e( 'MainWP Community', 'mainwp' ); ?>">
                    <i class="discourse grey icon"></i> <?php esc_html_e( 'Managers Community', 'mainwp' ); ?>
                </a>
                <a  class="item" id="mainwp-account-button" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Go to your MainWP Account at MainWP.com', 'mainwp' ); ?>" target="_blank" href="https://mainwp.com/my-account/"> <?php // NOSONAR - noopener - open safe. ?>
                    <i class="user grey icon"></i> <?php esc_html_e( 'My MainWP Account', 'mainwp' ); ?>
                </a>
                <div class="ui divider"></div>
                <a class="item" href="<?php echo wp_logout_url(); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Log out of your MainWP Dashboard', 'mainwp' ); ?>">
                    <i class="sign out grey icon"></i> <?php esc_html_e( 'Log Out', 'mainwp' ); ?>
                </a>
            </div>
        </div>
        <?php $pulse = apply_filters( 'mainwp_screen_options_pulse_control', 1 ); ?>
        <input type="hidden" id="mainwp-screen-options-pulse-control" value="<?php echo esc_attr( $pulse ); ?>">
        <script>
            jQuery( document ).ready( function( $ ) {
                $( '#mainwp-user-menu-button' ).dropdown();
            } );
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Method render_page_navigation()
     *
     * Render page navigation.
     *
     * @param array $subitems [access, active, style].
     * @param null  $name_caller Menu Name.
     */
    public static function render_page_navigation( $subitems = array(), $name_caller = null ) {  //phpcs:ignore -- NOSONAR - complex.

        /**
         * Filter: mainwp_page_navigation
         *
         * Filters MainWP page navigation menu items.
         *
         * @since 4.0
         */
        $subitems = apply_filters( 'mainwp_page_navigation', $subitems, $name_caller );
        ?>
        <div id="mainwp-page-navigation-wrapper">
            <?php if ( isset( $_GET['dashboard'] ) || isset( $_GET['id'] ) || isset( $_GET['updateid'] ) || isset( $_GET['emailsettingsid'] ) || isset( $_GET['scanid'] ) || isset( $_GET['monitor_wpid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
                <?php
                //phpcs:disable WordPress.Security.NonceVerification
                $id = 0;
                if ( isset( $_GET['dashboard'] ) ) {
                    $id = intval( $_GET['dashboard'] );
                } elseif ( isset( $_GET['id'] ) ) {
                    $id = intval( $_GET['id'] );
                } elseif ( isset( $_GET['updateid'] ) ) {
                    $id = intval( $_GET['updateid'] );
                } elseif ( isset( $_GET['emailsettingsid'] ) ) {
                    $id = intval( $_GET['emailsettingsid'] );
                } elseif ( isset( $_GET['scanid'] ) ) {
                    $id = intval( $_GET['scanid'] );
                } elseif ( isset( $_GET['monitor_wpid'] ) ) {
                    $id = intval( $_GET['monitor_wpid'] );
                }
                // phpcs:enable
                $website = MainWP_DB::instance()->get_website_by_id( $id );
                ?>
                <img alt="<?php esc_attr_e( 'Website preview', 'mainwp' ); ?>" src="//s0.wordpress.com/mshots/v1/<?php echo esc_html( rawurlencode( $website->url ) ); ?>?w=170" id="mainwp-site-preview-image">
            <?php endif; ?>

            <div class="ui vertical menu mainwp-page-navigation">

                <?php

                if ( is_array( $subitems ) ) {
                    foreach ( $subitems as $item ) {

                        if ( ! is_array( $item ) ) {
                            continue;
                        }

                        if ( isset( $item['access'] ) && ! $item['access'] ) {
                            continue;
                        }

                        $class = '';
                        if ( isset( $item['active'] ) && $item['active'] ) {
                            $class = 'active';
                        }

                        if ( isset( $item['class'] ) ) {
                            $class = $class . ' ' . $item['class'];
                        }

                        $style = '';
                        if ( isset( $item['style'] ) ) {
                            $style = $item['style'];
                        }

                        ?>
                        <a class="<?php echo esc_attr( $class ); ?> item" style="<?php echo esc_attr( $style ); ?>" href="<?php echo esc_url( $item['href'] ); ?>">
                        <?php echo isset( $item['before_title'] ) ? $item['before_title'] : ''; ?> <?php echo esc_html( $item['title'] ); ?> <?php echo isset( $item['after_title'] ) ? $item['after_title'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </a>
                        <?php
                    }
                }

                do_action( 'mainwp_page_navigation_menu' );
                ?>
            </div>

        </div>
        <?php
        $is_site = MainWP_System::is_mainwp_site_page();
        if ( $is_site ) {
            ?>
            <div id="mainwp-site-mode-wrap">
        <?php } ?>

        <?php
    }

    /**
     * Method render_header()
     *
     * Render page title.
     *
     * @param string $title Page title.
     *
     * @return void Render page title and hidden divider html.
     */
    public static function render_header( $title = '' ) {
        static::render_top_header( array( 'title' => $title ) );
        echo '<div class="ui hidden clearing fitted divider"></div>';
        echo '<div class="wrap">';
    }

    /**
     * Method render_footer()
     *
     * Render page footer.
     *
     * @return void Render closing tags for page container.
     */
    public static function render_footer() {
        $is_site = MainWP_System::is_mainwp_site_page();
        if ( $is_site ) {
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Method add_widget_box()
     *
     * Customize WordPress add_meta_box() function.
     *
     * @param mixed $id Widget ID parameter.
     * @param mixed $callback Callback function.
     * @param null  $screen Current page.
     * @param array $layout widget layout.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_page_id()
     */
    public static function add_widget_box( $id, $callback, $screen = null, $layout = array() ) {
        /**
        * MainWP widget boxes array.
        *
        * @global object
        */
        global $mainwp_widget_boxes;

        $page = MainWP_System_Utility::get_page_id( $screen );

        if ( empty( $page ) ) {
            return;
        }

        if ( ! isset( $mainwp_widget_boxes ) ) {
            $mainwp_widget_boxes = array();
        }
        if ( ! isset( $mainwp_widget_boxes[ $page ] ) ) {
            $mainwp_widget_boxes[ $page ] = array();
        }

        $mainwp_widget_boxes[ $page ][ $id ] = array(
            'id'       => $id,
            'callback' => $callback,
            'layout'   => $layout,
        );
    }

    /**
     * Method do_widget_boxes()
     *
     * Customize WordPress do_meta_boxes() function.
     *
     * @param mixed $screen_id Current page ID.
     * @param array ...$args Widget callback args.
     *
     * @return void Renders widget container box.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_page_id()
     */
    public static function do_widget_boxes( $screen_id, ...$args ) { // phpcs:ignore -- NOSONAR - complex.
        global $mainwp_widget_boxes;
        $page = MainWP_System_Utility::get_page_id( $screen_id );
        if ( empty( $page ) ) {
            return;
        }

        $wgsorted               = false;
        $selected_widget_layout = '';

        if ( ! empty( $_GET['page'] ) && ! empty( $_GET['select_layout'] ) && ! empty( $_GET['_opennonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_opennonce'] ), 'mainwp-admin-nonce' ) && ! empty( $_GET['updated'] ) && ! empty( $_GET['screen_slug'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            $layid       = sanitize_text_field( wp_unslash( $_GET['updated'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            $screen_slug = sanitize_text_field( wp_unslash( $_GET['screen_slug'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended

            $saved_layouts   = MainWP_Ui_Manage_Widgets_Layout::set_get_widgets_layout( false, array(), $screen_slug );
            $selected_layout = is_array( $saved_layouts ) && isset( $saved_layouts[ $layid ] ) ? $saved_layouts[ $layid ] : array();
            if ( is_array( $selected_layout ) && isset( $selected_layout['layout'] ) ) {
                $wgsorted = $selected_layout['layout'];
                if ( isset( $selected_layout['name'] ) ) {
                    $selected_widget_layout = $selected_layout['name'];
                }
            }
        }

        if ( empty( $wgsorted ) ) {
            $wgsorted = get_user_option( 'mainwp_widgets_sorted_' . strtolower( $page ) );
        }

        if ( ! empty( $wgsorted ) && is_string( $wgsorted ) ) {
            $wgsorted = json_decode( $wgsorted, true );
        }

        if ( ! is_array( $wgsorted ) ) {
            $wgsorted = array();
        }

        $client_id = 0;
        if ( 'mainwp_page_manageclients' === $page ) {
            $sorted_array = is_array( $wgsorted ) ? $wgsorted : array();
            $wgsorted     = array();
            $client_id    = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            if ( ! empty( $client_id ) && is_array( $sorted_array ) && isset( $sorted_array[ $client_id ] ) ) {
                $wgsorted = $sorted_array[ $client_id ];
            }
        }

        $wgsorted = apply_filters( 'mainwp_do_widget_boxes_sorted', $wgsorted, $page, $client_id );

        if ( 'mainwp_page_manageclients' === $page ) {
            $show_widgets = get_user_option( 'mainwp_clients_show_widgets' );
        } elseif ( 'toplevel_page_mainwp_tab' === $page || 'mainwp_page_managesites' === $page ) {
            $show_widgets = get_user_option( 'mainwp_settings_show_widgets' );
        } else {
            $show_widgets = apply_filters( 'mainwp_widget_boxes_show_widgets', array(), $page );
        }

        if ( ! is_array( $show_widgets ) ) {
            $show_widgets = array();
        }

        if ( $selected_widget_layout ) {
            // to support saving selected layout.
            ?>
            <input type="hidden" id="mainwp-widgets-selected-layout" layout-name="<?php echo esc_attr( $selected_layout['name'] ); ?>" layout-idx="<?php echo esc_attr( $layid ); ?>" >
            <script type="text/javascript">
                jQuery( document ).ready( function( $ ) {
                    mainwp_overview_gridstack_save_layout(<?php echo (int) $client_id; ?>);
                } );
            </script>
            <?php
        }
        ?>
        <div id="mainwp-grid-stack-wrapper" class="grid-stack">
        <?php
        if ( isset( $mainwp_widget_boxes[ $page ] ) ) {
            foreach ( (array) $mainwp_widget_boxes[ $page ] as $box ) {
                if ( false === $box || ! isset( $box['callback'] ) ) {
                    continue;
                }

                // to avoid hidden widgets.
                if ( isset( $show_widgets[ $box['id'] ] ) && 0 === (int) $show_widgets[ $box['id'] ] ) {
                    continue;
                }

                $layout = array();
                if ( isset( $wgsorted[ $box['id'] ] ) ) {
                    $val = $wgsorted[ $box['id'] ];
                    if ( is_array( $val ) && isset( $val['x'] ) ) {
                        $layout = array(
                            'x' => $val['x'],
                            'y' => $val['y'],
                            'w' => $val['w'],
                            'h' => $val['h'],
                        );
                    }
                }

                // init widget layout settings.
                if ( ! isset( $layout['x'] ) && isset( $box['layout']['2'] ) ) {
                    $layout = array(
                        'x' => $box['layout']['0'],
                        'y' => $box['layout']['1'],
                        'w' => $box['layout']['2'],
                        'h' => $box['layout']['3'],
                    );
                }

                // default settings.
                if ( ! isset( $layout['x'] ) ) {
                    $layout['w'] = 4;
                    $layout['h'] = 4;
                }

                $layout_attrs_escaped  = ' gs-y="' . ( isset( $layout['y'] ) && -1 !== (int) ( $layout['y'] ) ? esc_attr( $layout['y'] ) : '' ) . '" gs-x="' . ( isset( $layout['x'] ) && - 1 !== (int) $layout['x'] ? esc_attr( $layout['x'] ) : '' ) . '" ';
                $layout_attrs_escaped .= ' gs-w="' . ( isset( $layout['w'] ) ? esc_attr( $layout['w'] ) : '' ) . '" gs-h="' . ( isset( $layout['h'] ) ? esc_attr( $layout['h'] ) : '' ) . '" ';

                echo '<div id="widget-' . esc_html( $box['id'] ) . '" class="grid-stack-item" ' . $layout_attrs_escaped . '>' . "\n"; //phpcs:ignore -- escaped.
                    echo '<div class="grid-stack-item-content ui segment mainwp-widget">' . "\n";
                        call_user_func( $box['callback'], $screen_id, $args );
                    echo "</div>\n";
                echo "</div>\n";

            }
        }
        ?>
        </div>
        <script type="text/javascript">
                let page_sortablewidgets = '<?php echo esc_js( $page ); ?>';
                jQuery( document ).ready( function( $ ) {
                    let wgIds = [];
                    jQuery( ".mainwp-widget" ).each( function () {
                        wgIds.push( jQuery( this ).attr('id') );
                    } );
                    let gsOpts = {
                        auto: true,
                        cellHeight: '1rem',
                        float: false,
                        resizable: {
                            handles: 'e,se,s,sw,w'
                        },
                        margin: '1rem',
                        itemClass: 'grid-stack-item',
                        handleClass: 'handle-drag',
                        columnOpts: {
                            breakpointForWindow: true,  // test window vs grid size
                            breakpoints: [{w:768, c:1}],
                        },
                    }

                    let grid = GridStack.init(gsOpts);
                    grid.on('change', function() {
                        mainwp_overview_gridstack_save_layout();
                    });
                });
        </script>
        <?php
    }

    /**
     * Method add_bulkpost_widget_box()
     *
     * @param mixed $boxid box id.
     * @param mixed $params parameters.
     */
    public static function add_bulkpost_widget_box( $boxid, $params ) {
        /**
        * MainWP widget boxes array.
        *
        * @global object
        */
        global $wp_meta_boxes;

        $screen_page_id = MainWP_System_Utility::get_page_id();

        if ( empty( $screen_page_id ) ) {
            return;
        }

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        if ( ! isset( $wp_meta_boxes ) ) {
            $wp_meta_boxes = array(); //phpcs:ignore -- NOSONAR - ok.
        }

        $page = MainWP_Post::get_fix_metabox_page( $screen_page_id );

        if ( ! isset( $wp_meta_boxes[ $page ] ) ) {
            $wp_meta_boxes[ $page ] = array(); //phpcs:ignore -- NOSONAR - ok.
        }

        $context  = ! empty( $params['context'] ) ? $params['context'] : 'normal';
        $priority = ! empty( $params['priority'] ) ? $params['priority'] : 'default';

        if ( ! isset( $wp_meta_boxes[ $page ][ $context ] ) ) {
            $wp_meta_boxes[ $page ][ $context ] = array(); //phpcs:ignore -- NOSONAR - ok.
        }

        if ( ! isset( $wp_meta_boxes[ $page ][ $context ][ $priority ] ) ) {
            $wp_meta_boxes[ $page ][ $context ][ $priority ] = array(); //phpcs:ignore -- NOSONAR - ok.
        }

        if ( ! empty( $params['id'] ) ) {
            $params['id'] = 'bulkpost-metabox-' . $params['id'];
        } else {
            $params['id'] = '';
        }

        if ( empty( $params['metabox-custom'] ) ) {
            $params['metabox-custom'] = 'bulkpost';
        }
        $wp_meta_boxes[ $page ][ $context ][ $priority ][ $boxid ] = $params; //phpcs:ignore -- NOSONAR - ok.
    }

    /**
     * Method render_empty_bulk_actions()
     *
     * Render empty bulk actions when drop down is disabled.
     */
    public static function render_empty_bulk_actions() {
        ?>
        <select class="ui disabled dropdown" id="mainwp-bulk-actions">
            <option value=""><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
        </select>
        <button class="ui tiny basic disabled button" href="javascript:void(0)" ><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
                <?php
    }

    /**
     * Method render_modal_install_plugin_theme()
     *
     * Render modal window for installing plugins & themes.
     *
     * @param string $what Which window to render, plugin|theme.
     */
    public static function render_modal_install_plugin_theme( $what = 'plugin' ) {
        ?>
        <div id="plugintheme-installation-progress-modal" class="ui modal">
            <i class="close icon"></i>
            <div class="header">
            <?php
            if ( 'plugin' === $what ) {
                esc_html_e( 'Plugin Installation', 'mainwp' );
            } elseif ( 'theme' === $what ) {
                esc_html_e( 'Theme Installation', 'mainwp' );
            }
            ?>
            </div>
            <div class="ui green progress mainwp-modal-progress">
                <div class="bar"><div class="progress"></div></div>
                <div class="label"></div>
            </div>
            <div class="scrolling content">
                <?php
                /**
                 * Action: mainwp_before_plugin_theme_install_progress
                 *
                 * Fires before the progress list in the install modal element.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_before_plugin_theme_install_progress' );
                ?>
                <div id="plugintheme-installation-queue"></div>
                <?php
                /**
                 * Action: mainwp_after_plugin_theme_install_progress
                 *
                 * Fires after the progress list in the install modal element.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_after_plugin_theme_install_progress' );
                ?>
            </div>
            <div class="actions" item-type="<?php echo esc_attr( $what ); ?>">
                <?php
                /**
                 * Action: mainwp_after_plugin_theme_install_progress
                 *
                 * Fires after the progress list in the install modal element.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_install_plugin_theme_modal_action', $what );
                ?>

            </div>
        </div>
        <?php
    }

    /**
     * Method render_modal_upload_icon()
     *
     * Render modal window for upload plugins & themes icon.
     */
    public static function render_modal_upload_icon() {

        ?>
        <div id="mainwp-upload-custom-icon-modal" class="ui modal">
        <i class="close icon"></i>
            <div class="header">
            <?php
            esc_html_e( 'Upload Icon', 'mainwp' );
            ?>
            </div>
                <div class="content" id="mainwp-upload-custom-icon-content">
                <form action="" method="post" enctype="multipart/form-data" name="uploadicon_form" id="uploadicon_form" class="">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <div class="ui message" id="mainwp-message-zone-upload" style="display:none;"></div>
                    <?php
                    /**
                     * Action: mainwp_after_upload_custom_icon
                     *
                     * Fires before the modal element.
                     *
                     * @since 4.3
                     */
                    do_action( 'mainwp_before_upload_custom_icon' );
                    ?>
                    <div class="ui form">
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Custom icon', 'mainwp' ); ?></label>
                            <div class="six wide column" data-tooltip="<?php esc_attr_e( 'Upload a custom icon.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui file input">
                                    <input type="file" id="mainwp_upload_icon_uploader" name="mainwp_upload_icon_uploader[]" accept="image/*" data-inverted="" data-tooltip="<?php esc_attr_e( 'Upload a custom icon.', 'mainwp' ); ?>" />
                                </div>
                            </div>
                        </div>
                        <div class="ui grid field" id="mainwp_delete_image_field">
                            <label class="six wide column middle aligned"></label>
                            <div class="six wide column">
                                <img class="ui tiny image" src="" alt="<?php esc_attr_e( 'Icon to remove.', 'mainwp' ); ?>"/><br/>
                                <div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, delete image.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                    <input type="checkbox"id="mainwp_delete_image_chk" item-icon-id="" />
                                    <label for="mainwp_delete_image_chk"><?php esc_html_e( 'Delete Image', 'mainwp' ); ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                    /**
                     * Action: mainwp_after_upload_custom_icon
                     *
                     * Fires after the modal element.
                     *
                     * @since 4.3
                     */
                    do_action( 'mainwp_after_upload_custom_icon' );
                    ?>
                    </form>
                </div>
                <div class="actions">
                    <div class="ui green button"  uploading-icon="default" id="update_custom_icon_btn"><?php esc_html_e( 'Update', 'mainwp' ); ?></div>
                </div>
        </div>
                <?php
    }

    /**
     * Method render_show_all_updates_button()
     *
     * Render show all updates button.
     *
     * @return void Render Show All Updates button html.
     */
    public static function render_show_all_updates_button() {
        ?>
        <a href="javascript:void(0)" class="ui mini button trigger-all-accordion mainwp-show-all-updates-button">
            <?php
            /**
             * Filter: mainwp_show_all_updates_button_text
             *
             * Filters the Show All Updates button text.
             *
             * @since 4.1
             */
            echo esc_html( apply_filters( 'mainwp_show_all_updates_button_text', esc_html__( 'Show All Updates', 'mainwp' ) ) );
            ?>
        </a>
        <?php
    }

    /**
     * Method render_sorting_icons()
     *
     * Render sorting up & down icons.
     *
     * @return void Render Sort up & down incon html.
     */
    public static function render_sorting_icons() {
        ?>
        <i class="sort icon"></i><i class="sort up icon"></i><i class="sort down icon"></i>
        <?php
    }

    /**
     * Method render_modal_edit_notes()
     *
     * Render modal window for edit notes.
     *
     * @param string $what What modal window to render. Default = site.
     *
     * @return void
     */
    public static function render_modal_edit_notes( $what = 'site' ) {
        ?>
        <div id="mainwp-notes-modal" class="ui modal">
            <i class="close icon" id="mainwp-notes-cancel"></i>
            <div class="header"><?php esc_html_e( 'Notes', 'mainwp' ); ?></div>
            <div class="content" id="mainwp-notes-content">
                <div id="mainwp-notes-status" class="ui message hidden"></div>
                <?php
                /**
                 * Action: mainwp_before_edit_site_note
                 *
                 * Fires before the site note content in the Edit Note modal element.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_before_edit_site_note' );
                ?>
                <div id="mainwp-notes-html"></div>
                <div id="mainwp-notes-editor" class="ui form" style="display:none;">
                    <div class="field">
                        <label><?php esc_html_e( 'Edit note', 'mainwp' ); ?></label>
                        <textarea id="mainwp-notes-note"></textarea>
                    </div>
                    <div><?php esc_html_e( 'Allowed HTML tags:', 'mainwp' ); ?> &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;hr&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;h1&gt;, &lt;h2&gt; </div>
                </div>
                <?php
                /**
                 * Action: mainwp_after_edit_site_note
                 *
                 * Fires after the site note content in the Edit Note modal element.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_after_edit_site_note' );
                ?>
            </div>
            <div class="actions">
                <div class="ui grid">
                    <div class="eight wide left aligned middle aligned column">
                        <input type="button" class="ui green button" id="mainwp-notes-save" value="<?php esc_attr_e( 'Save Note', 'mainwp' ); ?>" style="display:none;"/>
                        <input type="button" class="ui green button" id="mainwp-notes-edit" value="<?php esc_attr_e( 'Edit Note', 'mainwp' ); ?>"/>
                    </div>
                    <div class="eight wide column">
                        <input type="hidden" id="mainwp-notes-websiteid" value=""/>
                        <input type="hidden" id="mainwp-notes-slug" value=""/>
                        <input type="hidden" id="mainwp-which-note" value="<?php echo esc_html( $what ); ?>"/>
                        <input type="hidden" id="mainwp-notes-itemid" value=""/>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * No Sites Modal
     *
     * Renders modal window for notification when there are no connected sites.
     *
     * @return void
     */
    public static function render_modal_no_sites_note() {
        if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-no-sites-modal-notice' ) ) :
            ?>
        <div id="mainwp-no-sites-modal" class="ui small modal">
            <div class="content" id="mainwp-no-sites-modal-content" style="text-align:center">
                <div class="ui hidden divider"></div>
                <div class="ui hidden divider"></div>
                <h4><?php esc_html_e( 'Hi MainWP Manager, there is not anything to see here before connecting your first site.', 'mainwp' ); ?></h4>
                <div class="ui hidden divider"></div>
                <div class="ui hidden divider"></div>
                <a href="admin.php?page=managesites&do=new" class="ui big green button"><?php esc_html_e( 'Connect Your WordPress Site', 'mainwp' ); ?></a>
                <div class="ui hidden fitted divider"></div>
                <small><?php printf( esc_html__( 'or you can %1$sbulk import%2$s your sites.', 'mainwp' ), '<a href="admin.php?page=managesites&do=bulknew">', '</a>' ); ?></small>
            </div>
            <div class="actions">
                <div class="ui grid">
                    <div class="eight wide left aligned middle aligned column">
                        <a href="https://kb.mainwp.com/docs/get-started-with-mainwp/" class="ui basic green mini button" target="_blank"><?php esc_html_e( 'See How to Connect Sites', 'mainwp' ); // NOSONAR - noopener - open safe. ?></a>
                    </div>
                    <div class="eight wide column">
                        <input type="button" class="ui mini basic cancel button mainwp-notice-dismiss" notice-id="mainwp-no-sites-modal-notice" value="<?php esc_attr_e( 'Let Me Look Around', 'mainwp' ); ?>"/>
                    </div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
        jQuery( '#mainwp-no-sites-modal' ).modal( {
            blurring: true,
            inverted: true,
            closable: false
        } ).modal( 'show' );
        </script>
            <?php
    endif;
    }

    /**
     * Help Modal
     *
     * Renders the help modal.
     *
     * @return void
     */
    public static function render_help_modal() { //phpcs:ignore -- NOSONAR - complex.
        $siteViewMode = MainWP_Utility::get_siteview_mode();

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized.Recommended
        $page = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : '';

        $tour_id  = '';
        $video_id = '';
        if ( 'mainwp_tab' === $page ) {
            $tour_id  = '13112';
            $video_id = 'VZeUhuihddw';
        } elseif ( 'managesites' === $page ) {
            if ( isset( $_GET['do'] ) && 'new' === $_GET['do'] ) {
                $tour_id  = '13210';
                $video_id = 'k1-4x6h0LfE';
            } elseif ( isset( $_GET['do'] ) && 'bulknew' === $_GET['do'] ) {
                $tour_id = '60206';
            } elseif ( ! isset( $_GET['dashboard'] ) && ! isset( $_GET['id'] ) && ! isset( $_GET['updateid'] ) && ! isset( $_GET['emailsettingsid'] ) && ! isset( $_GET['scanid'] ) ) {
                if ( 'grid' === $siteViewMode ) {
                    $tour_id = '27217';
                } else {
                    $tour_id = '29331';
                }
            }
        } elseif ( 'MonitoringSites' === $page ) {
            $tour_id = '29003';
        } elseif ( 'ManageClients' === $page ) {
            if ( isset( $_GET['client_id'] ) ) {
                $tour_id = '28258';
            } else {
                $tour_id = '28240';
            }
        } elseif ( 'ClientAddNew' === $page ) {
            if ( isset( $_GET['client_id'] ) ) {
                $tour_id = '28962';
            } else {
                $tour_id = '28256';
            }
            $video_id = '8Th7bSZAHjw';
        } elseif ( 'ClientAddField' === $page ) {
            $tour_id = '28257';
        } elseif ( 'PluginsManage' === $page ) {
            $tour_id  = '28510';
            $video_id = 'p0pB7mXoIYw';
        } elseif ( 'ManageGroups' === $page ) {
            $tour_id = '27275';
        } elseif ( 'UpdatesManage' === $page ) {
            $video_id = 'igOn8wOBcAQ';
            $tab      = isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '';
            if ( 'plugins-updates' === $tab ) {
                $tour_id = '28259';
            } elseif ( 'themes-updates' === $tab ) {
                $tour_id = '28447';
            } elseif ( 'wordpress-updates' === $tab ) {
                $tour_id = '29005';
            } elseif ( 'translations-updates' === $tab ) {
                $tour_id = '29007';
            } elseif ( 'abandoned-plugins' === $tab ) {
                $tour_id = '29008';
            } elseif ( 'abandoned-themes' === $tab ) {
                $tour_id = '29009';
            } elseif ( 'plugin-db-updates' === $tab ) {
                $tour_id = '33161';
            } else {
                $tour_id = '28259';
            }
        } elseif ( 'PluginsInstall' === $page ) {
            $tour_id = '29011';
        } elseif ( 'PluginsAutoUpdate' === $page ) {
            $tour_id = '29015';
        } elseif ( 'PluginsIgnore' === $page ) {
            $tour_id = '29018';
        } elseif ( 'PluginsIgnoredAbandoned' === $page ) {
            $tour_id = '29329';
        } elseif ( 'ThemesManage' === $page ) {
            $tour_id  = '28511';
            $video_id = 'fWYBTWNL9gw';
        } elseif ( 'ThemesInstall' === $page ) {
            $tour_id = '29010';
        } elseif ( 'ThemesAutoUpdate' === $page ) {
            $tour_id = '29016';
        } elseif ( 'ThemesIgnore' === $page ) {
            $tour_id = '29019';
        } elseif ( 'ThemesIgnoredAbandoned' === $page ) {
            $tour_id = '29330';
        } elseif ( 'UserBulkManage' === $page ) {
            $tour_id  = '28574';
            $video_id = 'lvzpE04nsTs';
        } elseif ( 'UserBulkAdd' === $page ) {
            $tour_id = '28575';
        } elseif ( 'BulkImportUsers' === $page ) {
            $tour_id = '28736';
        } elseif ( 'UpdateAdminPasswords' === $page ) {
            $tour_id = '28737';
        } elseif ( 'PostBulkManage' === $page ) {
            $tour_id  = '28796';
            $video_id = 'Z81gzO16K8o';
        } elseif ( 'PostBulkAdd' === $page ) {
            $tour_id = '28799';
        } elseif ( 'PageBulkManage' === $page ) {
            $tour_id  = '29045';
            $video_id = 'O4jG-GRHE2Q';
        } elseif ( 'PageBulkAdd' === $page ) {
            $tour_id = '29048';
        } elseif ( 'Extensions' === $page ) {
            $tour_id  = '28800';
            $video_id = 'FMkXqpRu6-E';
        } elseif ( 'Settings' === $page ) {
            $tour_id = '28883';
        } elseif ( 'SettingsAdvanced' === $page ) {
            $tour_id = '28886';
        } elseif ( 'SettingsEmail' === $page ) {
            $tour_id = '29054';
        } elseif ( 'MainWPTools' === $page ) {
            $tour_id = '29272';
        } elseif ( 'RESTAPI' === $page ) {
            $tour_id  = '29273';
            $video_id = 'BO-u1FlptY0';
        } elseif ( 'ServerInformation' === $page ) {
            $tour_id = '28873';
        } elseif ( 'ServerInformationCron' === $page ) {
            $tour_id = '28874';
        } elseif ( 'ErrorLog' === $page ) {
            $tour_id = '28876';
        } elseif ( 'ActionLogs' === $page ) {
            $tour_id = '28877';
        } elseif ( 'ManageCostTracker' === $page ) {
            $video_id = 'Q1aVVGpkJAQ';
        } elseif ( 'CostTrackerAdd' === $page ) {
            $video_id = 'Q1aVVGpkJAQ';
        } elseif ( 'Extensions-Mainwp-Jetpack-Protect-Extension' === $page ) {
            $tour_id = '31700';
        } elseif ( 'Extensions-Mainwp-Jetpack-Scan-Extension' === $page ) {
            $tour_id = '31694';
        } elseif ( 'Extensions-Termageddon-For-Mainwp' === $page ) {
            $tour_id  = '32104';
            $video_id = 'HAHySipH22I';
        } elseif ( 'Extensions-Advanced-Uptime-Monitor-Extension' === $page ) {
            $tour_id  = '32149';
            $video_id = '3PIsyNM3OM0';
        } elseif ( 'Extensions-Mainwp-Custom-Dashboard-Extension' === $page ) {
            $tour_id  = '32150';
            $video_id = 'T6jzvD3ogfw';
        } elseif ( 'Extensions-Mainwp-Updraftplus-Extension' === $page ) {
            $tour_id = '32151';
        } elseif ( 'Extensions-Mainwp-Sucuri-Extension' === $page ) {
            $tour_id  = '32152';
            $video_id = 'bykz9YabuA8';
        } elseif ( 'Extensions-Mainwp-Clean-And-Lock-Extension' === $page ) {
            $tour_id  = '32153';
            $video_id = 'uCCDiPbqXUc';
        } elseif ( 'Extensions-Mainwp-Woocommerce-Shortcuts-Extension' === $page ) {
            $tour_id = '32851';
        } elseif ( 'Extensions-Mainwp-Buddy-Extension' === $page ) {
            $tour_id = '33064';
        } elseif ( 'Extensions-Mainwp-Backwpup-Extension' === $page ) {
            $tour_id = '32923';
        } elseif ( 'Extensions-Mainwp-Ssl-Monitor-Extension' === $page ) {
            $tour_id  = '33164';
            $video_id = 'HYb9xZ7Lxe0';
        } elseif ( 'Extensions-Mainwp-Cache-Control-Extension' === $page ) {
            $tour_id = '33167';
        } elseif ( 'Extensions-Mainwp-Maintenance-Extension' === $page ) {
            $tour_id = '33301';
        } elseif ( 'Extensions-Mainwp-Domain-Monitor-Extension' === $page ) {
            $tour_id  = '66031';
            $video_id = 'QMziJ1BxEcE';
        } elseif ( 'Extensions-Mainwp-Favorites-Extension' === $page ) {
            $tour_id  = '66035';
            $video_id = 'JRn7SLC4028';
        } elseif ( 'Extensions-Mainwp-Regression-Testing-Extension' === $page ) {
            $tour_id  = '66037';
            $video_id = 'rrynCqVxKSw';
        } elseif ( 'Extension-Mainwp-Google-Analytics-Extension' === $page ) {
            $video_id = 'BV0xTftH7as';
        } elseif ( 'Extensions-Mainwp-Code-Snippets-Extension' === $page ) {
            $video_id = 'Bl7rJzGmNU0';
        } elseif ( 'Extensions-Mainwp-Article-Uploader-Extension' === $page ) {
            $video_id = 'e8WoikPFyB0';
        } elseif ( 'Extensions-Mainwp-Branding-Extension' === $page ) {
            $video_id = 'kWvEVIpPEss';
        } elseif ( 'Extensions-Mainwp-Time-Tracker-Extension' === $page ) {
            $video_id = 'nvsKRp_rtiA';
        } elseif ( 'Extensions-Mainwp-Comments-Extension' === $page ) {
            $video_id = 'tCSX3z1KOOg';
        } elseif ( 'Extensions-Mainwp-Lighthouse-Extension' === $page ) {
            $video_id = 'GNYKJsoJso8';
        } elseif ( 'Extensions-Mainwp-Rocket-Extension' === $page ) {
            $video_id = 'qahq6cR7Svo';
        }

        $enable_guided_tours    = get_option( 'mainwp_enable_guided_tours', 0 );
        $enable_guided_chatbase = get_option( 'mainwp_enable_guided_chatbase', 0 );
        $enable_guided_video    = get_option( 'mainwp_enable_guided_video', 0 );

        $enabled_at_least_one = $enable_guided_tours || $enable_guided_chatbase || $enable_guided_video;

        ?>
        <div id="mainwp-help-modal" class="ui modal">
            <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Quick Help', 'mainwp' ); ?></div>
            <div class="content" id="mainwp-help-modal-consent-content" <?php echo $enabled_at_least_one ? 'style="display:none"' : ''; ?>>
                <div class="ui message">
                    <h3><?php esc_html_e( 'Privacy Notice', 'mainwp' ); ?></h3>
                    <p><?php printf( esc_html__( 'The "Quick Help" feature, including the guided tour and support agent, utilizes third-party technology and is subject to their respective privacy policies. Please review the privacy policies of %sUsetiful%s and %sChatbase%s and %sYoutube%s before proceeding.', 'mainwp' ), '<a href="https://www.usetiful.com/privacy-policy" target="_blank">', '</a>', '<a href="https://www.chatbase.co/legal/privacy" target="_blank">', '</a>', '<a href="https://www.youtube.com/" target="_blank">', '</a>' ); ?></p>
                    <p><?php esc_html_e( 'You can choose which features to enable below.', 'mainwp' ); ?></p>
                    <p><div class="ui toggle checkbox" id="mainwp-guided-tours-check">
                        <input type="checkbox" class="settings-field-value-change-handler" name="mainwp-guided-tours-option" id="mainwp-guided-tours-option" <?php echo 1 === (int) get_option( 'mainwp_enable_guided_tours', 0 ) ? 'checked="true"' : ''; ?> />
                        <label>
                        <?php
                            esc_html_e( 'Usetiful (Guides & Tips)', 'mainwp' );
                            printf( esc_html__( ' - Provides walkthroughs and tooltips to help you navigate MainWP. %sRead Privacy Police%s.', 'mainwp' ), '<a href="https://mainwp.com/mainwp-plugin-privacy-policy/" target="_blank">', '</a>' );
                        ?>
                        </label>
                    </div></p>
                    <p><div class="ui toggle checkbox" id="mainwp-guided-chatbase-check">
                        <input type="checkbox" class="settings-field-value-change-handler" name="mainwp-guided-chatbase-option" id="mainwp-guided-chatbase-option" <?php echo 1 === (int) get_option( 'mainwp_enable_guided_chatbase', 0 ) ? 'checked="true"' : ''; ?> />
                        <label>
                        <?php
                        esc_html_e( 'Chatbase (AI-Powered Chat Support)', 'mainwp' );
                        printf( esc_html__( ' - Allows AI-powered assistance for faster support. %sRead Privacy Police%s.', 'mainwp' ), '<a href="https://chatbotapp.ai/privacy/" target="_blank">', '</a>' );
                        ?>
                        </label>
                    </div></p>
                    <p><div class="ui toggle checkbox" id="mainwp-guided-video-check">
                        <input type="checkbox" class="settings-field-value-change-handler" name="mainwp-guided-video-option" id="mainwp-guided-video-option" <?php echo 1 === (int) get_option( 'mainwp_enable_guided_video', 0 ) ? 'checked="true"' : ''; ?> />
                        <label>
                        <?php
                        esc_html_e( 'Youtube Embeds (Video Tutorials)', 'mainwp' );
                        printf( esc_html__( ' - Enable embedded Youtube video for step-by-step tutorials. %sRead Privacy Police%s.', 'mainwp' ), '<a href="https://youtube.com/privacy/" target="_blank">', '</a>' );
                        ?>
                        </label>
                    </div></p>
                    <p><?php esc_html_e( 'By enabling an options, you consent to the respective service\'s privacy polices.', 'mainwp' ); ?></p>
                    <p><?php esc_html_e( 'Don\'t worry, you can always change this or revoke access in Tools (MainWP -> Settings -> Tools)', 'mainwp' ); ?></p>
                    <button class="ui mini green button" onclick="mainwp_help_modal_content_onclick();return false;"><?php esc_html_e( 'Acknowledge & Accept Terms', 'mainwp' ); ?></button>
                </div>
            </div>
            <div class="scrolling center aligned content" id="mainwp-help-modal-content" <?php echo $enabled_at_least_one ? '' : 'style="display:none"'; ?>>
                <div class="ui three cards" id="mainwp-help-modal-options">
                    <a class="ui card grey text" id="mainwp-start-chat-card" <?php echo $enable_guided_chatbase ? '' : 'style="display:none"'; ?> onclick="jQuery('#mainwp-help-back-button').fadeIn(200);mainwp_help_modal_start_content_onclick( false, false, true );return false;">
                        <div class="content">
                            <div class="header"><?php esc_html_e( 'Support Assistant', 'mainwp' ); ?></div>
                        </div>
                        <div class="content">
                            <i class="robot massive grey icon" style="opacity:0.3"></i>
                            <div class="ui hidden divider"></div>
                            <div><?php esc_html_e( 'Chat with our AI Support Assistant for quick guidance and troubleshooting. It\'s trained on MainWP documentation to help you find answers faster.', 'mainwp' ); ?> <span class="ui mini green label">BETA</span></div>
                        </div>
                    </a>
                    <a class="ui card grey text" id="mainwp-start-tour-card" <?php echo $enable_guided_tours ? '' : 'style="display:none"'; ?> onclick="jQuery('#mainwp-help-modal').modal('hide');mainwp_help_modal_start_content_onclick( <?php echo (int) $tour_id; ?> );" >
                        <div class="content">
                            <div class="header"><?php esc_html_e( 'Take a Quick Tour', 'mainwp' ); ?></div>
                        </div>
                        <div class="content">
                            <i class="map marked alternate massive grey icon" style="opacity:0.3"></i>
                            <div class="ui hidden divider"></div>
                            <div><?php esc_html_e( 'New here? Learn how to navigate and use key features with an interactive step-by-step tour.', 'mainwp' ); ?></div>
                        </div>
                    </a>
                    <a class="ui card grey text" id="mainwp-start-video-card" <?php echo $enable_guided_video && '' !== $video_id ? '' : 'style="display:none"'; ?> onclick="jQuery('#mainwp-help-back-button').fadeIn(200);mainwp_help_modal_start_content_onclick( false, '<?php echo esc_js( $video_id ); ?>', false );return false;" >
                        <div class="content">
                            <div class="header"><?php esc_html_e( 'MainWP 101 Video Tour', 'mainwp' ); ?></div>
                        </div>
                        <div class="content">
                            <i class="youtube massive grey icon" style="opacity:0.3"></i>
                            <div class="ui hidden divider"></div>
                            <div><?php esc_html_e( 'Discover the essential features of this page in our quick, step-by-step video guide.', 'mainwp' ); ?></div>
                        </div>
                    </a>
                </div>
                <div style="display:none" id="mainwp-chatbase-chat-screen">
                    <iframe src="" width="100%" style="height: 100%; min-height: 600px;border: none;" title="MainWP Support Assistant"></iframe>
                </div>
                <div style="display:none;position:relative;padding-bottom:56.25%;height:0;" id="mainwp-chatbase-video-screen">
                    <iframe width="420" height="315" src="" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;" title="MainWP Video Tutorials"></iframe>
                </div>
            </div>
            <div class="ui segment">
                <div class="ui grid">
                    <div class="eight wide middle aligned column"><a href="#" style="display:none" id="mainwp-help-back-button" class="ui mini basic button" onclick="jQuery('#mainwp-help-modal-options').fadeIn(200);jQuery('#mainwp-chatbase-video-screen').fadeOut(200);jQuery('#mainwp-chatbase-chat-screen').fadeOut(200);jQuery('#mainwp-help-back-button').fadeOut(200);return false;"><?php esc_html_e( 'Back to Quick Help options', 'mainwp' ); ?></a></div>
                    <div class="eight wide right aligned middle aligned column"><span id="revoke-third-party-perms" <?php echo $enabled_at_least_one ? '' : 'style="display:none"'; ?>><?php esc_html_e( '* Revoke third-party permissions in the Tools page.', 'mainwp' ); ?></span></div>
                </div>
            </div>
            
        </div>

        <?php
    }



    /**
     * Method render_screen_options()
     *
     * Render modal window for Page Settings.
     *
     * @param bool $setting_page Default: True. Widgets that you want to hide in the MainWP Overview page.
     *
     * @return void  Render modal window for Page Settings html.
     */
    public static function render_screen_options( $setting_page = true ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

                $default_widgets = array(
                    'overview'                        => esc_html__( 'Updates Overview', 'mainwp' ),
                    'recent_posts'                    => esc_html__( 'Recent Posts', 'mainwp' ),
                    'recent_pages'                    => esc_html__( 'Recent Pages', 'mainwp' ),
                    'plugins'                         => esc_html__( 'Plugins (Individual Site Overview page)', 'mainwp' ),
                    'themes'                          => esc_html__( 'Themes (Individual Site Overview page)', 'mainwp' ),
                    'connection_status'               => esc_html__( 'Connection Status', 'mainwp' ),
                    'security_issues'                 => esc_html__( 'Security Issues', 'mainwp' ),
                    'notes'                           => esc_html__( 'Notes (Individual Site Overview page)', 'mainwp' ),
                    'clients'                         => esc_html__( 'Clients', 'mainwp' ),
                    'child_site_info'                 => esc_html__( 'Child site info (Individual Site Overview page)', 'mainwp' ),
                    'client_info'                     => esc_html__( 'Client info (Individual Site Overview page)', 'mainwp' ),
                    'non_mainwp_changes'              => esc_html__( 'Sites Changes', 'mainwp' ),
                    'get-started'                     => esc_html__( 'Get Started with MainWP', 'mainwp' ),
                    'uptime_monitoring_status'        => esc_html__( 'Uptime Monitoring', 'mainwp' ),
                    'uptime_monitoring_response_time' => esc_html__( 'Uptime Monitoring (Individual Site Overview page)', 'mainwp' ),
                );

                if ( ! MainWP_Uptime_Monitoring_Edit::is_enable_global_monitoring() ) {
                    unset( $default_widgets['uptime_monitoring_status'] );
                    unset( $default_widgets['uptime_monitoring_response_time'] );
                }

                $custom_opts = apply_filters_deprecated( 'mainwp-widgets-screen-options', array( array() ), '4.0.7.2', 'mainwp_widgets_screen_options' );  // @deprecated Use 'mainwp_widgets_screen_options' instead. NOSONAR - not IP.

                /**
                 * Filter: mainwp_widgets_screen_options
                 *
                 * Filters available widgets on the Overview page allowing users to unsent unwanted widgets.
                 *
                 * @since 4.0
                 */
                $custom_opts = apply_filters( 'mainwp_widgets_screen_options', $custom_opts );

                if ( is_array( $custom_opts ) && ! empty( $custom_opts ) ) {
                    $default_widgets = array_merge( $default_widgets, $custom_opts );
                }

                $show_widgets = get_user_option( 'mainwp_settings_show_widgets' );

                if ( ! is_array( $show_widgets ) ) {
                    $show_widgets = array();
                }

                $sidebar_pages = array( 'ManageGroups', 'PostBulkManage', 'PostBulkAdd', 'PageBulkManage', 'PageBulkAdd', 'ThemesManage', 'ThemesInstall', 'ThemesAutoUpdate', 'PluginsManage', 'PluginsInstall', 'PluginsAutoUpdate', 'UserBulkManage', 'UserBulkAdd', 'UpdateAdminPasswords', 'Extensions' );
                $sidebar_pages = apply_filters( 'mainwp_sidbar_pages', $sidebar_pages ); // deprecated filter.
                $sidebar_pages = apply_filters( 'mainwp_sidebar_pages', $sidebar_pages );

                /**
                 * Action: mainwp_screen_options_modal_top
                 *
                 * Fires at the top of the Page Settings modal element.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_screen_options_modal_top' );
                $which_settings = 'overview_settings';
                ?>
                <?php if ( ! $setting_page ) : ?>
                    <?php if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $sidebar_pages ) ) : // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended ?>
                        <?php
                        $which_settings   = 'sidebar_settings';
                        $sidebarPosition  = (int) get_user_option( 'mainwp_sidebarPosition', 1 );
                        $manageGroupsPage = false;
                        if ( isset( $_GET['page'] ) && 'ManageGroups' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
                            $manageGroupsPage = true;
                        }
                        ?>
            <div class="ui grid field">
                <label tabindex="0" class="six wide column middle aligned"><?php echo $manageGroupsPage ? esc_html__( 'Tags menu position', 'mainwp' ) : esc_html__( 'Sidebar position', 'mainwp' ); ?></label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select if you want to show the element on left or right.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select name="mainwp_sidebarPosition" id="mainwp_sidebarPosition" class="ui dropdown">
                        <option value="1" <?php echo 1 === $sidebarPosition ? 'selected' : ''; ?>><?php esc_html_e( 'Right', 'mainwp' ); ?></option>
                        <option value="0" <?php echo 0 === $sidebarPosition ? 'selected' : ''; ?>><?php esc_html_e( 'Left', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        if ( isset( $_GET['page'] ) && ! in_array( $_GET['page'], $sidebar_pages ) ) : // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            $hide_up_everything = (int) get_option( 'mainwp_hide_update_everything' );
            ?>
        <div class="ui grid field settings-field-indicator-wrapper">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_hide_update_everything', $hide_up_everything );
            esc_html_e( 'Hide the Update Everything button', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the "Update Everything" button will be hidden in the Updates Overview widget.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                <input type="checkbox" class="settings-field-value-change-handler" name="hide_update_everything" <?php echo 1 === $hide_up_everything ? 'checked="true"' : ''; ?> />
            </div>
        </div>
            <?php
            $indi_val = 'all';
            foreach ( $default_widgets as $name => $title ) {
                if ( ! isset( $show_widgets[ $name ] ) || 1 === (int) $show_widgets[ $name ] ) {
                    continue;
                }
                $indi_val = '';
            }
            ?>
        <div class="ui grid field settings-field-indicator-wrapper" default-indi-value="all">
            <label class="six wide column">
            <?php
            if ( $setting_page ) {
                MainWP_Settings_Indicator::render_not_default_indicator( 'show_default_widgets', $indi_val );
            }
            esc_html_e( 'Show widgets', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column" <?php echo $setting_page ? 'data-tooltip="' . esc_attr__( 'Select widgets that you want to hide in the MainWP Overview page.', 'mainwp' ) . '"' : ''; ?> data-inverted="" data-position="top left">
                <ul class="mainwp_hide_wpmenu_checkboxes">
                    <?php
                    foreach ( $default_widgets as $name => $title ) {
                        $_selected = '';
                        if ( ! isset( $show_widgets[ $name ] ) || 1 === (int) $show_widgets[ $name ] ) {
                            $_selected = 'checked';
                        }
                        ?>
                        <li>
                            <div class="ui checkbox">
                                <input type="checkbox" class="settings-field-value-change-handler" id="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" name="mainwp_show_widgets[]" <?php echo esc_html( $_selected ); ?> value="<?php echo esc_attr( $name ); ?>">
                                <label for="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
                            </div>
                            <input type="hidden" name="mainwp_widgets_name[]" value="<?php echo esc_attr( $name ); ?>">
                        </li>
                        <?php
                    }
                    ?>
                </ul>
            </div>
        </div>
            <?php
        endif;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized.Recommended
        ?>
        <input type="hidden" name="reset_overview_which_settings" value="<?php echo esc_html( $which_settings ); ?>" />
        <?php
        /**
         * Action: mainwp_screen_options_modal_bottom
         *
         * Fires at the bottom of the Page Settings modal element.
         *
         * @since 4.1
         */
        do_action( 'mainwp_screen_options_modal_bottom' );
    }

    /**
     * Method render_select_mainwp_themes_modal()
     *
     * Render modal window for mainwp themes selection.
     *
     * @return void  Render modal window for themes selection.
     */
    public static function render_select_mainwp_themes_modal() {
        ?>
        <div class="ui modal" id="mainwp-select-mainwp-themes-modal">
        <i class="close icon"></i>
        <div class="header"><?php esc_html_e( 'Select MainWP Theme', 'mainwp' ); ?></div>
        <div class="content ui form">
            <div class="ui blue message">
                <div class=""><?php printf( esc_html__( 'Did you know you can create your custom theme? %1$sSee here how to do it%2$s!', '' ), '<a href="https://mainwp.com/kb/how-to-change-the-theme-for-mainwp/" target="_blank">', '</a>' ); // NOSONAR - noopener - open safe. ?></div>
            </div>
            <form method="POST" action="" name="mainwp_select_mainwp_themes_form" id="mainwp_select_mainwp_themes_form">
            <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <input type="hidden" name="wp_scr_options_nonce" value="<?php echo esc_attr( wp_create_nonce( 'MainWPSelectThemes' ) ); ?>" />
            <?php
            /**
             * Action: mainwp_select_themes_modal_top
             *
             * Fires at the top of the modal.
             *
             * @since 4.3
             */
            do_action( 'mainwp_select_themes_modal_top' );

            MainWP_Settings::get_instance()->render_select_custom_themes();

            /**
             * Action: mainwp_select_themes_modal_bottom
             *
             * Fires at the bottom of the modal.
             *
             * @since 4.3
             */
            do_action( 'mainwp_select_themes_modal_bottom' );
            ?>
        </div>
        <div class="actions">
            <div class="ui two columns grid">
                <div class="left aligned column">

                </div>
                <div class="ui right aligned column">
                <input type="submit" class="ui green button" id="submit-select-mainwp-themes" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                </div>
            </div>
        </div>
        </form>
    </div>
            <?php
    }

        /**
         * Method render_install_extensions_promo_modal()
         */
    public static function render_install_extensions_promo_modal() {
        $mainwp_api_key = MainWP_Api_Manager_Key::instance()->get_decrypt_master_api_key();
        ?>
        <div class="ui small modal" id="mainwp-install-extensions-promo-modal">
            <i class="close icon"></i>
        <?php if ( empty( $mainwp_api_key ) ) : ?>
                <div class="header"><?php esc_html_e( 'Get MainWP Pro', 'mainwp' ); ?></div>
                <div class="content">
                    <div class="ui header"><?php esc_html_e( 'With your MainWP Pro subscription, you get access to:', 'mainwp' ); ?></div>
                    <div class="ui bulleted list">
                        <div class="item"><?php esc_html_e( 'All 30+ Existing Premium Extensions', 'mainwp' ); ?></div>
                        <div class="item"><?php esc_html_e( 'All Future Extensions', 'mainwp' ); ?></div>
                        <div class="item"><?php esc_html_e( 'Critical Security & Performance Updates', 'mainwp' ); ?></div>
                        <div class="item"><?php esc_html_e( 'Priority Support with Subscription', 'mainwp' ); ?></div>
                        <div class="item"><?php esc_html_e( 'Manage Unlimited Websites', 'mainwp' ); ?></div>
                    </div>
                    <a href="https://mainwp.com/signup/?utm_campaign=Dashboard%20-%20Upgrade%20to%20Pro&utm_source=Dashboard&utm_medium=grey%20link%20modal&utm_term=get%20mainwp%20pro" class="ui big green button" target="_blank">Get MainWP Pro</a> <?php // NOSONAR - noopener - open safe. ?>
                </div>
            <?php else : ?>
                <div class="header"><?php esc_html_e( 'Install Extensions', 'mainwp' ); ?></div>
                <div class="content">
                <div class="ui header"><?php esc_html_e( 'Extension not activated', 'mainwp' ); ?></div>
                <div><?php esc_html_e( 'Go to the ', 'mainwp' ); ?><a href="admin.php?page=Extensions">MainWP > Extensions</a><?php esc_html_e( ' page to install and activate extensions', 'mainwp' ); ?></div>
                </div>
            <?php endif; ?>
        </div>
            <?php
    }

    /**
     * Method render_empty_element_placeholder()
     *
     * Renders the content for empty elements.
     *
     * @param string $placeholder Placelolder text.
     */
    public static function render_empty_element_placeholder( $placeholder = '' ) {
        ?>
        <div class="mainwp-empty-widget-placeholder">
            <img alt="<?php esc_attr_e( 'Nothing to show here, check back later!', 'mainwp' ); ?>" src="<?php echo esc_url( MAINWP_PLUGIN_URL ); ?>assets/images/mainwp-widget-placeholder.png" class="mainwp-no-results-placeholder"/>
            <?php if ( '' !== $placeholder ) : ?>
                <p><?php echo $placeholder; //phpcs:ignore -- requires escaped. ?></p>
            <?php else : ?>
                <p><?php echo esc_html__( 'Nothing to show here, check back later!', 'mainwp' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Method render_empty_page_placeholder()
     *
     * Renders the content for empty pages.
     *
     * @param string $title   Title text.
     * @param string $message Message text.
     * @param string $icon    Icon HTML markup.
     */
    public static function render_empty_page_placeholder( $title = '', $message = '', $icon = '' ) {
        ?>
        <div class="mainwp-empty-page-placeholder ui center aligned middle aligned very padded segment">
            <div class="ui hidden divider"></div>
            <h2 class="ui icon header">
                <?php if ( '' !== $icon ) : ?>
                    <?php echo $icon; //phpcs:ignore -- requires escaped. ?>
                <?php else : ?>
                    <i class="massive green check tada transition icon"></i>
                <?php endif; ?>
                <div class="content">
                    <?php if ( '' !== $title ) : ?>
                    <?php echo $title; //phpcs:ignore -- requires escaped. ?>
                    <?php else : ?>
                        <?php echo esc_html__( 'Nothing to show here!', 'mainwp' ); ?>
                    <?php endif; ?>
                    <?php if ( '' !== $message ) : ?>
                    <div class="sub header"><?php echo $message; //phpcs:ignore -- requires escaped. ?></div>
                    <?php else : ?>
                    <div class="sub header"><?php echo esc_html__( 'Check back later.', 'mainwp' ); ?></div>
                    <?php endif; ?>
                </div>
            </h2>
        </div>
        <?php
    }

    /**
     * Method render_modal_save_segment()
     *
     * Render modal window.
     *
     * @param string $name Model segment name.
     *
     * @return void
     */
    public static function render_modal_save_segment( $name = '' ) {
        ?>
        <div id="mainwp-common-filter-segment-modal" class="ui tiny modal">
            <i class="close icon" id="mainwp-common-filter-segment-cancel"></i>
            <div class="header"><?php esc_html_e( 'Save Segment', 'mainwp' ); ?></div>
            <div class="content" id="mainwp-common-filter-segment-content">
                <div id="mainwp-common-filter-edit-segment-status" class="ui message hidden"></div>
                <div id="mainwp-common-filter-segment-edit-fields" class="ui form">
                    <div class="field">
                        <label><?php esc_html_e( 'Enter the segment name', 'mainwp' ); ?></label>
                    </div>
                    <div class="field">
                        <input type="text" id="mainwp-common-filter-edit-segment-name" value=""/>
                    </div>
                </div>
                <div id="mainwp-common-filter-segment-select-fields" style="display:none;">
                    <div class="field">
                        <label><?php esc_html_e( 'Select a segment', 'mainwp' ); ?></label>
                    </div>
                    <div class="field">
                        <div id="mainwp-common-filter-segments-lists-wrapper"></div>
                    </div>
                </div>
            </div>
            <div class="actions">
                <div class="ui grid">
                    <div class="eight wide left aligned middle aligned column">
                        <input type="button" class="ui green button" id="mainwp-common-filter-edit-segment-save" value="<?php esc_attr_e( 'Save', 'mainwp' ); ?>"/>
                        <input type="button" class="ui green button" id="mainwp-common-filter-select-segment-choose-button" value="<?php esc_attr_e( 'Choose', 'mainwp' ); ?>" style="display:none;"/>
                        <input type="button" class="ui basic button" id="mainwp-common-filter-select-segment-delete-button" value="<?php esc_attr_e( 'Delete', 'mainwp' ); ?>" style="display:none;"/>
                    </div>
                    <div class="eight wide column">

                    </div>
                </div>
            </div>
            <input type="hidden" id="mainwp-common-filter-segments-model-name" value="<?php echo esc_attr( $name ); ?>" />
        </div>
        <?php
    }

    /**
     * Method render_modal_reconnect()
     *
     * Render modal window.
     *
     * @return void
     */
    public static function render_modal_reconnect() {
        ?>
        <div class="ui modal" id="mainwp-reconnect-site-with-user-passwd-modal">
            <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Reconnect Site', 'mainwp' ); ?></div>
            <div class="content">
                    <div class="ui message" id="mainwp-message-zone-reconnect" style="display:none;"></div>
                    <div class="ui grid field" >
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Administrator username', 'mainwp' ); ?></label>
                        <div class="ui ten wide fluid column" data-tooltip="<?php esc_attr_e( 'Enter the website Administrator username.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left fluid labeled input" tabindex="0">
                                <input type="text" id="mainwp_managesites_add_wpadmin" name="mainwp_managesites_add_wpadmin" value="" />
                            </div>
                        </div>
                    </div>
                    <div id="mainwp-administrator-password-field">
                        <input type="password" id="fake-disable-autofill" style="display:none;" name="fake-disable-autofill" />
                        <div class="ui grid field">
                            <label class="six wide column top aligned"><?php esc_html_e( 'Administrator password', 'mainwp' ); ?></label>
                            <div class="ui ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the website Administrator password.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui left fluid labeled input">
                                    <input type="password" id="mainwp_managesites_add_admin_pwd" name="mainwp_managesites_add_admin_pwd" autocomplete="one-time-code" autocorrect="off" autocapitalize="none" spellcheck="false" value="" />
                                </div>
                                <div class="ui hidden fitted divider"></div>
                                <span class="ui small text"><?php esc_html_e( 'Your password is never stored by your Dashboard and never sent to MainWP.com. Once this initial connection is complete, your MainWP Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected, just be sure not to delete the admin account, as this would disrupt the connection.', 'mainwp' ); ?></span>
                            </div>
                        </div>
                    </div>
            </div>
            <div class="actions">
                <input type="button" autofocus name="mainwp-popup-reconnect-site-btn" id="mainwp-popup-reconnect-site-btn" class="ui button green big focus" value="<?php esc_attr_e( 'Reconnect', 'mainwp' ); ?>" />
            </div>
        </div>
        <?php
    }

    /**
     * Method get_default_icons().
     *
     * @return array icons.
     */
    public static function get_default_icons() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - compatible phpcs's line breaks.
        return array(
            'wordpress', //phpcs:ignore -- WP icon.
            'ambulance',
            'anchor',
            'archive',
            'award',
            'baby carriage',
            'balance scale',
            'balance scale left',
            'balance scale right',
            'bath',
            'bed',
            'beer',
            'bell',
            'bell outline',
            'bicycle',
            'binoculars',
            'birthday cake',
            'blender',
            'bomb',
            'book',
            'book dead',
            'bookmark',
            'bookmark outline',
            'briefcase',
            'broadcast tower',
            'bug',
            'building',
            'building outline',
            'bullhorn',
            'bullseye',
            'bus',
            'calculator',
            'calendar',
            'calendar alternate',
            'calendar alternate outline',
            'calendar outline',
            'camera',
            'camera retro',
            'candy cane',
            'car',
            'carrot',
            'church',
            'clipboard',
            'clipboard outline',
            'cloud',
            'coffee',
            'cog',
            'cogs',
            'compass',
            'compass outline',
            'cookie',
            'cookie bite',
            'copy',
            'copy outline',
            'cube',
            'cubes',
            'cut',
            'dice',
            'dice d20',
            'dice d6',
            'dice five',
            'dice four',
            'dice one',
            'dice six',
            'dice three',
            'dice two',
            'digital tachograph',
            'door closed',
            'door open',
            'drum',
            'drum steelpan',
            'envelope',
            'envelope open',
            'envelope open outline',
            'envelope outline',
            'eraser',
            'eye',
            'eye dropper',
            'eye outline',
            'fax',
            'feather',
            'feather alternate',
            'fighter jet',
            'file',
            'file alternate',
            'file alternate outline',
            'file outline',
            'file prescription',
            'film',
            'fire',
            'fire alternate',
            'fire extinguisher',
            'flag',
            'flag checkered',
            'flag outline',
            'flask',
            'futbol',
            'futbol outline',
            'gamepad',
            'gavel',
            'gem',
            'gem outline',
            'gift',
            'gifts',
            'glass cheers',
            'glass martini',
            'glass whiskey',
            'glasses',
            'globe',
            'graduation cap',
            'guitar',
            'hat wizard',
            'hdd',
            'hdd outline',
            'headphones',
            'headphones alternate',
            'headset',
            'heart',
            'heart broken',
            'heart outline',
            'helicopter',
            'highlighter',
            'holly berry',
            'home',
            'hospital',
            'hospital outline',
            'hourglass',
            'hourglass outline',
            'igloo',
            'image',
            'image outline',
            'images',
            'images outline',
            'industry',
            'key',
            'keyboard',
            'keyboard outline',
            'laptop',
            'leaf',
            'lemon',
            'lemon outline',
            'life ring',
            'life ring outline',
            'lightbulb',
            'lightbulb outline',
            'lock',
            'lock open',
            'magic',
            'magnet',
            'map',
            'map marker',
            'map marker alternate',
            'map outline',
            'map pin',
            'map signs',
            'marker',
            'medal',
            'medkit',
            'memory',
            'microchip',
            'microphone',
            'microphone alternate',
            'mitten',
            'mobile',
            'mobile alternate',
            'money bill',
            'money bill alternate',
            'money bill alternate outline',
            'money check',
            'money check alternate',
            'moon',
            'moon outline',
            'motorcycle',
            'mug hot',
            'newspaper',
            'newspaper outline',
            'paint brush',
            'paper plane',
            'paper plane outline',
            'paperclip',
            'paste',
            'paw',
            'pen',
            'pen alternate',
            'pen fancy',
            'pen nib',
            'pencil alternate',
            'phone',
            'phone alternate',
            'plane',
            'plug',
            'print',
            'puzzle piece',
            'ring',
            'road',
            'rocket',
            'ruler combined',
            'ruler horizontal',
            'ruler vertical',
            'satellite',
            'satellite dish',
            'save',
            'save outline',
            'school',
            'screwdriver',
            'scroll',
            'sd card',
            'search',
            'shield alternate',
            'shopping bag',
            'shopping basket',
            'shopping cart',
            'shower',
            'sim card',
            'skull crossbones',
            'sleigh',
            'snowflake',
            'snowflake outline',
            'snowplow',
            'space shuttle',
            'star',
            'star outline',
            'sticky note',
            'sticky note outline',
            'stopwatch',
            'stroopwafel',
            'subway',
            'suitcase',
            'sun',
            'sun outline',
            'tablet',
            'tablet alternate',
            'tachometer alternate',
            'tag',
            'tags',
            'taxi',
            'thumbtack',
            'ticket alternate',
            'toilet',
            'toolbox',
            'tools',
            'train',
            'tram',
            'trash',
            'trash alternate',
            'trash alternate outline',
            'tree',
            'trophy',
            'truck',
            'tv',
            'umbrella',
            'university',
            'unlock',
            'unlock alternate',
            'utensil spoon',
            'utensils',
            'wallet',
            'weight',
            'wheelchair',
            'wine glass',
            'wrench',
            'folder',
            'folder open',
            'palette',
            'server',
            'tint',
        );
    }
}
