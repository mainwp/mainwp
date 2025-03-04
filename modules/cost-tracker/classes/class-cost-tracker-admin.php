<?php
/**
 * MainWP Module Cost Tracker Admin class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Menu;
use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_Settings_Indicator;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class Cost_Tracker_Admin
 */
class Cost_Tracker_Admin { // phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Variable to hold the version number.
     *
     * @var mixed Version.
     */
    public $version = '1.0';

    /**
     * Variable to hold the Page value.
     *
     * @static
     * @var mixed Page value, default null.
     */
    public static $page = null;

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
     * @return Cost_Tracker_Admin
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor
     *
     * Runs each time the class is called.
     */
    public function __construct() {
        add_action( 'init', array( &$this, 'init' ) );
        add_filter( 'mainwp_init_primary_menu_items', array( $this, 'hook_init_primary_menu_items' ), 10, 2 );
        add_filter( 'mainwp_module_cost_tracker_get_default_cost_fields', array( $this, 'hook_get_default_cost_fields' ), 10, 2 );
        add_filter( 'mainwp_module_cost_tracker_get_next_renewal', array( $this, 'hook_get_next_renewal' ), 10, 3 );
        add_action( 'mainwp_delete_site', array( $this, 'hook_delete_site' ), 10, 3 );
        add_filter( 'mainwp_module_cost_tracker_get_total_cost', array( $this, 'hook_get_total_cost' ), 10, 2 );
    }


    /**
     * Initiate Hooks
     *
     * Initiates hooks for the Cost Tracker module.
     */
    public function init() {
        add_action( 'admin_init', array( &$this, 'admin_init' ) );
        add_action( 'mainwp_help_sidebar_content', array( $this, 'mainwp_help_content' ) );
        add_filter( 'mainwp_log_specific_actions', array( $this, 'hook_log_specific_actions' ) );
        Cost_Tracker_Hooks::get_instance()->init();
        Cost_Tracker_Dashboard::get_instance();
        Cost_Tracker_DB::get_instance()->init();
        $this->handle_sites_screen_settings();
    }

    /**
     * Method hook_delete_site()
     *
     * Installs the new DB.
     *
     * @param mixed $site site object.
     *
     * @return bool result.
     */
    public function hook_delete_site( $site ) {
        if ( empty( $site ) ) {
            return false;
        }
        return MainWP_DB::instance()->delete_lookup_items(
            'object_id',
            array(
                'item_name'    => 'cost',
                'object_id'    => $site->id,
                'object_names' => array( 'site' ),
            )
        );
    }

    /**
     * Admin Init
     *
     * Initiates admin hooks.
     */
    public function admin_init() {

        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_upload_product_icon', array( Cost_Tracker_Add_Edit::get_instance(), 'ajax_upload_product_icon' ) );

        $this->handle_edit_cost_tracker_post();
        $this->handle_settings_post();

        $allow_pages = array( 'ManageCostTracker', 'CostTrackerAdd', 'CostTrackerSettings', 'CostSummary' );
        if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allow_pages, true ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $base_url = Cost_Tracker_Manager::get_location_path( 'url' );
            wp_enqueue_script( 'mainwp-module-cost-tracker-extension', $base_url . 'ui/js/cost-tracker.js', array( 'jquery' ), $this->version, true );
            if ( 'CostSummary' === $_GET['page'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
                add_filter(
                    'mainwp_admin_enqueue_scripts',
                    function ( $scripts ) {
                        if ( is_array( $scripts ) ) {
                            $scripts['apexcharts'] = true;
                        }
                        return $scripts;
                    }
                );
            }
        }
    }

    /**
     * Hook hook_log_specific_actions.
     *
     * @param array $logs_spec Log actions.
     *
     * @return array $logs_spec Log actions.
     */
    public function hook_log_specific_actions( $logs_spec ) {
        $logs_spec[ MainWP_Logger::COST_TRACKER_LOG_PRIORITY ] = __( 'Cost Tracker', 'mainwp' );
        return $logs_spec;
    }

    /**
     * Init sub menu logs settings.
     *
     * @param array  $items Sub menu items.
     * @param string $which_menu first|second.
     *
     * @return array $tmp_items Menu items.
     */
    public function hook_init_primary_menu_items( $items, $which_menu ) {
        if ( ! is_array( $items ) || 'first' !== $which_menu ) {
            return $items;
        }
        $items[] = array(
            'slug'               => 'ManageCostTracker',
            'menu_level'         => 2,
            'menu_rights'        => array(
                'dashboard' => array(
                    'manage_cost_tracker',
                ),
            ),
            'init_menu_callback' => array( static::class, 'init_menu' ),
            'leftbar_order'      => 2.8,
        );
        return $items;
    }

