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
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Logger;
use function MainWP\Dashboard\mainwp_current_user_have_right;

/**
 * Class Cost_Tracker_Admin
 */
class Cost_Tracker_Admin {

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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
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
	}


	/**
	 * Initiate Hooks
	 *
	 * Initiates hooks for the Subscription extension.
	 */
	public function init() {
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'mainwp_help_sidebar_content', array( $this, 'mainwp_help_content' ) );
		add_filter( 'mainwp_log_specific_actions', array( $this, 'hook_log_specific_actions' ) );
		Cost_Tracker_Hooks::get_instance()->init();
		Cost_Tracker_Dashboard::get_instance();
		$this->handle_sites_screen_settings();
	}

	/**
	 * Admin Init
	 *
	 * Initiates admin hooks.
	 */
	public function admin_init() {
		$this->handle_edit_cost_tracker_post();
		$this->handle_settings_post();

		$allow_pages = array( 'ManageCostTracker', 'CostTrackerAdd', 'CostTrackerSettings' );
		if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allow_pages, true ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$base_url = Cost_Tracker_Manager::get_location_path( 'url' );
			wp_enqueue_style( 'mainwp-module-cost-tracker-extension', $base_url . 'ui/css/cost-tracker.css', array(), $this->version );
			wp_enqueue_script( 'mainwp-module-cost-tracker-extension', $base_url . 'ui/js/cost-tracker.js', array( 'jquery' ), $this->version, true );
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
			'init_menu_callback' => array( self::class, 'init_menu' ),
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
		return self::get_default_fields_values( $field );
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
		$product_types     = self::get_product_types();
		$payment_types     = array(
			'subscription' => esc_html__( 'Subscription', 'mainwp' ),
			'lifetime'     => esc_html__( 'Lifetime', 'mainwp' ),
		);
		$payment_methods   = self::get_payment_methods();
		$renewal_frequency = self::get_renewal_frequency();
		$cost_status       = self::get_cost_status();

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
		self::$page = add_submenu_page(
			'mainwp_tab',
			esc_html__( 'Cost Tracker', 'mainwp' ),
			'<span id="mainwp-cost-tracker">' . esc_html__( 'Cost Tracker', 'mainwp' ) . '</span>',
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

		add_submenu_page(
			'mainwp_tab',
			esc_html__( 'Settings', 'mainwp' ),
			'<div class="mainwp-hidden">' . esc_html__( 'Settings', 'mainwp' ) . '</div>',
			'read',
			'CostTrackerSettings',
			array(
				Cost_Tracker_Settings::get_instance(),
				'render_settings_page',
			)
		);

		/**
		 * This hook allows you to add extra sub pages to the client page via the 'mainwp_getsubpages_cost_tracker' filter.
		 */
		self::$subPages = apply_filters( 'mainwp_getsubpages_cost_tracker', array() );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( empty( $subPage['slug'] ) || empty( $subPage['callback'] ) ) {
					continue;
				}
				if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageCostTracker' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . esc_html( $subPage['title'] ) . '</div>', 'read', 'ManageCostTracker' . $subPage['slug'], $subPage['callback'] );
			}
		}

		self::init_left_menu( self::$subPages );
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
				'slug'       => 'ManageCostTracker',
				'href'       => 'admin.php?page=ManageCostTracker',
				'icon'       => '<i class="dollar sign icon"></i>',
				'desc'       => 'Costr Tracker Overview',
				'nosubmenu'  => true,
			),
			0
		);

		$init_sub_subleftmenu = array(
			array(
				'title'      => esc_html__( 'Manage Costs', 'mainwp' ),
				'parent_key' => 'ManageCostTracker',
				'href'       => 'admin.php?page=ManageCostTracker',
				'slug'       => 'ManageCostTracker',
				'right'      => 'manage_cost_tracker',
			),
			array(
				'title'      => esc_html__( 'Add New', 'mainwp' ),
				'parent_key' => 'ManageCostTracker',
				'href'       => 'admin.php?page=CostTrackerAdd',
				'slug'       => 'CostTrackerAdd',
				'right'      => '',
			),
			array(
				'title'      => esc_html__( 'Settings', 'mainwp' ),
				'parent_key' => 'ManageCostTracker',
				'href'       => 'admin.php?page=CostTrackerSettings',
				'slug'       => 'CostTrackerSettings',
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
	public static function render_header( $shownPage = '' ) {
		$params = array(
			'title'      => esc_html__( 'Cost Tracker', 'mainwp' ),
			'which'      => 'page_cost_tracker_overview',
			'wrap_class' => 'mainwp-module-cost-tracker-content-wrapper',
		);
		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		if ( mainwp_current_user_have_right( 'dashboard', 'manage_cost_tracker' ) ) {
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

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'CostTrackerSettings' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Settings', 'mainwp' ),
				'href'   => 'admin.php?page=CostTrackerSettings',
				'active' => ( 'settings' === $shownPage ) ? true : false,
			);
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageCostTracker' . $subPage['slug'] ) ) {
					continue;
				}

				$item           = array();
				$item['title']  = $subPage['title'];
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
	public static function handle_edit_cost_tracker_post() {

		if ( ! isset( $_POST['mwp_cost_tracker_editing_submit'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'module_cost_tracker_edit_nonce' ) ) {
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
		$update['price']          = floatval( $_POST['mainwp_module_cost_tracker_edit_price'] );
		$update['payment_method'] = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_payment_method'] ) );

		$renewal_fequency       = sanitize_text_field( wp_unslash( $_POST['mainwp_module_cost_tracker_edit_renewal_type'] ) );
		$update['renewal_type'] = $renewal_fequency;
		$update['last_renewal'] = $last_renewal; // labeled Purchase date.

		$next_renewal           = self::get_next_renewal( $last_renewal, $renewal_fequency );
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

		if ( ! empty( $_POST['mainwp_module_cost_tracker_edit_id'] ) ) {
			$update['id'] = intval( $_POST['mainwp_module_cost_tracker_edit_id'] );
		}
		//phpcs:enable
		$error = false;
		try {
			$output = Cost_Tracker_DB::get_instance()->update_cost_tracker( $update );
		} catch ( \Exception $ex ) {
			$error = true;
		}

		if ( ! $error && ! empty( $output ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=CostTrackerAdd&message=1&id=' . $output->id ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=ManageCostTracker' ) );
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

		$cust_product_types = isset( $_POST['cost_tracker_custom_product_types'] ) ? wp_unslash( $_POST['cost_tracker_custom_product_types'] ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cust_product_types = self::validate_custom_settings_text_fields( $cust_product_types );

		$cust_payment_methods = isset( $_POST['cost_tracker_custom_payment_methods'] ) ? wp_unslash( $_POST['cost_tracker_custom_payment_methods'] ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cust_payment_methods = self::validate_custom_settings_text_fields( $cust_payment_methods );

		$all_opts['currency']               = $currency;
		$all_opts['currency_format']        = $currency_format;
		$all_opts['custom_product_types']   = wp_json_encode( $cust_product_types );
		$all_opts['custom_payment_methods'] = wp_json_encode( $cust_payment_methods );

		$all_opts = apply_filters( 'mainwp_module_cost_tracker_before_save_settings', $all_opts );

		Cost_Tracker_Utility::get_instance()->save_options( $all_opts );

		wp_safe_redirect( admin_url( 'admin.php?page=CostTrackerSettings&message=1' ) );
		exit();
	}


	/**
	 * Method array_validate_text_fields().
	 *
	 * @param array $arr Data to valid.
	 *
	 * @return array Validated array fields data.
	 */
	public static function validate_custom_settings_text_fields( $arr ) {
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

			$valid_arr[ strtolower( $slug ) ] = sanitize_text_field( $title );
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
	public static function hook_get_next_renewal( $filter_input, $previous_renewal, $renewal_type ) {
		unset( $filter_input );
		return self::get_next_renewal( $previous_renewal, $renewal_type );
	}

	/**
	 * Method get_next_renewal().
	 *
	 * Get the next renewal.
	 *
	 * @param int    $previous_renewal last renewal.
	 * @param string $renewal_type renewal time.
	 */
	public static function get_next_renewal( $previous_renewal, $renewal_type ) {
		$next_renewal = 0;
		if ( ! empty( $previous_renewal ) && ! empty( $renewal_type ) && 'disabled' !== $renewal_type ) {

			if ( 'monthly' === $renewal_type ) {
				$next_renewal = strtotime( '+1 month', $previous_renewal );
			} elseif ( 'yearly' === $renewal_type ) {
				$next_renewal = strtotime( '+365 day', $previous_renewal );
			} elseif ( 'weekly' === $renewal_type ) {
				$next_renewal = strtotime( '+7 day', $previous_renewal );
			} elseif ( 'quarterly' === $renewal_type ) {
				$next_renewal = strtotime( '+3 month', $previous_renewal );
			}
			$today_time = strtotime( gmdate( 'Y-m-d 00:00:00' ) );
			if ( $next_renewal < $today_time ) {
				$next_renewal = self::get_next_renewal( $next_renewal, $renewal_type );
			}
		}
		return $next_renewal;
	}

	/**
	 * Method get_product_types().
	 */
	public static function get_product_types() {
		$product_types      = array(
			'plugin'  => esc_html__( 'Plugin', 'mainwp' ),
			'theme'   => esc_html__( 'Theme', 'mainwp' ),
			'hosting' => esc_html__( 'Hosting', 'mainwp' ),
			'service' => esc_html__( 'Service', 'mainwp' ),
			'other'   => esc_html__( 'Other', 'mainwp' ),
		);
		$cust_product_types = Cost_Tracker_Utility::get_instance()->get_option( 'custom_product_types', array() );
		if ( ! empty( $cust_product_types ) ) {
			$product_types = array_merge( $product_types, $cust_product_types );
		}
		return $product_types;
	}

	/**
	 * Method get_payment_methods().
	 */
	public static function get_payment_methods() {
		$payment_methods      = array(
			'paypal'       => esc_html__( 'Paypal', 'mainwp' ),
			'credit_debit' => esc_html__( 'Credit/Debit Card', 'mainwp' ),
		);
		$cust_payment_methods = Cost_Tracker_Utility::get_instance()->get_option( 'custom_payment_methods', array() );
		if ( ! empty( $cust_payment_methods ) ) {
			$payment_methods = array_merge( $payment_methods, $cust_payment_methods );
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
	public function handle_sites_screen_settings() {
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
	 * Hooks the section help content to the Help Sidebar element.
	 */
	public static function mainwp_help_content() {
		$allow_pages = array( 'ManageCostTracker', 'CostTrackerAdd', 'CostTrackerSettings' );
		if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allow_pages, true ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<p><?php esc_html_e( 'If you need help with the Cost Tracker extension, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="" target="_blank"></a></div>
				<div class="item"><a href="" target="_blank"></a></div>
			<?php
			/**
			 * Action: mainwp_module_cost_tracker_help_item
			 *
			 * Fires at the bottom of the help articles list in the Help sidebar on the Themes page.
			 *
			 * Suggested HTML markup:
			 *
			 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_module_cost_tracker_help_item' );
			?>
			</div>
			<?php
		}
	}

	/**
	 * Method generate_next_renewal()
	 *
	 * Generate next renewal info.
	 *
	 * @param object $subscription subscription.
	 *
	 * @return void
	 */
	public static function generate_next_renewal( $subscription ) {
		if ( empty( $subscription ) || ! is_object( $subscription ) ) {
			echo 'N/A';
			return;
		}

		if ( 'lifetime' === $subscription->type ) {
			esc_html_e( '', 'mainwp' );
			return;
		}

		$next_renewal = (int) $subscription->next_renewal;
		if ( empty( $next_renewal ) ) {
			echo 'N/A';
			return;
		}
		if ( 'active' !== $subscription->cost_status ) {
			echo 'N/A';
			return;
		}

		$current_time = time();
		$renewal_html = MainWP_Utility::format_date( MainWP_Utility::get_timestamp( $next_renewal ) );
		$day1         = $next_renewal - 15 * DAY_IN_SECONDS;
		$day2         = $next_renewal - 7 * DAY_IN_SECONDS;
		if ( $day1 > $current_time ) {
			$renewal_html = esc_html( $renewal_html );
		} elseif ( $day1 <= $current_time && $current_time < $day2 ) {
			$renewal_html = '<span data-tooltip="Renewal approaching soon. Please review your subscription details." data-inverted="" data-position="left center"><i class="yellow bell icon"></i></span>' . esc_html( $renewal_html );
		} elseif ( $day2 <= $current_time && $current_time < $next_renewal ) {
			$renewal_html = '<span data-tooltip="Renewal approaching soon. Please review your subscription details." data-inverted="" data-position="left center"><i class="yellow bell icon"></i></span>' . esc_html( $renewal_html );
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
				'label' => esc_html__( 'Activate', 'mainwp' ),
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
}
