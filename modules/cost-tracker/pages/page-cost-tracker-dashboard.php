<?php
/**
 * MainWP Module Cost Tracker Dashboard class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB_Client;
use function MainWP\Dashboard\mainwp_current_user_have_right;
use function MainWP\Dashboard\mainwp_do_not_have_permissions;

/**
 * Class Cost_Tracker_Dashboard
 */
class Cost_Tracker_Dashboard {

	/**
	 * Variable to hold the items.
	 *
	 * @var mixed Default empty.
	 */
	private $items = null;

	/**
	 * Variable to hold the total items.
	 *
	 * @var mixed Default empty.
	 */
	private $total_items = 0;

	/**
	 * Variable to hold the order.
	 *
	 * @var mixed Default empty.
	 */
	private static $order = '';

	/**
	 * Variable to hold the order by.
	 *
	 * @var mixed Default empty.
	 */
	private static $orderby = '';

	/**
	 * Static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Get Instance
	 *
	 * Creates public static instance.
	 *
	 * @static
	 *
	 * @return Cost_Tracker_Dashboard
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
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
	}

	/**
	 * Method admin_init()
	 *
	 * Admin init.
	 */
	public function admin_init() {
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_notes_save', array( $this, 'ajax_notes_save' ) );
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_delete', array( $this, 'ajax_cost_tracker_delete' ) );
		MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_lists_display_rows', array( $this, 'ajax_display_rows' ) );
	}


	/**
	 * Get columns.
	 *
	 * @param bool $filtered With per site price column.
	 *
	 * @return array Array of column names.
	 */
	public static function get_columns( $filtered = false ) {
		$cols = array(
			'cb'             => true,
			'name'           => esc_html__( 'Name', 'mainwp' ),
			'type'           => esc_html__( 'Type', 'mainwp' ),
			'product_type'   => esc_html__( 'Product Type', 'mainwp' ),
			'license_type'   => esc_html__( 'License Type', 'mainwp' ),
			'per-site-price' => esc_html__( 'Price', 'mainwp' ),
			'price'          => esc_html__( 'Price', 'mainwp' ),
			'cost_status'    => esc_html__( 'Status', 'mainwp' ),
			'last_renewal'   => esc_html__( 'Last Renewal', 'mainwp' ),
			'next_renewal'   => esc_html__( 'Next Renewal', 'mainwp' ),
			'payment_method' => esc_html__( 'Payment method', 'mainwp' ),
			'note'           => esc_html__( 'Note', 'mainwp' ),
			'sites'          => esc_html__( 'Sites', 'mainwp' ),
			'actions'        => esc_html__( 'Action', 'mainwp' ),
		);

		if ( ! $filtered ) {
			unset( $cols['per-site-price'] );
		}
		return $cols;
	}


	/**
	 * Get column defines.
	 *
	 * @return array $defines
	 */
	public function get_columns_defines() {
		$defines   = array();
		$defines[] = array(
			'targets'   => 'no-sort',
			'orderable' => false,
		);
		$defines[] = array(
			'targets'   => 'title-column',
			'className' => 'task-row-working',
		);
		$defines[] = array(
			'targets'   => 'date-column',
			'className' => 'mainwp-date-cell',
		);
		$defines[] = array(
			'targets'   => 'state-column',
			'className' => 'mainwp-state-cell',
		);
		$defines[] = array(
			'targets'   => array( 'actions-column' ),
			'className' => 'collapsing',
		);
		$defines[] = array(
			'targets'   => array( 'column-site-price', 'column-price' ),
			'className' => 'right aligned',
		);
		return $defines;
	}

	/**
	 * Instantiate Columns.
	 *
	 * @param bool $filtered With per site price column.
	 *
	 * @return array $init_cols
	 */
	public function get_columns_init( $filtered ) {
		$cols      = $this->get_columns( $filtered );
		$init_cols = array();
		foreach ( $cols as $key => $val ) {
			$init_cols[] = array( 'data' => esc_html( $key ) );
		}
		return $init_cols;
	}

