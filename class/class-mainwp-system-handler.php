<?php
/**
 * System Handler
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_System_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_System_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.WP.AlternativeFunctions -- use system functions

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Private variable to hold the upgrade version info.
     *
     * @var null Version info.
     */
    private $upgradeVersionInfo = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_System
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_System_Handler constructor.
     *
     * Run each time the class is called.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_class_name()
     */
    public function __construct() {
        add_filter( 'mainwp-extension-enabled-check', array( MainWP_Extensions_Handler::get_class_name(), 'is_extension_enabled' ) ); // @deprecated Use 'mainwp_extension_enabled_check' instead.
        add_filter( 'mainwp_extension_enabled_check', array( MainWP_Extensions_Handler::get_class_name(), 'is_extension_enabled' ) );

        /**
         * This hook allows you to get a list of sites via the 'mainwp-getsites' filter.
         *
         * @link http://codex.mainwp.com/#mainwp-getsites
         *
         * @see \MainWP_Extensions::hook_get_sites
         */
        add_filter( 'mainwp-getsites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_sites' ), 10, 5 );     // @deprecated Use 'mainwp_getsites' instead.
        add_filter( 'mainwp-getdbsites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_db_sites' ), 10, 5 ); // @deprecated Use 'mainwp_getdbsites' instead.

        add_filter( 'mainwp_getsites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_sites' ), 10, 5 );
        add_filter( 'mainwp_getdbsites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_db_sites' ), 10, 5 );
        add_filter( 'mainwp_get_db_websites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_db_websites' ), 10, 5 );

        /**
         * This hook allows you to get a information about groups via the 'mainwp-getgroups' filter.
         *
         * @link http://codex.mainwp.com/#mainwp-getgroups
         *
         * @see \MainWP_Extensions::hook_get_groups
         */
        add_filter( 'mainwp-getgroups', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_groups' ), 10, 4 ); // @deprecated Use 'mainwp_getgroups' instead.
        add_filter( 'mainwp_getgroups', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_groups' ), 10, 4 );
        add_action( 'mainwp_fetchurlsauthed', array( &$this, 'filter_fetch_urls_authed' ), 10, 7 );
        add_filter( 'mainwp_fetchurlauthed', array( &$this, 'filter_fetch_url_authed' ), 10, 6 );
        add_filter( 'mainwp_getsqlwebsites_for_current_user', array( static::class, 'hook_get_sql_websites_for_current_user' ), 10, 4 );
        add_filter( 'mainwp_fetchurlverifyaction', array( &$this, 'hook_fetch_url_verify_action' ), 10, 4 );

        add_filter(
            'mainwp_getdashboardsites',
            array(
                MainWP_Extensions_Handler::get_class_name(),
                'hook_get_dashboard_sites',
            ),
            10,
            7
        );

        // @deprecated Use 'mainwp_manager_getextensions' instead.
        add_filter(
            'mainwp-manager-getextensions',
            array(
                MainWP_Extensions_Handler::get_class_name(),
                'hook_get_all_extensions',
            )
        );

        add_filter(
            'mainwp_manager_getextensions',
            array(
                MainWP_Extensions_Handler::get_class_name(),
                'hook_get_all_extensions',
            )
        );

        add_action( 'admin_init', array( &$this, 'admin_init' ) );

        if ( false !== get_option( 'mainwp_upgradeVersionInfo' ) && '' !== get_option( 'mainwp_upgradeVersionInfo' ) ) {
            $this->upgradeVersionInfo = get_option( 'mainwp_upgradeVersionInfo' );
            if ( ! is_object( $this->upgradeVersionInfo ) ) {
                $this->upgradeVersionInfo = new \stdClass();
            }
        }
    }

    /**
     * Method filter_fetch_urls_authed()
     *
     * Filter fetch authorized urls.
     *
     * @param mixed  $pluginFile MainWP extention.
     * @param string $key MainWP Licence Key.
     * @param object $dbwebsites Child Sites.
     * @param string $what Function to perform.
     * @param array  $params Function paramerters.
     * @param mixed  $handle Function handle.
     * @param mixed  $output Function output.
     *
     * @return mixed MainWP_Extensions_Handler::hook_fetch_urls_authed() Hook fetch authorized URLs.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::hook_fetch_urls_authed()
     */
    public function filter_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
        return MainWP_Extensions_Handler::hook_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output );
    }

    /**
     * Method filter_fetch_url_authed()
     *
     * Filter fetch Authorized URL.
     *
     * @param mixed  $pluginFile MainWP extention.
     * @param string $key MainWP licence key.
     * @param int    $websiteId Website ID.
     * @param string $what Function to perform.
     * @param array  $params Function paramerters.
     * @param null   $raw_response Raw response.
     *
     * @return mixed MainWP_Extensions_Handler::hook_fetch_url_authed() Hook fetch authorized URL.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::hook_fetch_url_authed()
     */
    public function filter_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $raw_response = null ) {
        return MainWP_Extensions_Handler::hook_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $raw_response );
    }

    /**
     * Method hook_fetch_url_verify_action()
     *
     * Filter fetch Authorized URL.
     *
     * @param mixed  $pluginFile MainWP extention.
     * @param string $childKey Extension child key.
     * @param int    $websiteId Website ID.
     * @param array  $params Function paramerters.
     *
     * @return mixed MainWP_Extensions_Handler::hook_fetch_url_authed() Hook fetch authorized URL.
     */
    public function hook_fetch_url_verify_action( $pluginFile, $childKey, $websiteId, $params ) {
        if ( ! is_array( $params ) || ! isset( $params['actionnonce'] ) ) {
            return false;
        }
        $what = 'verify_action';
        return MainWP_Extensions_Handler::hook_fetch_url_authed( $pluginFile, $childKey, $websiteId, $what, $params );
    }

    /**
     * Method apply_filter()
     *
     * Apply filter
     *
     * @param string $filter The filter.
     * @param array  $value Input value.
     *
     * @return array $output Output array.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::hook_verify()
     */
    public function apply_filters( $filter, $value = array() ) {

        if ( 'mainwp-getmetaboxes' === $filter ) {
            $output = apply_filters_deprecated( 'mainwp-getmetaboxes', array( $value ), '4.0.7.2', 'mainwp_getmetaboxes' );  // @deprecated Use 'mainwp_getmetaboxes' instead. NOSONAR - not IP.
        } else {
            $output = apply_filters( $filter, $value );
        }

        if ( ! is_array( $output ) ) {
            return array();
        }
        return $output;
    }

    /**
     * Method admin_init()
     *
     * Do nothing if current user is not an Admin.
     */
    public function admin_init() { // phpcs:ignore -- NOSONAR - complex function.
        if ( ! MainWP_System_Utility::is_admin() ) {
            return;
        }

        global $pagenow;

        if ( 'plugins.php' === $pagenow && isset( $_GET['do'] ) && 'checkUpgrade' === $_GET['do'] && ( ( time() - $this->upgradeVersionInfo->updated ) > 30 ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $this->check_upgrade();
            delete_site_transient( 'update_plugins' ); // to forced refresh 'update_plugins' transient.
            wp_safe_redirect( admin_url( 'plugins.php' ) );
            exit;
        }
    }



    /**
     * Method hook_get_sql_websites_for_current_user()
     *
     * Get sql websites for current user.
     *
     * @param mixed  $input_value First input filter value.
     * @param string $pluginFile Extension plugin file to verify.
     * @param string $key The child-key.
     * @param mixed  $params  Input params data.
     *
     * @return string sql.
     */
    public static function hook_get_sql_websites_for_current_user( $input_value, $pluginFile, $key, $params ) {
        unset( $input_value );
        if ( ! MainWP_Extensions_Handler::hook_verify( $pluginFile, $key ) ) {
            return false;
        }
        return MainWP_DB::instance()->get_sql_wp_for_current_user( $params );
    }
    /**
     * Method handle_manage_sites_screen_settings()
     *
     * Handle manage sites screen settings
     */
    public function handle_manage_sites_screen_settings() { // phpcs:ignore -- NOSONAR - required to achieve desired results, pull request solutions appreciated.
        if ( isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'ManageSitesScrOptions' ) ) {
            $show_cols = array();
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST ) ) as $key => $val ) {
                if ( false !== strpos( $key, 'mainwp_show_column_' ) ) {
                    $col               = str_replace( 'mainwp_show_column_', '', $key );
                    $show_cols[ $col ] = 1;
                }
            }
            if ( isset( $_POST['show_columns_name'] ) ) {
                foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['show_columns_name'] ) ) as $col ) {
                    if ( ! isset( $show_cols[ $col ] ) ) {
                        $show_cols[ $col ] = 0; // uncheck, hide columns.
                    }
                }
            }
            MainWP_Utility::update_option( 'mainwp_use_favicon', 1 );
            MainWP_Utility::update_option( 'mainwp_optimize', ( ! isset( $_POST['mainwp_optimize'] ) ? 0 : 1 ) );
            $user = wp_get_current_user();
            if ( $user ) {
                $val = ( isset( $_POST['mainwp_sitesviewmode'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_sitesviewmode'] ) ) : 'grid' );
                if ( 'grid' !== $val && 'table' !== $val ) {
                    $val = 'grid';
                }
                update_user_option( $user->ID, 'mainwp_sitesviewmode', $val, true );
                update_user_option( $user->ID, 'mainwp_settings_show_manage_sites_columns', $show_cols, true );
                update_option( 'mainwp_default_sites_per_page', ( isset( $_POST['mainwp_default_sites_per_page'] ) ? intval( $_POST['mainwp_default_sites_per_page'] ) : 25 ) );
            }
        } elseif ( isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'SreenshotsSitesScrOptions' ) ) {
            $user = wp_get_current_user();
            if ( $user ) {
                $val = ( isset( $_POST['mainwp_sitesviewmode'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_sitesviewmode'] ) ) : 'grid' );
                if ( 'grid' !== $val && 'table' !== $val ) {
                    $val = 'grid';
                }
                update_user_option( $user->ID, 'mainwp_sitesviewmode', $val, true );
            }
        }
    }

    /**
     * Method handle_monitoring_sites_screen_settings()
     *
     * Handle monitoring sites screen settings
     */
    public function handle_monitoring_sites_screen_settings() { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'MonitoringSitesScrOptions' ) ) {
            $show_cols = array();
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST ) ) as $key => $val ) {
                if ( false !== strpos( $key, 'mainwp_show_column_' ) ) {
                    $col               = str_replace( 'mainwp_show_column_', '', $key );
                    $show_cols[ $col ] = 1;
                }
            }
            if ( isset( $_POST['show_columns_name'] ) ) {
                foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['show_columns_name'] ) ) as $col ) {
                    if ( ! isset( $show_cols[ $col ] ) ) {
                        $show_cols[ $col ] = 0; // uncheck, hide columns.
                    }
                }
            }

            $user = wp_get_current_user();
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_settings_show_monitoring_sites_columns', $show_cols, true );
                update_option( 'mainwp_default_monitoring_sites_per_page', ( isset( $_POST['mainwp_default_monitoring_sites_per_page'] ) ? intval( $_POST['mainwp_default_monitoring_sites_per_page'] ) : 25 ) );
            }

            update_option( 'mainwp_disableSitesHealthMonitoring', ( ! isset( $_POST['mainwp_disable_sitesHealthMonitoring'] ) ? 1 : 0 ) );

            $val = isset( $_POST['mainwp_site_healthThreshold'] ) ? intval( $_POST['mainwp_site_healthThreshold'] ) : 80;
            update_option( 'mainwp_sitehealthThreshold', $val );
        }
    }

    /**
     * Method handle_clients_screen_settings()
     *
     * Handle manage clients screen settings
     */
    public function handle_clients_screen_settings() { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'ManageClientsScrOptions' ) ) {
            $show_cols = array();
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST ) ) as $key => $val ) {
                if ( false !== strpos( $key, 'mainwp_show_column_' ) ) {
                    $col               = str_replace( 'mainwp_show_column_', '', $key );
                    $show_cols[ $col ] = 1;
                }
            }
            if ( isset( $_POST['show_columns_name'] ) ) {
                foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['show_columns_name'] ) ) as $col ) {
                    if ( ! isset( $show_cols[ $col ] ) ) {
                        $show_cols[ $col ] = 0; // uncheck, hide columns.
                    }
                }
            }

            $user = wp_get_current_user();
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_settings_show_manage_clients_columns', $show_cols, true );
                update_option( 'mainwp_default_manage_clients_per_page', ( isset( $_POST['mainwp_default_manage_clients_per_page'] ) ? intval( $_POST['mainwp_default_manage_clients_per_page'] ) : 25 ) );
            }
        }
    }

    /**
     * Method handle_insights_events_screen_settings()
     *
     * Handle manage insights events screen settings
     */
    public function handle_insights_events_screen_settings() { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'ManageEventsScrOptions' ) ) {
            $show_cols = array();
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST ) ) as $key => $val ) {
                if ( false !== strpos( $key, 'mainwp_show_column_' ) ) {
                    $col               = str_replace( 'mainwp_show_column_', '', $key );
                    $show_cols[ $col ] = 1;
                }
            }
            if ( isset( $_POST['show_columns_name'] ) ) {
                foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['show_columns_name'] ) ) as $col ) {
                    if ( ! isset( $show_cols[ $col ] ) ) {
                        $show_cols[ $col ] = 0; // uncheck, hide columns.
                    }
                }
            }

            $user = wp_get_current_user();
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_settings_show_insights_events_columns', $show_cols, true );
                update_option( 'mainwp_default_manage_insights_events_per_page', ( isset( $_POST['mainwp_default_sites_per_page'] ) ? intval( $_POST['mainwp_default_sites_per_page'] ) : 25 ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=InsightsManage&message=savedscreenopts' ) );
            exit();
        }
    }

    /**
     * Method handle_clients_screen_settings()
     *
     * Handle manage clients screen settings
     */
    public function handle_updates_screen_settings() { //phpcs:ignore -- NOSONAR - complex.
        if ( isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'UpdatesScrOptions' ) ) {
            $val = ( ! isset( $_POST['mainwp_pluginAutomaticDailyUpdate'] ) ? 0 : intval( $_POST['mainwp_pluginAutomaticDailyUpdate'] ) );
            MainWP_Utility::update_option( 'mainwp_pluginAutomaticDailyUpdate', $val );
            $val = ( ! isset( $_POST['mainwp_themeAutomaticDailyUpdate'] ) ? 0 : intval( $_POST['mainwp_themeAutomaticDailyUpdate'] ) );
            MainWP_Utility::update_option( 'mainwp_themeAutomaticDailyUpdate', $val );
            $val = ( ! isset( $_POST['mainwp_transAutomaticDailyUpdate'] ) ? 0 : intval( $_POST['mainwp_transAutomaticDailyUpdate'] ) );
            MainWP_Utility::update_option( 'mainwp_transAutomaticDailyUpdate', $val );
            $val = ( ! isset( $_POST['mainwp_automaticDailyUpdate'] ) ? 0 : intval( $_POST['mainwp_automaticDailyUpdate'] ) );
            MainWP_Utility::update_option( 'mainwp_automaticDailyUpdate', $val );
            $val = ( isset( $_POST['mainwp_delay_autoupdate'] ) ? intval( $_POST['mainwp_delay_autoupdate'] ) : 1 );
            MainWP_Utility::update_option( 'mainwp_delay_autoupdate', $val );
            $val = ( ! isset( $_POST['mainwp_show_language_updates'] ) ? 0 : 1 );
            MainWP_Utility::update_option( 'mainwp_show_language_updates', $val );
            $val = ( ! isset( $_POST['mainwp_disable_update_confirmations'] ) ? 0 : intval( $_POST['mainwp_disable_update_confirmations'] ) );
            MainWP_Utility::update_option( 'mainwp_disable_update_confirmations', $val );
        }
    }


    /**
     * Method handle_mainwp_tools_settings()
     *
     * Handle mainwp tools settings.
     *
     * @uses \MainWP\Dashboard\MainWP_Twitter::clear_all_twitter_messages()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function handle_mainwp_tools_settings() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $user = wp_get_current_user();

        $update_selected_mainwp_themes = false;

        if ( isset( $_GET['page'] ) && 'MainWPTools' === $_GET['page'] && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'MainWPTools' ) ) {
            if ( isset( $_POST['mainwp_restore_info_messages'] ) && ! empty( $_POST['mainwp_restore_info_messages'] ) ) {
                delete_user_option( $user->ID, 'mainwp_notice_saved_status' );
            }
            $enabled_tours = ! isset( $_POST['mainwp-guided-tours-option'] ) ? 0 : 1;
            MainWP_Utility::update_option( 'mainwp_enable_guided_tours', $enabled_tours );

            $enabled1 = ! isset( $_POST['mainwp-guided-chatbase-option'] ) ? 0 : 1;
            MainWP_Utility::update_option( 'mainwp_enable_guided_chatbase', $enabled1 );

            $enabled2 = ! isset( $_POST['mainwp-guided-video-option'] ) ? 0 : 1;
            MainWP_Utility::update_option( 'mainwp_enable_guided_video', $enabled2 );

            if ( isset( $_POST['mainwp_settings_custom_theme'] ) ) {
                $update_selected_mainwp_themes = true;
            }
        }

        if ( isset( $_POST['wp_scr_options_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_scr_options_nonce'] ), 'MainWPSelectThemes' ) ) {
            $update_selected_mainwp_themes = true;
        }

        if ( $update_selected_mainwp_themes && isset( $_POST['mainwp_settings_custom_theme'] ) ) {
            $custom_theme = sanitize_text_field( wp_unslash( $_POST['mainwp_settings_custom_theme'] ) );
            update_user_option( $user->ID, 'mainwp_selected_theme', $custom_theme );
        }

        $update_screen_options = false;
        if ( isset( $_POST['wp_nonce'] ) && ( wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'Settings' ) || wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'MainWPScrOptions' ) ) ) {
            $update_screen_options = true;
        }

        if ( $update_screen_options ) {
            $show_wids = array();
            if ( isset( $_POST['mainwp_show_widgets'] ) && is_array( $_POST['mainwp_show_widgets'] ) ) {
                $selected_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_show_widgets'] ) );
                foreach ( $selected_wids as $name ) {
                    $show_wids[ $name ] = 1;
                }
            }

            if ( isset( $_POST['mainwp_widgets_name'] ) && is_array( $_POST['mainwp_widgets_name'] ) ) {
                $name_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_widgets_name'] ) );
                foreach ( $name_wids as $name ) {
                    if ( ! isset( $show_wids[ $name ] ) ) {
                        $show_wids[ $name ] = 0;
                    }
                }
            }

            $show_cols = array();
            if ( isset( $_POST['mainwp_show_columns'] ) && is_array( $_POST['mainwp_show_columns'] ) ) {
                $selected_cols = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_show_columns'] ) );
                foreach ( $selected_cols as $name ) {
                    $show_cols[ $name ] = 1;
                }
            }

            if ( isset( $_POST['mainwp_columns_name'] ) && is_array( $_POST['mainwp_columns_name'] ) ) {
                $name_cols = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_columns_name'] ) );
                foreach ( $name_cols as $name ) {
                    if ( ! isset( $show_cols[ $name ] ) ) {
                        $show_cols[ $name ] = 0;
                    }
                }
            }

            $val = ( isset( $_POST['mainwp_sidebarPosition'] ) ? intval( $_POST['mainwp_sidebarPosition'] ) : 1 );
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_settings_show_widgets', $show_wids, true );
                update_user_option( $user->ID, 'mainwp_sidebarPosition', $val, true );

                if ( isset( $_POST['mainwp_manageposts_show_columns_settings'] ) ) {
                    update_user_option( $user->ID, 'mainwp_manageposts_show_columns', $show_cols, true );
                }

                if ( isset( $_POST['mainwp_managepages_show_columns_settings'] ) ) {
                    update_user_option( $user->ID, 'mainwp_managepages_show_columns', $show_cols, true );
                }

                if ( isset( $_POST['mainwp_manageusers_show_columns_settings'] ) ) {
                    update_user_option( $user->ID, 'mainwp_manageusers_show_columns', $show_cols, true );
                }
            }

            MainWP_Utility::update_option( 'mainwp_hide_update_everything', ( ! isset( $_POST['hide_update_everything'] ) ? 0 : 1 ) );

            if ( isset( $_POST['reset_overview_settings'] ) && ! empty( $_POST['reset_overview_settings'] ) && isset( $_POST['reset_overview_which_settings'] ) && 'overview_settings' === $_POST['reset_overview_which_settings'] ) {
                update_user_option( $user->ID, 'mainwp_widgets_sorted_toplevel_page_mainwp_tab', false, true );
                update_user_option( $user->ID, 'mainwp_widgets_sorted_mainwp_page_managesites', false, true );
            }
        }

        $update_clients_screen_options = false;
        if ( isset( $_POST['wp_scr_options_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_scr_options_nonce'] ), 'MainWPClientsScrOptions' ) ) {
            $update_clients_screen_options = true;
        }
        if ( $update_clients_screen_options ) {

            $show_wids = array();
            if ( isset( $_POST['mainwp_show_widgets'] ) && is_array( $_POST['mainwp_show_widgets'] ) ) {
                $selected_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_show_widgets'] ) );
                foreach ( $selected_wids as $name ) {
                    $show_wids[ $name ] = 1;
                }
            }

            if ( isset( $_POST['mainwp_widgets_name'] ) && is_array( $_POST['mainwp_widgets_name'] ) ) {
                $name_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_widgets_name'] ) );
                foreach ( $name_wids as $name ) {
                    if ( ! isset( $show_wids[ $name ] ) ) {
                        $show_wids[ $name ] = 0;
                    }
                }
            }

            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_clients_show_widgets', $show_wids, true );
            }

            if ( isset( $_POST['reset_client_overview_settings'] ) && ! empty( $_POST['reset_client_overview_settings'] ) ) {
                update_user_option( $user->ID, 'mainwp_widgets_sorted_mainwp_page_manageclients', false, true );
            }
        }
    }

    /**
     * Method handle_settings_post()
     *
     * Handle saving settings page.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Handler::handle_settings_post()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
     * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::handle_settings_post()
     * @uses \MainWP\Dashboard\MainWP_Settings::handle_settings_post()
     */
    public function handle_settings_post() { // phpcs:ignore -- NOSONAR - complex method.

        if ( isset( $_GET['page'] ) && ( isset( $_POST['wp_nonce'] ) || isset( $_POST['wp_scr_options_nonce'] ) ) ) {
            $this->include_pluggable();
            $this->handle_mainwp_tools_settings();
            $this->handle_manage_sites_screen_settings();
            $this->handle_monitoring_sites_screen_settings();
            $this->handle_clients_screen_settings();
            $this->handle_updates_screen_settings();
            $this->handle_insights_events_screen_settings();
        }

        if ( isset( $_POST['select_mainwp_options_siteview'] ) ) {
            $this->include_pluggable();
            if ( check_admin_referer( 'mainwp-admin-nonce' ) ) {
                $userExtension            = MainWP_DB_Common::instance()->get_user_extension();
                $userExtension->site_view = ( empty( $_POST['select_mainwp_options_siteview'] ) ? MAINWP_VIEW_PER_PLUGIN_THEME : intval( $_POST['select_mainwp_options_siteview'] ) );
                MainWP_DB_Common::instance()->update_user_extension( $userExtension );
            }
        }

        if ( isset( $_POST['select_mainwp_options_plugintheme_view'] ) ) {
            $this->include_pluggable();
            if ( check_admin_referer( 'mainwp-admin-nonce' ) ) {
                $view_per = ( empty( $_POST['select_mainwp_options_plugintheme_view'] ) ? MAINWP_VIEW_PER_PLUGIN_THEME : intval( $_POST['select_mainwp_options_plugintheme_view'] ) );
                $which    = isset( $_POST['whichview'] ) ? sanitize_text_field( wp_unslash( $_POST['whichview'] ) ) : '';
                if ( 'plugin' === $which ) {
                    MainWP_Utility::update_user_option( 'mainwp_manage_plugin_view', $view_per );
                } else {
                    MainWP_Utility::update_user_option( 'mainwp_manage_theme_view', $view_per );
                }
            }
        }

        if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) ) {
            $this->include_pluggable();
            if ( wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'Settings' ) ) {
                $updated  = MainWP_Settings::handle_settings_post();
                $updated |= MainWP_Backup_Handler::handle_settings_post();
                $updated |= MainWP_Monitoring_Handler::handle_settings_post();
                $msg      = '';
                if ( $updated ) {
                    $msg = '&message=saved';
                }
                wp_safe_redirect( admin_url( 'admin.php?page=Settings' . $msg ) );
                exit();
            }
        }

        if ( isset( $_POST['mainwp_sidebar_position'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'onchange_sidebarposition' ) ) {
            $val  = isset( $_POST['mainwp_sidebar_position'] ) && ! empty( $_POST['mainwp_sidebar_position'] ) ? 1 : 0;
            $user = wp_get_current_user();
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_sidebarPosition', $val, true );
            }
            return true;
        }

        if ( isset( $_GET['viewmode'] ) && isset( $_GET['modenonce'] ) ) {
            $viewmode = sanitize_text_field( wp_unslash( $_GET['viewmode'] ) );
            $nonce    = sanitize_key( $_GET['modenonce'] );
            if ( ( 'table' === $viewmode || 'grid' === $viewmode ) && wp_verify_nonce( sanitize_key( $nonce ), 'viewmode' ) ) {
                $user = wp_get_current_user();
                if ( $user ) {
                    update_user_option( $user->ID, 'mainwp_sitesviewmode', $viewmode, true );
                    wp_safe_redirect( admin_url( 'admin.php?page=managesites' ) );
                    exit;
                }
            }
        }
    }

    /**
     * Method include_pluggable()
     *
     * Include pluggable functions.
     */
    public function include_pluggable() {
        // may causing of conflict with Post S m t p plugin.
        if ( ! function_exists( 'wp_create_nonce' ) ) {
            include_once ABSPATH . WPINC . '/pluggable.php'; // NOSONAR - WP compatible.
        }
    }

    /**
     * Method plugins_api_extension_info()
     *
     * Get MainWP Extension api information.
     *
     * @param mixed $input_value Return value.
     * @param mixed $action Action being performed.
     * @param mixed $arg Action arguments. Should be the plugin slug.
     *
     * @return mixed $info|$input_value
     *
     * @uses \MainWP\Dashboard\MainWP_API_Handler::get_update_information()
     * @uses \MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions()
     * @uses \MainWP\Dashboard\MainWP_System::get_plugin_slug()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_slugs()
     */
    public function plugins_api_extension_info( $input_value, $action, $arg ) { // phpcs:ignore -- NOSONAR - complex.
        if ( 'plugin_information' !== $action ) {
            return $input_value;
        }

        if ( is_array( $arg ) ) {
            $arg = (object) $arg;
        }

        if ( ! isset( $arg->slug ) || ( '' === $arg->slug ) ) {
            return $input_value;
        }

        if ( dirname( MainWP_System::instance()->get_plugin_slug() ) === $arg->slug ) {
            return $input_value;
        }

        $result   = MainWP_Extensions_Handler::get_slugs();
        $am_slugs = $result['am_slugs'];

        if ( '' !== $am_slugs ) {
            $am_slugs = explode( ',', $am_slugs );
            if ( false !== strpos( $arg->slug, '/' ) ) { // to fix.
                $dir_slug = dirname( $arg->slug );
            } else {
                $dir_slug = $arg->slug;
            }
            if ( in_array( $dir_slug, $am_slugs ) ) {
                $info = MainWP_API_Handler::get_update_information( $dir_slug );
                if ( is_object( $info ) && property_exists( $info, 'sections' ) ) {
                    if ( ! is_array( $info->sections ) || ! isset( $info->sections['changelog'] ) || empty( $info->sections['changelog'] ) ) {
                        $exts_data = MainWP_Extensions_View::get_available_extensions();
                        if ( isset( $exts_data[ $arg->slug ] ) ) {
                            $ext_info                    = $exts_data[ $arg->slug ];
                            $changelog_link              = rtrim( $ext_info['changelog_url'], '/' );
                            $info->sections['changelog'] = '<a href="' . $changelog_link . '" target="_blank">' . $changelog_link . '</a>';
                        }
                    }
                    return $info;
                }
                return $info;
            }
        }

        return $input_value;
    }

    /**
     * Method plugins_api_wp_plugins_api_result()
     *
     * Hook after get plugins api information.
     *
     * @param mixed $res api information value.
     * @param mixed $action Action being performed.
     * @param mixed $arg Action arguments. Should be the plugin slug.
     *
     * @return mixed $res
     */
    public function plugins_api_wp_plugins_api_result( $res, $action, $arg ) { // phpcs:ignore -- NOSONAR - complex.

        if ( 'plugin_information' !== $action ) {
            return $res;
        }

        if ( is_object( $res ) && property_exists( $res, 'slug' ) && property_exists( $res, 'sections' ) ) {
            return $res;
        }

        if ( ! isset( $_GET['wpplugin'] ) || ! is_numeric( $_GET['wpplugin'] ) || empty( $_GET['wpplugin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return $res;
        }

        if ( is_array( $arg ) ) {
            $arg = (object) $arg;
        }

        if ( ! isset( $arg->slug ) || ( '' === $arg->slug ) ) {
            return $res;
        }

        $site_id = intval( $_GET['wpplugin'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( $site_id ) {
            $website = MainWP_DB::instance()->get_website_by_id( $site_id );
            if ( $website && ! empty( $website->plugin_upgrades ) ) {
                $plugin_upgrades = json_decode( $website->plugin_upgrades, true );

                if ( is_array( $plugin_upgrades ) ) {
                    $found_update   = false;
                    $empty_sections = false;
                    foreach ( $plugin_upgrades as $plugin_slug => $info ) {
                        if ( false !== strpos( $plugin_slug, $arg->slug ) && isset( $info['update'] ) ) {
                            $found_update = true;
                            if ( isset( $info['update']['slug'] ) && $arg->slug === $info['update']['slug'] && isset( $info['update']['new_version'] ) && ! empty( $info['update']['new_version'] ) && isset( $info['update']['sections'] ) && ! empty( $info['update']['sections'] ) ) {
                                $info_update           = (object) $info['update'];
                                $info_update->external = false;
                                return $info_update;
                            }
                            $empty_sections = true;
                            break;
                        }
                    }

                    if ( $found_update && $empty_sections ) {
                        try {
                            $info = MainWP_Connect::fetch_url_authed(
                                $website,
                                'plugin_action',
                                array(
                                    'action' => 'changelog_info', // try to get changelog from the child site.
                                    'slug'   => $arg->slug,
                                )
                            );
                            if ( is_array( $info ) && isset( $info['update'] ) && ! empty( $info['update']['sections'] ) ) {
                                $info_update           = (object) $info['update'];
                                $info_update->external = true;
                                return $info_update;
                            }
                        } catch ( \Exception $e ) {
                            // error happen.
                        }
                    }
                }
            }
        }

        return $res;
    }

    /**
     * Method check_update_custom()
     *
     * Check MainWP Extensions for updates.
     *
     * @param object $transient Transient information.
     *
     * @return object $transient Transient information.
     *
     * @uses \MainWP\Dashboard\MainWP_API_Handler::get_upgrade_information()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_indexed_extensions_infor()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extension_slug()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function check_update_custom( $transient ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        if ( isset( $_POST['action'] ) && ( ( 'update-plugin' === $_POST['action'] ) || ( 'update-selected' === $_POST['action'] ) ) && is_object( $transient ) && property_exists( $transient, 'response' ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $extensions = MainWP_Extensions_Handler::get_indexed_extensions_infor( array( 'activated' => true ) );
            if ( defined( 'DOING_AJAX' ) && isset( $_POST['plugin'] ) && 'update-plugin' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

                if ( empty( $transient->response ) ) {
                    $transient->response = array();
                }

                $plugin_slug = sanitize_text_field( wp_unslash( $_POST['plugin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( isset( $extensions[ $plugin_slug ] ) ) {

                    if ( ! isset( $transient->response[ $plugin_slug ] ) || ! is_object( $transient->response[ $plugin_slug ] ) || ! property_exists( $transient->response[ $plugin_slug ], 'new_version' ) ) {
                        return $transient;
                    }

                    if ( isset( $transient->response[ $plugin_slug ] ) && version_compare( $transient->response[ $plugin_slug ]->new_version, $extensions[ $plugin_slug ]['version'], '=' ) ) {
                        return $transient;
                    }

                    $api_slug = dirname( $plugin_slug );
                    $rslt     = MainWP_API_Handler::get_upgrade_information( $api_slug );

                    if ( ! empty( $rslt ) && is_object( $rslt ) && property_exists( $rslt, 'new_version' ) && ! empty( $rslt->new_version ) && version_compare( $rslt->new_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
                        $transient->response[ $plugin_slug ] = static::map_rslt_obj( $rslt );
                    }

                    return $transient;
                }
            } elseif ( 'update-selected' === $_POST['action'] && isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

                if ( empty( $transient->response ) ) {
                    $transient->response = array();
                }

                $updated = false;
                foreach ( wp_unslash( $_POST['checked'] ) as $plugin_slug ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    if ( isset( $extensions[ $plugin_slug ] ) ) {

                        if ( ! isset( $transient->response[ $plugin_slug ] ) ) {
                            $transient->response[ $plugin_slug ] = new \stdClass();
                        }

                        if ( isset( $transient->response[ $plugin_slug ] ) && version_compare( $transient->response[ $plugin_slug ]->new_version, $extensions[ $plugin_slug ]['version'], '=' ) ) {
                            continue;
                        }
                        $api_slug = dirname( $plugin_slug );
                        $rslt     = MainWP_API_Handler::get_upgrade_information( $api_slug );
                        if ( ! empty( $rslt ) && is_object( $rslt ) && property_exists( $rslt, 'new_version' ) && ! empty( $rslt->new_version ) && version_compare( $rslt->new_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
                            $this->upgradeVersionInfo->result[ $api_slug ] = $rslt;
                            $transient->response[ $plugin_slug ]           = static::map_rslt_obj( $rslt );
                            $updated                                       = true;
                        }
                    }
                }
                if ( $updated ) {
                    MainWP_Utility::update_option( 'mainwp_upgradeVersionInfo', $this->upgradeVersionInfo );
                }

                return $transient;
            }
        }

        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        if ( ! empty( $this->upgradeVersionInfo ) && property_exists( $this->upgradeVersionInfo, 'result' ) && is_array( $this->upgradeVersionInfo->result ) ) {
            foreach ( $this->upgradeVersionInfo->result as $rslt ) {
                if ( ! isset( $rslt->slug ) ) {
                    continue;
                }

                $plugin_slug = MainWP_Extensions_Handler::get_extension_slug( $rslt->slug );
                if ( isset( $transient->checked[ $plugin_slug ] ) && property_exists( $rslt, 'new_version' ) && ! empty( $rslt->new_version ) && version_compare( $rslt->new_version, $transient->checked[ $plugin_slug ], '>' ) ) {
                    $transient->response[ $plugin_slug ] = static::map_rslt_obj( $rslt );
                }
            }
        }

        return $transient;
    }


    /**
     * Method map_rslt_obj()
     *
     * Map resulting object.
     *
     * @param object $result Resulting information.
     *
     * @return object $obj Mapped resulting object.
     */
    public static function map_rslt_obj( $result ) {
        $obj              = new \stdClass();
        $obj->slug        = $result->slug;
        $obj->new_version = $result->new_version;
        $obj->url         = 'https://mainwp.com/';
        $obj->package     = $result->package;

        return $obj;
    }

    /**
     * Method check_upgrade()
     *
     * Check if Extension has an update.
     *
     * @uses \MainWP\Dashboard\MainWP_API_Handler::check_exts_upgrade()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    private function check_upgrade() {
        $result = MainWP_API_Handler::check_exts_upgrade();
        if ( null === $this->upgradeVersionInfo ) {
            $this->upgradeVersionInfo = new \stdClass();
        }
        $this->upgradeVersionInfo->updated = time();
        if ( ! empty( $result ) ) {
            $this->upgradeVersionInfo->result = $result;
        }
        MainWP_Utility::update_option( 'mainwp_upgradeVersionInfo', $this->upgradeVersionInfo );
    }

    /**
     * Method pre_check_update_custom()
     *
     * Pre-check for extension updates.
     *
     * @param object $transient Transient information.
     *
     * @return object $transient Transient information.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_indexed_extensions_infor()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extension_slug()
     */
    public function pre_check_update_custom( $transient ) {
        if ( ! isset( $transient->checked ) ) {
            return $transient;
        }

        if ( ( null === $this->upgradeVersionInfo ) || ! property_exists( $this->upgradeVersionInfo, 'updated' ) || ( ( time() - $this->upgradeVersionInfo->updated ) > 60 ) ) {
            $this->check_upgrade();
        }

        if ( empty( $transient->response ) ) {
            $transient->response = array();
        }

        if ( ! empty( $this->upgradeVersionInfo ) && property_exists( $this->upgradeVersionInfo, 'result' ) && is_array( $this->upgradeVersionInfo->result ) ) {
            $extensions = MainWP_Extensions_Handler::get_indexed_extensions_infor( array( 'activated' => true ) );
            foreach ( $this->upgradeVersionInfo->result as $rslt ) {
                $plugin_slug = MainWP_Extensions_Handler::get_extension_slug( $rslt->slug );
                if ( isset( $extensions[ $plugin_slug ] ) && property_exists( $rslt, 'new_version' ) && ! empty( $rslt->new_version ) && version_compare( $rslt->new_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
                    $transient->response[ $plugin_slug ] = static::map_rslt_obj( $rslt );
                }
            }
        }

        return $transient;
    }

    /**
     * Method upload_file()
     *
     * Upload a file.
     *
     * @param mixed $file File to upload.
     *
     * @return void
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::ends_with()
     */
    public function upload_file( $file ) {
        header( 'Content-Description: File Transfer' );
        $dwl_fname = basename( $file );
        if ( 'tar.gz' === $dwl_fname || '.tar.gz' === $dwl_fname ) {
            $dwl_fname = 'noname.tar.gz'; // to fix name of zip file.
        }
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $dwl_fname . '"' );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        header( 'Content-Length: ' . filesize( $file ) );
        while ( ob_get_level() ) {
            ob_end_clean();
        }
        $this->readfile_chunked( $file );
        exit();
    }

    /**
     * Method readfile_chunked()
     *
     * Read Chunked File.
     *
     * @param mixed $filename Name of file.
     *
     * @return mixed echo $buffer|false|$handle.
     */
    public function readfile_chunked( $filename ) {
        $chunksize = 1024;
        $handle    = fopen( $filename, 'rb' );
        if ( false === $handle ) {
            return false;
        }

        while ( ! feof( $handle ) ) {
            $buffer = fread( $handle, $chunksize );
            echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ( ob_get_length() ) {
                ob_flush();
                flush();
            }
            $buffer = null;
        }

        return fclose( $handle );
    }

    /**
     * Method activate_redirection()
     *
     * Redirect after activating MainWP Extension.
     *
     * @param mixed $location Location to redirect to.
     *
     * @return $location Admin URL + the page to redirect to.
     */
    public function activate_redirect( $location ) {
        unset( $location );
        return admin_url( 'admin.php?page=Extensions' );
    }

    /**
     * Method activate_extension()
     *
     * Activate MainWP Extension.
     *
     * @param mixed $ext_key Extension API Key.
     * @param array $info Extension Info.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::set_activation_info()
     * @uses \MainWP\Dashboard\MainWP_Api_Manager_Password_Management::generate_password()
     */
    public function activate_extension( $ext_key, $info = array() ) {

        add_filter( 'wp_redirect', array( $this, 'activate_redirect' ) );

        if ( is_array( $info ) && isset( $info['product_id'] ) && isset( $info['software_version'] ) ) {
            $act_info = array(
                'product_id'       => $info['product_id'],
                'software_version' => $info['software_version'],
                'activated_key'    => 'Deactivated',
                'instance_id'      => MainWP_Api_Manager_Password_Management::generate_password( 12, false ),
            );
            MainWP_Api_Manager::instance()->set_activation_info( $ext_key, $act_info );
        }
    }

    /**
     * Method deactivate_extension()
     *
     * Deactivate MaiNWP Extension.
     *
     * @param mixed $ext_key Exnension API Key.
     * @param bool  $dashboard_only Deactive API Key on dashboard only.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::set_activation_info()
     */
    public function deactivate_extension( $ext_key, $dashboard_only = false ) {
        // try to deactivate license.
        if ( ! $dashboard_only ) {
            $mainwp_api_key = MainWP_Api_Manager_Key::instance()->get_decrypt_master_api_key();
            MainWP_Api_Manager::instance()->license_key_deactivation( $ext_key, $mainwp_api_key );
        }

        MainWP_Api_Manager::instance()->remove_activation_info( $ext_key );
    }
}
