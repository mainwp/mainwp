<?php
/**
 * Monitoring Sites List Table.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Monitoring_Sites_List_Table
 *
 * @package MainWP\Dashboard
 *
 * MainWP sites monitoring list.
 *
 * @todo The only variables that seam to be used are $column_headers.
 */
class MainWP_Monitoring_Sites_List_Table extends MainWP_Manage_Sites_List_Table {

	/**
	 * Protected variable to hold columns headers
	 *
	 * @var array
	 */
	protected $column_headers;

	/**
	 * Method __construct()
	 *
	 * Add action to generate tabletop.
	 */
	public function __construct() {
		add_action( 'mainwp_managesites_tabletop', array( &$this, 'generate_tabletop' ) );
	}

	/**
	 * Get the default primary column name.
	 *
	 * @return string Child site name.
	 */
	protected function get_default_primary_column_name() {
		return 'site';
	}


	/**
	 * Set the column names.
	 *
	 * @param mixed  $item        MainWP site table item.
	 * @param string $column_name Column name to use.
	 *
	 * @return string Column name.
	 */
	public function column_default( $item, $column_name ) { 	// phpcs:ignore -- comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Filter: mainwp_monitoring_sitestable_item
		 *
		 * Filters the Monitoring Sites table column items. Allows user to create new column item.
		 *
		 * @param array $item Array containing child site data.
		 *
		 * @since 4.1
		 */
		$item = apply_filters( 'mainwp_monitoring_sitestable_item', $item, $item );

		switch ( $column_name ) {
			case 'status':
			case 'site':
			case 'login':
			case 'url':
			case 'status_code':
			case 'last_check':
			case 'site_health':
			case 'preview':
			case 'site_actions':
				return '';
			default:
				return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
		}
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array $sortable_columns Array of sortable column names.
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'site'        => array( 'site', false ),
			'url'         => array( 'url', false ),
			'status_code' => array( 'status_code', false ),
			'last_check'  => array( 'last_check', false ),
			'site_health' => array( 'site_health', false ),
		);