	/**
	 * Renders overview.
	 *
	 * When the page loads render the body content.
	 */
	public function render_overview_page() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_cost_tracker' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage cost tracker', 'mainwp' ) );
			return;
		}

		$sel_ids = isset( $_GET['selected_ids'] ) ? $_GET['selected_ids'] : ''; //phpcs:ignore -- ok.
		$sel_ids = explode( ',', $sel_ids );

		if ( ! empty( $sel_ids ) && is_array( $sel_ids ) ) {
			global $current_user;
			$sel_ids = MainWP_Utility::array_numeric_filter( $sel_ids );
			if ( ! empty( $sel_ids ) ) {
				update_user_option( $current_user->ID, 'mainwp_module_cost_tracker_onetime_filters_saved', $sel_ids );
				delete_user_option( $current_user->ID, 'mainwp_module_cost_tracker_filters_saved' );
			}
		}
		Cost_Tracker_Admin::render_header();
		?>
		<div id="mainwp-module-cost-tracker-dashboard-tab">
			<?php self::render_manage_tasks_table_top(); ?>
			<?php $this->render_actions_bar(); ?>
				<div class="ui segment">					
				<?php $this->render_dashboard_body(); ?>
				</div>
			</div>
		<?php
	}

	/**
	 * Method ajax_display_rows()
	 *
	 * Handle display table rows.
	 */
	public function ajax_display_rows() {

		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_lists_display_rows' );
		$client_id = isset( $_POST['client_id'] ) ? intval( $_POST['client_id'] ) : 0;
		$site_id   = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : 0;

		$filtered_onetime_ids = get_user_option( 'mainwp_module_cost_tracker_onetime_filters_saved' );

		if ( ! empty( $filtered_onetime_ids ) && is_array( $filtered_onetime_ids ) ) {
			global $current_user;
			delete_user_option( $current_user->ID, 'mainwp_module_cost_tracker_onetime_filters_saved' );
			$filtered_onetime_ids = MainWP_Utility::array_numeric_filter( $filtered_onetime_ids );
		}

		$req_orderby = '';
		$req_order   = null;

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_REQUEST['order'] ) ) {
			$columns = isset( $_REQUEST['columns'] ) ? wp_unslash( $_REQUEST['columns'] ) : array();
			$ord_col = isset( $_REQUEST['order'][0]['column'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'][0]['column'] ) ) : '';
			if ( isset( $columns[ $ord_col ] ) ) {
				$req_orderby = isset( $columns[ $ord_col ]['data'] ) ? sanitize_text_field( wp_unslash( $columns[ $ord_col ]['data'] ) ) : '';
				$req_order   = isset( $_REQUEST['order'][0]['dir'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'][0]['dir'] ) ) : '';
			}
		}

		$search = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';

		$filters = array( 'sites', 'client', 'prods_types', 'costs_state', 'dtsstart', 'dtsstop' );

		$get_saved = true;
		foreach ( $filters as $filter ) {
			if ( isset( $_REQUEST[ $filter ] ) ) {
				$get_saved = false;
				break;
			}
		}

		$filter_sites_ids       = '';
		$filter_client_ids      = '';
		$filter_prod_type_slugs = '';
		$filter_cost_state      = '';
		$filter_dtsstart        = '';
		$filter_dtsstop         = '';

		if ( $get_saved ) {
			$filters_saved = get_user_option( 'mainwp_module_cost_tracker_filters_saved' );
			if ( ! is_array( $filters_saved ) ) {
				$filters_saved = array();
			}
			$filter_cost_state      = isset( $filters_saved['costs_state'] ) ? $filters_saved['costs_state'] : '';
			$filter_dtsstart        = isset( $filters_saved['dtsstart'] ) ? $filters_saved['dtsstart'] : '';
			$filter_dtsstop         = isset( $filters_saved['dtsstop'] ) ? $filters_saved['dtsstop'] : '';
			$filter_client_ids      = isset( $filters_saved['client_ids'] ) ? $filters_saved['client_ids'] : false;
			$filter_client_ids      = isset( $filters_saved['sites_ids'] ) ? $filters_saved['sites_ids'] : false;
			$filter_prod_type_slugs = isset( $filters_saved['prods_types'] ) ? $filters_saved['prods_types'] : false;
		} else {
			// phpcs:disable WordPress.Security.NonceVerification
			$filter_sites_ids       = isset( $_REQUEST['sites'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sites'] ) ) : '';
			$filter_client_ids      = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
			$filter_prod_type_slugs = isset( $_REQUEST['prods_types'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['prods_types'] ) ) : '';
			$filter_cost_state      = isset( $_REQUEST['costs_state'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['costs_state'] ) ) : '';
			$filter_dtsstart        = isset( $_REQUEST['dtsstart'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstart'] ) ) : '';
			$filter_dtsstop         = isset( $_REQUEST['dtsstop'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstop'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification
		}

		$array_sites_ids        = array();
		$array_clients_ids      = array();
		$array_prod_types_slugs = array();
		$array_status_slugs     = array();

		if ( ! empty( $filter_sites_ids ) ) {
			$array_sites_ids = explode( ',', $filter_sites_ids ); // convert to array.
			if ( in_array( 'nosites', $array_sites_ids, true ) ) {
				$array_sites_ids  = false;
				$filter_sites_ids = '';
			}
		}

		if ( ! empty( $filter_client_ids ) ) {
			$array_clients_ids = explode( ',', $filter_client_ids ); // convert to array.
			if ( in_array( 'noclients', $array_clients_ids, true ) ) {
				$array_clients_ids = false;
				$filter_client_ids = '';
			}
		}

		if ( ! empty( $filter_prod_type_slugs ) ) {
			$array_prod_types_slugs = explode( ',', $filter_prod_type_slugs ); // convert to array.
			if ( in_array( 'nocategories', $array_prod_types_slugs, true ) ) {
				$array_prod_types_slugs = false;
				$filter_prod_type_slugs = '';
			}
		}

		if ( ! empty( $filter_cost_state ) ) {
			$array_status_slugs = explode( ',', $filter_cost_state ); // convert to array.
			if ( in_array( 'nostatus', $array_status_slugs, true ) ) {
				$array_status_slugs = false;
				$filter_cost_state  = '';
			}
		}
		global $current_user;
		update_user_option(
			$current_user->ID,
			'mainwp_module_cost_tracker_filters_saved',
			array(
				'sites_ids'   => $filter_sites_ids,
				'client_ids'  => $filter_client_ids,
				'prods_types' => $filter_prod_type_slugs,
				'costs_state' => $filter_cost_state,
				'dtsstart'    => $filter_dtsstart,
				'dtsstop'     => $filter_dtsstop,
			)
		);

		// phpcs:enable

		 // phpcs:disable WordPress.Security.NonceVerification
		$per_page = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : 25;

		if ( -1 === $per_page ) {
			$per_page = 9999;
		}

		$start  = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;
		$search = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';

		// phpcs:enable WordPress.Security.NonceVerification

		$convert_orb_fields = array(
			'cats_id'   => 'cats_title',
			'site_ids'  => 'count_sites',
			'client_id' => 'client_name',
		);

		$req_orderby = ! empty( $req_orderby ) && isset( $convert_orb_fields[ $req_orderby ] ) ? $convert_orb_fields[ $req_orderby ] : $req_orderby;

		$args = array(
			'order'              => ( 'asc' === $req_order ) ? 'asc' : 'desc',
			'orderby'            => $req_orderby,
			'start'              => $start,
			'search'             => $search,
			'dtsstart'           => ! empty( $filter_dtsstart ) ? $filter_dtsstart : false,
			'dtsstop'            => ! empty( $filter_dtsstop ) ? $filter_dtsstop : false,
			'filter_clients'     => $array_clients_ids,
			'filter_prods_types' => $array_prod_types_slugs,
			'filter_states'      => $array_status_slugs,
		);

		$args['records_per_page'] = $per_page ? $per_page : 20;

		if ( ! empty( $client_id ) ) {
			$args['client_id'] = $client_id;
		} elseif ( ! empty( $site_id ) ) {
			$args['site_id'] = $site_id;
		}

		$args['count_sites'] = true;

		if ( ! empty( $filtered_onetime_ids ) ) {
			$items             = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'all', null, array( 'selected_ids' => $filtered_onetime_ids ) );
			$this->items       = ! empty( $items ) ? $items : array();
			$this->total_items = ! empty( $items ) ? count( $items ) : 0;
		} else {
			$results           = Cost_Tracker_DB_Query::instance()->query_costs( $args );
			$this->items       = $results['items'];
			$this->total_items = $results['count'];
		}
		$output = $this->ajax_get_datatable_rows();
		wp_send_json( $output );
	}


	/**
	 * Render the Dashbaord tab
	 *
	 * Renders the dashbaord tab content - Subscription table
	 */
	public function render_dashboard_body() {
		$_orderby = 'name';
		$_order   = 'desc';

		self::$order   = $_order;
		self::$orderby = $_orderby;
		$output        = array();

		$filtered = false;
		if ( isset( $_GET['selected_ids'] ) && ! empty( $_GET['selected_ids'] ) ) {
			$filtered = true;
		}
		?>
		<table class="ui single line table" id="mainwp-module-cost-tracker-sites-table" style="width:100%">
			<thead>
				<tr>
					<th class="no-sort collapsing check-column column-check"><span class="ui checkbox"><input type="checkbox"></span></th>
					<th id="name" class="column-name"><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
					<th id="type" class="column-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
					<th id="product_type" class="collapsing column-product-type"><?php esc_html_e( 'Cetegory', 'mainwp' ); ?></th>
					<th id="license_type" class="collapsing column-license-type"><?php esc_html_e( 'License Type', 'mainwp' ); ?></th>
					<?php if ( $filtered ) : ?>
						<th id="per-site-price" class="no-sort collapsing column-site-price"><?php esc_html_e( 'Per Site Price', 'mainwp' ); ?></th>
						<th id="price" class="collapsing column-total-price"><?php esc_html_e( 'Total Price', 'mainwp' ); ?></th>
					<?php else : ?>
						<th id="price" class="collapsing column-price"><?php esc_html_e( 'Price', 'mainwp' ); ?></th>
					<?php endif; ?>
					<th id="cost_status" class="collapsing column-status"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th id="last_renewal" class="collapsing column-last-renewal"><?php esc_html_e( 'Purchased at', 'mainwp' ); ?></th>
					<th id="next_renewal" class="collapsing column-next-renewal"><?php esc_html_e( 'Renews at', 'mainwp' ); ?></th>
					<th id="payment_method" class="collapsing column-payment-method"><?php esc_html_e( 'Payment method', 'mainwp' ); ?></th>
					<th id="note" class="no-sort collapsing center aligned column-note"><i class="sticky note outline icon"></i></th>
					<th id="sites" class="no-sort collapsing center aligned column-sites"><?php esc_html_e( 'Sites', 'mainwp' ); ?></th>
					<th id="actions" class="no-sort collapsing right aligned column-actions"></th>
				</tr>
			</thead>
			<tfoot>
				<tr>
					<th class="no-sort collapsing check-column column-check"><span class="ui checkbox"><input type="checkbox"></span></th>
					<th id="name" class="column-name" ><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
					<th id="type" class="column-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
					<th id="product_type" class="collapsing column-product-type"><?php esc_html_e( 'Cetegory', 'mainwp' ); ?></th>
					<th id="license_type" class="collapsing column-license-type"><?php esc_html_e( 'License Type', 'mainwp' ); ?></th>
					<?php if ( $filtered ) : ?>
						<th id="per-site-price" class="no-sort collapsing column-site-price"><?php esc_html_e( 'Per Site Price', 'mainwp' ); ?></th>
						<th id="price" class="collapsing column-price"><?php esc_html_e( 'Total Price', 'mainwp' ); ?></th>
					<?php else : ?>
						<th id="price" class="collapsing column-price"><?php esc_html_e( 'Price', 'mainwp' ); ?></th>
					<?php endif; ?>
					<th id="cost_status" class="collapsing column-status"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th id="last_renewal" class="collapsing column-last-renewal"><?php esc_html_e( 'Purchased at', 'mainwp' ); ?></th>
					<th id="next_renewal" class="collapsing column-next-renewal"><?php esc_html_e( 'Renews at', 'mainwp' ); ?></th>
					<th id="payment_method" class="collapsing column-payment-method"><?php esc_html_e( 'Payment method', 'mainwp' ); ?></th>
					<th id="note" class="no-sort collapsing column-note"><i class="sticky note outline icon"></i></th>
					<th id="sites" class="collapsing column-sites"><?php esc_html_e( 'Sites', 'mainwp' ); ?></th>
					<th id="actions" class="no-sort collapsing right aligned column-actions"></th>
				</tr>
			</tfoot>
		</table>
		<div id="mainwp-loading-sites" style="display: none;">
			<div class="ui active inverted dimmer">
				<div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading ...', 'mainwp-time-tracker-extension' ); ?></div>
			</div>
		</div>
		<?php
		$lifetime_costs = isset( $output['lifetime_costs'] ) ? (float) $output['lifetime_costs'] : 0;
		$monthly_costs  = isset( $output['monthly_costs'] ) ? (float) $output['monthly_costs'] : 0;
		$yearly_costs   = isset( $output['yearly_costs'] ) ? (float) $output['yearly_costs'] : 0;

		self::render_modal_edit_notes();
		self::render_screen_options();

		$sites_per_page = get_option( 'mainwp_default_sites_per_page', 25 );

		$sites_per_page = intval( $sites_per_page );

		$pages_length = array(
			25  => '25',
			10  => '10',
			50  => '50',
			100 => '100',
			300 => '300',
		);

		$pages_length = $pages_length + array( $sites_per_page => $sites_per_page );

		ksort( $pages_length );

		if ( isset( $pages_length[-1] ) ) {
			unset( $pages_length[-1] );
		}

		$pagelength_val   = implode( ',', array_keys( $pages_length ) );
		$pagelength_title = implode( ',', array_values( $pages_length ) );

		$table_features = array(
			'searching'     => 'true',
			'paging'        => 'true',
			'pagingType'    => 'full_numbers',
			'info'          => 'true',
			'colReorder'    => '{ fixedColumnsLeft: 1, fixedColumnsRight: 1 }',
			'stateSave'     => 'true',
			'stateDuration' => '0',
			'order'         => '[]',
			'scrollX'       => 'true',
			'responsive'    => 'true',
			'fixedColumns'  => '',
		);

		?>
		<script type="text/javascript">
			var responsive = <?php echo esc_js( $table_features['responsive'] ); ?>;
			if( jQuery( window ).width() > 1140 ) {
				responsive = false;
			}
			jQuery( document ).ready( function( $ ) {
					// to fix issue not loaded calendar js library
					if (jQuery('.ui.calendar').length > 0) {
						if (mainwpParams.use_wp_datepicker == 1) {
							jQuery('#mainwp-module-cost-tracker-list-sub-header .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
						} else {
							jQuery('#mainwp-module-cost-tracker-list-sub-header .ui.calendar').calendar({
								type: 'date',
								monthFirst: false,
								today: true,
								touchReadonly: false,
								formatter: {
									date : 'YYYY-MM-DD'
								}
							});
						}
					}
				
					try {
						$subscription_sites_table = jQuery( '#mainwp-module-cost-tracker-sites-table' ).on( 'processing.dt', function ( e, settings, processing ) {
							jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
							if (!processing) {
								var tb = jQuery( '#mainwp-module-cost-tracker-sites-table' );
								$( '#mainwp-module-cost-tracker-sites-table .ui.dropdown' ).dropdown();
								$( '#mainwp-module-cost-tracker-sites-table .ui.checkbox' ).checkbox();
							}
						} ).on( 'column-reorder.dt', function ( e, settings, details ) {
							$( '#mainwp-module-cost-tracker-sites-table .ui.dropdown' ).dropdown();
							$( '#mainwp-module-cost-tracker-sites-table .ui.checkbox' ).checkbox();
						} ).DataTable( {
							"ajax": {
								"url": ajaxurl,
								"type": "POST",
								"data":  function ( d ) {
									var data = mainwp_secure_data( {
										action: 'mainwp_module_cost_tracker_lists_display_rows',
										sites: $( '#mainwp-module-cost-tracker-costs-filter-sites').dropdown('get value'),
										prods_types: $( '#mainwp-module-cost-tracker-costs-filter-cats').dropdown('get value'),
										costs_state: $( '#mainwp-module-cost-tracker-costs-filter-status').dropdown('get value'),
										dtsstart: $('#mainwp-module-cost-tracker-costs-filter-dtsstart').val(),
										dtsstop: $('#mainwp-module-cost-tracker-costs-filter-dtsstop').val(),
										show_per_site_price: <?php echo $filtered ? 1 : 0; ?>,
									} );
									if($( '#mainwp-module-cost-tracker-costs-filter-clients').length > 0){
										data.client = $( '#mainwp-module-cost-tracker-costs-filter-clients').dropdown('get value');
									}
									return $.extend( {}, d, data );
								},
								"dataSrc": function ( json ) {
									for ( var i=0, ien=json.data.length ; i < ien ; i++ ) {
										json.data[i].rowClass = json.rowsInfo[i].rowClass;
										json.data[i].cost_id = json.rowsInfo[i].cost_id;
									}
									return json.data;
								}
							},
							"responsive": responsive,
							"searching" : <?php echo esc_js( $table_features['searching'] ); ?>,
							"paging" : <?php echo esc_js( $table_features['paging'] ); ?>,
							"pagingType" : "<?php echo esc_js( $table_features['pagingType'] ); ?>",
							"info" : <?php echo esc_js( $table_features['info'] ); ?>,
							"colReorder" : <?php echo esc_js( $table_features['colReorder'] ); ?>,
							"scrollX" : <?php echo esc_js( $table_features['scrollX'] ); ?>,
							"stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
							"stateDuration" : <?php echo esc_js( $table_features['stateDuration'] ); ?>,
							"order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
							"fixedColumns" : <?php echo ! empty( $table_features['fixedColumns'] ) ? esc_js( $table_features['fixedColumns'] ) : '""'; ?>,
							"lengthMenu" : [ [<?php echo esc_js( $pagelength_val ); ?>, -1 ], [<?php echo esc_js( $pagelength_title ); ?>, "All"] ],
							"serverSide": true,
							"pageLength": <?php echo intval( $sites_per_page ); ?>,
							"columnDefs": <?php echo wp_json_encode( $this->get_columns_defines() ); ?>,
							"columns": <?php echo wp_json_encode( $this->get_columns_init( $filtered ) ); ?>,
							"language": {
								"emptyTable": "<?php esc_html_e( 'No subscriptions found.', 'mainwp' ); ?>"
							},
							"drawCallback": function( settings ) {
								this.api().tables().body().to$().attr( 'id', 'mainwp-module-cost-tracker-body-table' );
								mainwp_datatable_fix_menu_overflow();
							},
							"initComplete": function( settings, json ) {
							},
							rowCallback: function (row, data) {
								jQuery( row ).addClass(data.rowClass);
								jQuery( row ).attr( 'id', "cost-row-" + data.cost_id );
								jQuery( row ).attr( 'item-id', data.cost_id );
								
							}
						} );
					} catch(err) {
						// to fix js error.
						console.log(err);
					}
					_init_cost_tracker_sites_screen();
			} );

			mainwp_module_cost_tracker_manage_costs_filter = function() {
				try {
					$subscription_sites_table.ajax.reload();
				} catch(err) {
					// to fix js error.
					console.log(err);
				}
			};

			_init_cost_tracker_sites_screen = function() {
				jQuery( '#mainwp-module-cost-tracker-sites-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
					var check_id = jQuery( this ).attr( 'id' );
					col_id = check_id.replace( "mainwp_show_column_", "" );
					try {
						$subscription_sites_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
						if ( check_id.indexOf("mainwp_show_column_desktop") >= 0 ) {							
							col_id = check_id.replace( "mainwp_show_column_desktop", "" );
							$subscription_sites_table.column( '#mobile' + col_id ).visible( jQuery(this).is( ':checked' ) ); // to set mobile columns.						
						}
					} catch(err) {
						// to fix js error.
					}
				} );
			};

			//@see hook_screen_options().
			mainwp_module_cost_tracker_sites_screen_options = function () {
				jQuery( '#mainwp-module-cost-tracker-sites-screen-options-modal' ).modal( {
					allowMultiple: true,
					onHide: function () {
					}
				} ).modal( 'show' );

				jQuery( '#subscription-sites-screen-options-form' ).submit( function() {
					if ( jQuery('input[name=reset_subscriptionsites_columns_order]').attr('value') == 1 ) {
						$subscription_sites_table.colReorder.reset();
					}					
					jQuery( '#mainwp-module-cost-tracker-sites-screen-options-modal' ).modal( 'hide' );
				} );
				return false;
			};
		</script>
		<?php
	}

	/**
	 * Get table rows.
	 *
	 * Optimize for shared hosting or big networks.
	 *
	 * @return array Rows html.
	 */
	public function ajax_get_datatable_rows() {

		$sel_ids = isset( $_GET['selected_ids'] ) ? $_GET['selected_ids'] : ''; //phpcs:ignore -- ok.
		$sel_ids = explode( ',', $sel_ids );

		$lifetime_costs = 0;
		$yearly_costs   = 0;
		$monthly_costs  = 0;

		$payment_types = array(
			'subscription' => esc_html__( 'Subscription', 'mainwp' ),
			'lifetime'     => esc_html__( 'Lifetime', 'mainwp' ),
		);

		$license_types = array(
			'single_site' => esc_html__( 'Single-Site License', 'mainwp' ),
			'multi_site'  => esc_html__( 'Multiple-Site License', 'mainwp' ),
		);

		$product_types   = Cost_Tracker_Admin::get_product_types();
		$payment_methods = Cost_Tracker_Admin::get_payment_methods();

		$show_per_site_price = false;
		if ( isset( $_POST['show_per_site_price'] ) && ! empty( $_POST['show_per_site_price'] ) ) {
			$show_per_site_price = true;
		}

		$current_time = time();
		$upcoming1    = strtotime( gmdate( 'Y-m-d 00:00:00', $current_time ) );
		$upcoming2    = strtotime( '+1 month', $current_time );

		$all_rows  = array();
		$info_rows = array();

		$columns = $this->get_columns( $show_per_site_price );

		if ( $this->items ) {
			foreach ( $this->items as $subscription ) {

				$note         = html_entity_decode( $subscription->note );
				$esc_note     = apply_filters( 'mainwp_escape_content', $note );
				$strip_note   = wp_strip_all_tags( $esc_note );
				$last_renewal = $subscription ? $subscription->last_renewal : 0;
				$next_renewal = $subscription && $last_renewal ? $subscription->next_renewal : 0;

				$sel_sites   = json_decode( $subscription->sites, true );
				$sel_groups  = json_decode( $subscription->groups, true );
				$sel_clients = json_decode( $subscription->clients, true );
				if ( ! is_array( $sel_sites ) ) {
					$sel_sites = array();
				}
				if ( ! is_array( $sel_groups ) ) {
					$sel_groups = array();
				}
				if ( ! is_array( $sel_clients ) ) {
					$sel_clients = array();
				}

				$url_manage_sites = '';

				$params = array(
					'sites'   => $sel_sites,
					'groups'  => $sel_groups,
					'clients' => $sel_clients,
				);

				$sub_sites = MainWP_DB::instance()->get_db_sites( $params );
				$num_sites = count( $sub_sites );
				if ( $num_sites > 0 ) {
					$filter_sites     = ! empty( $sel_sites ) ? '&selected_sites=' . implode( ',', $sel_sites ) : '';
					$filter_groups    = ! empty( $sel_groups ) ? '&g=' . implode( ',', $sel_groups ) : '';
					$filter_clients   = ! empty( $sel_clients ) ? '&client=' . implode( ',', $sel_clients ) : '';
					$url_manage_sites = 'admin.php?page=managesites' . $filter_sites . $filter_groups . $filter_clients;
				}

				$is_multi_license = 'multi_site' === $subscription->license_type ? true : false;
				$sub_type_icon    = '';

				if ( 'lifetime' === $subscription->type ) {
					if ( $num_sites > 0 ) {
						if ( $is_multi_license ) {
							$lifetime_costs += (float) $subscription->price;
						} else { // single site.
							$lifetime_costs += (float) $subscription->price * $num_sites;
						}
					}
					$sub_type_icon = '<i class="infinity icon"></i>';
				} elseif ( 'subscription' === $subscription->type ) {
					if ( $num_sites > 0 ) {
						if ( $is_multi_license ) {
							if ( 'monthly' === $subscription->renewal_type ) {
								$monthly_costs += (float) $subscription->price;
							} elseif ( 'yearly' === $subscription->renewal_type ) {
								$yearly_costs += (float) $subscription->price;
							}
						} elseif ( 'monthly' === $subscription->renewal_type ) {
							// single site.
								$monthly_costs += (float) $subscription->price * $num_sites;
						} elseif ( 'yearly' === $subscription->renewal_type ) {
							$yearly_costs += (float) $subscription->price * $num_sites;
						}
					}
					$sub_type_icon = '<i class="redo icon"></i>';
				}

				$rw_classes = 'cost-item cost-tracker-item-' . intval( $subscription->id );
				$info_item  = array(
					'rowClass' => esc_html( $rw_classes ),
					'cost_id'  => intval( $subscription->id ),
				);

				$cols_data = array();

				foreach ( $columns as $column_name => $column_display_name ) {
					ob_start();
					switch ( $column_name ) {
						case 'cb':
							?>
							<span class="ui checkbox" data-tooltip="<?php esc_attr_e( 'Click to select the site.', 'mainwp' ); ?>" data-inverted="" data-position="right center"><input type="checkbox" name="checked[]"></span>
							<?php
							break;
						case 'name':
							?>
							<a class="item" href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $subscription->id ); ?>"><?php echo esc_html( $subscription->name ); ?></a>
							<?php
							break;
						case 'type':
							?>
							<?php echo $sub_type_icon; //phpcs:ignore -- escaped. ?> <?php echo 'lifetime' === $subscription->type ? 'Lifetime' : esc_html( ucfirst( $subscription->renewal_type ) ); ?>
							<?php
							break;
						case 'product_type':
							?>
							<?php echo isset( $product_types[ $subscription->product_type ] ) ? esc_html( $product_types[ $subscription->product_type ] ) : 'N/A'; ?>
							<?php
							break;
						case 'license_type':
							?>
							<?php echo isset( $license_types[ $subscription->license_type ] ) ? esc_html( $license_types[ $subscription->license_type ] ) : 'N/A'; ?>
							<?php
							break;
						case 'per-site-price':
							if ( 'single_site' === $subscription->license_type ) {
								$per_site_price = $subscription->price;
							} else {
								$per_site_price = ( $num_sites > 0 ) ? ( $subscription->price / $num_sites ) : 0;
							}

							$next30_price = 0;
							if ( 'active' === $subscription->cost_status && 'subscription' === $subscription->type ) {
								$next_rl = $subscription->next_renewal;
								if ( $next_rl <= $upcoming1 ) {
									$next_rl = Cost_Tracker_Admin::get_next_renewal( $upcoming1, $subscription->renewal_type, false );
								}
								while ( $next_rl <= $upcoming2 ) {
									if ( $next_rl > $upcoming1 && $next_rl <= $upcoming2 ) {
										$next30_price += $per_site_price;
									}
									$next_rl = Cost_Tracker_Admin::get_next_renewal( $next_rl, $subscription->renewal_type, false );
								}
							}
							?>
							<strong><?php Cost_Tracker_Utility::cost_tracker_format_price( $next30_price ); ?></strong>
							<?php
							break;
						case 'price':
							echo ! empty( $url_manage_sites ) ? '<a href="' . esc_url( $url_manage_sites ) . '">' . Cost_Tracker_Utility::cost_tracker_format_price( $subscription->price, true ) . '</a>' : Cost_Tracker_Utility::cost_tracker_format_price( $subscription->price );
							break;
						case 'cost_status':
							?>
							<?php echo Cost_Tracker_Admin::get_cost_status_label( $subscription->cost_status ); //phpcs:ignore -- escaped. ?>
							<?php
							break;
						case 'last_renewal':
							?>
							<?php echo $last_renewal ? MainWP_Utility::format_date( $last_renewal ) : ''; //phpcs:ignore -- escaped. ?>
							<?php
							break;
						case 'next_renewal':
							$next_renewal = Cost_Tracker_Admin::get_next_renewal( $subscription->last_renewal, $subscription->renewal_type );
							Cost_Tracker_Admin::generate_next_renewal( $subscription, $next_renewal );
							break;
						case 'payment_method':
							?>
							<?php echo isset( $payment_methods[ $subscription->payment_method ] ) ? esc_html( $payment_methods[ $subscription->payment_method ] ) : 'N/A'; ?>
							<?php
							break;
						case 'note':
							if ( empty( $subscription->note ) ) :
								?>
								<a href="javascript:void(0)" class="mainwp-edit-sub-note" data-tooltip="<?php esc_attr_e( 'Edit notes.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="sticky note outline icon"></i></a>
								<?php else : ?>
									<a href="javascript:void(0)" class="mainwp-edit-sub-note" data-tooltip="<?php echo esc_attr( substr( wp_unslash( $strip_note ), 0, 100 ) ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
								<?php endif; ?>
								<span style="display: none" id="sub-notes-<?php echo intval( $subscription->id ); ?>-note"><?php echo wp_unslash( $esc_note ); //phpcs:ignore -- escaped. ?></span>
							<?php
							break;
						case 'sites':
							?>
							<?php echo ! empty( $url_manage_sites ) ? '<a href="' . esc_url( $url_manage_sites ) . '">' . count( $sub_sites ) . '</a>' : 0; ?>
							<?php
							break;
						case 'actions':
							?>
							<div class="ui right pointing dropdown icon mini basic green button">
								<a href="javascript:void(0)"><i class="ellipsis horizontal icon"></i></a>
								<div class="menu">
									<a class="item" href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $subscription->id ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
									<a class="item subscription_menu_item_delete" href="javascript:void(0)"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
								</div>
							</div>
							<?php
							break;
					}
					$cols_data[ $column_name ] = ob_get_clean();
				}
				$all_rows[]  = $cols_data;
				$info_rows[] = $info_item;
			}
		}
		return array(
			'data'            => $all_rows,
			'recordsTotal'    => $this->total_items,
			'recordsFiltered' => $this->total_items,
			'rowsInfo'        => $info_rows,
		);
	}

	/**
	 * Method ajax_notes_save()
	 *
	 * Post handler for save notes.
	 */
	public function ajax_notes_save() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_notes_save' );
		//phpcs:disable WordPress.Security.NonceVerification.Missing
		$sub_id = isset( $_POST['subid'] ) ? intval( $_POST['subid'] ) : 0;
		$sub    = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $sub_id );
		if ( $sub_id && $sub ) {
			$note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- mainwp_escape_content.
			$esc_note = apply_filters( 'mainwp_escape_content', $note );
			$update   = array(
				'id'   => $sub_id,
				'note' => $esc_note,
			);
			Cost_Tracker_DB::get_instance()->update_cost_tracker( $update );
			die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
		} else {
			die( wp_json_encode( array( 'error' => esc_html__( 'Invalid cost tracker ID or item not found.', 'mainwp' ) ) ) );
		}
		//phpcs:enable
	}


	/**
	 * Method ajax_cost_tracker_delete()
	 *
	 * Post handler for save notes.
	 */
	public function ajax_cost_tracker_delete() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_delete' );
		$sub_id  = isset( $_POST['sub_id'] ) ? intval( $_POST['sub_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $sub_id );
		if ( Cost_Tracker_DB::get_instance()->delete_cost_tracker( 'id', $sub_id ) ) {
			die( wp_json_encode( array( 'status' => 'success' ) ) );
		} else {
			die( wp_json_encode( array( 'error' => esc_html__( 'Failed.', 'mainwp' ) ) ) );
		}
	}

	/**
	 * Render Actions Bar
	 *
	 * Renders the actions bar on the Dashboard tab.
	 */
	public function render_actions_bar() {
		?>
		<div class="mainwp-actions-bar">
			<div class="ui two columns grid">
				<div class="column ui mini form">
						<select class="ui dropdown" id="mwp_cost_tracker_bulk_action">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></option>
							<option value="delete-sub"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
						</select>
						<input type="button" name="mainwp_module_cost_tracker_action_btn" id="mainwp_module_cost_tracker_action_btn" class="ui basic mini button" value="<?php esc_html_e( 'Apply', 'mainwp' ); ?>"/>
						<?php do_action( 'mainwp_module_cost_tracker_actions_bar_left' ); ?>
					</div>
				<div class="right aligned middle aligned column">
				<?php do_action( 'mainwp_module_cost_tracker_actions_bar_right' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Manage Tasks Table Top.
	 */
	public static function render_manage_tasks_table_top( $client_id = 0 ) {

		$filters = array( 'sites', 'client', 'prods_types', 'costs_state', 'dtsstart', 'dtsstop' );

		$get_saved = true;
		foreach ( $filters as $filter ) {
			if ( isset( $_REQUEST[ $filter ] ) ) {
				$get_saved = false;
				break;
			}
		}

		$filter_sites_ids  = '';
		$filter_pro_cats   = '';
		$filter_pro_status = '';
		if ( $get_saved ) {
			$filters_saved = get_user_option( 'mainwp_module_cost_tracker_filters_saved' );
			if ( ! is_array( $filters_saved ) ) {
				$filters_saved = array();
			}
			$filter_client_ids      = isset( $filters_saved['client_ids'] ) && ! empty( $filters_saved['client_ids'] ) ? $filters_saved['client_ids'] : false;
			$filter_prod_type_slugs = isset( $filters_saved['prods_types'] ) && ! empty( $filters_saved['prods_types'] ) ? $filters_saved['prods_types'] : false;
			$filter_cost_state      = isset( $filters_saved['costs_state'] ) && ! empty( $filters_saved['costs_state'] ) ? $filters_saved['costs_state'] : '';
			$filter_dtsstart        = isset( $filters_saved['dtsstart'] ) && ! empty( $filters_saved['dtsstart'] ) ? $filters_saved['dtsstart'] : '';
			$filter_dtsstop         = isset( $filters_saved['dtsstop'] ) && ! empty( $filters_saved['dtsstop'] ) ? $filters_saved['dtsstop'] : '';
		} else {
			// phpcs:disable WordPress.Security.NonceVerification
			$filter_client_ids      = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
			$filter_prod_type_slugs = isset( $_REQUEST['prods_types'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['prods_types'] ) ) : '';
			$filter_cost_state      = isset( $_REQUEST['costs_state'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['costs_state'] ) ) : '';
			$filter_dtsstart        = isset( $_REQUEST['dtsstart'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstart'] ) ) : '';
			$filter_dtsstop         = isset( $_REQUEST['dtsstop'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstop'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification
		}

		$all_defaults  = Cost_Tracker_Admin::get_default_fields_values();
		$product_types = $all_defaults['product_types'];
		$cost_status   = $all_defaults['cost_status'];

		?>
		<div class="mainwp-sub-header" id="mainwp-module-cost-tracker-list-sub-header">
			<div class="ui stackable grid">
				<div class="row ui mini form" id="mainwp-module-cost-tracker-costs-filters-row">
					<div class="ten wide middle aligned column">
						<div class="ui stackable eight column compact grid">
							
							<div class="middle aligned column">
								<div id="mainwp-module-cost-tracker-costs-filter-sites" class="ui fluid selection multiple dropdown">
									<input type="hidden" value="<?php echo esc_html( $filter_sites_ids ); ?>">
									<i class="dropdown icon"></i>
									<div class="default text"><?php esc_html_e( 'All sites', 'mainwp' ); ?></div>
									<div class="menu">
										<?php
										$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_wp_for_current_user() );
										foreach ( $websites as $site ) {
											?>
											<div class="item" data-value="<?php echo intval( $site['id'] ); ?>"><?php echo esc_html( stripslashes( $site['name'] ) ); ?></div>
											<?php
										}
										?>
										<div class="item" data-value="nosites"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></div>
									</div>
								</div>
							</div>
							<?php if ( empty( $client_id ) ) { ?>
							<div class="middle aligned column">
								<div id="mainwp-module-cost-tracker-costs-filter-clients" class="ui fluid selection multiple dropdown">
									<input type="hidden" value="<?php echo esc_html( $filter_client_ids ); ?>">
									<i class="dropdown icon"></i>
									<div class="default text"><?php esc_html_e( 'All clients', 'mainwp' ); ?></div>
									<div class="menu">
										<?php
										$clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );

										foreach ( $clients as $client ) {
											?>
											<div class="item" data-value="<?php echo intval( $client->client_id ); ?>"><?php echo esc_html( stripslashes( $client->name ) ); ?></div>
											<?php
										}
										?>
										<div class="item" data-value="noclients"><?php esc_html_e( 'All Clients', 'mainwp' ); ?></div>
									</div>
								</div>
							</div>
							<?php } ?>

							<div class="middle aligned column">
								<div id="mainwp-module-cost-tracker-costs-filter-cats" class="ui fluid selection multiple dropdown">
									<input type="hidden" value="<?php echo esc_html( $filter_pro_cats ); ?>">
									<i class="dropdown icon"></i>
									<div class="default text"><?php esc_html_e( 'All categories', 'mainwp' ); ?></div>
									<div class="menu">
										<?php
										foreach ( $product_types as $pro_type => $pro_name ) {
											?>
											<div class="item" data-value="<?php echo esc_attr( $pro_type ); ?>"><?php echo esc_html( stripslashes( $pro_name ) ); ?></div>
											<?php
										}
										?>
										<div class="item" data-value="nocategories"><?php esc_html_e( 'All Categories', 'mainwp' ); ?></div>
									</div>
								</div>
							</div>

							<div class="middle aligned column">
								<div id="mainwp-module-cost-tracker-costs-filter-status" class="ui fluid selection multiple dropdown">
									<input type="hidden" value="<?php echo esc_html( $filter_pro_status ); ?>">
									<i class="dropdown icon"></i>
									<div class="default text"><?php esc_html_e( 'All status', 'mainwp' ); ?></div>
									<div class="menu">
										<?php
										foreach ( $cost_status as $status => $status_name ) {
											?>
											<div class="item" data-value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( stripslashes( $status_name ) ); ?></div>
											<?php
										}
										?>
										<div class="item" data-value="nostatus"><?php esc_html_e( 'All Status', 'mainwp' ); ?></div>
									</div>
								</div>
							</div>
							<div class="middle aligned column">
								<div class="ui calendar fluid mainwp_datepicker">
									<div class="ui input left icon">
										<i class="calendar icon"></i>
										<input type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'Select next renewal start date', 'mainwp' ); ?>" id="mainwp-module-cost-tracker-costs-filter-dtsstart" value="<?php echo ! empty( $filter_dtsstart ) ? esc_attr( $filter_dtsstart ) : ''; ?>"/>
									</div>
								</div>
							</div>
							<div class="middle aligned column">
								<div class="ui calendar fluid mainwp_datepicker">
									<div class="ui input left icon">
										<i class="calendar icon"></i>
										<input type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'Select next renewal end date', 'mainwp' ); ?>" id="mainwp-module-cost-tracker-costs-filter-dtsstop" value="<?php echo ! empty( $filter_dtsstop ) ? esc_attr( $filter_dtsstop ) : ''; ?>"/>
									</div>
								</div>
							</div>
							<div class="middle aligned column">
								<button onclick="mainwp_module_cost_tracker_manage_costs_filter()" class="ui tiny basic button"><?php esc_html_e( 'Filter costs', 'mainwp' ); ?></button>
							</div>
						</div>
					</div>
					<div class="six wide middle aligned column">
						
					</div>
				</div>
			</div>
		</div>	
		<?php
	}



	/**
	 * Method render_modal_edit_notes()
	 *
	 * Render modal window for edit notes.
	 *
	 * @param string $what What modal window to render. Default = site.
	 *
	 * @return void
	 */
	public static function render_modal_edit_notes( $what = 'site' ) {
		?>
		<div id="mainwp-notes-subs-modal" class="ui modal">
			<i class="close icon" id="mainwp-notes-subs-cancel"></i>
			<div class="header"><?php esc_html_e( 'Notes', 'mainwp' ); ?></div>
			<div class="content" id="mainwp-notes-subs-content">
				<div id="mainwp-notes-subs-status" class="ui message hidden"></div>
				<div id="mainwp-notes-subs-html"></div>
				<div id="mainwp-notes-subs-editor" class="ui form" style="display:none;">
					<div class="field">
						<label><?php esc_html_e( 'Edit note', 'mainwp' ); ?></label>
						<textarea id="mainwp-notes-subs-note"></textarea>
					</div>
					<div><?php esc_html_e( 'Allowed HTML tags:', 'mainwp' ); ?> &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;hr&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;h1&gt;, &lt;h2&gt; </div>
				</div>
			</div>
			<div class="actions">
				<div class="ui grid">
					<div class="eight wide left aligned middle aligned column">
						<input type="button" class="ui green button" id="mainwp-notes-subs-save" value="<?php esc_attr_e( 'Save Note', 'mainwp' ); ?>" style="display:none;"/>
						<input type="button" class="ui green button" id="mainwp-notes-subs-edit" value="<?php esc_attr_e( 'Edit Note', 'mainwp' ); ?>"/>
					</div>
					<div class="eight wide column">
						<input type="hidden" id="mainwp-notes-subs-subid" value=""/>
						<input type="hidden" id="mainwp-notes-subs-slug" value=""/>
						<input type="hidden" id="mainwp-which-note" value="<?php echo esc_html( $what ); ?>"/>
					</div>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Render Page Settings.
	 */
	public static function render_screen_options() {

		$columns = self::get_columns();

		$show_cols = get_user_option( 'mainwp_module_costs_tracker_manage_showhide_columns' );

		if ( ! is_array( $show_cols ) ) {
			$show_cols = array();
		}

		?>
		<div class="ui modal" id="mainwp-module-cost-tracker-sites-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<form method="POST" action="" id="subscription-sites-screen-options-form" name="subscription_sites_screen_options_form">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'CostTrackerSitesScrOptions' ) ); ?>" />
						<div class="ui grid field">
							<label class="six wide column"><?php esc_html_e( 'Show columns', 'mainwp' ); ?></label>
							<div class="ten wide column">
								<ul class="mainwp_hide_wpmenu_checkboxes">
								<?php
								foreach ( $columns as $name => $title ) {
									if ( 'cb' === $name ) {
										continue;
									}
									?>
										<li>
											<div class="ui checkbox">
												<input type="checkbox"
											<?php
											$show_col = ! isset( $show_cols[ $name ] ) || ( 1 === (int) $show_cols[ $name ] );
											if ( $show_col ) {
												echo 'checked="checked"';
											}
											?>
												id="mainwp_show_column_<?php echo esc_attr( $name ); ?>" name="mainwp_show_column_<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>">
												<label for="mainwp_show_column_<?php echo esc_attr( $name ); ?>" ><?php echo $title; //phpcs:ignore ?></label>
												<input type="hidden" value="<?php echo esc_attr( $name ); ?>" name="show_columns_name[]" />
											</div>
										</li>
										<?php
								}
								?>
								</ul>
							</div>
					</div>
				</div>
			<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column">
							<span data-tooltip="<?php esc_attr_e( 'Returns this page to the state it was in when installed. The feature also restores any column you have moved through the drag and drop feature on the page.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-subscriptionsites-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
						</div>
						<div class="ui right aligned column">
					<input type="submit" class="ui green button" name="btnSubmit" id="submit-subscriptionsites-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
					<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
				</div>
					</div>
				</div>
				<input type="hidden" name="reset_subscriptionsites_columns_order" value="0">
			</form>
		</div>
		<div class="ui small modal" id="mainwp-module-cost-tracker-sites-site-preview-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Screen Options', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<span><?php esc_html_e( 'Would you like to turn on home screen previews?  This function queries WordPress.com servers to capture a screenshot of your site the same way comments shows you preview of URLs.', 'mainwp' ); ?>
			</div>
			<div class="actions">
				<div class="ui ok button"><?php esc_html_e( 'Yes', 'mainwp' ); ?></div>
				<div class="ui cancel button"><?php esc_html_e( 'No', 'mainwp' ); ?></div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery('#reset-subscriptionsites-settings').on( 'click', function () {
					mainwp_confirm(__( 'Are you sure.' ), function(){
						jQuery('.mainwp_hide_wpmenu_checkboxes input[id^="mainwp_show_column_"]').prop( 'checked', false );
						//default columns
						var cols = ['name','type','product_type','price','cost_status','license_type','last_renewal','next_renewal','payment_method','note','sites','actions'];
						jQuery.each( cols, function ( index, value ) {
							jQuery('.mainwp_hide_wpmenu_checkboxes input[id="mainwp_show_column_' + value + '"]').prop( 'checked', true );
						} );
						jQuery('input[name=reset_subscriptionsites_columns_order]').attr('value',1);
						jQuery('#submit-subscriptionsites-settings').click();
					}, false, false, true );
					return false;
				});
			} );
		</script>
			<?php
	}
}