    /**
     * Method hook_get_default_cost_fields().
     *
     * @param string $def_val Default value.
     * @param string $field Field name.
     *
     * @return array Default fields values.
     */
    public function hook_get_default_cost_fields( $def_val = null, $field = 'all' ) {
        unset( $def_val );
        return static::get_default_fields_values( $field );
    }

    /**
     * Method get_default_fields_values().
     *
     * @param string $field Field name.
     *
     * @return array Default fields values.
     */
    public static function get_default_fields_values( $field = 'all' ) {
        if ( empty( $field ) || ! is_string( $field ) ) {
            $field = 'all';
        }

        $license_types     = array(
            'single_site' => esc_html__( 'Single-Site License', 'mainwp' ),
            'multi_site'  => esc_html__( 'Multiple-Site License', 'mainwp' ),
        );
        $product_types     = static::get_product_types();
        $payment_types     = array(
            'subscription' => esc_html__( 'Subscription', 'mainwp' ),
            'lifetime'     => esc_html__( 'Lifetime', 'mainwp' ),
        );
        $payment_methods   = static::get_payment_methods();
        $renewal_frequency = static::get_renewal_frequency();
        $cost_status       = static::get_cost_status();

        $all_defaults = array(
            'license_types'     => $license_types,
            'product_types'     => $product_types,
            'payment_types'     => $payment_types,
            'payment_methods'   => $payment_methods,
            'renewal_frequency' => $renewal_frequency,
            'cost_status'       => $cost_status,
        );

        if ( 'all' === $field ) {
            return $all_defaults;
        }
        return isset( $all_defaults[ $field ] ) ? $all_defaults[ $field ] : array();
    }


