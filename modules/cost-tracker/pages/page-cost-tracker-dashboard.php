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
use function MainWP\Dashboard\mainwp_current_user_have_right;
use function MainWP\Dashboard\mainwp_do_not_have_permissions;

/**
 * Class Cost_Tracker_Dashboard
 */
class Cost_Tracker_Dashboard {

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
	 * Renders overview.
	 *
	 * When the page loads render the body content.
	 */
	public function render_overview_page() {

		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_cost_tracker' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage cost tracker', 'mainwp' ) );
			return;
		}

		Cost_Tracker_Admin::render_header();
		$subscriptions_data = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'all' );
		?>
		<div id="mainwp-module-cost-tracker-dashboard-tab">
			<?php $this->render_actions_bar(); ?>
				<div class="ui segment">					
				<?php $this->render_dashboard_body( $subscriptions_data ); ?>
				</div>
			</div>
		<?php
	}

	/**
	 * Method render_costs_summary()
	 */
	public function render_costs_summary() {
		$lifetime_costs = 0;
		$monthly_costs  = 0;
		$yearly_costs   = 0;
		?>
		<div class="ui equal width grid" id="mainwp-module-cost-tracker-dashboard-summary">
			<div class="center aligned middle aligned column">
				<div class="ui mini vertical statistic">
					<div class="label">
						<?php esc_html_e( 'Lifetime Deals', 'mainwp' ); ?>		
					</div>
					<div class="value loading-lifetime-costs-value">
						<?php Cost_Tracker_Utility::cost_tracker_format_price( $lifetime_costs ); ?>				
					</div>
				</div>
			</div>
			<div class="center aligned middle aligned column">
				<div class="ui mini vertical statistic">
					<div class="label">
						<?php esc_html_e( 'Monthly Costs', 'mainwp' ); ?>		
					</div>
					<div class="value loading-monthly-costs-value">
						<?php Cost_Tracker_Utility::cost_tracker_format_price( $monthly_costs ); ?>
					</div>
				</div>
			</div>
			<div class="center aligned middle aligned column">
				<div class="ui mini vertical statistic">
					<div class="label">
						<?php esc_html_e( 'Yearly Costs', 'mainwp' ); ?>	
					</div>
					<div class="value loading-yearly-costs-value">
						<?php Cost_Tracker_Utility::cost_tracker_format_price( $yearly_costs ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Method ajax_cost_tracker_delete()
	 *
	 * Post handler for save notes.
	 */
	public function ajax_cost_tracker_delete() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_delete' );
		$sub_id = isset( $_POST['sub_id'] ) ? intval( $_POST['sub_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
	 * Render the Dashbaord tab
	 *
	 * Renders the dashbaord tab content - Subscription table
	 *
	 * @param array $subscriptions Subscriptions.
	 */
	public function render_dashboard_body( $subscriptions ) {
		$_orderby = 'name';
		$_order   = 'desc';

		self::$order   = $_order;
		self::$orderby = $_orderby;
		$output        = array();
		?>
		<table class="ui single line table" id="mainwp-module-cost-tracker-sites-table" style="width:100%">
			<thead>
				<tr>
					<th class="no-sort collapsing check-column"><span class="ui checkbox"><input type="checkbox"></span></th>
					<th id="name" class="collapsing"><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
					<th id="type" class="collapsing"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
					<th id="product-type" class="collapsing"><?php esc_html_e( 'Product Type', 'mainwp' ); ?></th>
					<th id="license-type" class="collapsing"><?php esc_html_e( 'License Type', 'mainwp' ); ?></th>
					<th id="price" class="collapsing"><?php esc_html_e( 'Price', 'mainwp' ); ?></th>
					<th id="status" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th id="last-renewal" class="collapsing"><?php esc_html_e( 'Purchased at', 'mainwp' ); ?></th>
					<th id="next-renewal" class="collapsing"><?php esc_html_e( 'Renews at', 'mainwp' ); ?></th>
					<th id="payment-method" class="collapsing"><?php esc_html_e( 'Payment method', 'mainwp' ); ?></th>
					<th id="note" class="collapsing center aligned"><i class="sticky note outline icon"></i></th>
					<th id="sites" class="collapsing center aligned"><?php esc_html_e( 'Sites', 'mainwp' ); ?></th>
					<th id="actions" class="no-sort collapsing right aligned"></th>
				</tr>
			</thead>
			<tbody>
				<?php $this->get_dashboard_table_row( $subscriptions, $output ); ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="no-sort collapsing check-column"><span class="ui checkbox"><input type="checkbox"></span></th>
					<th id="name" class="collapsing"><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
					<th id="type" class="collapsing"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
					<th id="product-type" class="collapsing"><?php esc_html_e( 'Product Type', 'mainwp' ); ?></th>
					<th id="license-type" class="collapsing"><?php esc_html_e( 'License Type', 'mainwp' ); ?></th>
					<th id="price" class="collapsing"><?php esc_html_e( 'Price', 'mainwp' ); ?></th>
					<th id="status" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th id="last-renewal" class="collapsing"><?php esc_html_e( 'Purchased at', 'mainwp' ); ?></th>
					<th id="next-renewal" class="collapsing"><?php esc_html_e( 'Renews at', 'mainwp' ); ?></th>
					<th id="payment-method" class="collapsing"><?php esc_html_e( 'Payment method', 'mainwp' ); ?></th>
					<th id="note" class="collapsing"><i class="sticky note outline icon"></i></th>
					<th id="sites" class="collapsing"><?php esc_html_e( 'Sites', 'mainwp' ); ?></th>
					<th id="actions" class="no-sort collapsing right aligned"></th>
				</tr>
			</tfoot>
		</table>
		<?php

		$lifetime_costs = isset( $output['lifetime_costs'] ) ? (float) $output['lifetime_costs'] : 0;
		$monthly_costs  = isset( $output['monthly_costs'] ) ? (float) $output['monthly_costs'] : 0;
		$yearly_costs   = isset( $output['yearly_costs'] ) ? (float) $output['yearly_costs'] : 0;

		?>
		<?php self::render_modal_edit_notes(); ?>
		<?php self::render_screen_options(); ?>
		<script type="text/javascript">
		jQuery( document ).ready( function () {
			$subscription_sites_table = jQuery( '#mainwp-module-cost-tracker-sites-table' ).DataTable( {
				"stateSave": true,
				"stateDuration": 0,
				"scrollX": true,
				"colReorder" : {
					fixedColumnsLeft: 1,
					fixedColumnsRight: 1
				},
				"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
				"columnDefs": [ { "targets": 'no-sort', "orderable": false } ],
				"order": [ [ 1, "asc" ] ],
				"language": { "emptyTable": "No subscriptions found." },
				"drawCallback": function( settings ) {
					jQuery( '#mainwp-module-cost-tracker-sites-table .ui.checkbox' ).checkbox();
					jQuery( '#mainwp-module-cost-tracker-sites-table .ui.dropdown' ).dropdown();
					mainwp_datatable_fix_menu_overflow();
				},
			} );

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
			_init_cost_tracker_sites_screen();

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
			jQuery('.loading-lifetime-costs-value').html('<?php Cost_Tracker_Utility::cost_tracker_format_price( $lifetime_costs ); //phpcs:ignore -- escaped.  ?>');
			jQuery('.loading-monthly-costs-value').html('<?php Cost_Tracker_Utility::cost_tracker_format_price( $monthly_costs ); //phpcs:ignore -- escaped. ?>');
			jQuery('.loading-yearly-costs-value').html('<?php Cost_Tracker_Utility::cost_tracker_format_price( $yearly_costs ); //phpcs:ignore -- escaped. ?>');
		} );
		</script>
		<?php
	}

	/**
	 * Get Subscription Table Row
	 *
	 * Gets the Subscription dashbaord table row.
	 *
	 * @param array $subscriptions Subscriptions data.
	 * @param array $output Subscriptions output data.
	 */
	public function get_dashboard_table_row( $subscriptions, &$output = array() ) { //phpcs:ignore -- complex method.

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

		foreach ( $subscriptions as $subscription ) {
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

			?>
			<tr class="" item-id="<?php echo esc_html( $subscription->id ); ?>">
				<td class="check-column"><span class="ui checkbox" data-tooltip="<?php esc_attr_e( 'Click to select the site.', 'mainwp' ); ?>" data-inverted="" data-position="right center"><input type="checkbox" name="checked[]"></span></td>
				<td>
					<a class="item" href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $subscription->id ); ?>"><?php echo esc_html( $subscription->name ); ?></a>
				</td>
				<td><?php echo $sub_type_icon; //phpcs:ignore -- escaped. ?> <?php echo 'lifetime' === $subscription->type ? 'Lifetime' : esc_html( ucfirst( $subscription->renewal_type ) ); ?></td>
				<td><?php echo isset( $product_types[ $subscription->product_type ] ) ? esc_html( $product_types[ $subscription->product_type ] ) : 'N/A'; ?></td>
				<td><?php echo isset( $license_types[ $subscription->license_type ] ) ? esc_html( $license_types[ $subscription->license_type ] ) : 'N/A'; ?></td>
				<td class="right aligned"><span class="ui large text"><?php Cost_Tracker_Utility::cost_tracker_format_price( $subscription->price ); ?></span></td>
				<td><?php echo Cost_Tracker_Admin::get_cost_status_label( $subscription->cost_status ); //phpcs:ignore -- escaped. ?></td>
				<td><?php echo $last_renewal ? MainWP_Utility::format_date( $last_renewal ) : ''; //phpcs:ignore -- escaped. ?></td>
				<td><?php Cost_Tracker_Admin::generate_next_renewal( $subscription ); ?></td>
				<td><?php echo isset( $payment_methods[ $subscription->payment_method ] ) ? esc_html( $payment_methods[ $subscription->payment_method ] ) : 'N/A'; ?></td>
				
				<td class="collapsing center aligned">
				<?php if ( empty( $subscription->note ) ) : ?>
						<a href="javascript:void(0)" class="mainwp-edit-sub-note" data-tooltip="<?php esc_attr_e( 'Edit notes.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="sticky note outline icon"></i></a>
					<?php else : ?>
						<a href="javascript:void(0)" class="mainwp-edit-sub-note" data-tooltip="<?php echo esc_attr( substr( wp_unslash( $strip_note ), 0, 100 ) ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
					<?php endif; ?>
						<span style="display: none" id="sub-notes-<?php echo intval( $subscription->id ); ?>-note"><?php echo wp_unslash( $esc_note ); //phpcs:ignore -- escaped. ?></span>
				</td>
				<td class="center aligned"><?php echo ! empty( $url_manage_sites ) ? '<a href="' . esc_url( $url_manage_sites ) . '">' . count( $sub_sites ) . '</a>' : 0; ?></td>
				<td class="right aligned">
					<div class="ui right pointing dropdown icon mini basic green button">
						<a href="javascript:void(0)"><i class="ellipsis horizontal icon"></i></a>
						<div class="menu">
							<a class="item" href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $subscription->id ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
							<a class="item subscription_menu_item_delete" href="javascript:void(0)"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
						</div>
					</div>
				</td>
			</tr>
			<?php
		}

		if ( ! is_array( $output ) ) {
			$output = array();
		}

		$output['lifetime_costs'] = $lifetime_costs;
		$output['monthly_costs']  = $monthly_costs;
		$output['yearly_costs']   = $yearly_costs;
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
	 * Get columns.
	 *
	 * @return array Array of column names.
	 */
	public static function get_columns() {
		return array(
			'name'           => esc_html__( 'Name', 'mainwp' ),
			'type'           => esc_html__( 'Type', 'mainwp' ),
			'product-type'   => esc_html__( 'Product Type', 'mainwp' ),
			'license-type'   => esc_html__( 'License Type', 'mainwp' ),
			'price'          => esc_html__( 'Price', 'mainwp' ),
			'status'         => esc_html__( 'Status', 'mainwp' ),
			'last-renewal'   => esc_html__( 'Last Renewal', 'mainwp' ),
			'next-renewal'   => esc_html__( 'Next Renewal', 'mainwp' ),
			'payment-method' => esc_html__( 'Payment method', 'mainwp' ),
			'note'           => esc_html__( 'Note', 'mainwp' ),
			'sites'          => esc_html__( 'Sites', 'mainwp' ),
			'actions'        => esc_html__( 'Action', 'mainwp' ),
		);
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
						var cols = ['name','type','product-type','price','renewal-time','last-renewal','next-renewal','payment-method','note','tags','actions'];
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
