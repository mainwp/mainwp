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

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;

/**
 * Class Log_Graph_Clients_Widget
 *
 * Displays the Logs info.
 */
class Log_Graph_Clients_Widget {

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
	 */
	public function render() {
		$this->render_widget();
	}


	/**
	 * Render client overview Info.
	 */
	public function render_widget() {
		?>
		<div class="mainwp-widget-header">
			<h3 class="ui header handle-drag">
				<?php esc_html_e( 'Client Sites Distribution', 'mainwp' ); ?>
				<div class="sub header">
				<?php esc_html_e( 'Count of sites assigned to each client. Easily compare the site allocations among your client base.', 'mainwp' ); ?>
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
				do_action( 'mainwp_logs_widget_top', 'status' );
				?>
				<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
				<?php
				wp_nonce_field( 'mainwp-admin-nonce' );
				$this->render_widget_content();
				?>
				<?php
				/**
				 * Action: mainwp_logs_widget_bottom
				 *
				 * Fires at the bottom of the widget.
				 *
				 * @since 4.6
				 */
				do_action( 'mainwp_logs_widget_bottom', 'status' );
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
	 */
	public function render_widget_content() {
		$clients = MainWP_DB_Client::instance()->get_wp_clients();
		?>
		<div id="mainwp-module-log-chart-clients-wrapper" ></div>
		<script type="text/javascript">
			jQuery( document ).ready( function() {
				var options = {
					chart: { 
						type: 'bar',
					},
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
					series: [ {
						name: 'Sites',
						data: [
							<?php foreach ( $clients as $client ) : ?>
							{ 
							x: '<?php echo esc_js( $client['name'] ); ?>',
							y: <?php echo ! empty( $client['selected_sites'] ) ? count( explode( ',', $client['selected_sites'] ) ) : 0; ?>,
							fillColor: "#18a4e0",
							},
							<?php endforeach; ?>
						]
					} ],
				}
				var clients = new ApexCharts(document.querySelector("#mainwp-module-log-chart-clients-wrapper"), options);
				setTimeout(() => {
					clients.render();
				}, 1000);
			} );
		</script>
		<?php
	}
}
