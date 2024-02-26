<?php
/**
 * Centralized manager for WordPress backend functionality.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Post_Handler;


defined( 'ABSPATH' ) || exit;

use DateTime;
use DateTimeZone;
use DateInterval;
use WP_CLI;

/**
 * Class - Log_Admin
 */
class Log_Admin {

	/**
	 * Holds Instance of manager object
	 *
	 * @var Log_manager
	 */
	public $manager;


	/**
	 * Menu page screen id
	 *
	 * @var string
	 */
	public $screen_id = array();

	/**
	 * List table object
	 *
	 * @var List_Table
	 */
	public $list_table = null;

	/**
	 * Parent page of the records and settings pages
	 *
	 * @var string
	 */
	public $admin_parent_page = 'admin.php';

	/**
	 * Class constructor.
	 *
	 * @param Log_Manager $manager Instance of manager object.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;
		add_filter( 'mainwp_getsubpages_settings', array( $this, 'add_subpage_menu_logs' ) );
		// Load admin scripts and styles.
		add_action(
			'admin_enqueue_scripts',
			array(
				$this,
				'admin_enqueue_scripts',
			)
		);

		// Auto purge setup.
		add_action( 'wp_loaded', array( $this, 'hook_purge_scheduled_action' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
	}

	/**
	 * Handle admin_init action.
	 */
	public function admin_init() {
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_display_rows', array( $this, 'ajax_display_rows' ) );
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_delete_records', array( $this, 'ajax_delete_records' ) );
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_compact_records', array( $this, 'ajax_compact_records' ) );
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_events_display_rows', array( Log_Insights_Page::instance(), 'ajax_events_display_rows' ) );
	}


	/**
	 * Init sub menu logs.
	 *
	 * @param array $subpages sub pages.
	 *
	 * @action init
	 */
	public function add_subpage_menu_logs( $subpages = array() ) {
		$subpages[] = array(
			'title'    => esc_html__( 'Dashboard Insights', 'mainwp' ),
			'slug'     => 'DashboardInsights',
			'callback' => array( $this, 'render_list_table' ),
			'class'    => 'mainwp-logs-menu',
		);
		return $subpages;
	}

	/**
	 * Render main page
	 */
	public function render_list_table() {
		$this->list_table = new Log_List_Table( $this->manager );
		/** This action is documented in ../pages/page-mainwp-manage-sites.php */
		do_action( 'mainwp_pageheader_settings', 'DashboardInsights' );
		?>
		<div id="mainwp-manage-sites-content" class="ui segment">
			<div class="ui form">
				<h3 class="ui dividing header"><?php esc_html_e( 'Dashboard Insights', 'mainwp' ); ?></h3>
				<h4 class="ui header"><?php printf( esc_html__( 'Total logs size: %1$s (MB)', 'mainwp' ), esc_html( $this->get_db_size() ) ); ?></h4>
				<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
				<form method="post" class="mainwp-table-container">
					<?php
					wp_nonce_field( 'mainwp-admin-nonce' );
					$this->list_table->display();
					?>
				</form>
			</div>
		</div>		
		<?php
		/** This action is documented in ../pages/page-mainwp-manage-sites.php */
		do_action( 'mainwp_pagefooter_settings', 'DashboardInsights' );
	}