    /**
     * Method init_menu()
     *
     * Add Insights Overview sub menu "Insights".
     */
    public static function init_menu() {

        static::$page = add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Cost Tracker', 'mainwp' ),
            '<span id="mainwp-cost-tracker-summary">' . esc_html__( 'Cost Tracker', 'mainwp' ) . '</span>',
            'read',
            'CostSummary',
            array(
                Cost_Tracker_Summary::instance(),
                'render_summary_page',
            )
        );

        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Manage Cost Tracker', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Manage Cost Tracker', 'mainwp' ) . '</div>',
            'read',
            'ManageCostTracker',
            array(
                Cost_Tracker_Dashboard::get_instance(),
                'render_overview_page',
            )
        );

        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Add New', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Add New', 'mainwp' ) . '</div>',
            'read',
            'CostTrackerAdd',
            array(
                Cost_Tracker_Add_Edit::get_instance(),
                'render_add_edit_page',
            )
        );

        /**
         * This hook allows you to add extra sub pages to the client page via the 'mainwp_getsubpages_cost_tracker' filter.
         */
        static::$subPages = apply_filters( 'mainwp_getsubpages_cost_tracker', array() );

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( empty( $subPage['slug'] ) || empty( $subPage['callback'] ) ) {
                    continue;
                }
                if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageCostTracker' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . esc_html( $subPage['title'] ) . '</div>', 'read', 'ManageCostTracker' . $subPage['slug'], $subPage['callback'] );
            }
        }
        add_action( 'load-' . static::$page, array( static::class, 'on_load_summary_page' ) );

        if ( isset( $_GET['page'] ) && 'CostSummary' === $_GET['page'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_filter( 'mainwp_enqueue_script_gridster', '__return_true' );
        }

        static::init_left_menu( static::$subPages );
    }

    /**
     * Method on_load_summary_page()
     *
     * Run on page load.
     */
    public static function on_load_summary_page() {
        Cost_Tracker_Summary::instance()->on_load_page( static::$page );
    }

    /**
     * Initiates Cost Tracker menu.
     *
     * @param array $subPages Cost Tracker sub pages.
     */
    public static function init_left_menu( $subPages ) {
        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'Cost Tracker', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'CostSummary',
                'href'       => 'admin.php?page=CostSummary',
                'icon'       => '<i class="dollar sign icon"></i>',
                'desc'       => 'Costr Tracker Summary',
                'nosubmenu'  => true,
            ),
            0
        );
        $init_sub_subleftmenu = array(
            array(
                'title'      => esc_html__( 'Cost Summary', 'mainwp' ),
                'parent_key' => 'CostSummary',
                'href'       => 'admin.php?page=CostSummary',
                'slug'       => 'CostSummary',
                'right'      => 'manage_cost_tracker',
            ),
            array(
                'title'      => esc_html__( 'Manage Costs', 'mainwp' ),
                'parent_key' => 'CostSummary',
                'href'       => 'admin.php?page=ManageCostTracker',
                'slug'       => 'ManageCostTracker',
                'right'      => 'manage_cost_tracker',
            ),
            array(
                'title'      => esc_html__( 'Add New', 'mainwp' ),
                'parent_key' => 'CostSummary',
                'href'       => 'admin.php?page=CostTrackerAdd',
                'slug'       => 'CostTrackerAdd',
                'right'      => '',
            ),
        );

        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ManageCostTracker', 'ManageCostTracker' );

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
     *
     * @param string $shownPage Current Page.
     */
    public static function render_header( $shownPage = '' ) { //phpcs:ignore -- NOSONAR - complex.
        $params = array(
            'title'      => esc_html__( 'Cost Tracker', 'mainwp' ),
            'which'      => 'page_cost_tracker_overview',
            'wrap_class' => 'mainwp-module-cost-tracker-content-wrapper',
        );

        MainWP_UI::render_top_header( $params );

        $renderItems = array();

        if ( \mainwp_current_user_can( 'dashboard', 'manage_cost_tracker' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Overview', 'mainwp' ),
                'href'   => 'admin.php?page=ManageCostTracker',
                'active' => ( '' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'CostTrackerAdd' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Add New', 'mainwp' ),
                'href'   => 'admin.php?page=CostTrackerAdd',
                'active' => ( 'add' === $shownPage ) ? true : false,
            );
        }

        $cost_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $cost_id ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Edit Cost Tracker', 'mainwp' ),
                'href'   => 'admin.php?page=CostTrackerAdd&id=' . $cost_id,
                'active' => ( 'edit' === $shownPage ) ? true : false,
            );
        }

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageCostTracker' . $subPage['slug'] ) ) {
                    continue;
                }

                $item          = array();
                $item['title'] = $subPage['title'];

                $item['href']   = 'admin.php?page=ManageCostTracker' . $subPage['slug'];
                $item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
                $renderItems[]  = $item;
            }
        }

        MainWP_UI::render_page_navigation( $renderItems );
    }

    /**
     * Edit subscription Post
     *
     * Handles the saving subscription.
     *
     * @return mixed Save output.
     */
    public static function handle_edit_cost_tracker_post() { //phpcs:ignore -- NOSONAR - complex method.

        $updating = ! empty( $_POST['mainwp_module_cost_tracker_edit_id'] ) ? intval( $_POST['mainwp_module_cost_tracker_edit_id'] ) : ''; //phpcs:ignore -- -- NOSONAR - ok.

        if ( ! isset( $_POST['mwp_cost_tracker_editing_submit'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'module_cost_tracker_edit_nonce' . $updating ) ) {
            return;
        }
        //phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $last_renewal             = isset( $_POST['mainwp_module_cost_tracker_edit_last_renewal'] ) ? strtotime( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_last_renewal'] ) ) : 0;
        $update                   = array();
        $update['name']           = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_name'] ) );
        $update['type']           = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_payment_type'] ) );
        $update['product_type']   = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_product_type'] ) );
        $update['slug']           = isset( $_POST['mainwp_module_cost_tracker_edit_product_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_product_slug'] ) ) : '';
        $update['license_type']   = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_license_type'] ) );
        $update['cost_status']    = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_cost_tracker_status'] ) );
        $update['url']            = ! empty( $_POST['mainwp_module_cost_tracker_edit_url'] ) ? esc_url_raw( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_url'] ) ) : '';
        $update['cost_icon']      = ! empty( $_POST['mainwp_module_cost_tracker_edit_icon_hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_icon_hidden'] ) ) : '';
        $update['cost_color']     = sanitize_hex_color( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_product_color'] ) );
        $update['price']          = floatval( $_POST['mainwp_module_cost_tracker_edit_price'] );
        $update['payment_method'] = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_payment_method'] ) );

        $renewal_fequency       = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_renewal_type'] ) );
        $update['renewal_type'] = $renewal_fequency;
        $update['last_renewal'] = $last_renewal; // labeled Purchase date.

        $next_renewal           = static::get_next_renewal( $last_renewal, $renewal_fequency );
        $update['next_renewal'] = $next_renewal;

        $note           = isset( $_POST['mainwp_module_cost_tracker_edit_note'] ) ? wp_unslash( $_POST['mainwp_module_cost_tracker_edit_note'] ) : '';
        $esc_note       = apply_filters( 'mainwp_escape_content', $note );
        $update['note'] = $esc_note;

        $selected_sites   = array();
        $selected_groups  = array();
        $selected_clients = array();

        if ( isset( $_POST['select_by'] ) ) {
            if ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) {
                foreach ( wp_unslash( $_POST['selected_sites'] ) as $selected ) {
                    $selected_sites[] = intval( $selected );
                }
            }

            if ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) {
                foreach ( wp_unslash( $_POST['selected_groups'] ) as $selected ) {
                    $selected_groups[] = intval( $selected );
                }
            }

            if ( isset( $_POST['selected_clients'] ) && is_array( $_POST['selected_clients'] ) ) {
                foreach ( wp_unslash( $_POST['selected_clients'] ) as $selected ) {
                    $selected_clients[] = intval( $selected );
                }
            }
        }

        $update['sites']   = ! empty( $selected_sites ) ? wp_json_encode( $selected_sites ) : '';
        $update['groups']  = ! empty( $selected_groups ) ? wp_json_encode( $selected_groups ) : '';
        $update['clients'] = ! empty( $selected_clients ) ? wp_json_encode( $selected_clients ) : '';

        $current = false;
        if ( ! empty( $_POST['mainwp_module_cost_tracker_edit_id'] ) ) {
            $update['id'] = intval( $_POST['mainwp_module_cost_tracker_edit_id'] );
            $current      = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $update['id'] );
        }

        //phpcs:enable
        $err_msg = '';
        $output  = false;
        try {
            $output = Cost_Tracker_DB::get_instance()->update_cost_tracker( $update );
            if ( $output && ! empty( $output->id ) ) {
                Cost_Tracker_DB::get_instance()->update_selected_lookup_cost( $output->id, $selected_sites, $selected_groups, $selected_clients );
                if ( $current && ! empty( $current->cost_icon ) && false === strpos( $current->cost_icon, 'deficon:' ) && $current->cost_icon !== $update['cost_icon'] ) {
                    Cost_Tracker_Add_Edit::get_instance()->delete_product_icon_file( $current->cost_icon );
                }
                $next_today = static::calc_next_renewal_today( $output, $next_renewal );
                Cost_Tracker_DB::get_instance()->update_cost_tracker(
                    array(
                        'id'                 => $output->id,
                        'next_renewal_today' => $next_today,
                    )
                );
            }
        } catch ( MainWP_Exception $ex ) {
            $err_msg = $ex->getMessage();
        }

        $msg_id = ! empty( $update['id'] ) ? intval( $update['id'] ) : 0;

        if ( ! empty( $err_msg ) ) {
            set_transient( 'mainwp_cost_tracker_update_error_' . $msg_id, $err_msg, HOUR_IN_SECONDS );
        }

        if ( empty( $err_msg ) && ! empty( $output ) ) { // success.
            wp_safe_redirect( admin_url( 'admin.php?page=CostTrackerAdd&message=1&id=' . $output->id ) );
        } elseif ( ! empty( $err_msg ) ) { // error.
            wp_safe_redirect( admin_url( 'admin.php?page=CostTrackerAdd&message=2&id=' . $msg_id ) );
        }
        exit();
    }


    /**
     * Settigns Post
     *
     * Handles the save settings post request.
     *
     * @return mixed Save output.
     */
    public static function handle_settings_post() {
        if ( ! isset( $_POST['mwp_cost_tracker_settings_submit'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'module_cost_tracker_settings_nonce' ) ) {
            return;
        }

        $all_opts        = Cost_Tracker_Utility::get_instance()->get_all_options();
        $currency        = isset( $_POST['mainwp_module_cost_tracker_settings_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_settings_currency'] ) ) : '';
        $currency_format = isset( $_POST['mainwp_module_cost_tracker_currency_format'] ) ? wp_unslash( $_POST['mainwp_module_cost_tracker_currency_format'] ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $currency_format = Cost_Tracker_Utility::validate_currency_settings( $currency_format );

        $product_types_colors = array();
        $product_types_icons  = array();

        // first.
        $cust_product_types = isset( $_POST['cost_tracker_custom_product_types'] ) ? wp_unslash( $_POST['cost_tracker_custom_product_types'] ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $cust_product_types = static::validate_custom_settings_text_fields( $cust_product_types, $product_types_colors, $product_types_icons );

        // second.
        $default_product_colors = isset( $_POST['cost_tracker_default_product_types'] ) ? wp_unslash( $_POST['cost_tracker_default_product_types'] ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        static::validate_custom_settings_text_fields( $default_product_colors, $product_types_colors, $product_types_icons );

        $cust_payment_methods = isset( $_POST['cost_tracker_custom_payment_methods'] ) ? wp_unslash( $_POST['cost_tracker_custom_payment_methods'] ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $cust_payment_methods = static::validate_custom_settings_text_fields( $cust_payment_methods );

        $all_opts['currency']               = $currency;
        $all_opts['currency_format']        = $currency_format;
        $all_opts['custom_product_types']   = wp_json_encode( $cust_product_types );
        $all_opts['custom_payment_methods'] = wp_json_encode( $cust_payment_methods );
        $all_opts['product_types_colors']   = wp_json_encode( $product_types_colors );
        $all_opts['product_types_icons']    = wp_json_encode( $product_types_icons );

        $all_opts = apply_filters( 'mainwp_module_cost_tracker_before_save_settings', $all_opts );

        Cost_Tracker_Utility::get_instance()->save_options( $all_opts );

        wp_safe_redirect( admin_url( 'admin.php?page=CostTrackerSettings&message=1' ) );
        exit();
    }


    /**
     * Method array_validate_text_fields().
     *
     * @param array $arr Data to valid.
     * @param mixed $product_types_colors Product types colors.
     * @param mixed $product_types_icons Product types icons.
     *
     * @return array Validated array fields data.
     */
    public static function validate_custom_settings_text_fields( $arr, &$product_types_colors = null, &$product_types_icons = null ) {
        if ( ! is_array( $arr ) || ! isset( $arr['title'] ) || ! is_array( $arr['title'] ) ) {
            return array();
        }
        $valid_arr = array();
        foreach ( $arr['title'] as $idx => $title ) {
            $title = trim( $title );
            if ( empty( $title ) ) {
                continue;
            }
            $slug = isset( $arr['slug'][ $idx ] ) ? sanitize_title( $arr['slug'][ $idx ] ) : '';

            if ( empty( $slug ) ) {
                $slug = sanitize_title( $title );
            }
            $slug               = strtolower( $slug );
            $valid_arr[ $slug ] = sanitize_text_field( $title );

            if ( is_array( $product_types_colors ) && is_array( $arr['color'] ) && isset( $arr['color'][ $idx ] ) ) {
                $product_types_colors[ $slug ] = sanitize_hex_color( wp_unslash( $arr['color'][ $idx ] ) );
            }

            if ( is_array( $product_types_icons ) && is_array( $arr['icon'] ) && isset( $arr['icon'][ $idx ] ) ) {
                $product_types_icons[ $slug ] = sanitize_text_field( wp_unslash( $arr['icon'][ $idx ] ) );
            }
        }
        return $valid_arr;
    }

    /**
     * Method hook_get_next_renewal().
     *
     * Get the next renewal.
     *
     * @param mixed  $filter_input filter input value.
     * @param int    $previous_renewal last renewal.
     * @param string $renewal_type renewal time.
     */
    public function hook_get_next_renewal( $filter_input, $previous_renewal, $renewal_type ) {
        unset( $filter_input );
        return static::get_next_renewal( $previous_renewal, $renewal_type );
    }

    /**
     * Method hook_get_total_cost().
     *
     * Get total costs.
     */
    public function hook_get_total_cost() {
        return Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'count' );
    }

    /**
     * Method get_next_renewal().
     *
     * Get the next renewal.
     *
     * @param int    $previous_renewal last renewal.
     * @param string $renewal_type renewal time.
     * @param bool   $get_real_renewal To get future renewal, true|false.
     */
    public static function get_next_renewal( $previous_renewal, $renewal_type, $get_real_renewal = true ) {
        $next_renewal = 0;
        if ( ! empty( $previous_renewal ) && ! empty( $renewal_type ) && 'disabled' !== $renewal_type ) {

            if ( 'monthly' === $renewal_type ) {
                $next_renewal = strtotime( '+1 month', $previous_renewal );
            } elseif ( 'yearly' === $renewal_type ) {
                $next_renewal = strtotime( '+1 year', $previous_renewal );
            } elseif ( 'weekly' === $renewal_type ) {
                $next_renewal = strtotime( '+1 week', $previous_renewal );
            } elseif ( 'quarterly' === $renewal_type ) {
                $next_renewal = strtotime( '+3 month', $previous_renewal );
            }

            $today_time = strtotime( gmdate( 'Y-m-d 00:00:00' ) );

            if ( $get_real_renewal && $next_renewal < $today_time ) {
                $next_renewal = static::get_next_renewal( $next_renewal, $renewal_type, $get_real_renewal );
            }
        }
        return $next_renewal;
    }

    /**
     * Method get_default_product_types().
     */
    public static function get_default_product_types() {
        return array(
            'plugin'  => esc_html__( 'Plugin', 'mainwp' ),
            'theme'   => esc_html__( 'Theme', 'mainwp' ),
            'hosting' => esc_html__( 'Hosting', 'mainwp' ),
            'service' => esc_html__( 'Service', 'mainwp' ),
            'other'   => esc_html__( 'Other', 'mainwp' ),
        );
    }

    /**
     * Method get_default_product_type_colors().
     */
    public static function get_default_product_type_colors() {
        return array(
            'plugin'  => '#5ec130',
            'theme'   => '#34afd8',
            'hosting' => '#f2e93a',
            'service' => '#ed1730',
            'other'   => '#14f4fc',
        );
    }

    /**
     * Method get_product_colors().
     *
     * @param string $type Product type.
     */
    public static function get_product_colors( $type = false ) {
        $defaults = static::get_default_product_type_colors();

        $product_colors = Cost_Tracker_Utility::get_instance()->get_option( 'product_types_colors', array(), true );
        if ( is_array( $product_colors ) && ! empty( $product_colors ) ) {
            $product_colors = array_replace( $defaults, $product_colors ); // to fix: array merge with keys.
        } else {
            $product_colors = $defaults;
        }

        if ( ! empty( $type ) && is_string( $type ) ) {
            return isset( $product_colors[ $type ] ) ? $product_colors[ $type ] : '';
        }
        return $product_colors; // return all colors.
    }


    /**
     * Method get_default_product_types_icons().
     */
    public static function get_default_product_types_icons() {
        return array(
            'plugin'  => 'plug',
            'theme'   => 'tint',
            'hosting' => 'server',
            'service' => 'wrench',
            'other'   => 'folder',
        );
    }

    /**
     * Method get_product_type_icons().
     *
     * @param string $type Product type.
     */
    public static function get_product_type_icons( $type = false ) {
        $product_icons = Cost_Tracker_Utility::get_instance()->get_option( 'product_types_icons', array(), true );
        if ( ! is_array( $product_icons ) ) {
            $product_icons = array();
        }
        if ( ! empty( $type ) && is_string( $type ) ) {
            return isset( $product_icons[ $type ] ) ? $product_icons[ $type ] : '';
        }
        return $product_icons; // return all colors.
    }

    /**
     * Method get_product_types().
     */
    public static function get_product_types() {
        $product_types      = static::get_default_product_types();
        $cust_product_types = Cost_Tracker_Utility::get_instance()->get_option( 'custom_product_types', array(), true );
        if ( ! empty( $cust_product_types ) ) {
            $product_types = array_replace( $product_types, $cust_product_types ); // to fix: array merge with keys.
        }
        return $product_types;
    }

    /**
     * Method get_payment_methods().
     */
    public static function get_payment_methods() {
        $payment_methods      = array(
            'paypal'       => esc_html__( 'PayPal', 'mainwp' ),
            'stripe'       => esc_html__( 'Stripe', 'mainwp' ),
            'apple'        => esc_html__( 'Apple Pay', 'mainwp' ),
            'amazon'       => esc_html__( 'Amazon Pay', 'mainwp' ),
            'google'       => esc_html__( 'Google Pay', 'mainwp' ),
            'credit_debit' => esc_html__( 'Credit Card', 'mainwp' ),
            'debit_card'   => esc_html__( 'Debit Card', 'mainwp' ),
            'cash'         => esc_html__( 'Cash', 'mainwp' ),
        );
        $cust_payment_methods = Cost_Tracker_Utility::get_instance()->get_option( 'custom_payment_methods', array(), true );
        if ( ! empty( $cust_payment_methods ) ) {
            $payment_methods = array_replace( $payment_methods, $cust_payment_methods ); // to fix: array merge with keys.
        }
        return $payment_methods;
    }

    /**
     * Method get_renewal_frequency().
     */
    public static function get_renewal_frequency() {
        return array(
            'weekly'    => esc_html__( 'Weekly', 'mainwp' ),
            'monthly'   => esc_html__( 'Monthly', 'mainwp' ),
            'quarterly' => esc_html__( 'Quarterly', 'mainwp' ),
            'yearly'    => esc_html__( 'Yearly', 'mainwp' ),
        );
    }

    /**
     * Method get_cost_status().
     */
    public static function get_cost_status() {
        return array(
            'active'              => esc_html__( 'Active', 'mainwp' ),
            'canceled'            => esc_html__( 'Canceled', 'mainwp' ),
            'onhold'              => esc_html__( 'On Hold', 'mainwp' ),
            'expired'             => esc_html__( 'Expired', 'mainwp' ),
            'pending_cancelation' => esc_html__( 'Pending Cancelation', 'mainwp' ),
        );
    }

    /**
     * Method handle_sites_screen_settings()
     *
     * Handle sites screen settings
     */
    public function handle_sites_screen_settings() { //phpcs:ignore -- NOSONAR - complex.
        if ( isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'CostTrackerSitesScrOptions' ) ) {
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
                update_user_option( $user->ID, 'mainwp_module_costs_tracker_manage_showhide_columns', $show_cols, true );
            }
        }
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        $allow_pages = array( 'ManageCostTracker', 'CostTrackerAdd', 'CostTrackerSettings', 'CostSummary' );
        if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allow_pages, true ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <p><?php esc_html_e( 'If you need help with the Cost Tracker module, please review following help documents', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-cost-tracker/" target="_blank">Cost Tracker</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-cost-tracker/#manage-costs-page" target="_blank">Manage Costs</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-cost-tracker/#adding-a-new-cost-to-track" target="_blank">Adding a New Cost to track</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-cost-tracker/#edit-an-item" target="_blank">Edit Costs</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-cost-tracker/#delete-an-item" target="_blank">Delete Costs</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-cost-tracker/#settings-page" target="_blank">Cost Tracker Settings</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-cost-tracker/#cost-tracker-pro-extension" target="_blank">Cost Tracker Pro Extension</a></div> <?php // NOSONAR -- compatible with help. ?>
                <?php
                /**
                 * Action: mainwp_module_cost_tracker_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the Cost Tracker page.
                 *
                 * Suggested HTML markup:
                 *
                 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
                 *
                 * @since 5.0
                 */
                do_action( 'mainwp_module_cost_tracker_help_item' );
                ?>
            </div>
            <?php
        }
    }

    /**
     * Method calc_next_renewal_today()
     *
     * Calculate next renewal today.
     *
     * @param object $subscription subscription.
     * @param int    $next_renewal Next renewal.
     *
     * @return mixed result.
     */
    public static function calc_next_renewal_today( $subscription, $next_renewal = false ) {
        if ( 'lifetime' === $subscription->type ) {
            return 0;
        }
        $next_renewal = ! empty( $next_renewal ) ? intval( $next_renewal ) : (int) $subscription->next_renewal;
        if ( empty( $next_renewal ) ) {
            return 0;
        }
        if ( 'active' !== $subscription->cost_status ) {
            return 0;
        }
        return $next_renewal;
    }

    /**
     * Method generate_next_renewal()
     *
     * Generate next renewal info.
     *
     * @param object $subscription subscription.
     * @param int    $next_renewal Next renewal.
     *
     * @return void
     */
    public static function generate_next_renewal( $subscription, $next_renewal = false ) {
        if ( empty( $subscription ) || ! is_object( $subscription ) ) {
            echo '';
            return;
        }

        if ( 'lifetime' === $subscription->type ) {
            echo '';
            return;
        }

        $next_renewal = ! empty( $next_renewal ) ? intval( $next_renewal ) : (int) $subscription->next_renewal;

        if ( empty( $next_renewal ) ) {
            echo '';
            return;
        }
        if ( 'active' !== $subscription->cost_status ) {
            echo '';
            return;
        }

        $current_time = time();
        $renewal_html = MainWP_Utility::format_date( $next_renewal );
        $day1         = $next_renewal - 15 * DAY_IN_SECONDS;
        $day2         = $next_renewal - 7 * DAY_IN_SECONDS;
        if ( $day1 > $current_time ) {
            $renewal_html = '<strong>' . esc_html( $renewal_html ) . '</strong>';
        } elseif ( $day1 <= $current_time && $current_time < $day2 ) {
            $renewal_html = '<strong><span data-tooltip="Renewal approaching soon. Please review your subscription details." data-inverted="" data-position="left center"><i class="orange bell icon"></i></span>' . esc_html( $renewal_html ) . '</strong>';
        } elseif ( $day2 <= $current_time && $current_time < $next_renewal ) {
            $renewal_html = '<strong><span data-tooltip="Renewal approaching soon. Please review your subscription details." data-inverted="" data-position="left center"><i class="orange bell icon"></i></span>' . esc_html( $renewal_html ) . '</strong>';
        }
        echo $renewal_html; //phpcs:ignore -- ok.
    }

    /**
     * Returns the label for a cost status.
     *
     * @param mixed $key false|string to get status of key.
     *
     * @return string
     */
    public static function get_cost_status_label( $key = false ) {

        $default = array(
            'active'              => array(
                'label' => esc_html__( 'Active', 'mainwp' ),
                'class' => 'basic green center aligned fluid',
            ),
            'onhold'              => array(
                'label' => esc_html__( 'On Hold', 'mainwp' ),
                'class' => 'basic yellow center aligned fluid',
            ),
            'canceled'            => array(
                'label' => esc_html__( 'Canceled', 'mainwp' ),
                'class' => 'basic grey center aligned fluid',
            ),
            'expired'             => array(
                'label' => esc_html__( 'Expired', 'mainwp' ),
                'class' => 'basic purple center aligned fluid',
            ),
            'pending_cancelation' => array(
                'label' => esc_html__( 'Pending Cancelation', 'mainwp' ),
                'class' => 'basic black center aligned fluid',
            ),
        );

        if ( false !== $key ) {
            if ( empty( $key ) ) {
                $key = 'active';
            }
            return isset( $default[ $key ] ) ? '<span class="ui small ' . $default[ $key ]['class'] . ' label">' . $default[ $key ]['label'] . '</span>' : $key;
        }

        return $default;
    }

    /**
     * Gets product icon to output.
     *
     * @param string $product Product object.
     * @param string $img_id_attr img id attr.
     * @param bool   $with_color with color.
     */
    public function get_product_icon_display( $product = false, $img_id_attr = '', $with_color = true ) { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.

        $prod_icon  = '';
        $prod_color = '';

        if ( $product ) {
            $prod_icon  = $product->cost_icon;
            $prod_color = $product->cost_color;
        }

        if ( empty( $prod_icon ) || false !== strpos( $prod_icon, 'deficon:' ) ) {
            $upload_icon = '';
        } else {
            $upload_icon = $prod_icon;
        }

        $style = 'width:28px;height:auto;display:inline-block;';

        if ( empty( $prod_color ) ) {
            $prod_color = '#34424D';
        }

        $color_style = '';
        if ( $with_color ) {
            $color_style = 'color:' . esc_attr( $prod_color ) . ';';
        }

        $default_cls = 'module_cost_tracker_settings_upload_img_display';
        if ( empty( $prod_icon ) ) {
            $def_icon          = Cost_Tracker_Utility::get_product_default_icons( false, 'default_product' );
            $icon_wrapper_attr = ! empty( $img_id_attr ) ? 'id="' . esc_attr( $img_id_attr ) . '" ' : 'class="' . esc_attr( $default_cls ) . '"';
            $icon              = '<div style="display:inline-block;" ' . $icon_wrapper_attr . '><i style="' . $color_style . '" class="' . esc_attr( $def_icon ) . ' large icon"></i></div>';
        } elseif ( false !== strpos( $prod_icon, 'deficon:' ) ) {
            $icon_wrapper_attr = ! empty( $img_id_attr ) ? 'id="' . esc_attr( $img_id_attr ) . '" ' : 'class="' . esc_attr( $default_cls ) . '"';
            $icon              = '<div style="display:inline-block;" ' . $icon_wrapper_attr . '><i class="' . str_replace( 'deficon:', '', $prod_icon ) . ' large icon" style="' . $color_style . '" ></i></div>';
        } else {
            if ( ! empty( $upload_icon ) ) {
                $dirs      = MainWP_System_Utility::get_mainwp_dir( Cost_Tracker_Settings::$icon_sub_dir, true );
                $icon_base = $dirs[1];
                $scr       = $icon_base . $upload_icon;
            } else {
                $scr = '';
            }
            $icon_wrapper_attr = ! empty( $img_id_attr ) ? 'id="' . esc_attr( $img_id_attr ) . '" class="ui circular image" ' : 'class="' . esc_attr( $default_cls ) . ' ui circular image "';
            $icon              = '<div style="display:inline-block;" ' . $icon_wrapper_attr . '><img style="' . $style . '" alt="' . esc_attr__( 'Product icon', 'mainwp' ) . '" src="' . esc_attr( $scr ) . '"/></div>';
        }
        return $icon;
    }
}
