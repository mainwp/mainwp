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
use \WP_CLI;

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
		add_action( 'wp_loaded', array( $this, 'purge_schedule_setup' ) );
		add_action(
			'mainwp_module_log_auto_purge',
			array(
				$this,
				'purge_scheduled_action',
			)
		);

		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_display_rows', array( $this, 'ajax_display_rows' ) );
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_delete_records', array( $this, 'ajax_delete_records' ) );
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_compact_records', array( $this, 'ajax_compact_records' ) );
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
		do_action( 'mainwp-pageheader-settings', 'DashboardInsights' );
		?>
		<div id="mainwp-manage-sites-content" class="ui segment">
			<div class="ui form">
				<h3 class="ui dividing header"><?php esc_html_e( 'Dashboard Insights', 'mainwp' ); ?></h3>
				<h4 class="ui header"><?php echo sprintf( esc_html__( 'Total logs size: %1$s (MB)', 'mainwp' ), $this->get_db_size() ); ?></h4>
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
		do_action( 'mainwp-pagefooter-settings', 'DashboardInsights' );
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
		wp_enqueue_style( 'mainwp-module-log-admin', $this->manager->locations['url'] . 'ui/css/admin.css', array(), $this->manager->get_version() );
		$script_screens = array( 'plugins.php' );

		if ( true || in_array( $hook, $script_screens, true ) ) {
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_List_Table
	 */
	public function ajax_display_rows() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_display_rows' );
		$this->load_list_table();
		$this->list_table->prepare_items( true );
		$output = $this->list_table->ajax_get_datatable_rows();
		MainWP_Logger::instance()->log_execution_time( 'ajax_display_rows()' );
		wp_send_json( $output );
	}

	/**
	 * Handle ajax delete logs records.
	 */
	public function ajax_delete_records() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_delete_records' );

		$start_date = isset( $_POST['startdate'] ) ? sanitize_text_field( wp_unslash( $_POST['startdate'] ) ) : '';
		$end_date   = isset( $_POST['enddate'] ) ? sanitize_text_field( wp_unslash( $_POST['enddate'] ) ) : '';

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

		$year = isset( $_POST['year'] ) ? intval( $_POST['year'] ) : 0;

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
	public function purge_schedule_setup() {
		$enable_schedule = is_array( $this->manager->settings->options ) && ! empty( $this->manager->settings->options['enabled'] ) && ! empty( $this->manager->settings->options['auto_purge'] ) ? true : false;
		if ( $enable_schedule ) {
			if ( ! wp_next_scheduled( 'mainwp_module_log_auto_purge' ) ) {
				wp_schedule_event( time(), 'twicedaily', 'mainwp_module_log_auto_purge' );
			}
		} else {
			$sched = wp_next_scheduled( 'mainwp_module_log_auto_purge' );
			if ( $sched ) {
				wp_unschedule_event( $sched, 'mainwp_module_log_auto_purge' );
			}
		}
	}

	/**
	 * Executes a scheduled purge
	 *
	 * @return void
	 */
	public function purge_scheduled_action() {

		do_action( 'mainwp_log_action', 'CRON :: module log :: purge logs schedule start.', MainWP_Logger::LOGS_AUTO_PURGE_LOG_PRIORITY );

		if ( empty( $this->manager->settings->options['auto_purge'] ) ) {
			return;
		}
		$days = isset( $this->manager->settings->options['records_ttl'] ) ? intval( $this->manager->settings->options['records_ttl'] ) : 100;

		if ( defined( 'MAINWP_MODULE_LOG_KEEP_RECORDS_TTL' ) && is_numeric( MAINWP_MODULE_LOG_KEEP_RECORDS_TTL ) && MAINWP_MODULE_LOG_KEEP_RECORDS_TTL > 0 ) {
			$days = MAINWP_MODULE_LOG_KEEP_RECORDS_TTL;
		}

		global $wpdb;

		$end_time   = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' );
		$start_time = $end_time - $days * DAY_IN_SECONDS;

		$this->manager->db->create_compact_and_erase_records( $start_time, $end_time );
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

		$dbsize_mb = $wpdb->get_var( $sql ); // phpcs:ignore unprepared SQL.

		set_transient( 'mainwp_module_log_transient_db_logs_size', $dbsize_mb, 15 * MINUTE_IN_SECONDS );

		return $dbsize_mb;
	}

}
