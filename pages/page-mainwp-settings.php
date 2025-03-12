<?php
/**
 * MainWP Settings page
 *
 * This Class handles building/Managing the
 * Settings MainWP DashboardPage & all SubPages.
 *
 * @package MainWP/Settings
 */

namespace MainWP\Dashboard;

use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Settings;

/**
 * Class MainWP_Settings
 *
 * @package MainWP\Dashboard
 */
class MainWP_Settings { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Public static varable to hold Subpages information.
     *
     * @var array $subPages
     */
    public static $subPages;

    /**
     * Get Class Name
     *
     * @return __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Return the single instance of the class.
     *
     * @return mixed $instance The single instance of the class.
     */
    public static function get_instance() {
        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /** Instantiate Hooks for the Settings Page. */
    public static function init() {
        /**
         * This hook allows you to render the Settings page header via the 'mainwp_pageheader_settings' action.
         *
         * This hook is normally used in the same context of 'mainwp_getsubpages_settings'
         *
         * @see \MainWP_Settings::render_header
         */
        add_action( 'mainwp-pageheader-settings', array( static::get_class_name(), 'render_header' ) );  // deprecated, use mainwp_pageheader_settings.

        add_action( 'mainwp_pageheader_settings', array( static::get_class_name(), 'render_header' ) );

        /**
         * This hook allows you to render the Settings page footer via the 'mainwp-pagefooter-settings' action.
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-settings'
         *
         * @see \MainWP_Settings::render_footer
         */
        add_action( 'mainwp-pagefooter-settings', array( static::get_class_name(), 'render_footer' ) ); // deprecated, use mainwp_pagefooter_settings.

        add_action( 'mainwp_pagefooter_settings', array( static::get_class_name(), 'render_footer' ) );

        add_action( 'admin_init', array( static::get_class_name(), 'admin_init' ) );

        add_action( 'mainwp_help_sidebar_content', array( static::get_class_name(), 'mainwp_help_content' ) );
    }

    /** Run the export_sites method that exports the Child Sites .csv file */
    public static function admin_init() { // phpcs:ignore -- NOSONAR - complex.
        static::export_sites();
        if ( isset( $_GET['clearActivationData'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'clear_activation_data' ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            delete_option( 'mainwp_extensions_api_username' );
            delete_option( 'mainwp_extensions_api_password' );
            delete_option( 'mainwp_extensions_api_save_login' );
            delete_option( 'mainwp_extensions_plan_info' );
            MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_extensions_master_api_key', false );

            $new_extensions = array();
            $extensions     = get_option( 'mainwp_extensions', array() );

            if ( is_array( $extensions ) ) {
                foreach ( $extensions as $ext ) {
                    if ( isset( $ext['api'] ) && isset( $ext['apiManager'] ) && ! empty( $ext['apiManager'] ) ) {
                        if ( isset( $ext['api_key'] ) ) {
                            $ext['api_key'] = '';
                        }
                        if ( isset( $ext['activation_email'] ) ) {
                            $ext['activation_email'] = '';
                        }
                        if ( isset( $ext['activated_key'] ) ) {
                            $ext['activated_key'] = 'Deactivated';
                        }

                        $act_info = MainWP_Api_Manager::instance()->get_activation_info( $ext['api'] );
                        if ( isset( $act_info['api_key'] ) ) {
                            $act_info['api_key'] = '';
                        }
                        if ( isset( $act_info['activation_email'] ) ) {
                            $act_info['activation_email'] = '';
                        }
                        if ( isset( $act_info['activated_key'] ) ) {
                            $act_info['activated_key'] = 'Deactivated';
                        }
                        MainWP_Api_Manager::instance()->set_activation_info( $ext['api'], $act_info );
                    }
                    $new_extensions[] = $ext;
                }
            }

            MainWP_Utility::update_option( 'mainwp_extensions', $new_extensions );
            update_option( 'mainwp_extensions_all_activation_cached', '' );
            wp_safe_redirect( esc_url( admin_url( 'admin.php?page=MainWPTools' ) ) );
            die();
        }
    }

    /**
     * Instantiate the Settings Menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_menu() {
        add_submenu_page(
            'mainwp_tab',
            __( 'Settings Global options', 'mainwp' ),
            ' <span id="mainwp-Settings">' . esc_html__( 'Settings', 'mainwp' ) . '</span>',
            'read',
            'Settings',
            array(
                static::get_class_name(),
                'render',
            )
        );

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'MainWPTools' ) ) {
            add_submenu_page(
                'mainwp_tab',
                __( 'Tools', 'mainwp' ),
                ' <div class="mainwp-hidden">' . esc_html__( 'Tools', 'mainwp' ) . '</div>',
                'read',
                'MainWPTools',
                array(
                    static::get_class_name(),
                    'render_mainwp_tools',
                )
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'CostTrackerSettings' ) ) {
            add_submenu_page(
                'mainwp_tab',
                esc_html__( 'Cost Tracker', 'mainwp' ),
                '<div class="mainwp-hidden">' . esc_html__( 'Cost Tracker', 'mainwp' ) . '</div>',
                'read',
                'CostTrackerSettings',
                array(
                    Cost_Tracker_Settings::get_instance(),
                    'render_settings_page',
                )
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'SettingsAdvanced' ) ) {
            add_submenu_page(
                'mainwp_tab',
                __( 'Advanced Options', 'mainwp' ),
                ' <div class="mainwp-hidden">' . esc_html__( 'Advanced Options', 'mainwp' ) . '</div>',
                'read',
                'SettingsAdvanced',
                array(
                    static::get_class_name(),
                    'render_advanced',
                )
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'SettingsEmail' ) ) {
            add_submenu_page(
                'mainwp_tab',
                __( 'Email Settings', 'mainwp' ),
                ' <div class="mainwp-hidden">' . esc_html__( 'Email Settings', 'mainwp' ) . '</div>',
                'read',
                'SettingsEmail',
                array(
                    static::get_class_name(),
                    'render_email_settings',
                )
            );
        }

        /**
         * Settings Subpages
         *
         * Filters subpages for the Settings page.
         *
         * @since Unknown
         */
        $sub_pages        = apply_filters_deprecated( 'mainwp-getsubpages-settings', array( array() ), '4.0.7.2', 'mainwp_getsubpages_settings' );  // @deprecated Use 'mainwp_getsubpages_settings' instead. NOSONAR - not IP.
        static::$subPages = apply_filters( 'mainwp_getsubpages_settings', $sub_pages );

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'Settings' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Settings' . $subPage['slug'], $subPage['callback'] );
            }
        }
    }