	/**
	 * Enqueue scripts/styles for admin screen
	 *
	 * @action admin_enqueue_scripts
	 *
	 * @param string $hook  Current hook.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts( $hook ) {
		$script_screens = array( 'mainwp_page_InsightsOverview', 'mainwp_page_SettingsDashboardInsights', 'mainwp_page_SettingsInsights' );
		wp_enqueue_style( 'mainwp-module-log-admin', $this->manager->locations['url'] . 'ui/css/admin.css', array(), $this->manager->get_version() );

		if ( in_array( $hook, $script_screens, true ) ) {
			wp_enqueue_script(
				'mainwp-module-log-admin',
				$this->manager->locations['url'] . 'ui/js/admin.js',
				array(
					'jquery',
					'mainwp',
				),
				$this->manager->get_version(),
				false
			);

			if ( in_array( $hook, array( 'mainwp_page_InsightsOverview' ), true ) ) {
				wp_enqueue_script(
					'mainwp-module-log-apexcharts',
					$this->manager->locations['url'] . 'ui/js/apexcharts/apexcharts.js',
					array(
						'jquery',
						'mainwp',
					),
					$this->manager->get_version(),
					true
				);
			}

			wp_localize_script(
				'mainwp-module-log-admin',
				'mainwpModuleLog',
				array(
					'i18n'       => array(),
					'gmt_offset' => get_option( 'gmt_offset' ),
				)
			);
		}
	}


	/**
	 * Method ajax_display_rows()
	 *
	 * Display table rows, optimize for shared hosting or big networks.
	 */
	public function ajax_display_rows() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_display_rows' );
		$this->load_list_table();
		$this->list_table->prepare_items();
		$output = $this->list_table->ajax_get_datatable_rows();
		MainWP_Logger::instance()->log_execution_time( 'ajax_display_rows()' );
		wp_send_json( $output );
	}

	/**
	 * Handle ajax delete logs records.
	 */
	public function ajax_delete_records() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_delete_records' );

		$start_date = isset( $_POST['startdate'] ) ? sanitize_text_field( wp_unslash( $_POST['startdate'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$end_date   = isset( $_POST['enddate'] ) ? sanitize_text_field( wp_unslash( $_POST['enddate'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$start_time = ! empty( $start_date ) ? strtotime( $start_date . ' 00:00:00' ) : '';
		$end_time   = ! empty( $end_date ) ? strtotime( $end_date . ' 23:59:59' ) : '';

		if ( ! is_numeric( $start_time ) || ! is_numeric( $end_time ) || $start_time > $end_time ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Invalid Start date or end date. Please try again.' ) ) ) );
		}

		$this->manager->db->create_compact_and_erase_records( $start_time, $end_time );

		wp_send_json( array( 'result' => 'SUCCESS' ) );
	}


	/**
	 * Handle ajax compact logs records.
	 */
	public function ajax_compact_records() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_compact_records' );

		$year = isset( $_POST['year'] ) ? intval( $_POST['year'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( $year < 2022 ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Invalid selected year. Please try again.' ) ) ) );
		}

		$aday = $year . '-12-15'; // a day in last month.

		$start_time = strtotime( $year . '-1-1 00:00:00' );
		$end_time   = strtotime( gmdate( 'Y-m-t', strtotime( $aday ) ) . ' 23:59:59' );

		$this->manager->db->create_compact_and_erase_records( $start_time, $end_time );

		wp_send_json( array( 'result' => 'SUCCESS' ) );
	}

	/**
	 * Method load_sites_table()
	 *
	 * Load sites table.
	 */
	public function load_list_table() {
		$this->list_table = new Log_List_Table( $this->manager );
	}


	/**
	 * Schedules a purge of records.
	 *
	 * @return void
	 */
	public function hook_purge_scheduled_action() {
		$enable_schedule = is_array( $this->manager->settings->options ) && ! empty( $this->manager->settings->options['enabled'] ) && ! empty( $this->manager->settings->options['auto_purge'] ) ? true : false;
		if ( $enable_schedule ) {
			$last_purge = get_option( 'mainwp_module_log_last_time_auto_purge_logs' );
			$next_purge = get_option( 'mainwp_module_log_next_time_auto_purge_logs' );
			$days       = false;
			if ( is_array( $this->manager->settings->options ) && isset( $this->manager->settings->options['records_ttl'] ) ) {
				$days = intval( $this->manager->settings->options['records_ttl'] );
			} else {
				$days = 100;
			}

			if ( defined( 'MAINWP_MODULE_LOG_KEEP_RECORDS_TTL' ) && is_numeric( MAINWP_MODULE_LOG_KEEP_RECORDS_TTL ) && MAINWP_MODULE_LOG_KEEP_RECORDS_TTL > 0 ) {
				$days = MAINWP_MODULE_LOG_KEEP_RECORDS_TTL;
			}

			if ( $days ) {
				$time            = time();
				$next_time_purge = false;
				if ( false === $last_purge && false === $next_purge ) {
					$next_purge = $time + $days * DAY_IN_SECONDS;
				} elseif ( ! empty( $next_purge ) && $time > (int) $next_purge ) {
					do_action( 'mainwp_log_action', 'module log :: purge logs schedule start.', MainWP_Logger::LOGS_AUTO_PURGE_LOG_PRIORITY );

					$end_time   = $time - $days * DAY_IN_SECONDS;
					$start_time = ! empty( $last_purge ) ? $last_purge : $end_time - $days * DAY_IN_SECONDS;

					$this->manager->db->create_compact_and_erase_records( false, $end_time );
					update_option( 'mainwp_module_log_last_time_auto_purge_logs', $time );
					$next_time_purge = $time + $days * DAY_IN_SECONDS;
				}
				if ( $next_time_purge ) {
					update_option( 'mainwp_module_log_next_time_auto_purge_logs', $next_time_purge );
				}
			}
		}
	}



	/**
	 * Get db size.
	 *
	 * @return string Return current db size.
	 */
	public function get_db_size() {
		$size = get_transient( 'mainwp_module_log_transient_db_logs_size' );
		if ( false !== $size ) {
			return $size;
		}

		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT
		ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2)
		FROM INFORMATION_SCHEMA.TABLES
		WHERE
		TABLE_SCHEMA = %s
		AND table_name = %s 
		OR table_name = %s',
			$wpdb->dbname,
			$wpdb->mainwp_tbl_logs,
			$wpdb->mainwp_tbl_logs_meta
		);

		$dbsize_mb = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared SQL.

		set_transient( 'mainwp_module_log_transient_db_logs_size', $dbsize_mb, 15 * MINUTE_IN_SECONDS );

		return $dbsize_mb;
	}

	/**
	 * Get WP users.
	 *
	 * @return array Array of users.
	 */
	public function get_all_users() {
		$list_users = array();
		$all_users  = get_users();
		if ( is_array( $all_users ) ) {
			foreach ( $all_users as $user ) {
				if ( empty( $user->ID ) ) {
					continue;
				}
				$fields             = array();
				$fields['id']       = $user->ID;
				$fields['login']    = $user->user_login;
				$fields['nicename'] = $user->user_nicename;
				$list_users[]       = $fields;
			}
		}
		return $list_users;
	}
}
