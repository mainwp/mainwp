<?php
/**
 * Clients List Table.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_List_Table
 *
 * @package MainWP\Dashboard
 *
 * MainWP sites client list.
 *
 * @todo The only variables that seam to be used are $column_headers.
 *
 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_List_Table
 */
class MainWP_Client_List_Table extends MainWP_Manage_Sites_List_Table {

	/**
	 * Protected variable to hold columns headers
	 *
	 * @var array
	 */
	protected $column_headers;

	/**
	 * MainWP_Client_List_Table constructor.
	 *
	 * Run each time the class is called.
	 * Add action to generate tabletop.
	 */
	public function __construct() {
		add_action( 'mainwp_manageclients_tabletop', array( &$this, 'generate_tabletop' ) );
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
	public function column_default( $item, $column_name ) { // phpcs:ignore -- comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Filter: mainwp_clients_sitestable_item
		 *
		 * Filters the Clients table column items. Allows user to create new column item.
		 *
		 * @param array $item Array containing child site data.
		 *
		 * @since 4.1
		 */
		$item = apply_filters( 'mainwp_clients_sitestable_item', $item, $item );
		switch ( $column_name ) {
			case 'name':
			case 'client_email':
			case 'client_phone':
			case 'client_facebook':
			case 'client_twitter':
			case 'client_instagram':
			case 'client_linkedin':
			case 'websites':
			case 'tags':
			case 'suspended':
			case 'contact_name':
			case 'address_1':
			case 'address_2':
			case 'city':
			case 'zip':
			case 'state':
			case 'country':
			case 'note':
			default:
				return isset( $item[ $column_name ] ) && ! empty( $item[ $column_name ] ) ? $item[ $column_name ] : 'N/A';
		}
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array $sortable_columns Array of sortable column names.
	 */
	public function get_sortable_columns() {

		$sortable_columns = array(
			'name'             => array( 'name', false ),
			'client_email'     => array( 'client_email', false ),
			'client_phone'     => array( 'client_phone', false ),
			'client_facebook'  => array( 'client_facebook', false ),
			'client_twitter'   => array( 'client_twitter', false ),
			'client_instagram' => array( 'client_instagram', false ),
			'client_linkedin'  => array( 'client_linkedin', false ),
			'suspended'        => array( 'suspended', false ),
			'tags'             => array( 'tags', false ),
			'websites'         => array( 'websites', false ),
			'contact_name'     => array( 'contact_name', false ),
			'address_1'        => array( 'address_1', false ),
			'address_2'        => array( 'address_2', false ),
			'city'             => array( 'city', false ),
			'zip'              => array( 'zip', false ),
			'state'            => array( 'state', false ),
			'country'          => array( 'country', false ),
		);
		return $sortable_columns;
	}

	/**
	 * Gets default columns.
	 *
	 * @return array Array of default column names.
	 */
	public function get_default_columns() {
		return array(
			'cb'               => '<input type="checkbox" />',
			'name'             => __( 'Client', 'mainwp' ),
			'tags'             => __( 'Tags', 'mainwp' ),
			'contact_name'     => __( 'Contact Name', 'mainwp' ),
			'client_email'     => __( 'Client Email', 'mainwp' ),
			'suspended'        => __( 'Status', 'mainwp' ),
			'client_phone'     => __( 'Phone', 'mainwp' ),
			'client_facebook'  => __( 'Facebook', 'mainwp' ),
			'client_twitter'   => __( 'Twitter', 'mainwp' ),
			'client_instagram' => __( 'Instagram', 'mainwp' ),
			'client_linkedin'  => __( 'LinkedIn', 'mainwp' ),
			'websites'         => __( 'Websites', 'mainwp' ),
			'address_1'        => __( 'Address 1', 'mainwp' ),
			'address_2'        => __( 'Address 2', 'mainwp' ),
			'city'             => __( 'City', 'mainwp' ),
			'zip'              => __( 'Zip', 'mainwp' ),
			'state'            => __( 'State', 'mainwp' ),
			'country'          => __( 'Country', 'mainwp' ),
			'notes'            => __( 'Notes', 'mainwp' ),
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
		 * Filter: mainwp_clients_sitestable_getcolumns
		 *
		 * Filters the Clients table columns. Allows user to create a new column.
		 *
		 * @param array $columns Array containing table columns.
		 *
		 * @since 4.1
		 */
		$columns = apply_filters( 'mainwp_clients_sitestable_getcolumns', $columns, $columns );

		$columns['client_actions'] = '';

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
	 * @return array $actions Return actions through the mainwp_manageclients_bulk_actions filter.
	 */
	public function get_bulk_actions() {

		$actions = array(
			'delete' => __( 'Delete', 'mainwp' ),
		);

		/**
		 * Filter: mainwp_manageclients_bulk_actions
		 *
		 * Filters bulk actions on the Clients page. Allows user to hook in new actions or remove default ones.
		 *
		 * @since 4.1
		 */
		return apply_filters( 'mainwp_manageclients_bulk_actions', $actions );
	}

	/**
	 * Render manage sites table top.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_for_manage_sites()
	 */
	public function render_manage_sites_table_top() {
		$items_bulk = $this->get_bulk_actions();

		?>
		<div class="ui grid">
			<div class="equal width row ui mini form">
			<div class="middle aligned column">
					<div id="mainwp-clients-bulk-actions-menu" class="ui selection dropdown">
						<div class="default text"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></div>
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
					<button class="ui tiny basic button" id="mainwp-do-clients-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
				</div>
				<div class="right aligned middle aligned column">
				</div>
		</div>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
			} );
		</script>
		<?php
	}


	/**
	 * Prepair the items to be listed.
	 *
	 * @param bool $optimize true|false Whether or not to optimize.
	 */
	public function prepare_items( $optimize = false ) {

		$params = array(
			'with_selected_sites' => true,
			'with_tags'           => true,
		);

		if ( isset( $_GET['tags'] ) && ! empty( $_GET['tags'] ) ) {
			$tags = sanitize_text_field( wp_unslash( $_GET['tags'] ) );
			if ( ! empty( $tags ) ) {
				$tags              = explode( ';', $tags );
				$params['by_tags'] = array_filter( $tags );
			}
		}

		$clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all', null, ARRAY_A, $params );

		$totalRecords = ( $clients ? count( $clients ) : 0 );

		// for compatible.
		$optimize = $optimize ? true : false;

		$this->items       = $clients;
		$this->total_items = $totalRecords;
	}

	/**
	 * Display the table.
	 *
	 * @param bool $optimize true|false Whether or not to optimize.
	 */
	public function display( $optimize = false ) {

		// for compatible.
		$optimize = $optimize ? true : false;

		$sites_per_page = get_option( 'mainwp_default_manage_clients_per_page', 25 );

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
		<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-client-info-message' ) ) : ?>
			<div class="ui info message">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-client-info-message"></i>
				<?php echo sprintf( __( 'Manage your clients.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/manage-clients/" target="_blank">', '</a>' ); ?>
			</div>
		<?php endif; ?>
		<table id="mainwp-manage-sites-table" style="width:100%" class="ui single line selectable unstackable table mainwp-with-preview-table">
			<thead>
				<tr>
					<?php $this->print_column_headers( $optimize, true ); ?>
				</tr>
			</thead>
			<tbody id="mainwp-manage-sites-body-table">
				<?php $this->display_rows_or_placeholder(); ?>
			</tbody>
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
		<?php MainWP_UI::render_modal_edit_notes( 'client' ); ?>
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
			'scrollX'       => 'true',
			'responsive'    => 'true',
		);

		/**
		 * Filter: mainwp_clients_table_features
		 *
		 * Filter the Clients table features.
		 *
		 * @since 4.1
		 */
		$table_features = apply_filters( 'mainwp_clients_table_features', $table_features );
		?>
	<script type="text/javascript">	
			jQuery( document ).ready( function( $ ) {

				mainwp_manage_clients_screen_options = function () {
					jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( {
						allowMultiple: true,
						onHide: function () {
							var val = jQuery( '#mainwp_default_manage_clients_per_page' ).val();
							var saved = jQuery( '#mainwp_default_manage_clients_per_page' ).attr( 'saved-value' );
							if ( saved != val ) {
								jQuery( '#mainwp-manage-sites-table' ).DataTable().page.len( val );
								jQuery( '#mainwp-manage-sites-table' ).DataTable().state.save();
							}
						}
					} ).modal( 'show' );

					jQuery( '#manage-sites-screen-options-form' ).submit( function() {
						if ( jQuery('input[name=reset_manageclients_columns_order]').attr('value') == 1 ) {
							$manage_sites_table.colReorder.reset();
						}					
						jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( 'hide' );
					} );
					return false;
				};

				var responsive = <?php echo $table_features['responsive']; ?>;
				if( jQuery( window ).width() > 1140 ) {
					responsive = false;
				}

				try {
					$manage_sites_table = jQuery( '#mainwp-manage-sites-table' ).DataTable( {
						"responsive" : responsive,
						"searching" : <?php echo $table_features['searching']; ?>,
						"paging" : <?php echo $table_features['paging']; ?>,
						"pagingType" : <?php echo $table_features['pagingType']; ?>,
						"info" : <?php echo $table_features['info']; ?>,
						"colReorder" : <?php echo $table_features['colReorder']; ?>,
						"stateSave" : <?php echo $table_features['stateSave']; ?>,
						"stateDuration" : <?php echo $table_features['stateDuration']; ?>,
						"order" : <?php echo $table_features['order']; ?>,
						"scrollX" : <?php echo $table_features['scrollX']; ?>,
						"columnDefs": [
							{
								"targets": 'no-sort',
								"orderable": false
							},
							{
								"targets": 'manage-site-column',
								"type": 'natural-nohtml'
							},
							<?php do_action( 'mainwp_manage_sites_table_columns_defs' ); ?>
						],
						"lengthMenu" : [ [<?php echo $pagelength_val; ?>, -1 ], [<?php echo $pagelength_title; ?>, "All" ] ],
						"pageLength": <?php echo intval( $sites_per_page ); ?>
					} );
				} catch(err) {
					// to fix js error.
				}

				mainwp_datatable_fix_menu_overflow();		
				_init_manage_sites_screen = function() {
					jQuery( '#mainwp-manage-sites-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
						var col_id = jQuery( this ).attr( 'id' );
						col_id = col_id.replace( "mainwp_show_column_", "" );
						try {	
							$manage_sites_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
						} catch(err) {
							// to fix js error.
						}
					} );
				};
				_init_manage_sites_screen();
			} );
			</script>
		<?php
	}

	/**
	 * Echo the column headers.
	 *
	 * @param bool $optimize true|false Whether or not to optimize.
	 * @param bool $top true|false.
	 */
	public function print_column_headers( $optimize, $top = true ) {
		// for compatible.
		$optimize = $optimize ? true : false;

		list( $columns, $sortable, $primary ) = $this->get_column_info();

		if ( ! empty( $columns['cb'] ) ) {
			$columns['cb'] = '<div class="ui checkbox"><input id="' . ( $top ? 'cb-select-all-top' : 'cb-select-all-bottom' ) . '" type="checkbox" /></div>';
		}

		$def_columns                   = $this->get_default_columns();
		$def_columns['client_actions'] = '';

		foreach ( $columns as $column_key => $column_display_name ) {

			$class = array( 'manage-' . $column_key . '-column' );
			$attr  = '';
			if ( ! isset( $def_columns[ $column_key ] ) ) {
				$class[] = 'extra-column';

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
	 * Fetch single row item.
	 *
	 * @return mixed Single Row Item.
	 */
	public function display_rows() {
		if ( $this->items ) {
			foreach ( $this->items as $item ) {
				$this->single_row( $item );
			}
		}
	}


	/**
	 * Single row.
	 *
	 * @param mixed $item Object containing the client info.
	 */
	public function single_row( $item ) {
		echo '<tr id="client-site-' . $item['client_id'] . '"  clientid=' . $item['client_id'] . ' >';
		$this->single_row_columns( $item );
		echo '</tr>';
	}


	/**
	 * Columns for a single row.
	 *
	 * @param mixed $item     Object containing the client info.
	 * @param bool  $compatible to compatible param - DO NOT remove.
	 */
	protected function single_row_columns( $item, $compatible = true ) { // phpcs:ignore -- comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		list( $columns ) = $this->get_column_info();

		foreach ( $columns as $column_name => $column_display_name ) {

			$classes = "collapsing $column_name column-$column_name";

			if ( 'client_actions' === $column_name ) {
				$classes .= ' center aligned ';
			}

			$attributes = "class='$classes'";
			?>
			<?php if ( 'cb' === $column_name ) { ?>
				<td class="check-column">
					<div class="ui checkbox">
						<input type="checkbox" value="<?php echo intval( $item['client_id'] ); ?>" name=""/>
					</div>
				</td>
				<?php
			} elseif ( 'client_actions' === $column_name ) {
				$selected_sites = isset( $item['selected_sites'] ) ? trim( $item['selected_sites'] ) : '';
				?>
					<td class="collapsing">
						<div class="ui right pointing dropdown icon mini basic green button" style="z-index:999;">
							<i class="ellipsis horizontal icon"></i>
							<div class="menu" clientid=<?php echo intval( $item['client_id'] ); ?>>
								<a class="item client_getedit" href="admin.php?page=ClientAddNew&client_id=<?php echo intval( $item['client_id'] ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
								<a class="item" href="admin.php?page=managesites&client=<?php echo intval( $item['client_id'] ); ?>"><?php esc_html_e( 'View Sites', 'mainwp' ); ?></a>
								<?php if ( is_plugin_active( 'mainwp-pro-reports-extension/mainwp-pro-reports-extension.php' ) ) { ?>
									<a class="item" href="admin.php?page=Extensions-Mainwp-Pro-Reports-Extension&tab=report&action=newreport&selected_sites=<?php echo esc_html( $selected_sites ); ?>"><?php esc_html_e( 'Create Report', 'mainwp' ); ?></a>
								<?php } ?>
								<a class="item client_deleteitem" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							</div>
						</div>
					</td>
				<?php
			} elseif ( 'name' === $column_name ) {
				echo "<td $attributes>";
				?>
				<a class="item" href="admin.php?page=ManageClients&client_id=<?php echo intval( $item['client_id'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
				<?php
				echo '</td>';
			} elseif ( 'client_email' === $column_name ) {
				echo "<td $attributes>";
				?>
				<a class="item" href="admin.php?page=ClientAddNew&client_id=<?php echo intval( $item['client_id'] ); ?>"><?php echo esc_html( $item['client_email'] ); ?></a>
				<?php
				echo '</td>';
			} elseif ( 'tags' === $column_name ) {
				?>
				<td class="collapsing"><?php echo MainWP_System_Utility::get_site_tags( $item, true ); ?></td>
				<?php
			} elseif ( 'suspended' === $column_name ) {
				?>
				<td class="collapsing"><?php echo ( 1 === intval( $item['suspended'] ) ) ? '<span class="ui red mini label">' . __( 'Suspended', 'mainwp' ) . '</span>' : '<span class="ui green mini label">' . __( 'Active', 'mainwp' ) . '</span>'; ?></td>
				<?php
			} elseif ( 'websites' === $column_name ) {
				$selected_sites = isset( $item['selected_sites'] ) ? trim( $item['selected_sites'] ) : '';
				$selected_ids   = ( '' != $selected_sites ) ? explode( ',', $selected_sites ) : array();

				$count = count( $selected_ids );
				echo "<td $attributes>";
				?>
				<a class="item" href="admin.php?page=managesites&client=<?php echo intval( $item['client_id'] ); ?>"><?php echo intval( $count ); ?></a>
				<?php
				echo '</td>';
			} elseif ( 'notes' === $column_name ) {

				$note       = html_entity_decode( $item['note'] );
				$esc_note   = MainWP_Utility::esc_content( $note );
				$strip_note = wp_strip_all_tags( $esc_note );

				$col_class = 'collapsing center aligned';
				echo "<td $attributes>";
				if ( '' == $item['note'] ) :
					?>
					<a href="javascript:void(0)" class="mainwp-edit-client-note" id="mainwp-notes-<?php echo $item['client_id']; ?>" data-tooltip="<?php esc_attr_e( 'Edit client notes.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="sticky note outline icon"></i></a>
				<?php else : ?>
					<a href="javascript:void(0)" class="mainwp-edit-client-note" id="mainwp-notes-<?php echo $item['client_id']; ?>" data-tooltip="<?php echo substr( wp_unslash( $strip_note ), 0, 100 ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
				<?php endif; ?>
				<span style="display: none" id="mainwp-notes-<?php echo $item['client_id']; ?>-note"><?php echo wp_unslash( $esc_note ); ?></span>
				<?php
				echo '</td>';
			} elseif ( method_exists( $this, 'column_' . $column_name ) ) {
				echo "<td $attributes>";
				echo call_user_func( array( $this, 'column_' . $column_name ), $item );
				echo '</td>';
			} else {
				echo "<td $attributes>";
				echo $this->column_default( $item, $column_name );
				echo '</td>';
			}
		}

		if ( ! $compatible ) {
			$compatible = true;
		}
	}

}
