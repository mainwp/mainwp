<?php
/**
 * Manage Logs List Table.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;
/**
 * Class Log_List_Table
 *
 * @package MainWP\Dashboard
 */
class Log_List_Table {

	/**
	 * Holds instance of manager object
	 *
	 * @var Log_Manager
	 */
	public $manager;


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
	 * Class constructor.
	 *
	 * Run each time the class is called.
	 *
	 * @param Log_Manager $manager Instance of manager object.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;
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
	 * Returns the column content for the provided item and column.
	 *
	 * @param array  $item         Record data.
	 * @param string $column_name  Column name.
	 * @return string $out Output.
	 */
	public function column_default( $item, $column_name ) {
		$out = '';

		$record = new Log_Record( $item );

		$escaped = false;
		switch ( $column_name ) {
			case 'date':
				$created     = gmdate( 'Y-m-d H:i:s', $record->created );
				$date_string = sprintf(
					'<time datetime="%s" class="relative-time record-created">%s</time>',
					mainwp_module_log_get_iso_8601_extended_date( $record->created ),
					get_date_from_gmt( $created, 'Y/m/d' )
				);
				$out         = get_date_from_gmt( $created, 'Y/m/d' );
				$out        .= '<br />';
				$out        .= get_date_from_gmt( $created, 'h:i:s A' );
				break;

			case 'item':
				$out = $record->item;
				break;

			case 'name':
				$out     = ! empty( $record->log_site_name ) ? '<a href="admin.php?page=managesites&dashboard=' . intval( $record->site_id ) . '">' . esc_html( $record->log_site_name ) . '</a>' : 'N/A';
				$escaped = true;
				break;

			case 'url':
				$out     = ! empty( $record->url ) ? '<a href="' . esc_url( $record->url ) . '" target="_blank">' . esc_html( $record->url ) . '</a>' : 'N/A';
				$escaped = true;
				break;

			case 'user_id':
				$user    = new Log_Author( (int) $record->user_id, (array) $record->user_meta );
				$out     = $user->get_display_name() . sprintf( '<br /><small>%s</small>', $user->get_agent_label( $user->get_agent() ) );
				$escaped = true;
				break;

			case 'context':
				$connector_title = $this->get_term_title( $record->connector, 'connector' );
				$context_title   = $this->get_term_title( $record->context, 'context' );

				$out  = $connector_title;
				$out .= '<br />&#8627;&nbsp;';
				$out .= $context_title;
				break;

			case 'action':
				$out = $this->get_term_title( $record->action, 'action' );
				break;
			case 'state':
				$out = $this->get_state_title( $record );
				break;
			case 'duration':
				$out = $record->duration;
				break;
			default:
		}

		if ( empty( $out ) ) {
			return '';
		}

		if ( ! $escaped ) {
			$allowed_tags         = wp_kses_allowed_html( 'post' );
			$allowed_tags['time'] = array(
				'datetime' => true,
				'class'    => true,
			);

			$allowed_tags['img']['srcset'] = true;

			return wp_kses( $out, $allowed_tags );
		} else {
			return $out; //phpcs:ignore -- escaped.
		}
	}


	/**
	 * Returns the label for a status.
	 *
	 * @param object $record record item.
	 *
	 * @return string
	 */
	public function get_state_title( $record ) {

		$state = esc_html__( 'Undefined', 'mainwp' );
		if ( is_null( $record->state ) ) {
			$state = 'N/A';
		} elseif ( '0' === $record->state ) {
			$state = esc_html__( 'Failed', 'mainwp' );
		} elseif ( '1' === $record->state ) {
			$state = esc_html__( 'Success', 'mainwp' );
		}
		return $state;
	}

