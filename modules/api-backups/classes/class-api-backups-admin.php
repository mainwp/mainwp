<?php
/**
 * =======================================
 * MainWP API Backups Admin
 * =======================================
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Menu;
use MainWP\Dashboard\MainWP_Utility;


/**
 * Class Api_Backups_Admin
 */
class Api_Backups_Admin {

    /**
     * Version string number.
     *
     * @var string $version Version.
     */
    public $version = '4.2';

    /**
     * Update version string number.
     *
     * @var string $version Update version.
     */
    public $update_version = '4.1';

    /**
     * Current page.
     *
     * @static
     * @var string $page Current page.
     */
    public static $page;

    /**
     * Variable to hold the Sub Pages.
     *
     * @static
     * @var mixed Sub Page, default null.
     */
    public static $subPages = null;

    /**
     * Static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Api_Backups_Admin
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Constructor
     *
     * Runs each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_init', array( &$this, 'admin_init' ) );
        add_filter( 'mainwp_getprimarybackup_methods', array( &$this, 'hook_getprimarybackup_methods' ) );
        add_filter( 'mainwp_managesites_getbackuplink', array( &$this, 'hook_managesites_getbackuplink' ), 10, 4 );

        $this->update_check();
        Api_Backups_Overview::get_instance();
        Api_Backups_Settings::get_instance();
        Api_Backups_Handler::get_instance();
        Api_Backups_Hooks::get_instance();
    }

    /**
     * Method admin_init() initiated by init()
     *
     * Enqueue scripts and styles.
     */
    public function admin_init() {
        add_filter( 'mainwp_log_specific_actions', array( &$this, 'hook_mainwp_log_specific_actions' ), 10, 2 );
        Api_Backups_3rd_Party::instance()->init_ajax_actions();
        $module_url = Api_Backups_Manager::get_location_path( 'url' );

        if ( isset( $_GET['page'] ) && ( 'ManageApiBackups' === $_GET['page'] || 'ManageSitesApiBackups' === $_GET['page'] || 'SettingsApiBackups' === $_GET['page'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            // Enqueue needed assets.
            wp_enqueue_script( 'mainwp-module-api-backups', $module_url . 'assets/js/module-api-backups.js', array(), $this->version, true );
            wp_enqueue_script( 'mainwp-module-api-backups-manager', $module_url . 'assets/js/module-api-backups-manager.js', array( 'mainwp' ), $this->version, true );
            wp_enqueue_style( 'mainwp-module-api-backups', $module_url . 'assets/css/module-api-backups.css', array(), $this->version );
        }
    }


    /**
     * Method hook_getprimarybackup_methods().
     *
     * @param array $input Input values.
     *
     * @return array $input
     */
    public function hook_getprimarybackup_methods( $input ) {
        $input[] = array(
            'value' => 'module-api-backups',
            'title' => esc_html__( 'API Backups', 'mainwp' ),
        );
        return $input;
    }

    /**
     * Handle @filter hook_managesites_getbackuplink().
     *
     * @param array  $input Input values.
     * @param int    $site_id Site id.
     * @param int    $last_primary_backup Last time primary backup.
     * @param string $primary_backup Primary backup method.
     *
     * @return array $input
     */
    public function hook_managesites_getbackuplink( $input, $site_id, $last_primary_backup = 0, $primary_backup = '' ) {
        if ( 'module-api-backups' !== $primary_backup ) {
            return $input;
        }

        if ( $site_id ) {
            $output = '';
            if ( \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
                if ( ! empty( $last_primary_backup ) ) {
                    $output = '<span data-tooltip="' . esc_attr__( 'Last backup available: ', 'mainwp' ) . esc_html( MainWP_Utility::time_elapsed_string( $last_primary_backup ) ) . '" data-position="left center" data-inverted=""><a class="ui mini grey icon basic button" href="admin.php?page=ManageSitesApiBackups&id=' . $site_id . '" class="green"><i class="history green icon"></i></a></span>';
                } else {
                    $output = '<span data-tooltip="' . esc_attr__( 'No backups taken yet. ', 'mainwp' ) . '" data-position="left center" data-inverted=""><a class="ui mini grey icon basic button" href="admin.php?page=ManageSitesApiBackups&id=' . intval( $site_id ) . '" class="grey"><i class="history grey icon"></i></a></span>';
                }
            }
            return $output;
        } else {
            return $input;
        }
    }


    /**
     * Method hook_mainwp_log_specific_actions().
     *
     * @param array $input Input values.
     *
     * @return array $input
     */
    public function hook_mainwp_log_specific_actions( $input ) {
        $input[ MainWP_Logger::API_BACKUPS_LOG_PRIORITY ] = 'API Backups';
        return $input;
    }

    /**
     * Method update_check().
     *
     * Handle update db values if needed .
     */
    public function update_check() {

        $current_update = get_option( 'mainwp_api_backups_update_version' );

        if ( ( false === $current_update && version_compare( $this->version, '4.2', '<=' ) ) || version_compare( $current_update, '4.1', '<' ) ) {
            $map_names = array(
                'vultr'        => 'mainwp_vultr_api_key',
                'gridpane'     => 'mainwp_gridpane_api_key',
                'linode'       => 'mainwp_linode_api_key',
                'digitalocean' => 'mainwp_digitalocean_api_key',
                'cloudways'    => 'mainwp_cloudways_api_key',
            );

            foreach ( $map_names as $api_name => $old_name ) {
                $data = get_option( $old_name );
                if ( ! empty( $data ) ) {
                    $encrypted = Api_Backups_Utility::get_instance()->encrypt_api_keys( $data, false, false, $api_name );
                    if ( is_array( $encrypted ) && ! empty( $encrypted['file_key'] ) ) {
                        update_option( 'mainwp_api_backups_' . $api_name . '_api_key', $encrypted );
                        delete_option( $old_name );
                    }
                }
            }
            update_option( 'mainwp_api_backups_update_version', $this->update_version );
        }
    }

    /**
     * Method init_menu()
     *
     * Add Insights Overview sub menu "Insights".
     */
    public static function init_menu() {
        static::$page = add_submenu_page(
            'mainwp_tab',
            esc_html__( 'API Backups', 'mainwp' ),
            '<div class="mainwp-hidden" id="mainwp-api-backups">' . esc_html__( 'API Backups', 'mainwp' ) . '</div>',
            'read',
            'ManageApiBackups',
            array(
                Api_Backups_Overview::get_instance(),
                'render_backups_list',
            )
        );

        /**
         * This hook allows you to add extra sub pages to the client page via the 'mainwp_getsubpages_cost_tracker' filter.
         */
        static::$subPages = apply_filters( 'mainwp_getsubpages_api_backups', array() );

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( empty( $subPage['slug'] ) || empty( $subPage['callback'] ) ) {
                    continue;
                }
                if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageApiBackups' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . esc_html( $subPage['title'] ) . '</div>', 'read', 'ManageApiBackups' . $subPage['slug'], $subPage['callback'] );
            }
        }

        static::init_left_menu( static::$subPages );
    }



