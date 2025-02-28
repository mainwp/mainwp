<?php
/**
 * MainWP Extension Groups Page
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Extensions_Groups
 */
class MainWP_Extensions_Groups { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Instantiate action hooks.
     */
    public static function init() {
        add_action( 'mainwp_admin_menu', array( static::get_class_name(), 'init_extensions_menu' ), 10, 2 );
    }

    /**
     * Method init_extensions_menu()
     *
     * Initiate left Extensions menus.
     */
    public static function init_extensions_menu() { //phpcs:ignore -- NOSONAR - complex.

        // @NO_SONAR_START@ - duplicated issue.
        $end_div = '</div>';

        $submenu_pages = array(
            array(
                'parent_slug' => 'mainwp_tab',
                'page_title'  => esc_html__( 'Backups', 'mainwp' ),
                'menu_title'  => '<div class="mainwp-hidden" id="mainwp-extensions-backups">' . esc_html__( 'Backups', 'mainwp' ) . $end_div,
                'capability'  => 'read',
                'menu_slug'   => 'Extensions-Mainwp-Backups',
                'callback'    => array(
                    static::class,
                    'render_extensions_groups',
                ),
            ),
            array(
                'parent_slug' => 'mainwp_tab',
                'page_title'  => esc_html__( 'Security', 'mainwp' ),
                'menu_title'  => '<div class="mainwp-hidden" id="mainwp-extensions-security">' . esc_html__( 'Security', 'mainwp' ) . $end_div,
                'capability'  => 'read',
                'menu_slug'   => 'Extensions-Mainwp-Security',
                'callback'    => array(
                    static::class,
                    'render_extensions_groups',
                ),
            ),
            array(
                'parent_slug' => 'mainwp_tab',
                'page_title'  => esc_html__( 'Monitoring', 'mainwp' ),
                'menu_title'  => '<div class="mainwp-hidden" id="mainwp-extensions-monitoring">' . esc_html__( 'Monitoring', 'mainwp' ) . $end_div,
                'capability'  => 'read',
                'menu_slug'   => 'Extensions-Mainwp-Monitoring',
                'callback'    => array(
                    static::class,
                    'render_extensions_groups',
                ),
            ),
            array(
                'parent_slug' => 'mainwp_tab',
                'page_title'  => esc_html__( 'Analytics', 'mainwp' ),
                'menu_title'  => '<div class="mainwp-hidden" id="mainwp-extensions-analytics">' . esc_html__( 'Analytics', 'mainwp' ) . $end_div,
                'capability'  => 'read',
                'menu_slug'   => 'Extensions-Mainwp-Analytics',
                'callback'    => array(
                    static::class,
                    'render_extensions_groups',
                ),
            ),
            array(
                'parent_slug' => 'mainwp_tab',
                'page_title'  => esc_html__( 'Performance', 'mainwp' ),
                'menu_title'  => '<div class="mainwp-hidden" id="mainwp-extensions-performance">' . esc_html__( 'Performance', 'mainwp' ) . $end_div,
                'capability'  => 'read',
                'menu_slug'   => 'Extensions-Mainwp-Performance',
                'callback'    => array(
                    static::class,
                    'render_extensions_groups',
                ),
            ),
            array(
                'parent_slug' => 'mainwp_tab',
                'page_title'  => esc_html__( 'Development', 'mainwp' ),
                'menu_title'  => '<div class="mainwp-hidden" id="mainwp-extensions-development">' . esc_html__( 'Development', 'mainwp' ) . $end_div,
                'capability'  => 'read',
                'menu_slug'   => 'Extensions-Mainwp-Development',
                'callback'    => array(
                    static::class,
                    'render_extensions_groups',
                ),
            ),
            array(
                'parent_slug' => 'mainwp_tab',
                'page_title'  => esc_html__( 'Agency', 'mainwp' ),
                'menu_title'  => '<div class="mainwp-hidden" id="mainwp-extensions-agency">' . esc_html__( 'Agency', 'mainwp' ) . $end_div,
                'capability'  => 'read',
                'menu_slug'   => 'Extensions-Mainwp-Agency',
                'callback'    => array(
                    static::class,
                    'render_extensions_groups',
                ),
            ),
            array(
                'parent_slug' => 'mainwp_tab',
                'page_title'  => esc_html__( 'Administrative', 'mainwp' ),
                'menu_title'  => '<div class="mainwp-hidden" id="mainwp-extensions-administrative">' . esc_html__( 'Administrative', 'mainwp' ) . $end_div,
                'capability'  => 'read',
                'menu_slug'   => 'Extensions-Mainwp-Administrative',
                'callback'    => array(
                    static::class,
                    'render_extensions_groups',
                ),
            ),
        );

        foreach ( $submenu_pages as $item ) {
            add_submenu_page(
                $item['parent_slug'],
                $item['page_title'],
                $item['menu_title'],
                $item['capability'],
                $item['menu_slug'],
                $item['callback'],
            );
        }

        $extensions_and_leftmenus = array(
            array(
                'title'         => esc_html__( 'Backups', 'mainwp' ),
                'parent_key'    => 'managesites',
                'slug'          => 'Extensions-Mainwp-Backups',
                'href'          => 'admin.php?page=Extensions-Mainwp-Backups',
                'leftsub_order' => 8.1,
                'id'            => 'mainwp-backups-extensions-category',
                'level'         => 1,
            ),
        );

        if ( defined( 'MAINWP_MODULE_API_BACKUPS_ENABLED' ) && MAINWP_MODULE_API_BACKUPS_ENABLED ) {
            $extensions_and_leftmenus[] = array(
                'type'                 => 'extension',
                'title'                => esc_html__( 'API Backups', 'mainwp' ),
                'slug'                 => 'mainwp-api-backpus',
                'parent_key'           => 'Extensions-Mainwp-Backups',
                'ext_page'             => 'admin.php?page=ManageApiBackups',
                'leftsub_order_level2' => 1,
                'level'                => 2,
                'active_path'          => array( 'ManageApiBackups' => 'managesites' ),
            );
        }

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'BackWPup', 'mainwp' ),
            'slug'                 => 'mainwp-backwpup-extension/mainwp-backwpup-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Backups',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Backwpup-Extension',
            'leftsub_order_level2' => 2,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Backwpup-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'UpdraftPlus', 'mainwp' ),
            'slug'                 => 'mainwp-updraftplus-extension/mainwp-updraftplus-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Backups',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Updraftplus-Extension',
            'leftsub_order_level2' => 3,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Updraftplus-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'MainWP Buddy', 'mainwp' ),
            'slug'                 => 'mainwp-buddy-extension/mainwp-buddy-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Backups',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Buddy-Extension',
            'leftsub_order_level2' => 4,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Buddy-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Time Capsule', 'mainwp' ),
            'slug'                 => 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Backups',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Timecapsule-Extension',
            'leftsub_order_level2' => 5,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Timecapsule-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'WPvivid Backup', 'mainwp' ),
            'slug'                 => 'wpvivid-backup-mainwp/wpvivid-backup-mainwp.php',
            'parent_key'           => 'Extensions-Mainwp-Backups',
            'ext_page'             => 'admin.php?page=Extensions-Wpvivid-Backup-Mainwp',
            'leftsub_order_level2' => 6,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Wpvivid-Backup-Mainwp' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'title'         => esc_html__( 'Security', 'mainwp' ),
            'parent_key'    => 'managesites',
            'slug'          => 'Extensions-Mainwp-Security',
            'href'          => 'admin.php?page=Extensions-Mainwp-Security',
            'leftsub_order' => 8.2,
            'id'            => 'mainwp-security-extensions-category',
            'level'         => 1,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Activity Log for MainWP', 'mainwp' ),
            'slug'                 => 'activity-log-mainwp/activity-log-mainwp.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Activity-Log-Mainwp',
            'leftsub_order_level2' => 1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Activity-Log-Mainwp' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Dashboard Lock', 'mainwp' ),
            'slug'                 => 'mainwp-clean-and-lock-extension/mainwp-clean-and-lock-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Clean-And-Lock-Extension',
            'leftsub_order_level2' => 2,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Clean-And-Lock-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Jetpack Scan', 'mainwp' ),
            'slug'                 => 'mainwp-jetpack-scan-extension/mainwp-jetpack-scan-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Jetpack-Scan-Extension',
            'leftsub_order_level2' => 3,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Jetpack-Scan-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Jetpack Protect', 'mainwp' ),
            'slug'                 => 'mainwp-jetpack-protect-extension/mainwp-jetpack-protect-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Jetpack-Protect-Extension',
            'leftsub_order_level2' => 4,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Jetpack-Protect-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Security Ninja', 'mainwp' ),
            'slug'                 => 'security-ninja-for-mainwp/security-ninja-mainwp.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Security-Ninja-For-Mainwp',
            'leftsub_order_level2' => 5,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Security-Ninja-For-Mainwp' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Sucuri', 'mainwp' ),
            'slug'                 => 'mainwp-sucuri-extension/mainwp-sucuri-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Sucuri-Extension',
            'leftsub_order_level2' => 6,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Sucuri-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'iThemes Security', 'mainwp' ),
            'slug'                 => 'mainwp-ithemes-security-extension/mainwp-ithemes-security-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Ithemes-Security-Extension',
            'leftsub_order_level2' => 7,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Ithemes-Security-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Virusdie', 'mainwp' ),
            'slug'                 => 'mainwp-virusdie-extension/mainwp-virusdie-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Virusdie-Extension',
            'leftsub_order_level2' => 8,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Virusdie-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Vulnerability Checker', 'mainwp' ),
            'slug'                 => 'mainwp-vulnerability-checker-extension/mainwp-vulnerability-checker-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Vulnerability-Checker-Extension',
            'leftsub_order_level2' => 9,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Vulnerability-Checker-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Wordfence', 'mainwp' ),
            'slug'                 => 'mainwp-wordfence-extension/mainwp-wordfence-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Security',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Wordfence-Extension',
            'leftsub_order_level2' => 10,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Wordfence-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'title'         => esc_html__( 'Analytics', 'mainwp' ),
            'parent_key'    => 'managesites',
            'slug'          => 'Extensions-Mainwp-Analytics',
            'href'          => 'admin.php?page=Extensions-Mainwp-Analytics',
            'leftsub_order' => 8.3,
            'id'            => 'mainwp-analytics-extensions-category',
            'level'         => 1,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Google Analytics', 'mainwp' ),
            'slug'                 => 'mainwp-google-analytics-extension/mainwp-google-analytics-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Analytics',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Google-Analytics-Extension',
            'leftsub_order_level2' => 1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Google-Analytics-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Fathom', 'mainwp' ),
            'slug'                 => 'mainwp-fathom-extension/mainwp-fathom-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Analytics',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Fathom-Extension',
            'leftsub_order_level2' => 2,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Fathom-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Matomo', 'mainwp' ),
            'slug'                 => 'mainwp-piwik-extension/mainwp-piwik-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Analytics',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Piwik-Extension',
            'leftsub_order_level2' => 3,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Piwik-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'title'         => esc_html__( 'Monitoring', 'mainwp' ),
            'parent_key'    => 'managesites',
            'slug'          => 'Extensions-Mainwp-Monitoring',
            'href'          => 'admin.php?page=Extensions-Mainwp-Monitoring',
            'leftsub_order' => 8.4,
            'id'            => 'mainwp-monitoring-extensions-category',
            'level'         => 1,
        );

        $extensions_and_leftmenus[] = array(
            'title'                => esc_html__( 'Monitoring', 'mainwp' ),
            'parent_key'           => 'Extensions-Mainwp-Monitoring',
            'slug'                 => 'MonitoringSites',
            'href'                 => 'admin.php?page=MonitoringSites',
            'leftsub_order_level2' => 0.5,
            'level'                => 2,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Advanced Uptime Monitor', 'mainwp' ),
            'slug'                 => 'advanced-uptime-monitor-extension/advanced-uptime-monitor-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Monitoring',
            'ext_page'             => 'admin.php?page=Extensions-Advanced-Uptime-Monitor-Extension',
            'leftsub_order_level2' => 1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Advanced-Uptime-Monitor-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'SSL Monitor', 'mainwp' ),
            'slug'                 => 'mainwp-ssl-monitor-extension/mainwp-ssl-monitor-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Monitoring',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Ssl-Monitor-Extension',
            'leftsub_order_level2' => 2,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Ssl-Monitor-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Domain Monitor', 'mainwp' ),
            'slug'                 => 'mainwp-domain-monitor-extension/mainwp-domain-monitor-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Monitoring',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Domain-Monitor-Extension',
            'leftsub_order_level2' => 3,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Domain-Monitor-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Lighthouse', 'mainwp' ),
            'slug'                 => 'mainwp-lighthouse-extension/mainwp-lighthouse-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Monitoring',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Lighthouse-Extension',
            'leftsub_order_level2' => 4,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Lighthouse-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'title'         => esc_html__( 'Agency', 'mainwp' ),
            'parent_key'    => 'managesites',
            'slug'          => 'Extensions-Mainwp-Agency',
            'href'          => 'admin.php?page=Extensions-Mainwp-Agency',
            'leftsub_order' => 8.4,
            'id'            => 'mainwp-agency-extensions-category',
            'level'         => 1,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'White Label', 'mainwp' ),
            'slug'                 => 'mainwp-branding-extension/mainwp-branding-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Agency',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Branding-Extension',
            'leftsub_order_level2' => 1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Branding-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Team Control', 'mainwp' ),
            'slug'                 => 'mainwp-team-control/mainwp-team-control.php',
            'parent_key'           => 'Extensions-Mainwp-Agency',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Team-Control',
            'leftsub_order_level2' => 2,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Team-Control' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'title'         => esc_html__( 'Administrative', 'mainwp' ),
            'parent_key'    => 'managesites',
            'slug'          => 'Extensions-Mainwp-Administrative',
            'href'          => 'admin.php?page=Extensions-Mainwp-Administrative',
            'leftsub_order' => 8.5,
            'id'            => 'mainwp-administrative-extensions-category',
            'level'         => 1,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'WooCommerce Shortcuts', 'mainwp' ),
            'slug'                 => 'mainwp-woocommerce-shortcuts-extension/mainwp-woocommerce-shortcuts-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Administrative',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Woocommerce-Shortcuts-Extension',
            'leftsub_order_level2' => 1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Woocommerce-Shortcuts-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'WooCommerce Status', 'mainwp' ),
            'slug'                 => 'mainwp-woocommerce-status-extension/mainwp-woocommerce-status-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Administrative',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Woocommerce-Status-Extension',
            'leftsub_order_level2' => 2,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Woocommerce-Status-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'title'         => esc_html__( 'Development', 'mainwp' ),
            'parent_key'    => 'managesites',
            'slug'          => 'Extensions-Mainwp-Development',
            'href'          => 'admin.php?page=Extensions-Mainwp-Development',
            'leftsub_order' => 8.6,
            'id'            => 'mainwp-development-extensions-category',
            'level'         => 1,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Atarim', 'mainwp' ),
            'slug'                 => 'mainwp-atarim-extension/mainwp-atarim-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Atarim-Extension',
            'leftsub_order_level2' => 1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Atarim-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Bulk Settings Manager', 'mainwp' ),
            'slug'                 => 'mainwp-bulk-settings-manager/mainwp-bulk-settings-manager.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Bulk-Settings-Manager',
            'leftsub_order_level2' => 2,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Bulk-Settings-Manager' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Code Snippets', 'mainwp' ),
            'slug'                 => 'mainwp-code-snippets-extension/mainwp-code-snippets-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Code-Snippets-Extension',
            'leftsub_order_level2' => 3,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Code-Snippets-Extension' => 'managesites' ),
        );
        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Custom Dashboard', 'mainwp' ),
            'slug'                 => 'mainwp-custom-dashboard-extension/mainwp-custom-dashboard-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Custom-Dashboard-Extension',
            'leftsub_order_level2' => 4,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Custom-Dashboard-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Custom Post Type', 'mainwp' ),
            'slug'                 => 'mainwp-custom-post-types/mainwp-custom-post-types.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Custom-Post-Types',
            'leftsub_order_level2' => 5,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Custom-Post-Types' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'File Uploader', 'mainwp' ),
            'slug'                 => 'mainwp-file-uploader-extension/mainwp-file-uploader-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-File-Uploader-Extension',
            'leftsub_order_level2' => 6,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-File-Uploader-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Pressable', 'mainwp' ),
            'slug'                 => 'mainwp-pressable-extension/mainwp-pressable-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Pressable-Extension',
            'leftsub_order_level2' => 7,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Pressable-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Staging', 'mainwp' ),
            'slug'                 => 'mainwp-staging-extension/mainwp-staging-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Staging-Extension',
            'leftsub_order_level2' => 8,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Staging-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'URL Extractor', 'mainwp' ),
            'slug'                 => 'mainwp-url-extractor-extension/mainwp-url-extractor-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Development',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Url-Extractor-Extension',
            'leftsub_order_level2' => 9,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Url-Extractor-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'title'         => esc_html__( 'Performance', 'mainwp' ),
            'parent_key'    => 'managesites',
            'slug'          => 'Extensions-Mainwp-Performance',
            'href'          => 'admin.php?page=Extensions-Mainwp-Performance',
            'leftsub_order' => 8.6,
            'id'            => 'mainwp-performance-extensions-category',
            'level'         => 1,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Cache Control', 'mainwp' ),
            'slug'                 => 'mainwp-cache-control-extension/mainwp-cache-control-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Performance',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Cache-Control-Extension',
            'leftsub_order_level2' => 1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Cache-Control-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Maintenance', 'mainwp' ),
            'slug'                 => 'mainwp-maintenance-extension/mainwp-maintenance-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Performance',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Maintenance-Extension',
            'leftsub_order_level2' => 2,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Maintenance-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Rocket', 'mainwp' ),
            'slug'                 => 'mainwp-rocket-extension/mainwp-rocket-extension.php',
            'parent_key'           => 'Extensions-Mainwp-Performance',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Rocket-Extension',
            'leftsub_order_level2' => 3,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Rocket-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'WP Compress', 'mainwp' ),
            'slug'                 => 'wp-compress-mainwp/wp-compress-main-wp.php',
            'parent_key'           => 'Extensions-Mainwp-Performance',
            'ext_page'             => 'admin.php?page=Extensions-Wp-Compress-Mainwp',
            'leftsub_order_level2' => 4,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Wp-Compress-Mainwp' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Comments', 'mainwp' ),
            'slug'                 => 'mainwp-comments-extension/mainwp-comments-extension.php',
            'parent_key'           => 'PostBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Comments-Extension',
            'leftsub_order_level2' => 2.1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Comments-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Comments', 'mainwp' ),
            'slug'                 => 'mainwp-comments-extension/mainwp-comments-extension.php',
            'parent_key'           => 'PageBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Comments-Extension',
            'leftsub_order_level2' => 2.1,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Comments-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Clone', 'mainwp' ),
            'slug'                 => 'mainwp-clone-extension/mainwp-clone-extension.php',
            'parent_key'           => 'managesites',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Clone-Extension',
            'leftsub_order_level2' => 5,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Clone-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Article Uploader', 'mainwp' ),
            'slug'                 => 'mainwp-article-uploader-extension/mainwp-article-uploader-extension.php',
            'parent_key'           => 'PostBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Article-Uploader-Extension',
            'leftsub_order_level2' => 3,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Article-Uploader-Extension' => 'managesites' ),
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Post Dripper', 'mainwp' ),
            'slug'                 => 'mainwp-post-dripper-extension/mainwp-post-dripper-extension.php',
            'parent_key'           => 'PostBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Post-Dripper-Extension',
            'leftsub_order_level2' => 4,
            'level'                => 2,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Post Plus', 'mainwp' ),
            'slug'                 => 'mainwp-post-plus-extension/mainwp-post-plus-extension.php',
            'parent_key'           => 'PostBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Post-Plus-Extension',
            'leftsub_order_level2' => 5,
            'level'                => 2,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Post Dripper', 'mainwp' ),
            'slug'                 => 'mainwp-post-dripper-extension/mainwp-post-dripper-extension.php',
            'parent_key'           => 'PageBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Post-Dripper-Extension',
            'leftsub_order_level2' => 4,
            'level'                => 2,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Post Plus', 'mainwp' ),
            'slug'                 => 'mainwp-post-plus-extension/mainwp-post-plus-extension.php',
            'parent_key'           => 'PageBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Post-Plus-Extension',
            'leftsub_order_level2' => 5,
            'level'                => 2,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'Pro Reports', 'mainwp' ),
            'slug'                 => 'mainwp-pro-reports-extension/mainwp-pro-reports-extension.php',
            'parent_key'           => 'ManageClients',
            'ext_page'             => 'admin.php?page=Extensions-Mainwp-Pro-Reports-Extension',
            'leftsub_order_level2' => 4,
            'level'                => 2,
            'active_path'          => array( 'Extensions-Mainwp-Pro-Reports-Extension' => 'ManageClients' ),
        );

        if ( defined( 'MAINWP_MODULE_COST_TRACKER_ENABLED' ) && MAINWP_MODULE_COST_TRACKER_ENABLED ) {
            $extensions_and_leftmenus[] = array(
                'type'                 => 'extension',
                'title'                => esc_html__( 'Cost Tracker Assistant', 'mainwp' ),
                'slug'                 => 'mainwp-cost-tracker-assistant-extension/mainwp-cost-tracker-assistant-extension.php',
                'parent_key'           => 'ManageCostTracker',
                'ext_page'             => 'admin.php?page=ManageCostTracker',
                'leftsub_order_level2' => 4,
                'level'                => 2,
            );
        }

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'WordPress SEO', 'mainwp' ),
            'slug'                 => 'wordpress-seo-extension/wordpress-seo-extension.php',
            'parent_key'           => 'PostBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Wordpress-Seo-Extension',
            'leftsub_order_level2' => 6,
            'level'                => 2,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'WordPress SEO', 'mainwp' ),
            'slug'                 => 'wordpress-seo-extension/wordpress-seo-extension.php',
            'parent_key'           => 'PageBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Wordpress-Seo-Extension',
            'leftsub_order_level2' => 6,
            'level'                => 2,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'SEOPress', 'mainwp' ),
            'slug'                 => 'seopress-for-mainwp/wp-seopress-mainwp.php',
            'parent_key'           => 'PostBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Seopress-For-Mainwp',
            'leftsub_order_level2' => 6,
            'level'                => 2,
        );

        $extensions_and_leftmenus[] = array(
            'type'                 => 'extension',
            'title'                => esc_html__( 'SEOPress', 'mainwp' ),
            'slug'                 => 'seopress-for-mainwp/seopress-for-mainwp.php',
            'parent_key'           => 'PageBulkManage',
            'ext_page'             => 'admin.php?page=Extensions-Seopress-For-Mainwp',
            'leftsub_order_level2' => 6,
            'level'                => 2,
        );

        $extensions_and_leftmenus = apply_filters( 'mainwp_menu_extensions_left_menu', $extensions_and_leftmenus );

        foreach ( $extensions_and_leftmenus as $item ) {
            if ( isset( $item['type'] ) && 'extension' === $item['type'] ) {
                static::add_extension_menu( $item );
            } else {
                $level = isset( $item['level'] ) ? intval( $item['level'] ) : 1;
                MainWP_Menu::add_left_menu( $item, $level );
            }
        }
        // @NO_SONAR_END@  .
        global $_mainwp_menu_active_slugs;

        $active_slugs = array(
            'Extensions-Mainwp-Backups'        => 'Extensions-Mainwp-Backups',
            'Extensions-Mainwp-Security'       => 'Extensions-Mainwp-Security',
            'Extensions-Mainwp-Analytics'      => 'Extensions-Mainwp-Analytics',
            'Extensions-Mainwp-Monitoring'     => 'Extensions-Mainwp-Monitoring',
            'Extensions-Mainwp-Agency'         => 'Extensions-Mainwp-Agency',
            'Extensions-Mainwp-Administrative' => 'Extensions-Mainwp-Administrative',
            'Extensions-Mainwp-Development'    => 'Extensions-Mainwp-Development',
            'Extensions-Mainwp-Performance'    => 'Extensions-Mainwp-Performance',
        );
        // to fix activate menus state.
        foreach ( $active_slugs as $item => $act_slug ) {
            MainWP_Menu::set_menu_active_slugs( $item, $act_slug );
        }
    }

    /**
     * Method render_extensions_groups()
     *
     * @param string $plugin_slug The extension's slug.
     *
     * @return bool file existed.
     */
    public static function extension_is_installed( $plugin_slug ) {
        return file_exists( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin_slug ) ? true : false;
    }

    /**
     * Method add_extension_menu()
     *
     * @param array $params The extension's params.
     */
    public static function add_extension_menu( $params = array() ) {

        $title                = $params['title'];
        $ext_page             = $params['ext_page'];
        $plugin_slug          = $params['slug'];
        $parent_key           = $params['parent_key'];
        $level                = $params['level'];
        $nosubmenu            = isset( $params['nosubmenu'] ) && $params['nosubmenu'] ? true : false;
        $leftsub_order        = isset( $params['leftsub_order'] ) ? $params['leftsub_order'] : false;
        $leftsub_order_level2 = isset( $params['leftsub_order_level2'] ) ? $params['leftsub_order_level2'] : false;

        if ( 'mainwp-api-backpus' === $plugin_slug ) {
            $activated_ext = true;
            $href          = $ext_page;
        } else {
            $activated_ext = is_plugin_active( $plugin_slug ) ? true : false;

            if ( $activated_ext ) {
                $href = $ext_page;
            } else {
                $href = '#';
                //phpcs:disable Squiz.PHP.CommentedOutCode.Found
                // $href = 'admin.php?page=Extensions';
                // if ( ! static::extension_is_installed( $plugin_slug ) ) {
                // $href .= '&message=install-ext-' . dirname( $plugin_slug );
                // }
                //phpcs:enable
            }
        }

        $menu_params = array(
            'title'      => $title,
            'parent_key' => $parent_key,
            'href'       => $href,
            'nosubmenu'  => $nosubmenu,
            'ext_status' => $activated_ext ? 'activated' : 'inactive',
        );

        if ( false !== $leftsub_order ) {
            $menu_params['leftsub_order'] = $leftsub_order;
        }

        if ( false !== $leftsub_order_level2 ) {
            $menu_params['leftsub_order_level2'] = $leftsub_order_level2;
        }

        if ( isset( $params['active_path'] ) ) {
            $menu_params['active_path'] = $params['active_path'];
        }
        MainWP_Menu::add_left_menu( $menu_params, $level );
    }

    /**
     * Method render_extensions_groups()
     *
     * @return void
     */
    public static function render_extensions_groups() { //phpcs:ignore -- NOSONAR - complex method.

        $get_ext_group = array();

        //phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['page'] ) ) {
            if ( 'Extensions-Mainwp-Backups' === $_GET['page'] ) {
                $get_ext_group[] = 'backup';
            } elseif ( 'Extensions-Mainwp-Security' === $_GET['page'] ) {
                $get_ext_group[] = 'security';
            } elseif ( 'Extensions-Mainwp-Analytics' === $_GET['page'] ) {
                $get_ext_group[] = 'visitor';
            } elseif ( 'Extensions-Mainwp-Monitoring' === $_GET['page'] ) {
                $get_ext_group[] = 'monitoring';
            } elseif ( 'Extensions-Mainwp-Agency' === $_GET['page'] ) {
                $get_ext_group[] = 'agency';
            } elseif ( 'Extensions-Mainwp-Administrative' === $_GET['page'] ) {
                $get_ext_group[] = 'admin';
            } elseif ( 'Extensions-Mainwp-Development' === $_GET['page'] ) {
                $get_ext_group[] = 'development';
            } elseif ( 'Extensions-Mainwp-Performance' === $_GET['page'] ) {
                $get_ext_group[] = 'performance';
            }
        }
        //phpcs:enable

        if ( empty( $get_ext_group ) ) {
            return;
        }

        $extension_update = get_site_transient( 'update_plugins' );

        $extensions = MainWP_Extensions_Handler::get_extensions();

        $group_available_extensions = MainWP_Extensions_View::get_available_extensions( false, $get_ext_group );

        $extensions_disabled = MainWP_Extensions_Handler::get_extensions_disabled();

        $extensions_not_installed = MainWP_Extensions_Handler::get_extensions_not_installed();

        $excluded_extensions = array();

        do_action( 'mainwp_pageheader_extensions' );
        ?>
        <div class="ui segment">
            <div class="ui three mainwp-cards cards" id="mainwp-extensions-list">
                <?php if ( isset( $_GET['page'] ) && 'Extensions-Mainwp-Backups' === $_GET['page'] ) : //phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="ui extension card">
                        <div class="content">
                            <img class="right floated mini ui image" alt="<?php esc_attr_e( 'Add-on icon placeholder', 'mainwp' ); ?>" src="<?php echo esc_url( MAINWP_PLUGIN_URL ) . 'assets/images/extensions/placeholder.png'; ?>">
                            <div class="header"><a href="admin.php?page=ManageApiBackups"><?php esc_html_e( 'API Backpus', 'mainwp' ); ?></a></div>
                            <a href="admin.php?page=ManageApiBackups" class="ui green ribbon label"><?php echo esc_html__( ' MainWP core feature', 'mainwp' ); ?></a>
                            <div class="description"><?php esc_html_e( 'Manage host-side backups via REST API. Currently, the supported providers are Cloudways, GridPane, Digital Ocean, Linode, Vultr and cPanel.', 'mainwp' ); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( isset( $_GET['page'] ) && 'Extensions-Mainwp-Monitoring' === $_GET['page'] ) : //phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="ui extension card">
                        <div class="content">
                            <img class="right floated mini ui image" alt="<?php esc_attr_e( 'Add-on icon placeholder', 'mainwp' ); ?>" src="<?php echo esc_url( MAINWP_PLUGIN_URL ) . 'assets/images/extensions/placeholder.png'; ?>">
                            <div class="header"><a href="admin.php?page=MonitoringSites"><?php esc_html_e( 'Uptime Monitoring', 'mainwp' ); ?></a></div>
                            <a href="admin.php?page=MonitoringSites" class="ui green ribbon label"><?php echo esc_html__( 'MainWP core feature', 'mainwp' ); ?></a>
                            <div class="description"><?php esc_html_e( 'The MainWP Uptime Monitoring function operates independently of third-party services, providing a straightforward and no-cost option for uptime monitoring across all your managed sites.', 'mainwp' ); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
        <?php if ( isset( $extensions ) && is_array( $extensions ) ) { ?>

            <?php foreach ( $extensions as $extension ) { ?>
                    <?php
                    if ( ! \mainwp_current_user_can( 'extension', dirname( $extension['slug'] ) ) ) {
                        continue;
                    }

                    if ( ! isset( $group_available_extensions[ dirname( $extension['slug'] ) ] ) ) {
                        continue;
                    }

                    if ( in_array( dirname( $extension['slug'] ), $excluded_extensions ) ) {
                        continue;
                    }

                    $extensions_data = isset( $group_available_extensions[ dirname( $extension['slug'] ) ] ) ? $group_available_extensions[ dirname( $extension['slug'] ) ] : array();

                    if ( isset( $extensions_data['img'] ) && ! empty( $extensions_data['img'] ) ) {
                        $img_url = $extensions_data['img'];
                    } elseif ( isset( $extension['icon'] ) && ! empty( $extension['icon'] ) ) {
                        $img_url = $extension['icon'];
                    } elseif ( isset( $extension['iconURI'] ) && '' !== $extension['iconURI'] ) {
                        $img_url = MainWP_Utility::remove_http_prefix( $extension['iconURI'] );
                    } else {
                        $img_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/placeholder.png';
                    }

                    MainWP_Extensions_View::render_extension_card( $extension, $extension_update, $img_url, false, true );
                    ?>

            <?php } ?>
        <?php } ?>

            <?php if ( is_array( $extensions_disabled ) ) { ?>
                    <?php foreach ( $extensions_disabled as $extension ) { ?>
                        <?php
                        $slug = dirname( $extension['slug'] );

                        if ( ! isset( $group_available_extensions[ $slug ] ) ) {
                            continue;
                        }

                        if ( in_array( $slug, $excluded_extensions ) ) {
                            continue;
                        }

                        $extensions_data = $group_available_extensions[ $slug ];

                        if ( isset( $extensions_data['img'] ) && ! empty( $extensions_data['img'] ) ) {
                            $img_url = $extensions_data['img'];  // icon from the available extensions in dashboard.
                        } elseif ( isset( $extension['icon'] ) && ! empty( $extension['icon'] ) ) {
                            $img_url = $extension['icon']; // icon from the get_this_extension().
                        } elseif ( isset( $extension['iconURI'] ) && '' !== $extension['iconURI'] ) {
                            $img_url = MainWP_Utility::remove_http_prefix( $extension['iconURI'] );  // icon from the extension header.
                        } else {
                            $img_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/placeholder.png';
                        }

                        MainWP_Extensions_View::render_inactive_extension_card( $extension, $img_url, true );
                        ?>

            <?php } ?>

        <?php } ?>

        <?php if ( is_array( $extensions_not_installed ) ) { ?>
                    <?php foreach ( $extensions_not_installed as $slug => $extension ) { ?>
                        <?php

                        if ( ! isset( $group_available_extensions[ $slug ] ) ) {
                            continue;
                        }

                        if ( in_array( $slug, $excluded_extensions ) ) {
                            continue;
                        }

                        if ( isset( $extension['img'] ) && ! empty( $extension['img'] ) ) {
                            $img_url = $extension['img'];  // icon from the available extensions in dashboard.
                        } else {
                            $img_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/placeholder.png';
                        }

                        MainWP_Extensions_View::render_inactive_extension_card( $extension, $img_url );
                        ?>

            <?php } ?>

        <?php } ?>

            </div>
    </div>
        <?php
        do_action( 'mainwp_pagefooter_extensions' );
    }
}
