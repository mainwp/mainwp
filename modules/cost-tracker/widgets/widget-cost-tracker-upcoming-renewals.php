<?php
/**
 * MainWP Upcomming Renewwals Widget
 *
 * Displays the Upcomming Renewwals.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Cost_Tracker_Upcoming_Renewals
 *
 * Displays the Upcomming Renewwals.
 */
class Cost_Tracker_Upcoming_Renewals {

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Return the single instance of the class.
	 *
	 * @return mixed $instance The single instance of the class.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Renders the Upocmming renewals widget data
	 */
	public static function render_top_grid() {
		?>
		<div class="ui grid mainwp-widget-header">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
				<?php
				/**
				 * Filter: mainwp_module_cost_tracker_upcoming_renewals_widget_title
				 *
				 * Filters the widget title text.
				 *
				 * @since 5.0.1
				 */
				echo esc_html( apply_filters( 'mainwp_module_cost_tracker_upcoming_renewals_widget_title', esc_html__( 'Upcoming Renewals', 'mainwp' ) ) );
				?>
				<div class="sub header"><?php esc_html_e( 'Manage your budget proactively with the forthcoming renewals.', 'mainwp' ); ?></div>
				</h3>
			</div>

			<div class="four wide column right aligned">
				<div class="ui dropdown right pointing mainwp-dropdown-tab">
						<div class="text"><?php esc_html_e( 'Today', 'mainwp' ); ?></div>
						<i class="dropdown icon"></i>
						<div class="menu">
							<a class="item upcoming_renewals_today_lnk" data-tab="renewals-today" title="<?php esc_attr_e( 'Today', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Today', 'mainwp' ); ?></a>
							<a class="item upcoming_renewals_tomorrow_lnk" data-tab="renewals-tomorrow" title="<?php esc_attr_e( 'Tomorrow', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Tomorrow', 'mainwp' ); ?></a>
							<a class="item upcoming_renewals_week_lnk" data-tab="renewals-week" title="<?php esc_attr_e( 'This Week', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'This Week', 'mainwp' ); ?></a>
							<a class="item upcoming_renewals_next_week_lnk" data-tab="renewals-next_week" title="<?php esc_attr_e( 'Next Week', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Next Week', 'mainwp' ); ?></a>
							<a class="item upcoming_renewals_month_lnk" data-tab="renewals-month" title="<?php esc_attr_e( 'This Month', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'This Month', 'mainwp' ); ?></a>
							<a class="item upcoming_renewals_next_month_lnk" data-tab="renewals-next_month" title="<?php esc_attr_e( 'Next Month', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Next Month', 'mainwp' ); ?></a>
							<a class="item upcoming_renewals_year_lnk" data-tab="renewals-year" title="<?php esc_attr_e( 'This Year', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'This Year', 'mainwp' ); ?></a>
							<a class="item upcoming_renewals_next_year_lnk" data-tab="renewals-next_year" title="<?php esc_attr_e( 'Next Year', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Next Year', 'mainwp' ); ?></a>
						</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle render costs widget.
	 */
	public function render() {
		$args       = func_get_args();
		$data       = is_array( $args ) && ! empty( $args[1][0] ) && is_array( $args[1][0] ) ? $args[1][0] : array();
		$costs_data = is_array( $data ) && ! empty( $data['costs_data'] ) ? $data['costs_data'] : array();

		if ( ! is_array( $costs_data ) ) {
			$costs_data = array();
		}
		self::render_top_grid();
		?>
		<div class="mainwp-scrolly-overflow">
		<?php
			$tabs = array( 'today', 'tomorrow', 'week', 'next_week', 'month', 'next_month', 'year', 'next_year' );
		foreach ( $tabs as $tab ) {
			$this->render_renewals_tab( $tab, $costs_data );
		}
		?>
		</div>
		<?php
	}

	/**
	 * Render upcomming renewals tabs.
	 *
	 * @param string $tab Tab.
	 * @param array  $cost_data     Cost data.
	 */
	public static function get_costs_widgets_data( $tab, $cost_data ) {
		$list = array();
		if ( is_array( $cost_data ) ) {
			$time      = time();
			$upcoming1 = 0;
			$upcoming2 = 0;
			if ( 'today' === $tab ) {
				$upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', $time ) );
				$upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', $time ) );
			} elseif ( 'tomorrow' === $tab ) {
				$upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( '+1 day', $time ) ) );
				$upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( '+1 day', $time ) ) );
			} elseif ( 'week' === $tab ) {
				$upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) ) );
				$upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'sunday this week' ) ) );
			} elseif ( 'next_week' === $tab ) {
				$upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( 'first day of next week' ) ) );
				$upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of next week' ) ) );
			} elseif ( 'month' === $tab ) {
				$upcoming1 = strtotime( gmdate( 'Y-m-01 00:00:00', $time ) );
				$upcoming2 = strtotime( gmdate( 'Y-m-t 23:59:59', $time ) );
			} elseif ( 'next_month' === $tab ) {
				$upcoming1 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of this month' ) ) );
				$upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of next month' ) ) );
			} elseif ( 'year' === $tab ) {
				$upcoming1 = strtotime( gmdate( 'Y-01-01 00:00:00', $time ) );
				$upcoming2 = strtotime( gmdate( 'Y-12-t 23:59:59', $time ) );
			} elseif ( 'next_year' === $tab ) {
				$upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( 'first day of January ' . gmdate( 'Y' ) . '+1 year' ) ) );
				$upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of December ' . gmdate( 'Y' ) . '+1 year' ) ) );
			}
			if ( $upcoming1 ) {
				foreach ( $cost_data as $cost ) {
					$next_renewal = Cost_Tracker_Admin::get_next_renewal( $cost->last_renewal, $cost->renewal_type );
					if ( $next_renewal > $upcoming1 && $next_renewal <= $upcoming2 ) {
						$list[] = $cost;
					}
				}
			}
		}
		return $list;
	}

	/**
	 * Render upcomming renewals tabs.
	 *
	 * @param string $tab Tab.
	 * @param array  $cost_data     Cost data.
	 */
	public static function render_renewals_tab( $tab, $cost_data ) {
		$lists = self::get_costs_widgets_data( $tab, $cost_data );
		?>
		<div class="cost_tracker_upcoming_renewals ui middle aligned tab" data-tab="renewals-<?php echo esc_attr( $tab ); ?>">
			<?php
			/**
			 * Action: mainwp_module_upcoming_renewals_before_costs_list
			 *
			 * Fires before the list of costs.
			 *
			 * @param string $tab Tab.
			 * @param array  $cost_data     Cost data.
			 *
			 * @since 5.0.2
			 */
			do_action( 'mainwp_module_upcoming_renewals_before_costs_list', $tab, $cost_data );
			if ( 0 === count( $lists ) ) {
				MainWP_UI::render_empty_element_placeholder( __( 'No upcoming renewals for the selected priod.', 'mainwp' ) );
			} else {
				?>
				<table class="ui stacking table" id="mainwp-upcoming-renewals-table-<?php echo esc_attr( $tab ); ?>">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Name', 'mainwp' ); ?></a></th>
							<th><?php echo esc_html__( 'Renews at', 'mainwp' ); ?></th>
							<th class="collapsing right aligned"><?php echo esc_html__( 'Price', 'mainwp' ); ?></th>
						</tr>
					</thead>
				<?php
				foreach ( $lists as $item ) {
					$next_renewal = Cost_Tracker_Admin::get_next_renewal( $item->last_renewal, $item->renewal_type );

					?>
					<tr>
						<td><a href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $item->id ); ?>"><?php echo esc_html( $item->name ); ?></a></td>
						<td><?php echo MainWP_Utility::format_date( $next_renewal ); //phpcs:ignore -- ok. ?></td>
						<td class="right aligned"><?php Cost_Tracker_Utility::cost_tracker_format_price( $item->price ); ?></td>
					</tr>
					<?php
				}
				?>
				</table>
				<script type="text/javascript">
				jQuery( document ).ready( function() {
					jQuery( '#mainwp-upcoming-renewals-table-today' ).DataTable();
					jQuery( '#mainwp-upcoming-renewals-table-tomorrow' ).DataTable();
					jQuery( '#mainwp-upcoming-renewals-table-week' ).DataTable();
					jQuery( '#mainwp-upcoming-renewals-table-next_week' ).DataTable();
					jQuery( '#mainwp-upcoming-renewals-table-month' ).DataTable();
					jQuery( '#mainwp-upcoming-renewals-table-next_month' ).DataTable();
					jQuery( '#mainwp-upcoming-renewals-table-year' ).DataTable();
					jQuery( '#mainwp-upcoming-renewals-table-next_year' ).DataTable();
				} );
				</script>
				<?php
			}
			/**
			 * Action: mainwp_module_upcoming_renewals_after_costs_list
			 *
			 * Fires after the list of costs.
			 *
			 * @param string $tab Tab.
			 * @param array  $cost_data     Cost data.
			 *
			 * @since 5.0.1
			 */
			do_action( 'mainwp_module_upcoming_renewals_after_costs_list', $tab, $cost_data );
			?>
		</div>
		<?php
	}
}
