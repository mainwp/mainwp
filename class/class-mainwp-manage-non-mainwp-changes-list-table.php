<?php
/**
 * Manage Non-MainWP changes List Table.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Non_MainWP_Changes_List_Table
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Non_MainWP_Changes_List_Table {

	/**
	 * Public variable to hold Items information.
	 *
	 * @var array
	 */
	public $items;

	/**
	 * Public variable to hold total items number.
	 *
	 * @var integer
	 */
	public $total_items;

	/**
	 * Protected variable to hold columns headers
	 *
	 * @var array
	 */
	protected $column_headers;


	/**
	 * MainWP_Manage_Sites_List_Table constructor.
	 *
	 * Run each time the class is called.
	 * Add action to generate tabletop.
	 */
	public function __construct() {
	}

	/**
	 * Get the default primary column name.
	 *
	 * @return string Child Site name.
	 */
	protected function get_default_primary_column_name() {
		return 'site';
	}


	/**
	 * Get sortable columns.
	 *
	 * @return array $sortable_columns Array of sortable column names.
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(

			'name'        => array( 'name', false ),
			'site'        => array( 'site', false ),
			'action_user' => array( 'action_user', false ),
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
			'cb'          => '',
			'name'        => esc_html__( 'Change', 'mainwp' ),
			'site'        => esc_html__( 'Website', 'mainwp' ),
			'action_user' => esc_html__( 'User', 'mainwp' ),
		);
	}



	/**
	 * Echo the column headers.
	 *
	 * @param bool $optimize true|false Whether or not to optimise.
	 * @param bool $top true|false.
	 */
	public function print_column_headers( $optimize, $top = true ) {
		list( $columns, $sortable, $primary ) = $this->get_column_info();

		$def_columns                 = $this->get_default_columns();
		$def_columns['data_actions'] = '';

		if ( isset( $columns['cb'] ) ) {
			$columns['cb'] = '<div class="ui checkbox"><input id="' . ( $top ? 'cb-select-all-top' : 'cb-select-all-bottom' ) . '" type="checkbox" /></div>';
		}

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

			echo "<$tag $id $class $attr>$column_display_name</$tag>"; // phpcs:ignore WordPress.Security.EscapeOutput
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
	 * Method get_columns()
	 *
	 * Combine all columns.
	 *
	 * @return array $columns Array of column names.
	 */
	public function get_columns() {
		$columns                 = $this->get_default_columns();
		$columns['data_actions'] = '';
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
	 * @return array $defines
	 */
	public function get_columns_defines() {
		$defines   = array();
		$defines[] = array(
			'targets'   => 'manage-cb-column',
			'className' => 'check-column collapsing',
		);
		$defines[] = array(
			'targets'   => array( 'manage-name-column', 'manage-site-column', 'manage-action_user-column', 'manage-data_actions-column' ),
			'className' => 'collapsing',
		);
		$defines[] = array(
			'targets'   => 'no-sort',
			'orderable' => false,
		);
		return $defines;
	}

	/**
	 * Prepare the items to be listed.
	 */
	public function prepare_items() {

		$orderby = '';

		$req_orderby = null;
		$req_order   = null;

		// phpcs:disable WordPress.Security.NonceVerification,Missing,Missing,Missing,Missing,Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_REQUEST['order'] ) ) {
			$columns = isset( $_REQUEST['columns'] ) ? wp_unslash( $_REQUEST['columns'] ) : array();
			$ord_col = isset( $_REQUEST['order'][0]['column'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'][0]['column'] ) ) : '';
			if ( isset( $columns[ $ord_col ] ) ) {
				$req_orderby = isset( $columns[ $ord_col ]['data'] ) ? sanitize_text_field( wp_unslash( $columns[ $ord_col ]['data'] ) ) : '';
				$req_order   = isset( $_REQUEST['order'][0]['dir'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'][0]['dir'] ) ) : '';
			}
		}
		$perPage = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : 25;
		if ( -1 === (int) $perPage ) {
			$perPage = 9999;
		}
		$start  = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;
		$search = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';
		// phpcs:enable

		if ( isset( $req_orderby ) ) {
			if ( 'name' === $req_orderby ) {
				$orderby = 'wa.summary ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
			} elseif ( 'site' === $req_orderby ) {
				$orderby = 'wp.name ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
			} elseif ( 'action_user' === $req_orderby ) {
				$orderby = 'wa.action_user ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
			}
		}

		$params = array(
			'order_by' => $orderby,
			'offset'   => $start,
			'rowcount' => $perPage,
			'dismiss'  => 0,
			'search'   => $search,
		);

		$actions = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );

		$params_total                = $params;
		$params_total['total_count'] = true;
		$totalRecords                = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params_total );

		$this->items       = $actions;
		$this->total_items = $totalRecords;
	}


	/**
	 * Get table rows.
	 *
	 * Optimize for shared hosting or big networks.
	 *
	 * @return array Rows html.
	 */
	public function ajax_get_datatable_rows() {
		$all_rows  = array();
		$info_rows = array();
		if ( $this->items ) {
			foreach ( $this->items as $item ) {

				$data = (object) $item;

				if ( empty( $data->action_user ) || empty( $data->meta_data ) ) {
					continue;
				}

				$columns    = $this->get_columns();
				$rw_classes = 'site-actions site-actions-' . intval( $data->action_id );

				$info_item = array(
					'rowClass' => esc_html( $rw_classes ),
					'siteID'   => $data->wpid,
					'siteUrl'  => $data->url,
					'actionID' => $data->action_id,
				);

				foreach ( $columns as $column_name => $column_display_name ) {
					ob_start();
					?>
					<?php
					if ( 'cb' === $column_name ) {
						?>
						<td class="check-column">
							<div class="ui checkbox"><input type="checkbox" value="<?php echo intval( $data->action_id ); ?>" /></div>
						</td>
						<?php
					} elseif ( 'name' === $column_name ) {
						$meta_data    = json_decode( $data->meta_data );
						$action_class = '';
						if ( 'activated' === $data->action ) {
							$action_class = 'green';
						} elseif ( 'deactivated' === $data->action ) {
							$action_class = 'red';
						} elseif ( 'installed' === $data->action ) {
							$action_class = 'blue';
						}
						?>
						<td class="collapsing">
							<strong><?php echo isset( $meta_data->name ) && '' !== $meta_data->name ? esc_html( $meta_data->name ) : 'WP Core'; ?></strong> <?php echo 'wordpress' !== $data->context ? esc_html( ucfirst( rtrim( $data->context, 's' ) ) ) : 'WordPress'; //phpcs:ignore -- text. ?><br/>
							<div><strong><span class="ui medium <?php echo esc_attr( $action_class ); ?> text"><?php echo esc_html( ucfirst( $data->action ) ); ?></span></strong></div>
							<span class="ui small text"><?php echo esc_html( MainWP_Utility::format_timestamp( $data->created ) ); ?></span>
						</td>
						<?php
					} elseif ( 'site' === $column_name ) {
						?>
						<td class="collapsing"><a href="admin.php?page=managesites&dashboard=<?php echo esc_attr( $data->wpid ); ?>"><?php echo esc_html( $data->name ); ?></a></td>
								<?php
					} elseif ( 'action_user' === $column_name ) {
						?>
						<td class="collapsing"><?php echo esc_html( $data->action_user ); ?></td>
								<?php
					} elseif ( 'data_actions' === $column_name ) {
						?>
						<td class="collapsing no-sort">
								<div class="ui right pointing dropdown icon mini basic green button" style="z-index: 999;">
									<i class="ellipsis horizontal icon"></i>
									<div class="menu"  action-id="<?php echo intval( $data->action_id ); ?>">
										<a class="item non-mainwp-action-row-dismiss" href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Dismiss the change.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a>
									</div>
								</div>
							</td>
							
						<?php
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

		/**
		 * Action: mainwp_before_manage_sites_table
		 *
		 * Fires before the Manage Sites table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_manage_sites_table' );
		?>
		<table id="mainwp-manage-non-mainwp-actions-table" style="width:100%" class="ui single line selectable unstackable table mainwp-with-preview-table mainwp-manage-wpsites-table">
			<thead>
				<tr><?php $this->print_column_headers( $optimize, true ); ?></tr>
			</thead>
			<tfoot>
			<tr><?php $this->print_column_headers( $optimize, false ); ?></tr>
			</tfoot>
		</table>
		<?php
		/**
		 * Action: mainwp_after_manage_sites_table
		 *
		 * Fires after the Manage Sites table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_manage_sites_table' );
		?>
	<div id="mainwp-loading-sites" style="display: none;">
	<div class="ui active inverted dimmer">
	<div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading ...', 'mainwp' ); ?></div>
	</div>
	</div>

		<?php
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
					try {
						jQuery( '#mainwp-sites-table-loader' ).hide();
						$manage_sites_table = jQuery( '#mainwp-manage-non-mainwp-actions-table' ).on( 'processing.dt', function ( e, settings, processing ) {
							jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
							if (!processing) {
								var tb = jQuery( '#mainwp-manage-non-mainwp-actions-table' );
								tb.find( 'th[cell-cls]' ).each( function(){
									var ceIdx = this.cellIndex;
									var cls = jQuery( this ).attr( 'cell-cls' );
									jQuery( '#mainwp-manage-non-mainwp-actions-table tr' ).each(function(){
										jQuery(this).find( 'td:eq(' + ceIdx + ')' ).addClass(cls);
									} );
								} );
								$( '#mainwp-manage-non-mainwp-actions-table .ui.dropdown' ).dropdown();
								$( '#mainwp-manage-non-mainwp-actions-table .ui.checkbox' ).checkbox();
							}
						} ).on( 'column-reorder.dt', function ( e, settings, details ) {
							$( '#mainwp-manage-non-mainwp-actions-table .ui.dropdown' ).dropdown();
							$( '#mainwp-manage-non-mainwp-actions-table .ui.checkbox' ).checkbox();
						} ).DataTable( {
							"ajax": {
								"url": ajaxurl,
								"type": "POST",
								"data":  function ( d ) {
									return $.extend( {}, d, mainwp_secure_data( {
										action: 'mainwp_non_mainwp_changes_display_rows',
									} )
								);
								},
								"dataSrc": function ( json ) {
									for ( var i=0, ien=json.data.length ; i < ien ; i++ ) {
										json.data[i].rowClass = json.rowsInfo[i].rowClass;
										json.data[i].siteID = json.rowsInfo[i].siteID;
										json.data[i].siteUrl = json.rowsInfo[i].siteUrl;
										json.data[i].actionID = json.rowsInfo[i].actionID;
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
							serverSide: true,
							"pageLength": <?php echo intval( $sites_per_page ); ?>,
							"columnDefs": <?php echo wp_json_encode( $this->get_columns_defines() ); ?>,
							"columns": <?php echo wp_json_encode( $this->get_columns_init() ); ?>,
							"language": {
								"emptyTable": "<?php esc_html_e( 'No items found.', 'mainwp' ); ?>"
							},
							"drawCallback": function( settings ) {
								this.api().tables().body().to$().attr( 'id', 'mainwp-manage-sites-body-table' );
								mainwp_datatable_fix_menu_overflow();
								if ( typeof mainwp_preview_init_event !== "undefined" ) {
									mainwp_preview_init_event();
								}
								jQuery( '#mainwp-sites-table-loader' ).hide();
								if ( jQuery('#mainwp-manage-sites-body-table td.dataTables_empty').length > 0 && jQuery('#sites-table-count-empty').length ){
									jQuery('#mainwp-manage-sites-body-table td.dataTables_empty').html(jQuery('#sites-table-count-empty').html());
								}
							},
							"initComplete": function( settings, json ) {
							},
							rowCallback: function (row, data) {
								jQuery( row ).addClass(data.rowClass);
								jQuery( row ).attr( 'site-url', data.siteUrl );
								jQuery( row ).attr( 'siteid', data.siteID );
								jQuery( row ).attr( 'action-id', data.actionID );
								jQuery( row ).attr( 'id', "child-site-" + data.siteID );
							}
						} );
					} catch(err) {
						// to fix js error.
					}		
					setTimeout( function () {
						mainwp_datatable_fix_menu_overflow();
					}, 1000 );
			} );
		</script>
		<?php
	}


	/**
	 * Clear Items.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::is_result()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 */
	public function clear_items() {
		if ( MainWP_DB::is_result( $this->items ) ) {
			MainWP_DB::free_result( $this->items );
		}
	}
}