    /**
     * Initiates API Backups menu.
     *
     * @param array $subPages API Backups sub pages.
     */
    public static function init_left_menu( $subPages ) {
        MainWP_Menu::add_left_menu(
            array(
                'title'         => esc_html__( 'API Backups', 'mainwp' ),
                'parent_key'    => 'managesites',
                'slug'          => 'ManageApiBackups',
                'href'          => 'admin.php?page=ManageApiBackups',
                'icon'          => '<i class="pie chart icon"></i>',
                'desc'          => 'API Backups Overview',
                'nosubmenu'     => true,
                'leftsub_order' => 8,
            ),
            1
        );

        $init_sub_subleftmenu = array();

        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ManageApiBackups', 'ManageApiBackups' );

        foreach ( $init_sub_subleftmenu as $item ) {
            if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
                continue;
            }
            MainWP_Menu::add_left_menu( $item, 2 );
        }
    }

    /**
     * Method render_header()
     *
     * Render page header.
     */
    public static function render_header() {
        $params = array(
            'title'      => esc_html__( 'API Backups - BETA', 'mainwp' ),
            'which'      => 'page_api_backups_overview',
            'wrap_class' => 'mainwp-module-api-backups-content-wrapper',
        );
        MainWP_UI::render_top_header( $params );
    }
}
