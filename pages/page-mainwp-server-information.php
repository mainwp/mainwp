<?php
/**
 * MainWP Info Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.
/**
 * Class MainWP_Server_Information
 */
class MainWP_Server_Information { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    const WARNING = 1;
    const ERROR   = 2;

    /**
     * The Info page sub-pages.
     *
     * @static
     * @var array Sub pages.
     */
    public static $subPages;

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method init_menu()
     *
     * Initiate Info subPage menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_apache_server_software()
     */
    public static function init_menu() { // phpcs:ignore -- NOSONAR - complex.

        add_action( 'mainwp_pageheader_infor', array( static::get_class_name(), 'render_header' ) );
        add_action( 'mainwp_pagefooter_infor', array( static::get_class_name(), 'render_footer' ) );

        add_submenu_page(
            'mainwp_tab',
            __( 'Info', 'mainwp' ),
            ' <span id="mainwp-ServerInformation">' . esc_html__( 'Info', 'mainwp' ) . '</span>',
            'read',
            'ServerInformation',
            array(
                static::get_class_name(),
                'render',
            )
        );
        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) {
            add_submenu_page(
                'mainwp_tab',
                __( 'Cron Schedules', 'mainwp' ),
                '<div class="mainwp-hidden">' . esc_html__( 'Cron Schedules', 'mainwp' ) . '</div>',
                'read',
                'ServerInformationCron',
                array(
                    static::get_class_name(),
                    'render_cron',
                )
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) {
            add_submenu_page(
                'mainwp_tab',
                __( 'Error Log', 'mainwp' ),
                '<div class="mainwp-hidden">' . esc_html__( 'Error Log', 'mainwp' ) . '</div>',
                'read',
                'ErrorLog',
                array(
                    static::get_class_name(),
                    'render_error_log_page',
                )
            );
        }
        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'WPConfig' ) ) {
            add_submenu_page(
                'mainwp_tab',
                __( 'WP-Config File', 'mainwp' ),
                '<div class="mainwp-hidden">' . esc_html__( 'WP-Config File', 'mainwp' ) . '</div>',
                'read',
                'WPConfig',
                array(
                    static::get_class_name(),
                    'render_wp_config',
                )
            );
        }
        if ( ! MainWP_Menu::is_disable_menu_item( 3, '.htaccess' ) && MainWP_Server_Information_Handler::is_apache_server_software() ) {
            add_submenu_page(
                'mainwp_tab',
                __( '.htaccess File', 'mainwp' ),
                '<div class="mainwp-hidden">' . esc_html__( '.htaccess File', 'mainwp' ) . '</div>',
                'read',
                '.htaccess',
                array(
                    static::get_class_name(),
                    'render_htaccess',
                )
            );
        }
        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ActionLogs' ) ) {
            add_submenu_page(
                'mainwp_tab',
                __( 'Custom Event Monitor', 'mainwp' ),
                '<div class="mainwp-hidden">' . esc_html__( 'Custom Event Monitor', 'mainwp' ) . '</div>',
                'read',
                'ActionLogs',
                array(
                    static::get_class_name(),
                    'render_action_logs',
                )
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginPrivacy' ) ) {
            add_submenu_page(
                'mainwp_tab',
                __( 'Plugin Privacy', 'mainwp' ),
                '<div class="mainwp-hidden">' . esc_html__( 'Plugin Privacy', 'mainwp' ) . '</div>',
                'read',
                'PluginPrivacy',
                array(
                    static::get_class_name(),
                    'render_plugin_privacy_page',
                )
            );
        }

        /**
         * Filter mainwp_getsubpages_server
         *
         * Filters subpages for the Info page.
         *
         * @since Unknown
         */
        $sub_pages        = apply_filters_deprecated( 'mainwp-getsubpages-server', array( array() ), '4.0.7.2', 'mainwp_getsubpages_server' ); // NOSONAR - not IP.
        static::$subPages = apply_filters( 'mainwp_getsubpages_server', $sub_pages );

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( ! isset( $subPage['slug'] ) ) {
                    continue;
                }
                if ( MainWP_Menu::is_disable_menu_item( 3, 'Server' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Server' . $subPage['slug'], $subPage['callback'] );
            }
        }
    }

    /**
     * Renders Sub Pages Menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_apache_server_software()
     */
    public static function init_subpages_menu() { // phpcs:ignore -- NOSONAR - complex.
        ?>
        <div id="menu-mainwp-ServerInformation" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ServerInformation' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Server', 'mainwp' ); ?></a>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ServerInformationCron' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Cron Schedules', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ErrorLog' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Error Log', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'WPConfig' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=WPConfig' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'WP-Config File', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php
                    if ( ! MainWP_Menu::is_disable_menu_item( 3, '.htaccess' ) && MainWP_Server_Information_Handler::is_apache_server_software() ) {
                        ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=.htaccess' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( '.htaccess File', 'mainwp' ); ?></a>
                        <?php
                    }
                    ?>
                    <?php
                    if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
                        foreach ( static::$subPages as $subPage ) {
                            if ( ! isset( $subPage['menu_hidden'] ) || ( isset( $subPage['menu_hidden'] ) && true !== $subPage['menu_hidden'] ) ) {
                                if ( MainWP_Menu::is_disable_menu_item( 3, 'Server' . $subPage['slug'] ) ) {
                                    continue;
                                }
                                ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=Server' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
                                <?php
                            }
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Initiates Server Information left menu.
     *
     * @param array $subPages array of subpages.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_apache_server_software()
     */
    public static function init_left_menu( $subPages = array() ) {
        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'Info', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'ServerInformation',
                'href'       => 'admin.php?page=ServerInformation',
                'icon'       => '<i class="info circle icon"></i>',
            ),
            0
        );

        /**
         * MainWP active menu slugs array.
         *
         * @global object
         */
        global $_mainwp_menu_active_slugs;

        $_mainwp_menu_active_slugs['ActionLogs'] = 'ServerInformation';

        $init_sub_subleftmenu = array(
            array(
                'title'      => esc_html__( 'Server', 'mainwp' ),
                'parent_key' => 'ServerInformation',
                'href'       => 'admin.php?page=ServerInformation',
                'slug'       => 'ServerInformation',
                'right'      => '',
            ),
            array(
                'title'      => esc_html__( 'Cron Schedules', 'mainwp' ),
                'parent_key' => 'ServerInformation',
                'href'       => 'admin.php?page=ServerInformationCron',
                'slug'       => 'ServerInformationCron',
                'right'      => '',
            ),
            array(
                'title'      => esc_html__( 'Error Log', 'mainwp' ),
                'parent_key' => 'ServerInformation',
                'href'       => 'admin.php?page=ErrorLog',
                'slug'       => 'ErrorLog',
                'right'      => '',
            ),
            array(
                'title'      => esc_html__( 'Custom Event Monitor', 'mainwp' ),
                'parent_key' => 'ServerInformation',
                'href'       => 'admin.php?page=ActionLogs',
                'slug'       => 'ActionLogs',
                'right'      => '',
            ),
            array(
                'title'      => esc_html__( 'Plugin Privacy', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'href'       => 'admin.php?page=PluginPrivacy',
                'slug'       => 'PluginPrivacy',
                'right'      => '',
            ),
        );

        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ServerInformation', 'Server' );
        foreach ( $init_sub_subleftmenu as $item ) {
            if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
                continue;
            }
            MainWP_Menu::add_left_menu( $item, 2 );
        }
    }

    /**
     * Renders Info header.
     *
     * @param string $shownPage Current page.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_apache_server_software()
     */
    public static function render_header( $shownPage = '' ) {
            $params = array(
                'title' => esc_html__( 'Info', 'mainwp' ),
            );

            MainWP_UI::render_top_header( $params );

            $renderItems = array();

            $renderItems[] = array(
                'title'  => esc_html__( 'Server', 'mainwp' ),
                'href'   => 'admin.php?page=ServerInformation',
                'active' => ( '' === $shownPage ) ? true : false,
            );

            if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) {
                $renderItems[] = array(
                    'title'  => esc_html__( 'Cron Schedules', 'mainwp' ),
                    'href'   => 'admin.php?page=ServerInformationCron',
                    'active' => ( 'ServerInformationCron' === $shownPage ) ? true : false,
                );
            }

            if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) {
                $renderItems[] = array(
                    'title'  => esc_html__( 'Error Log', 'mainwp' ),
                    'href'   => 'admin.php?page=ErrorLog',
                    'active' => ( 'ErrorLog' === $shownPage ) ? true : false,
                );
            }

            if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ActionLogs' ) ) {
                $renderItems[] = array(
                    'title'  => esc_html__( 'Custom Event Monitor', 'mainwp' ),
                    'href'   => 'admin.php?page=ActionLogs',
                    'active' => ( 'ActionLogs' === $shownPage ) ? true : false,
                );
            }

            if ( isset( $_GET['page'] ) && 'PluginPrivacy' !== $_GET['page'] ) { //phpcs:ignore -- monce safe.
                MainWP_UI::render_page_navigation( $renderItems );
            }

            static::render_actions_bar();

            echo '<div>';
    }

    /**
     * Renders Server Information footer.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Renders Server Information action bar element.
     */
    public static function render_actions_bar() {
        if ( isset( $_GET['page'] ) && 'ServerInformation' === $_GET['page'] ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ?>
        <div class="mainwp-actions-bar">
            <div class="ui two column grid">
                <div class="column"></div>
                <div class="right aligned column">
                    <a href="#" style="margin-left:5px" class="ui mini basic green button" id="mainwp-copy-meta-system-report" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Copy the system report to paste it to the MainWP Community.', 'mainwp' ); ?>"><?php esc_html_e( 'Copy System Report for the MainWP Community', 'mainwp' ); ?></a>
                    <a href="#" class="ui mini green button" id="mainwp-download-system-report"><?php esc_html_e( 'Download System Report', 'mainwp' ); ?></a>
                </div>
            </div>
        </div>
            <?php
        endif;
    }

    /**
     * Renders Server Information page.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extensions()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_mainwp_version()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_current_version()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_file_system_method()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_php_allow_url_fopen()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_php_exif()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_php_iptc()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_php_xml()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_loaded_php_extensions()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_sql_mode()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_wp_root()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_name()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_software()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_os()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_architecture()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_ip()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_protocol()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_http_host()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_https()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::server_self_connect()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_user_agent()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_port()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_gateway_interface()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::memory_usage()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_complete_url()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_request_time()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_http_accept()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_accept_charset()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_script_file_name()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_current_page_uri()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_remote_address()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_remote_host()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_remote_port()
     */
    public static function render() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
            \mainwp_do_not_have_permissions( 'server information', 'mainwp' );
            return;
        }
        static::render_header( '' );

        /**
         * Action: mainwp_before_server_info_table
         *
         * Fires on the top of the Info page, before the Server Info table.
         *
         * @since 4.0
         */
        do_action( 'mainwp_before_server_info_table' );
        ?>
        <div class="ui segment">
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-server-info-info-message' ) ) : ?>
            <div class="ui info message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-server-info-info-message"></i>
                <?php printf( esc_html__( 'Check your system configuration and make sure your MainWP Dashboard passes all system requirements.  If you need help with resolving specific errors, please review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/resolving-system-requirement-issues/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
            </div>
        <?php endif; ?>
        <?php
        if ( function_exists( 'curl_version' ) && ! MainWP_Server_Information_Handler::curlssl_compare( 'OpenSSL/1.1.0', '>=' ) ) {
            echo "<div class='ui yellow message'>" . sprintf( esc_html__( 'Your host needs to update OpenSSL to at least version 1.1.0 which is already over 4 years old and contains patches for over 60 vulnerabilities.%1$sThese range from Denial of Service to Remote Code Execution. %2$sClick here for more information.%3$s', 'mainwp' ), '<br/>', '<a href="https://community.letsencrypt.org/t/openssl-client-compatibility-changes-for-let-s-encrypt-certificates/143816" target="_blank">', '</a>' ) . '</div>';
        }
        ?>
        <table id="mainwp-system-report-wordpress-table" class="ui unstackable table single line mainwp-system-report-table mainwp-system-info-table">
                <thead>
                    <tr>
                        <th scope="col" ><?php esc_html_e( 'WordPress Check', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Required', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Detected', 'mainwp' ); ?></th>
                        <th scope="col" class="right aligned"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php static::render_wordpress_check_tbody(); ?>
            </tbody>
        </table>

        <table id="mainwp-system-report-php-table" class="ui unstackable table fixed mainwp-system-report-table mainwp-system-info-table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'PHP', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Required', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Detected', 'mainwp' ); ?></th>
                    <th scope="col" class="right aligned"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php static::render_php_check_tbody(); ?>
            </tbody>
        </table>

        <table id="mainwp-system-report-mysql-table" class="ui unstackable table mainwp-system-report-table mainwp-system-info-table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'MySQL', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Required', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Detected', 'mainwp' ); ?></th>
                    <th scope="col" class="right aligned"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php static::render_mysql_check_tbody(); ?>
            </tbody>
        </table>

        <table id="mainwp-system-report-server-table" class="ui unstackable table mainwp-system-report-table mainwp-system-info-table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Server Configuration', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Detected Value', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php static::render_server_check_tbody(); ?>
            </tbody>
        </table>

        <table id="mainwp-system-report-dashboard-table" class="ui unstackable table mainwp-system-report-table mainwp-system-info-table">
            <thead>
                    <tr>
                    <th scope="col" ><?php esc_html_e( 'MainWP Dashboard Settings', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Detected Value', 'mainwp' ); ?></th>
                    </tr>
            </thead>
            <tbody>
                <?php static::render_dashboard_check_tbody(); ?>
            </tbody>
        </table>

        <table id="mainwp-system-report-extensions-table" class="ui unstackable table single line mainwp-system-report-table mainwp-system-info-table">
            <thead>
                    <tr>
                    <th scope="col" ><?php esc_html_e( 'Extensions', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'License', 'mainwp' ); ?></th>
                    <th scope="col" class="right aligned"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    </tr>
            </thead>
            <tbody>
                <?php static::render_extensions_license_check_tbody(); ?>
            </tbody>
        </table>

        <table id="mainwp-system-report-plugins-table" class="ui single line table unstackable mainwp-system-report-table mainwp-system-info-table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php static::render_plugins_check_tbody(); ?>
            </tbody>
        </table>

        <div id="download-server-information" style="opacity:0">
            <textarea readonly="readonly" contenteditable="true" wrap="off"></textarea>
        </div>

        <script type="text/javascript">
        let responsive = true;
        if( jQuery( window ).width() > 1140 ) {
            responsive = false;
        }
        jQuery( document ).ready( function() {
            jQuery( '.mainwp-system-info-table:not(#mainwp-system-report-extensions-table)' ).DataTable( {
                responsive: responsive,
                colreorder: true,
                paging: false,
                info: false,
            } );
            jQuery( '#mainwp-system-report-extensions-table' ).DataTable( {
                responsive: responsive,
                colreorder: true,
                paging: false,
                info: false,
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No installed extensions', 'mainwp' ); ?>"
                },
            } );
        } );
        </script>
        </div>

        <?php

        /**
         * Action: mainwp_after_server_info_table
         *
         * Fires on the bottom of the Info page, after the Server Info table.
         *
         * @since 4.0
         */
        do_action( 'mainwp_after_server_info_table' );

        static::render_footer( '' );
    }

    /**
     * Renders WordPress checks table body.
     *
     * @return void
     */
    public static function render_wordpress_check_tbody() {
        static::render_row( 'WordPress Version', '>=', '6.2', 'get_wordpress_version', '', '', null, null, static::ERROR );
        static::render_row( 'WordPress Memory Limit', '>=', '64M', 'get_wordpress_memory_limit', '', '', null );
        static::render_row( 'MultiSite Disabled', '=', true, 'check_if_multisite', '', '', null );
        ?>
        <tr>
            <td><?php esc_html_e( 'FileSystem Method', 'mainwp' ); ?></td>
            <td><?php echo esc_html( '= direct' ); ?></td>
            <td><?php echo MainWP_Server_Information_Handler::get_file_system_method(); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            <td class="right aligned"><?php echo static::get_file_system_method_check(); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
        </tr>
        <?php
    }

    /**
     * Renders PHP checks table body.
     *
     * @return void
     */
    public static function render_php_check_tbody() {
        static::render_row( 'PHP Version', '>=', '7.4', 'get_php_version', '', '', null, null, static::ERROR );
        static::render_row( 'PHP Safe Mode Disabled', '=', true, 'get_php_safe_mode', '', '', null );
        static::render_row( 'PHP Max Execution Time', '>=', '30', 'get_max_execution_time', 'seconds', '=', '0' );
        static::render_row( 'PHP Max Input Time', '>=', '30', 'get_max_input_time', 'seconds', '=', '0' );
        static::render_row( 'PHP Memory Limit', '>=', '256M', 'get_php_memory_limit', '', '', null, 'filesize' );
        static::render_row( 'PCRE Backtracking Limit', '>=', '10000', 'get_output_buffer_size', '', '', null );
        static::render_row( 'PHP Upload Max Filesize', '>=', '2M', 'get_upload_max_filesize', '', '', null, 'filesize' );
        static::render_row( 'PHP Post Max Size', '>=', '2M', 'get_post_max_size', '', '', null, 'filesize' );
        static::render_row( 'SSL Extension Enabled', '=', true, 'get_ssl_support', '', '', null );
        static::render_row( 'SSL Warnings', '=', '', 'get_ssl_warning', 'empty', '', null );
        static::render_row( 'cURL Extension Enabled', '=', true, 'get_curl_support', '', '', null, null, static::ERROR );
        static::render_row( 'cURL Timeout', '>=', '300', 'get_curl_timeout', 'seconds', '=', '0' );
        if ( function_exists( 'curl_version' ) ) {
            $reuire_curl = '7.18.1';
            if ( version_compare( MainWP_Server_Information_Handler::get_php_version(), '8.0.0' ) >= 0 ) {
                $reuire_curl = '7.29.0';
            }
            static::render_row( 'cURL Version', '>=', $reuire_curl, 'get_curl_version', '', '', null );
            $openssl_version = 'OpenSSL/1.1.0';
            static::render_row(
                'OpenSSL Version',
                '>=',
                $openssl_version,
                'get_curl_ssl_version',
                '',
                '',
                null,
                'curlssl'
            );

            $wk = MainWP_Server_Information_Handler::get_openssl_working_status();
            ?>
            <tr>
                <td>OpenSSL Working Status</td>
                <td>Yes</td>
                <td><?php echo $wk ? 'Yes' : 'No'; ?></td>
                <td class="right aligned"><?php $wk ? static::get_pass_html( true ) : static::get_warning_html( self::WARNING, true ); ?></td>
            </tr>
            <?php

        }
        ?>
        <tr>
            <td><?php esc_html_e( 'PHP Allow URL fopen', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_php_allow_url_fopen(); ?></td>
            <td></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'PHP Exif Support', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_php_exif(); ?></td>
            <td></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'PHP IPTC Support', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_php_iptc(); ?></td>
            <td></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'PHP XML Support', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_php_xml(); ?></td>
            <td></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Function `tmpfile` enabled', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td>
            <?php
            $tmpfile_enabled = MainWP_System_View::is_tmpfile_enable();
            echo $tmpfile_enabled ? esc_html__( 'Enabled', 'mainwp' ) : esc_html__( 'Disabled', 'mainwp' );
            ?>
            </td>
            <td class="right aligned"><?php $tmpfile_enabled ? static::get_pass_html( true ) : static::get_warning_html( self::WARNING, true ); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'PHP Session enabled', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td>
            <?php
                $session_disable = MainWP_Cache::is_session_disable();
                echo $session_disable ? esc_html__( 'Disabled', 'mainwp' ) : esc_html__( 'Enabled', 'mainwp' );
            ?>
            </td>
            <td class="right aligned"><?php echo ! $session_disable ? static::get_pass_html() : static::get_warning_html(); //phpcs:ignore -- ok. ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'PHP Disabled Functions', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td><?php static::php_disabled_functions(); ?></td>
            <td></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'PHP Loaded Extensions', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_loaded_php_extensions(); ?></td>
            <td></td>
        </tr>
        <?php
    }

    /**
     * Renders MySQL checks table body.
     *
     * @return void
     */
    public static function render_mysql_check_tbody() {
        static::render_row( 'MySQL Version', '>=', '5.0', 'get_mysql_version', '', '', null, null, static::ERROR );
        ?>
                    <tr>
            <td><?php esc_html_e( 'MySQL Mode', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_sql_mode(); ?></td>
            <td></td>
                    </tr>
                    <tr>
            <td><?php esc_html_e( 'MySQL Client Encoding', 'mainwp' ); ?></td>
            <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
            <td><?php echo defined( 'DB_CHARSET' ) ? esc_html( DB_CHARSET ) : ''; ?></td>
            <td></td>
                    </tr>
        <?php
    }

    /**
     * Renders Server checks table body.
     *
     * @return void
     */
    public static function render_server_check_tbody() {
        ?>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'WordPress Root Directory', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_wp_root(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Server Name', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_name(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Server Software', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_software(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Operating System', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_os(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Architecture', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_architecture(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Server IP', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_ip(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Server Protocol', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_protocol(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'HTTP Host', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_http_host(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'HTTPS', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_https(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Server self connect', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::server_self_connect(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'User Agent', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_user_agent(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Server Port', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_port(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Gateway Interface', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_gateway_interface(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Memory Usage', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::memory_usage(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Complete URL', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_complete_url(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Request Time', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_request_time(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Accept Content', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_http_accept(); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Accept-Charset Content', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_server_accept_charset(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Currently Executing Script Pathname', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_script_file_name(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Current Page URI', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_current_page_uri(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Remote Address', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_remote_address(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Remote Host', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_remote_host(); ?></td>
        </tr>
        <tr class="mwp-not-generate-row">
            <td><?php esc_html_e( 'Remote Port', 'mainwp' ); ?></td>
            <td><?php MainWP_Server_Information_Handler::get_remote_port(); ?></td>
        </tr>
        <?php
    }

    /**
     * Renders MainWP Dashboard checks table body.
     *
     * @return void
     */
    public static function render_dashboard_check_tbody() {
        ?>
        <tr>
            <td><?php esc_html_e( 'MainWP Dashboard Version', 'mainwp' ); ?></td>
            <td><?php echo 'Latest: ' . MainWP_Server_Information_Handler::get_mainwp_version(); ?> | <?php echo 'Detected: ' . MainWP_Server_Information_Handler::get_current_version(); ?> <?php echo static::get_mainwp_version_check(); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
        </tr>
        <?php
        static::check_directory_mainwp_directory();
        static::display_mainwp_options();
    }

    /**
     * Renders extensions API License status table body.
     *
     * @return void
     */
    public static function render_extensions_license_check_tbody() {
        $extensions       = MainWP_Extensions_Handler::get_extensions();
        $extensions_slugs = array();
        if ( empty( $extensions ) ) {
            return;
        }
        foreach ( $extensions as $extension ) {
            $extensions_slugs[] = $extension['slug'];
            ?>
                    <tr>
                <td><?php echo esc_html( $extension['name'] ); ?></td>
                <td><?php echo esc_html( $extension['version'] ); ?></td>
                <?php
                if ( isset( $extension['mainwp'] ) && $extension['mainwp'] ) {
                    ?>
                    <td><?php echo isset( $extension['activated_key'] ) && 'Activated' === $extension['activated_key'] ? esc_html__( 'Active', 'mainwp' ) : esc_html__( 'Inactive', 'mainwp' ); ?></td>
                    <td class="right aligned"><?php echo isset( $extension['activated_key'] ) && 'Activated' === $extension['activated_key'] ? static::get_pass_html() : static::get_warning_html( static::WARNING ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                    <?php
                } else {
                    ?>
                    <td></td>
                    <td></td>
                    <?php
                }
                ?>
                    </tr>
            <?php
        }
    }

        /**
         * Renders plugins check table body.
         *
         * @return void
         */
    public static function render_plugins_check_tbody() {
        $all_extensions = MainWP_Extensions_View::get_available_extensions();
        $all_plugins    = get_plugins();
        foreach ( $all_plugins as $slug => $plugin ) {
            if ( isset( $all_extensions[ dirname( $slug ) ] ) ) {
                continue;
            }
            ?>
            <tr>
                <td><?php echo esc_html( $plugin['Name'] ); ?></td>
                <td><?php echo esc_html( $plugin['Version'] ); ?></td>
                <td class="right aligned"><?php echo is_plugin_active( $slug ) ? esc_html__( 'Active', 'mainwp' ) : esc_html__( 'Inactive', 'mainwp' ); ?></td>
            </tr>
            <?php
        }
    }

    /**
     * Renders MainWP system requirements check.
     *
     * @return void
     */
    public static function render_quick_setup_system_check() {
        /**
         * Action: mainwp_before_system_requirements_check
         *
         * Fires on the bottom of the System Requirements page, in Quick Setup Wizard.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_system_requirements_check' );
        ?>
        <table id="mainwp-quick-system-requirements-check" class="ui single line table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Check', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Required Value', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Detected Value', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                static::render_row_with_description( esc_html__( 'PHP Version', 'mainwp' ), '>=', '7.4', 'get_php_version', '', '', null );
                static::render_row_with_description( esc_html__( 'SSL Extension Enabled', 'mainwp' ), '=', true, 'get_ssl_support', '', '', null );
                static::render_row_with_description( esc_html__( 'cURL Extension Enabled', 'mainwp' ), '=', true, 'get_curl_support', '', '', null );

                $ssl_version     = MainWP_Server_Information_Handler::get_curl_ssl_version();
                $openssl_version = 'OpenSSL/1.1.0';
                if ( false !== strpos( $ssl_version, 'LibreSSL' ) ) {
                    $openssl_version = 'LibreSSL/2.5.0';
                }

                static::render_row_with_description(
                    'cURL SSL Version',
                    '>=',
                    $openssl_version,
                    'get_curl_ssl_version',
                    '',
                    '',
                    null,
                    'curlssl'
                );

                if ( ! MainWP_Server_Information_Handler::curlssl_compare( $openssl_version, '>=' ) ) {
                    ?>
                        <tr class='warning'>
                            <td colspan='4'>
                                <i class='attention icon'></i>
                                <?php
                                if ( false !== strpos( $ssl_version, 'LibreSSL' ) ) {
                                    printf( esc_html__( 'Your host needs to update LibreSSL to at least version 2.5.0 which is already over 4 years old and contains patches for over 60 vulnerabilities.%1$sThese range from Denial of Service to Remote Code Execution. %2$sClick here for more information.%3$s', 'mainwp' ), '<br/>', '<a href="https://www.libressl.org/" target="_blank">', '</a>' );
                                } else {
                                    printf( esc_html__( 'Your host needs to update OpenSSL to at least version 1.1.0 which is already over 4 years old and contains patches for over 60 vulnerabilities.%1$sThese range from Denial of Service to Remote Code Execution. %2$sClick here for more information.%3$s', 'mainwp' ), '<br/>', '<a href="https://community.letsencrypt.org/t/openssl-client-compatibility-changes-for-let-s-encrypt-certificates/143816" target="_blank">', '</a>' );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php
                }
                static::render_row_with_description( esc_html__( 'MySQL Version', 'mainwp' ), '>=', '5.0', 'get_mysql_version', '', '', null );
                ?>
            </tbody>
        </table>
        <?php
        /**
         * Action: mainwp_after_system_requirements_check
         *
         * Fires on the bottom of the System Requirements page, in Quick Setup Wizard.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_system_requirements_check' );
    }

    /**
     * Compares the detected MainWP Dashboard version agains the verion in WP.org.
     *
     * @return mixed Pass|static::get_warning_html().
     *
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_mainwp_version()
     */
    public static function get_mainwp_version_check() {
        $current = get_option( 'mainwp_plugin_version' );
        $latest  = MainWP_Server_Information_Handler::get_mainwp_version();
        if ( $current === $latest ) {
            return static::get_pass_html();
        } else {
            return static::get_warning_html();
        }
    }

    /**
     * Renders the Cron Schedule page.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    public static function render_cron() { // phpcs:ignore -- NOSONAR - complex.
        if ( ! \mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
            \mainwp_do_not_have_permissions( 'cron schedules', 'mainwp' );
            return;
        }

        static::render_header( 'ServerInformationCron' );

        $local_timestamp = MainWP_Utility::get_timestamp();

        $freq = (int) get_option( 'mainwp_frequencyDailyUpdate', 2 );
        if ( $freq <= 0 ) {
            $freq = 1;
        }
        $auto_update_text = static::get_schedule_auto_update_label( $freq );

        $cron_jobs = array(
            'Check for available updates' => array( 'mainwp_updatescheck_start_last_timestamp', 'mainwp_cronupdatescheck_action', $auto_update_text ),
            'Check for reconnect sites'   => array( 'mainwp_cron_last_stats', 'mainwp_cronreconnect_action', esc_html__( 'Once hourly', 'mainwp' ), 'hourly' ),
            'Ping childs sites'           => array( 'mainwp_cron_last_ping', 'mainwp_cronpingchilds_action', esc_html__( 'Once daily', 'mainwp' ), 'daily' ),
        );

        $cron_jobs['Child site uptime monitoring'] = array( 'mainwp_uptimecheck_auto_main_counter_lasttime_started', 'mainwp_cronuptimemonitoringcheck_action', esc_html__( 'Once every minute', 'mainwp' ), 'minutely' );

        $disableHealthChecking = get_option( 'mainwp_disableSitesHealthMonitoring', 1 );  // disabled by default.
        if ( ! $disableHealthChecking ) {
            $cron_jobs['Site Health monitoring'] = array( 'mainwp_cron_checksiteshealth_last_timestamp', 'mainwp_cronsitehealthcheck_action', esc_html__( 'Once hourly', 'mainwp' ), 'hourly' );
        }

        if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            $cron_jobs['Start backups (Legacy)']    = array( 'mainwp_cron_last_backups', 'mainwp_cronbackups_action', esc_html__( 'Once hourly', 'mainwp' ), 'hourly' );
            $cron_jobs['Continue backups (Legacy)'] = array( 'mainwp_cron_last_backups_continue', 'mainwp_cronbackups_continue_action', esc_html__( 'Once every five minutes', 'mainwp' ), '5minutely' );
        }

        $cron_jobs = apply_filters( 'mainwp_info_schedules_cron_listing', $cron_jobs );

        /**
         * Action: mainwp_before_cron_jobs_table
         *
         * Renders on the top of the Cron Jobs page, before the Schedules table.
         *
         * @since 4.0
         */
        do_action( 'mainwp_before_cron_jobs_table' );
        ?>
        <div class="ui segment">
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-cron-info-message' ) ) : ?>
            <div class="ui info message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-cron-info-message"></i>
                <?php printf( esc_html__( 'Make sure scheduled actions are working correctly.  If scheduled actions do not run normally, please review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/scheduled-events-not-occurring/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
            </div>
        <?php endif; ?>
        <table class="ui single line unstackable table" id="mainwp-cron-jobs-table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Cron Job', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Hook', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Schedule', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Last Run', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Next Run', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $useWPCron = ( false === get_option( 'mainwp_wp_cron' ) ) || ( 1 === (int) get_option( 'mainwp_wp_cron' ) );

                foreach ( $cron_jobs as $cron_job => $hook ) {

                    if ( is_array( $hook ) && isset( $hook['title'] ) && isset( $hook['action'] ) ) {
                        $job_title    = $hook['title'];
                        $job_action   = $hook['action'];
                        $job_freq     = isset( $hook['frequency'] ) ? $hook['frequency'] : '';
                        $job_last_run = isset( $hook['last_run'] ) ? $hook['last_run'] : '';
                        $job_next_run = isset( $hook['next_run'] ) ? $hook['next_run'] : '';
                    } else {

                        $is_auto_update_job = false;
                        $lasttime_run       = 0;
                        if ( 'mainwp_updatescheck_start_last_timestamp' === $hook[0] ) {
                            $update_time        = MainWP_Settings::get_websites_automatic_update_time();
                            $last_run           = $update_time['last'];
                            $next_run           = $update_time['next'];
                            $is_auto_update_job = true;
                        } elseif ( false === get_option( $hook[0] ) ) {
                            $last_run = esc_html__( 'Never', 'mainwp' );
                        } else {
                            $lasttime_run = get_option( $hook[0] );
                            if ( $lasttime_run ) {
                                $last_run = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $lasttime_run ) );
                            } else {
                                $last_run = esc_html__( 'Never', 'mainwp' );
                            }
                        }

                        if ( $useWPCron && 'mainwp_updatescheck_start_last_timestamp' !== $hook[0] ) {
                            $next_run = wp_next_scheduled( $hook[1] );
                            if ( ! empty( $next_run ) ) {
                                $next_run = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $next_run ) );
                            }
                        }

                        if ( empty( $next_run ) || ( ! $useWPCron && ! $is_auto_update_job && isset( $hook[3] ) ) ) {
                            $nexttime_run = static::get_schedule_next_time_to_show( $hook[3], $lasttime_run, $local_timestamp );
                            if ( $nexttime_run < $local_timestamp + 3 * MINUTE_IN_SECONDS ) {
                                $next_run = esc_html__( 'Any minute', 'mainwp' );
                            } else {
                                $next_run = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $nexttime_run ) );
                            }
                        }

                        $job_title    = $cron_job;
                        $job_action   = $hook[1];
                        $job_freq     = $hook[2];
                        $job_last_run = $last_run;
                        $job_next_run = $next_run;
                    }

                    // phpcs:disable WordPress.Security.EscapeOutput
                    ?>
                    <tr>
                        <td><?php echo esc_html( $job_title ); ?></td>
                        <td><?php echo esc_html( $job_action ); ?></td>
                        <td><?php echo esc_html( $job_freq ); ?></td>
                        <td><?php echo esc_html( $job_last_run ); ?></td>
                        <td><?php echo ! empty( $job_next_run ) ? esc_html( $job_next_run ) : ''; ?></td>
                    </tr>
                    <?php
                    // phpcs:enable
                }
                /**
                 * Action: mainwp_cron_jobs_list
                 *
                 * Renders as the last row of the Schedules table.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_cron_jobs_list' );
                ?>
            </tbody>
        </table>
        <?php
        $table_features = array(
            'searching'  => 'true',
            'paging'     => 'false',
            'info'       => 'false',
            'responsive' => 'true',
        );
        /**
         * Filter: mainwp_cron_jobs_table_features
         *
         * Filters the Cron Schedules table features.
         *
         * @since 4.1
         */
        $table_features = apply_filters( 'mainwp_cron_jobs_table_features', $table_features );
        ?>
        <script type="text/javascript">
            jQuery( document ).ready( function() {
                let responsive = <?php echo esc_html( $table_features['responsive'] ); ?>;
                if( jQuery( window ).width() > 1140 ) {
                    responsive = false;
                }
                jQuery( '#mainwp-cron-jobs-table' ).DataTable( {
                    "searching": <?php echo esc_html( $table_features['searching'] ); ?>,
                    "paging": <?php echo esc_html( $table_features['paging'] ); ?>,
                    "info": <?php echo esc_html( $table_features['info'] ); ?>,
                    "responsive": responsive,
                });
            } );
        </script>
        </div>
        <?php

        /**
         * Action: mainwp_after_cron_jobs_table
         *
         * Renders on the bottom of the Cron Jobs page, after the Schedules table.
         *
         * @since 4.0
         */
        do_action( 'mainwp_after_cron_jobs_table' );

        static::render_footer( 'ServerInformationCron' );
    }

    /**
     * Get frequency auto update to show.
     *
     * @param int $freq frequency of auto update.
     *
     * @return string label of frequency.
     */
    public static function get_schedule_auto_update_label( $freq ) {
        $freq = intval( $freq );
        $text = '';
        switch ( $freq ) {
            case 1:
                $text = esc_html__( 'Once per day', 'mainwp' );
                break;
            case 2:
                $text = esc_html__( 'Twice per day', 'mainwp' );
                break;
            case 3:
                $text = esc_html__( 'Three times per day', 'mainwp' );
                break;
            case 4:
                $text = esc_html__( 'Four times per day', 'mainwp' );
                break;
            case 5:
                $text = esc_html__( 'Five times per day', 'mainwp' );
                break;
            case 6:
                $text = esc_html__( 'Six times per day', 'mainwp' );
                break;
            case 7:
                $text = esc_html__( 'Seven times per day', 'mainwp' );
                break;
            case 8:
                $text = esc_html__( 'Eight times per day', 'mainwp' );
                break;
            case 9:
                $text = esc_html__( 'Nine times per day', 'mainwp' );
                break;
            case 10:
                $text = esc_html__( 'Ten times per day', 'mainwp' );
                break;
            case 11:
                $text = esc_html__( 'Eleven times per day', 'mainwp' );
                break;
            case 12:
                $text = esc_html__( 'Twelve times per day', 'mainwp' );
                break;
            default:
                break;
        }
        return $text;
    }

    /**
     * Get next time of schedule job to show.
     *
     * @param string $job_freq frequency of schedule job.
     * @param int    $lasttime_run Lasttime to run.
     * @param int    $local_timestamp current local time.
     *
     * @return int next run time of schedule job.
     */
    public static function get_schedule_next_time_to_show( $job_freq, $lasttime_run, $local_timestamp ) {
        $next_time    = $local_timestamp;
        $lasttime_run = is_numeric( $lasttime_run ) ? intval( $lasttime_run ) : false;
        switch ( $job_freq ) {
            case 'daily':
                $next_time = $lasttime_run ? $lasttime_run + DAY_IN_SECONDS : $local_timestamp + DAY_IN_SECONDS;
                break;
            case 'hourly':
                $next_time = $lasttime_run ? $lasttime_run + HOUR_IN_SECONDS : $local_timestamp + HOUR_IN_SECONDS;
                break;
            case '5minutely':
                $next_time = $lasttime_run ? $lasttime_run + 5 * MINUTE_IN_SECONDS : $local_timestamp + 5 * MINUTE_IN_SECONDS;
                break;
            case 'minutely':
                $next_time = $lasttime_run ? $lasttime_run + MINUTE_IN_SECONDS : $local_timestamp + MINUTE_IN_SECONDS;
                break;
            default:
                break;
        }

        if ( $next_time < $local_timestamp ) { // to fix next time in past.
            $next_time = $local_timestamp;
        }

        return $next_time;
    }

    /**
     * Checks if the ../wp-content/uploads/mainwp/ directory is writable.
     *
     * @return bool True if writable, false if not.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     */
    public static function check_directory_mainwp_directory() {
        $dirs = MainWP_System_Utility::get_mainwp_dir();
        $path = $dirs[0];

        $mess = 'Writable';

        if ( ! is_dir( dirname( $path ) ) ) {
            $mess = 'Not Found';
        }

        $is_writable = MainWP_System_Utility::is_writable( $path );

        if ( $is_writable ) {
            $mess = 'Not Writable';
        }

        return '<tr><td>MainWP Upload Directory</td><td>' . $mess . '</td></tr>';
    }

    /**
     * Renders the directory check row.
     *
     * @param string $name check name.
     * @param string $check desired result.
     * @param string $result detected result.
     * @param bool   $passed true|false check result.
     *
     * @return bool true.
     */
    public static function render_directory_row( $name, $check, $result, $passed ) {
         // phpcs:disable WordPress.Security.EscapeOutput
        ?>
        <tr>
            <td><?php echo esc_html( $name ); ?></td>
            <td><?php echo esc_html( $check ); ?></td>
            <td><?php echo esc_html( $result ); ?></td>
            <td class="right aligned"><?php echo $passed ? static::get_pass_html() : static::get_warning_html( static::ERROR ); ?></td>
        </tr>
        <?php
         // phpcs:enable
        return true;
    }

    /**
     * Renders server information table row.
     *
     * @param string $config configuraion check.
     * @param string $compare comparison operator.
     * @param mixed  $version reqiored minium version number.
     * @param mixed  $getter detected version number.
     * @param string $extraText additionl text.
     * @param null   $extraCompare extra compare.
     * @param null   $extraVersion extra version.
     * @param null   $whatType comparison type.
     * @param int    $errorType global variable static::WARNING = 1.
     *
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::filesize_compare()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::curlssl_compare()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_class_name()
     */
    public static function render_row( $config, $compare, $version, $getter, $extraText = '', $extraCompare = null, $extraVersion = null, $whatType = null, $errorType = self::WARNING ) { //phpcs:ignore -- NOSONAR - complex.
        $currentVersion = call_user_func( array( MainWP_Server_Information_Handler::get_class_name(), $getter ) );
         // phpcs:disable WordPress.Security.EscapeOutput
        $ver = is_array( $version ) && isset( $version['version'] ) ? esc_html( $version['version'] ) : esc_html( $version );
        ?>
        <tr>
            <td><?php echo esc_html( $config ); ?></td>
            <td><?php echo esc_html( $compare ); ?><?php echo ( true === $version ? 'true' : $ver ) . ' ' . $extraText; ?></td>
            <td><?php echo true === $currentVersion ? 'true' : $currentVersion; ?></td>
            <?php if ( 'filesize' === $whatType ) { ?>
                <td class="right aligned"><?php echo MainWP_Server_Information_Handler::filesize_compare( $currentVersion, $version, $compare ) ? static::get_pass_html() : static::get_warning_html( $errorType ); ?></td>
            <?php } elseif ( 'get_curl_ssl_version' === $getter ) { ?>
                <td class="right aligned"><?php echo MainWP_Server_Information_Handler::curlssl_compare( $version, $compare ) ? static::get_pass_html() : static::get_warning_html( $errorType ); ?></td>
            <?php } elseif ( ( 'get_max_input_time' === $getter || 'get_max_execution_time' === $getter ) && -1 === (int) $currentVersion ) { ?>
                <td class="right aligned"><?php echo static::get_pass_html(); ?></td>
            <?php } else { ?>
                <td class="right aligned"><?php echo version_compare( $currentVersion, $version, $compare ) || ( ! empty( $extraCompare ) && version_compare( $currentVersion, $extraVersion, $extraCompare ) ) ? static::get_pass_html() : static::get_warning_html( $errorType ); ?></td>
        <?php } ?>
        </tr>
        <?php
         // phpcs:enable
    }

    /**
     * Renders server information table row with description.
     *
     * @param string $config configuraion check.
     * @param string $compare comparison operator.
     * @param mixed  $version reqiored minium version number.
     * @param mixed  $getter detected version number.
     * @param string $extraText additionl text.
     * @param null   $extraCompare extra compare.
     * @param null   $extraVersion extra version.
     * @param null   $whatType comparison type.
     * @param int    $errorType global variable static::WARNING = 1.
     *
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::filesize_compare()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::curlssl_compare()
     */
    public static function render_row_with_description( $config, $compare, $version, $getter, $extraText = '', $extraCompare = null, $extraVersion = null, $whatType = null, $errorType = self::WARNING ) { // phpcs:ignore -- NOSONAR - complex.
        $currentVersion = call_user_func( array( MainWP_Server_Information_Handler::get_class_name(), $getter ) );
        // phpcs:disable WordPress.Security.EscapeOutput
        $ver = is_array( $version ) && isset( $version['version'] ) ? esc_html( $version['version'] ) : esc_html( $version );
        ?>
        <tr>
            <td><?php echo esc_html( $config ); ?></td>
            <td><?php echo esc_html( $compare ); ?>  <?php echo ( true === $version ? 'true' : $ver ) . ' ' . $extraText; ?></td>
            <td><?php echo true === $currentVersion ? 'true' : $currentVersion; ?></td>
            <?php if ( 'filesize' === $whatType ) { ?>
            <td class="right aligned"><?php echo MainWP_Server_Information_Handler::filesize_compare( $currentVersion, $version, $compare ) ? static::get_pass_html() : static::get_warning_html( $errorType ); ?></td>
            <?php } elseif ( 'get_curl_ssl_version' === $getter ) { ?>
            <td class="right aligned"><?php echo MainWP_Server_Information_Handler::curlssl_compare( $version, $compare ) ? static::get_pass_html() : static::get_warning_html( $errorType ); ?></td>
            <?php } elseif ( 'get_max_input_time' === $getter && -1 === (int) $currentVersion ) { ?>
            <td class="right aligned"><?php echo static::get_pass_html(); ?></td>
            <?php } else { ?>
            <td class="right aligned"><?php echo version_compare( $currentVersion, $version, $compare ) || ( ! empty( $extraCompare ) && version_compare( $currentVersion, $extraVersion, $extraCompare ) ) ? static::get_pass_html() : static::get_warning_html( $errorType ); ?></td>
            <?php } ?>
        </tr>
        <?php
         // phpcs:enable
    }

    /**
     * Checks if file system method is direct.
     *
     * @return mixed html|static::get_warning_html().
     *
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_file_system_method()
     */
    public static function get_file_system_method_check() {
        $fsmethod = MainWP_Server_Information_Handler::get_file_system_method();
        if ( 'direct' === $fsmethod ) {
            return static::get_pass_html();
        } else {
            return static::get_warning_html();
        }
    }

    /**
     * Renders Error Log page.
     *
     * Plugin-Name: Error Log Dashboard Widget
     * Plugin URI: http://wordpress.org/extend/plugins/error-log-dashboard-widget/
     * Description: Robust zero-configuration and low-memory way to keep an eye on error log.
     * Author: Andrey "Rarst" Savchenko
     * Author URI: http://www.rarst.net/
     * Version: 1.0.2
     * License: GPLv2 or later

     * Includes last_lines() function by phant0m, licensed under cc-wiki and GPLv2+
     */
    public static function render_error_log_page() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
            \mainwp_do_not_have_permissions( 'error log', 'mainwp' );
            return;
        }
        static::render_header( 'ErrorLog' );

        /**
         * Action: mainwp_before_error_log_table
         *
         * Fires before the Error Log table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_error_log_table' );
        ?>
        <div class="ui segment">
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-error-log-info-message' ) ) : ?>
            <div class="ui info message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-error-log-info-message"></i>
                <?php echo esc_html__( 'See the WordPress error log to fix problems that arise on your MainWP Dashboard site.', 'mainwp' ); ?>
            </div>
        <?php endif; ?>
        <table class="ui unstackable table" id="mainwp-error-log-table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Time', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Error', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php static::render_error_log(); ?>
            </tbody>
        </table>
        <script type="text/javascript">
            let responsive = true;
            if( jQuery( window ).width() > 1140 ) {
                responsive = false;
            }

            jQuery( document ).ready( function() {
                jQuery( '#mainwp-error-log-table' ).DataTable( {
                    "responsive": responsive,
                    "ordering": false,
                    "stateSave": true,
                    "stateDuration": 0,
                    "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
                    "language": {
                        "emptyTable": '<?php esc_html_e( 'Error logging disabled.', 'mainwp' ); ?><?php echo '<br/>' . sprintf( esc_html__( 'To enable error logging, please check this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://codex.wordpress.org/Debugging_in_WordPress" target="_blank">', '</a>' ); ?>'
                    },
                    columnDefs: [{
                        "defaultContent": "-",
                        "targets": "_all"
                    }]
                } );
            } );
        </script>
        </div>
        <?php
        /**
         * Action: mainwp_after_error_log_table
         *
         * Fires after the Error Log table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_error_log_table' );

        static::render_footer( 'ErrorLog' );
    }

    /**
     * Renders error log page.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::last_lines()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_class_name()
     */
    public static function render_error_log() {
        $log_errors = ini_get( 'log_errors' );
        if ( ! $log_errors ) {
            return;
        }

        $error_log = ini_get( 'error_log' );
        /**
         * Filter: error_log_mainwp_logs
         *
         * Filters the error log files to show.
         *
         * @since Unknown
         */
        $logs = apply_filters( 'error_log_mainwp_logs', array( $error_log ) );

        /**
         * Filter: error_log_mainwp_lines
         *
         * Limits the number of error log records to be displayed. Default value, 50.
         *
         * @since Unknown
         */
        $count = apply_filters( 'error_log_mainwp_lines', 100 );
        $lines = array();

        foreach ( $logs as $log ) {
            if ( is_readable( $log ) ) {
                $lines = array_merge( $lines, MainWP_Server_Information_Handler::last_lines( $log, $count ) );
            }
        }

        $lines = array_map( 'trim', $lines );
        $lines = array_filter( $lines );

        if ( empty( $lines ) ) {
            echo '<tr><td colspan="2">' . esc_html__( 'MainWP is unable to find your error logs, please contact your host for server error logs.', 'mainwp' ) . '</td></tr>';
            return;
        }

        foreach ( $lines as $key => $line ) {
            if ( false !== strpos( $line, ']' ) ) {
                list( $time, $error ) = explode( ']', $line, 2 );
            } else {
                list( $time, $error ) = array( '', $line );
            }
            $time          = trim( $time, '[]' );
            $error         = trim( $error );
            $lines[ $key ] = compact( 'time', 'error' );
        }

        if ( 1 < count( $lines ) ) {
            // phpcs:ignore -- ok.
            //uasort( $lines, array( MainWP_Server_Information_Handler::get_class_name(), 'time_compare' ) );
            $lines = array_slice( $lines, 0, $count );
        }

        // phpcs:disable WordPress.Security.EscapeOutput
        foreach ( $lines as $line ) {
            $error = esc_html( $line['error'] );
            $time  = esc_html( $line['time'] );
            if ( ! empty( $error ) ) {
                echo '<tr><td>' . $time . '</td><td>' . $error . '</td></tr>';
            }
        }
        // phpcs:enable
    }

    /**
     * Renders the WP Config page.
     *
     * @return void
     */
    public static function render_wp_config() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
            \mainwp_do_not_have_permissions( 'WP-Config.php', 'mainwp' );
            return;
        }

        static::render_header( 'WPConfig' );
        /**
         * Action: mainwp_before_wp_config_section
         *
         * Fires before the WP Config section.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_wp_config_section' );
        ?>
        <div id="mainwp-show-wp-config">
            <?php
            if ( false !== strpos( ini_get( 'disable_functions' ), 'show_source' ) ) {
                esc_html_e( 'File content could not be displayed.', 'mainwp' );
                echo '<br />';
                esc_html_e( 'It appears that the show_source() PHP function has been disabled on the servre.', 'mainwp' );
                echo '<br />';
                esc_html_e( 'Please, contact your host support and have them enable the show_source() function for the proper functioning of this feature.', 'mainwp' );
            } elseif ( file_exists( ABSPATH . 'wp-config.php' ) ) {
                    show_source( ABSPATH . 'wp-config.php' );
            } else {
                $files       = get_included_files();
                $configFound = false;
                if ( is_array( $files ) ) {
                    foreach ( $files as $file ) {
                        if ( stristr( $file, 'wp-config.php' ) ) {
                            $configFound = true;
                            show_source( $file );
                            break;
                        }
                    }
                }
                if ( ! $configFound ) {
                    esc_html_e( 'wp-config.php not found', 'mainwp' );
                }
            }
            ?>
        </div>
        <?php
        /**
         * Action: mainwp_after_wp_config_section
         *
         * Fires after the WP Config section.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_wp_config_section' );

        static::render_footer( 'WPConfig' );
    }

    /**
     * Renders action logs page.
     *
     * @uses \MainWP\Dashboard\MainWP_Logger
     * @uses \MainWP\Dashboard\MainWP_Logger::clear_log()
     * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public static function render_action_logs() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        static::render_header( 'ActionLogs' );

        if ( isset( $_REQUEST['actionlogs_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $act_log  = isset( $_REQUEST['actionlogs_status'] ) ? wp_unslash( $_REQUEST['actionlogs_status'] ) : MainWP_Logger::DISABLED; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $spec_log = 0;
            if ( is_string( $act_log ) && false !== strpos( $act_log, 'specific_' ) ) {
                $act_log  = str_replace( 'specific_', '', $act_log );
                $spec_log = 1;
            }

            $act_log = intval( $act_log );

            MainWP_Utility::update_option( 'mainwp_specific_logs', $spec_log );

            MainWP_Logger::instance()->log_action( 'Action logs set to: ' . MainWP_Logger::instance()->get_log_text( $act_log ), ( $spec_log ? $act_log : MainWP_Logger::LOG ), 2, true );

            if ( MainWP_Logger::DISABLED === $act_log ) {
                MainWP_Logger::instance()->set_log_priority( $act_log, $spec_log );
            }

            MainWP_Utility::update_option( 'mainwp_actionlogs', $act_log );
            MainWP_Utility::update_option( 'mainwp_actionlogs_enabled_timestamp', time() );
        }

        if ( isset( $_REQUEST['actionlogs_clear'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $log_to_db = apply_filters( 'mainwp_logger_to_db', true );
            if ( $log_to_db ) {
                MainWP_Logger::instance()->clear_log_db();
            } else {
                MainWP_Logger::instance()->clear_log();
            }
        }

        $enabled          = (int) MainWP_Logger::instance()->get_log_status();
        $specific_default = array(
            MainWP_Logger::UPDATE_CHECK_LOG_PRIORITY    => esc_html__( 'Update Checking', 'mainwp' ),
            MainWP_Logger::UPTIME_CHECK_LOG_PRIORITY    => esc_html__( 'Uptime monitoring', 'mainwp' ),
            MainWP_Logger::UPTIME_NOTICE_LOG_PRIORITY   => esc_html__( 'Uptime notification', 'mainwp' ),
            MainWP_Logger::LOGS_REGULAR_SCHEDULE        => esc_html__( 'Regular Schedule', 'mainwp' ),
            MainWP_Logger::DEBUG_UPDATES_SCHEDULE       => esc_html__( 'Debug updates crons', 'mainwp' ),
            MainWP_Logger::EXECUTION_TIME_LOG_PRIORITY  => esc_html__( 'Execution time', 'mainwp' ),
            MainWP_Logger::LOGS_AUTO_PURGE_LOG_PRIORITY => esc_html__( 'Logs Auto Purge', 'mainwp' ),
            MainWP_Logger::CONNECT_LOG_PRIORITY         => esc_html__( 'Dashboard Connect', 'mainwp' ),
        );
        $specific_logs    = apply_filters( 'mainwp_specific_action_logs', $specific_default ); // deprecated since 4.3.1, use 'mainwp_log_specific_actions' instead.
        $specific_logs    = apply_filters( 'mainwp_log_specific_actions', $specific_logs );

        ?>
        <div class="mainwp-sub-header">
            <div class="ui mini form two column stackable grid">
                <div class="column">
                <form method="POST" action="">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <?php // phpcs:disable WordPress.Security.EscapeOutput ?>
                        <select name="actionlogs_status" class="ui mini dropdown">
                        <option value="<?php echo MainWP_Logger::DISABLED; ?>" <?php echo MainWP_Logger::DISABLED === $enabled ? 'selected' : ''; ?>>
                            <?php esc_html_e( 'Disabled', 'mainwp' ); ?>
                        </option>
                            <option value="<?php echo MainWP_Logger::INFO; ?>" <?php echo MainWP_Logger::INFO === $enabled ? 'selected' : ''; ?>>
                                <?php esc_html_e( 'Info', 'mainwp' ); ?>
                            </option>
                        <option value="<?php echo MainWP_Logger::WARNING; ?>" <?php echo MainWP_Logger::WARNING === $enabled ? 'selected' : ''; ?>>
                            <?php esc_html_e( 'Warning', 'mainwp' ); ?>
                        </option>
                        <option value="<?php echo MainWP_Logger::DEBUG; ?>" <?php echo MainWP_Logger::DEBUG === $enabled ? 'selected' : ''; ?>>
                            <?php esc_html_e( 'Debug', 'mainwp' ); ?>
                        </option>
                        <?php
                        // phpcs:enable
                        if ( is_array( $specific_logs ) && ! empty( $specific_logs ) ) {
                            foreach ( $specific_logs as $spec_log => $spec_title ) {
                                ?>
                            <option value="specific_<?php echo intval( $spec_log ); ?>" <?php echo (int) $spec_log === (int) $enabled ? 'selected' : ''; ?>>
                                <?php echo esc_html( $spec_title ); ?>
                            </option>
                                <?php
                            }
                        }
                        ?>
                    </select>
                        <input type="submit" class="ui green mini button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                        <input type="submit" class="ui mini button" name="actionlogs_clear" value="<?php esc_attr_e( 'Delete Log', 'mainwp' ); ?>" />
                </form>
            </div>
                <div class="column">

                </div>
            </div>
        </div>
        <div class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-action-logs-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-action-logs-info-message"></i>
                    <div><?php echo esc_html__( 'Enable a specific logging system.', 'mainwp' ); ?></div>
                    <p><?php echo esc_html__( 'Each specific log type changes only the type of information logged. It does not change the log view.', 'mainwp' ); ?></p>
                    <p><?php echo esc_html__( 'After disabling the Action Log, logs will still be visible. To remove records, click the Delete Logs button.', 'mainwp' ); ?></p>
                    <p><?php printf( esc_html__( 'For additional help, please review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/action-logs/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></p>
                </div>
            <?php endif; ?>
        <?php
        $log_to_db = apply_filters( 'mainwp_logger_to_db', true );
        if ( $log_to_db ) {
            return MainWP_Logger::instance()->show_log_db();
        } else {
            MainWP_Logger::instance()->show_log_file();
        }
        ?>
        </div>
        <?php
        static::render_footer( 'ActionLogs' );
    }

    /**
     * Renders the Plugin Privacy page.
     *
     * @return void
     */
    public static function render_plugin_privacy_page() {

        static::render_header( 'PluginPrivacy' );
        /**
         * Action: mainwp_before_plugin_privacy_section
         *
         * Fires before the Plugin Privacy section.
         *
         * @since 4.2
         */
        do_action( 'mainwp_before_plugin_privacy_section' );
        ?>
        <div class="ui segment">
                    <div id="mainwp-plugin-privacy">
                        <h2 class="ui header">
                            <?php echo esc_html__( 'MainWP Dashboard Plugin Privacy Policy', 'mainwp' ); ?>
                            <div class="sub header"><em><?php echo esc_html__( 'Last updated: April 14, 2022', 'mainwp' ); ?></em></div>
                        </h2>
                        <p><?php echo esc_html__( 'We value your privacy very highly. Please read this Privacy Policy carefully before using the MainWP Dashboard Plugin ("Plugin") operated by Sick Marketing, LLC d/b/a MainWP, a Limited Liability Company formed in Nevada, United States ("us","we","our") as this Privacy Policy contains important information regarding your privacy.', 'mainwp' ); ?></p>
                        <p><?php echo esc_html__( 'Your access to and use of the Plugin is conditional upon your acceptance of and compliance with this Privacy Policy. This Privacy Policy applies to everyone accessing or using the Plugin.', 'mainwp' ); ?></p>
                        <p><?php echo esc_html__( 'By accessing or using the Plugin, you agree to be bound by this Privacy Policy. If you disagree with any part of this Privacy Policy, then you do not have our permission to access or use the Plugin.', 'mainwp' ); ?></p>
                        <h3 class="ui header"><?php echo esc_html__( 'What personal data we collect', 'mainwp' ); ?></h3>
                        <p><?php echo esc_html__( 'We do not collect, store, nor process any personal data through this Plugin.', 'mainwp' ); ?></p>
                        <h3 class="ui header"><?php echo esc_html__( 'Third-party extensions and integrations', 'mainwp' ); ?></h3>
                        <p><?php echo esc_html__( 'This Plugin may be used with extensions that are operated by parties other than us. We may also provide extensions that have integrations with third party services. We do not control such extensions and integrations and are not responsible for their contents or the privacy or other practices of such extensions or integrations. Further, it is up to you to take precautions to ensure that whatever extensions or integrations you use adequately protect your privacy. Please review the Privacy Policies of such extensions or integrations before using them.', 'mainwp' ); ?></p>
                        <div class="ui hidden divider"></div>
                    <div class="ui two columns stackable grid">
                            <div class="column">
                                <h3 class="ui header"><?php echo esc_html__( 'Our contact information', 'mainwp' ); ?></h3>
                                <p><?php echo esc_html__( 'If you have any questions regarding our privacy practices, please do not hesitate to contact us at the following:', 'mainwp' ); ?></p>
                                <div class="ui list">
                                    <div class="item"><?php echo esc_html__( 'Sick Marketing, LLC d/b/a MainWP', 'mainwp' ); ?></div>
                                    <div class="item"><?php echo esc_html__( 'support@mainwp.com', 'mainwp' ); ?></div>
                                    <div class="item"><?php echo esc_html__( '4730 S. Fort Apache Road', 'mainwp' ); ?></div>
                                    <div class="item"><?php echo esc_html__( 'Suite 300', 'mainwp' ); ?></div>
                                    <div class="item"><?php echo esc_html__( 'PO Box 27740', 'mainwp' ); ?></div>
                                    <div class="item"><?php echo esc_html__( 'Las Vegas, NV 89126', 'mainwp' ); ?></div>
                                    <div class="item"><?php echo esc_html__( 'United States', 'mainwp' ); ?></div>
                                </div>
                            </div>
                            <div class="column">
                                <h3 class="ui header"><?php echo esc_html__( 'Our representative\'s contact information', 'mainwp' ); ?></h3>
                                <p><?php echo esc_html__( 'If you are a resident of the European Union or the European Economic Area, you may also contact our representative at the following:', 'mainwp' ); ?></p>
                                <div class="item"><?php echo esc_html__( 'Ametros Ltd', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'Unit 3D', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'North Point House', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'North Point Business Park', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'New Mallow Road', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'Cork', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'Ireland', 'mainwp' ); ?></div>
                                <div class="ui hidden divider"></div>
                                <p><?php echo esc_html__( 'If you are a resident of the United Kingdom, you may contact our representative at the following:', 'mainwp' ); ?></p>
                                <div class="item"><?php echo esc_html__( 'Ametros Group Ltd', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'Lakeside Offices', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'Thorn Business Park', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'Hereford', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'Herefordshire', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'HR2 6JT', 'mainwp' ); ?></div>
                                <div class="item"><?php echo esc_html__( 'England', 'mainwp' ); ?></div>
                            </div>
                        </div>
                        <div class="ui divider"></div>
                    </div>

                    <a href="<?php echo esc_url( get_site_url() ) . '/wp-content/plugins/mainwp/privacy-policy.txt'; ?>" class="ui green basic button" target="_blank"><?php echo esc_html__( 'Download MainWP Dashboard Privacy Policy', 'mainwp' ); ?></a> <a href="<?php echo esc_url( get_site_url() ) . '/wp-content/plugins/mainwp/mainwp-child-privacy-policy.txt'; ?>" class="ui green basic button" target="_blank"><?php echo esc_html__( 'Download MainWP Child Privacy Policy', 'mainwp' ); ?></a>
        </div>

        <?php
        /**
         * Action: mainwp_after_plugin_privacy_section
         *
         * Fires after the Plugin Privacy section.
         *
         * @since 4.2
         */
        do_action( 'mainwp_after_plugin_privacy_section' );

        static::render_footer( 'PluginPrivacy' );
    }

    /**
     * Renders .htaccess File page.
     *
     * @return void
     */
    public static function render_htaccess() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
            \mainwp_do_not_have_permissions( '.htaccess', 'mainwp' );
            return;
        }
        static::render_header( '.htaccess' );
        /**
         * Action: mainwp_before_htaccess_section
         *
         * Fires before the .htaccess file section.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_htaccess_section' );
        ?>
        <div id="mainwp-show-htaccess">
            <?php
            if ( false !== strpos( ini_get( 'disable_functions' ), 'show_source' ) ) {
                esc_html_e( 'File content could not be displayed.', 'mainwp' );
                echo '<br />';
                esc_html_e( 'It appears that the show_source() PHP function has been disabled on the servre.', 'mainwp' );
                echo '<br />';
                esc_html_e( 'Please, contact your host support and have them enable the show_source() function for the proper functioning of this feature.', 'mainwp' );
            } else {
                show_source( ABSPATH . '.htaccess' );
            }
            ?>
        </div>
        <?php
        /**
         * Action: mainwp_after_htaccess_section
         *
         * Fires after the .htaccess file section.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_htaccess_section' );
        static::render_footer( '.htaccess' );
    }

    /**
     * Checks for disable PHP Functions.
     *
     * @return void
     */
    public static function php_disabled_functions() {
        $disabled_functions = ini_get( 'disable_functions' );
        if ( '' !== $disabled_functions ) {
            $arr = explode( ',', $disabled_functions );
            sort( $arr );
            $_count = count( $arr );
            for ( $i = 0; $i < $_count; $i++ ) {
                echo esc_html( $arr[ $i ] ) . ', ';
            }
        } else {
            esc_html_e( 'No functions disabled.', 'mainwp' );
        }
    }

    /**
     * Renders MainWP Settings 'Options'.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::mainwp_options()
     */
    public static function display_mainwp_options() {
        $options = MainWP_Server_Information_Handler::mainwp_options();

        $enable_individual_uptime_monitoring = false;
        if ( get_option( 'mainwp_individual_uptime_monitoring_schedule_enabled' ) ) {
            $enable_individual_uptime_monitoring = true;
        }

        // phpcs:disable WordPress.Security.EscapeOutput
        foreach ( $options as $option ) {
            $addition_info = '';
            if ( 'is_enable_automatic_check_uptime_monitoring' === $option['name'] && empty( $option['save_value'] ) && $enable_individual_uptime_monitoring ) { // global monitoring disabled.
                $addition_info = '<br><em>' . esc_html__( 'Individual uptime monitoring is running', 'mainwp' ) . '</em>';
            }
            echo '<tr><td>' . $option['label'] . '</td><td>' . $option['value'] . $addition_info . '</td></tr>';
        }
        // phpcs:enable
    }

    /**
     * Renders PHP Warning HTML.
     *
     * @param int  $errorType Global variable static::WARNING = 1.
     * @param bool $ech echo or return.
     *
     * @return string PHP Warning html.
     */
    private static function get_warning_html( $errorType = self::WARNING, $ech = false ) {
        $msg = '';
        if ( static::WARNING === $errorType ) {
            $msg = '<i class="large yellow exclamation icon"></i><span style="display:none">' . esc_html__( 'Warning', 'mainwp' ) . '</span>';
        } else {
            $msg = '<i class="large red times icon"></i><span style="display:none">' . esc_html__( 'Fail', 'mainwp' ) . '</span>';
        }

        if ( $ech ) {
            echo $msg; //phpcs:ignore -- escaped.
        } else {
            return $msg;
        }
    }

    /**
     * Renders PHP Pass HTML.
     *
     * @param bool $ech echo or return.
     *
     * @return string PHP Pass html.
     */
    private static function get_pass_html( $ech = false ) {
        $msg = '<i class="large green check icon"></i><span style="display:none">' . esc_html__( 'Pass', 'mainwp' ) . '</span>';
        if ( $ech ) {
            echo $msg; //phpcs:ignore -- escaped.
        } else {
            return $msg;
        }
    }
}