    /**
     * Instantiate Settings SubPages Menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_subpages_menu() {
        ?>
        <div id="menu-mainwp-Settings" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=Settings' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'General Settings', 'mainwp' ); ?></a>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'SettingsAdvanced' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=SettingsAdvanced' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Advanced Settings', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'SettingsEmail' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=SettingsEmail' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Email Settings', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'CostTrackerSettings' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=CostTrackerSettings' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Cost Tracker', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php
                    if ( isset( static::$subPages ) && is_array( static::$subPages ) && ! empty( static::$subPages ) ) {
                        foreach ( static::$subPages as $subPage ) {
                            if ( MainWP_Menu::is_disable_menu_item( 3, 'Settings' . $subPage['slug'] ) ) {
                                continue;
                            }
                            ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=Settings' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo isset( $subPage['before_title'] ) ? $subPage['before_title'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $subPage['title'] ); ?></a>
                            <?php
                        }
                    }
                    ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'MainWPTools' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=MainWPTools' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Tools', 'mainwp' ); ?></a>
                    <?php } ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Instantiate left menu
     *
     * Settings Page & SubPage link data.
     *
     * @param array $subPages SubPages Array.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_left_menu( $subPages = array() ) {
        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'Settings', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'Settings',
                'href'       => 'admin.php?page=Settings',
                'icon'       => '<i class="cog icon"></i>',
            ),
            0
        );

        $init_sub_subleftmenu = array(
            array(
                'title'      => esc_html__( 'General Settings', 'mainwp' ),
                'parent_key' => 'Settings',
                'href'       => 'admin.php?page=Settings',
                'slug'       => 'Settings',
                'right'      => '',
            ),
            array(
                'title'      => esc_html__( 'Advanced Settings', 'mainwp' ),
                'parent_key' => 'Settings',
                'href'       => 'admin.php?page=SettingsAdvanced',
                'slug'       => 'SettingsAdvanced',
                'right'      => '',
            ),
            array(
                'title'      => esc_html__( 'Email Settings', 'mainwp' ),
                'parent_key' => 'Settings',
                'href'       => 'admin.php?page=SettingsEmail',
                'slug'       => 'SettingsEmail',
                'right'      => '',
            ),
            array(
                'title'      => esc_html__( 'Tools', 'mainwp' ),
                'parent_key' => 'Settings',
                'href'       => 'admin.php?page=MainWPTools',
                'slug'       => 'MainWPTools',
                'right'      => '',
            ),
            array(
                'title'      => esc_html__( 'Cost Tracker', 'mainwp' ),
                'parent_key' => 'Settings',
                'href'       => 'admin.php?page=CostTrackerSettings',
                'slug'       => 'CostTrackerSettings',
                'right'      => '',
            ),
        );

        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'Settings', 'Settings' );
        foreach ( $init_sub_subleftmenu as $item ) {
            if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
                continue;
            }

            MainWP_Menu::add_left_menu( $item, 2 );
        }
    }

    /**
     * Render Page Header.
     *
     * @param string $shownPage The page slug shown at this moment.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
     */
    public static function render_header( $shownPage = '' ) { // phpcs:ignore -- NOSONAR - complex.

        $params = array(
            'title' => esc_html__( 'MainWP Settings', 'mainwp' ),
        );

        MainWP_UI::render_top_header( $params );

        $renderItems = array();

        $renderItems[] = array(
            'title'  => esc_html__( 'General Settings', 'mainwp' ),
            'href'   => 'admin.php?page=Settings',
            'active' => ( '' === $shownPage ) ? true : false,
        );

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'SettingsAdvanced' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Advanced Settings', 'mainwp' ),
                'href'   => 'admin.php?page=SettingsAdvanced',
                'active' => ( 'Advanced' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'SettingsEmail' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Email Settings', 'mainwp' ),
                'href'   => 'admin.php?page=SettingsEmail',
                'active' => ( 'Emails' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'CostTrackerSettings' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Cost Tracker', 'mainwp' ),
                'href'   => 'admin.php?page=CostTrackerSettings',
                'active' => ( 'CostTrackerSettings' === $shownPage ) ? true : false,
            );
        }

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'Settings' . $subPage['slug'] ) ) {
                    continue;
                }
                $item           = array();
                $item['title']  = $subPage['title'];
                $item['href']   = 'admin.php?page=Settings' . $subPage['slug'];
                $item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
                if ( ! empty( $subPage['before_title'] ) ) {
                    $item['before_title'] = $subPage['before_title'];
                }

                if ( isset( $subPage['class'] ) ) {
                    $item['class'] = $subPage['class'];
                }
                $renderItems[] = $item;
            }
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'MainWPTools' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Tools', 'mainwp' ),
                'href'   => 'admin.php?page=MainWPTools',
                'active' => ( 'MainWPTools' === $shownPage ) ? true : false,
            );
        }

        MainWP_UI::render_page_navigation( $renderItems );
    }

    /**
     * Close the HTML container.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Method handle_settings_post().
     *
     * This class handles the $_POST of Settings Options.
     *
     * @uses MainWP_DB::instance()
     * @uses MainWP_Utility::update_option()
     *
     * @return boolean True|False Posts On True.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
     * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public static function handle_settings_post() { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'Settings' ) ) {
            $userExtension            = MainWP_DB_Common::instance()->get_user_extension();
            $userExtension->pluginDir = '';

            MainWP_DB_Common::instance()->update_user_extension( $userExtension );
            if ( MainWP_System_Utility::is_admin() ) {

                /**
                * Action: mainwp_before_save_general_settings
                *
                * Fires before general settings save.
                *
                * @since 4.1
                */
                do_action( 'mainwp_before_save_general_settings', $_POST );

                $val = ( ! isset( $_POST['mainwp_pluginAutomaticDailyUpdate'] ) ? 0 : intval( $_POST['mainwp_pluginAutomaticDailyUpdate'] ) );
                MainWP_Utility::update_option( 'mainwp_pluginAutomaticDailyUpdate', $val );
                $val = ( ! isset( $_POST['mainwp_themeAutomaticDailyUpdate'] ) ? 0 : intval( $_POST['mainwp_themeAutomaticDailyUpdate'] ) );
                MainWP_Utility::update_option( 'mainwp_themeAutomaticDailyUpdate', $val );
                $val = ( ! isset( $_POST['mainwp_transAutomaticDailyUpdate'] ) ? 0 : intval( $_POST['mainwp_transAutomaticDailyUpdate'] ) );
                MainWP_Utility::update_option( 'mainwp_transAutomaticDailyUpdate', $val );
                $val = ( ! isset( $_POST['mainwp_automaticDailyUpdate'] ) ? 0 : intval( $_POST['mainwp_automaticDailyUpdate'] ) );
                MainWP_Utility::update_option( 'mainwp_automaticDailyUpdate', $val );
                $val = ( ! isset( $_POST['mainwp_show_language_updates'] ) ? 0 : 1 );
                MainWP_Utility::update_option( 'mainwp_show_language_updates', $val );
                $val = ( ! isset( $_POST['mainwp_disable_update_confirmations'] ) ? 0 : intval( $_POST['mainwp_disable_update_confirmations'] ) );
                MainWP_Utility::update_option( 'mainwp_disable_update_confirmations', $val );
                $val = ( ! isset( $_POST['mainwp_backup_before_upgrade'] ) ? 0 : 1 );
                MainWP_Utility::update_option( 'mainwp_backup_before_upgrade', $val );
                $val = ( ! isset( $_POST['mainwp_backup_before_upgrade_days'] ) ? 7 : intval( $_POST['mainwp_backup_before_upgrade_days'] ) );
                MainWP_Utility::update_option( 'mainwp_backup_before_upgrade_days', $val );

                if ( is_plugin_active( 'mainwp-comments-extension/mainwp-comments-extension.php' ) ) {
                    MainWP_Utility::update_option( 'mainwp_maximumComments', isset( $_POST['mainwp_maximumComments'] ) ? intval( $_POST['mainwp_maximumComments'] ) : 50 );
                }

                $current_timeDailyUpdate = get_option( 'mainwp_timeDailyUpdate' );
                $new_timeDailyUpdate     = isset( $_POST['mainwp_timeDailyUpdate'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_timeDailyUpdate'] ) ) : '';

                if ( $current_timeDailyUpdate !== $new_timeDailyUpdate ) {
                    MainWP_Utility::update_option( 'mainwp_timeDailyUpdate', $new_timeDailyUpdate );
                }

                $old_freq = (int) get_option( 'mainwp_frequencyDailyUpdate', 2 );
                $new_freq = ( isset( $_POST['mainwp_frequencyDailyUpdate'] ) ? intval( $_POST['mainwp_frequencyDailyUpdate'] ) : 2 );
                if ( $old_freq !== $new_freq ) {
                    MainWP_Utility::update_option( 'mainwp_frequencyDailyUpdate', $new_freq );
                    MainWP_Logger::instance()->log_update_check( 'New frequency daily :: ' . $new_freq );
                }

                $curr_frequency_updates = get_option( 'mainwp_frequency_AutoUpdate', 'daily' );
                $new_frequency_updates  = isset( $_POST['mainwp_frequency_AutoUpdate'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_frequency_AutoUpdate'] ) ) : 'daily';
                if ( $new_frequency_updates !== $curr_frequency_updates ) {
                    MainWP_Utility::update_option( 'mainwp_frequency_AutoUpdate', $new_frequency_updates );
                }

                $curr_day_updates = (int) get_option( 'mainwp_dayinweek_AutoUpdate', 0 );
                $new_day_updates  = isset( $_POST['mainwp_dayinweek_AutoUpdate'] ) ? intval( wp_unslash( $_POST['mainwp_dayinweek_AutoUpdate'] ) ) : 0;
                if ( $new_day_updates !== $curr_day_updates ) {
                    MainWP_Utility::update_option( 'mainwp_dayinweek_AutoUpdate', $new_day_updates );
                }

                $curr_dayinmonth_updates = (int) get_option( 'mainwp_dayinmonth_AutoUpdate', 1 );
                $new_dayinmonth_updates  = isset( $_POST['mainwp_dayinmonth_AutoUpdate'] ) ? intval( wp_unslash( $_POST['mainwp_dayinmonth_AutoUpdate'] ) ) : 1;
                if ( $new_dayinmonth_updates !== $curr_dayinmonth_updates ) {
                    MainWP_Utility::update_option( 'mainwp_dayinmonth_AutoUpdate', $new_dayinmonth_updates );
                }

                $curr_time_updates = get_option( 'mainwp_time_AutoUpdate', '00:00' );
                $new_time_updates  = isset( $_POST['mainwp_time_AutoUpdate'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_time_AutoUpdate'] ) ) : '00:00';
                if ( $new_time_updates !== $curr_time_updates ) {
                    MainWP_Utility::update_option( 'mainwp_time_AutoUpdate', $new_time_updates );
                }

                MainWP_Cron_Jobs_Auto_Updates::set_next_auto_updates_time();

                $new_delay = ( isset( $_POST['mainwp_delay_autoupdate'] ) ? intval( $_POST['mainwp_delay_autoupdate'] ) : 1 );
                MainWP_Utility::update_option( 'mainwp_delay_autoupdate', $new_delay );

                $val  = ( isset( $_POST['mainwp_sidebarPosition'] ) ? intval( $_POST['mainwp_sidebarPosition'] ) : 1 );
                $user = wp_get_current_user();
                if ( $user ) {
                    update_user_option( $user->ID, 'mainwp_sidebarPosition', $val, true );
                }

                MainWP_Utility::update_option( 'mainwp_numberdays_Outdate_Plugin_Theme', ! empty( $_POST['mainwp_numberdays_Outdate_Plugin_Theme'] ) ? intval( $_POST['mainwp_numberdays_Outdate_Plugin_Theme'] ) : 365 );

                $check_http_response = ( isset( $_POST['mainwp_check_http_response'] ) ? 1 : 0 );
                MainWP_Utility::update_option( 'mainwp_check_http_response', $check_http_response );

                $actions_notification_enable = ( isset( $_POST['mainwp_site_actions_notification_enable'] ) ? 1 : 0 );
                MainWP_Utility::update_option( 'mainwp_site_actions_notification_enable', $actions_notification_enable );

                //phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                // Handle custom date/time formats.
                if ( ! empty( $_POST['date_format'] ) && isset( $_POST['date_format_custom'] )
                    && '\c\u\s\t\o\m' === wp_unslash( $_POST['date_format'] )
                ) {
                    $_POST['date_format'] = wp_unslash( $_POST['date_format_custom'] );
                }

                if ( ! empty( $_POST['time_format'] ) && isset( $_POST['time_format_custom'] )
                    && '\c\u\s\t\o\m' === wp_unslash( $_POST['time_format'] )
                ) {
                    $_POST['time_format'] = wp_unslash( $_POST['time_format_custom'] );
                }

                if ( isset( $_POST['timezone_string'] ) ) {
                    // Map UTC+- timezones to gmt_offsets and set timezone_string to empty.
                    if ( ! empty( $_POST['timezone_string'] ) && preg_match( '/^UTC[+-]/', wp_unslash( $_POST['timezone_string'] ) ) ) {
                        $_POST['gmt_offset']      = wp_unslash( $_POST['timezone_string'] );
                        $_POST['gmt_offset']      = preg_replace( '/UTC\+?/', '', wp_unslash( $_POST['gmt_offset'] ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
                        $_POST['timezone_string'] = '';
                    }

                    $options = array(
                        'gmt_offset',
                        'date_format',
                        'time_format',
                        'timezone_string',
                    );

                    foreach ( $options as $option ) {
                        $value = null;
                        if ( isset( $_POST[ $option ] ) ) {
                            $value = wp_unslash( $_POST[ $option ] );
                            if ( ! is_array( $value ) ) {
                                $value = trim( $value );
                            }
                        }
                        update_option( $option, $value );
                    }
                }
                //phpcs:enable

                MainWP_Utility::update_option( 'mainwp_use_favicon', 1 );

                /**
                * Action: mainwp_after_save_general_settings
                *
                * Fires after save general settings.
                *
                * @since 4.1
                */
                do_action( 'mainwp_after_save_general_settings', $_POST );
            }

            return true;
        }

        return false;
    }

    /**
     * Render the MainWP Settings Page.
     *
     * @uses \MainWP\Dashboard\MainWP_Monitoring_View
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups::render_settings()
     * @uses \MainWP\Dashboard\MainWP_Utility::get_http_codes()
     */
    public static function render() { //phpcs:ignore -- NOSONAR - complex method.
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage dashboard settings', 'mainwp' ) );
            return;
        }

        static::render_header( '' );
        ?>
        <div id="mainwp-general-settings" class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-general-settings-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-general-settings-info-message"></i>
                    <?php printf( esc_html__( 'Manage MainWP general settings.  For additional help, review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/mainwp-dashboard-settings/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                </div>
            <?php endif; ?>
                <?php if ( isset( $_GET['message'] ) && 'saved' === $_GET['message'] ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
                    <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
                <?php endif; ?>
                <div class="ui form">
                    <form method="POST" action="admin.php?page=Settings" id="mainwp-settings-page-form">
                        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'Settings' ) ); ?>" />
                        <?php
                        /**
                         * Action: mainwp_settings_form_top
                         *
                         * Fires at the top of settings form.
                         *
                         * @since 4.1
                         */
                        do_action( 'mainwp_settings_form_top' );
                        ?>
                        <h3 class="ui dividing header">
                        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-general' ); ?>
                        <?php esc_html_e( 'General Settings', 'mainwp' ); ?></h3>
                        <?php
                        $timeDailyUpdate      = get_option( 'mainwp_timeDailyUpdate' );
                        $frequencyDailyUpdate = (int) get_option( 'mainwp_frequencyDailyUpdate', 2 );
                        $run_timestamp        = MainWP_System_Cron_Jobs::get_timestamp_from_hh_mm( $timeDailyUpdate );
                        $delay_autoupdate     = (int) get_option( 'mainwp_delay_autoupdate', 1 );

                        ?>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_timeDailyUpdate', $timeDailyUpdate );
                            esc_html_e( 'Daily sync time', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Choose a specific time to initiate the first daily synchronization process.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="time-selector">
                                    <div class="ui input left icon">
                                        <i class="clock icon"></i>
                                        <input type="text" class="settings-field-value-change-handler" current-utc-datetime="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s' ) ); ?>" sync-time-local-datetime="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', $run_timestamp ) ); ?>" local-datetime="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', MainWP_Utility::get_timestamp() ) ); // phpcs:ignore -- to get local time. ?>" name="mainwp_timeDailyUpdate" id="mainwp_timeDailyUpdate" value="<?php echo esc_attr( $timeDailyUpdate ); ?>" />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general" default-indi-value="2">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_frequencyDailyUpdate', $frequencyDailyUpdate );
                            esc_html_e( 'Frequency of auto sync', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set the frequency for automatic synchronization throughout the day.', 'mainwp' ); ?>" data-inverted="" data-position="top left" >
                                <select name="mainwp_frequencyDailyUpdate" id="mainwp_frequencyDailyUpdate" class="ui dropdown settings-field-value-change-handler">
                                    <option value="1" <?php echo 1 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Once per day', 'mainwp' ); ?></option>
                                    <option value="2" <?php echo 2 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Twice per day', 'mainwp' ); ?></option>
                                    <option value="3" <?php echo 3 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Three times per day', 'mainwp' ); ?></option>
                                    <option value="4" <?php echo 4 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Four times per day', 'mainwp' ); ?></option>
                                    <option value="5" <?php echo 5 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Five times per day', 'mainwp' ); ?></option>
                                    <option value="6" <?php echo 6 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Six times per day', 'mainwp' ); ?></option>
                                    <option value="7" <?php echo 7 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Seven times per day', 'mainwp' ); ?></option>
                                    <option value="8" <?php echo 8 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Eight times per day', 'mainwp' ); ?></option>
                                    <option value="9" <?php echo 9 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Nine times per day', 'mainwp' ); ?></option>
                                    <option value="10" <?php echo 10 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Ten times per day', 'mainwp' ); ?></option>
                                    <option value="11" <?php echo 11 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Eleven times per day', 'mainwp' ); ?></option>
                                    <option value="12" <?php echo 12 === $frequencyDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Twelve times per day', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <script type="text/javascript">
                            jQuery( document ).ready( function() {
                                jQuery( '.time-selector' ).calendar( {
                                    type: 'time',
                                    ampm: false,
                                    formatter: {
                                        time: 'H:mm',
                                        cellTime: 'H:mm'
                                    }
                                } );
                            } );
                        </script>

                        <?php

                        static::render_timezone_settings();
                        static::render_datetime_settings();

                        $sidebarPosition = get_user_option( 'mainwp_sidebarPosition' );
                        if ( false === $sidebarPosition ) {
                            $sidebarPosition = 1;
                        }

                        ?>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_sidebarPosition', (int) $sidebarPosition );
                            esc_html_e( 'Sidebar position', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select if you want to show sidebar with option on left or right.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <select name="mainwp_sidebarPosition" id="mainwp_sidebarPosition" class="ui dropdown settings-field-value-change-handler">
                                    <option value="1" <?php echo 1 === (int) $sidebarPosition ? 'selected' : ''; ?>><?php esc_html_e( 'Right (default)', 'mainwp' ); ?></option>
                                    <option value="0" <?php echo 0 === (int) $sidebarPosition ? 'selected' : ''; ?>><?php esc_html_e( 'Left', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <?php MainWP_UI::render_screen_options(); ?>

                        <h3 class="ui dividing header">
                        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-updates' ); ?>
                        <?php esc_html_e( 'Updates Settings', 'mainwp' ); ?></h3>
                        <?php
                        $snAutomaticDailyUpdate       = (int) get_option( 'mainwp_automaticDailyUpdate', 0 );
                        $snPluginAutomaticDailyUpdate = (int) get_option( 'mainwp_pluginAutomaticDailyUpdate', 0 );
                        $snThemeAutomaticDailyUpdate  = (int) get_option( 'mainwp_themeAutomaticDailyUpdate', 0 );
                        $snTransAutomaticUpdate       = (int) get_option( 'mainwp_transAutomaticDailyUpdate', 0 );

                        $backup_before_upgrade             = get_option( 'mainwp_backup_before_upgrade' );
                        $mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
                        if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
                            $mainwp_backup_before_upgrade_days = 7;
                        }
                        $mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );
                        $enableLegacyBackupFeature    = get_option( 'mainwp_enableLegacyBackupFeature' );
                        $primaryBackup                = get_option( 'mainwp_primaryBackup' );
                        $disableUpdateConfirmations   = (int) get_option( 'mainwp_disable_update_confirmations', 0 );
                        ?>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_pluginAutomaticDailyUpdate', $snPluginAutomaticDailyUpdate );
                            esc_html_e( 'Plugin advanced automatic updates', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enable or disable automatic plugin updates. If enabled, MainWP will update only plugins that you have marked as Trusted.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <select name="mainwp_pluginAutomaticDailyUpdate" id="mainwp_pluginAutomaticDailyUpdate" class="ui dropdown settings-field-value-change-handler">
                                    <option value="1" <?php echo 1 === $snPluginAutomaticDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Install Trusted Updates', 'mainwp' ); ?></option>
                                    <option value="0" <?php echo 0 === $snPluginAutomaticDailyUpdate || 2 === $snPluginAutomaticDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Disabled', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_themeAutomaticDailyUpdate', $snThemeAutomaticDailyUpdate );
                            esc_html_e( 'Theme advanced automatic updates', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enable or disable automatic theme updates. If enabled, MainWP will update only themes that you have marked as Trusted.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <select name="mainwp_themeAutomaticDailyUpdate" id="mainwp_themeAutomaticDailyUpdate" class="ui dropdown settings-field-value-change-handler">
                                    <option value="1" <?php echo 1 === $snThemeAutomaticDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Install Trusted Updates', 'mainwp' ); ?></option>
                                    <option value="0" <?php echo 0 === $snThemeAutomaticDailyUpdate || 2 === $snThemeAutomaticDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Disabled', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_stransAutomaticUpdate', $snTransAutomaticUpdate );
                            esc_html_e( 'Translation advanced automatic updates', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enable or disable automatic Translation updates.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <select name="mainwp_transAutomaticDailyUpdate" id="mainwp_transAutomaticDailyUpdate" class="ui dropdown settings-field-value-change-handler">
                                    <option value="1" <?php echo 1 === $snTransAutomaticUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Install Trusted Updates', 'mainwp' ); ?></option>
                                    <option value="0" <?php echo 0 === $snTransAutomaticUpdate || 2 === (int) $snTransAutomaticUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Disabled', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_automaticDailyUpdate', $snAutomaticDailyUpdate );
                            esc_html_e( 'WP Core advanced automatic updates', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enable or disable automatic WordPress core updates.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <select name="mainwp_automaticDailyUpdate" id="mainwp_automaticDailyUpdate" class="ui dropdown settings-field-value-change-handler">
                                    <option value="1" <?php echo 1 === $snAutomaticDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Install Trusted Updates', 'mainwp' ); ?></option>
                                    <option value="0" <?php echo 0 === $snAutomaticDailyUpdate || 2 === (int) $snAutomaticDailyUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Disabled', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <?php static::render_auto_updates_settings(); ?>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_delay_autoupdate', $delay_autoupdate );
                            esc_html_e( 'Advanced automatic updates delay', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui input" data-tooltip="<?php esc_attr_e( 'Set the number of days to delay automatic updates.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <select name="mainwp_delay_autoupdate" id="mainwp_delay_autoupdate" class="ui dropdown settings-field-value-change-handler">
                                    <option value="0" <?php echo 0 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( 'Delay off', 'mainwp' ); ?></option>
                                    <option value="1" <?php echo 1 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '1 day', 'mainwp' ); ?></option>
                                    <option value="2" <?php echo 2 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '2 days', 'mainwp' ); ?></option>
                                    <option value="3" <?php echo 3 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '3 days', 'mainwp' ); ?></option>
                                    <option value="4" <?php echo 4 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '4 days', 'mainwp' ); ?></option>
                                    <option value="5" <?php echo 5 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '5 days', 'mainwp' ); ?></option>
                                    <option value="6" <?php echo 6 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '6 days', 'mainwp' ); ?></option>
                                    <option value="7" <?php echo 7 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '7 days', 'mainwp' ); ?></option>
                                    <option value="14" <?php echo 14 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '14 days', 'mainwp' ); ?></option>
                                    <option value="30" <?php echo 30 === $delay_autoupdate ? 'selected' : ''; ?>><?php esc_html_e( '30 days', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_show_language_updates', $mainwp_show_language_updates );
                            esc_html_e( 'Show WordPress language updates', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want to manage Translation updates', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <input type="checkbox" name="mainwp_show_language_updates" class="settings-field-value-change-handler" id="mainwp_show_language_updates" <?php echo 1 === (int) $mainwp_show_language_updates ? 'checked="true"' : ''; ?>/>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_disable_update_confirmations', $disableUpdateConfirmations );
                            esc_html_e( 'Update confirmations', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Choose if you want to disable the popup confirmations when performing updates.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <select name="mainwp_disable_update_confirmations" id="mainwp_disable_update_confirmations" class="ui dropdown settings-field-value-change-handler">
                                    <option value="0" <?php echo 0 === $disableUpdateConfirmations ? 'selected' : ''; ?>><?php esc_html_e( 'Enable', 'mainwp' ); ?></option>
                                    <option value="2" <?php echo 2 === $disableUpdateConfirmations ? 'selected' : ''; ?>><?php esc_html_e( 'Disable', 'mainwp' ); ?></option>
                                    <option value="1" <?php echo 1 === $disableUpdateConfirmations ? 'selected' : ''; ?>><?php esc_html_e( 'Disable for single updates', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_check_http_response', get_option( 'mainwp_check_http_response', '' ) );
                            esc_html_e( 'Check site HTTP response after update', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want your MainWP Dashboard to check child site header response after updates.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" class="settings-field-value-change-handler" inverted-value="1" name="mainwp_check_http_response" id="mainwp_check_http_response" <?php echo 1 === (int) get_option( 'mainwp_check_http_response', 0 ) ? 'checked="true"' : ''; ?>/>
                            </div>
                        </div>

                        <?php if ( ( $enableLegacyBackupFeature && empty( $primaryBackup ) ) || ( empty( $enableLegacyBackupFeature ) && ! empty( $primaryBackup ) ) ) { ?>
                        <div class="ui grid field mainwp-parent-toggle settings-field-indicator-wrapper settings-field-indicator-updates">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_backup_before_upgrade', (int) $backup_before_upgrade );
                            esc_html_e( 'Require a backup before an update', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will check if full backups exists before updating.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_backup_before_upgrade" id="mainwp_backup_before_upgrade" <?php echo 1 === (int) $backup_before_upgrade ? 'checked="true"' : ''; ?>/>
                            </div>
                        </div>
                        <div class="ui grid field mainwp-child-field settings-field-indicator-wrapper settings-field-indicator-updates" default-indi-value="7" <?php echo 1 === (int) $backup_before_upgrade ? '' : 'style="display:none"'; ?> >
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_backup_before_upgrade_days', (int) $mainwp_backup_before_upgrade_days );
                            esc_html_e( 'Days without of a full backup tolerance', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set the number of days without of backup tolerance.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <input type="text"  class="settings-field-value-change-handler" name="mainwp_backup_before_upgrade_days" id="mainwp_backup_before_upgrade_days" value="<?php echo esc_attr( $mainwp_backup_before_upgrade_days ); ?>" />
                            </div>
                        </div>
                        <?php } ?>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-updates" default-indi-value="365">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_numberdays_Outdate_Plugin_Theme', (int) get_option( 'mainwp_numberdays_Outdate_Plugin_Theme', 365 ) );

                            esc_html_e( 'Abandoned plugins/themes tolerance', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set how many days without an update before plugin or theme will be considered as abandoned.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <input type="text" class="settings-field-value-change-handler" name="mainwp_numberdays_Outdate_Plugin_Theme" id="mainwp_numberdays_Outdate_Plugin_Theme" value="<?php echo false === get_option( 'mainwp_numberdays_Outdate_Plugin_Theme' ) ? 365 : intval( get_option( 'mainwp_numberdays_Outdate_Plugin_Theme' ) ); ?>"/>
                            </div>
                        </div>
                        <?php MainWP_Monitoring_View::render_settings(); ?>
                        <?php MainWP_Manage_Backups::render_settings(); ?>
                        <?php
                        /**
                         * Action: mainwp_settings_form_bottom
                         *
                         * Fires at the bottom of settings form.
                         *
                         * @since 4.1
                         */
                        do_action( 'mainwp_settings_form_bottom' );
                        ?>
                        <div class="ui divider"></div>
                        <input type="submit" name="submit" id="submit" class="ui button green big" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                        <div style="clear:both"></div>
                    </form>
                </div>
            </div>
        <?php
        static::render_footer( '' );
    }

    /**
     * Method render_auto_updates_settings().
     */
    public static function render_auto_updates_settings() { // phpcs:ignore -- NOSONAR - complex.

        $time_AutoUpdate       = get_option( 'mainwp_time_AutoUpdate' );
        $dayinweek_AutoUpdate  = (int) get_option( 'mainwp_dayinweek_AutoUpdate', 0 );
        $dayinmonth_AutoUpdate = (int) get_option( 'mainwp_dayinmonth_AutoUpdate', 1 );
        $frequency_AutoUpdate  = get_option( 'mainwp_frequency_AutoUpdate', 'daily' );

        if ( empty( $time_AutoUpdate ) ) {
            $time_AutoUpdate = '00:00';
        }

        if ( ! in_array( $frequency_AutoUpdate, array( 'daily', 'weekly', 'monthly' ) ) ) {
            $frequency_AutoUpdate = 'daily';
        }

        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general" default-indi-value="daily">
            <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_frequency_AutoUpdate', $frequency_AutoUpdate );
                esc_html_e( 'Frequency of automatic updates', 'mainwp' );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set the frequency for automatic updates process.', 'mainwp' ); ?>" data-inverted="" data-position="top left" >
                    <select name="mainwp_frequency_AutoUpdate" id="mainwp_frequency_AutoUpdate" class="ui dropdown settings-field-value-change-handler">
                        <option value="daily" <?php echo 'daily' === $frequency_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Daily', 'mainwp' ); ?></option>
                        <option value="weekly" <?php echo 'weekly' === $frequency_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Weekly', 'mainwp' ); ?></option>
                        <option value="monthly" <?php echo 'monthly' === $frequency_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Monthly', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>

            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general" id="mainwp-dayinmonth-autoupdate-wrapper" <?php echo in_array( $frequency_AutoUpdate, array( 'monthly' ) ) ? '' : 'style="display:none;"'; ?>>
                    <label class="six wide column middle aligned">
                        <?php
                        MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_dayinmonth_AutoUpdate', $dayinmonth_AutoUpdate );
                        esc_html_e( 'Select day', 'mainwp' );
                        ?>
                    </label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Choose a specific day in month to initiate automatic updates process.', 'mainwp' ); ?>" data-inverted="" data-position="top left" >
                    <select name="mainwp_dayinmonth_AutoUpdate" id="mainwp_dayinmonth_AutoUpdate" class="ui dropdown settings-field-value-change-handler">
                        <?php
                        $day_suffix = array(
                            1 => 'st',
                            2 => 'nd',
                            3 => 'rd',
                        );
                        for ( $x = 1; $x <= 31; $x++ ) {
                            $_select = '';
                            if ( $dayinmonth_AutoUpdate === $x ) {
                                $_select = 'selected';
                            }
                            $remain = $x % 10;
                            $day_sf = isset( $day_suffix[ $remain ] ) ? $day_suffix[ $remain ] : 'th';
                            echo '<option value="' . intval( $x ) . '" ' . esc_html( $_select ) . '>' . esc_html( $x . $day_sf ) . ' of the month</option>';
                        }
                        ?>
                            </select>
                    </div>
                </div>

            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general" id="mainwp-dayinweek-autoupdate-wrapper" <?php echo in_array( $frequency_AutoUpdate, array( 'weekly' ) ) ? '' : 'style="display:none;"'; ?>>
                <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_dayinweek_AutoUpdate', $dayinweek_AutoUpdate );
                esc_html_e( 'Select day', 'mainwp' );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Choose a specific day in week to initiate automatic updates process.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select name="mainwp_dayinweek_AutoUpdate" id="mainwp_dayinweek_AutoUpdate" class="ui dropdown settings-field-value-change-handler">
                        <option value="0" <?php echo 0 === $dayinweek_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Mon', 'mainwp' ); ?></option>
                        <option value="1" <?php echo 1 === $dayinweek_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Tue', 'mainwp' ); ?></option>
                        <option value="2" <?php echo 2 === $dayinweek_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Wed', 'mainwp' ); ?></option>
                        <option value="3" <?php echo 3 === $dayinweek_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Thus', 'mainwp' ); ?></option>
                        <option value="4" <?php echo 4 === $dayinweek_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Fri', 'mainwp' ); ?></option>
                        <option value="5" <?php echo 5 === $dayinweek_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Sat', 'mainwp' ); ?></option>
                        <option value="6" <?php echo 6 === $dayinweek_AutoUpdate ? 'selected' : ''; ?>><?php esc_html_e( 'Sun', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
            <div id="mainwp-time-autoupdate-wrapper" class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general" default-indi-value="00:00">
                <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_time_AutoUpdate', $time_AutoUpdate );
                esc_html_e( 'Select time', 'mainwp' );
                $_last_start_auto_updates = get_option( 'mainwp_automatic_updates_start_lasttime', 0 );
                $_next_auto_updates       = get_option( 'mainwp_automatic_update_next_run_timestamp', 0 );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Choose a specific time to initiate automatic updates process.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="time-selector">
                        <div class="ui input left icon">
                            <i class="clock icon"></i>
                            <input type="text" class="settings-field-value-change-handler" current-utc-datetime="<?php echo date( 'Y-m-d H:i:s' ); ?>" auto-updates-last-start-time="<?php echo date( 'Y-m-d H:i:s', $_last_start_auto_updates ); ?>" auto-updates-next-time="<?php echo date( 'Y-m-d H:i:s', $_next_auto_updates ); ?>" local-datetime="<?php echo date( 'Y-m-d H:i:s', MainWP_Utility::get_timestamp() ); // phpcs:ignore -- to get local time. ?>"  name="mainwp_time_AutoUpdate" id="mainwp_time_AutoUpdate" value="<?php echo esc_attr( $time_AutoUpdate ); ?>" />
                        </div>
                    </div>
                </div>
            </div>
            <script type="text/javascript">
                jQuery(document).on('change', '#mainwp_frequency_AutoUpdate', function () {
                    if( jQuery(this).val() == 'monthly' ){
                        jQuery('#mainwp-dayinweek-autoupdate-wrapper').hide();
                        jQuery('#mainwp-dayinmonth-autoupdate-wrapper').show();
                    } else if( jQuery(this).val() == 'weekly'){
                        jQuery('#mainwp-dayinweek-autoupdate-wrapper').show();
                        jQuery('#mainwp-dayinmonth-autoupdate-wrapper').hide();
                    } else {
                        jQuery('#mainwp-dayinweek-autoupdate-wrapper').hide();
                        jQuery('#mainwp-dayinmonth-autoupdate-wrapper').hide();
                    }
                });
            </script>
        <?php
    }

    /**
     * Render Timezone settings.
     */
    public static function render_timezone_settings() { // phpcs:ignore -- NOSONAR - complex.

        $current_offset  = get_option( 'gmt_offset' );
        $tzstring        = get_option( 'timezone_string' );
        $check_zone_info = true;

        // Remove old Etc mappings. Fallback to gmt_offset.
        if ( false !== strpos( $tzstring, 'Etc/GMT' ) ) {
            $tzstring = '';
        }

        if ( empty( $tzstring ) ) { // Create a UTC+- zone if no timezone string exists.
            $check_zone_info = false;
            if ( empty( $current_offset ) ) {
                $tzstring = 'UTC+0';
            } elseif ( $current_offset < 0 ) {
                $tzstring = 'UTC' . $current_offset;
            } else {
                $tzstring = 'UTC+' . $current_offset;
            }
        }

        $timezone_format = _x( 'Y-m-d H:i:s', 'timezone date format' );

        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Timezone', 'mainwp' ); ?></label>
            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Choose either a city in the same timezone as you or a %s (Coordinated Universal Time) time offset.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                <select id="timezone_string" class="ui dropdown" name="timezone_string" aria-describedby="timezone-description">
                <?php echo wp_timezone_choice( $tzstring, get_user_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </select>
                <div class="ui hidden fitted divider"></div>
                <div class="ui secondary segment">
                    <?php printf( esc_html__( 'Universal time is %s.' ), '<code>' . esc_html( date_i18n( $timezone_format, false, true ) ) . '</code>' ); ?>
                    <div class="ui hidden fitted divider"></div>
                <?php if ( get_option( 'timezone_string' ) || ! empty( $current_offset ) ) : ?>
                        <?php printf( esc_html__( 'Local time is %s.' ), '<code>' . esc_html( date_i18n( $timezone_format ) ) . '</code>' ); ?>
                        <div class="ui hidden fitted divider"></div>
                <?php endif; ?>
                <?php if ( $check_zone_info && $tzstring ) : ?>
                        <?php
                        $now = new \DateTime( 'now', new \DateTimeZone( $tzstring ) );
                        $dst = (bool) $now->format( 'I' );

                        if ( $dst ) {
                            esc_html_e( 'This timezone is currently in daylight saving time.', 'mainwp' );
                        } else {
                            esc_html_e( 'This timezone is currently in standard time.', 'mainwp' );
                        }
                        ?>
                        <div class="ui hidden fitted divider"></div>
                        <?php
                        if ( in_array( $tzstring, timezone_identifiers_list(), true ) ) {
                            $transitions = timezone_transitions_get( timezone_open( $tzstring ), time() );

                            if ( ! empty( $transitions[1] ) ) {
                                echo ' ';
                                $message = $transitions[1]['isdst'] ? esc_html__( 'Daylight saving time begins on: %s.', 'mainwp' ) : esc_html__( 'Standard time begins on: %s.', 'mainwp' );
                                printf( $message, '<code>' . wp_date( esc_html__( 'F j, Y' ) . ' ' . esc_html__( 'g:i a' ), esc_html( $transitions[1]['ts'] ) ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput
                            } else {
                                esc_html_e( 'This timezone does not observe daylight saving time.', 'mainwp' );
                            }
                        }
                        ?>
            <?php endif; ?>
            </div>
        </div>
        </div>
        <?php
    }

    /**
     * Render Date/Time settings.
     */
    public static function render_datetime_settings() {
        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general" default-indi-value="F j, Y">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'date_format', get_option( 'date_format' ) );
            esc_html_e( 'Date format', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column fieldset-wrapper" data-tooltip="<?php esc_attr_e( 'Choose the display format for date.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
            <?php
                /**
                 * Filters the default date formats.
                 *
                 * @since 2.7.0
                 * @since 4.0.0 Added ISO date standard YYYY-MM-DD format.
                 *
                 * @param string[] $default_date_formats Array of default date formats.
                 */
                $date_formats = array_unique( apply_filters( 'date_formats', array( esc_html__( 'F j, Y' ), 'Y-m-d', 'm/d/Y', 'd/m/Y' ) ) );

                $custom = true;

            foreach ( $date_formats as $format ) {
                echo "\t<label><input type='radio' name='date_format' value='" . esc_attr( $format ) . "'";
                if ( get_option( 'date_format' ) === $format ) { // checked() uses "==" rather than "===".
                    echo " checked='checked'";
                    $custom = false;
                }
                echo ' /> <span class="date-time-text format-i18n">' . esc_html( date_i18n( $format ) ) . '</span><code>' . esc_html( $format ) . "</code></label><br />\n";
            }
                echo '<label><input type="radio" name="date_format" id="date_format_custom_radio" value="\c\u\s\t\o\m"';
                checked( $custom );
                echo '/> <span class="date-time-text date-time-custom-text">' . esc_html__( 'Custom:' ) . '<span class="screen-reader-text"> ' . esc_html__( 'enter a custom date format in the following field' ) . '</span></span></label>' .
                    '<label for="date_format_custom" class="screen-reader-text">' . esc_html__( 'Custom date format:' ) . '</label>' .
                    '<input type="text" name="date_format_custom" id="date_format_custom" value="' . esc_attr( get_option( 'date_format' ) ) . '" class="small-text settings-field-value-change-handler" />' .
                    '<br />' .
                    '<em><strong>' . esc_html__( 'Preview:' ) . '</strong> <span class="example">' . esc_html( date_i18n( get_option( 'date_format' ) ) ) . '</span>' .
                    "<span class='spinner'></span>\n" . '</em>';
            ?>
        </div>
    </div>

    <div class="ui grid field settings-field-indicator-wrapper" default-indi-value="g:i a">
        <label class="six wide column middle aligned">
        <?php
        MainWP_Settings_Indicator::render_not_default_indicator( 'time_format', get_option( 'time_format' ) );
        esc_html_e( 'Time format', 'mainwp' );
        ?>
        </label>
        <div class="ten wide column fieldset-wrapper" data-tooltip="<?php esc_attr_e( 'Choose the display format for time.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
        <?php
                /**
                 * Filters the default time formats.
                 *
                 * @since 2.7.0
                 *
                 * @param string[] $default_time_formats Array of default time formats.
                 */
                $time_formats = array_unique( apply_filters( 'time_formats', array( esc_html__( 'g:i a' ), 'g:i A', 'H:i' ) ) );

                $custom = true;

        foreach ( $time_formats as $format ) {
            echo "\t<label><input type='radio' name='time_format' value='" . esc_attr( $format ) . "'";
            if ( get_option( 'time_format' ) === $format ) { // checked() uses "==" rather than "===".
                echo " checked='checked'";
                $custom = false;
            }
            echo ' /> <span class="date-time-text format-i18n">' . esc_html( date_i18n( $format ) ) . '</span><code>' . esc_html( $format ) . "</code></label><br />\n";
        }
            echo '<label><input type="radio" name="time_format" id="time_format_custom_radio" value="\c\u\s\t\o\m"';
            checked( $custom );
            echo '/> <span class="date-time-text date-time-custom-text">' . esc_html__( 'Custom:' ) . '<span class="screen-reader-text"> ' . esc_html__( 'enter a custom time format in the following field' ) . '</span></span></label>' .
                '<label for="time_format_custom" class="screen-reader-text">' . esc_html__( 'Custom time format:' ) . '</label>' .
                '<input type="text" class="small-text settings-field-value-change-handler" name="time_format_custom" id="time_format_custom" value="' . esc_attr( get_option( 'time_format' ) ) . '" />' .
                '<br />' .
            '<em><strong>' . esc_html__( 'Preview:' ) . '</strong> <span class="example">' . esc_html( date_i18n( get_option( 'time_format' ) ) ) . '</span>' .
            "<span class='spinner'></span>\n" . '</em>';
        ?>
        </div>
    </div>
    <script type="text/javascript">
            jQuery(function($) {
                $( 'input[name="date_format"]' ).on( 'click', function() {
                    if ( 'date_format_custom_radio' !== $( this ).attr( 'id' ) ){
                        $( 'input[name="date_format_custom"]' ).val( $( this ).val() ).closest( '.fieldset-wrapper' ).find( '.example' ).text( $( this ).parent( 'label' ).children( '.format-i18n' ).text() );
                        $( 'input[name="date_format_custom"]' ).trigger("change"); // support indicator.
                    }
                } );

                $( 'input[name="date_format_custom"]' ).on( 'click input', function() {
                    $( '#date_format_custom_radio' ).prop( 'checked', true );
                } );

                $( 'input[name="time_format"]' ).on( 'click', function() {
                    if ( 'time_format_custom_radio' !== $(this).attr( 'id' ) ){
                        $( 'input[name="time_format_custom"]' ).val( $( this ).val() ).closest( '.fieldset-wrapper' ).find( '.example' ).text( $( this ).parent( 'label' ).children( '.format-i18n' ).text() );
                        $( 'input[name="time_format_custom"]' ).trigger("change"); // support indicator.
                    }
                } );

                $( 'input[name="time_format_custom"]' ).on( 'click input', function() {
                    $( '#time_format_custom_radio' ).prop( 'checked', true );
                } );

                $( 'input[name="date_format_custom"], input[name="time_format_custom"]' ).on( 'input', function() {
                    let format = $( this ),
                        fieldset = format.closest( '.fieldset-wrapper' ),
                        example = fieldset.find( '.example' ),
                        spinner = fieldset.find( '.spinner' );

                    // Debounce the event callback while users are typing.
                    clearTimeout( $.data( this, 'timer' ) );
                    $( this ).data( 'timer', setTimeout( function() {
                        // If custom date is not empty.
                        if ( format.val() ) {
                            spinner.addClass( 'is-active' );

                            $.post( ajaxurl, {
                                action: 'date_format_custom' === format.attr( 'name' ) ? 'date_format' : 'time_format',
                                date    : format.val()
                            }, function( d ) { spinner.removeClass( 'is-active' ); example.text( d ); } );
                        }
                    }, 500 ) );
                } );
            });
        </script>
        <?php
    }

    /**
     * Method get_websites_automatic_update_time()
     *
     * Get websites automatic update time.
     *
     * @return mixed array
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     */
    public static function get_websites_automatic_update_time() {
        $lastAutomaticUpdate    = MainWP_Auto_Updates_DB::instance()->get_websites_last_automatic_sync();
        $lasttimeAutomatic      = get_option( 'mainwp_updatescheck_last_timestamp' );
        $lasttimeStartAutomatic = get_option( 'mainwp_updatescheck_start_last_timestamp' );
        $local_timestamp        = MainWP_Utility::get_timestamp();

        if ( empty( $lasttimeStartAutomatic ) && ! empty( $lasttimeAutomatic ) ) {
            $lasttimeStartAutomatic = $lasttimeAutomatic;
        }

        if ( empty( $lastAutomaticUpdate ) ) {
            $nextAutomaticUpdate = esc_html__( 'Any minute', 'mainwp' );
        } elseif ( 0 < MainWP_Auto_Updates_DB::instance()->get_websites_check_updates_count( $lasttimeStartAutomatic ) ) {
            $nextAutomaticUpdate = esc_html__( 'Processing your websites.', 'mainwp' );
        } else {
            $next_time = MainWP_System_Cron_Jobs::get_next_time_automatic_update_to_show();
            if ( $next_time < $local_timestamp + 5 * MINUTE_IN_SECONDS ) {
                $nextAutomaticUpdate = esc_html__( 'Any minute', 'mainwp' );
            } else {
                $nextAutomaticUpdate = MainWP_Utility::format_timestamp( $next_time );
            }
        }

        if ( empty( $lastAutomaticUpdate ) ) {
            $lastAutomaticUpdate = esc_html__( 'Never', 'mainwp' );
        } else {
            $lastAutomaticUpdate = MainWP_Utility::format_timestamp( $lastAutomaticUpdate );
        }

        return array(
            'last' => $lastAutomaticUpdate,
            'next' => $nextAutomaticUpdate,
        );
    }

    /**
     * Returns false or the location of the OpenSSL Lib File.
     *
     * @return mixed false|opensslLibLocation
     *
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_openssl_config_warning()
     */
    public static function show_openssl_lib_config() {
        return MainWP_Server_Information_Handler::is_openssl_config_warning() ? true : false;
    }

    /**
     * Render Advanced Options Subpage.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public static function render_advanced() { //phpcs:ignore -- NOSONAR - complex method.
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage dashboard settings', 'mainwp' ) );
            return;
        }

        if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'SettingsAdvanced' ) ) {

            /**
            * Action: mainwp_before_save_advanced_settings
            *
            * Fires before save advanced settings.
            *
            * @since 4.1
            */
            do_action( 'mainwp_before_save_advanced_settings', $_POST );

            MainWP_Utility::update_option( 'mainwp_maximumRequests', ! empty( $_POST['mainwp_maximumRequests'] ) ? intval( $_POST['mainwp_maximumRequests'] ) : 4 );
            MainWP_Utility::update_option( 'mainwp_minimumDelay', ! empty( $_POST['mainwp_minimumDelay'] ) ? intval( $_POST['mainwp_minimumDelay'] ) : 200 );
            MainWP_Utility::update_option( 'mainwp_maximumIPRequests', ! empty( $_POST['mainwp_maximumIPRequests'] ) ? intval( $_POST['mainwp_maximumIPRequests'] ) : 1 );
            MainWP_Utility::update_option( 'mainwp_minimumIPDelay', ! empty( $_POST['mainwp_minimumIPDelay'] ) ? intval( $_POST['mainwp_minimumIPDelay'] ) : 1000 );
            MainWP_Utility::update_option( 'mainwp_maximumSyncRequests', ! empty( $_POST['mainwp_maximumSyncRequests'] ) ? intval( $_POST['mainwp_maximumSyncRequests'] ) : 8 );
            MainWP_Utility::update_option( 'mainwp_maximumInstallUpdateRequests', ! empty( $_POST['mainwp_maximumInstallUpdateRequests'] ) ? intval( $_POST['mainwp_maximumInstallUpdateRequests'] ) : 3 );
            MainWP_Utility::update_option( 'mainwp_sslVerifyCertificate', isset( $_POST['mainwp_sslVerifyCertificate'] ) ? 1 : 0 );
            MainWP_Utility::update_option( 'mainwp_connect_signature_algo', isset( $_POST['mainwp_settings_openssl_alg'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_settings_openssl_alg'] ) ) : 0 );
            MainWP_Utility::update_option( 'mainwp_verify_connection_method', isset( $_POST['mainwp_settings_verify_connection_method'] ) ? intval( $_POST['mainwp_settings_verify_connection_method'] ) : 0 );
            MainWP_Utility::update_option( 'mainwp_forceUseIPv4', isset( $_POST['mainwp_forceUseIPv4'] ) ? 1 : 0 );
            $use_wpcron = ! isset( $_POST['mainwp_options_wp_cron'] ) ? 0 : 1;
            MainWP_Utility::update_option( 'mainwp_wp_cron', $use_wpcron );
            MainWP_Utility::update_option( 'mainwp_optimize', ( ! isset( $_POST['mainwp_optimize'] ) ? 0 : 1 ) );
            MainWP_Utility::update_option( 'mainwp_maximum_uptime_monitoring_requests', ! empty( $_POST['mainwp_maximumUptimeMonitoringRequests'] ) ? intval( $_POST['mainwp_maximumUptimeMonitoringRequests'] ) : 10 );
            MainWP_Utility::update_option( 'mainwp_chunksitesnumber', isset( $_POST['mainwp_chunksitesnumber'] ) ? intval( $_POST['mainwp_chunksitesnumber'] ) : 10 );
            MainWP_Utility::update_option( 'mainwp_chunksleepinterval', isset( $_POST['mainwp_chunksleepinterval'] ) ? intval( $_POST['mainwp_chunksleepinterval'] ) : 5 );

            $sync_data = array();

            if ( isset( $_POST['mainwp_settings_sync_data'] ) && is_array( $_POST['mainwp_settings_sync_data'] ) ) {
                $selected_data = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_settings_sync_data'] ) );
                foreach ( $selected_data as $name ) {
                    $sync_data[ $name ] = 1;
                }
            }

            if ( isset( $_POST['mainwp_settings_sync_name'] ) && is_array( $_POST['mainwp_settings_sync_name'] ) ) {
                $name_data = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_settings_sync_name'] ) );
                foreach ( $name_data as $name ) {
                    if ( ! isset( $sync_data[ $name ] ) ) {
                        $sync_data[ $name ] = 0;
                    }
                }
            }

            MainWP_Utility::update_option( 'mainwp_settings_sync_data', wp_json_encode( $sync_data ) );

            // required check.
            MainWP_Uptime_Monitoring_Schedule::instance()->check_to_disable_schedule_individual_uptime_monitoring(); // required a check to sync the settings.

            if ( isset( $_POST['mainwp_openssl_lib_location'] ) ) {
                $openssl_loc = ! empty( $_POST['mainwp_openssl_lib_location'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_openssl_lib_location'] ) ) : '';
                MainWP_Utility::update_option( 'mainwp_opensslLibLocation', $openssl_loc );
            }

            /**
            * Action: mainwp_after_save_advanced_settings
            *
            * Fires after advanced settings save.
            *
            * @since 4.1
            */
            do_action( 'mainwp_after_save_advanced_settings', $_POST );
        }
        static::render_header( 'Advanced' );
        ?>

        <div id="mainwp-advanced-settings" class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-advanced-settings-info-notice' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-advanced-settings-info-notice"></i>
                    <?php echo esc_html__( 'Set how many requests are performed at once and delay between requests in order to optimize your MainWP Dashboard performance.  Both Cross IP and IP Settings handle the majority of work connecting to your Child sites, while the sync, update, and installation request have specialized options under the Frontend Requests Settings section.', 'mainwp' ); ?>
                </div>
            <?php endif; ?>
            <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'SettingsAdvanced' ) ) : ?>
                <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
                <?php endif; ?>
                <div class="ui form">
                    <form method="POST" action="">
                        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'SettingsAdvanced' ) ); ?>" />
                        <?php

                        /**
                         * Action: mainwp_advanced_settings_form_top
                         *
                         * Fires at the top of advanced settings form.
                         *
                         * @since 4.1
                         */
                        do_action( 'mainwp_advanced_settings_form_top' );

                        if ( static::show_openssl_lib_config() ) {
                            $openssl_loc = MainWP_System_Utility::get_openssl_conf();
                            ?>
                            <h3 class="ui dividing header">
                                <?php esc_html_e( 'OpenSSL Settings', 'mainwp' ); ?>
                                <div class="sub header"><?php esc_html_e( 'Due to bug with PHP on some servers it is required to set the OpenSSL Library location so MainWP Dashboard can connect to your child sites.', 'mainwp' ); ?></div>
                            </h3>
                                <div class="ui grid field">
                                    <label class="six wide column middle aligned"><?php esc_html_e( 'OpenSSL.cnf location', 'mainwp' ); ?></label>
                                    <div class="ten wide column ui field">
                                        <input type="text" name="mainwp_openssl_lib_location" value="<?php echo esc_html( $openssl_loc ); ?>">
                                        <em><?php esc_html_e( 'If your openssl.cnf file is saved to a different path from what is entered please enter your exact path.', 'mainwp' ); ?> <?php printf( esc_html__( 'If you are not sure how to find the openssl.cnf location, please %1$scheck this help document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/how-to-find-the-openssl-cnf-file/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></em>
                                        <em><?php esc_html_e( 'If you have confirmed the placement of your openssl.cnf and are still receiving an error banner, click the "Error Fixed" button to dismiss it.', 'mainwp' ); ?></em>
                                    </div>
                                </div>
                        <?php } ?>

                        <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-cross-ip' ); ?>
                            <?php esc_html_e( 'Rate Limiting & Concurrency Controls', 'mainwp' ); ?>
                            <div class="sub header"><?php esc_html_e( 'Fine-tune how your system handles outgoing requests by managing both global and per-server concurrency limits.', 'mainwp' ); ?></div>
                        </h3>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cross-ip" default-indi-value="4">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_maximumRequests', (int) get_option( 'mainwp_maximumRequests', 4 ) );
                            esc_html_e( 'Global request limit (Default: 4)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Defines the overall maximum number of simultaneous requests that can be active. Adjust this setting to balance system throughput with resource constraints (default is 4).', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider" id="mainwp_maximumRequests_slider"></div>
                                <div class="ui input">
                                    <input type="hidden" class="settings-field-value-change-handler" name="mainwp_maximumRequests" id="mainwp_maximumRequests" value="<?php echo false === get_option( 'mainwp_maximumRequests' ) ? 4 : esc_attr( get_option( 'mainwp_maximumRequests' ) ); ?>"/>
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cross-ip" default-indi-value="200">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_minimumDelay', (int) get_option( 'mainwp_minimumDelay', 200 ) );
                            esc_html_e( 'Minimum delay between requests in milliseconds (Default: 200ms)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'This option allows you to control minimum time delay between two requests.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider" id="mainwp_minimumDelay_slider"></div>
                                <div class="ui input">
                                    <input type="hidden" class="settings-field-value-change-handler" name="mainwp_minimumDelay" id="mainwp_minimumDelay" value="<?php echo false === get_option( 'mainwp_minimumDelay' ) ? 200 : esc_attr( get_option( 'mainwp_minimumDelay' ) ); ?>"/>
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-per-ip" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_maximumIPRequests', (int) get_option( 'mainwp_maximumIPRequests', 1 ) );
                            esc_html_e( 'Per-IP request limit (Default: 1)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column"  data-tooltip="<?php esc_attr_e( 'Sets the maximum number of concurrent requests allowed for any individual website\'s IP address. This setting safeguards target servers from excessive load (default is 1).', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider" id="mainwp_maximumIPRequests_slider"></div>
                                <div class="ui input">
                                    <input type="hidden" class="settings-field-value-change-handler" name="mainwp_maximumIPRequests" id="mainwp_maximumIPRequests" value="<?php echo false === get_option( 'mainwp_maximumIPRequests' ) ? 1 : esc_attr( get_option( 'mainwp_maximumIPRequests' ) ); ?>"/>
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-per-ip" default-indi-value="1000">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_minimumIPDelay', (int) get_option( 'mainwp_minimumIPDelay', 1000 ) );
                            esc_html_e( 'Minimum delay between requests to the same IP in milliseconds (Default: 1000ms)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'This option allows you to control minimum time delay between two requests.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider" id="mainwp_minimumIPDelay_slider"></div>
                                <div class="ui input">
                                    <input type="hidden" class="settings-field-value-change-handler" name="mainwp_minimumIPDelay" id="mainwp_minimumIPDelay" value="<?php echo false === get_option( 'mainwp_minimumIPDelay' ) ? 1000 : esc_attr( get_option( 'mainwp_minimumIPDelay' ) ); ?>"/>
                                </div>
                            </div>
                        </div>

                        <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-chunk-site' ); ?>
                            <?php esc_html_e( 'Batch Processing Settings', 'mainwp' ); ?>
                            <div class="sub header"><?php esc_html_e( 'Configure how many websites are processed at once and the delay between each batch to optimize system performance and prevent server overload.', 'mainwp' ); ?></div>
                        </h3>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cross-ip" default-indi-value="10">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', 10 === (int) get_option( 'mainwp_chunksitesnumber', 10 ) ? '' : 1 );
                            esc_html_e( 'Maximum sites per batch (Default: 10)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'The maximum number of websites processed concurrently. If too many requests are sent out, they will begin to time out.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider" id="mainwp_chunksitesnumber_slider"></div>
                                <div class="ui input">
                                    <input type="hidden" class="settings-field-value-change-handler" name="mainwp_chunksitesnumber" id="mainwp_chunksitesnumber" value="<?php echo false === get_option( 'mainwp_chunksitesnumber' ) ? 10 : (int) get_option( 'mainwp_chunksitesnumber' ); ?>"/>
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cross-ip" default-indi-value="5">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', 5 === (int) get_option( 'mainwp_chunksleepinterval', 5 ) ? '' : 1 );
                            esc_html_e( 'Delay between batches (Default: 5ms)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'The pause duration between processing each batch of websites.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider" id="mainwp_chunksleepinterval_slider"></div>
                                <div class="ui input">
                                    <input type="hidden" class="settings-field-value-change-handler" name="mainwp_chunksleepinterval" id="mainwp_chunksleepinterval" value="<?php echo false === get_option( 'mainwp_chunksleepinterval' ) ? 5 : (int) get_option( 'mainwp_chunksleepinterval' ); ?>"/>
                                </div>
                            </div>
                        </div>

                        <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-frontend-request' ); ?>
                            <?php esc_html_e( 'Frontend Request Settings', 'mainwp' ); ?>
                            <div class="sub header"><?php esc_html_e( 'Configure limits for concurrent background tasks such as synchronization, installation/updating, and uptime monitoring, ensuring efficient resource use and system stability.', 'mainwp' ); ?></div>
                        </h3>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-frontend-request" default-indi-value="8">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_maximumSyncRequests', (int) get_option( 'mainwp_maximumSyncRequests', 8 ) );
                            esc_html_e( 'Maximum simultaneous sync requests (Default: 8)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'This option allows you to control how many sites your MainWP Dashboard should sync at once.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider" id="mainwp_maximumSyncRequests_slider"></div>
                                <div class="ui input">
                                    <input type="hidden" class="settings-field-value-change-handler" name="mainwp_maximumSyncRequests" id="mainwp_maximumSyncRequests" value="<?php echo false === get_option( 'mainwp_maximumSyncRequests' ) ? 8 : esc_attr( get_option( 'mainwp_maximumSyncRequests' ) ); ?>"/>
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-frontend-request" default-indi-value="3">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_maximumInstallUpdateRequests', (int) get_option( 'mainwp_maximumInstallUpdateRequests', 3 ) );
                            esc_html_e( 'Maximum simultaneous install and update requests (Default: 3)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column"  data-tooltip="<?php esc_attr_e( 'This option allows you to control how many update and install requests your MainWP Dashboard should process at once.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider" id="mainwp_maximumInstallUpdateRequests_slider"></div>
                                <div class="ui input">
                                <input type="hidden" class="settings-field-value-change-handler" name="mainwp_maximumInstallUpdateRequests" id="mainwp_maximumInstallUpdateRequests" value="<?php echo false === get_option( 'mainwp_maximumInstallUpdateRequests' ) ? 3 : esc_attr( get_option( 'mainwp_maximumInstallUpdateRequests' ) ); ?>"/>
                            </div>
                            </div>
                        </div>

                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-frontend-request" default-indi-value="10">
                            <label class="six wide column middle aligned">
                            <?php
                            $maximum_monitoring_requests = (int) get_option( 'mainwp_maximum_uptime_monitoring_requests', 10 );
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_maximum_uptime_monitoring_requests', $maximum_monitoring_requests );
                            esc_html_e( 'Maximum simultaneous uptime monitoring requests (Default: 10)', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column"  data-tooltip="<?php esc_attr_e( 'This option allows you to control how many uptime monitoring requests your MainWP Dashboard should process at once.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <div class="ui bottom aligned labeled slider settings-field-value-change-handler" id="mainwp_maximumUptimeMonitoringRequests_slider"></div>
                                <div class="ui input">
                                    <input type="hidden" class="settings-field-value-change-handler" name="mainwp_maximumUptimeMonitoringRequests" id="mainwp_maximumUptimeMonitoringRequests" value="<?php echo false === get_option( 'mainwp_maximum_uptime_monitoring_requests' ) ? 10 : esc_attr( get_option( 'mainwp_maximum_uptime_monitoring_requests' ) ); ?>"/>
                                </div>
                            </div>
                        </div>

                        <h3 class="ui dividing header">
                        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-miscellaneous' ); ?>
                        <?php esc_html_e( 'Miscellaneous Settings', 'mainwp' ); ?></h3>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-miscellaneous" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_optimize', (int) get_option( 'mainwp_optimize', 1 ) );
                            esc_html_e( 'Optimize data loading', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox"  data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will cache updates for faster loading.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_optimize" id="mainwp_optimize" <?php echo 1 === (int) get_option( 'mainwp_optimize', 1 ) ? 'checked="true"' : ''; ?> /><label><?php esc_html_e( 'Default: Off', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-miscellaneous" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_wp_cron', (int) get_option( 'mainwp_wp_cron', 1 ) );
                            esc_html_e( 'Use WP Cron', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Disabling this option will disable the WP Cron so all scheduled events will stop working.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_options_wp_cron" id="mainwp_options_wp_cron" <?php echo 1 === (int) get_option( 'mainwp_wp_cron' ) || ( false === get_option( 'mainwp_wp_cron' ) ) ? 'checked="true"' : ''; ?>/><label><?php esc_html_e( 'Default: On', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-miscellaneous" default-indi-value="1" >
                            <label class="six wide column middle aligned">
                            <?php
                                $indi_val = (int) get_option( 'mainwp_sslVerifyCertificate', 1 );
                                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_sslVerifyCertificate', $indi_val );
                                esc_html_e( 'Verify SSL certificate', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will verify the SSL Certificate on your Child Site (if exists) while connecting the Child Site.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_sslVerifyCertificate" id="mainwp_sslVerifyCertificate" value="checked" <?php echo false === get_option( 'mainwp_sslVerifyCertificate' ) || 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ? 'checked="checked"' : ''; ?>/><label><?php esc_html_e( 'Default: On', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <?php
                        $general_verify_con = (int) get_option( 'mainwp_verify_connection_method', 1 );
                        ?>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-miscellaneous" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_verify_connection_method', (int) $general_verify_con );
                            esc_html_e( 'Verify connection method', 'mainwp' );
                            ?>
                            </label>
                            <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Select Verify connection method. If you are not sure, select "Default".', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <select class="ui dropdown settings-field-value-change-handler" id="mainwp_settings_verify_connection_method" name="mainwp_settings_verify_connection_method">
                                    <option <?php echo ( empty( $general_verify_con ) || 1 === $general_verify_con ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'OpenSSL (default)', 'mainwp' ); ?></option>
                                    <option <?php echo ( 2 === $general_verify_con ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'PHPSECLIB (fallback)', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <?php
                        $sign_note        = MainWP_Connect_Lib::get_connection_algo_settings_note();
                        $sign_algs        = MainWP_System_Utility::get_open_ssl_sign_algos();
                        $general_sign_alg = get_option( 'mainwp_connect_signature_algo', false );
                        if ( false === $general_sign_alg ) {
                            $general_sign_alg = defined( 'OPENSSL_ALGO_SHA256' ) ? OPENSSL_ALGO_SHA256 : 1;
                            MainWP_Utility::update_option( 'mainwp_connect_signature_algo', $general_sign_alg );
                        } else {
                            $general_sign_alg = intval( $general_sign_alg );
                        }
                        $algo_def = defined( 'OPENSSL_ALGO_SHA256' ) ? (int) OPENSSL_ALGO_SHA256 : 1;
                        ?>
                        <div default-indi-value="<?php echo esc_attr( $algo_def ); ?>" class="ui grid field mainwp-hide-elemenent-sign-algo settings-field-indicator-wrapper settings-field-indicator-miscellaneous" <?php echo ( 2 === $general_verify_con ) ? 'style="display:none;"' : ''; ?> >
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_connect_signature_algo', (int) $general_sign_alg );
                            esc_html_e( 'OpenSSL signature algorithm', 'mainwp' );
                            ?>
                            </label>
                            <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Select OpenSSL signature algorithm. If you are not sure, select "Default".', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <select class="ui dropdown settings-field-value-change-handler" id="mainwp_settings_openssl_alg" name="mainwp_settings_openssl_alg">
                                    <?php
                                    foreach ( $sign_algs as $val => $text ) {
                                        ?>
                                        <option <?php echo ( $val === $general_sign_alg ) ? 'selected' : ''; ?> value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $text ); ?></option>
                                        <?php
                                    }
                                    ?>
                                </select>
                                <div class="ui yellow message mainwp-hide-elemenent-sign-algo-note" <?php echo ( 1 === $general_sign_alg ) ? '' : 'style="display:none;"'; ?>><?php echo esc_html( $sign_note ); ?></div>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-miscellaneous">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_forceUseIPv4', (int) get_option( 'mainwp_forceUseIPv4' ) );
                            esc_html_e( 'Force IPv4', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox"  data-tooltip="<?php esc_attr_e( 'Enable if you want to force your MainWP Dashboard to use IPv4 while tryig to connect child sites.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_forceUseIPv4" id="mainwp_forceUseIPv4" value="checked" <?php echo ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) ? 'checked="checked"' : ''; ?>/><label><?php esc_html_e( 'Default: Off', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <?php
                        static::render_sync_data_selection();
                        /**
                         * Action: mainwp_advanced_settings_form_bottom
                         *
                         * Fires at the bottom of advanced settings form.
                         *
                         * @since 4.1
                         */
                        do_action( 'mainwp_advanced_settings_form_bottom' );

                        ?>
                        <div class="ui divider"></div>
                        <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                    </form>
                </div>
            </div>
            <script>
            const maximumRequests = <?php echo ! empty( get_option( 'mainwp_maximumRequests' ) ) ? esc_js( get_option( 'mainwp_maximumRequests' ) ) : 4; ?>;
            jQuery('#mainwp_maximumRequests_slider').slider({
                min: 1,
                max: 20,
                start: maximumRequests,
                step: 1,
                restrictedLabels: [1,20],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_maximumRequests').val(value);
                }
            });
            const minimumDelay = <?php echo ! empty( get_option( 'mainwp_minimumDelay' ) ) ? esc_js( get_option( 'mainwp_minimumDelay' ) ) : 200; ?>;
            jQuery('#mainwp_minimumDelay_slider').slider({
                min: 100,
                max: 5000,
                start: minimumDelay,
                step: 100,
                restrictedLabels: [100,5000],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_minimumDelay').val(value);
                }
            });
            const maximumIPRequests = <?php echo ! empty( get_option( 'mainwp_maximumIPRequests' ) ) ? esc_js( get_option( 'mainwp_maximumIPRequests' ) ) : 1; ?>;
            jQuery('#mainwp_maximumIPRequests_slider').slider({
                min: 1,
                max: 10,
                start: maximumIPRequests,
                step: 1,
                restrictedLabels: [1,10],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_maximumIPRequests').val(value);
                }
            });
            const minimumIPDelay = <?php echo ! empty( get_option( 'mainwp_minimumIPDelay' ) ) ? esc_js( get_option( 'mainwp_minimumIPDelay' ) ) : 1000; ?>;
            jQuery('#mainwp_minimumIPDelay_slider').slider({
                min: 500,
                max: 5000,
                start: minimumIPDelay,
                step: 100,
                restrictedLabels: [500,5000],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_minimumIPDelay').val(value);
                }
            });
            const maximumSyncRequests = <?php echo ! empty( get_option( 'mainwp_maximumSyncRequests' ) ) ? esc_js( get_option( 'mainwp_maximumSyncRequests' ) ) : 8; ?>;
            jQuery('#mainwp_maximumSyncRequests_slider').slider({
                min: 1,
                max: 20,
                start: maximumSyncRequests,
                step: 1,
                restrictedLabels: [1,20],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_maximumSyncRequests').val(value);
                }
            });
            const maximumInstallUpdateRequests = <?php echo ! empty( get_option( 'mainwp_maximumInstallUpdateRequests' ) ) ? esc_js( get_option( 'mainwp_maximumInstallUpdateRequests' ) ) : 3; ?>;
            jQuery('#mainwp_maximumInstallUpdateRequests_slider').slider({
                min: 1,
                max: 20,
                start: maximumInstallUpdateRequests,
                step: 1,
                restrictedLabels: [1,20],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_maximumInstallUpdateRequests').val(value);
                }
            });
            const maximumUptimeMonitoringRequests = <?php echo ! empty( get_option( 'mainwp_maximum_uptime_monitoring_requests' ) ) ? esc_js( get_option( 'mainwp_maximum_uptime_monitoring_requests' ) ) : 10; ?>;
            jQuery('#mainwp_maximumUptimeMonitoringRequests_slider').slider({
                min: 1,
                max: 100,
                start: maximumUptimeMonitoringRequests,
                step: 1,
                restrictedLabels: [1,100],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_maximumUptimeMonitoringRequests').val(value).change();
                }
            });
            jQuery('#mainwp_maximumUptimeMonitoringRequests_slider').slider('set value', maximumUptimeMonitoringRequests);

            jQuery('#mainwp_chunksitesnumber_slider').slider({
                min: 1,
                max: 30,
                start: <?php echo false !== get_option( 'mainwp_chunksitesnumber' ) ? intval( get_option( 'mainwp_chunksitesnumber' ) ) : 10; ?>,
                step: 1,
                restrictedLabels: [1,30],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_chunksitesnumber').val(value).change();
                }
            });

            jQuery('#mainwp_chunksleepinterval_slider').slider({
                min: 0,
                max: 20,
                start: <?php echo false !== get_option( 'mainwp_chunksleepinterval' ) ? intval( get_option( 'mainwp_chunksleepinterval' ) ) : 5; ?>,
                step: 1,
                restrictedLabels: [0,20],
                showThumbTooltip: true,
                tooltipConfig: {
                    position: 'top center',
                    variation: 'small visible black'
                },
                onChange: function(value) {
                    jQuery('#mainwp_chunksleepinterval').val(value).change();
                }
            });

            </script>
        <?php
        static::render_footer( 'Advanced' );
    }

    /**
     * Method render_sync_data_selection().
     *
     * @return void
     */
    public static function render_sync_data_selection() {

        $default_sync = static::get_data_sync_default();

        $sync_data_settings = get_option( 'mainwp_settings_sync_data' );

        $sync_data_settings = ! empty( $sync_data_settings ) ? json_decode( $sync_data_settings, true ) : array();

        if ( ! is_array( $sync_data_settings ) ) {
            $sync_data_settings = array();
        }

        $setting_page = true;

        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-miscellaneous">
            <label class="six wide column top aligned">
            <?php
            MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-sync-data' );
            esc_html_e( 'Select data to sync', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column" <?php echo $setting_page ? 'data-tooltip="' . esc_attr__( 'Select data that you want to sync.', 'mainwp' ) . '"' : ''; ?> data-inverted="" data-position="top left">
                <ul class="mainwp_hide_wpmenu_checkboxes">
                    <?php
                    foreach ( $default_sync as $name => $title ) {
                        $_selected = '';
                        if ( ! isset( $sync_data_settings[ $name ] ) || 1 === (int) $sync_data_settings[ $name ] ) {
                            $_selected = 'checked';
                        }
                        ?>
                        <li>
                            <div class="ui checkbox">
                                <input type="checkbox" class="settings-field-value-change-handler" id="mainwp_select_sync_<?php echo esc_attr( $name ); ?>" name="mainwp_settings_sync_data[]" <?php echo esc_html( $_selected ); ?> value="<?php echo esc_attr( $name ); ?>">
                                <label for="mainwp_select_sync_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
                            </div>
                            <input type="hidden" name="mainwp_settings_sync_name[]" value="<?php echo esc_attr( $name ); ?>">
                        </li>
                        <?php
                    }
                    ?>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Method get_data_sync_default().
     *
     * @return array data.
     */
    public static function get_data_sync_default() {
        return array(
            'wp_updates'              => __( 'WordPress updates information', 'mainwp' ),
            'plugin_updates'          => __( 'Plugins updates information', 'mainwp' ),
            'theme_updates'           => __( 'Themes updates information', 'mainwp' ),
            'translation_updates'     => __( 'Translations updates information', 'mainwp' ),
            'recent_comments'         => __( 'Recent comments', 'mainwp' ),
            'recent_posts'            => __( 'Recent posts', 'mainwp' ),
            'recent_pages'            => __( 'Recent pages', 'mainwp' ),
            'securityStats'           => __( 'Site hardening information', 'mainwp' ),
            'directories'             => __( 'Site directory listing (needed for legacy backup feature)', 'mainwp' ),
            'categories'              => __( 'Posts catetegories', 'mainwp' ),
            'totalsize'               => __( 'Website size (needed for Clone and legacy backup features)', 'mainwp' ),
            'dbsize'                  => __( 'Database size information', 'mainwp' ),
            'plugins'                 => __( 'Installed plugins', 'mainwp' ),
            'themes'                  => __( 'Installed Themes', 'mainwp' ),
            'users'                   => __( 'Users information', 'mainwp' ),
            'plugins_outdate_info'    => __( 'Abandoned plugins information', 'mainwp' ),
            'themes_outdate_info'     => __( 'Abandoned themes information', 'mainwp' ),
            'health_site_status'      => __( 'Site health information', 'mainwp' ),
            'child_site_actions_data' => __( 'Non-MainWP changes data', 'mainwp' ),
            'othersData'              => __( 'Other data (required by some extensions)', 'mainwp' ),
        );
    }

    /**
     * Method get_data_list_to_sync().
     *
     * @return array Data list to sync.
     */
    public function get_data_list_to_sync() {
        $sync_data_settings = get_option( 'mainwp_settings_sync_data' );

        if ( false === $sync_data_settings ) {
            $default_sync = static::get_data_sync_default();
            $sync_lists   = array_fill_keys( array_keys( $default_sync ), 1 );
            MainWP_Utility::update_option( 'mainwp_settings_sync_data', wp_json_encode( $sync_lists ) );
            return $sync_lists;
        }

        $sync_lists = ! empty( $sync_data_settings ) ? json_decode( $sync_data_settings, true ) : array();

        if ( ! is_array( $sync_lists ) ) {
            $sync_lists = array();
        }
        return $sync_lists;
    }


    /**
     * Render MainWP Tools SubPage.
     *
     * @uses \MainWP\Dashboard\MainWP_UI::render_screen_options()
     */
    public static function render_mainwp_tools() { // phpcs:ignore -- NOSONAR - complex.
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage dashboard settings', 'mainwp' ) );
            return;
        }
        static::render_header( 'MainWPTools' );
        $is_demo = MainWP_Demo_Handle::is_demo_mode();
        ?>
        <div id="mainwp-tools-settings" class="ui segment">
            <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-tools-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-tools-info-message"></i>
                    <?php printf( esc_html__( 'Use MainWP tools to adjust your MainWP Dashboard to your needs and perform specific actions when needed.  For additional help, review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/mainwp-dashboard-settings/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                </div>
            <?php endif; ?>
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-tools-info-custom-theme' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-tools-info-custom-theme"></i>
                    <div><?php esc_html_e( 'Here you can select a theme for your MainWP Dashboard.', 'mainwp' ); ?></div>
                    <div><?php esc_html_e( 'To create a custom theme, copy the `mainwp-dark-theme.css` file from the MainWP Custom Dashboard Extension located in the `css` directory, make your edits and upload the file to the `../wp-content/uploads/mainwp/custom-dashboard/` directory.', 'mainwp' ); ?></div>
                </div>
            <?php endif; ?>
        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'MainWPTools' ) ) : ?>
                    <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
                <?php endif; ?>
        <?php if ( isset( $_POST['mainwp_restore_info_messages'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'MainWPTools' ) ) : ?>
                <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Info messages have been restored successfully!', 'mainwp' ); ?></div>
            <?php endif; ?>
                <div class="ui form">
                    <form method="POST" action="">
        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'MainWPTools' ) ); ?>" />
                        <h3 class="ui dividing header">
        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-tools' ); ?>
        <?php esc_html_e( 'MainWP Dashboard Tools', 'mainwp' ); ?></h3>
        <?php
        /**
         * Action: mainwp_tools_form_top
         *
         * Fires at the top of MainWP tools form.
         *
         * @since 4.1
         */
        do_action( 'mainwp_tools_form_top' );

        $show_qsw = apply_filters( 'mainwp_show_qsw', true );

        static::get_instance()->render_select_custom_themes();

        ?>
        <?php if ( get_option( 'mainwp_not_start_encrypt_keys' ) ) { ?>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'OpenSSL Key Encryption', 'mainwp' ); ?></label>
                            <div class="ten wide column"  data-tooltip="<?php esc_attr_e( 'To enhance security, we\'ve added a feature to encrypt your private keys stored in the database.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <input type="button" name="" id="increase-connection-security-btn"  data-inverted="" data-position="top right" data-tooltip="<?php esc_attr_e( 'To enhance security, we\'ve added a feature to encrypt your private keys stored in the database.', 'mainwp' ); ?>" class="ui green basic button" value="<?php esc_attr_e( 'Encrypt Keys Now', 'mainwp' ); ?>" />
                            </div>
                        </div>
                        <?php } ?>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Force your MainWP Dashboard to establish a new connection', 'mainwp' ); ?></label>
                            <div class="ten wide column"  data-tooltip="<?php esc_attr_e( 'Force your MainWP Dashboard to reconnect with your child sites. Only needed if suggested by MainWP Support.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
        <?php
        if ( $is_demo ) {
            MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="button" disabled="disabled" class="ui green basic button disabled" value="' . esc_attr__( 'Re-establish Connections', 'mainwp' ) . '" />' );
        } else {
            ?>
                                <input type="button" name="" id="force-destroy-sessions-button"  data-inverted="" data-position="top right" data-tooltip="<?php esc_attr_e( 'Forces your dashboard to reconnect with your child sites. This feature will log out any currently logged in users on the Child sites and require them to re-log in. Only needed if suggested by MainWP Support.', 'mainwp' ); ?>" class="ui green basic button" value="<?php esc_attr_e( 'Re-establish Connections', 'mainwp' ); ?>" />
            <?php } ?>
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Force your MainWP Dashboard to set new pair of OpenSSL Keys', 'mainwp' ); ?></label>
                            <div class="ten wide column" id="mainwp-renew-connections-tool" data-content="<?php esc_attr_e( 'This will function renew connection and reconnect site right away.', 'mainwp' ); ?>" data-variation="inverted" data-position="top left">
        <?php
        if ( $is_demo ) {
                MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" disabled="disabled" class="ui button green basic disabled">' . esc_html__( 'Reset OpenSSL Key Pair', 'mainwp' ) . '</a>' );
        } else {
            ?>
                                    <a href="javascript:void(0)" onclick="mainwp_tool_renew_connections_show(); return false;" class="ui button green basic"><?php esc_html_e( 'Reset OpenSSL Key Pair', 'mainwp' ); ?></a>
                <?php } ?>
                            </div>
                        </div>
            <?php if ( $show_qsw ) { ?>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Start the MainWP Quick Setup Wizard', 'mainwp' ); ?></label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click this button to start the Quick Setup Wizard', 'mainwp' ); ?>" data-inverted="" data-position="top left"><a href="admin.php?page=mainwp-setup" class="ui green button basic" ><?php esc_html_e( 'Start Quick Setup Wizard', 'mainwp' ); ?></a></div>
                        </div>
                        <?php } ?>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Export child sites to CSV file', 'mainwp' ); ?></label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click this button to export all connected sites to a CSV file.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><a href="admin.php?page=MainWPTools&doExportSites=yes&_wpnonce=<?php echo esc_attr( wp_create_nonce( 'export_sites' ) ); ?>" class="ui button green basic"><?php esc_html_e( 'Export Child Sites', 'mainwp' ); ?></a></div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Import child sites', 'mainwp' ); ?></label>
                            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click this button to import websites to your MainWP Dashboard.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><a href="admin.php?page=managesites&do=bulknew" class="ui button green basic"><?php esc_html_e( 'Import Child Sites', 'mainwp' ); ?></a></div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Disconnect all child sites', 'mainwp' ); ?></label>
                        <div class="ten wide column" id="mainwp-disconnect-sites-tool" data-content="<?php esc_attr_e( 'This will function will break the connection and leave the MainWP Child plugin active and which makes your sites vulnerable. Use only if you attend to reconnect site to the same or a different dashboard right away.', 'mainwp' ); ?>" data-variation="inverted" data-position="top left">
            <?php
            if ( $is_demo ) {
                    MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="#" class="ui button green basic disabled" disabled="disabled">' . esc_html__( 'Disconnect Sites', 'mainwp' ) . '</a>' );
            } else {
                ?>
                            <a href="admin.php?page=MainWPTools&disconnectSites=yes&_wpnonce=<?php echo esc_attr( wp_create_nonce( 'disconnect_sites' ) ); ?>" onclick="mainwp_tool_disconnect_sites(); return false;" class="ui button green basic"><?php esc_html_e( 'Disconnect Sites', 'mainwp' ); ?></a>
                    <?php } ?>
                    </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Delete extensions API Activation data', 'mainwp' ); ?></label>
                            <div class="ten wide column" id="mainwp-clear-activation-data" data-content="<?php esc_attr_e( 'Delete extensions API activation data. This will not affect extensions settings, it just removes API activation data.', 'mainwp' ); ?>" data-variation="inverted" data-position="top left">
                                <a href="admin.php?page=MainWPTools&clearActivationData=yes&_wpnonce=<?php echo esc_attr( wp_create_nonce( 'clear_activation_data' ) ); ?>" onclick="mainwp_tool_clear_activation_data(this); return false;" class="ui button green basic"><?php esc_html_e( 'Delete Extensions API Activation Data', 'mainwp' ); ?></a>
                            </div>
                        </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Restore all info messages', 'mainwp' ); ?></label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click this button to restore all info messages in your MainWP Dashboard and Extensions.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <input type="submit" name="mainwp_restore_info_messages" id="mainwp_restore_info_messages" class="ui button" value="<?php esc_attr_e( 'Restore Info Messages', 'mainwp' ); ?>"/>
                        </div>
                    </div>

                    <h3 class="ui dividing header">
                        <?php esc_html_e( 'Privacy & Third-Party Services Permissions', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'There tools are implemented using Javascript and are subject to their respective privacy policies. You can choose which services to enable below, and update preferences at anytime.', 'mainwp' ); ?></div>
                    </h3>

                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-tools">
                        <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_enable_guided_tours', (int) get_option( 'mainwp_enable_guided_tours', 0 ) );
                            esc_html_e( 'Usetiful (Interactiive Guides & Tips)', 'mainwp' );
                            ?>
                            <span class="ui blue mini label"><?php esc_html_e( 'BETA', 'mainwp' ); ?></span>
                        </label>
                        <div class="ten wide column " data-tooltip="<?php esc_attr_e( 'Check this option to enable, or uncheck to disable MainWP guided tours.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                            <div class="ui toggle checkbox">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp-guided-tours-option" id="mainwp-guided-tours-option" <?php echo 1 === (int) get_option( 'mainwp_enable_guided_tours', 0 ) ? 'checked="true"' : ''; ?> />
                                <label><?php esc_html_e( 'Enable guided tours, tooltips, and onboarding assistance to help you navigate MainWP more efficiently.', 'mainwp' ); ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-tools">
                        <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_enable_guided_chatbase', (int) get_option( 'mainwp_enable_guided_chatbase', 0 ) );
                            esc_html_e( 'Chatbase (AI-Powered Chat Support)', 'mainwp' );
                            ?>
                            <span class="ui blue mini label"><?php esc_html_e( 'BETA', 'mainwp' ); ?></span>
                        </label>
                        <div class="ten wide column " data-tooltip="<?php esc_attr_e( 'Check this option to enable, or uncheck to disable MainWP AI drive support.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                            <div class="ui toggle checkbox">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp-guided-chatbase-option" id="mainwp-guided-chatbase-option" <?php echo 1 === (int) get_option( 'mainwp_enable_guided_chatbase', 0 ) ? 'checked="true"' : ''; ?> />
                                <label><?php esc_html_e( 'Receive AI drive support within the MainWP Dashboard for quick answers to common questions.', 'mainwp' ); ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-tools">
                        <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_enable_guided_video', (int) get_option( 'mainwp_enable_guided_video', 0 ) );
                            esc_html_e( 'Youtube Embeds (Video Tutorials)', 'mainwp' );
                            ?>
                        </label>
                        <div class="ten wide column " data-tooltip="<?php esc_attr_e( 'Check this option to enable, or uncheck to disable MainWP video tutorials.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                            <div class="ui toggle checkbox">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp-guided-video-option" id="mainwp-guided-video-option" <?php echo 1 === (int) get_option( 'mainwp_enable_guided_video', 0 ) ? 'checked="true"' : ''; ?> />
                                <label><?php esc_html_e( 'Enable embedded Youtube video tutorials within MainWP for step step-by-step guidance.', 'mainwp' ); ?></label>
                            </div>
                        </div>
                    </div>
                    <?php
                    /**
                     * Action: mainwp_tools_form_bottom
                     *
                     * Fires at the bottom of mainwp tools form.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_tools_form_bottom' );
                    ?>
                    <div class="ui divider"></div>
                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                    </form>
                </div>
            </div>
            <script type="text/javascript">
                jQuery( '#mainwp-disconnect-sites-tool' ).popup();
                jQuery( '#mainwp-clear-activation-data' ).popup();
            </script>
        <?php
        static::render_footer( 'MainWPTools' );
        MainWP_Connect_Helper::render_renew_connections_modal();
    }

    /**
     * Method is_basic_auth_dashboard_enabled.
     *
     * @return bool true|false.
     */
    public static function is_basic_auth_dashboard_enabled() { // phpcs:ignore -- NOSONAR - complex.
        $response = wp_remote_get( get_site_url() . '/wp-json' );
        $code     = wp_remote_retrieve_response_code( $response );
        if ( 404 === (int) $code || 401 === (int) $code ) {
            return true;
        }
        return false;
    }


    /**
     * Render MainWP themes selection.
     */
    public function render_select_custom_themes() { // phpcs:ignore -- NOSONAR - complex.
        $custom_theme = $this->get_current_user_theme();
        if ( false === $custom_theme ) {
            // to compatible with Custom Dashboard extension settings.
            $compat_settings = get_option( 'mainwp_custom_dashboard_settings' );
            if ( is_array( $compat_settings ) && isset( $compat_settings['theme'] ) ) {
                $compat_theme = $compat_settings['theme'];
            }
            if ( null !== $compat_theme ) {
                $custom_theme = $compat_theme;
            }
        }
        $themes_files = static::get_instance()->get_custom_themes_files();
        if ( empty( $themes_files ) ) {
            $themes_files = array();
        }
        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-tools" default-indi-value="default">
            <label class="six wide column middle aligned">
        <?php
        MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_selected_theme', $custom_theme );
        esc_html_e( 'Select MainWP Theme', 'mainwp' );
        ?>
            </label>
            <div class="ten wide column" tabindex="0" data-tooltip="<?php esc_attr_e( 'Select your MainWP Dashboard theme.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                <select name="mainwp_settings_custom_theme" id="mainwp_settings_custom_theme" class="ui dropdown selection settings-field-value-change-handler">
                    <option value="default" <?php echo ( 'default' === $custom_theme || empty( $custom_theme ) ) ? 'selected' : ''; ?>><?php esc_html_e( 'Default', 'mainwp' ); ?></option>
                    <option value="default-2024" <?php echo ( 'default-2024' === $custom_theme || empty( $custom_theme ) ) ? 'selected' : ''; ?>><?php esc_html_e( 'Default 2024', 'mainwp' ); ?></option>
                    <option value="classic" <?php echo ( 'classic' === $custom_theme ) ? 'selected' : ''; ?>><?php esc_html_e( 'Classic', 'mainwp' ); ?></option>
                    <option value="dark" <?php echo ( 'dark' === $custom_theme ) ? 'selected' : ''; ?>><?php esc_html_e( 'Dark', 'mainwp' ); ?></option>
                    <option value="wpadmin" <?php echo ( 'wpadmin' === $custom_theme ) ? 'selected' : ''; ?>><?php esc_html_e( 'WP Admin', 'mainwp' ); ?></option>
                    <option value="minimalistic" <?php echo ( 'minimalistic' === $custom_theme ) ? 'selected' : ''; ?>><?php esc_html_e( 'Minimalistic', 'mainwp' ); ?></option>
        <?php
        foreach ( $themes_files as $file_name => $theme ) {
            $theme   = ucfirst( $theme );
            $_select = '';
            if ( $custom_theme === $file_name ) {
                $_select = 'selected';
            }
            echo '<option value="' . esc_attr( $file_name ) . '" ' . esc_attr( $_select ) . '>' . esc_html( $theme ) . '</option>';
        }
        ?>
                </select>
            </div>
        </div>
        <?php
    }

    /**
     * Get custom themes files.
     */
    public function get_custom_themes_files() {
        // get themes files.
        $dirs     = $this->get_custom_theme_folder();
        $scan_dir = $dirs[0];
        $handle   = opendir( $scan_dir );
        $themes   = array();
        if ( $handle ) {
            $filename = readdir( $handle );
            while ( false !== $filename ) {
                $correct_file = true;
                if ( '.' === substr( $filename, 0, 1 ) || 'index.php' === $filename || '.css' !== substr( $filename, - 4 ) ) {
                    $correct_file = false;
                }
                if ( $correct_file ) {
                    $theme               = basename( $filename, '.css' );
                    $theme               = str_replace( '-theme', '', $theme );
                    $themes[ $filename ] = trim( $theme );
                }
                $filename = readdir( $handle ); // to while loop.
            }
            closedir( $handle );
        }
        return $themes;
    }

    /**
     * Get selected MainWP theme name.
     */
    public function get_selected_theme() {
        $selected_theme = false;

        $custom_theme = $this->get_current_user_theme();

        if ( false === $custom_theme ) {
            // to compatible with Custom Dashboard extension settings.
            $compat_settings = get_option( 'mainwp_custom_dashboard_settings' );
            if ( is_array( $compat_settings ) && isset( $compat_settings['theme'] ) ) {
                $compat_theme = $compat_settings['theme'];
            }

            if ( null !== $compat_theme ) {
                $custom_theme = $compat_theme;
            }
        }

        if ( ! empty( $custom_theme ) ) {
            if ( 'default' === $custom_theme || 'default-2024' === $custom_theme || 'dark' === $custom_theme || 'wpadmin' === $custom_theme || 'minimalistic' === $custom_theme ) {
                return $custom_theme;
            }
            $dirs      = $this->get_custom_theme_folder();
            $theme_dir = $dirs[0];
            $file      = $theme_dir . $custom_theme;
            if ( file_exists( $file ) && '.css' === substr( $file, - 4 ) ) {
                $selected_theme = $custom_theme;
            }
        }
        return $selected_theme;
    }


    /**
     * Get custom MainWP theme folder.
     */
    public function get_custom_theme_folder() {
        $dirs = MainWP_System_Utility::get_mainwp_dir();
        global $wp_filesystem;
        if ( is_array( $dirs ) && ! $wp_filesystem->exists( $dirs[0] . 'themes' ) && $wp_filesystem->exists( $dirs[0] . 'custom-dashboard' ) ) {
            // re-name the custom-dashboard folder to compatible.
            $wp_filesystem->move( $dirs[0] . 'custom-dashboard', $dirs[0] . 'themes' );
        }
        return MainWP_System_Utility::get_mainwp_dir_allow_access( 'themes' );
    }


    /**
     * Get current user's selected theme.
     */
    public function get_current_user_theme() {
        $custom_theme = get_user_option( 'mainwp_selected_theme' );
        if ( empty( $custom_theme ) ) {
            $custom_theme = get_option( 'mainwp_selected_theme', 'default' );
        }
        return $custom_theme;
    }

    /**
     * Export Child Sites and save as .csv file.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
     */
    public static function export_sites() {
        if ( isset( $_GET['doExportSites'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'export_sites' ) ) {

            $sql      = MainWP_DB::instance()->get_sql_websites_for_current_user( true );
            $websites = MainWP_DB::instance()->query( $sql );

            if ( ! $websites ) {
                die( 'Not found sites' );
            }

            $keys           = array( 'name', 'url', 'adminname', 'adminpasswd', 'wpgroups', 'uniqueId', 'http_user', 'http_pass', 'verify_certificate', 'ssl_version' );
            $allowedHeaders = array( 'site name', 'url', 'admin name', 'admin password', 'tag', 'security id', 'http username', 'http password', 'verify certificate', 'ssl version' );
            $csv            = "#\r";
            $csv           .= '# Your password is never stored by your Dashboard and never sent to MainWP.com. Once this initial connection is complete, your MainWP Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected, just be sure not to delete the admin account, as this would disrupt the connection.' . "\r";
            $csv           .= "#\r";
            $csv           .= implode( ',', $allowedHeaders ) . "\r";
            MainWP_DB::data_seek( $websites, 0 );
            while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                if ( empty( $website ) ) {
                    continue;
                }
                $row  = MainWP_Utility::map_site( $website, $keys, false );
                $csv .= '"' . implode( '","', $row ) . '"' . "\r";
            }

            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=export-sites.csv' );
            echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
            exit();
        }
    }

    /**
     * Render MainWP Email Settings SubPage.
     *
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_notification_types()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::render_edit_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::emails_general_settings_handle()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::render_all_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Template::handle_template_file_action()
     */
    public static function render_email_settings() {
        $notification_emails = MainWP_Notification_Settings::get_notification_types();
        static::render_header( 'Emails' );
        $edit_email = isset( $_GET['edit-email'] ) ? sanitize_text_field( wp_unslash( $_GET['edit-email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! empty( $edit_email ) && isset( $notification_emails[ $edit_email ] ) ) {
            $updated_templ = MainWP_Notification_Template::instance()->handle_template_file_action();
            MainWP_Notification_Settings::instance()->render_edit_settings( $edit_email, $updated_templ );
        } else {
            $updated = MainWP_Notification_Settings::emails_general_settings_handle();
            MainWP_Notification_Settings::instance()->render_all_settings( $updated );
        }
        static::render_footer( 'Emails' );
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        if ( isset( $_GET['page'] ) && ( 'Settings' === $_GET['page'] || 'SettingsAdvanced' === $_GET['page'] || 'MainWPTools' === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ?>
            <p><?php esc_html_e( 'If you need help with your MainWP Dashboard settings, please review following help documents', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-dashboard-settings/" target="_blank">MainWP Dashboard Settings</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-dashboard-settings/#updates-settings" target="_blank">Updates Settings</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-dashboard-settings/#basic-uptime-monitoring" target="_blank">Uptime Monitoring Settings</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-dashboard-settings/#site-health-monitoring" target="_blank">Site Health Settings</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-dashboard-settings/#backup-options" target="_blank">Backup Settings</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-dashboard-settings/#advanced-settings" target="_blank">Advanced Settings</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-dashboard-settings/#email-settings" target="_blank">Email Settings</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-dashboard-settings/#mainwp-tools" target="_blank">Tools</a></div>
            </div>
            <?php
            /**
             * Action: mainwp_settings_help_item
             *
             * Fires at the bottom of the help articles list in the Help sidebar on the Settings page.
             *
             * Suggested HTML markup:
             *
             * <div class="item"><a href="Your custom URL">Your custom text</a></div>
             *
             * @since 5.2
             */
            do_action( 'mainwp_settings_help_item' );

        }
    }
}