		return $sortable_columns;
	}

	/**
	 * Get default columns.
	 *
	 * @return array Array of default column names.
	 */
	public function get_default_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'status'       => '',
			'site'         => __( 'Site', 'mainwp' ),
			'login'        => '<i class="sign in alternate icon"></i>',
			'url'          => __( 'URL', 'mainwp' ),
			'site_health'  => __( 'Site Health', 'mainwp' ),
			'status_code'  => __( 'Status Code', 'mainwp' ),
			'last_check'   => __( 'Last Check', 'mainwp' ),
			'site_preview' => '<i class="eye icon"></i>',
		);
	}

	/**
	 * Method get_columns()
	 *
	 * Combine all columns.
	 *
	 * @return array $columns Array of column names.
	 */
	public function get_columns() {

		$columns = $this->get_default_columns();

		/**
		 * Filter: mainwp_monitoring_sitestable_getcolumns
		 *
		 * Filters the Monitoring Sites table columns. Allows user to create a new column.
		 *
		 * @param array $columns Array containing table columns.
		 *
		 * @since 4.1
		 */
		$columns = apply_filters( 'mainwp_monitoring_sitestable_getcolumns', $columns, $columns );

		$columns['site_actions'] = '';

		return $columns;
	}

	/**
	 * Instantiate Columns.
	 *
	 * @return array $init_cols
	 */
	public function get_columns_init() {
		$cols      = $this->get_columns();
		$init_cols = array();
		foreach ( $cols as $key => $val ) {
			$init_cols[] = array( 'data' => esc_html( $key ) );
		}
		return $init_cols;
	}

	/**
	 * Get column defines.
	 *
	 * @return array $defines Array of defines.
	 */
	public function get_columns_defines() {
		$defines   = array();
		$defines[] = array(
			'targets'   => 'no-sort',
			'orderable' => false,
		);
		$defines[] = array(
			'targets'   => 'manage-cb-column',
			'className' => 'check-column collapsing',
		);
		$defines[] = array(
			'targets'   => 'manage-site-column',
			'className' => 'column-site-bulk',
		);
		$defines[] = array(
			'targets'   => array( 'manage-login-column', 'manage-last_check-column', 'manage-status_code-column', 'manage-site_health-column', 'manage-site_actions-column', 'extra-column' ),
			'className' => 'collapsing',
		);
		$defines[] = array(
			'targets'   => array( 'manage-status-column' ),
			'className' => 'collapsing',
		);
		return $defines;
	}

	/**
	 * Method generate_tabletop()
	 *
	 * Run the render_manage_sites_table_top menthod.
	 */
	public function generate_tabletop() {
		$this->render_manage_sites_table_top();
	}

	/**
	 * Create bulk actions drop down.
	 *
	 * @return array $actions Return actions through the mainwp_monitoringsites_bulk_actions filter.
	 */
	public function get_bulk_actions() {

		$actions = array(
			'checknow' => __( 'Check Now', 'mainwp' ),
			'sync'     => __( 'Sync Data', 'mainwp' ),
		);

		/**
		 * Filter: mainwp_monitoringsites_bulk_actions
		 *
		 * Filters bulk actions on the Monitoring Sites page. Allows user to hook in new actions or remove default ones.
		 *
		 * @since 4.1
		 */
		return apply_filters( 'mainwp_monitoringsites_bulk_actions', $actions );
	}

	/**
	 * Render manage sites table top.
	 */
	public function render_manage_sites_table_top() {
		$items_bulk = $this->get_bulk_actions();

		$selected_status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
		$selected_group  = isset( $_REQUEST['g'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) : '';

		if ( empty( $selected_status ) && empty( $selected_group ) ) {
			$selected_status = get_option( 'mainwp_monitoringsites_filter_status' );
			$selected_group  = get_option( 'mainwp_monitoringsites_filter_group' );
		}

		?>
		<div class="ui grid">
			<div class="equal width row ui mini form">
			<div class="middle aligned column">
					<?php esc_html_e( 'Bulk actions: ', 'mainwp' ); ?>
					<div id="mainwp-sites-bulk-actions-menu" class="ui selection dropdown">
						<div class="default text"><?php esc_html_e( 'Select action', 'mainwp' ); ?></div>
						<i class="dropdown icon"></i>
						<div class="menu">
							<?php
							foreach ( $items_bulk as $value => $title ) {
								if ( 'seperator_' === substr( $value, 0, 10 ) ) {
									?>
									<div class="ui divider"></div>
									<?php
								} else {
									?>
									<div class="item" data-value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $title ); ?></div>
									<?php
								}
							}
							?>
						</div>
					</div>
					<button class="ui tiny basic button" id="mainwp-do-sites-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
				</div>
				<div class="right aligned middle aligned column">
						<?php esc_html_e( 'Filter sites: ', 'mainwp' ); ?>
						<div id="mainwp-filter-sites-group" class="ui multiple selection dropdown">
							<input type="hidden" value="<?php echo esc_html( $selected_group ); ?>">
							<i class="dropdown icon"></i>
							<div class="default text"><?php esc_html_e( 'All groups', 'mainwp' ); ?></div>
							<div class="menu">
								<?php
								$groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
								foreach ( $groups as $group ) {
									?>
									<div class="item" data-value="<?php echo $group->id; ?>"><?php echo stripslashes( $group->name ); ?></div>
									<?php
								}
								?>
								<div class="item" data-value="nogroups"><?php esc_html_e( 'No Groups', 'mainwp' ); ?></div>
							</div>
						</div>
						<div class="ui selection dropdown" id="mainwp-filter-sites-status">
							<div class="default text"><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
							<i class="dropdown icon"></i>
							<div class="menu">
								<div class="item" data-value="all" ><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
								<div class="item" data-value="online"><?php esc_html_e( 'Online', 'mainwp' ); ?></div>
								<div class="item" data-value="offline"><?php esc_html_e( 'Offline', 'mainwp' ); ?></div>
								<div class="item" data-value="undefined"><?php esc_html_e( 'Undefined', 'mainwp' ); ?></div>
						</div>
						</div>
						<button onclick="mainwp_manage_monitor_sites_filter()" class="ui tiny basic button"><?php esc_html_e( 'Filter Sites', 'mainwp' ); ?></button>
				</div>
		</div>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				<?php if ( '' !== $selected_status ) { ?>
				jQuery( '#mainwp-filter-sites-status' ).dropdown( "set selected", "<?php echo esc_js( $selected_status ); ?>" );
				<?php } ?>
			} );
		</script>
		<?php
	}


	/**
	 * Prepair the items to be listed.
	 *
	 * @param bool $optimize true|false Whether or not to optimize.
	 */
	public function prepare_items( $optimize = true ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$orderby = 'wp.url';

		$req_orderby = null;
		$req_order   = null;

		if ( $optimize ) {

			if ( isset( $_REQUEST['order'] ) ) {
				$columns = isset( $_REQUEST['columns'] ) ? $_REQUEST['columns'] : array();
				$ord_col = isset( $_REQUEST['order'][0]['column'] ) ? $_REQUEST['order'][0]['column'] : '';
				if ( isset( $columns[ $ord_col ] ) ) {
					$req_orderby = isset( $columns[ $ord_col ]['data'] ) ? $columns[ $ord_col ]['data'] : '';
					$req_order   = isset( $_REQUEST['order'][0]['dir'] ) ? $_REQUEST['order'][0]['dir'] : '';
				}
			}
			if ( isset( $req_orderby ) ) {
				if ( 'site' === $req_orderby ) {
					$orderby = 'wp.name ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
				} elseif ( 'url' === $req_orderby ) {
					$orderby = 'wp.url ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
				} elseif ( 'status' === $req_orderby ) {
					$orderby = 'CASE true
								WHEN (offline_check_result = 1)
									THEN 1
								WHEN (offline_check_result <> 1)
									THEN 2
								END ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
				} elseif ( 'status_code' === $req_orderby ) {
					$orderby = 'wp.http_response_code ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
				} elseif ( 'last_check' === $req_orderby ) {
					$orderby = 'wp.offline_checks_last ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
				} elseif ( 'site_health' === $req_orderby ) {
					$orderby = 'wp_sync.health_value ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
				}
			}
		}

		if ( ! $optimize ) {
			$perPage = 9999;
			$start   = 0;
		} else {
			$perPage = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : 25;
			if ( -1 == $perPage ) {
				$perPage = 9999;
			}
			$start = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;
		}

		$search = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';

		$get_saved_state = empty( $search ) && ! isset( $_REQUEST['g'] ) && ! isset( $_REQUEST['status'] );
		$get_all         = ( '' === $search ) && ( isset( $_REQUEST['status'] ) && 'all' === $_REQUEST['status'] ) && ( isset( $_REQUEST['g'] ) && -1 == $_REQUEST['g'] ) ? true : false;

		$group_ids   = false;
		$site_status = '';

		if ( ! isset( $_REQUEST['status'] ) ) {
			if ( $get_saved_state ) {
				$site_status = get_option( 'mainwp_monitoringsites_filter_status' );
			} else {
				MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_status', '' );
			}
		} else {
			$site_status = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );
			MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_status', $site_status );
		}

		if ( $get_all ) {
			MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_group', '' );
		} elseif ( ! isset( $_REQUEST['g'] ) ) {
			if ( $get_saved_state ) {
				$group_ids = get_option( 'mainwp_monitoringsites_filter_group' );
			} else {
				MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_group', '' );
			}
		} else {
			MainWP_Utility::update_option( 'mainwp_monitoringsites_filter_group', wp_unslash( $_REQUEST['g'] ) );
			$group_ids = wp_unslash( $_REQUEST['g'] ); // may be multi groups.
		}

		$where = null;

		if ( '' !== $site_status && 'all' !== $site_status ) {
			if ( 'online' === $site_status ) {
				$where = 'wp.offline_check_result = 1';
			} elseif ( 'undefined' === $site_status ) {
				$where = 'wp.http_response_code = ""';
			} elseif ( 'offline' === $site_status ) {
				$where = 'wp.offline_check_result <> "" AND wp.offline_check_result <> 1';
			}
		}

		$total_params = array( 'count_only' => true );
		if ( $get_all ) {
			$params = array(
				'selectgroups' => true,
				'orderby'      => $orderby,
				'offset'       => $start,
				'rowcount'     => $perPage,
			);
		} else {
			$total_params['search'] = $search;
			$params                 = array(
				'selectgroups' => true,
				'orderby'      => $orderby,
				'offset'       => $start,
				'rowcount'     => $perPage,
				'search'       => $search,
			);

			if ( ! empty( $group_ids ) ) {
				$group_ids = explode( ',', $group_ids ); // convert to array.
			}

			if ( $group_ids && 0 < count( $group_ids ) ) {
				$total_params['group_id'] = $group_ids;
				$params['group_id']       = $group_ids;
			}

			if ( ! empty( $where ) ) {
				$total_params['extra_where'] = $where;
				$params['extra_where']       = $where;
			}
		}

		$total_websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $total_params ) );
		$totalRecords   = ( $total_websites ? MainWP_DB::num_rows( $total_websites ) : 0 );
		if ( $total_websites ) {
			MainWP_DB::free_result( $total_websites );
		}

		$params['extra_view'] = array( 'favi_icon', 'health_site_status' );

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $params ) );

		$site_ids = array();
		while ( $websites && ( $site = MainWP_DB::fetch_object( $websites ) ) ) {
			$site_ids[] = $site->id;
		}

		/**
		 * Action: mainwp_monitoring_sitestable_prepared_items
		 *
		 * Fires before the Monitoring Sites table itemes are prepared.
		 *
		 * @param object $websites Object containing child sites data.
		 * @param array  $site_ids Array containing IDs of all child sites.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_monitoring_sitestable_prepared_items', $websites, $site_ids );

		MainWP_DB::data_seek( $websites, 0 );

		$this->items       = $websites;
		$this->total_items = $totalRecords;
	}

	/**
	 * Display the table.
	 *
	 * @param bool $optimize true|false Whether or not to optimize.
	 */
	public function display( $optimize = true ) {

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

		?>
		<table id="mainwp-manage-sites-table" style="width:100%" class="ui single line selectable stackable table">
			<thead>
			<tr>
				<?php $this->print_column_headers( $optimize, true ); ?>
			</tr>
			</thead>
			<?php if ( ! $optimize ) { ?>
			<tbody id="mainwp-manage-sites-body-table">
				<?php $this->display_rows_or_placeholder(); ?>
			</tbody>
			<?php } ?>
			<tfoot>
				<tr>
					<?php $this->print_column_headers( $optimize, false ); ?>
				</tr>
	</tfoot>
	</table>
	<div id="mainwp-loading-sites" style="display: none;">
	<div class="ui active inverted dimmer">
	<div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading ...', 'mainwp' ); ?></div>
	</div>
	</div>

		<?php
		$table_features = array(
			'searching'     => 'true',
			'paging'        => 'true',
			'pagingType'    => '"full_numbers"',
			'info'          => 'true',
			'colReorder'    => '{ fixedColumnsLeft: 1, fixedColumnsRight: 1 }',
			'stateSave'     => 'true',
			'stateDuration' => '0',
			'order'         => '[]',
		);

		/**
		 * Filter: mainwp_monitoring_table_features
		 *
		 * Filter the Monitoring table features.
		 *
		 * @since 4.1
		 */
		$table_features = apply_filters( 'mainwp_monitoring_table_features', $table_features );
		?>
	<script type="text/javascript">	
			jQuery( document ).ready( function( $ ) {
			<?php if ( ! $optimize ) { ?>
				try {
					$manage_sites_table = jQuery( '#mainwp-manage-sites-table' ).DataTable( {
						"searching" : <?php echo $table_features['searching']; ?>,
						"paging" : <?php echo $table_features['paging']; ?>,
						"pagingType" : <?php echo $table_features['pagingType']; ?>,
						"info" : <?php echo $table_features['info']; ?>,
						"colReorder" : <?php echo $table_features['colReorder']; ?>,
						"stateSave" : <?php echo $table_features['stateSave']; ?>,
						"stateDuration" : <?php echo $table_features['stateDuration']; ?>,
						"order" : <?php echo $table_features['order']; ?>,
						"columnDefs": [ { "targets": 'no-sort', "orderable": false } ],
						"lengthMenu" : [ [<?php echo $pagelength_val; ?>, -1 ], [<?php echo $pagelength_title; ?>, "All" ] ],
						"pageLength": <?php echo intval( $sites_per_page ); ?>
					} );
				} catch(err) {
					// to fix js error.
				}
				mainwp_datatable_fix_menu_overflow();
			<?php } else { ?>
				try {
					$manage_sites_table = jQuery( '#mainwp-manage-sites-table' ).on( 'processing.dt', function ( e, settings, processing ) {
						jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
						if (!processing) {
							var tb = jQuery( '#mainwp-manage-sites-table' );
							tb.find( 'th[cell-cls]' ).each( function(){
								var ceIdx = this.cellIndex;
								var cls = jQuery( this ).attr( 'cell-cls' );
								jQuery( '#mainwp-manage-sites-table tr' ).each(function(){
									jQuery(this).find( 'td:eq(' + ceIdx + ')' ).addClass(cls);
								} );
							} );
							$( '#mainwp-manage-sites-table .ui.dropdown' ).dropdown();
							$( '#mainwp-manage-sites-table .ui.checkbox' ).checkbox();
						}

					} ).on( 'column-reorder.dt', function ( e, settings, details ) {
						$( '#mainwp-manage-sites-table .ui.dropdown' ).dropdown();
						$( '#mainwp-manage-sites-table .ui.checkbox' ).checkbox();
					} ).DataTable( {
						"ajax": {
							"url": ajaxurl,
							"type": "POST",
							"data":  function ( d ) {
								return $.extend( {}, d, mainwp_secure_data( {
									action: 'mainwp_monitoring_sites_display_rows',
									status: jQuery("#mainwp-filter-sites-status").dropdown("get value"),
									g: jQuery("#mainwp-filter-sites-group").dropdown("get value")
								} )
							);
							},
							"dataSrc": function ( json ) {
								for ( var i=0, ien=json.data.length ; i < ien ; i++ ) {
									json.data[i].syncError = json.rowsInfo[i].syncError ? json.rowsInfo[i].syncError : false;
									json.data[i].rowClass = json.rowsInfo[i].rowClass;
									json.data[i].siteID = json.rowsInfo[i].siteID;
									json.data[i].siteUrl = json.rowsInfo[i].siteUrl;
								}
								return json.data;
							}
						},
						"searching" : <?php echo $table_features['searching']; ?>,
						"paging" : <?php echo $table_features['paging']; ?>,
						"pagingType" : <?php echo $table_features['pagingType']; ?>,
						"info" : <?php echo $table_features['info']; ?>,
						"colReorder" : <?php echo $table_features['colReorder']; ?>,
						"stateSave" : <?php echo $table_features['stateSave']; ?>,
						"stateDuration" : <?php echo $table_features['stateDuration']; ?>,
						"order" : <?php echo $table_features['order']; ?>,
						"lengthMenu" : [ [<?php echo $pagelength_val; ?>, -1 ], [<?php echo $pagelength_title; ?>, "All"] ],
						serverSide: true,
						"pageLength": <?php echo intval( $sites_per_page ); ?>,
						"columnDefs": <?php echo wp_json_encode( $this->get_columns_defines() ); ?>,
						"columns": <?php echo wp_json_encode( $this->get_columns_init() ); ?>,
						"drawCallback": function( settings ) {
							this.api().tables().body().to$().attr( 'id', 'mainwp-manage-sites-body-table' );
							mainwp_preview_init_event();
							mainwp_datatable_fix_menu_overflow();
						},
						rowCallback: function (row, data) {
							jQuery( row ).addClass(data.rowClass);
							jQuery( row ).attr( 'site-url', data.siteUrl );
							jQuery( row ).attr( 'siteid', data.siteID );
							jQuery( row ).attr( 'id', "child-site-" + data.siteID );
							if ( data.syncError ) {
								jQuery( row ).find( 'td.column-site-bulk' ).addClass( 'site-sync-error' );
							};
						}
					} );
				} catch(err) {
					// to fix js error.
				}
					<?php } ?>
				} );

				mainwp_manage_monitor_sites_filter = function() {
					<?php if ( ! $optimize ) { ?>
						var group = jQuery( "#mainwp-filter-sites-group" ).dropdown( "get value" );
						var status = jQuery( "#mainwp-filter-sites-status" ).dropdown( "get value" );

						var params = '';						
						params += '&g=' + group;						
						if ( status != '' )
							params += '&status=' + status;

						window.location = 'admin.php?page=MonitoringSites' + params;
						return false;
					<?php } else { ?>
						try {
							$manage_sites_table.ajax.reload();
						} catch(err) {
							// to fix js error.
						}
					<?php } ?>
				};

				</script>
		<?php
	}

	/**
	 * Echo the column headers.
	 *
	 * @param bool $optimize true|false Whether or not to optimise.
	 * @param bool $top true|false.
	 */
	public function print_column_headers( $optimize, $top = true ) {
		list( $columns, $sortable, $primary ) = $this->get_column_info();

		$current_url = set_url_scheme( 'http://' . ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' ) );
		$current_url = remove_query_arg( 'paged', $current_url );

		if ( ! empty( $columns['cb'] ) ) {
			$columns['cb'] = '<div class="ui checkbox"><input id="' . ( $top ? 'cb-select-all-top' : 'cb-select-all-bottom' ) . '" type="checkbox" /></div>';
		}

		$def_columns                 = $this->get_default_columns();
		$def_columns['site_actions'] = '';

		foreach ( $columns as $column_key => $column_display_name ) {

			$class = array( 'manage-' . $column_key . '-column' );
			$attr  = '';
			if ( ! isset( $def_columns[ $column_key ] ) ) {
				$class[] = 'extra-column';
				if ( $optimize ) {
					$attr = 'cell-cls="' . esc_html( "collapsing $column_key column-$column_key" ) . '"';
				}
			}

			if ( 'cb' === $column_key ) {
				$class[] = 'check-column';
				$class[] = 'collapsing';
			}

			if ( ! isset( $sortable[ $column_key ] ) ) {
				$class[] = 'no-sort';
			}

			$tag = 'th';
			$id  = "id='$column_key'";

			if ( ! empty( $class ) ) {
				$class = "class='" . join( ' ', $class ) . "'";
			}

			echo "<$tag $id $class $attr>$column_display_name</$tag>";
		}
	}

	/**
	 * Get column info.
	 */
	protected function get_column_info() {

		if ( isset( $this->column_headers ) && is_array( $this->column_headers ) ) {
			$column_headers = array( array(), array(), array(), $this->get_default_primary_column_name() );
			foreach ( $this->column_headers as $key => $value ) {
				$column_headers[ $key ] = $value;
			}

			return $column_headers;
		}

		$columns = $this->get_columns();

		$sortable_columns = $this->get_sortable_columns();

		$_sortable = $sortable_columns;

		$sortable = array();
		foreach ( $_sortable as $id => $data ) {
			if ( empty( $data ) ) {
				continue;
			}

			$data = (array) $data;
			if ( ! isset( $data[1] ) ) {
				$data[1] = false;
			}

			$sortable[ $id ] = $data;
		}

		$primary              = $this->get_default_primary_column_name();
		$this->column_headers = array( $columns, $sortable, $primary );

		return $this->column_headers;
	}


	/**
	 * Single row.
	 *
	 * @param mixed $website Object containing the site info.
	 */
	public function single_row( $website ) {
		$classes = '';
		if ( isset( $website['groups'] ) && ! empty( $website['groups'] ) ) {
			$group_class = $website['groups'];
			$group_class = explode( ',', $group_class );
			if ( is_array( $group_class ) ) {
				foreach ( $group_class as $_class ) {
					$_class   = trim( $_class );
					$_class   = MainWP_Utility::sanitize_file_name( $_class );
					$classes .= ' ' . strtolower( $_class );
				}
			} else {
				$_class   = MainWP_Utility::sanitize_file_name( $group_class );
				$classes .= ' ' . strtolower( $_class );
			}
		}

		$health_status = isset( $website['health_site_status'] ) ? json_decode( $website['health_site_status'], true ) : array();

		$hstatus     = MainWP_Utility::get_site_health( $health_status );
		$hval        = $hstatus['val'];
		$critical    = $hstatus['critical'];
		$good_health = false;

		if ( 80 <= $hval && 0 == $critical ) {
			$good_health = true;
		}

		$statusUndefined = ( '' == $website['http_response_code'] );
		$statusOnline    = ( 1 == $website['offline_check_result'] );

		$classes        = trim( $classes );
		$status_classes = $statusOnline ? '' : 'error';
		if ( empty( $status_classes ) ) {
			$status_classes = $good_health ? '' : 'warning';
		}

		$classes = ' class="child-site mainwp-child-site-' . $website['id'] . ' ' . $status_classes . ' ' . $classes . '"';

		echo '<tr id="child-site-' . $website['id'] . '"' . $classes . ' siteid="' . $website['id'] . '" site-url="' . $website['url'] . '">';
		$this->single_row_columns( $website, $good_health );
		echo '</tr>';
	}


	/**
	 * Columns for a single row.
	 *
	 * @param mixed $website     Object containing the site info.
	 * @param bool  $good_health Good site health info.
	 */
	protected function single_row_columns( $website, $good_health ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$statusUndefined = ( '' == $website['http_response_code'] );
		$statusOnline    = ( 1 == $website['offline_check_result'] );

		$note       = html_entity_decode( $website['note'] );
		$esc_note   = MainWP_Utility::esc_content( $note );
		$strip_note = wp_strip_all_tags( $esc_note );

		list( $columns ) = $this->get_column_info();

		$use_favi = get_option( 'mainwp_use_favicon', 1 );

		if ( $good_health ) {
			$h_color = 'green';
			$h_text  = __( 'Good', 'mainwp' );
		} else {
			$h_color = 'orange';
			$h_text  = __( 'Should be improved', 'mainwp' );
		}

		$http_error_codes = MainWP_Utility::get_http_codes();

		foreach ( $columns as $column_name => $column_display_name ) {

			$classes    = "collapsing center aligned $column_name column-$column_name";
			$attributes = "class='$classes'";

			?>
			<?php if ( 'cb' === $column_name ) { ?>
				<td class="check-column">
					<div class="ui checkbox">
						<input type="checkbox" value="<?php echo $website['id']; ?>" name=""/>
					</div>
				</td>
			<?php } elseif ( 'status' === $column_name ) { ?>
				<td class="center aligned collapsing">
					<?php if ( $statusUndefined ) : ?>
						<span data-tooltip="<?php esc_attr_e( 'Site status appears to be undefined.', 'mainwp' ); ?>"  data-position="right center"  data-inverted=""><a href="#"><i class="circular inverted exclamation red icon"></i></a></span>
						<?php
					elseif ( $statusOnline ) :
						if ( ! $good_health ) {
							?>
							<span data-tooltip="<?php esc_attr_e( 'Site status appears to be online.', 'mainwp' ); ?>"  data-position="right center" data-inverted=""><i class="circular inverted yellow heartbeat icon"></i></span>
							<?php
						} else {
							?>
							<span data-tooltip="<?php esc_attr_e( 'Site status appears to be online.', 'mainwp' ); ?>"  data-position="right center" data-inverted=""><i class="circular inverted green check icon"></i></span>
							<?php
						}
						?>

					<?php else : ?>
						<span data-tooltip="<?php esc_attr_e( 'Site status appears to be offline.', 'mainwp' ); ?>"  data-position="right center" data-inverted=""><a href="#"><i class="circular inverted exclamation red icon"></i></a></span>
						<?php
					endif;
					?>
				</td>
				<?php
			} elseif ( 'site' === $column_name ) {
				$cls_site = '';
				if ( ! $statusOnline ) {
					$cls_site = 'site-sync-error';
				}
				?>
				<td class="column-site-bulk <?php echo $cls_site; ?>"><a href="<?php echo 'admin.php?page=managesites&dashboard=' . $website['id']; ?>" data-tooltip="<?php esc_attr_e( 'Open the site overview', 'mainwp' ); ?>"  data-position="right center" data-inverted=""><?php echo stripslashes( $website['name'] ); ?></a><i class="ui active inline loader tiny" style="display:none"></i><span id="site-status-<?php echo esc_attr( $website['id'] ); ?>" class="status hidden"></span></td>
			<?php } elseif ( 'login' === $column_name ) { ?>
				<td class="collapsing">
				<?php if ( ! mainwp_current_user_have_right( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
					<i class="sign in icon"></i>
					<?php
				else :
					?>
					<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website['id']; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>" data-position="right center" data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
					<?php
				endif;
				?>
				</td>
				<?php
			} elseif ( 'url' === $column_name ) {

				$imgfavi = '';
				if ( $use_favi ) {
					$siteObj  = (object) $website;
					$favi_url = MainWP_Connect::get_favico_url( $siteObj );
					$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
				}

				?>
				<td><?php echo $imgfavi; ?><a href="<?php echo esc_url( $website['url'] ); ?>" class="mainwp-may-hide-referrer open_site_url" target="_blank"><?php echo esc_html( $website['url'] ); ?></a></td>
			<?php } elseif ( 'status_code' === $column_name ) { ?>
				<td class="collapsing" data-order="<?php echo esc_html( $website['http_response_code'] ); ?>">
					<?php
					if ( $website['http_response_code'] ) {
						$code = $website['http_response_code'];
						echo esc_html( $code );
						if ( isset( $http_error_codes[ $code ] ) ) {
							echo ' - ' . $http_error_codes[ $code ];
						}
					}
					?>
				</td>
			<?php } elseif ( 'last_check' === $column_name ) { ?>
				<td class="collapsing"><?php echo 0 != $website['offline_checks_last'] ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website['offline_checks_last'] ) ) : ''; ?></td>
			<?php } elseif ( 'site_health' === $column_name ) { ?>
				<td class="collapsing"><span><a class="open_newwindow_wpadmin" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website['id'] ); ?>&location=<?php echo base64_encode( 'site-health.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>" data-tooltip="<?php echo esc_html__( 'Jump to the Site Health', 'mainwp' ); ?>" data-position="right center" data-inverted="" target="_blank"><span class="ui <?php echo $h_color; ?> empty circular label"></span></a> <?php echo esc_html( $h_text ); ?></span></td>
			<?php } elseif ( 'site_actions' === $column_name ) { ?>
					<td class="collapsing">
						<div class="ui left pointing dropdown icon mini basic green button" style="z-index: 999;">
							<i class="ellipsis horizontal icon"></i>
							<div class="menu">
							<a class="managesites_checknow item" href="#"><?php esc_html_e( 'Check Now', 'mainwp' ); ?></a>
					<?php if ( '' == $website['sync_errors'] ) : ?>							
							<a class="managesites_syncdata item" href="#"><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
							<?php endif; ?>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'access_individual_dashboard' ) ) : ?>
							<a class="item" href="admin.php?page=managesites&dashboard=<?php echo $website['id']; ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
							<?php endif; ?>
							</div>
						</div>
					</td>
				<?php
			} elseif ( method_exists( $this, 'column_' . $column_name ) ) {
				echo "<td $attributes>";
				echo call_user_func( array( $this, 'column_' . $column_name ), $website );
				echo '</td>';
			} else {
				echo "<td $attributes>";
				echo $this->column_default( $website, $column_name );
				echo '</td>';
			}
		}
	}

	/**
	 * Get table rows.
	 *
	 * Optimize for shared hosting or big networks.
	 *
	 * @return array Table rows HTML.
	 */
	public function ajax_get_datatable_rows() { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$all_rows  = array();
		$info_rows = array();

		$http_error_codes = MainWP_Utility::get_http_codes();

		$use_favi = get_option( 'mainwp_use_favicon', 1 );
		if ( $this->items ) {
			foreach ( $this->items as $website ) {
				$rw_classes = '';

				$statusUndefined = ( '' == $website['http_response_code'] );
				$statusOnline    = ( 1 == $website['offline_check_result'] );

				$health_status = isset( $website['health_site_status'] ) ? json_decode( $website['health_site_status'], true ) : array();

				$hstatus     = MainWP_Utility::get_site_health( $health_status );
				$hval        = $hstatus['val'];
				$critical    = $hstatus['critical'];
				$good_health = false;

				if ( 80 <= $hval && 0 == $critical ) {
					$good_health = true;
				}

				$rw_classes = trim( $rw_classes );

				$status_classes = $statusOnline ? '' : 'error';
				if ( empty( $status_classes ) ) {
					$status_classes = $good_health ? '' : 'warning';
				}

				$rw_classes = 'child-site mainwp-child-site-' . $website['id'] . ' ' . $status_classes . ' ' . $rw_classes;

				$info_item = array(
					'rowClass'  => esc_html( $rw_classes ),
					'siteID'    => $website['id'],
					'siteUrl'   => $website['url'],
					'syncError' => ( '' !== $website['sync_errors'] ? true : false ),
				);

				if ( $good_health ) {
					$h_color = 'green';
					$h_text  = __( 'Good', 'mainwp' );
				} else {
					$h_color = 'orange';
					$h_text  = __( 'Should be improved', 'mainwp' );
				}

				$columns = $this->get_columns();

				$cols_data = array();

				foreach ( $columns as $column_name => $column_display_name ) {
					$default_classes = esc_html( "collapsing $column_name column-$column_name" );
					ob_start();
					?>
						<?php if ( 'cb' === $column_name ) { ?>
							<div class="ui checkbox"><input type="checkbox" value="<?php echo $website['id']; ?>" /></div>
							<?php } elseif ( 'status' === $column_name ) { ?>
								<?php if ( $statusUndefined ) : ?>
									<span data-tooltip="<?php esc_attr_e( 'The site status appears to be undefined.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><a href="#"><i class="circular inverted exclamation red icon"></i></a></span>
									<?php
								elseif ( $statusOnline ) :
									if ( ! $good_health ) {
										?>
									<span data-tooltip="<?php esc_attr_e( 'The site status appears to be online.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><i class="circular inverted yellow heartbeat icon"></i></span>
										<?php
									} else {
										?>
									<span data-tooltip="<?php esc_attr_e( 'The site status appears to be online.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><i class="circular green check inverted icon"></i></span>
										<?php
									}
									?>
								<?php else : ?>
									<span data-tooltip="<?php esc_attr_e( 'The site status appears to be offline.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><a href="#"><i class="circular inverted exclamation red icon"></i></a></span>
								<?php endif; ?>
							<?php } elseif ( 'site' === $column_name ) { ?>
								<a href="<?php echo 'admin.php?page=managesites&dashboard=' . $website['id']; ?>" data-tooltip="<?php esc_attr_e( 'Open the site overview', 'mainwp' ); ?>" data-position="right center"  data-inverted=""><?php echo stripslashes( $website['name'] ); ?></a><i class="ui active inline loader tiny" style="display:none"></i><span id="site-status-<?php echo esc_attr( $website['id'] ); ?>" class="status hidden"></span>
							<?php } elseif ( 'login' === $column_name ) { ?>
								<?php if ( ! mainwp_current_user_have_right( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
									<i class="sign in icon"></i>
								<?php else : ?>
									<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website['id']; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="right center"  data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
								<?php endif; ?>
								<?php
							} elseif ( 'url' === $column_name ) {
								$imgfavi = '';
								if ( $use_favi ) {
									$siteObj  = (object) $website;
									$favi_url = MainWP_Connect::get_favico_url( $siteObj );
									$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
								}
								echo $imgfavi;
								?>
								<a href="<?php echo esc_url( $website['url'] ); ?>" class="mainwp-may-hide-referrer open_site_url" target="_blank"><?php echo esc_html( $website['url'] ); ?></a>
								<?php
							} elseif ( 'status_code' === $column_name ) {
								if ( $website['http_response_code'] ) {
									$code = $website['http_response_code'];
									echo esc_html( $code );
									if ( isset( $http_error_codes[ $code ] ) ) {
										echo ' - ' . $http_error_codes[ $code ];
									}
								}
							} elseif ( 'last_check' === $column_name ) {
								?>
								<?php echo 0 != $website['offline_checks_last'] ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website['offline_checks_last'] ) ) : ''; ?>
							<?php } elseif ( 'site_health' === $column_name ) { ?>
									<span><a class="open_newwindow_wpadmin" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website['id'] ); ?>&location=<?php echo base64_encode( 'site-health.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>" data-tooltip="<?php echo esc_html__( 'Jump to the Site Health', 'mainwp' ); ?>" data-position="right center" data-inverted="" target="_blank"><span class="ui <?php echo $h_color; ?> empty circular label"></span></a> <?php echo esc_html( $h_text ); ?></span>
									<?php
							} elseif ( 'site_actions' === $column_name ) {
								?>
									<div class="ui left pointing dropdown icon mini basic green button" style="z-index: 999;">
										<i class="ellipsis horizontal icon"></i>
										<div class="menu">
											<a class="managesites_checknow item" href="#"><?php esc_html_e( 'Check Now', 'mainwp' ); ?></a>
											<?php if ( '' == $website['sync_errors'] ) : ?>											
											<a class="managesites_syncdata item" href="#"><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
											<?php endif; ?>
											<?php if ( mainwp_current_user_have_right( 'dashboard', 'access_individual_dashboard' ) ) : ?>
											<a class="item" href="admin.php?page=managesites&dashboard=<?php echo $website['id']; ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
											<?php endif; ?>
										</div>
									</div>
										<?php
							} elseif ( method_exists( $this, 'column_' . $column_name ) ) {
								echo call_user_func( array( $this, 'column_' . $column_name ), $website );
							} else {
								echo $this->column_default( $website, $column_name );
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

}