	/**
	 * Returns the label for a connector term.
	 *
	 * @param string $term  Connector label type.
	 * @param string $type  Connector term.
	 * @return string
	 */
	public function get_term_title( $term, $type ) {

		if ( ! isset( $this->manager->connectors->term_labels[ 'logs_' . $type ][ $term ] ) ) {
			$title = $term;
		} else {
			$title = $this->manager->connectors->term_labels[ 'logs_' . $type ][ $term ];
		}

		$title = is_string( $title ) ? ucfirst( $title ) : $title;
		return $title;
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array $sortable_columns Array of sortable column names.
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'date'     => array( 'date', false ),
			'name'     => array( 'site', false ),
			'url'      => array( 'site', false ),
			'user_id'  => array( 'user_id', false ),
			'context'  => array( 'context', false ),
			'action'   => array( 'action', false ),
			'state'    => array( 'state', false ),
			'duration' => array( 'duration', false ),
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
			'date'     => esc_html__( 'Date', 'mainwp' ),
			'name'     => esc_html__( 'Site', 'mainwp' ),
			'url'      => esc_html__( 'Url', 'mainwp' ),
			'item'     => esc_html__( 'Item', 'mainwp' ),
			'user_id'  => esc_html__( 'User', 'mainwp' ),
			'context'  => esc_html__( 'Context', 'mainwp' ),
			'action'   => esc_html__( 'Action', 'mainwp' ),
			'state'    => esc_html__( 'State', 'mainwp' ),
			'duration' => esc_html__( 'Duration', 'mainwp' ),
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
			'targets'   => 'no-sort',
			'orderable' => false,
		);
		$defines[] = array(
			'targets'   => 'manage-site-column',
			'className' => 'column-site-bulk mainwp-site-cell',
		);
		$defines[] = array(
			'targets'   => 'manage-url-column',
			'className' => 'mainwp-url-cell',
		);
		$defines[] = array(
			'targets'   => 'manage-date-column',
			'className' => 'mainwp-date-cell',
		);
		$defines[] = array(
			'targets'   => 'manage-state-column',
			'className' => 'mainwp-state-cell',
		);
		$defines[] = array(
			'targets'   => array( 'extra-column' ),
			'className' => 'collapsing',
		);
		return $defines;
	}


	/**
	 * Html output if no Child Sites are connected.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_websites_count()
	 */
	public function no_items() {
		?>
		<div class="ui center aligned segment">
			<i class="globe massive icon"></i>
			<div class="ui header">
				<?php esc_html_e( 'No records found.', 'mainwp' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Method has_items().
	 *
	 * Verify if items exist.
	 */
	public function has_items() {
		return ! empty( $this->items );
	}

	/**
	 * Get last query found rows
	 *
	 * @return integer
	 */
	public function get_total_found_rows() {
		return $this->total_items;
	}

	/**
	 * Prepare the items to be listed.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_DB::num_rows()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function prepare_items() {

		$req_orderby = '';
		$req_order   = null;

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_REQUEST['order'] ) ) {
			$columns = isset( $_REQUEST['columns'] ) ? wp_unslash( $_REQUEST['columns'] ) : array();
			$ord_col = isset( $_REQUEST['order'][0]['column'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'][0]['column'] ) ) : '';
			if ( isset( $columns[ $ord_col ] ) ) {
				$req_orderby = isset( $columns[ $ord_col ]['data'] ) ? sanitize_text_field( wp_unslash( $columns[ $ord_col ]['data'] ) ) : '';
				$req_order   = isset( $_REQUEST['order'][0]['dir'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'][0]['dir'] ) ) : '';
			}
		}

		// phpcs:enable

		 // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$perPage = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : 25;
		if ( -1 === (int) $perPage ) {
			$perPage = 9999;
		}
		$start = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;

		$search = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';

		// phpcs:enable

		$args = array(
			'order'   => ( 'asc' === $req_order ) ? 'asc' : 'desc',
			'orderby' => $req_orderby,
			'start'   => $start,
			'search'  => $search,
		);

		$args['records_per_page'] = $perPage ? $perPage : 20;

		$this->items       = $this->manager->db->get_records( $args );
		$this->total_items = $this->manager->db->get_found_records_count();
	}


	/**
	 * Display the table.
	 */
	public function display() {

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
		<table id="mainwp-module-log-records-table" style="width:100%" class="ui single line selectable unstackable table mainwp-with-preview-table">
			<thead>
				<tr><?php $this->print_column_headers( true ); ?></tr>
			</thead>
			<tfoot>
				<tr><?php $this->print_column_headers( false ); ?></tr>
			</tfoot>
		</table>
		<?php
		$count = $this->get_total_found_rows();
		if ( empty( $count ) ) {
			?>
		<div id="sites-table-count-empty" style="display: none;">
			<?php $this->no_items(); ?>
		</div>
			<?php
		}
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
						$module_log_table = jQuery( '#mainwp-module-log-records-table' ).on( 'processing.dt', function ( e, settings, processing ) {
							jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
							if (!processing) {
								var tb = jQuery( '#mainwp-module-log-records-table' );
								tb.find( 'th[cell-cls]' ).each( function(){
									var ceIdx = this.cellIndex;
									var cls = jQuery( this ).attr( 'cell-cls' );
									jQuery( '#mainwp-module-log-records-table tr' ).each(function(){
										jQuery(this).find( 'td:eq(' + ceIdx + ')' ).addClass(cls);
									} );
								} );
								$( '#mainwp-module-log-records-table .ui.dropdown' ).dropdown();
								$( '#mainwp-module-log-records-table .ui.checkbox' ).checkbox();
							}
						} ).on( 'column-reorder.dt', function ( e, settings, details ) {
							$( '#mainwp-module-log-records-table .ui.dropdown' ).dropdown();
							$( '#mainwp-module-log-records-table .ui.checkbox' ).checkbox();
						} ).DataTable( {
							"ajax": {
								"url": ajaxurl,
								"type": "POST",
								"data":  function ( d ) {
									return $.extend( {}, d, mainwp_secure_data( {
										action: 'mainwp_module_log_display_rows',
									} )
								);
								},
								"dataSrc": function ( json ) {
									for ( var i=0, ien=json.data.length ; i < ien ; i++ ) {
										json.data[i].rowClass = json.rowsInfo[i].rowClass;
										json.data[i].log_id = json.rowsInfo[i].log_id;
										json.data[i].created_sort = json.rowsInfo[i].created;
										json.data[i].state_sort = json.rowsInfo[i].state;
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
							"columns": <?php echo wp_json_encode( $this->get_columns_init() ); ?>,
							"language": {
								"emptyTable": "<?php esc_html_e( 'No websites found.', 'mainwp' ); ?>"
							},
							"drawCallback": function( settings ) {
								this.api().tables().body().to$().attr( 'id', 'mainwp-module-log-records-body-table' );
								mainwp_datatable_fix_menu_overflow();
								if ( typeof mainwp_preview_init_event !== "undefined" ) {
									mainwp_preview_init_event();
								}
								jQuery( '#mainwp-sites-table-loader' ).hide();
								if ( jQuery('#mainwp-module-log-records-body-table td.dataTables_empty').length > 0 && jQuery('#sites-table-count-empty').length ){
									jQuery('#mainwp-module-log-records-body-table td.dataTables_empty').html(jQuery('#sites-table-count-empty').html());
								}
							},
							"initComplete": function( settings, json ) {
							},
							rowCallback: function (row, data) {
								jQuery( row ).addClass(data.rowClass);
								jQuery( row ).attr( 'id', "log-row-" + data.log_id );
								jQuery( row ).find('.mainwp-date-cell').attr('data-sort', data.created_sort );
								jQuery( row ).find('.mainwp-state-cell').attr('data-sort', data.state_sort );
							}
						} );
					} catch(err) {
						// to fix js error.
					}			
			} );

			mainwp_module_log_records_filter = function() {
				try {
					$module_log_table.ajax.reload();
				} catch(err) {
					// to fix js error.
				}
			};			
		</script>
		<?php
	}

	/**
	 * Get the column count.
	 *
	 * @return int Column Count.
	 */
	public function get_column_count() {
		list( $columns ) = $this->get_column_info();
		return count( $columns );
	}

	/**
	 * Echo the column headers.
	 */
	public function print_column_headers() {
		list( $columns, $sortable, $primary ) = $this->get_column_info();

		$def_columns                 = $this->get_default_columns();
		$def_columns['site_actions'] = '';

		foreach ( $columns as $column_key => $column_display_name ) {

			$class = array( 'manage-' . $column_key . '-column' );
			$attr  = '';
			if ( ! isset( $def_columns[ $column_key ] ) ) {
				$class[]  = 'extra-column';
					$attr = 'cell-cls="' . esc_html( "collapsing $column_key column-$column_key" ) . '"';
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
	 * Get table rows.
	 *
	 * Optimize for shared hosting or big networks.
	 *
	 * @return array Rows html.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::get_favico_url()
	 * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_options_array()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_http_codes()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize_file_name()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_site_health()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
	 */
	public function ajax_get_datatable_rows() {

		$all_rows  = array();
		$info_rows = array();

		if ( $this->items ) {
			foreach ( $this->items as $log ) {
				$rw_classes = 'log-item mainwp-log-item-' . intval( $log->log_id );

				$info_item = array(
					'rowClass' => esc_html( $rw_classes ),
					'log_id'   => $log->log_id,
					'site_id'  => ! empty( $log->site_id ) ? $log->site_id : 0,
					'created'  => $log->created,
					'state'    => is_null( $log->state ) ? - 1 : $log->state,
				);

				$columns = $this->get_columns();

				$cols_data = array();

				foreach ( $columns as $column_name => $column_display_name ) {
					ob_start();
					echo $this->column_default( $log, $column_name ); // phpcs:ignore WordPress.Security.EscapeOutput
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
