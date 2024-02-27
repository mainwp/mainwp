<?php
/**
 * MainWP Logs Widget
 *
 * Displays the Logs Info.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Log_Clients_Widget
 *
 * Displays the Logs info.
 */
class Log_Clients_Widget {

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
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * @param array ...$args Widget callback args.
	 * @return mixed render_site_info()
	 */
	public function render( ...$args ) {
		$this->render_widget( $args );
	}


	/**
	 * Render client overview Info.
	 *
	 * @param array $args Args data.
	 */
	public function render_widget( $args ) {
		$data       = is_array( $args ) && ! empty( $args[1][0] ) && is_array( $args[1][0] ) ? $args[1][0] : array();
		$stats_data = is_array( $data ) && ! empty( $data['stats_data']['client']['clients'] ) ? $data['stats_data']['client']['clients'] : array();
		if ( ! is_array( $stats_data ) ) {
			$stats_data = array();
		}

		$stats_prev_data = is_array( $data ) && ! empty( $data['stats_prev_data']['client']['clients'] ) ? $data['stats_prev_data']['client']['clients'] : array();
		if ( ! is_array( $stats_prev_data ) ) {
			$stats_prev_data = array();
		}

		?>
		<div class="mainwp-widget-header">
			<h3 class="ui header handle-drag">
				<?php esc_html_e( 'Client Management Activity Summary', 'mainwp' ); ?>
				<div class="sub header">
				<?php esc_html_e( 'Quantitative snapshot of client management actions and the total count of all events.', 'mainwp' ); ?>
				</div>
			</h3>
		</div>

		<div class="mainwp-widget-insights-card">
				<?php
				/**
				 * Action: mainwp_logs_widget_top
				 *
				 * Fires at the top of the widget.
				 *
				 * @since 4.6
				 */
				do_action( 'mainwp_logs_widget_top', 'clients' );
				?>
				<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
				<?php
				wp_nonce_field( 'mainwp-admin-nonce' );
				$this->render_widget_content( $stats_data, $stats_prev_data );
				?>
				<?php
				/**
				 * Action: mainwp_logs_widget_bottom
				 *
				 * Fires at the bottom of the widget.
				 *
				 * @since 4.6
				 */
				do_action( 'mainwp_logs_widget_bottom', 'clients' );
				?>
			</div>
		<div class="mainwp-widget-footer ui four columns stackable grid">
			<div class="column">
			</div>
			<div class="column">
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_widget_content()
	 *
	 * @param array $data Stats data.
	 * @param array $prev_data Previous stats data.
	 */
	public function render_widget_content( $data, $prev_data ) {
		$columns = array(
			'created'      => esc_html__( 'Created', 'mainwp' ),
			'updated'      => esc_html__( 'Updated', 'mainwp' ),
			'deleted'      => esc_html__( 'Deleted', 'mainwp' ),
			'suspend'      => esc_html__( 'Suspended', 'mainwp' ),
			'unsuspend'    => esc_html__( 'Unsuspended', 'mainwp' ),
			'lost'         => esc_html__( 'Lost', 'mainwp' ),
			'total_events' => esc_html__( 'Total', 'mainwp' ),
		);
		?>
		<div class="ui one column grid">
			<div class="left aligned middle aligned column">
				<div class="ui equal width grid">
					<?php
					foreach ( $columns as $act => $title ) {
						Log_Stats::render_stats_info( $data, $act, $title, $prev_data );
					}
					?>
				</div>
			</div>
			<div class="left aligned middle aligned column">
				<div id="mainwp-module-log-chart-clients-management-wrapper" ></div>
				<script type="text/javascript">
					jQuery( document ).ready( function() {
						var options = {
							chart: { 
								type: 'bar',
								height: 350,
							},
							plotOptions: {
								bar: {
									columnWidth: '60%'
								}
							},
							series: [ {
								name: 'Events:',
								data: [
								<?php
								foreach ( $columns as $act => $title ) {
									Log_Stats::render_chart_series( $data, $act, $title, $prev_data );
								}
								?>
								],
							} ],
							xaxis: {
								labels: {
									style: {
										colors: '#999999',
									}
								}
							},
							yaxis: {
								labels: {
									style: {
										colors: '#999999',
									}
								}
							},
							tooltip: {
								theme: 'dark'
							},
						}
						var clients = new ApexCharts(document.querySelector("#mainwp-module-log-chart-clients-management-wrapper"), options);
						setTimeout(() => {
							clients.render();
						}, 1000);
					} );
				</script>
			</div>
		</div>
		<?php
	}
}
