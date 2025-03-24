<?php
/**
 * MainWP Manage Sites Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Sites
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Sites { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * The single instance of the class
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Private static variable to hold the total sites.
     *
     * @var mixed Default null
     */
    private static $total_sites = null;

    /**
     * Get instance.
     *
     *  @return static::singlton
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Setup_Wizard constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        $this->get_total_sites();
    }

    /**
     * Get instance.
     *
     *  @return static::singlton
     */
    public static function get_total_sites() {
        if ( null === static::$total_sites ) {
            static::$total_sites = MainWP_DB::instance()->get_websites_count();
        }
        return static::$total_sites;
    }

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Sub pages
     *
     * @static
     * @var array $subPages Sub pages.
     */
    public static $subPages;

    /**
     * Current page.
     *
     * @static
     * @var string $page Current page.
     */
    public static $page;

    /**
     * Widgets to enable.
     *
     * @static
     * @var array $enable_widgets Widgets to enable.
     */
    private static $enable_widgets = array(
        'overview'                        => true,
        'recent_posts'                    => true,
        'recent_pages'                    => true,
        'security_issues'                 => true,
        'manage_backups'                  => true,
        'plugins'                         => true,
        'themes'                          => true,
        'notes'                           => true,
        'site_note'                       => true,
        'client_info'                     => true,
        'non_mainwp_changes'              => true,
        'uptime_monitoring_info'          => true,
        'uptime_monitoring_response_time' => true,
    );

    /**
     * Magage Sites table
     *
     * @var $sitesTable Magage Sites table.
     */
    public static $sitesTable;

    /**
     * Method init()
     *
     * Initiate Manage Sites.
     *
     * @uses \MainWP\Dashboard\MainWP_Security_Issues::get_class_name()
     */
    public static function init() {
        /**
         * This hook allows you to render the Sites page header via the 'mainwp-pageheader-sites' action.
         *
         * @link http://codex.mainwp.com/#mainwp-pageheader-sites
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-sites'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-sites
         *
         * @see \MainWP_Manage_Sites::render_header
         */
        add_action( 'mainwp-pageheader-sites', array( static::get_class_name(), 'render_header' ) ); // @deprecated Use 'mainwp_pageheader_sites' instead.
        add_action( 'mainwp_pageheader_sites', array( static::get_class_name(), 'render_header' ) );

        /**
         * This hook allows you to render the Sites page footer via the 'mainwp-pagefooter-sites' action.
         *
         * @link http://codex.mainwp.com/#mainwp-pagefooter-sites
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-sites'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-sites
         *
         * @see \MainWP_Manage_Sites::render_footer
         */
        add_action( 'mainwp-pagefooter-sites', array( static::get_class_name(), 'render_footer' ) ); // @deprecated Use 'mainwp_pagefooter_sites' instead.
        add_action( 'mainwp_pagefooter_sites', array( static::get_class_name(), 'render_footer' ) );

        add_action( 'mainwp_securityissues_sites', array( MainWP_Security_Issues::get_class_name(), 'render' ) );
        add_action( 'mainwp_manage_sites_edit', array( static::get_class_name(), 'on_edit_site' ) );

        // Hook the Help Sidebar content.
        add_action( 'mainwp_help_sidebar_content', array( static::get_class_name(), 'mainwp_help_content' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_managesites_add_edit_site_upload_site_icon', array( static::class, 'ajax_upload_icon' ) );
    }

    /**
     * Method on_screen_layout_columns()
     *
     * Columns to display.
     *
     * @param mixed $columns Columns to display.
     * @param mixed $screen Current page.
     *
     * @return array $columns
     */
    public static function on_screen_layout_columns( $columns, $screen ) {
        if ( $screen === static::$page ) {
            $columns[ static::$page ] = 3;
        }

        return $columns;
    }

    /**
     * Initiate menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::init_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_Site_Open::get_class_name()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function init_menu() { // phpcs:ignore -- NOSONAR - complex.
        static::$page = MainWP_Manage_Sites_View::init_menu();
        add_action( 'load-' . static::$page, array( static::get_class_name(), 'on_load_page' ) );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_REQUEST['dashboard'] ) ) {
         // phpcs:enable
            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            delete_user_option( $current_user->ID, 'screen_layout_toplevel_page_managesites' );
            add_filter( 'screen_layout_columns', array( static::get_class_name(), 'on_screen_layout_columns' ), 10, 2 );

            $val = get_user_option( 'screen_layout_' . static::$page );
            if ( ! MainWP_Utility::ctype_digit( $val ) ) {
                global $current_user;
                update_user_option( $current_user->ID, 'screen_layout_' . static::$page, 2, true );
            }
        }
        add_submenu_page(
            'mainwp_tab',
            __( 'Sites', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Sites', 'mainwp' ) . '</div>',
            'read',
            'SiteOpen',
            array(
                MainWP_Site_Open::get_class_name(),
                'render',
            )
        );
        add_submenu_page(
            'mainwp_tab',
            __( 'Sites', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Sites', 'mainwp' ) . '</div>',
            'read',
            'SiteRestore',
            array(
                MainWP_Site_Open::get_class_name(),
                'render_restore',
            )
        );

        /**
         * Sites Subpages
         *
         * Filters subpages for the Sites page.
         *
         * @since Unknown
         */
        $sub_pages        = array();
        $sub_pages        = apply_filters_deprecated( 'mainwp-getsubpages-sites', array( $sub_pages ), '4.0.7.2', 'mainwp_getsubpages_sites' ); // @deprecated Use 'mainwp_getsubpages_sites' instead. NOSONAR - not IP.
        static::$subPages = apply_filters( 'mainwp_getsubpages_sites', $sub_pages );

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( ! empty( $subPage['no_submenu_page'] ) ) {
                    continue;
                }
                if ( ! isset( $subPage['slug'] ) && ! isset( $subPage['title'] ) ) {
                    continue;
                }
                if ( MainWP_Menu::is_disable_menu_item( 2, 'ManageSites' . $subPage['slug'] ) ) {
                    continue;
                }
                $_page = add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageSites' . $subPage['slug'], $subPage['callback'] );
                add_action( 'load-' . $_page, array( static::get_class_name(), 'on_load_subpages' ), 9 );
                if ( isset( $subPage['on_load_callback'] ) && ! empty( $subPage['on_load_callback'] ) ) {
                    add_action( 'load-' . $_page, $subPage['on_load_callback'] );
                }
            }
        }

        MainWP_Manage_Sites_View::init_left_menu( static::$subPages );
    }

    /**
     * Initiate sub page menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::init_subpages_menu()
     */
    public static function init_subpages_menu() {
        MainWP_Manage_Sites_View::init_subpages_menu( static::$subPages );
    }

    /**
     * Method on_load_page()
     *
     * Run on page load.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_List_Table
     * @uses \MainWP\Dashboard\MainWP_System::enqueue_postbox_scripts()
     */
    public static function on_load_page() {

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_REQUEST['dashboard'] ) ) {
            static::on_load_page_dashboard();
            return;
        }

        MainWP_System::enqueue_postbox_scripts();

        if ( isset( $_REQUEST['do'] ) ) {
            if ( 'new' === $_REQUEST['do'] || 'bulknew' === $_REQUEST['do'] ) {
                return;
            }
        } elseif ( isset( $_GET['id'] ) || isset( $_GET['scanid'] ) || isset( $_GET['backupid'] ) || isset( $_GET['updateid'] ) || isset( $_GET['monitor_wpid'] ) || isset( $_GET['emailsettingsid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
            return;
        }
        // phpcs:enable

        $sitesViewMode = MainWP_Utility::get_siteview_mode();

        add_filter( 'mainwp_header_actions_right', array( static::get_class_name(), 'screen_options' ), 10, 2 );
        if ( 'grid' !== $sitesViewMode ) {
            static::load_sites_table();
        } else {
            MainWP_Manage_Screenshots::get_instance(); // to init hooks.
        }
    }


    /**
     * Method load_sites_table()
     *
     * Load sites table.
     */
    public static function load_sites_table() {
        if ( empty( static::$sitesTable ) ) {
            static::$sitesTable = new MainWP_Manage_Sites_List_Table();
        }
    }


    /**
     * Method on_load_sunpages()
     *
     * Run on subpage load.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
     */
    public static function on_load_subpages() {
        if ( ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
            MainWP_System_Utility::set_current_wpid( intval( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,ized
        }
    }

    /**
     * Method render_header()
     *
     * Render page header.
     *
     * @param string $shownPage Current page slug.
     *
     * @uses \MainWP\Dashboard\MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_header()
     */
    public static function render_header( $shownPage = '' ) {
        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
        MainWP_Manage_Sites_View::render_header( $shownPage, static::$subPages );
    }

    /**
     * Method render_footer()
     *
     * Render page footer.
     */
    public static function render_footer() {
        MainWP_Manage_Sites_View::render_footer();
    }

    /**
     * Method screen_options()
     *
     * Create Page Settings button.
     *
     * @param mixed $input Page Settings button HTML.
     *
     * @return mixed Page Settings button.
     */
    public static function screen_options( $input ) {
        return $input .
                '<a class="ui button basic icon" onclick="mainwp_manage_sites_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
                    <i class="cog icon"></i>
                </a>';
    }

    /**
     * Method render_screen_options()
     *
     * Render Page Settings Modal.
     */
    public static function render_screen_options() {  // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $columns = static::$sitesTable->get_columns();

        if ( isset( $columns['cb'] ) ) {
            unset( $columns['cb'] );
        }

        if ( isset( $columns['status'] ) ) {
            $columns['status'] = esc_html__( 'Status', 'mainwp' );
        }

        if ( isset( $columns['favicon'] ) ) {
            $columns['favicon'] = esc_html__( 'Favicon', 'mainwp' );
        }

        $list_items = array(
            'login'                    => esc_html__( 'Jump to WP Admin', 'mainwp' ),
            'update'                   => esc_html__( 'Updates', 'mainwp' ),
            'wpcore_update'            => esc_html__( 'WP updates', 'mainwp' ),
            'plugin_update'            => esc_html__( 'Plugin updates', 'mainwp' ),
            'theme_update'             => esc_html__( 'Theme updates', 'mainwp' ),
            'site-cost-tracker'        => esc_html__( 'Cost Tracker', 'mainwp' ),
            'note'                     => esc_html__( 'Notes', 'mainwp' ),
            'site_preview'             => esc_html__( 'Site preview', 'mainwp' ),
            'time-tracker-tasks'       => esc_html__( 'Time Tracker tasks', 'mainwp' ),
            'lighthouse_desktop_score' => esc_html__( 'Lighthouse desktop score', 'mainwp' ),
            'lighthouse_mobile_score'  => esc_html__( 'Lighthouse mobile score', 'mainwp' ),
        );

        foreach ( $list_items as $idx => $title ) {
            if ( isset( $columns[ $idx ] ) ) {
                $columns[ $idx ] = $title;
            }
        }

        $sites_per_page = get_option( 'mainwp_default_sites_per_page', 25 );

        if ( isset( $columns['site_actions'] ) && empty( $columns['site_actions'] ) ) {
            $columns['site_actions'] = esc_html__( 'Actions', 'mainwp' );
        }

        $show_cols = get_user_option( 'mainwp_settings_show_manage_sites_columns' );
        if ( false === $show_cols ) { // to backwards.
            $show_cols = array();
            foreach ( $columns as $name => $title ) {
                if ( in_array( $name, array( 'status', 'favicon', 'site_combo', 'update', 'client_name', 'security', 'index', 'backup', 'site_actions' ) ) ) {
                    $show_cols[ $name ] = 1;
                } else {
                    $show_cols[ $name ] = 0;
                }
            }
            $user = wp_get_current_user();
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_settings_show_manage_sites_columns', $show_cols, true );
            }
        }

        if ( ! is_array( $show_cols ) ) {
            $show_cols = array();
        }

        $siteViewMode = MainWP_Utility::get_siteview_mode();
        ?>
        <div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
            <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <form method="POST" action="" id="manage-sites-screen-options-form" name="manage_sites_screen_options_form">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ManageSitesScrOptions' ) ); ?>" />
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
                        <label class="six wide column"><?php esc_html_e( 'Default items per page value', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <div class="ui info message">
                                <ul>
                                    <li><?php esc_html_e( 'Based on your Dashboard server default large numbers can severely impact page load times.', 'mainwp' ); ?></li>
                                    <li><?php esc_html_e( 'Do not add commas for thousands (ex 1000).', 'mainwp' ); ?></li>
                                    <li><?php esc_html_e( '-1 to default to All of your Child Sites.', 'mainwp' ); ?></li>
                                </ul>
                            </div>
                            <input type="text" name="mainwp_default_sites_per_page" id="mainwp_default_sites_per_page" saved-value="<?php echo intval( $sites_per_page ); ?>" value="<?php echo intval( $sites_per_page ); ?>"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column"><?php esc_html_e( 'Show columns', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <ul class="mainwp_hide_wpmenu_checkboxes">
                                <?php
                                foreach ( $columns as $name => $title ) {
                                    if ( empty( $title ) ) {
                                        continue;
                                    }
                                    ?>
                                    <li>
                                        <div class="ui checkbox <?php echo ( 'site_preview' === $name ) ? 'site_preview not-auto-init' : ''; ?>">
                                            <input type="checkbox"
                                            <?php
                                            $show_col = ! isset( $show_cols[ $name ] ) || ( 1 === (int) $show_cols[ $name ] );

                                            if ( 'added_datetime' === $name && ! isset( $show_cols[ $name ] ) ) {
                                                $show_col = false; // default is hidden.
                                            }

                                            if ( $show_col ) {
                                                echo 'checked="checked"';
                                            }

                                            ?>
                                            id="mainwp_show_column_<?php echo esc_attr( $name ); ?>" name="mainwp_show_column_<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>">
                                            <label for="mainwp_show_column_<?php echo esc_attr( $name ); ?>" ><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
                                            <input type="hidden" value="<?php echo esc_attr( $name ); ?>" name="show_columns_name[]" />
                                        </div>
                                    </li>
                                    <?php
                                }
                                ?>
                            </ul>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Optimize for shared hosting or big networks', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox"  data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will cache updates for faster loading.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <input type="checkbox" name="mainwp_optimize" id="mainwp_optimize" <?php echo 1 === (int) get_option( 'mainwp_optimize', 1 ) ? 'checked="true"' : ''; ?> /><label><?php esc_html_e( 'Default: Off', 'mainwp' ); ?></label>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Export child sites to CSV file', 'mainwp' ); ?></label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click this button to export all connected sites to a CSV file.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><a href="admin.php?page=MainWPTools&doExportSites=yes&_wpnonce=<?php echo esc_html( wp_create_nonce( 'export_sites' ) ); ?>" class="ui button green basic"><?php esc_html_e( 'Export Child Sites', 'mainwp' ); ?></a></div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Disconnect all child sites', 'mainwp' ); ?></label>
                        <div class="ten wide column" id="mainwp-disconnect-sites-tool" data-tooltip="<?php esc_attr_e( 'This will function will break the connection and leave the MainWP Child plugin active.', 'mainwp' ); ?>" data-variation="inverted" data-position="top left">
                            <a href="admin.php?page=MainWPTools&disconnectSites=yes&_wpnonce=<?php echo esc_html( wp_create_nonce( 'disconnect_sites' ) ); ?>" onclick="mainwp_tool_disconnect_sites(); return false;" class="ui button green basic"><?php esc_html_e( 'Disconnect Websites.', 'mainwp' ); ?></a>
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
                jQuery( '.ui.checkbox.not-auto-init.site_preview' ).checkbox( {
                    onChecked   : function() {
                        let $chk = jQuery( this );
                        jQuery( '#mainwp-manage-sites-site-preview-screen-options-modal' ).modal( {
                            allowMultiple: true, // multiple modals.
                            width: 100,
                            onDeny: function () {
                                $chk.prop('checked', false);
                            }
                        } ).modal( 'show' );
                    }
                } );
                jQuery('#reset-managersites-settings').on( 'click', function () {
                    mainwp_confirm(__( 'Are you sure.' ), function(){
                        jQuery('#mainwp_sitesviewmode').dropdown( 'set selected', 'table' );
                        jQuery('input[name=mainwp_default_sites_per_page]').val(25);
                        jQuery('.mainwp_hide_wpmenu_checkboxes input[id^="mainwp_show_column_"]').prop( 'checked', false );
                        //default columns.
                        let cols = ['status', 'favicon', 'site_combo','update','client_name', 'security', 'index', 'site_actions'];
                        jQuery.each( cols, function ( index, value ) {
                            jQuery('.mainwp_hide_wpmenu_checkboxes input[id="mainwp_show_column_' + value + '"]').prop( 'checked', true );
                        } );
                        jQuery('input[name=reset_managersites_columns_order]').attr('value',1);
                        jQuery('#submit-managersites-settings').click();
                    }, false, false, true );
                    return false;
                });
            } );
        </script>
        <?php
    }

    /**
     * Method ajax_optimize_display_rows()
     *
     * Display table rows, optimize for shared hosting or big networks.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_List_Table
     */
    public static function ajax_optimize_display_rows() {
        static::load_sites_table();
        static::$sitesTable->prepare_items( true );
        $output = static::$sitesTable->ajax_get_datatable_rows();
        static::$sitesTable->clear_items();

        MainWP_Logger::instance()->log_execution_time( 'ajax_optimize_display_rows()' );

        wp_send_json( $output );
    }

    /**
     * Method render_new_site()
     *
     * Render add new site page.
     *
     * @return string Add new site html.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_sync_exts_settings()
     */
    public static function render_new_site() {

        $showpage   = 'AddNew';
        $title_page = esc_html__( 'Add a New Site', 'mainwp' );
        static::render_header( $showpage );
        $has_import_data = ! empty( $_POST['mainwp_managesites_import'] );

        if ( ! \mainwp_current_user_can( 'dashboard', 'add_sites' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'add sites', 'mainwp' ) );
            return;
        } elseif ( $has_import_data && check_admin_referer( 'mainwp-admin-nonce' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
            static::render_import_sites_modal( 'admin.php?page=managesites&do=new', $title_page );
        } else {
            $groups = MainWP_DB_Common::instance()->get_groups_for_current_user();
            if ( ! is_array( $groups ) ) {
                $groups = array();
            }
            ?>
            <div id="mainwp-add-new-site">
                <form method="POST" class="ui form" action="" enctype="multipart/form-data" id="mainwp_managesites_add_form">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <div class="ui labeled icon inverted menu mainwp-sub-submenu" id="mainwp-add-sites-tabular-menu">
                        <a class="item active" data-tab="single-site">
                            <i class="wordpress icon"></i><?php //phpcs:ignore -- WP icon. ?>
                            <?php esc_html_e( 'Single Site', 'mainwp' ); ?>
                        </a>
                        <a class="item" data-tab="multiple-site">
                            <div class="icons" style="margin:0.5rem auto">
                                <i class="icon wordpress"></i><?php //phpcs:ignore -- WP icon. ?>
                                <i class="icon wordpress"></i><?php //phpcs:ignore -- WP icon. ?>
                            </div>
                            <?php esc_html_e( 'Multiple Sites', 'mainwp' ); ?>
                        </a>
                    </div>
                    <div class="ui bottom attached tab segment active" data-tab="single-site">
                        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-add-site-info-message' ) ) : ?>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-add-site-info-message"></i>
                                <div><?php printf( esc_html__( 'Use the provided form to connect your websites to your MainWP Dashboard. For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/get-started-with-mainwp/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?></div>
                                <div><?php printf( esc_html__( 'If you are experiencing issues with adding a website to your MainWP Dashboard, use the %1$sTest Connection%2$s feature to ensure that your MainWP Dashboard can communicate with your website.', 'mainwp' ), '<a href="https://mainwp.com/kb/test-connection-between-your-mainwp-dashboard-and-child-site/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?></div>
                                <div><?php printf( esc_html__( 'If you still can not connect the site, see the list of %1$spotential issues%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/potential-issues/" target="_blank">', '</a>' ); // NOSONAR - noopener - open safe. ?></div>
                            </div>
                        <?php endif; ?>

                        <div id="mainwp-message-zone" style="display: none;" class="ui message"></div>

                        <div id="mainwp_managesites_add_errors" style="display: none" class="ui red message"></div>
                        <div id="mainwp_managesites_add_message" style="display: none" class="ui green message"></div>

                        <div class="ui info message" id="mainwp_message_verify_installed_child" style="display:none">
                            <?php esc_html_e( 'MainWP requires the MainWP Child plugin to be installed and activated on the WordPress site that you want to connect to your MainWP Dashboard. ', 'mainwp' ); ?>
                            <?php esc_html_e( 'To install the MainWP Child plugin, please follow these steps:', 'mainwp' ); ?>
                            <ol>
                                <li><?php printf( esc_html__( 'Login to the WordPress site you want to connect %1$s(open it in a new browser tab)%2$s', 'mainwp' ), '<em>', '</em>' ); ?></li>
                                <li><?php printf( esc_html__( 'Go to the %1$sWP > Plugins%2$s page', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( 'Click %1$sAdd New%2$s to install a new plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( 'In the %1$sSearch Field%2$s, enter "MainWP Child" and once the plugin shows, click the Install button', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( '%1$sActivate%2$s the plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
                            </ol>
                        </div>
                        <h2 class="ui dividing header">
                            <?php echo esc_html__( 'Connect a Single Site', 'mainwp' ); ?>
                            <div class="sub header"><?php echo esc_html__( 'Connect your site to your MainWP Dashboard for centralized management.', 'mainwp' ); ?></div>
                        </h2>
                        <?php static::render_new_site_add_new_site( $groups );  // NOSONAR - render html form. ?>
                    </div>

                    <div class="ui bottom attached tab segment" data-tab="multiple-site">
                        <?php static::render_new_site_add_multi_new_site(); // NOSONAR - render html form. ?>
                    </div>
                </form>
            </div>

            <div class="ui modal" id="mainwp-test-connection-modal">
                <i class="close icon"></i>
                <div class="header"><?php esc_html_e( 'Connection Test', 'mainwp' ); ?></div>
                <div class="content">
                    <div class="ui active inverted dimmer">
                        <div class="ui text loader"><?php esc_html_e( 'Testing connection...', 'mainwp' ); ?></div>
                    </div>
                    <div id="mainwp-test-connection-result" class="ui segment" style="display:none">
                        <h2 class="ui center aligned icon header">
                            <i class=" icon"></i>
                            <div class="content">
                                <span></span>
                                <div class="sub header"></div>
                            </div>
                        </h2>
                    </div>
                </div>
                <div class="actions">
                </div>
            </div>

            <script type="text/javascript">
                jQuery('#mainwp-add-sites-tabular-menu .item').tab();
                jQuery( document ).ready( function () {
                    jQuery( '#mainwp_managesites_add_addgroups' ).dropdown( {
                        allowAdditions: true
                    } );
                    jQuery( '#mainwp_manage_add_edit_site_icon_select' ).dropdown( {
                        onChange: function( val ) {
                            jQuery( '#mainwp_managesites_add_site_select_icon_hidden' ).val(val);
                        }
                    } );

                    jQuery(document).on('click', '.mainwp-managesites-add-site-icon-customable', function () {
                        let iconObj = jQuery(this);
                        jQuery('#mainwp_delete_image_field').hide();
                        jQuery('#mainwp-upload-custom-icon-modal').modal('setting', 'closable', false).modal('show');
                        jQuery('#update_custom_icon_btn').removeAttr('disabled');
                        jQuery('#update_custom_icon_btn').attr('uploading-icon', 'site');
                        jQuery('#mainwp_delete_image_field').find('#mainwp_delete_image_chk').attr('iconItemId', iconObj.attr('iconItemId') ); // @see used by mainwp_upload_custom_types_icon().
                        jQuery('#mainwp_delete_image_field').find('#mainwp_delete_image_chk').attr('iconFileSlug', iconObj.attr('iconFileSlug') ); // @see used by mainwp_upload_custom_types_icon().

                        if (iconObj.attr('icon-src') != '') {
                            jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', iconObj.attr('icon-src'));
                            jQuery('#mainwp_delete_image_field').show();
                        }

                        jQuery(document).on('click', '#update_custom_icon_btn', function () {
                                let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked');
                                let iconItemId = iconObj.attr('iconItemId');
                                let iconFileSlug = iconObj.attr('iconFileSlug'); // to support delete file when iconItemId = 0.
                                // upload/delete icon action.
                                mainwp_upload_custom_types_icon(iconObj, 'mainwp_managesites_add_edit_site_upload_site_icon', iconItemId, iconFileSlug, deleteIcon, function(response){
                                    if (jQuery('#mainwp_managesites_add_site_uploaded_icon_hidden').length > 0) {
                                        if (typeof response.iconfile !== undefined) {
                                            jQuery('#mainwp_managesites_add_site_uploaded_icon_hidden').val(response.iconfile);
                                        } else {
                                            jQuery('#mainwp_managesites_add_site_uploaded_icon_hidden').val('');
                                        }
                                    }
                                    let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked'); // to delete.
                                    if(deleteIcon){
                                        jQuery('#mainw_managesites_add_edit_site_upload_custom_icon').hide();
                                    } else if (jQuery('#mainw_managesites_add_edit_site_upload_custom_icon').length > 0) {
                                        if (typeof response.iconfile !== undefined) {
                                            let icon_img = typeof response.iconimg !== undefined ? response.iconimg : '';
                                            let icon_src = typeof response.iconsrc !== undefined ? response.iconsrc : '';
                                            iconObj.attr('icon-src', icon_src);
                                            iconObj.attr('iconFileSlug', response.iconfile); // to support delete file when iconItemId = 0.
                                            iconObj.attr('del-icon-nonce', response.iconnonce);
                                            jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', icon_src);
                                            jQuery('#mainw_managesites_add_edit_site_upload_custom_icon').html(icon_img).show();
                                        }
                                    }
                                    setTimeout(function () {
                                        //window.location.href = location.href;
                                        jQuery('#mainwp-upload-custom-icon-modal').modal('hide')
                                    }, 1000);
                                });
                                return false;
                        });
                    });

                } );
            </script>
            <?php
        }
        static::render_footer( $showpage );
        MainWP_UI::render_modal_upload_icon();
    }

    /**
     * Method render_new_site_add_new_site()
     *
     * Render page managesites add new site.
     *
     * @uses MainWP_UI::get_default_icons()
     * @uses MainWP_DB_Client::instance()->get_wp_client_by()
     * @uses MainWP_Manage_Sites_View::render_sync_exts_settings()
     *
     * @param array $groups manage sites add groups.
     */
    public static function render_new_site_add_new_site( $groups ) {
        ?>
        <div class="ui grid field">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Site URL', 'mainwp' ); ?></label>
            <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter your website URL.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                <div class="ui left action input">
                    <select class="ui compact selection dropdown" id="mainwp_managesites_add_wpurl_protocol" name="mainwp_managesites_add_wpurl_protocol">
                        <option value="http"><?php echo esc_html( 'http://' ); ?></option>
                        <option selected="" value="https"><?php echo esc_html( 'https://' ); ?></option>
                    </select>
                    <input type="text" id="mainwp_managesites_add_wpurl" name="mainwp_managesites_add_wpurl" value="" />
                </div>
            </div>
            <div class="ui four wide middle aligned column">
                <input type="button" name="mainwp_managesites_edit_test" id="mainwp_managesites_test" class="ui button basic green" value="<?php esc_attr_e( 'Test Connection', 'mainwp' ); ?>"/>
            </div>
        </div>
        <div class="ui grid field">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Verify that the MainWP Child plugin is installed and activated', 'mainwp' ); ?></label>
            <div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Verify that MainWP Child is Installed and Activated.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                <input type="checkbox" name="mainwp_managesites_verify_installed_child" id="mainwp_managesites_verify_installed_child" />
                <label><?php esc_attr_e( 'Select to confirm that the MainWP Child plugin is active on the child site.', 'mainwp' ); ?></label>
            </div>
        </div>

        <div id="mainwp-add-site-hidden-form" style="display:none">
            <h2 class="ui dividing header">
                <?php esc_html_e( 'Connection Settings', 'mainwp' ); ?>
                <div class="sub header">
                    <?php esc_html_e( 'Enter site connection details', 'mainwp' ); ?>
                </div>
            </h2>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Administrator username', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website Administrator username.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui left labeled input">
                        <input type="text" id="mainwp_managesites_add_wpadmin" name="mainwp_managesites_add_wpadmin" value="" />
                    </div>
                </div>
            </div>
            <div class="ui grid field">
                <label class="six wide column top aligned"><?php esc_html_e( 'Connection authentication method(s)', 'mainwp' ); ?></label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select which connection authentication processes you are using.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui toggle checked checkbox not-auto-init" id="addsite-adminpwd" style="margin-right:2em;">
                        <input type="checkbox" id="mainwp-administrator-password-checkbox-field" checked=""><label><?php esc_html_e( 'Administrator password', 'mainwp' ); ?></label>
                    </div>
                    <div class="ui toggle checkbox not-auto-init" id="addsite-uniqueid">
                        <input type="checkbox" id="mainwp-unique-security-id-checkbox-field"><label><?php esc_html_e( 'Unique Security ID', 'mainwp' ); ?></label>
                    </div>

                    <div class="ui fluid accordion" id="mainwp-connection-authentication-accordion" style="margin-top:1em">
                        <div class="title">
                            <i class="dropdown icon"></i>
                            <?php esc_html_e( 'Connection authentication methods explained', 'mainwp' ); ?>
                        </div>
                        <div class="content">
                            <span class="ui text"><?php esc_html_e( 'Choose options based on your MainWP Child plugin setup on the WordPress site you want to connect.', 'mainwp' ); ?></span>
                            <div class="ui bulleted small list">
                                <div class="item"><?php esc_html_e( 'Default Setup: Use only the Password field if you haven\'t changed the default settings.', 'mainwp' ); ?></div>
                                <div class="item"><?php esc_html_e( 'Advanced Setup: If you\'ve turned off all verification on the child site, switch off both fields.', 'mainwp' ); ?></div>
                            </div>
                            <span class="ui text"><?php esc_html_e( 'Use the sliders to control the fields shown:', 'mainwp' ); ?></span>
                            <div class="ui bulleted small list">
                                <div class="item"><?php esc_html_e( 'Password On: Displays the Password field.', 'mainwp' ); ?></div>
                                <div class="item"><?php esc_html_e( 'Security Key On: Displays the Security Key field.', 'mainwp' ); ?></div>
                                <div class="item"><?php esc_html_e( 'Both On: Displays both fields.', 'mainwp' ); ?></div>
                                <div class="item"><?php esc_html_e( 'Both Off: Hides both fields.', 'mainwp' ); ?></div>
                            </div>
                            <span class="ui  text"><strong><?php esc_html_e( 'This needs to match what is set on your child site. Default is Administrator password.', 'mainwp' ); ?></strong></span>
                        </div>
                    </div>
                </div>
            </div>
            <div id="mainwp-administrator-password-field">
                <input type="password" id="fake-disable-autofill" style="display:none;" name="fake-disable-autofill" />
                <div class="ui grid field">
                    <label class="six wide column top aligned"><?php esc_html_e( 'Administrator password', 'mainwp' ); ?></label>
                    <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website Administrator password.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <div class="ui left labeled input">
                            <input type="password" id="mainwp_managesites_add_admin_pwd" name="mainwp_managesites_add_admin_pwd" autocomplete="one-time-code" autocorrect="off" autocapitalize="none" spellcheck="false" value="" />
                        </div>
                        <div class="ui hidden fitted divider"></div>
                        <span class="ui small text"><?php esc_html_e( 'Your password is never stored by your Dashboard and never sent to MainWP.com. Once this initial connection is complete, your MainWP Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected, just be sure not to delete the admin account, as this would disrupt the connection.', 'mainwp' ); ?></span>
                    </div>
                </div>
            </div>
            <div id="mainwp-unique-security-id-field" style="display:none">
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Unique security ID', 'mainwp' ); ?></label>
                    <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If in use, enter the website Unique ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <div class="ui left labeled input">
                            <input type="text" id="mainwp_managesites_add_uniqueId" name="mainwp_managesites_add_uniqueId" value="" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Site title', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website title.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui left labeled input">
                        <input type="text" id="mainwp_managesites_add_wpname" name="mainwp_managesites_add_wpname" value="" />
                    </div>
                </div>
            </div>
            <div class="ui grid field">
                <div class="six wide column middle aligned">
                    <a href="#" id="mainwp-add-site-advanced-options-toggle"><i class="eye slash outline icon"></i> <?php esc_html_e( 'Show Optional Settings', 'mainwp' ); ?></a>
                </div>
                <div class="six wide column middle aligned">
                </div>
            </div>

            <div id="mainwp-add-site-advanced-options" class="ui secondary segment" style="display:none" >

            <h2 class="ui dividing header">
                <?php esc_html_e( 'Site Details', 'mainwp' ); ?>
                <div class="sub header">
                    <?php esc_html_e( 'Optional fields to help identify and organize your site.', 'mainwp' ); ?>
                </div>
            </h2>

            <div class="ui grid field">
                <label class="six wide column middle aligned">
                    <?php esc_html_e( 'Upload site icon (Optional)', 'mainwp' ); ?>
                </label>
                <input type="hidden" name="mainwp_managesites_add_site_uploaded_icon_hidden" id="mainwp_managesites_add_site_uploaded_icon_hidden" value="">

                <div class="three wide middle aligned column">
                    <span class="ui circular bordered image">
                        <div style="display:inline-block;" id="mainw_managesites_add_edit_site_upload_custom_icon"></div>
                    </span>
                    <div class="ui basic button mainwp-managesites-add-site-icon-customable" iconItemId="" iconFileSlug="" icon-src="" del-icon-nonce="">
                        <i class="image icon"></i> <?php esc_html_e( 'Upload Image', 'mainwp' ); ?>
                    </div>
                </div>
            </div>

            <?php
            $default_icons         = MainWP_UI::get_default_icons();
            $selected_default_icon = 'wordpress'; //phpcs:ignore -- WP icon.
            $selected_site_color   = '#34424D';
            ?>

            <div class="ui grid field">
                <label class="six wide column middle aligned">
                    <?php esc_html_e( 'Select icon (Optional)', 'mainwp' ); ?>
                </label>
                <input type="hidden" name="mainwp_managesites_add_site_select_icon_hidden" id="mainwp_managesites_add_site_select_icon_hidden" value="">
                <div class="six wide column" data-tooltip="<?php esc_attr_e( 'Select an icon if not using original site icon.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                    <div class="ui left action input mainwp-dropdown-color-picker-field">
                        <div class="ui five column selection search dropdown not-auto-init" id="mainwp_manage_add_edit_site_icon_select">
                            <div class="text">
                                <span style="color:<?php echo esc_attr( $selected_site_color ); ?>" ><?php echo ! empty( $selected_default_icon ) ? '<i class="' . esc_attr( $selected_default_icon ) . ' icon"></i>' : ''; ?></span>
                            </div>
                            <i class="dropdown icon"></i>
                            <div class="menu">
                                <?php foreach ( $default_icons as $icon ) : ?>
                                    <?php echo '<div class="item" style="color:' . esc_attr( $selected_site_color ) . '" data-value="' . esc_attr( $icon ) . '"><i class="' . esc_attr( $icon ) . ' icon"></i></div>'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="color" data-tooltip="Color will update on save" data-position="top center" data-inverted="" name="mainwp_managesites_add_site_color" class="mainwp-color-picker-input" id="mainwp_managesites_add_site_color"  value="<?php echo esc_attr( $selected_site_color ); ?>" />
                    </div>
                </div>
                <div class="one wide column"></div>
            </div>
            <div class="ui grid field">
                <label class="six wide column middle aligned">
                    <?php esc_html_e( 'Tags (optional)', 'mainwp' ); ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Add the website to existing tag(s).', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui multiple search selection dropdown" init-value="" id="mainwp_managesites_add_addgroups">
                        <i class="dropdown icon"></i>
                        <div class="default text"></div>
                        <div class="menu">
                            <?php foreach ( $groups as $group ) { ?>
                                <div class="item" data-value="<?php echo intval( $group->id ); ?>"><?php echo esc_html( stripslashes( $group->name ) ); ?></div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
            ?>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Client (optional)', 'mainwp' ); ?></label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Add a client to the website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui search selection dropdown" init-value="" id="mainwp_managesites_add_client_id">
                        <i class="dropdown icon"></i>
                        <div class="default text"></div>
                        <div class="menu">
                            <div class="item" data-value="0"><?php esc_attr_e( 'Select client', 'mainwp' ); ?></div>
                            <?php foreach ( $clients as $client ) { ?>
                                <div class="item" data-value="<?php echo intval( $client->client_id ); ?>"><?php echo esc_html( $client->name ); ?></div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- fake fields are a workaround for chrome autofill getting the wrong fields. -->
            <input style="display:none" type="text" name="fakeusernameremembered"/>
            <input style="display:none" type="password" name="fakepasswordremembered"/>

            <h2 class="ui dividing header">
                <?php esc_html_e( 'Advanced Settings', 'mainwp' ); ?>
                <div class="sub header">
                    <?php esc_html_e( 'Extra options for special cases, modify only if needed.', 'mainwp' ); ?>
                </div>
            </h2>

            <div class="ui grid field settings-field-indicator-wrapper">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Verify SSL certificate (optional)', 'mainwp' ); ?></label>
                <div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Disabling this is only necessary if your site\'s SSL certificate is broken or self-signed. For security, keep it enabled unless you experience connection issues.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <input type="checkbox" name="mainwp_managesites_verify_certificate" id="mainwp_managesites_verify_certificate" checked="true" />
                </div>
            </div>

            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'SSL version (optional)', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'This setting is only needed in rare cases where the default Auto-Detect option fails, usually on outdated servers with old SSL protocols. If unsure, keep it set to Auto-Detect for best compatibility.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select class="ui dropdown" id="mainwp_managesites_add_ssl_version" name="mainwp_managesites_add_ssl_version">
                        <option value="0"><?php esc_html_e( 'Auto detect', 'mainwp' ); ?></option>
                        <option value="6"><?php esc_html_e( 'TLS v1.2', 'mainwp' ); ?></option>
                        <option value="1"><?php esc_html_e( 'TLS v1.x', 'mainwp' ); ?></option>
                        <option value="2"><?php esc_html_e( 'SSL v2', 'mainwp' ); ?></option>
                        <option value="3"><?php esc_html_e( 'SSL v3', 'mainwp' ); ?></option>
                        <option value="4"><?php esc_html_e( 'TLS v1.0', 'mainwp' ); ?></option>
                        <option value="5"><?php esc_html_e( 'TLS v1.1', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>

            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'HTTP username (optional)', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If the child site is HTTP Basic Auth protected, enter the HTTP username here.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui left labeled input">
                        <input type="text" id="mainwp_managesites_add_http_user" name="mainwp_managesites_add_http_user" value="" autocomplete="off" />
                    </div>
                </div>
            </div>

            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'HTTP password (optional)', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If the child site is HTTP Basic Auth protected, enter the HTTP password here.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui left labeled input">
                        <input type="password" id="mainwp_managesites_add_http_pass" name="mainwp_managesites_add_http_pass" value="" autocomplete="new-password" />
                    </div>
                </div>
            </div>

            <?php MainWP_Manage_Sites_View::render_sync_exts_settings(); ?>
            </div>

            <?php
            do_action_deprecated( 'mainwp-manage-sites-edit', array( false ), '4.0.7.2', 'mainwp_manage_sites_edit' ); // @deprecated Use 'mainwp_manage_sites_edit' instead. NOSONAR - not IP.

            /**
             * Edit site
             *
             * Fires on the Edit child site page and allows user to hook in new site options.
             *
             * @param bool false
             */
            do_action( 'mainwp_manage_sites_edit', false );
            ?>

            <div class="ui divider"></div>
                <input type="button" name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui button green big" value="<?php esc_attr_e( 'Add Site', 'mainwp' ); ?>" />
                <div class="ui hidden clearing divider"></div>
            </div>
        <?php
        static::render_add_site_scripts();
    }

    /**
     * Render add site scripts.
     *
     * @return void
     */
    public static function render_add_site_scripts() {
        ?>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                let adminwpTip = jQuery('#mainwp-connection-authentication-accordion').accordion({
                    onChange:function(){
                        mainwp_ui_state_save( 'adminpwd_tip_showhide', jQuery(this).hasClass('active') ? 1 : 0 );
                    }
                });

                mainwp_ui_state_init('adminpwd_tip_showhide', function(state){
                    if( '1' === state ){
                        adminwpTip.accordion('open', 0);
                    } else {
                        adminwpTip.accordion('close', 0);
                    }
                });

                let chkbx1 = jQuery('#addsite-adminpwd').checkbox({
                    onChange:function(){
                        mainwp_ui_state_save( 'addsite-adminpwd', jQuery(this).is(':checked') ? 2 : 0 );
                        if(jQuery(this).is(':checked')){
                            jQuery('#mainwp-administrator-password-field').fadeIn(500);
                        } else {
                            jQuery('#mainwp-administrator-password-field').fadeOut(500);
                        }
                    }
                });

                let chkbx2 = jQuery('#addsite-uniqueid').checkbox({
                    onChange:function(){
                        mainwp_ui_state_save( 'addsite-uniqueid', jQuery(this).is(':checked') ? 2 : 0 );
                        if(jQuery(this).is(':checked')){
                            jQuery('#mainwp-unique-security-id-field').fadeIn(500);
                        } else {
                            jQuery('#mainwp-unique-security-id-field').fadeOut(500);
                        }
                    }
                });

                mainwp_ui_state_init('addsite-adminpwd', function(state){
                    if( '1' === state || '0' === state ){
                        chkbx1.checkbox('set unchecked');
                        jQuery('#mainwp-administrator-password-field').fadeOut(500);
                    } else {
                        chkbx1.checkbox('set checked');
                        jQuery('#mainwp-administrator-password-field').fadeIn(500);
                    }
                });


                mainwp_ui_state_init('addsite-uniqueid', function(state){
                    if( '1' === state || '0' === state ){
                        chkbx2.checkbox('set unchecked');
                        jQuery('#mainwp-unique-security-id-field').fadeOut(500);
                    } else {
                        chkbx2.checkbox('set checked');
                        jQuery('#mainwp-unique-security-id-field').fadeIn(500);
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Method render_new_site_add_multi_new_site()
     *
     * Render page managesites add multi new site.
     *
     * @uses static::mainwp_managesites_form_import_sites()
     */
    public static function render_new_site_add_multi_new_site() {
        ?>
        <div class="ui fitted hidden divider"></div>
        <h2 class="ui dividing header">
            <?php echo esc_html__( 'Connect Multiple Sites', 'mainwp' ); ?>
            <div class="sub header"><?php echo esc_html__( 'Connect multiple sites to your MainWP Dashboard for centralized management.', 'mainwp' ); ?></div>
        </h2>
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-add-multiple-sites-info-message' ) ) : ?>
            <div class="ui blue message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-add-multiple-sites-info-message"></i>
                <?php static::mainwp_managesites_information_import_sites(); ?>
            </div>
        <?php endif; ?>

        <div id="mainwp-add-multi-new-site-message-zone" style="display: none;" class="ui message"></div>

        <?php static::mainwp_managesites_form_import_sites(); // NOSONAR - render html form. ?>

        <div class="ui divider"></div>
        <input type="button" name="mainwp_managesites_add" id="mainwp_managesites_add_multi_site" class="ui big green button left floated" value="<?php esc_html_e( 'Add Sites', 'mainwp' ); ?>"/>
        <div class="ui hidden divider"></div>
        <?php
    }

    /**
     * Method ajax_upload_icon()
     */
    public static function ajax_upload_icon() { //phpcs:ignore -- NOSONAR - complex.
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_managesites_add_edit_site_upload_site_icon' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $iconfile_slug = isset( $_POST['iconFileSlug'] ) ? sanitize_text_field( wp_unslash( $_POST['iconFileSlug'] ) ) : '';
        $delete        = isset( $_POST['delete'] ) ? intval( $_POST['delete'] ) : 0;
        $site_id       = isset( $_POST['iconItemId'] ) ? intval( $_POST['iconItemId'] ) : 0;
        $delnonce      = isset( $_POST['delnonce'] ) ? sanitize_key( $_POST['delnonce'] ) : '';

        if ( $delete ) {
            if ( ! MainWP_System_Utility::is_valid_custom_nonce( 'site', $iconfile_slug, $delnonce ) ) {
                die( 'Invalid nonce!' );
            }
            if ( $site_id ) {
                $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                if ( $website && ! empty( $website->cust_site_icon_info ) ) {
                    $uploaded_site_icon = static::get_instance()->get_cust_site_icon( $website->cust_site_icon_info, 'uploaded' );
                    if ( ! empty( $uploaded_site_icon ) ) {
                        $tmp      = explode( ';', $website->cust_site_icon_info );
                        $new_info = 'uploaded:;' . $tmp[1] . ';' . $tmp[2];
                        MainWP_DB::instance()->update_website_option( $website, 'cust_site_icon_info', $new_info ); // to delete uploaded icon.
                        MainWP_Utility::instance()->delete_uploaded_icon_file( 'site-icons', $uploaded_site_icon );
                    }
                }
            } elseif ( ! empty( $iconfile_slug ) ) {
                MainWP_Utility::instance()->delete_uploaded_icon_file( 'site-icons', $iconfile_slug );
            }
            wp_die( wp_json_encode( array( 'result' => 'success' ) ) );
        }

        $output = isset( $_FILES['mainwp_upload_icon_uploader'] ) ? MainWP_System_Utility::handle_upload_image( 'site-icons', $_FILES['mainwp_upload_icon_uploader'] ) : null;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $uploaded_icon = 'NOTCHANGE';
        if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
            $uploaded_icon = $output['filename'];
        }

        if ( 'NOTCHANGE' !== $uploaded_icon ) {
            $dirs     = MainWP_System_Utility::get_mainwp_dir( 'site-icons', true );
            $icon_url = $dirs[1] . $uploaded_icon;
            wp_die(
                wp_json_encode(
                    array(
                        'result'    => 'success',
                        'iconfile'  => esc_html( $uploaded_icon ),
                        'iconsrc'   => esc_html( $icon_url ),
                        'iconimg'   => '<img class="ui circular image" src="' . esc_attr( $icon_url ) . '" style="width:32px;height:auto;display:inline-block;" alt="Custom icon">',
                        'iconnonce' => MainWP_System_Utility::get_custom_nonce( 'site', esc_html( $uploaded_icon ) ),
                    )
                )
            );
        } else {
            $result = array(
                'result' => 'failed',
            );
            $error  = MainWP_Post_Handler::get_upload_icon_error( $output );
            if ( ! empty( $error ) ) {
                $result['error'] = esc_html( $error );
            }
            wp_die( wp_json_encode( $result ) );
        }
    }

    /**
     * Method get_site_icon_display()
     *
     * Get site icon.
     *
     * @param string $icon_data icon data.
     * @param string $favi_url favico url.
     */
    public function get_site_icon_display( $icon_data, $favi_url ) {
        $output = '';
        // 1 priority.
        if ( ! empty( $icon_data ) ) {
            $uploaded = $this->get_cust_site_icon( $icon_data, 'uploaded' );
            if ( ! empty( $uploaded ) ) {
                $output = $this->get_cust_site_icon( $icon_data, 'display' );
            }
        }

        // 2 priority.
        if ( empty( $output ) && ! empty( $favi_url ) ) {
            $output = '<img src="' . esc_attr( $favi_url ) . '" style="width:28px;height:28px;" class="ui circular image" alt="Site icon"/>';
        }

        // 3 priority.
        $selected = $this->get_cust_site_icon( $icon_data, 'selected' );
        if ( empty( $output ) && ! empty( $selected ) ) {
            $output = $this->get_cust_site_icon( $icon_data, 'display_selected' );
        }

        if ( empty( $output ) ) {
            $output = '<i class="icon big wordpress" style="width:28px;height:28px;"></i> '; // phpcs:ignore -- WP icon.
        }

        // last default.
        return $output;
    }

    /**
     * Method get_cust_site_icon()
     *
     * Get site icon.
     *
     * @param string $icon_data icon data.
     * @param string $type Type: uploaded|selected|color|display.
     */
    public function get_cust_site_icon( $icon_data, $type = 'display' ) { //phpcs:ignore -- NOSONAR - complex.

        if ( empty( $icon_data ) ) {
            return '';
        }
        $data = explode( ';', $icon_data );

        $uploaded = str_replace( 'uploaded:', '', $data[0] );
        $selected = str_replace( 'selected:', '', $data[1] );
        $color    = str_replace( 'color:', '', $data[2] );

        if ( empty( $color ) ) {
            $color = '#34424D';
        }

        if ( 'display_selected' === $type ) {
            if ( empty( $selected ) ) {
                $selected = 'wordpress'; // phpcs:ignore -- WP icon.
            }
            return '<span style="color:' . esc_attr( $color ) . '" ><i class="' . esc_attr( $selected ) . ' big icon"></i></span>';
        }

        $output = '';
        if ( 'uploaded' === $type ) {
            $output = $uploaded;
        } elseif ( 'selected' === $type ) {
            $output = $selected;
        } elseif ( 'color' === $type ) {
            $output = $color;
        } elseif ( 'display' === $type || 'display_edit' === $type ) {
            $style       = 'width:28px;height:auto;display:inline-block;';
            $default_cls = 'mainw_site_custom_icon_display';
            $icon        = '';
            if ( ! empty( $uploaded ) ) {
                $dirs              = MainWP_System_Utility::get_mainwp_dir( 'site-icons', true );
                $icon_base         = $dirs[1];
                $scr               = $icon_base . $uploaded;
                $icon_wrapper_attr = ' class="' . esc_attr( $default_cls ) . ' ui circular image " ';
                if ( 'display_edit' === $type ) {
                    $icon_wrapper_attr = ' id="mainw_managesites_add_edit_site_upload_custom_icon" class="' . esc_attr( $default_cls ) . ' ui circular image" ';
                }
                $icon = '<div style="display:inline-block;" ' . $icon_wrapper_attr . '><img style="' . $style . '" src="' . esc_attr( $scr ) . '" alt="Site icon"/></div>';
            }
            $output = $icon;
        }
        return $output;
    }


    /**
     * Method render_bulk_new_site()
     *
     * Render Import Sites - bulk new site modal.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_import_sites()
     * @uses \MainWP_Utility::show_mainwp_message()
     */
    public static function render_bulk_new_site() {
        $title_page = esc_html__( 'Import Sites', 'mainwp' );
        $showpage   = 'BulkAddNew';
        static::render_header( $showpage );
        $has_file_upload = isset( $_FILES['mainwp_managesites_file_bulkupload'] ) && isset( $_FILES['mainwp_managesites_file_bulkupload']['error'] ) && UPLOAD_ERR_OK === $_FILES['mainwp_managesites_file_bulkupload']['error'];
        $has_import_data = ! empty( $_POST['mainwp_managesites_import'] );

        if ( ! \mainwp_current_user_can( 'dashboard', 'add_sites' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'add sites', 'mainwp' ) );
            return;
        } elseif ( ( $has_file_upload || $has_import_data ) && check_admin_referer( 'mainwp-admin-nonce' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
            static::render_import_sites_modal( 'admin.php?page=managesites&do=bulknew', $title_page );
        } else {
            ?>
            <div id="mainwp-import-sites">
                <div class="ui labeled icon inverted menu mainwp-sub-submenu" id="mainwp-import-sites-tabular-menu">
                    <a class="item active" data-tab="mainwp-import-csv">
                        <i class="file excel icon"></i>
                        <?php esc_html_e( 'CSV Import', 'mainwp' ); ?>
                    </a>
                </div>
                <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-import-sites-info-message' ) ) : ?>
                    <div class="ui segment">
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-import-sites-info-message"></i>
                            <?php printf( esc_html__( 'You can download the sample CSV file to see how to format the import file properly. For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/import-sites/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?>
                        </div>
                    </div>
                <?php endif; ?>
                <form method="POST" action="" enctype="multipart/form-data" id="mainwp_managesites_bulkadd_form" class="ui form">
                    <div class="ui bottom attached tab segment active" data-tab="mainwp-import-csv">
                        <div id="mainwp-message-zone" class="ui message" style="display:none"></div>
                        <h2 class="ui dividing header">
                            <?php echo esc_html( $title_page ); ?>
                            <div class="sub header"><?php esc_html_e( 'Import multiple websites to your MainWP Dashboard.', 'mainwp' ); ?></div>
                        </h2>
                        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <div class="ui grid field">
                            <label class="three wide column middle aligned" for="mainwp_managesites_file_bulkupload"><?php esc_html_e( 'Upload CSV', 'mainwp' ); ?> (<a href="<?php echo esc_url( MAINWP_PLUGIN_URL . 'assets/csv/sample.csv' ); ?>"><?php esc_html_e( 'Download Sample', 'mainwp' ); ?></a>)</label>
                            <div class="nine wide column">
                                <div class="ui file input">
                                <input type="file" name="mainwp_managesites_file_bulkupload" id="mainwp_managesites_file_bulkupload" accept="text/comma-separated-values"/>
                                </div>
                            </div>
                            <div class="ui toggle checkbox four wide column middle aligned">
                                <input type="checkbox" name="mainwp_managesites_chk_header_first" checked="checked" id="mainwp_managesites_chk_header_first" value="1"/>
                                <label for="mainwp_managesites_chk_header_first"><?php esc_html_e( 'CSV file contains a header', 'mainwp' ); ?></label>
                            </div>
                        </div>
                    </div>
                    <div class="ui segment">
                        <div class="ui divider"></div>
                        <input type="button" name="mainwp_managesites_add" id="mainwp_managesites_bulkadd" class="ui big green button" value="<?php echo esc_attr( $title_page ); ?>"/>
                    </div>
                </form>
                <script type="text/javascript">
                    jQuery('#mainwp-import-sites-tabular-menu .item').tab();
                </script>
            </div>
            <?php
        }
        static::render_footer( $showpage );
    }

    /**
     * Method on_load_page_dashboard()
     *
     * Add individual meta boxes.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Handler::apply_filters()
     * @uses \MainWP\Dashboard\MainWP_UI::add_widget_box()
     * @uses \MainWP\Dashboard\MainWP_Notes::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Recent_Pages::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Security_Issues_Widget::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Site_Info::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Updates_Overview::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Widget_Plugins::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Widget_Themes::get_class_name()
     */
    public static function on_load_page_dashboard() { // phpcs:ignore -- NOSONAR - current complexity is required to achieve desired results. Pull request solutions are welcome.
        wp_enqueue_script( 'common' );
        wp_enqueue_script( 'wp-lists' );
        wp_enqueue_script( 'postbox' );
        wp_enqueue_script( 'dashboard' );
        wp_enqueue_script( 'widgets' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $dashboard_siteid = isset( $_GET['dashboard'] ) ? intval( $_GET['dashboard'] ) : null;
         // phpcs:enable

        /**
         * Get getmetaboxes
         *
         * Adds metaboxes (widgets) to the Overview page.
         *
         * @since Unknown
         */
        $extMetaBoxs = MainWP_System_Handler::instance()->apply_filters( 'mainwp-getmetaboxes', array() );  // @deprecated Use 'mainwp_getmetaboxes' instead.
        $extMetaBoxs = apply_filters( 'mainwp_getmetaboxes', $extMetaBoxs, $dashboard_siteid ); // hooks load widgets for individual overview page and for manage sites's subpage.

        foreach ( $extMetaBoxs as $box ) {
            // to compatible.
            if ( isset( $box['custom'] ) && $box['custom'] && isset( $box['plugin'] ) ) {
                continue;
            }

            if ( isset( $box['plugin'] ) ) {
                $name                            = basename( $box['plugin'], '.php' );
                static::$enable_widgets[ $name ] = true;
            } elseif ( isset( $box['widget_id'] ) ) {
                static::$enable_widgets[ $box['widget_id'] ] = true;
            }
        }

        $values = static::$enable_widgets;

        /**
         * Unset unwanted Widgets
         *
         * Contains the list of enabled widgets and allows user to unset unwanted widgets.
         *
         * @param array $values           Array containing enabled widgets.
         * @param int   $dashboard_siteid Child site (Overview) ID.
         *
         * @since 4.0
         */
        $values                 = apply_filters( 'mainwp_overview_enabled_widgets', $values, $dashboard_siteid );
        static::$enable_widgets = array_merge( static::$enable_widgets, $values );

        // Load the Updates Overview widget.
        if ( static::$enable_widgets['overview'] ) {
            MainWP_UI::add_widget_box( 'overview', array( MainWP_Updates_Overview::get_class_name(), 'render' ), static::$page, array( 1, 1, 12, 19 ) );
        }

        // Load the Uptime monitoring widget.
        if ( MainWP_Uptime_Monitoring_Edit::is_enable_global_monitoring() && \mainwp_current_user_can( 'dashboard', 'manage_uptime_monitoring' ) && static::$enable_widgets['uptime_monitoring_response_time'] ) {
            MainWP_UI::add_widget_box( 'uptime_monitoring_response_time', array( MainWP_Uptime_Monitoring_Site_Widget::instance(), 'render_response_times_widget' ), static::$page, array( -1, -1, 12, 43 ) );
        }

        // Load the Themes widget.
        if ( static::$enable_widgets['themes'] ) {
            MainWP_UI::add_widget_box( 'themes', array( MainWP_Widget_Themes::get_class_name(), 'render' ), static::$page, array( -1, -1, 6, 30 ) );
        }

        // Load the Pluins widget.
        if ( static::$enable_widgets['plugins'] ) {
            MainWP_UI::add_widget_box( 'plugins', array( MainWP_Widget_Plugins::get_class_name(), 'render' ), static::$page, array( -1, -1, 6, 30 ) );
        }

        // Load the Non-MainWP Changes widget.
        if ( static::$enable_widgets['non_mainwp_changes'] ) {
            MainWP_UI::add_widget_box( 'non_mainwp_changes', array( MainWP_Site_Actions::get_class_name(), 'render' ), static::$page, array( -1, -1, 6, 30 ) );
        }

        // Load the Client widget.
        if ( static::$enable_widgets['client_info'] ) {
            MainWP_UI::add_widget_box( 'client_info', array( MainWP_Client_Info::get_class_name(), 'render' ), static::$page, array( -1, -1, 3, 30 ) );
        }

        // Load the Notes widget.
        if ( static::$enable_widgets['notes'] ) {
            MainWP_UI::add_widget_box( 'notes', array( MainWP_Notes::get_class_name(), 'render' ), static::$page, array( -1, -1, 3, 30 ) );
        }

        // Load the Site Info widget.
        MainWP_UI::add_widget_box( 'child_site_info', array( MainWP_Site_Info::get_class_name(), 'render' ), static::$page, array( -1, -1, 4, 30 ) );

        // Load the Recent Pages widget.
        if ( \mainwp_current_user_can( 'dashboard', 'manage_pages' ) && static::$enable_widgets['recent_pages'] ) {
            MainWP_UI::add_widget_box( 'recent_pages', array( MainWP_Recent_Pages::get_class_name(), 'render' ), static::$page, array( -1, -1, 4, 30 ) );
        }

        // Load the Recent Posts widget.
        if ( \mainwp_current_user_can( 'dashboard', 'manage_posts' ) && static::$enable_widgets['recent_posts'] ) {
            MainWP_UI::add_widget_box( 'recent_posts', array( MainWP_Recent_Posts::get_class_name(), 'render' ), static::$page, array( -1, -1, 4, 30 ) );
        }

        $i = 0;
        foreach ( $extMetaBoxs as $metaBox ) {
            $enabled = true;
            if ( isset( $metaBox['plugin'] ) ) {
                $name = basename( $metaBox['plugin'], '.php' );
                if ( isset( static::$enable_widgets[ $name ] ) && ! static::$enable_widgets[ $name ] ) {
                    $enabled = false;
                }
            }

            $id = isset( $metaBox['id'] ) ? $metaBox['id'] : $i++;
            $id = 'advanced-' . $id;

            if ( $enabled ) {
                $layout = array( -1, -1, 6, 30 );
                if ( isset( $metaBox['id'] ) && ( 'google-widget' === $metaBox['id'] || 'matomo' === $metaBox['id'] ) ) {
                    $layout = array( -1, -1, 6, 30 );
                }
                $layout = ! empty( $metaBox['layout'] ) && is_array( $metaBox['layout'] ) ? $metaBox['layout'] : $layout;
                MainWP_UI::add_widget_box( $id, $metaBox['callback'], static::$page, $layout );
            }
        }
    }

    /**
     * Method render_updates()
     *
     * Render updates.
     *
     * @param mixed $website Child Site.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Update_View::render_updates()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
     */
    public static function render_updates( $website ) {
        MainWP_System_Utility::set_current_wpid( $website->id );
        static::render_header( 'ManageSitesUpdates' );
        MainWP_Manage_Sites_Update_View::render_updates( $website );
        static::render_footer( 'ManageSitesUpdates' );
    }

    /**
     * Method render_email_settings()
     *
     * Render email settings.
     *
     * @param mixed $website Child Site.
     * @param bool  $updated updated settings.
     * @param bool  $updated_templ updated template file.
     * @param bool  $editing_temp editing template file.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_site_edit_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_notification_types()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
     */
    public static function render_email_settings( $website, $updated = false, $updated_templ = false, $editing_temp = false ) {
        $website = MainWP_DB::instance()->get_website_by_id( $website->id, false, array( 'settings_notification_emails' ) ); // reload.

        MainWP_System_Utility::set_current_wpid( $website->id );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $edit       = false;
        $email_type = isset( $_GET['edit-email'] ) ? sanitize_text_field( wp_unslash( $_GET['edit-email'] ) ) : false;

        if ( ! empty( $email_type ) ) {
            $notification_emails = MainWP_Notification_Settings::get_notification_types();
            if ( isset( $notification_emails[ $email_type ] ) ) {
                $edit = true;
            }
        }
        // phpcs:enable

        if ( $editing_temp ) {
            static::render_header( 'ManageSitesEdit' );
        }
        if ( $edit ) {
            MainWP_Manage_Sites_View::render_site_edit_email_settings( $website, $email_type, $updated_templ );
        } else {
            MainWP_Manage_Sites_View::render_edit_site_email_settings( $website, $updated );
        }
        if ( $editing_temp ) {
            static::render_footer( 'ManageSitesEdit' );
        }
    }

    /**
     * Method render_dashboard()
     *
     * Render Manage Sites Dashboard.
     *
     * @param mixed $website Child Site.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_dashboard()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
     */
    public static function render_dashboard( $website ) {
        MainWP_System_Utility::set_current_wpid( $website->id );
        static::render_header( 'ManageSitesDashboard' );
        MainWP_Manage_Sites_View::render_dashboard( $website );
        static::render_footer( 'ManageSitesDashboard' );
    }

    /**
     * Method render_backup_site()
     *
     * Render Manage Sites Backups.
     *
     * @param mixed $website Child Site.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Backup_View::render_backup_site()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
     */
    public static function render_backup_site( $website ) {
        MainWP_System_Utility::set_current_wpid( $website->id );
        static::render_header( 'ManageSitesBackups' );
        MainWP_Manage_Sites_Backup_View::render_backup_site( $website );
        static::render_footer( 'ManageSitesBackups' );
    }

    /**
     * Method render_scan_site()
     *
     * Render Site Hardening.
     *
     * @param mixed $website Child Site.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_scan_site()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
     */
    public static function render_scan_site( $website ) {
        MainWP_System_Utility::set_current_wpid( $website->id );
        static::render_header( 'SecurityScan' );
        MainWP_Manage_Sites_View::render_scan_site( $website );
        static::render_footer( 'SecurityScan' );
    }

    /**
     * Method render_monitor_site()
     *
     * @param mixed $website Child Site.
     */
    public static function render_monitor_site( $website ) {
        MainWP_System_Utility::set_current_wpid( $website->id );
        static::render_header( 'ManageSitesMonitor' );
        MainWP_Uptime_Monitoring_Edit::instance()->render_monitor_settings( $website->id, true );
        static::render_footer( 'ManageSitesMonitor' );
    }


    /**
     * Method show_backups()
     *
     * Render Backups.
     *
     * @param mixed $website Child Site.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Handler::is_sql_file()
     * @uses \MainWP\Dashboard\MainWP_Backup_Handler::is_archive()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Backup_View::show_backups()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     */
    public static function show_backups( &$website ) {  //phpcs:ignore -- NOSONAR - complex.
        $dir = MainWP_System_Utility::get_mainwp_specific_dir( $website->id );

        MainWP_System_Utility::touch( $dir . 'index.php' );

        $dbBackups   = array();
        $fullBackups = array();
        if ( file_exists( $dir ) ) {
            $dh = opendir( $dir );
            if ( $dh ) {
                while ( false !== ( $file = readdir( $dh ) ) ) {
                    if ( '.' !== $file && '..' !== $file ) {
                        $theFile = $dir . $file;
                        if ( MainWP_Backup_Handler::is_sql_file( $file ) ) {
                            $dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
                        } elseif ( MainWP_Backup_Handler::is_archive( $file ) ) {
                            $fullBackups[ filemtime( $theFile ) . $file ] = $theFile;
                        }
                    }
                }
                closedir( $dh );
            }
        }
        krsort( $dbBackups );
        krsort( $fullBackups );

        MainWP_Manage_Sites_Backup_View::show_backups( $website, $fullBackups, $dbBackups );
    }

    /**
     * Method render_all_sites()
     *
     * Render manage sites content.
     *
     * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
     */
    public static function render_all_sites() {

        static::load_sites_table();// to fix loading.

        $optimize_for_sites_table = apply_filters( 'mainwp_manage_sites_optimize_loading', 1, 'manage-sites' ); // use ajax to load sites table .

        if ( ! $optimize_for_sites_table ) {
            static::$sitesTable->prepare_items( false );
        }

        static::render_header( '' );

        ?>
        <div id="mainwp-manage-sites-content" class="ui segment">
            <div id="mainwp-message-zone" class="ui message" style="display: none;"></div>
            <form method="post" class="mainwp-table-container">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <?php
                static::$sitesTable->display( $optimize_for_sites_table );
                static::$sitesTable->clear_items();
                ?>
            </form>
        </div>
        <?php MainWP_UI::render_modal_edit_notes(); ?>
        <div class="ui modal" id="managesites-backup-box" tabindex="0">
            <div class="header"><?php esc_html_e( 'Full backup required', 'mainwp' ); ?></div>
            <div class="content mainwp-modal-content"></div>
            <div class="actions mainwp-modal-actions">
                <input id="managesites-backup-all" type="button" name="Backup All" value="<?php esc_attr_e( 'Backup all', 'mainwp' ); ?>" class="button-primary"/>
                <a id="managesites-backup-now" href="#" target="_blank" style="display: none"  class="button-primary button"><?php esc_html_e( 'Backup Now', 'mainwp' ); ?></a>&nbsp;
                <input id="managesites-backup-ignore" type="button" name="Ignore" value="<?php esc_attr_e( 'Ignore', 'mainwp' ); ?>" class="button"/>
            </div>
        </div>
        <?php
        static::render_screen_options();
        static::render_footer( '' );
    }

    /**
     * Method render_manage_sites()
     *
     * Render Manage Sites Page.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Notification_Template::handle_template_file_action()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function render_manage_sites() { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_REQUEST['do'] ) ) {
            if ( 'new' === $_REQUEST['do'] ) {
                static::render_new_site();
            } elseif ( 'bulknew' === $_REQUEST['do'] ) {
                static::render_bulk_new_site();
            }

            return;
        }

        if ( get_option( 'mainwp_enableLegacyBackupFeature' ) && ! empty( $_GET['backupid'] ) ) {
            $websiteid     = ! empty( $_GET['backupid'] ) ? intval( $_GET['backupid'] ) : false;
            $backupwebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
            if ( MainWP_System_Utility::can_edit_website( $backupwebsite ) ) {
                static::render_backup_site( $backupwebsite );
                return;
            }
        }

        if ( isset( $_GET['scanid'] ) ) {
            $websiteid = intval( $_GET['scanid'] );

            $scanwebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
            if ( MainWP_System_Utility::can_edit_website( $scanwebsite ) ) {
                static::render_scan_site( $scanwebsite );

                return;
            }
        }

        if ( isset( $_GET['dashboard'] ) ) {
            $websiteid = intval( $_GET['dashboard'] );

            $dashboardWebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
            if ( MainWP_System_Utility::can_edit_website( $dashboardWebsite ) ) {
                static::render_dashboard( $dashboardWebsite );

                return;
            }
        }

        if ( isset( $_GET['updateid'] ) ) {
            $websiteid      = intval( $_GET['updateid'] );
            $updatesWebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
            if ( MainWP_System_Utility::can_edit_website( $updatesWebsite ) ) {
                static::render_updates( $updatesWebsite );
                return;
            }
        }

        if ( ( isset( $_GET['action'] ) && 'add_submonitor' === $_GET['action'] ) || ! empty( $_GET['sub_monitor_id'] ) ) {
            $websiteid = intval( $_GET['monitor_wpid'] );
            $website   = MainWP_DB::instance()->get_website_by_id( $websiteid );
            // Show modal.
            static::render_monitor_site( $website );
            return;
        }

        if ( ! empty( $_GET['emailsettingsid'] ) ) {
            $websiteid     = intval( $_GET['emailsettingsid'] );
            $website       = MainWP_DB::instance()->get_website_by_id( $websiteid, false, array( 'settings_notification_emails' ) );
            $updated       = false;
            $updated_templ = false;
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                $updated       = static::update_site_emails_settings_handle( $website );
                $updated_templ = MainWP_Notification_Template::instance()->handle_template_file_action();
            }
            if ( ! empty( $_GET['edit-email'] ) ) {
                // Edit email settings show in separated page in site settings.
                static::render_email_settings( $website, $updated, $updated_templ, true );
                return;
            }
        }

        if ( isset( $_GET['id'] ) ) {
            $websiteid = intval( $_GET['id'] );
            $website   = MainWP_DB::instance()->get_website_by_id( $websiteid );
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                // Edit website.
                $updated = static::update_site_handle( $website );
                static::render_edit_site( $websiteid, $updated );
                return;
            }
        }
        // phpcs:enable

        $sitesViewMode = MainWP_Utility::get_siteview_mode();
        if ( 'grid' === $sitesViewMode ) {
            MainWP_Manage_Screenshots::render_all_sites();
        } else {
            static::render_all_sites();
        }
        MainWP_Logger::instance()->log_execution_time( 'render_manage_sites()' );
    }


    /**
     * Method update_site_emails_settings_handle()
     *
     * Handle site email settings update.
     *
     * @param mixed $website Child Site object.
     *
     * @return bool $updated Updated.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_notification_types()
     * @uses  \MainWP\Dashboard\MainWP_Utility::valid_input_emails()
     */
    private static function update_site_emails_settings_handle( $website ) { // phpcs:ignore -- NOSONAR - complex.
    // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $updated = false;
        if ( isset( $_POST['submit'] ) && isset( $_GET['emailsettingsid'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'UpdateWebsiteEmailSettings' . sanitize_text_field( wp_unslash( $_GET['emailsettingsid'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
            $settings_emails = MainWP_DB::instance()->get_website_option( $website, 'settings_notification_emails', '' );
            $settings_emails = ! empty( $settings_emails ) ? json_decode( $settings_emails, true ) : $settings_emails;
            if ( ! is_array( $settings_emails ) ) {
                $settings_emails = array();
            }

            $notification_emails = MainWP_Notification_Settings::get_notification_types();
            $type                = isset( $_POST['mainwp_managesites_setting_emails_type'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_setting_emails_type'] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification,ized
            $edit_settingEmails  = isset( $_POST['mainwp_managesites_edit_settingEmails'][ $type ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_managesites_edit_settingEmails'][ $type ] ) ) : array();  // phpcs:ignore WordPress.Security.NonceVerification,ized

            if ( isset( $notification_emails[ $type ] ) && ! empty( $edit_settingEmails ) ) {
                $update_settings               = $edit_settingEmails;
                $update_settings['recipients'] = MainWP_Utility::valid_input_emails( $edit_settingEmails['recipients'] );
                $update_settings['disable']    = isset( $edit_settingEmails['disable'] ) ? 0 : 1; // isset 'on' means enable (0), not isset mean disabled (1).

                /**
                * Action: mainwp_before_save_email_settings
                *
                * Fires before save email settings.
                *
                * @since 4.1
                */
                do_action( 'mainwp_before_save_email_settings', $type, $update_settings, $website );

                $settings_emails[ $type ] = $update_settings;
                MainWP_DB::instance()->update_website_option( $website, 'settings_notification_emails', wp_json_encode( $settings_emails ) );
                $updated = true;

                /**
                * Action: mainwp_after_save_email_settings
                *
                * Fires after save email settings.
                *
                * @since 4.1
                */
                do_action( 'mainwp_after_save_email_settings', $settings_emails );

            }
        }
    // phpcs:enable
        return $updated;
    }

    /**
     * Method update_site_handle()
     *
     * Handle site update.
     *
     * @param mixed $website Child Site object.
     *
     * @return bool $updated Updated.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::update_website()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_option()
     * @uses  \MainWP\Dashboard\MainWP_Utility::remove_http_prefix()
     * @uses  \MainWP\Dashboard\MainWP_Utility::valid_input_emails()
     */
    private static function update_site_handle( $website ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

    // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $updated = false;
        if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && isset( $_REQUEST['id'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && ( '' !== $_POST['mainwp_managesites_edit_siteadmin'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'UpdateWebsite' . sanitize_key( $_REQUEST['id'] ) ) && \mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) {
            // update site.
            $groupids   = array();
            $groupnames = array();
            if ( isset( $_POST['mainwp_managesites_edit_addgroups'] ) && ! empty( $_POST['mainwp_managesites_edit_addgroups'] ) ) {
                $groupids = explode( ',', sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_addgroups'] ) ) );
            }

            // to fix update staging site.
            if ( $website->is_staging ) {
                $stag_gid = get_option( 'mainwp_stagingsites_group_id' );
                if ( $stag_gid && ! in_array( $stag_gid, $groupids, true ) ) {
                    $groupids[] = $stag_gid;
                }
            }

            $newPluginDir = '';

            $maximumFileDescriptorsOverride = isset( $_POST['mainwp_options_maximumFileDescriptorsOverride'] );
            $maximumFileDescriptorsAuto     = isset( $_POST['mainwp_maximumFileDescriptorsAuto'] );
            $maximumFileDescriptors         = isset( $_POST['mainwp_options_maximumFileDescriptors'] ) ? intval( $_POST['mainwp_options_maximumFileDescriptors'] ) : 150;

            $archiveFormat = isset( $_POST['mainwp_archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_archiveFormat'] ) ) : 'global';

            $http_user     = isset( $_POST['mainwp_managesites_edit_http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_http_user'] ) ) : '';
            $http_pass     = isset( $_POST['mainwp_managesites_edit_http_pass'] ) ? wp_unslash( $_POST['mainwp_managesites_edit_http_pass'] ) : '';
            $url           = ( isset( $_POST['mainwp_managesites_edit_wpurl_with_www'] ) && ( 'www' === sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_wpurl_with_www'] ) ) ) ? 'www.' : '' ) . MainWP_Utility::remove_http_www_prefix( $website->url, true );
            $url           = ( isset( $_POST['mainwp_managesites_edit_siteurl_protocol'] ) && ( 'https' === sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_siteurl_protocol'] ) ) ) ? 'https' : 'http' ) . '://' . MainWP_Utility::remove_http_prefix( $url, true );
            $backup_method = isset( $_POST['mainwp_primaryBackup'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_primaryBackup'] ) ) : 'global';

            $disableHealthChecking = isset( $_POST['mainwp_managesites_edit_disableSiteHealthMonitoring'] ) ? 0 : 1;
            $healthThreshold       = isset( $_POST['mainwp_managesites_edit_healthThreshold'] ) ? intval( $_POST['mainwp_managesites_edit_healthThreshold'] ) : 0; // 0 use global settings.

            $site_name         = isset( $_POST['mainwp_managesites_edit_sitename'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_sitename'] ) ) : '';
            $site_admin        = isset( $_POST['mainwp_managesites_edit_siteadmin'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_siteadmin'] ) ) : '';
            $verifycertificate = isset( $_POST['mainwp_managesites_edit_verifycertificate'] ) ? intval( $_POST['mainwp_managesites_edit_verifycertificate'] ) : 2;
            $uniqueId          = isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_uniqueId'] ) ) : '';
            $ssl_version       = isset( $_POST['mainwp_managesites_edit_ssl_version'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_ssl_version'] ) ) : '';

            MainWP_DB::instance()->update_website( $website->id, $url, $current_user->ID, $site_name, $site_admin, $groupids, $groupnames, $newPluginDir, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $verifycertificate, $archiveFormat, $uniqueId, $http_user, $http_pass, $ssl_version, $disableHealthChecking, $healthThreshold, $backup_method );

            $new_client_id = isset( $_POST['mainwp_managesites_edit_client_id'] ) ? intval( $_POST['mainwp_managesites_edit_client_id'] ) : 0;

            if ( $website->client_id !== $new_client_id ) {

                $update = array(
                    'client_id' => $new_client_id,
                );
                MainWP_DB::instance()->update_website_values( $website->id, $update );
            }

            /**
             * Update site
             *
             * Fires after updating a website settings.
             *
             * @param int $website->id Child site ID.
             *
             * @since 3.4
             */
            do_action( 'mainwp_update_site', $website->id );

            $backup_before_upgrade = isset( $_POST['mainwp_backup_before_upgrade'] ) ? intval( $_POST['mainwp_backup_before_upgrade'] ) : 2;
            if ( 2 < $backup_before_upgrade ) {
                $backup_before_upgrade = 2;
            }

            $forceuseipv4 = isset( $_POST['mainwp_managesites_edit_forceuseipv4'] ) ? intval( $_POST['mainwp_managesites_edit_forceuseipv4'] ) : 0;
            if ( 2 < $forceuseipv4 ) {
                $forceuseipv4 = 0;
            }

            $old_suspended = (int) $website->suspended;
            $suspended     = isset( $_POST['mainwp_suspended_site'] ) ? 1 : 0;
            $newValues     = array(
                'automatic_update'      => ( ! isset( $_POST['mainwp_automaticDailyUpdate'] ) ? 0 : 1 ),
                'backup_before_upgrade' => $backup_before_upgrade,
                'force_use_ipv4'        => $forceuseipv4,
                'loadFilesBeforeZip'    => isset( $_POST['mainwp_options_loadFilesBeforeZip'] ) ? 1 : 0,
                'suspended'             => $suspended,
            );

            if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
                $newValues['is_ignoreCoreUpdates']   = ! empty( $_POST['mainwp_is_ignoreCoreUpdates'] ) ? 1 : 0;
                $newValues['is_ignorePluginUpdates'] = ! empty( $_POST['mainwp_is_ignorePluginUpdates'] ) ? 1 : 0;
                $newValues['is_ignoreThemeUpdates']  = ! empty( $_POST['mainwp_is_ignoreThemeUpdates'] ) ? 1 : 0;
            }

            MainWP_DB::instance()->update_website_values( $website->id, $newValues );

            $monitoring_emails = isset( $_POST['mainwp_managesites_edit_monitoringNotificationEmails'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_monitoringNotificationEmails'] ) ) : '';
            $monitoring_emails = MainWP_Utility::valid_input_emails( $monitoring_emails );
            MainWP_DB::instance()->update_website_option( $website, 'monitoring_notification_emails', trim( $monitoring_emails ) );

            $added = isset( $_POST['mainwp_managesites_edit_dt_added'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_dt_added'] ) ) : '';
            if ( ! empty( $added ) ) {
                $added = strtotime( $added );
            }
            MainWP_DB::instance()->update_website_option( $website, 'added_timestamp', $added );

            $new_alg = isset( $_POST['mainwp_managesites_edit_openssl_alg'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_openssl_alg'] ) ) : '';
            MainWP_DB::instance()->update_website_option( $website, 'signature_algo', $new_alg );

            $use_lib = isset( $_POST['mainwp_managesites_edit_verify_connection_method'] ) ? intval( $_POST['mainwp_managesites_edit_verify_connection_method'] ) : 0;
            MainWP_DB::instance()->update_website_option( $website, 'verify_method', $use_lib );

            $uploaded_site_icon = isset( $_POST['mainwp_managesites_edit_site_uploaded_icon_hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_site_uploaded_icon_hidden'] ) ) : '';
            $selected_site_icon = isset( $_POST['mainwp_managesites_edit_site_selected_icon_hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_site_selected_icon_hidden'] ) ) : '';
            $cust_icon_color    = isset( $_POST['mainwp_managesites_edit_site_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['mainwp_managesites_edit_site_color'] ) ) : '';
            $icon_info          = 'uploaded:' . $uploaded_site_icon . ';selected:' . $selected_site_icon . ';color:' . $cust_icon_color;
            MainWP_DB::instance()->update_website_option( $website, 'cust_site_icon_info', $icon_info );

            if ( ! empty( $website->cust_site_icon_info ) ) {
                $current_uploaded = static::get_instance()->get_cust_site_icon( $website->cust_site_icon_info, 'uploaded' );
                if ( ! empty( $current_uploaded ) && $current_uploaded !== $uploaded_site_icon ) {
                    MainWP_Utility::instance()->delete_uploaded_icon_file( 'site-icons', $current_uploaded ); // delete old icon.
                }
            }

            /**
             * Update site
             *
             * Fires after updating a website settings.
             *
             * @param int $website Child site Ọbject.
             *
             * @since 3.4
             */
            do_action( 'mainwp_site_updated', $website, $_POST );

            if ( $old_suspended !== $suspended ) { // changed suspended.
                /**
                 * Site suspended changed.
                 *
                 * Fires after suspended a website changed.
                 *
                 * @param int $website->id Child site ID.
                 *
                 * @since 4.5.1.1
                 */
                do_action( 'mainwp_site_suspended', $website, $suspended );
            }
            $updated = true;
        }
    // phpcs:enable
        return $updated;
    }

    /**
     * Method render_edit_site()
     *
     * Render edit site.
     *
     * @param mixed $websiteid Child Site ID.
     * @param mixed $updated Updated.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_edit_site()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
     */
    public static function render_edit_site( $websiteid, $updated ) {
        if ( $websiteid ) {
            MainWP_System_Utility::set_current_wpid( $websiteid );
        }
        static::render_header( 'ManageSitesEdit' );
        MainWP_Manage_Sites_View::render_edit_site( $websiteid, $updated );
        static::render_footer( 'ManageSitesEdit' );
    }

    /**
     * Method on_edit_site()
     *
     * Render on edit.
     *
     * @param object $website The website object.
     */
    public static function on_edit_site( $website ) {
        if ( isset( $_POST['submit'] ) && isset( $_REQUEST['id'] ) && isset( $_POST['wp_nonce'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && ( '' !== $_POST['mainwp_managesites_edit_siteadmin'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'UpdateWebsite' . sanitize_key( $_REQUEST['id'] ) ) && isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
            ?>
            <script type="text/javascript">
                jQuery( document ).ready( function () {
                    mainwp_managesites_update_childsite_value( <?php echo esc_attr( $website->id ); ?>, '<?php echo esc_js( $website->uniqueId ); ?>' );
                } );
            </script>
            <?php
        }
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        // @NO_SONAR_START@ start block.
        if ( isset( $_GET['page'] ) && 'managesites' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
            if ( isset( $_GET['do'] ) && 'new' === $_GET['do'] ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
                ?>
                <p><?php esc_html_e( 'If you need help connecting your websites, please review following help documents', 'mainwp' ); ?></p>
                <div class="ui list">
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/get-started-with-mainwp/#install-mainwp-dashboard" target="_blank">Set up the MainWP Plugin</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/get-started-with-mainwp/#install-mainwp-child" target="_blank">Install MainWP Child</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#add-a-site-to-your-dashboard" target="_blank">Add a Site to your Dashboard</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#import-sites" target="_blank">Import Sites</a></div> <?php // NOSONAR - noopener - open safe. ?>
                </div>
                <?php
            } elseif ( isset( $_GET['do'] ) && 'bulknew' === $_GET['do'] ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
                ?>
                <p><?php esc_html_e( 'If you need help connecting your websites, please review following help documents', 'mainwp' ); ?></p>
                <div class="ui list">
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/get-started-with-mainwp/#install-mainwp-dashboard" target="_blank">Set up the MainWP Plugin</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/get-started-with-mainwp/#install-mainwp-child" target="_blank">Install MainWP Child</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#add-a-site-to-your-dashboard" target="_blank">Add a Site to your Dashboard</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#import-sites" target="_blank">Import Sites</a></div> <?php // NOSONAR - noopener - open safe. ?>
                </div>
                <?php
            } else {
                ?>
                <p><?php esc_html_e( 'If you need help with managing child sites, please review following help documents', 'mainwp' ); ?></p>
                <div class="ui list">
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/" target="_blank">Manage Child Sites</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#access-child-site-wp-admin" target="_blank">Access Child Site WP Admin</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#synchronize-a-child-site" target="_blank">Synchronize a Child Site</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#edit-a-child-site" target="_blank">Edit a Child Site</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#reconnect-a-child-site" target="_blank">Reconnect a Child Site</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#delete-a-child-site" target="_blank">Delete a Child Site</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#security-issues" target="_blank">Security Issues</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#manage-child-site-tags" target="_blank">Manage Child Site Tags</a></div> <?php // NOSONAR - noopener - open safe. ?>
                    <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-child-sites/#manage-child-site-notes" target="_blank">Manage Child Site Notes</a></div> <?php // NOSONAR - noopener - open safe. ?>
                </div>
                <?php
            }
        }
        // @NO_SONAR_END@ end of block.
    }

    /**
     * Method mainwp_managesites_information_import_sites()
     *
     * @param bool $is_qsw is qsw.
     *
     * Display Import site information.
     */
    public static function mainwp_managesites_information_import_sites( $is_qsw = false ) {
        ?>
        <?php esc_html_e( 'MainWP requires the MainWP Child plugin to be installed and activated on the WordPress sites that you want to connect to your MainWP Dashboard. ', 'mainwp' ); ?>
        <?php esc_html_e( 'To connect multiple sites, please follow these steps:', 'mainwp' ); ?>
        <ol>
            <li><?php esc_html_e( 'Fill in the Site URL field by entering the URL of the website you want to connect.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'Enter the username of your Administrator account on the site.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'Optionally, enter a custom Site Name, or MainWP will automatically generate a name for it.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'Enter the administrator account password. If the requirement is disabled in the MainWP Child plugin settings, leave blank.', 'mainwp' ); ?></li>
        <?php if ( $is_qsw ) : ?>
            <li><?php esc_html_e( 'To show additional fields (Tags, Security ID, HTTP Username, HTTP Password, SSL Version, and SSL Verification, click the eye icon at the end of the row.', 'mainwp' ); ?></li>
            <?php endif; ?>
            <li><?php esc_html_e( 'If needed, you can tag your sites in the Tag field.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'If you have enabled the Unique Security ID in the MainWP Child plugin settings, enter the Security ID in the corresponding field.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'If the website you are trying to connect is protected with HTTP Basic Auth, enter the HTTP Username & Password. If not, leave it blank.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'Keep the default value of "1" to verify the SSL certificate for your sites, or change it if you prefer not to verify.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'Leave the SSL Version as "auto" unless you know the specific version required by the site. The "auto" option typically works for most sites.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'Repeat this process for all sites you want to add.', 'mainwp' ); ?></li>
            <li><?php esc_html_e( 'Use the Add New Row button to add additional rows if you need to connect more sites at once.', 'mainwp' ); ?></li>
        <?php if ( ! $is_qsw ) : ?>
            <li><?php esc_html_e( 'After filling in all the required information, submit the form to connect the selected sites to your MainWP Dashboard.', 'mainwp' ); ?></li>
            <?php else : ?>
            <li><?php esc_html_e( 'After filling in all the required information, confirm that you have the MainWP Child plugin installed and activated and click the Connect Sites button to proceed.', 'mainwp' ); ?></li>
            <?php endif; ?>
        </ol>
        <span class="ui small text">* <?php esc_html_e( 'Your password is never stored by your Dashboard and never sent to MainWP.com. Once this initial connection is complete, your MainWP Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected, just be sure not to delete the admin account, as this would disrupt the connection.', 'mainwp' ); ?></span>
        <?php
    }

    /**
     * Method mainwp_managesites_form_import_sites()
     *
     * Creates the form import websites.
     *
     * @uses MainWP_DB::instance()->get_general_option()
     * @uses MainWP_DB::instance()->update_general_option()
     */
    public static function mainwp_managesites_form_import_sites() {
        $temp_sites = MainWP_DB::instance()->get_general_option( 'temp_import_sites', 'array' );

        // Reset key of temp_sites.
        if ( ! empty( $temp_sites ) && is_array( $temp_sites ) ) {
            $temp_sites = array_values( $temp_sites );
            MainWP_DB::instance()->update_general_option( 'temp_import_sites', $temp_sites, 'array' );
        }

        // Get total rows import site default 5.
        $total_sites   = max( 5, count( $temp_sites ) );
        $is_page_setup = isset( $_GET['page'] ) && 'mainwp-setup' === $_GET['page'] ? true : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $display_none  = $is_page_setup ? 'display:none' : '';
        ?>
        <div>
            <div class="ui left aligned middle aligned very compact stackable grid" id="mainwp-managesites-row-import-sites">
                <div class="ui row">
                    <div class="<?php echo $is_page_setup ? 'four' : 'two'; ?> wide column" >
                        <span class="ui text small"><strong><?php esc_html_e( 'Site URL (required)', 'mainwp' ); ?></strong></span>
                    </div>
                    <div class="<?php echo $is_page_setup ? 'four' : 'two'; ?> wide column">
                        <span class="ui text small"><?php esc_html_e( 'Site Name', 'mainwp' ); ?></span>
                    </div>
                    <div class="<?php echo $is_page_setup ? 'four' : 'two'; ?> wide column">
                        <span class="ui text small"><strong><?php esc_html_e( 'Admin Name (required)', 'mainwp' ); ?></strong></span>
                    </div>
                    <div class="<?php echo $is_page_setup ? 'three' : 'two'; ?> wide column">
                        <span class="ui text small"><strong><?php esc_html_e( 'Admin Password *', 'mainwp' ); ?></strong></span>
                    </div>
                <?php if ( ! $is_page_setup ) : ?>
                        <div class="one wide column">
                            <span class="ui text small"><?php esc_html_e( 'Tag', 'mainwp' ); ?></span>
                        </div>
                        <div class="one wide column">
                            <span class="ui text small"><?php esc_html_e( 'Security ID', 'mainwp' ); ?></span>
                        </div>
                        <div class="two wide column">
                            <span class="ui text small"><?php esc_html_e( 'HTTP Username', 'mainwp' ); ?></span>
                        </div>
                        <div class="one wide column">
                            <span class="ui text small"><?php esc_html_e( 'HTTP Password', 'mainwp' ); ?></span>
                        </div>
                        <div class="one wide column">
                            <span class="ui text small"><?php esc_html_e( 'Verify SSL', 'mainwp' ); ?></span>
                        </div>
                        <div class="one wide column">
                            <span class="ui text small"><?php esc_html_e( 'SSL Version', 'mainwp' ); ?></span>
                        </div>
                        <div class="one wide column">
                            <span></span>
                        </div>
                    <?php else : ?>
                        <div class="one wide column"></div>
                    <?php endif; ?>
                </div>
            <?php
            $fields = self::mainwp_managesites_form_import_sites_fields();
            for ( $x = 0; $x < $total_sites; $x++ ) {
                $site = ! empty( $temp_sites[ $x ] ) ? $temp_sites[ $x ] : array();
                self::mainwp_managesites_form_import_sites_row( $x, $site, $is_page_setup, $display_none, $fields );
            }
            ?>
                <div  class="add-row row" id="mainwp-managesites-add-row">
                    <div class="sixteen wide column"><a href="#" class="ui mini basic button" id="mainwp-managesites-import-row" data-default-row="<?php echo esc_attr( $total_sites ); ?>" data-page-setup="<?php echo esc_attr( $is_page_setup ); ?>"><?php esc_html_e( 'Add New Row', 'mainwp' ); ?></a></div>
                </div>
            </div>
        </div>
            <?php
    }

    /**
     * Method mainwp_managesites_form_import_sites_fields();
     *
     * Array containing fields value
     *
     * @return array array data
     */
    public static function mainwp_managesites_form_import_sites_fields() {
        $default_class = 'mainwp-managesites-import';
        return array(
            'fields'          => array(
                'site_url'       => array(
                    'label' => 'Site URL',
                    'class' => $default_class . '-site-url',
                    'id'    => $default_class . '-site-url',
                ),
                'site_name'      => array(
                    'label' => 'Site Name',
                    'class' => $default_class . '-site-name',
                    'id'    => $default_class . '-site-name',
                ),
                'admin_name'     => array(
                    'label' => 'Admin Name',
                    'class' => $default_class . '-admin-name',
                    'id'    => $default_class . '-admin-name',
                ),
                'admin_password' => array(
                    'label' => 'Admin Password',
                    'class' => $default_class . '-admin-password',
                    'id'    => $default_class . '-admin-password',
                    'type'  => 'password',
                ),
            ),
            'optional_fields' => array(
                'tag'                => array(
                    'label' => 'Tag',
                    'class' => $default_class . '-tag',
                    'id'    => $default_class . '-tag-',
                ),
                'security_id'        => array(
                    'label' => 'Security ID',
                    'class' => $default_class . '-security-id',
                    'id'    => $default_class . '-security-id',
                ),
                'http_username'      => array(
                    'label' => 'HTTP Username',
                    'class' => $default_class . '-http-username',
                    'id'    => $default_class . '-http-usernam',
                ),
                'http_password'      => array(
                    'label' => 'HTTP Password',
                    'class' => $default_class . '-http-password',
                    'id'    => $default_class . '-http-password',
                ),
                'verify_certificate' => array(
                    'label'      => 'Verify Certificate',
                    'input_type' => 'number',
                    'default'    => 1,
                    'class'      => 'mini ' . $default_class . '-verify-certificate',
                    'id'         => $default_class . '-verify-certificate',
                ),
                'ssl_version'        => array(
                    'label'   => 'SSL Version',
                    'default' => 'auto',
                    'class'   => 'mini ' . $default_class . '-ssl-version',
                    'id'      => $default_class . '-ssl-version',
                ),
            ),
        );
    }
    /**
     * Method mainwp_managesites_form_import_sites_render_input()
     * Html input rendering.
     *
     * @param int    $index row index.
     * @param string $key_optional field key.
     * @param mixed  $field_optional field value.
     * @param array  $field field data.
     * @param array  $site site row data.
     */
    public static function mainwp_managesites_form_import_sites_render_input( $index, $key_optional, $field_optional, $field, $site ) {
        $input_optional = isset( $field_optional['default'] ) ? $field_optional['default'] : '';
        $value          = static::mainwp_managesites_form_import_sites_get_value( $site, $key_optional, $input_optional );
        $index_rand     = wp_rand( 0, 9999 );
        ?>
        <div class="ui mini fluid input">
            <input type="text" name="mainwp_managesites_import[<?php echo esc_attr( $index ); ?>][<?php echo esc_attr( $key_optional ); ?>]" value="<?php echo esc_attr( $value ); ?>" data-row-index="<?php echo esc_attr( $index ); ?>"  <?php echo isset( $field_optional['input_type'] ) && 'number' === $field_optional['input_type'] ? 'oninput="this.value = this.value.replace(/[^0-9]/g, \'\')"' : ''; ?> id="<?php echo esc_attr( $field['id'] . '-' . $index . '-' . $index_rand ); ?>" class="<?php echo esc_attr( $field['class'] ); ?>"/>
        </div>
        <?php
    }

    /**
     * Method mainwp_managesites_form_import_sites_get_value()
     *
     * Get the result of input.
     *
     * @param array  $site site row data.
     * @param string $key field name.
     * @param string $default_value value.
     * @return mixed value of input
     */
    public static function mainwp_managesites_form_import_sites_get_value( $site, $key, $default_value = '' ) {
        return isset( $site[ $key ] ) ? esc_attr( $site[ $key ] ) : $default_value;
    }

    /**
     * Method mainwp_managesites_form_import_sites_row()
     *
     * Creates the form import row websites.
     *
     * @param int    $index row index.
     * @param array  $site row site data.
     * @param bool   $is_page_setup is page setup or not found.
     * @param string $display_none display none if page setup wizard.
     * @param array  $fields fields data.
     */
    public static function mainwp_managesites_form_import_sites_row( $index, $site, $is_page_setup, $display_none, $fields ) { // phpcs:ignore -- NOSONAR - complex.
        $general_fields  = $fields['fields'];
        $optional_fields = $fields['optional_fields'];
        $row_class       = $is_page_setup ? 'four' : 'two';
        ?>
        <div class="row mainwp-managesites-import-rows" id="mainwp-managesites-import-row-<?php echo esc_attr( $index ); ?>" data-index="<?php echo esc_attr( $index ); ?>">
        <?php
        foreach ( $general_fields as $key => $field ) :
            if ( 'admin_password' === $key ) {
                $row_class = $is_page_setup ? 'three' : 'two';
            }
            $field_value = self::mainwp_managesites_form_import_sites_get_value( $site, $key, '' );
            $field_id    = $field['id'] . '-' . $index;
            ?>
            <div class="<?php echo esc_attr( $row_class ); ?> wide column">
                <div class="ui mini fluid input">
                    <input type="<?php echo isset( $field['type'] ) && 'password' === $field['type'] ? 'password' : 'text'; ?>" name="mainwp_managesites_import[<?php echo esc_attr( $index ); ?>][<?php echo esc_attr( $key ); ?>]" class="<?php echo esc_attr( $field['class'] ); ?>" value="<?php echo esc_attr( $field_value ); ?>"  data-row-index="<?php echo esc_attr( $index ); ?>" id="<?php echo esc_attr( $field_id ); ?>"/>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ( ! $is_page_setup ) : ?>
            <?php foreach ( $optional_fields as $key_optional => $field_optional ) : ?>
                <?php $one_class = array( 'security_id', 'verify_certificate', 'ssl_version', 'tag', 'http_password' ); ?>
                <div class="<?php echo in_array( $key_optional, $one_class, true ) ? 'one' : 'two'; ?> wide column">
                    <?php self::mainwp_managesites_form_import_sites_render_input( $index, $key_optional, $field_optional, $field, $site ); ?>
                </div>
            <?php endforeach; ?>
            <div class="column">
                <div class="">
                    <a class="mainwp-managesites-delete-import-row" href="javascript:void(0)" onclick="mainwp_managesites_import_sites_delete_row(<?php echo esc_attr( $index ); ?>)">
                        <i class="trash alternate outline icon"></i>
                    </a>
                </div>
            </div>
        <?php else : ?>
            <div class="one wide column">
                <div class="ui mini fluid input">
                    <a class="mainwp-managesites-more-import-row " onclick="mainwp_managesites_import_sites_more_row(<?php echo esc_attr( $index ); ?>)" style="margin-right: 10px !important;" href="javascript:void(0)">
                        <i class="eye outline icon"  id="icon-visible-<?php echo esc_attr( $index ); ?>"></i>
                        <i class="eye slash outline icon" id="icon-hidden-<?php echo esc_attr( $index ); ?>" style="<?php echo esc_attr( $display_none ); ?>"></i>
                    </a>
                    <a class="mainwp-managesites-delete-import-row" href="javascript:void(0)" onclick="mainwp_managesites_import_sites_delete_row(<?php echo esc_attr( $index ); ?>)">
                        <i class="trash alternate outline icon"></i>
                    </a>
                </div>
            </div>
            <?php foreach ( $optional_fields as $key_optional => $field_optional ) : ?>
                <div class="<?php echo ( 'verify_certificate' === $key_optional || 'ssl_version' === $key_optional ) ? 'two' : 'three'; ?> wide column mainwp-managesites-import-column-more-<?php echo esc_attr( $index ); ?>" style="<?php echo esc_attr( $display_none ); ?>">
                    <div class="">
                        <span class="ui small text"><?php echo esc_html( $field_optional['label'] ); ?></span>
                        <?php self::mainwp_managesites_form_import_sites_render_input( $index, $key_optional, $field_optional, $field, $site ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Method render_import_sites_modal()
     *
     * Render import sites modal.
     *
     * @uses MainWP_Manage_Sites_View::render_import_sites()
     *
     * @param string      $url location url href.
     * @param string|null $title_page modal title.
     */
    public static function render_import_sites_modal( $url, $title_page = null ) {
        ?>
        <div class="ui large modal <?php echo ( 'Import Sites' === $title_page ) ? 'mainwp-qsw-import-modal' : ''; ?>" id="mainwp-import-sites-modal" data-page-url="<?php echo esc_url( $url ); ?>" >
        <?php if ( 'Import Sites' !== $title_page ) : ?>
                <i class="close icon"></i>
            <?php endif; ?>
            <div class="header"><?php echo esc_html( $title_page ); ?></div>
            <div class="scrolling content">
        <?php MainWP_Manage_Sites_View::render_import_sites(); ?>
            </div>
            <div class="actions">
                <div class="ui two column grid">
                    <div class="left aligned middle aligned column">
                        <input type="button" name="mainwp_managesites_btn_import" id="mainwp_managesites_btn_import" class="ui basic button" value="<?php esc_attr_e( 'Pause', 'mainwp' ); ?>"/>
                    <?php if ( 'Import Sites' === $title_page ) : ?>
                            <a class="ui green button" id="mainwp-import-sites-modal-try-again" href="admin.php?page=mainwp-setup&step=connect_first_site" style="display:none"><?php esc_html_e( 'Try Again', 'mainwp' ); ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="right aligned middle aligned column">
                    <?php if ( 'Import Sites' === $title_page ) : ?>
                            <a class="ui basic green button" id="mainwp-import-sites-modal-continue" href="<?php echo esc_url( $url ); ?>" style="display:none"><?php esc_html_e( 'Continue', 'mainwp' ); ?></a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( "#mainwp-import-sites-modal" ).modal( {
                    closable: false,
                    onHide: function() {
                        location.href = '<?php echo ! empty( $url ) ? $url : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>';
                    }
                } ).modal( 'show' );
            } );
        </script>
        <?php
    }
}
